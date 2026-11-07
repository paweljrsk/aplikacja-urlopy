"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { ApiError, getApi, postApi, redirectForAuthError } from "@/lib/auth-api";
import { displayDate, warsawToday } from "@/lib/admin-api";
import { balanceError, nextDate, wholeNumber, type Calendar, type CalendarChange, type CalendarPreview } from "@/lib/balance-api";
import { AccountingField, AccountingGate, AccountingNotice, useOperationKey } from "./accounting-shared";

export function CompanyCalendarScreen() {
  return <AccountingGate screen="calendar">{() => <CompanyCalendarWorkspace />}</AccountingGate>;
}

function CompanyCalendarWorkspace() {
  const [calendar, setCalendar] = useState<Calendar | null>(null);
  const [year, setYear] = useState("");
  const [today, setToday] = useState("");
  const [change, setChange] = useState<CalendarChange>({ date: "", name: "", active: true });
  const [reason, setReason] = useState("");
  const [preview, setPreview] = useState<CalendarPreview | null>(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const pending = useRef(false);
  const sequence = useRef(0);
  const keyFor = useOperationKey();
  const previewHeading = useRef<HTMLHeadingElement>(null);
  const formHeading = useRef<HTMLHeadingElement>(null);

  function fail(failure: unknown) {
    if (failure instanceof ApiError && (failure.status === 401 || failure.status === 403)) { setCalendar(null); setPreview(null); setChange({ date: "", name: "", active: true }); }
    if (!redirectForAuthError(failure)) setError(balanceError(failure));
  }

  useEffect(() => {
    const request = ++sequence.current;
    const localToday = warsawToday();
    async function start() {
      try {
        const result = await getApi<Calendar>(`/api/calendar/${localToday.slice(0, 4)}`);
        if (sequence.current !== request) return;
        setCalendar(result); setYear(String(result.year)); setToday(localToday); setChange({ date: nextDate(localToday), name: "", active: true });
      } catch (failure) { if (sequence.current === request && !redirectForAuthError(failure)) setError(balanceError(failure)); }
      finally { if (sequence.current === request) setLoading(false); }
    }
    void start();
    return () => { sequence.current = -1; };
  }, []);
  useEffect(() => { if (preview) previewHeading.current?.focus(); }, [preview]);

  function edit(next: CalendarChange) { setChange(next); setPreview(null); setConfirmed(false); setError(""); setNotice(""); }

  async function load() {
    if (pending.current) return;
    let selectedYear: number;
    try { selectedYear = wholeNumber(year, 2025, 2100); } catch (failure) { setError(balanceError(failure)); return; }
    pending.current = true; setLoading(true); setError(""); setPreview(null); setConfirmed(false);
    const request = ++sequence.current;
    try { const result = await getApi<Calendar>(`/api/calendar/${selectedYear}`); if (sequence.current === request) setCalendar(result); }
    catch (failure) { if (sequence.current === request) fail(failure); }
    finally { pending.current = false; if (sequence.current === request) setLoading(false); }
  }

  async function check(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (pending.current) return;
    pending.current = true; setBusy(true); setPreview(null); setConfirmed(false); setError(""); setNotice("");
    const request = sequence.current;
    try {
      const result = await postApi<CalendarPreview>("/api/calendar/preview", { ...change, name: change.name.trim() });
      if (request === sequence.current) setPreview(result);
    } catch (failure) { if (request === sequence.current) fail(failure); }
    finally { pending.current = false; setBusy(false); }
  }

  async function apply() {
    if (pending.current || !preview || !confirmed || !reason.trim()) return;
    pending.current = true; setBusy(true); setError(""); setNotice("");
    const request = sequence.current;
    let committed = false;
    try {
      const payload = { date: preview.date, name: preview.name, active: preview.active, previewToken: preview.previewToken, reason: reason.trim() };
      await postApi("/api/calendar/apply", { ...payload, operationKey: keyFor(payload) });
      committed = true;
      if (request !== sequence.current) return;
      setNotice("Zapisano dzień firmowy i zatwierdzone korekty wniosków."); setPreview(null); setConfirmed(false); setReason("");
      const result = await getApi<Calendar>(`/api/calendar/${preview.date.slice(0, 4)}`);
      if (request === sequence.current) { setCalendar(result); setYear(String(result.year)); }
    } catch (failure) {
      if (request === sequence.current) {
        if (failure instanceof ApiError && failure.status === 409) { setPreview(null); setConfirmed(false); }
        fail(failure);
        if (committed) setNotice("Zmiana została zapisana. Odśwież listę, aby zobaczyć aktualne dane; nie ponawiaj zapisu.");
      }
    } finally { pending.current = false; setBusy(false); }
  }

  return <>
    {error && <AccountingNotice>{error}</AccountingNotice>}{notice && <AccountingNotice kind="success">{notice}</AccountingNotice>}
    <form className="calendar-year" onSubmit={event => { event.preventDefault(); void load(); }}><fieldset className="admin-fieldset balance-filter-fields" disabled={busy || loading}><AccountingField id="calendar-year" label="Rok kalendarza" type="number" min={2025} max={2100} value={year} onChange={setYear} /><button className="button button-secondary" type="submit">Pokaż dni wolne</button></fieldset></form>
    {loading && <p role="status">Wczytywanie kalendarza…</p>}
    {calendar && <>
      <section className="admin-editor calendar-editor" aria-labelledby="calendar-editor-title"><h2 id="calendar-editor-title" ref={formHeading} tabIndex={-1}>Zmień firmowy dzień wolny</h2><p className="field-hint">Zmiana może dotyczyć dat od jutra. Najpierw zobaczysz wpływ na wnioski; zapis wymaga osobnego potwierdzenia. Minione dni i święta ustawowe pozostają bez zmian.</p>
        <form onSubmit={event => void check(event)}><fieldset className="admin-fieldset" disabled={busy || loading}><div className="admin-form-grid"><AccountingField id="company-date" label="Data dnia firmowego" type="date" min={today ? nextDate(today) : undefined} max="2100-12-31" value={change.date} onChange={value => edit({ ...change, date: value })} /><AccountingField id="company-name" label="Nazwa dnia firmowego" value={change.name} onChange={value => edit({ ...change, name: value })} /></div><label className="check-field" htmlFor="company-active"><input id="company-active" type="checkbox" checked={change.active} onChange={event => edit({ ...change, active: event.target.checked })} /><span>Dzień wolny od pracy<span className="field-hint"> Odznaczenie usuwa firmowe zwolnienie z pracy w tym dniu.</span></span></label><button type="submit" className="button button-primary">{busy && !preview ? "Sprawdzanie…" : "Sprawdź wpływ zmiany"}</button></fieldset></form>
      </section>
      {preview && <section className="calendar-preview" aria-labelledby="preview-title"><h2 id="preview-title" ref={previewHeading} tabIndex={-1}>Podgląd zmiany</h2><p><strong>{displayDate(preview.date)} · {preview.name}</strong><br />{preview.active ? "Dzień firmowy wolny od pracy" : "Usunięcie firmowego dnia wolnego"}</p><p>Wnioski objęte zmianą: <strong>{preview.changedRequests}</strong>.</p>
        {preview.impacts.length > 0 && <><div className="accounting-table-scroll"><table className="accounting-table"><thead><tr><th scope="col">Osoba i wniosek</th><th scope="col">Zatwierdzony urlop</th><th scope="col">Oczekująca propozycja</th><th scope="col">Skutek</th></tr></thead><tbody>{preview.impacts.map(impact => <tr key={impact.requestId}><th scope="row">{impact.employeeName}<small>{impact.reference}</small></th><td>{impact.beforeHours} h → {impact.afterHours} h</td><td>{impact.beforePendingHours} h → {impact.afterPendingHours} h</td><td>{impact.action}</td></tr>)}</tbody></table></div><p className="field-hint">Godziny propozycji są pełnym wymiarem oczekującego wniosku lub zmiany, a nie dodatkową rezerwacją. Nie dodawaj ich do zatwierdzonych godzin.</p><AccountingNotice kind="info">Potwierdzenie zapisze także decyzje dotyczące wskazanych wniosków. Zwiększenie obciążenia może wykazać niedobór salda; usunięcie wszystkich godzin oznacza anulowanie lub wycofanie wniosku.</AccountingNotice></>}
        {preview.impacts.some(impact => impact.afterHours > impact.beforeHours || impact.afterPendingHours > impact.beforePendingHours) && <AccountingNotice>Ta zmiana zwiększy liczbę godzin co najmniej jednego wniosku. Potwierdzenie może spowodować lub zwiększyć niedobór salda.</AccountingNotice>}
        <fieldset className="admin-fieldset" disabled={busy}><AccountingField id="calendar-reason" label="Uzasadnienie zmiany kalendarza" value={reason} onChange={setReason} maxLength={1000} /><label className="check-field calendar-confirm" htmlFor="calendar-confirm"><input id="calendar-confirm" type="checkbox" checked={confirmed} onChange={event => setConfirmed(event.target.checked)} /><span>Potwierdzam zmianę dnia firmowego oraz wszystkie pokazane korekty wniosków.</span></label><button className="button button-primary" disabled={!confirmed || !reason.trim()} onClick={() => void apply()}>{busy ? "Zapisywanie…" : "Zatwierdź zmianę i korekty"}</button></fieldset>
      </section>}
      <section className="balance-section" aria-labelledby="calendar-list-title"><h2 id="calendar-list-title">Dni wolne · {calendar.year}</h2><p className="field-hint">Tydzień pracy: poniedziałek–piątek, 8 h dziennie. Święto w sobotę nie wyznacza automatycznie dodatkowej daty — firmowy dzień wolny dodaje administrator.</p><ul className="admin-list calendar-list">{calendar.holidays.map(holiday => <li key={`${holiday.source}:${holiday.date}`}><div className="admin-item-main"><strong>{displayDate(holiday.date)} · {holiday.name}</strong><small>{holiday.source === "statutory" ? "Święto ustawowe" : holiday.active ? "Firmowy dzień wolny" : "Nieaktywny dzień firmowy"}</small></div>{holiday.source === "company" && holiday.date > today && <button className="button button-secondary button-small" disabled={busy || loading} onClick={() => { edit({ date: holiday.date, name: holiday.name, active: holiday.active }); formHeading.current?.focus(); formHeading.current?.scrollIntoView({ block: "center" }); }} aria-label={`Edytuj dzień ${displayDate(holiday.date)}`}>Edytuj</button>}</li>)}</ul></section>
    </>}
  </>;
}
