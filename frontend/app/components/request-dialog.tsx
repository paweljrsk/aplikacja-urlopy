"use client";

import { useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from "react";
import { redirectForAuthError } from "@/lib/auth-api";
import { hoursLabel } from "@/lib/balance-api";
import { RequestDurationSummary } from "./request-duration-summary";
import {
  getRequest, getRequestOptions, previewRequest, requestConflict, requestError, saveRequest, unconfirmedRequest,
  type RequestApplication, type RequestDetail, type RequestFormMode, type RequestOptions, type RequestPreview,
} from "@/lib/request-api";
import "./requests.css";

export function RequestModal({ title, description, children, onClose, closeDisabled = false, eyebrow = "Twoje nieobecności", closeLabel = "Zamknij formularz" }: {
  title: string; description?: string; children: ReactNode; onClose: () => void; closeDisabled?: boolean; eyebrow?: string; closeLabel?: string;
}) {
  const dialog = useRef<HTMLDialogElement>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const id = useId();
  useEffect(() => {
    const element = dialog.current;
    if (!element) return;
    const headingElement = heading.current;
    const trigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const overflow = document.body.style.overflow;
    element.showModal();
    document.body.style.overflow = "hidden";
    function keepTabInside(event: KeyboardEvent) {
      if (event.key !== "Tab" || !element?.open || event.defaultPrevented) return;
      const active = document.activeElement;
      const otherDialog = active?.closest("dialog[open]");
      if (otherDialog && otherDialog !== element) return;
      // Disabling/removing the focused control can leave focus on body. Capture
      // at document level so the next Tab cannot escape before reaching a dialog.
      // Recompute every time because loading and confirmations change the controls.
      const controls = Array.from(element.querySelectorAll<HTMLElement>("button, a[href], input, select, textarea, summary, [tabindex], [contenteditable=true]"))
        .filter(control => control.tabIndex >= 0 && !control.matches(":disabled") && !control.closest("[inert]")
          && control.getClientRects().length > 0 && !["hidden", "collapse"].includes(getComputedStyle(control).visibility));
      if (!controls.length) {
        event.preventDefault(); headingElement?.focus(); return;
      }
      const first = controls[0];
      const last = controls[controls.length - 1];
      const outsideTabOrder = !controls.some(control => control === active);
      if ((event.shiftKey && (active === first || outsideTabOrder)) || (!event.shiftKey && (active === last || outsideTabOrder))) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
      }
    }
    document.addEventListener("keydown", keepTabInside, true);
    headingElement?.focus();
    return () => {
      document.removeEventListener("keydown", keepTabInside, true);
      element.close();
      document.body.style.overflow = overflow;
      if (trigger?.isConnected) trigger.focus();
    };
  }, []);
  return <dialog ref={dialog} className="requests-dialog" aria-labelledby={`${id}-title`} aria-describedby={description ? `${id}-description` : undefined}
    onCancel={event => { event.preventDefault(); if (!closeDisabled) onClose(); }}>
    <div className="requests-dialog-heading">
      <div><span className="requests-eyebrow">{eyebrow}</span><h2 ref={heading} tabIndex={-1} id={`${id}-title`}>{title}</h2>{description && <p id={`${id}-description`}>{description}</p>}</div>
      <button type="button" className="requests-close" aria-label={closeLabel} onClick={onClose} disabled={closeDisabled}>×</button>
    </div>
    {children}
  </dialog>;
}

type FormFields = { absenceTypeId: string; startDate: string; endDate: string; mode: "full_days" | "hourly"; hours: string };
function initialFields(request?: RequestDetail, date = ""): FormFields {
  const revision = request?.pending ?? request?.effective ?? request?.current;
  return revision ? { absenceTypeId: String(revision.absenceType.id), startDate: revision.startDate, endDate: revision.endDate, mode: revision.mode, hours: String(revision.mode === "hourly" ? revision.totalHours : 3) }
    : { absenceTypeId: "", startDate: date, endDate: date, mode: "full_days", hours: "3" };
}

