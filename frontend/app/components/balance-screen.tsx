"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { ApiError, getApi, postApi, redirectForAuthError, type CurrentUser } from "@/lib/auth-api";
import { displayDate } from "@/lib/admin-api";
import { balanceError, hoursLabel, wholeNumber, type Balance, type BalancePerson, type Carry, type PeopleResponse } from "@/lib/balance-api";
import { BalanceImpactPreview, type BalancePreview } from "./balance-impact-preview";
import { TeamBalancesPanel } from "./team-balances-panel";
import { AccountingField, AccountingGate, AccountingNotice, useOperationKey } from "./accounting-shared";

type Editor = "opening" | "limit" | "correction" | null;
type SaveResult = "saved" | "uncertain" | "failed";
type Save = (path: string, body: Record<string, unknown>, message: string) => Promise<SaveResult>;

export function BalanceScreen({ mode = "personal" }: { mode?: "personal" | "management" }) {
  return <AccountingGate screen={mode === "management" ? "team-balances" : "balances"}>{actor => <BalanceWorkspace actor={actor} mode={mode} />}</AccountingGate>;
}

function BalanceWorkspace({ actor, mode }: { actor: CurrentUser; mode: "personal" | "management" }) {
  const [people, setPeople] = useState<BalancePerson[]>([]);
  const [view, setView] = useState<"person" | "team">("person");
  const management = mode === "management";
  const canViewTeam = management && (actor.isAdministrator || actor.role === "manager" || actor.role === "deputy");
  const [personId, setPersonId] = useState(actor.id);
  const [year, setYear] = useState("");
  const [today, setToday] = useState("");
  const [balance, setBalance] = useState<Balance | null>(null);
  const [editor, setEditor] = useState<Editor>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const sequence = useRef(0);
  const pending = useRef(false);
  const keyFor = useOperationKey();
  const person = people.find(item => item.id === personId);

  function fail(failure: unknown) {
    if (failure instanceof ApiError && (failure.status === 401 || failure.status === 403)) {
      ++sequence.current; setPeople([]); setBalance(null); setEditor(null); setLoading(false);
    }
    if (!redirectForAuthError(failure)) setError(balanceError(failure));
  }

  useEffect(() => {
    const request = ++sequence.current;
    async function start() {
      try {
        const list = await getApi<PeopleResponse>("/api/balances/people");
        if (sequence.current !== request) return;
        const available = management ? list.people : list.people.filter(item => item.id === actor.id);
        setPeople(available); setToday(list.today); setYear(String(list.currentYear));
        if (canViewTeam && new URLSearchParams(window.location.search).get("view") !== "person") setView("team");
        const selected = available.find(item => item.id === actor.id) ?? (management ? available[0] : undefined);
        if (!selected) { setError("Nie ma danych salda do wyświetlenia. Sprawdź przypisanie do zespołu z administratorem."); setLoading(false); return; }
        setPersonId(selected.id);
        const result = await getApi<{ balance: Balance }>(`/api/balances/users/${selected.id}/${list.currentYear}`);
        if (sequence.current === request) setBalance(result.balance);
      } catch (failure) {
        if (sequence.current === request) {
          if (failure instanceof ApiError && (failure.status === 401 || failure.status === 403)) { setPeople([]); setBalance(null); }
          if (!redirectForAuthError(failure)) setError(balanceError(failure));
        }
      } finally { if (sequence.current === request) setLoading(false); }
    }
    void start();
    return () => { sequence.current = -1; };
  }, [actor.id, canViewTeam, management]);

  async function load(id = personId, requestedYear = year) {
    if (pending.current) return;
    if (!management) id = actor.id;
    let selectedYear: number;
    try { selectedYear = wholeNumber(requestedYear, 2025, 2100); } catch (failure) { setError(balanceError(failure)); return; }
    const request = ++sequence.current;
    setLoading(true); setError(""); setNotice(""); setEditor(null); setBalance(null); setPersonId(id);
    try {
      const result = await getApi<{ balance: Balance }>(`/api/balances/users/${id}/${selectedYear}`);
      if (sequence.current === request) { setBalance(result.balance); setYear(String(result.balance.year)); return result.balance; }
    } catch (failure) { if (sequence.current === request) fail(failure); }
    finally { if (sequence.current === request) setLoading(false); }
  }

  async function openCorrection() {
    if (!management || !person?.canManage || !balance?.opening || pending.current) return;
    const openingYear = Number(balance.opening.effectiveDate.slice(0, 4));
    if (openingYear !== balance.year) {
      const result = await load(personId, String(openingYear));
      if (!result) return;
    }
    setEditor("correction");
  }

  const save: Save = async (path, body, message) => {
    if (!management || !person?.canManage || pending.current || !balance) return "failed";
    pending.current = true; setBusy(true); setError(""); setNotice("");
    const request = sequence.current;
    const payload = { ...body, expectedVersion: balance.version };
    try {
      const result = await postApi<{ balance: Balance }>(path, { ...payload, operationKey: keyFor({ path, ...payload }) });
      if (sequence.current !== request) return "saved";
      setBalance(result.balance); setYear(String(result.balance.year)); setEditor(null); setNotice(message);
      return "saved";
    } catch (failure) {
      if (sequence.current === request) fail(failure);
      return failure instanceof ApiError && (failure.status === 0 || failure.status >= 500 || failure.code === "INVALID_RESPONSE") ? "uncertain" : "failed";
    } finally { pending.current = false; setBusy(false); }
  };

  function changeView(next: "person" | "team") {
    if (busy) return;
    setView(next); setEditor(null); setError(""); setNotice("");
    window.history.replaceState(null, "", next === "team" ? "/management/balances" : "/management/balances?view=person");
  }

  return <>
    {canViewTeam && <div className="balance-view-tabs" role="group" aria-label="Widok sald"><button type="button" className="button button-secondary" disabled={busy} aria-pressed={view === "person"} onClick={() => changeView("person")}>Saldo osoby</button><button type="button" className="button button-secondary" disabled={busy} aria-pressed={view === "team"} onClick={() => changeView("team")}>Salda zespołu</button></div>}
    {view === "team" && canViewTeam ? (today ? <div data-tour="team-balances"><TeamBalancesPanel initialYear={String(balance?.year ?? Number(today.slice(0, 4)))} onSelect={(selected, selectedYear) => { setPeople(previous => [...previous.filter(item => item.id !== selected.id), selected]); changeView("person"); void load(selected.id, selectedYear); }} /></div> : error ? <AccountingNotice>{error}<button type="button" className="text-button" onClick={() => window.location.reload()}>Spróbuj ponownie</button></AccountingNotice> : <p role="status">Wczytywanie zestawienia…</p>) : <>
    {error && <AccountingNotice>{error}<div className="notice-actions"><button className="text-button" disabled={busy || loading} onClick={() => void load()}>Wczytaj aktualne dane</button></div></AccountingNotice>}
    {notice && <AccountingNotice kind="success">{notice}</AccountingNotice>}
    {people.length > 0 && <form className="balance-filters" onSubmit={event => { event.preventDefault(); void load(); }}><fieldset disabled={busy || loading} className="admin-fieldset balance-filter-fields">
      {management && <div className="field"><label htmlFor="balance-person">Osoba</label><select id="balance-person" value={personId} onChange={event => void load(Number(event.target.value))}>{people.map(item => <option key={item.id} value={item.id}>{item.firstName} {item.lastName}{item.id === actor.id ? " (Ty)" : ""}{item.active ? "" : " — konto nieaktywne"}</option>)}</select></div>}
      <AccountingField id="balance-year" label="Rok salda" type="number" min={2025} max={2100} value={year} onChange={setYear} />
      <button className="button button-secondary" type="submit">Pokaż saldo</button>
    </fieldset></form>}
    {loading && <p role="status">Wczytywanie salda…</p>}
    {balance && person && <>
      <section aria-labelledby="balance-person-title" className="balance-overview" data-tour={management ? undefined : "own-balance"}>
        <div className="admin-section-heading"><div><h2 id="balance-person-title">{person.firstName} {person.lastName} · {balance.year}</h2><p className="field-hint">{person.team?.name ?? "Brak zespołu"} · {person.employmentType?.name ?? "Brak formy zatrudnienia"}</p></div><span className={`admin-status ${balance.configured ? "is-active" : "is-inactive"}`}>{balance.configured ? "Limit ustawiony" : "Brak limitu"}</span></div>
        {!balance.configured && <AccountingNotice kind="info">Limit na rok {balance.year} nie został jeszcze ustawiony. Brak limitu nie oznacza limitu 0 godzin.</AccountingNotice>}
        {balance.deficitHours > 0 && <AccountingNotice>Niedobór: {hoursLabel(balance.deficitHours)}. Zatwierdzone urlopy pozostają ważne. Nowe obciążenia zwiększające niedobór są zablokowane.</AccountingNotice>}
        <div className="balance-stats">
          <div className={`balance-stat balance-available ${balance.deficitHours > 0 ? "balance-negative" : ""}`}><span>Dostępne</span><strong>{balance.availableHours} h</strong><small>{hoursLabel(balance.availableHours)}</small></div>
          <div className="balance-stat"><span>Limit roczny</span><strong>{balance.annualHours === null ? "Nie ustawiono" : `${balance.annualHours} h`}</strong><small>{balance.annualHours === null ? "Wymaga uzupełnienia" : hoursLabel(balance.annualHours)}</small></div>
          <div className="balance-stat"><span>Dostępna zaległość</span><strong>{balance.carriedHours} h</strong><small>Według roku pochodzenia</small></div>
        </div>
        <dl className="balance-breakdown"><div><dt>Wykorzystanie przed startem ewidencji</dt><dd>{balance.openingUsedHours} h</dd></div><div><dt>Wykorzystane w ewidencji</dt><dd>{balance.usedHours} h</dd></div><div><dt>Zatwierdzone przyszłe urlopy</dt><dd>{balance.approvedFutureHours} h</dd></div><div><dt>Oczekujące wnioski</dt><dd>{balance.reservedHours} h</dd></div><div><dt>Dodatkowa rezerwacja na zmiany</dt><dd>{balance.changeReservedHours} h</dd></div></dl>
        <p className="field-hint">Dzień = 8 godzin. Oczekujące wnioski już rezerwują saldo; akceptacja nie potrąca go drugi raz.</p>
        <p className="field-hint">{person.employmentType?.carriesUnusedHours ? "Niewykorzystane godziny przechodzą od początku nowego roku. Wcześniej przyszły urlop korzysta wyłącznie z wpisanego limitu przyszłego roku. W pierwszej kolejności zużywana jest najstarsza dostępna pula." : "Niewykorzystane godziny nie przechodzą na kolejny rok. Limit każdego roku jest ustawiany osobno."}</p>
      </section>
      {management && person.canManage ? <div className="balance-management"><div className="admin-actions">
        {!balance.opening && <button className="button button-primary" disabled={busy} onClick={() => setEditor("opening")}>Ustaw dane początkowe</button>}
        {balance.opening && <><button className="button button-primary" disabled={busy} onClick={() => setEditor("limit")}>{balance.configured ? "Zmień limit roczny" : "Ustaw limit roczny"}</button><button className="button button-secondary" disabled={busy} onClick={() => void openCorrection()}>Koryguj dane początkowe</button><button className="button button-secondary" disabled={busy || balance.year > Number(today.slice(0, 4))} onClick={() => void save(`/api/balances/users/${personId}/${balance.year}/settle`, {}, "Rozliczenie roku jest aktualne. Powtórzenie nie dodaje godzin drugi raz.")}>Rozlicz rok {balance.year}</button></>}
      </div><p className="field-hint">Zmiany zapisują autora, czas i poprzednie wartości. Korekta może wykazać niedobór; nie anuluje urlopów.</p></div> : <p className="field-hint">{actor.role === "manager" && actor.id === personId && !actor.isAdministrator ? "Twój limit i dane początkowe zmienia administrator." : "Masz dostęp do odczytu. Limity ustawia kierownik lub administrator."}</p>}
      {management && editor && person.canManage && <BalanceEditor key={`${personId}:${balance.year}:${balance.version}:${editor}`} kind={editor} balance={balance} person={person} busy={busy} today={today} save={save} onAccessError={fail} close={() => setEditor(null)} />}
      {balance.opening && <section className="balance-section" aria-labelledby="opening-title"><h2 id="opening-title">Dane początkowe</h2><p className="field-hint">Bieżące wartości po korektach. Wcześniejsze wersje pozostają w historii.</p><dl className="balance-breakdown"><div><dt>Początek ewidencji</dt><dd>{displayDate(balance.opening.effectiveDate)}</dd></div><div><dt>Limit roku rozpoczęcia</dt><dd>{hoursLabel(balance.opening.annualHours)}</dd></div><div><dt>Wykorzystane z bieżącej puli przed startem</dt><dd>{balance.opening.currentYearUsedHours} h</dd></div>{balance.opening.carries.map(carry => <div key={carry.originYear}><dt>Pozostałość z {carry.originYear} na dzień startu</dt><dd>{carry.remainingHours} h</dd></div>)}</dl></section>}
      {balance.pools.length > 0 && <section className="balance-section" aria-labelledby="pools-title"><h2 id="pools-title">Pule godzin według pochodzenia</h2><div className="accounting-table-scroll"><table className="accounting-table"><thead><tr><th scope="col">Rok puli</th><th scope="col">Przydział</th><th scope="col">Rezerwacje</th><th scope="col">Obciążenia</th><th scope="col">Pozostało</th><th scope="col">Dostępność w {balance.year}</th></tr></thead><tbody>{balance.pools.map(pool => <tr key={pool.originYear}><th scope="row">{pool.originYear}</th><td>{pool.allocatedHours} h</td><td>{pool.reservedHours} h</td><td>{pool.chargedHours} h</td><td>{pool.availableHours} h</td><td>{pool.eligible ? "Dostępna" : "Niedostępna w tym roku"}</td></tr>)}</tbody></table></div><p className="field-hint">Ta sama pula jest wspólna dla lat korzystających z zaległości. Nie sumuj jej ponownie między latami.</p></section>}
      <section className="balance-section" aria-labelledby="history-title"><h2 id="history-title">Historia rozliczeń</h2>{balance.history.length === 0 ? <p className="field-hint">Brak zapisanych operacji.</p> : <ol className="balance-history">{balance.history.map(item => <li key={item.id}><div><strong>{actionLabel(item.action)}</strong><span>{new Intl.DateTimeFormat("pl-PL", { timeZone: "Europe/Warsaw", dateStyle: "short", timeStyle: "short" }).format(new Date(item.occurredAt))} · {item.actorName ?? "System"}</span></div>{item.reason && <p>{item.reason}</p>}<HistoryValues before={item.before} after={item.after} /></li>)}</ol>}</section>
    </>}
    </>}
  </>;
}

