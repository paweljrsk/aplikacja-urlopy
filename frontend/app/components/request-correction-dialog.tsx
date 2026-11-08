"use client";

import { useEffect, useId, useRef, useState, type FormEvent } from "react";
import { ApiError, redirectForAuthError, type CurrentUser } from "@/lib/auth-api";
import { hoursLabel } from "@/lib/balance-api";
import { getRequest, getRequestOptions, requestRange, unconfirmedRequest, type RequestDetail, type RequestMode, type RequestOptions } from "@/lib/request-api";
import { correctionConflict, correctionError, previewCorrection, saveCorrection, type CorrectionApplication, type CorrectionConfirmation, type CorrectionPreview, type CorrectionSnapshot } from "@/lib/request-correction-api";
import { RequestModal } from "./request-dialog";
import { RequestDurationSummary } from "./request-duration-summary";
import "./request-correction.css";

type Props = { request: RequestDetail; user: CurrentUser; onClose: () => void; onSaved: () => void };
type Fields = { action: "change" | "cancel"; absenceTypeId: string; mode: RequestMode; startDate: string; endDate: string; hours: string; reason: string };
function initialFields(request: RequestDetail): Fields {
  const revision = request.effective ?? request.pending ?? request.current ?? request.latestVisibleRevision;
  return { action: "change", absenceTypeId: String(revision.absenceType.id), mode: revision.mode,
    startDate: revision.startDate, endDate: revision.endDate,
    hours: String(revision.mode === "hourly" ? revision.totalHours : 3), reason: "" };
}
function canCorrect(request: RequestDetail): boolean {
  return request.actions.correct && request.currentVisible && request.version !== null;
}

export function RequestCorrectionDialog(props: Props) {
  return <CorrectionForm key={`${props.request.id}:${props.user.id}`} {...props} />;
}