export function RequestDialog({ mode, request, onClose, onSaved, initialDate }: {
  mode: RequestFormMode; request?: RequestDetail; onClose: () => void; onSaved: () => void; initialDate?: string;
}) {
  const [base, setBase] = useState(request);
  const [fields, setFields] = useState(() => initialFields(request, initialDate));
  const [options, setOptions] = useState<RequestOptions | null>(null);
  const [optionsError, setOptionsError] = useState("");
  const [optionsAttempt, setOptionsAttempt] = useState(0);
  const [preview, setPreview] = useState<{ fingerprint: string; value: RequestPreview } | null>(null);
  const [previewBusy, setPreviewBusy] = useState(false);
  const [previewError, setPreviewError] = useState("");
  const [previewAttempt, setPreviewAttempt] = useState(0);
  const [saveError, setSaveError] = useState("");
  const [busy, setBusy] = useState(false);
  const [uncertain, setUncertain] = useState(false);
  const [conflict, setConflict] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [closing, setClosing] = useState(false);
  const sending = useRef(false);
  const operation = useRef<{ fingerprint: string; key: string } | null>(null);
  const id = useId();

  useEffect(() => {
    let alive = true;
    void getRequestOptions().then(result => {
      if (!alive) return;
      setOptions(result); setOptionsError("");
      setFields(current => ({ ...current,
        absenceTypeId: current.absenceTypeId || String(result.absenceTypes.find(type => type.availableForCurrentEmployment && type.deductsBalance)?.id ?? result.absenceTypes.find(type => type.availableForCurrentEmployment)?.id ?? result.absenceTypes[0]?.id ?? ""),
        startDate: current.startDate || result.today, endDate: current.endDate || current.startDate || result.today,
      }));
    }).catch(failure => {
      if (!alive || redirectForAuthError(failure)) return;
      setOptionsError(requestError(failure));
    });
    return () => { alive = false; };
  }, [optionsAttempt]);

  const application: RequestApplication = {
    absenceTypeId: Number(fields.absenceTypeId), mode: fields.mode, startDate: fields.startDate,
    endDate: fields.mode === "hourly" ? fields.startDate : fields.endDate,
    ...(fields.mode === "hourly" ? { hours: Number(fields.hours) } : {}),
    ...(mode !== "create" ? { expectedVersion: base?.version ?? 0 } : {}),
  };
  const fingerprint = JSON.stringify(application);
  const validFields = application.absenceTypeId > 0 && /^\d{4}-\d{2}-\d{2}$/.test(application.startDate)
    && /^\d{4}-\d{2}-\d{2}$/.test(application.endDate) && application.endDate >= application.startDate
    && (fields.mode !== "hourly" || (/^[1-7]$/.test(fields.hours)));

  useEffect(() => {
    if (!options || !validFields || conflict || uncertain) return;
    let alive = true;
    const timer = window.setTimeout(() => {
      setPreviewBusy(true); setPreviewError("");
      void previewRequest(mode, JSON.parse(fingerprint) as RequestApplication, base?.id).then(result => {
        if (!alive) return;
        setPreview({ fingerprint, value: result.preview });
      }).catch(failure => {
        if (!alive || redirectForAuthError(failure)) return;
        setPreview(null); setPreviewError(requestError(failure));
        if (requestConflict(failure)) setConflict(true);
      }).finally(() => { if (alive) setPreviewBusy(false); });
    }, 350);
    return () => { alive = false; window.clearTimeout(timer); };
  }, [options, validFields, fingerprint, mode, base?.id, conflict, uncertain, previewAttempt]);

  function update(change: Partial<FormFields>) {
    if (sending.current || uncertain || conflict) return;
    setFields(current => {
      const next = { ...current, ...change };
      if (change.startDate && (current.startDate === current.endDate || current.endDate < change.startDate)) next.endDate = change.startDate;
      return next;
    });
    setDirty(true); setPreview(null); setPreviewError(""); setSaveError(""); setPreviewBusy(false); operation.current = null;
  }

  function close() {
    if (sending.current) return;
    if (dirty || uncertain) setClosing(true); else onClose();
  }

  async function reload() {
    if (sending.current) return;
    sending.current = true; setBusy(true); setSaveError("");
    try {
      if (mode !== "create" && base) {
        const result = await getRequest(base.id);
        if (!result.request.actions[mode]) {
          setSaveError("Ten wniosek nie pozwala już na tę zmianę. Zamknij formularz i sprawdź jego szczegóły.");
          return;
        }
        setBase(result.request); setFields(initialFields(result.request));
      }
      setConflict(false); setPreview(null); setPreviewError(""); setDirty(false); operation.current = null;
      setPreviewAttempt(value => value + 1);
    } catch (failure) { if (!redirectForAuthError(failure)) setSaveError(requestError(failure)); }
    finally { sending.current = false; setBusy(false); }
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (sending.current || conflict || !validFields || (!uncertain && preview?.fingerprint !== fingerprint)) return;
    sending.current = true; setBusy(true); setSaveError("");
    if (operation.current?.fingerprint !== fingerprint) operation.current = { fingerprint, key: crypto.randomUUID() };
    try {
      await saveRequest(mode, application, operation.current.key, base?.id);
      onSaved();
    } catch (failure) {
      if (redirectForAuthError(failure)) return;
      if (unconfirmedRequest(failure)) {
        setUncertain(true);
        setSaveError("Nie otrzymaliśmy potwierdzenia zapisu. Ponów wysłanie tych samych danych — aplikacja rozpozna wcześniejszy zapis i nie utworzy duplikatu.");
      } else {
        setUncertain(false); setSaveError(requestError(failure)); setPreview(null); operation.current = null;
        if (requestConflict(failure)) setConflict(true);
        else setPreviewAttempt(value => value + 1);
      }
    } finally { sending.current = false; setBusy(false); }
  }

  const selectedRevision = base?.pending ?? base?.effective ?? base?.current;
  const types = [...(options?.absenceTypes ?? [])];
  if (selectedRevision && !types.some(type => type.id === selectedRevision.absenceType.id)) {
    types.unshift({ ...selectedRevision.absenceType, availableForCurrentEmployment: true });
  }
  const locked = busy || uncertain || conflict;
  const currentPreview = preview?.fingerprint === fingerprint ? preview.value : null;
  const title = mode === "create" ? "Nowy wniosek" : mode === "edit" ? "Edytuj wniosek" : "Zmień termin lub wymiar";
  const description = mode === "change" ? "Do akceptacji zmiany obowiązuje dotychczasowy urlop." : "Wybierz termin. Liczbę godzin i saldo sprawdzimy za Ciebie.";
  const submitLabel = mode === "create" ? "Złóż wniosek" : mode === "edit" ? "Zapisz zmianę" : "Wyślij propozycję";
  return <RequestModal title={title} description={description} onClose={close} closeDisabled={busy}>
    {closing ? <div className="requests-dialog-body">
      <h3>{uncertain ? "Zapis mógł zostać wykonany" : "Zamknąć formularz?"}</h3>
      <p>{uncertain ? "Przed złożeniem kolejnego wniosku sprawdź listę „Moje wnioski”. Możesz też wrócić i bezpiecznie ponowić wysłanie." : "Niewysłane zmiany zostaną pominięte."}</p>
      <div className="requests-inline-actions"><button className="button button-secondary" onClick={() => setClosing(false)}>Wróć do formularza</button><button className="button button-primary" onClick={onClose}>Zamknij formularz</button></div>
    </div> : optionsError ? <div className="requests-dialog-body"><p className="message message-error" role="alert">{optionsError}</p><button className="button button-secondary" onClick={() => setOptionsAttempt(value => value + 1)}>Spróbuj ponownie</button></div>
      : !options ? <div className="requests-dialog-body" role="status">Wczytywanie formularza…</div>
        : <form onSubmit={event => void submit(event)} aria-busy={busy}>
          <div className="requests-dialog-body">
            <fieldset className="requests-fields" disabled={locked}>
              <div className="field"><label htmlFor={`${id}-type`}>Rodzaj nieobecności</label><select id={`${id}-type`} value={fields.absenceTypeId} onChange={event => update({ absenceTypeId: event.target.value })} required>
                {!types.length && <option value="">Brak dostępnych rodzajów</option>}
                {types.map(type => <option key={type.id} value={type.id}>{type.name}</option>)}
              </select></div>
              <fieldset className="requests-mode"><legend>Wymiar nieobecności</legend>
                <label className={fields.mode === "full_days" ? "is-selected" : ""}><input type="radio" name={`${id}-mode`} value="full_days" checked={fields.mode === "full_days"} onChange={() => update({ mode: "full_days" })} />Całe dni <span>8 h dziennie</span></label>
                <label className={fields.mode === "hourly" ? "is-selected" : ""}><input type="radio" name={`${id}-mode`} value="hourly" checked={fields.mode === "hourly"} onChange={() => update({ mode: "hourly" })} />Część dnia <span>1–7 godzin</span></label>
              </fieldset>
              <div className="requests-date-fields">
                <div className="field"><label htmlFor={`${id}-from`}>{fields.mode === "hourly" ? "Data" : "Data od"}</label><input id={`${id}-from`} type="date" required min={options.today} max="2100-12-31" value={fields.startDate} onChange={event => update({ startDate: event.target.value })} /></div>
                {fields.mode === "full_days" ? <div className="field"><label htmlFor={`${id}-to`}>Data do</label><input id={`${id}-to`} type="date" required min={fields.startDate || options.today} max="2100-12-31" value={fields.endDate} onChange={event => update({ endDate: event.target.value })} /></div>
                  : <div className="field"><label htmlFor={`${id}-hours`}>Liczba godzin</label><select id={`${id}-hours`} value={fields.hours} onChange={event => update({ hours: event.target.value })}>{[1, 2, 3, 4, 5, 6, 7].map(hours => <option key={hours} value={hours}>{hours} h</option>)}</select></div>}
              </div>
              <RequestDurationSummary mode={application.mode} startDate={application.startDate} endDate={application.endDate} hours={application.hours} />
              <p className="requests-field-note">{fields.mode === "full_days" ? "Weekendy i dni wolne nie pomniejszają salda." : "Podaj wymiar nieobecności, bez godzin rozpoczęcia i zakończenia."}</p>
            </fieldset>
            {previewBusy && <p role="status" className="requests-preview-loading">Sprawdzamy godziny i dostępne saldo…</p>}
            {previewError && <div className="message message-error" role="alert">{previewError}</div>}
            {currentPreview && <section className="requests-preview" role="status" aria-label="Podsumowanie wniosku">
              <div className="requests-preview-total"><span>Wymiar nieobecności</span><strong>{currentPreview.totalHours} h</strong></div>
              <p className="requests-preview-conversion">{hoursLabel(currentPreview.totalHours)}</p>
              {!currentPreview.deductsBalance && <p>Ten rodzaj nieobecności nie pomniejsza limitu płatnych dni.</p>}
              {currentPreview.years.map(year => <div key={year.year} className="requests-preview-year"><strong>Saldo {year.year}</strong><dl><div><dt>Dostępne teraz</dt><dd>{hoursLabel(year.beforeAvailableHours)}</dd></div><div><dt>Po wysłaniu</dt><dd>{hoursLabel(year.afterAvailableHours)}</dd></div></dl>{year.additionalReservedHours > 0 && mode === "change" && <p>Dodatkowa rezerwacja: {year.additionalReservedHours} h.</p>}</div>)}
              {mode === "change" && <p className="requests-field-note">Skrócenie lub zmiana rodzaju zwolni godziny dopiero po akceptacji.</p>}
            </section>}
            {saveError && <div className="message message-error" role="alert">{saveError}</div>}
            {conflict && <button type="button" className="button button-secondary" disabled={busy} onClick={() => void reload()}>Wczytaj aktualne dane</button>}
            {!types.length && <p className="message message-info">Administrator musi udostępnić rodzaj nieobecności, zanim złożysz wniosek.</p>}
          </div>
          <div className="requests-dialog-footer"><button type="button" className="button button-secondary" onClick={close} disabled={busy}>Anuluj</button><button type="submit" className="button button-primary" disabled={busy || conflict || !validFields || (!uncertain && !currentPreview)}>{busy ? "Wysyłanie…" : uncertain ? "Ponów wysłanie" : submitLabel}</button></div>
        </form>}
  </RequestModal>;
}