function actionLabel(action: string): string {
  if (/opening.*correct|correct.*opening/.test(action)) return "Korekta danych początkowych";
  if (/opening/.test(action)) return "Ustawienie danych początkowych";
  if (/limit|annual/.test(action)) return "Zmiana limitu rocznego";
  if (/settle|rollover/.test(action)) return "Rozliczenie roku";
  if (/calendar/.test(action)) return "Korekta kalendarza";
  if (/reserv/.test(action)) return "Rezerwacja godzin";
  if (/release/.test(action)) return "Zwolnienie rezerwacji";
  if (/approv/.test(action)) return "Zatwierdzenie godzin";
  if (/cancel/.test(action)) return "Zwrot godzin";
  return "Operacja rozliczeniowa";
}

function HistoryValues({ before, after }: { before: Record<string, unknown>; after: Record<string, unknown> }) {
  const beforeOpening = before.opening;
  const afterOpening = after.opening;
  const beforeValues = typeof beforeOpening === "object" && beforeOpening !== null && !Array.isArray(beforeOpening) ? { ...before, ...beforeOpening } : before;
  const afterValues = typeof afterOpening === "object" && afterOpening !== null && !Array.isArray(afterOpening) ? { ...after, ...afterOpening } : after;
  const labels: Record<string, string> = { annualHours: "Limit roczny", currentYearUsedHours: "Wykorzystanie przed startem", effectiveDate: "Początek ewidencji", year: "Rok" };
  const lines = Object.entries(labels).filter(([key]) => afterValues[key] !== undefined && beforeValues[key] !== afterValues[key]);
  function carryMap(value: unknown): Map<number, number> {
    const result = new Map<number, number>();
    if (Array.isArray(value)) for (const row of value) if (typeof row === "object" && row !== null && typeof row.originYear === "number" && typeof row.remainingHours === "number") result.set(row.originYear, row.remainingHours);
    return result;
  }
  const oldCarries = carryMap(beforeValues.carries);
  const newCarries = carryMap(afterValues.carries);
  const changedCarries = [...new Set([...oldCarries.keys(), ...newCarries.keys()])].sort((a, b) => a - b).filter(year => oldCarries.get(year) !== newCarries.get(year));
  return lines.length || changedCarries.length ? <dl className="history-values">{lines.map(([key, label]) => <div key={key}><dt>{label}</dt><dd>{String(beforeValues[key] ?? "—")} → {String(afterValues[key])}{key.endsWith("Hours") ? " h" : ""}</dd></div>)}{changedCarries.map(year => <div key={year}><dt>Pozostałość z {year}</dt><dd>{oldCarries.get(year) ?? 0} h → {newCarries.get(year) ?? 0} h</dd></div>)}</dl> : null;
}