function CorrectionForm({ request, user, onClose, onSaved }: Props) {
  const id = useId();
  const [base, setBase] = useState<RequestDetail | null>(canCorrect(request) ? request : null);
  const [fields, setFields] = useState(() => initialFields(request));
  const [options, setOptions] = useState<RequestOptions | null>(null);
  const [optionsError, setOptionsError] = useState("");
  const [optionsAttempt, setOptionsAttempt] = useState(0);
  const [preview, setPreview] = useState<{ fingerprint: string; value: CorrectionPreview } | null>(null);
  const [confirmed, setConfirmed] = useState(false);
  const [allowDeficit, setAllowDeficit] = useState(false);
  const [busy, setBusy] = useState<"preview" | "save" | "reload" | null>(null);
  const [error, setError] = useState("");
  const [conflict, setConflict] = useState(false);
  const [uncertain, setUncertain] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [closing, setClosing] = useState(false);
  const [checking, setChecking] = useState(false);
  const pending = useRef(false);
  const activity = useRef<"preview" | "save" | "reload" | null>(null);
  const readSequence = useRef(0);
  const operation = useRef<CorrectionConfirmation | null>(null);
  const alive = useRef(true);
  const uncertainWrite = useRef(false);
  const refreshing = useRef(false);
  const freshnessSequence = useRef(0);
  const optionsSequence = useRef(0);
  const draftVersion = useRef(request.version);

  useEffect(() => {
    alive.current = true;
    return () => { alive.current = false; };
  }, []);

  useEffect(() => {
    let active = true;
    const sequence = ++optionsSequence.current;
    getRequestOptions().then(value => { if (active && sequence === optionsSequence.current) { setOptions(value); setOptionsError(""); } })
      .catch(failure => { if (active && sequence === optionsSequence.current && !redirectForAuthError(failure)) setOptionsError(correctionError(failure)); });
    return () => { active = false; };
  }, [optionsAttempt]);

  useEffect(() => {
    let active = true;
    function cancelRead() {
      // A read-only preview can finish after access changes. Its response must
      // never restore data cleared by a newer authorization check.
      ++readSequence.current;
      if (activity.current === "preview" || activity.current === "reload") {
        activity.current = null; pending.current = false; setBusy(null);
      }
    }
    function clearDisplayedData() {
      setBase(null); setPreview(null); setConfirmed(false); setAllowDeficit(false);
      operation.current = null;
      ++optionsSequence.current;
    }
    function hide() {
      // A started mutation and its uncertain retry must keep the original payload.
      if (activity.current === "save" || uncertainWrite.current) return;
      cancelRead();
      ++freshnessSequence.current; refreshing.current = false;
      clearDisplayedData(); setChecking(true);
    }
    async function refresh() {
      if (!active || activity.current === "save" || uncertainWrite.current || refreshing.current || document.visibilityState === "hidden") return;
      cancelRead();
      const sequence = ++freshnessSequence.current;
      refreshing.current = true; clearDisplayedData(); setChecking(true); setError("");
      try {
        const [fresh, freshOptions] = await Promise.all([getRequest(request.id), getRequestOptions()]);
        if (!active || !alive.current || sequence !== freshnessSequence.current) return;
        if (fresh.request.id !== request.id) throw new ApiError(0, "INVALID_RESPONSE");
        setOptions(freshOptions); setOptionsError("");
        if (!canCorrect(fresh.request)) {
          setConflict(false);
          setError("Ten wniosek nie pozwala już na korektę. Zamknij okno i sprawdź jego aktualny stan.");
        } else if (fresh.request.version !== draftVersion.current) {
          setConflict(true);
          setError("Wniosek zmienił się podczas Twojej nieobecności. Wczytaj aktualne dane przed przygotowaniem korekty.");
        } else {
          // The same authorized version can keep the user's unsaved fields, but
          // financial preview and confirmations must always be obtained again.
          setBase(fresh.request);
        }
      } catch (failure) {
        if (!active || !alive.current || sequence !== freshnessSequence.current) return;
        if (!redirectForAuthError(failure)) setError(correctionError(failure));
        setConflict(!(failure instanceof ApiError && [401, 403, 404].includes(failure.status)));
      } finally {
        if (active && alive.current && sequence === freshnessSequence.current) { refreshing.current = false; setChecking(false); }
      }
    }
    const focus = () => { void refresh(); };
    const visibility = () => { if (document.visibilityState === "visible") focus(); else hide(); };
    const pageShow = (event: PageTransitionEvent) => { if (event.persisted) focus(); };
    window.addEventListener("focus", focus);
    window.addEventListener("pagehide", hide);
    window.addEventListener("pageshow", pageShow);
    document.addEventListener("visibilitychange", visibility);
    return () => {
      active = false;
      window.removeEventListener("focus", focus);
      window.removeEventListener("pagehide", hide);
      window.removeEventListener("pageshow", pageShow);
      document.removeEventListener("visibilitychange", visibility);
    };
  }, [request.id]);

  const application: CorrectionApplication = {
    expectedVersion: base?.version ?? 0, action: fields.action, reason: fields.reason.trim(),
    ...(fields.action === "change" ? {
      absenceTypeId: Number(fields.absenceTypeId), mode: fields.mode, startDate: fields.startDate,
      endDate: fields.mode === "hourly" ? fields.startDate : fields.endDate,
      ...(fields.mode === "hourly" ? { hours: Number(fields.hours) } : {}),
    } : {}),
  };
  const fingerprint = JSON.stringify(application);
  const valid = !!base && application.reason.length > 0 && application.reason.length <= 1000
    && /[^\s\p{Z}\p{C}]/u.test(application.reason)
    && (fields.action === "cancel" || Number.isSafeInteger(application.absenceTypeId) && application.absenceTypeId! > 0
      && /^\d{4}-\d{2}-\d{2}$/.test(fields.startDate) && fields.startDate >= "2025-01-01" && fields.startDate <= "2100-12-31"
      && (fields.mode === "hourly" ? /^[1-7]$/.test(fields.hours)
        : /^\d{4}-\d{2}-\d{2}$/.test(fields.endDate) && fields.endDate >= fields.startDate && fields.endDate <= "2100-12-31"));
  const currentPreview = preview?.fingerprint === fingerprint ? preview.value : null;
  const locked = busy !== null || checking || uncertain || conflict;
  const revision = base?.effective ?? base?.pending ?? base?.current;
  const types = [...(options?.absenceTypes ?? [])];
  if (revision && !types.some(type => type.id === revision.absenceType.id)) {
    types.unshift({ ...revision.absenceType, availableForCurrentEmployment: true });
  }

  function invalidate() { setPreview(null); setConfirmed(false); setAllowDeficit(false); operation.current = null; }
  function update(change: Partial<Fields>) {
    if (pending.current || refreshing.current || uncertain || conflict) return;
    setFields(current => {
      const next = { ...current, ...change };
      if (change.startDate && (current.startDate === current.endDate || current.endDate < change.startDate)) next.endDate = change.startDate;
      return next;
    });
    setDirty(true); setError(""); invalidate();
  }
  function close() {
    if (pending.current) return;
    if (dirty || uncertain) setClosing(true); else onClose();
  }
  function failed(failure: unknown) {
    if (redirectForAuthError(failure)) { setBase(null); invalidate(); return; }
    setError(correctionError(failure)); invalidate();
    if (correctionConflict(failure)) setConflict(true);
    if (failure instanceof ApiError && [403, 404].includes(failure.status)) setBase(null);
  }

  async function showPreview() {
    if (pending.current || refreshing.current || uncertain || conflict || !valid || !base) return;
    const sequence = ++readSequence.current;
    activity.current = "preview"; pending.current = true; setBusy("preview"); setError(""); invalidate();
    try {
      const result = await previewCorrection(base.id, application);
      if (alive.current && sequence === readSequence.current) setPreview({ fingerprint, value: result.preview });
    } catch (failure) { if (alive.current && sequence === readSequence.current) failed(failure); }
    finally { if (sequence === readSequence.current) { activity.current = null; pending.current = false; if (alive.current) setBusy(null); } }
  }

  async function reload() {
    if (pending.current || refreshing.current || uncertain) return;
    const sequence = ++readSequence.current;
    activity.current = "reload"; pending.current = true; setBusy("reload"); setError(""); setBase(null); invalidate();
    try {
      const [fresh, freshOptions] = await Promise.all([getRequest(request.id), getRequestOptions()]);
      if (!alive.current || sequence !== readSequence.current) return;
      if (fresh.request.id !== request.id) throw new ApiError(0, "INVALID_RESPONSE");
      setOptions(freshOptions); setOptionsError("");
      if (!canCorrect(fresh.request)) { setError("Ten wniosek nie pozwala już na korektę. Zamknij okno i sprawdź jego aktualny stan."); return; }
      draftVersion.current = fresh.request.version;
      setBase(fresh.request); setFields(initialFields(fresh.request)); setConflict(false); setDirty(false);
    } catch (failure) { if (alive.current && sequence === readSequence.current) failed(failure); }
    finally { if (sequence === readSequence.current) { activity.current = null; pending.current = false; if (alive.current) setBusy(null); } }
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (pending.current || refreshing.current || conflict || !base) return;
    if (!uncertain && (!valid || !currentPreview || !confirmed
      || currentPreview.requiresDeficitConfirmation && (!user.isAdministrator || !allowDeficit))) return;
    if (!uncertain && currentPreview) {
      operation.current = { ...application, operationKey: crypto.randomUUID(), previewToken: currentPreview.previewToken, confirm: true,
        ...(currentPreview.requiresDeficitConfirmation && user.isAdministrator && allowDeficit ? { allowDeficit: true } : {}) };
    }
    const body = operation.current;
    if (!body) return;
    activity.current = "save"; pending.current = true; setBusy("save"); setError("");
    try {
      await saveCorrection(base.id, body);
      if (alive.current) onSaved();
    } catch (failure) {
      if (!alive.current) return;
      if (unconfirmedRequest(failure)) {
        uncertainWrite.current = true; setUncertain(true);
        setError("Nie otrzymaliśmy potwierdzenia zapisu. Ponów tę samą korektę — zachowamy jej dane i rozpoznamy wcześniejszy zapis.");
      } else { uncertainWrite.current = false; setUncertain(false); failed(failure); }
    } finally { activity.current = null; pending.current = false; if (alive.current) setBusy(null); }
  }

  return <RequestModal title="Korekta nieobecności" eyebrow="Obsługa zespołu" description="Zmień wcześniejszy wniosek po sprawdzeniu skutków. Uzasadnienie zostanie zapisane w historii." onClose={close} closeDisabled={busy !== null || checking} closeLabel="Zamknij korektę">
    {closing ? <div className="requests-dialog-body">
      <h3>{uncertain ? "Zapis mógł zostać wykonany" : "Zamknąć korektę?"}</h3>
      <p>{uncertain ? "Przed kolejną operacją sprawdź aktualny wniosek i historię. Możesz też wrócić i bezpiecznie ponowić zapis." : "Niezapisane zmiany zostaną pominięte."}</p>
      <div className="requests-inline-actions"><button type="button" className="button button-secondary" onClick={() => setClosing(false)}>Wróć do korekty</button><button type="button" className="button button-primary" onClick={onClose}>Zamknij korektę</button></div>
    </div> : busy === "reload" || checking ? <div className="requests-dialog-body" role="status">Wczytywanie aktualnego wniosku…</div>
      : !base ? <><div className="requests-dialog-body"><p className="message message-error" role="alert">{error || "Nie masz dostępu do korekty tego wniosku."}</p>{conflict && <button type="button" className="button button-secondary" onClick={() => void reload()}>Wczytaj aktualne dane</button>}</div><div className="requests-dialog-footer"><button type="button" className="button button-secondary" onClick={onClose}>Wróć do wniosku</button></div></>
        : optionsError ? <div className="requests-dialog-body"><p className="message message-error" role="alert">{optionsError}</p><button type="button" className="button button-secondary" onClick={() => setOptionsAttempt(value => value + 1)}>Spróbuj ponownie</button></div>
          : !options ? <div className="requests-dialog-body" role="status">Wczytywanie formularza korekty…</div>
            : <form onSubmit={event => void submit(event)} aria-busy={busy !== null} className="correction-form">
              <div className="requests-dialog-body">
                <p className="correction-person"><strong>{base.user.firstName} {base.user.lastName}</strong><span>Wniosek #{base.id}</span></p>
                <fieldset className="requests-fields" disabled={locked}>
                  <fieldset className="requests-mode correction-actions"><legend>Zakres korekty</legend>
                    <label className={fields.action === "change" ? "is-selected" : ""}><input type="radio" name={`${id}-action`} checked={fields.action === "change"} onChange={() => update({ action: "change" })} />Zmień termin lub wymiar</label>
                    <label className={fields.action === "cancel" ? "is-selected" : ""}><input type="radio" name={`${id}-action`} checked={fields.action === "cancel"} onChange={() => update({ action: "cancel" })} />Anuluj nieobecność</label>
                  </fieldset>
                  {fields.action === "change" ? <>
                    <div className="field"><label htmlFor={`${id}-type`}>Rodzaj nieobecności</label><select id={`${id}-type`} value={fields.absenceTypeId} onChange={event => update({ absenceTypeId: event.target.value })} required>{types.map(type => <option key={type.id} value={type.id}>{type.name}</option>)}</select></div>
                    <fieldset className="requests-mode"><legend>Wymiar nieobecności</legend><label className={fields.mode === "full_days" ? "is-selected" : ""}><input type="radio" name={`${id}-mode`} checked={fields.mode === "full_days"} onChange={() => update({ mode: "full_days" })} />Całe dni <span>8 h dziennie</span></label><label className={fields.mode === "hourly" ? "is-selected" : ""}><input type="radio" name={`${id}-mode`} checked={fields.mode === "hourly"} onChange={() => update({ mode: "hourly" })} />Część dnia <span>1–7 godzin</span></label></fieldset>
                    <div className="requests-date-fields"><div className="field"><label htmlFor={`${id}-from`}>{fields.mode === "hourly" ? "Data" : "Data od"}</label><input id={`${id}-from`} type="date" min="2025-01-01" max="2100-12-31" required value={fields.startDate} onChange={event => update({ startDate: event.target.value })} /></div>
                      {fields.mode === "full_days" ? <div className="field"><label htmlFor={`${id}-to`}>Data do</label><input id={`${id}-to`} type="date" min={fields.startDate || "2025-01-01"} max="2100-12-31" required value={fields.endDate} onChange={event => update({ endDate: event.target.value })} /></div> : <div className="field"><label htmlFor={`${id}-hours`}>Liczba godzin</label><select id={`${id}-hours`} value={fields.hours} onChange={event => update({ hours: event.target.value })}>{[1, 2, 3, 4, 5, 6, 7].map(hours => <option key={hours} value={hours}>{hours} h</option>)}</select></div>}
                    </div>
                    <RequestDurationSummary mode={fields.mode} startDate={fields.startDate} endDate={fields.mode === "hourly" ? fields.startDate : fields.endDate} hours={fields.mode === "hourly" ? Number(fields.hours) : undefined} />
                    <p className="requests-field-note">Podgląd uwzględnia dni robocze i zapisane zasady rozliczenia. Godziny oznaczają wymiar, bez pory dnia.</p>
                  </> : <p className="correction-cancel-note">{base.effective ? "Anulowanie zakończy obowiązujący urlop i rozliczy zwrot godzin." : "Oczekujący wniosek zostanie wycofany, a jego rezerwacja zwolniona."}</p>}
                  <div className="field correction-reason"><label htmlFor={`${id}-reason`}>Uzasadnienie korekty</label><textarea id={`${id}-reason`} value={fields.reason} onChange={event => update({ reason: event.target.value })} required maxLength={1000} rows={3} /><small>Wymagane, do 1000 znaków. Widoczne w historii wniosku.</small></div>
                  <button type="button" className="button button-secondary" disabled={!valid || locked} onClick={() => void showPreview()}>{busy === "preview" ? "Sprawdzanie skutków…" : "Sprawdź skutki korekty"}</button>
                </fieldset>
                {busy === "preview" && <p role="status" className="requests-preview-loading">Obliczamy wpływ korekty. Podgląd nie zmienia wniosku ani salda.</p>}
                {currentPreview && <>
                  <CorrectionImpact preview={currentPreview} pendingInitial={!base.effective} typeName={typeId => revision?.absenceType.id === typeId ? revision.absenceType.name : types.find(type => type.id === typeId)?.name ?? "Rodzaj nieobecności"} />
                  <fieldset className="correction-confirmations" disabled={locked}>
                    <label><input type="checkbox" checked={confirmed} onChange={event => setConfirmed(event.target.checked)} />Potwierdzam przedstawione skutki korekty</label>
                    {currentPreview.requiresDeficitConfirmation && (user.isAdministrator ? <label className="correction-deficit"><input type="checkbox" checked={allowDeficit} onChange={event => setAllowDeficit(event.target.checked)} />Potwierdzam zwiększenie niedoboru</label> : <p role="alert">Tę korektę musi wykonać administrator, ponieważ zwiększa niedobór godzin.</p>)}
                  </fieldset>
                </>}
                {error && <div className="message message-error" role="alert">{error}</div>}
                {conflict && <button type="button" className="button button-secondary correction-reload" disabled={busy !== null} onClick={() => void reload()}>Wczytaj aktualne dane</button>}
              </div>
              <div className="requests-dialog-footer"><button type="button" className="button button-secondary" disabled={busy !== null} onClick={close}>Wróć</button><button type="submit" className="button button-primary" disabled={busy !== null || conflict || !valid || (!uncertain && (!currentPreview || !confirmed || currentPreview.requiresDeficitConfirmation && (!user.isAdministrator || !allowDeficit)))}>{busy === "save" ? "Zapisywanie…" : uncertain ? "Ponów zapis korekty" : "Zapisz korektę"}</button></div>
            </form>}
  </RequestModal>;
}