type CarryRow = { key: number; originYear: string; remainingHours: string };
function BalanceEditor({ kind, balance, person, busy, today, save, onAccessError, close }: { kind: Exclude<Editor, null>; balance: Balance; person: BalancePerson; busy: boolean; today: string; save: Save; onAccessError: (failure: unknown) => void; close: () => void }) {
  const [annual, setAnnual] = useState(String(kind === "opening" ? "" : balance.annualHours ?? ""));
  const [unit, setUnit] = useState("hours");
  const [date, setDate] = useState(balance.opening?.effectiveDate ?? (Number(today.slice(0, 4)) === balance.year ? today : `${balance.year}-01-01`));
  const [used, setUsed] = useState(String(balance.opening?.currentYearUsedHours ?? 0));
  const [carries, setCarries] = useState<CarryRow[]>((balance.opening?.carries ?? []).map((item, key) => ({ key, originYear: String(item.originYear), remainingHours: String(item.remainingHours) })));
  const [reason, setReason] = useState("");
  const [error, setError] = useState("");
  const [preview, setPreview] = useState<BalancePreview | null>(null);
  const [previewLoading, setPreviewLoading] = useState(false);
  const [confirmed, setConfirmed] = useState(false);
  const [uncertain, setUncertain] = useState(false);
  const prepared = useRef<{ path: string; body: Record<string, unknown>; message: string } | null>(null);
  const previewSequence = useRef(0);
  const saving = useRef(false);
  const nextKey = useRef(carries.length);
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => { heading.current?.focus(); }, []);
  const titles = { opening: "Ustaw dane początkowe", limit: "Limit roczny", correction: "Korekta danych początkowych" };
  let converted: number | null = null;
  try { if (annual !== "") converted = wholeNumber(annual, 0, unit === "days" ? 125000 : 1000000) * (unit === "days" ? 8 : 1); } catch { /* Input remains editable before submission. */ }

  function invalidate() {
    ++previewSequence.current; setPreview(null); setConfirmed(false); setError(""); prepared.current = null;
  }

  useEffect(() => () => { ++previewSequence.current; }, []);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (busy || previewLoading || uncertain) return;
    const request = ++previewSequence.current;
    setPreview(null); setConfirmed(false); setError(""); setPreviewLoading(true); prepared.current = null;
    try {
      const annualHours = kind === "correction" ? 0 : wholeNumber(annual, 0, unit === "days" ? 125000 : 1000000) * (unit === "days" ? 8 : 1);
      let operation: { path: string; body: Record<string, unknown>; message: string };
      if (kind === "limit") {
        operation = { path: `/api/balances/users/${person.id}/${balance.year}/limit`, body: { annualHours, reason: reason.trim() }, message: "Zapisano limit roczny." };
      } else {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) throw new Error("Wybierz poprawną datę rozpoczęcia ewidencji.");
        const parsed: Carry[] = carries.map(row => ({ originYear: wholeNumber(row.originYear, 1000, Number(date.slice(0, 4)) - 1), remainingHours: wholeNumber(row.remainingHours) }));
        if (new Set(parsed.map(row => row.originYear)).size !== parsed.length) throw new Error("Każdy rok zaległości może pojawić się tylko raz.");
        const body = { effectiveDate: date, currentYearUsedHours: wholeNumber(used), carries: parsed };
        operation = kind === "opening"
          ? { path: `/api/balances/users/${person.id}/opening`, body: { ...body, annualHours }, message: "Zapisano dane początkowe." }
          : { path: `/api/balances/users/${person.id}/opening/corrections`, body: { ...body, reason: reason.trim() }, message: "Zapisano korektę danych początkowych." };
      }
      const result = await postApi<{ preview: BalancePreview }>(`${operation.path}-preview`, { ...operation.body, expectedVersion: balance.version });
      if (request !== previewSequence.current) return;
      if (result.preview.userId !== person.id || result.preview.version !== balance.version) throw new ApiError(0, "INVALID_RESPONSE");
      prepared.current = operation; setPreview(result.preview);
    } catch (failure) {
      if (request !== previewSequence.current) return;
      if (failure instanceof ApiError && (failure.status === 401 || failure.status === 403)) onAccessError(failure);
      else if (!redirectForAuthError(failure)) setError(balanceError(failure));
    } finally { if (request === previewSequence.current) setPreviewLoading(false); }
  }

  async function confirm() {
    if (saving.current || busy || !confirmed || !preview || !prepared.current) return;
    saving.current = true;
    try {
      const result = await save(prepared.current.path, prepared.current.body, prepared.current.message);
      if (result === "uncertain") setUncertain(true);
      else if (result === "failed") invalidate();
    } finally { saving.current = false; }
  }

  return <section className="admin-editor balance-editor" aria-labelledby="balance-editor-title"><div className="reset-heading"><h2 id="balance-editor-title" ref={heading} tabIndex={-1}>{titles[kind]} · {person.firstName} {person.lastName}</h2><button className="text-button" disabled={busy} onClick={close}>Zamknij formularz</button></div>
    {error && <AccountingNotice>{error}</AccountingNotice>}
    <form onSubmit={event => void submit(event)} onChange={invalidate}><fieldset className="admin-fieldset" disabled={busy || previewLoading || uncertain}>
      {kind !== "correction" && <div className="admin-form-grid"><AccountingField id="annual-limit" label={kind === "opening" ? "Limit roku rozpoczęcia" : `Limit na rok ${balance.year}`} value={annual} onChange={setAnnual} type="number" min={0} max={unit === "days" ? 125000 : 1000000} /><div className="field"><label htmlFor="limit-unit">Jednostka limitu</label><select id="limit-unit" value={unit} onChange={event => { setUnit(event.target.value); setAnnual(""); }}><option value="hours">Godziny</option><option value="days">Pełne dni (8 h)</option></select></div>{converted !== null && <p className="field-hint balance-conversion" role="status">Zostanie zapisane: {hoursLabel(converted)}.</p>}</div>}
      {kind !== "limit" && <><div className="admin-form-grid"><AccountingField id="opening-date" label="Początek ewidencji" type="date" value={date} onChange={setDate} min={kind === "correction" ? `${balance.opening!.effectiveDate.slice(0, 4)}-01-01` : `${balance.year}-01-01`} max={kind === "correction" ? `${balance.opening!.effectiveDate.slice(0, 4)}-12-31` : `${balance.year}-12-31`} hint={kind === "correction" ? "Datę można skorygować w tym samym roku, dopóki nie rozpoczęto rozliczania wniosków." : "Wcześniejsze nieobecności uwzględniamy wyłącznie w danych początkowych."} /><AccountingField id="opening-used" label="Wykorzystane z bieżącej puli przed startem (h)" value={used} onChange={setUsed} type="number" min={0} max={1000000} /></div>
        <fieldset className="absence-availability"><legend>Pozostałości wcześniejszych lat na dzień startu</legend><p className="field-hint">Wpisz pozostałe godziny osobno dla każdego roku pochodzenia. Nie odejmuj od nich ponownie wcześniejszego wykorzystania.</p>{!person.employmentType?.carriesUnusedHours && <p className="field-hint">Bieżąca forma zatrudnienia nie przenosi niewykorzystanych godzin.</p>}{carries.map((row, index) => <div className="carry-row" key={row.key}><AccountingField id={`carry-year-${row.key}`} label={`Rok pochodzenia ${index + 1}`} type="number" value={row.originYear} onChange={value => setCarries(carries.map(item => item.key === row.key ? { ...item, originYear: value } : item))} min={1000} max={Number(date.slice(0, 4)) - 1} /><AccountingField id={`carry-hours-${row.key}`} label={`Pozostałe godziny ${index + 1}`} type="number" value={row.remainingHours} onChange={value => setCarries(carries.map(item => item.key === row.key ? { ...item, remainingHours: value } : item))} min={0} max={1000000} /><button type="button" className="button button-secondary" aria-label={`Usuń zaległość ${index + 1}`} onClick={() => { invalidate(); setCarries(carries.filter(item => item.key !== row.key)); }}>Usuń</button></div>)}<button type="button" className="button button-secondary" onClick={() => { invalidate(); setCarries([...carries, { key: nextKey.current++, originYear: String(Number(date.slice(0, 4)) - 1), remainingHours: "0" }]); }}>Dodaj rok zaległości</button></fieldset>
      </>}
      {kind !== "opening" && <AccountingField id="balance-reason" label="Uzasadnienie zmiany" value={reason} onChange={setReason} required={kind === "correction" || balance.configured} maxLength={1000} hint="Powód będzie widoczny w historii rozliczeń." />}
      <button className="button button-secondary" type="submit">{previewLoading ? "Sprawdzanie wpływu…" : "Sprawdź wpływ zmiany"}</button>
    </fieldset></form>
    {preview && <>
      <BalanceImpactPreview preview={preview} />
      {uncertain && <AccountingNotice kind="info">Nie otrzymaliśmy potwierdzenia zapisu. Ponów tę samą operację albo wczytaj aktualne dane przed kolejną zmianą. Godziny nie zostaną dodane drugi raz.</AccountingNotice>}
      <label className="check-field"><input type="checkbox" checked={confirmed} disabled={busy || uncertain} onChange={event => setConfirmed(event.target.checked)} /><span>Potwierdzam pokazany wpływ na saldo.</span></label>
      <button className="button button-primary" type="button" disabled={busy || !confirmed} onClick={() => void confirm()}>{busy ? "Zapisywanie…" : uncertain ? "Ponów wysłanie" : kind === "opening" ? "Zapisz dane początkowe" : kind === "limit" ? "Zapisz limit" : "Zapisz korektę"}</button>
    </>}
  </section>;
}