function Snapshot({ title, value, typeName }: { title: string; value: CorrectionSnapshot; typeName: string }) {
  return <div className="correction-snapshot"><h4>{title}</h4><strong>{value.cancelled ? "Nieobecność zakończona" : requestRange(value)}</strong><span>{typeName}</span><b>{hoursLabel(value.totalHours)}</b>{!value.deductsBalance && <small>Nie pomniejsza limitu płatnych dni.</small>}</div>;
}

function CorrectionImpact({ preview, pendingInitial, typeName }: { preview: CorrectionPreview; pendingInitial: boolean; typeName: (id: number) => string }) {
  const pools = preview.impact.pools;
  const origins = pools ? [...new Set([...pools.before, ...pools.after].map(pool => pool.originYear))].sort((a, b) => a - b) : [];
  return <section className="correction-preview" aria-label="Podgląd korekty">
    <h3>Skutki korekty</h3>
    <div className="correction-snapshots"><Snapshot title="Przed korektą" value={preview.before} typeName={typeName(preview.before.absenceTypeId)} /><Snapshot title="Po korekcie" value={preview.after} typeName={typeName(preview.after.absenceTypeId)} /></div>
    <p>{pendingInitial ? preview.action === "cancel" ? "Wniosek zostanie wycofany bez zatwierdzenia." : "Poprawiony wniosek nadal będzie oczekiwał na osobną decyzję." : "Korekta zostanie zapisana od razu, wraz z decyzją i uzasadnieniem."}</p>
    <div className="correction-years"><h4>Godziny obciążające saldo według roku nieobecności</h4>{preview.impact.hoursByYear.map(year => <div key={year.year}><strong>{year.year}</strong><span>{year.beforeHours} h → {year.afterHours} h</span><small>{year.deltaHours > 0 ? `Wzrost o ${year.deltaHours} h` : year.deltaHours < 0 ? `Spadek o ${-year.deltaHours} h` : "Bez zmiany wymiaru obciążającego saldo"}</small></div>)}</div>
    {pools ? <details className="correction-pools"><summary>Wpływ na pule godzin</summary><p>Pule pokazujemy według roku pochodzenia godzin.</p>{origins.map(originYear => {
      const before = pools.before.find(pool => pool.originYear === originYear);
      const after = pools.after.find(pool => pool.originYear === originYear);
      return <section key={originYear}><h4>Pula {originYear}</h4><dl><div><dt>Dostępne przed</dt><dd>{hoursLabel(before?.availableHours ?? 0)}</dd></div><div><dt>Dostępne po</dt><dd className={(after?.availableHours ?? 0) < 0 ? "has-deficit" : ""}>{hoursLabel(after?.availableHours ?? 0)}</dd></div><div><dt>Rezerwacje przed → po</dt><dd>{before?.reservedHours ?? 0} h → {after?.reservedHours ?? 0} h</dd></div><div><dt>Obciążenia przed → po</dt><dd>{before?.chargedHours ?? 0} h → {after?.chargedHours ?? 0} h</dd></div></dl></section>;
    })}</details> : <p className="requests-scope-note">Szczegóły bieżących pul są poza Twoim zakresem dostępu. Podgląd uwzględnia poprawność rozliczenia.</p>}
    {preview.requiresDeficitConfirmation && <p className="correction-deficit-note">Korekta zwiększy niedobór. Pozostałe zatwierdzone urlopy pozostaną zachowane; nowe obciążenia zwiększające niedobór będą blokowane.</p>}
  </section>;
}
