"use client";

import { useEffect, useRef, useState } from "react";
import { ApiError, redirectForAuthError } from "@/lib/auth-api";
import { hoursLabel } from "@/lib/balance-api";
import { previewDecision, saveDecision, type DecisionAction, type DecisionApplication, type DecisionPreview } from "@/lib/managed-requests-api";
import { getRequest, requestConflict, requestError, requestRange, unconfirmedRequest, type RequestDetail } from "@/lib/request-api";
import { RequestModal } from "./request-dialog";
import { RequestRevisionDetails } from "./request-details";

function explanation(request: RequestDetail, action: DecisionAction) {
  if (request.pending?.kind === "cancellation") return action === "approve"
    ? "Urlop zostanie anulowany. Godziny obciążające limit wrócą do dostępnego salda."
    : "Prośba o anulowanie zostanie odrzucona. Zatwierdzony urlop pozostanie bez zmian.";
  if (request.pending?.kind === "change") return action === "approve"
    ? "Proponowany termin i wymiar zastąpią obowiązujący urlop."
    : "Propozycja zostanie odrzucona. Obowiązujący urlop pozostanie bez zmian, a dodatkowa rezerwacja zostanie zwolniona.";
  return action === "approve" ? "Rezerwacja zmieni się w zatwierdzony urlop. Nie potrącimy tych samych godzin ponownie."
    : "Wniosek zostanie odrzucony, a jego rezerwacja zwolniona.";
}

export function RequestDecisionDialog({ request: initialRequest, action, onClose, onSaved, onRefresh }: {
  request: RequestDetail; action: DecisionAction; onClose: () => void; onSaved: () => void; onRefresh: () => void;
}) {
  const [base, setBase] = useState<RequestDetail | null>(initialRequest);
  const request = base ?? initialRequest;
  const [revalidating, setRevalidating] = useState(false);
  const [reason, setReason] = useState("");
  const [preview, setPreview] = useState<DecisionPreview | null>(null);
  const [previewBusy, setPreviewBusy] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [uncertain, setUncertain] = useState(false);
  const [conflict, setConflict] = useState(false);
  const [denied, setDenied] = useState(false);
  const alive = useRef(true);
  const sequence = useRef(0);
  const reauthorization = useRef(0);
  const sending = useRef(false);
  const operation = useRef<{ body: DecisionApplication; key: string } | null>(null);
  const valid = base !== null && !revalidating && request.version !== null && request.actions[action] && (action !== "reject" || reason.trim().length > 0 && reason.length <= 1000);
  useEffect(() => { alive.current = true; return () => { alive.current = false; }; }, []);
  useEffect(() => {
    let active = true;
    const invalidate = () => { ++reauthorization.current; };
    const refresh = async () => {
      if (busy || previewBusy || uncertain || sending.current) return;
      const current = ++reauthorization.current;
      setBase(null); setPreview(null); setRevalidating(true); setError("");
      try {
        const result = await getRequest(initialRequest.id);
        if (!active || current !== reauthorization.current) return;
        if (result.request.id !== initialRequest.id) throw new ApiError(0, "INVALID_RESPONSE");
        if (!result.request.actions[action]) throw new ApiError(403, "FORBIDDEN");
        setBase(result.request); setDenied(false);
        if (result.request.version !== initialRequest.version) { setConflict(true); setError("Wniosek zmienił się w międzyczasie. Wczytaj aktualne dane przed decyzją."); }
      } catch (failure) {
        if (active && current === reauthorization.current && !redirectForAuthError(failure)) { setDenied(true); setError(requestError(failure)); }
      } finally { if (active && current === reauthorization.current) setRevalidating(false); }
    };
    const hide = () => {
      if (busy || previewBusy || uncertain || sending.current) return;
      invalidate(); setBase(null); setPreview(null); setRevalidating(true);
    };
    const onFocus = () => { void refresh(); };
    const visibility = () => { if (document.visibilityState === "visible") onFocus(); else hide(); };
    window.addEventListener("focus", onFocus); document.addEventListener("visibilitychange", visibility);
    return () => { active = false; invalidate(); window.removeEventListener("focus", onFocus); document.removeEventListener("visibilitychange", visibility); };
  }, [initialRequest.id, initialRequest.version, action, busy, previewBusy, uncertain]);
  function payload(): DecisionApplication {
    return { expectedVersion: request.version!, action, ...(action === "reject" ? { reason: reason.trim() } : {}) };
  }
  function report(failure: unknown) {
    if (redirectForAuthError(failure)) return;
    if (failure instanceof ApiError && [403, 404].includes(failure.status)) { setDenied(true); setPreview(null); }
    setConflict(requestConflict(failure));
    setError(requestError(failure));
  }
  async function inspect() {
    if (!valid || previewBusy || busy || uncertain || conflict || denied) return;
    const current = ++sequence.current;
    setPreviewBusy(true); setPreview(null); setError("");
    try {
      const result = await previewDecision(request.id, payload());
      if (!alive.current || current !== sequence.current) return;
      if (result.preview.id !== request.id || result.preview.version !== request.version || result.preview.action !== action) throw new ApiError(0, "INVALID_RESPONSE");
      setPreview(result.preview);
    } catch (failure) { if (alive.current && current === sequence.current) report(failure); }
    finally { if (alive.current && current === sequence.current) setPreviewBusy(false); }
  }
  async function confirm() {
    if (sending.current || denied || conflict || (!uncertain && (!valid || !preview))) return;
    operation.current ??= { body: payload(), key: crypto.randomUUID() };
    sending.current = true; setBusy(true); setError("");
    try {
      await saveDecision(request.id, operation.current.body, operation.current.key);
      if (alive.current) onSaved();
    } catch (failure) {
      if (!alive.current) return;
      if (unconfirmedRequest(failure)) {
        setUncertain(true);
        setError("Nie otrzymaliśmy potwierdzenia. Ponów tę samą decyzję. Ponowienie nie rozliczy wniosku drugi raz.");
      } else {
        setUncertain(false); operation.current = null; setPreview(null); report(failure);
      }
    } finally { sending.current = false; if (alive.current) setBusy(false); }
  }
  function changeReason(value: string) {
    ++sequence.current; setPreviewBusy(false); setReason(value); setPreview(null); setError(""); operation.current = null;
  }
  return <RequestModal title={action === "approve" ? "Akceptacja wniosku" : "Odrzucenie wniosku"} eyebrow="Obsługa zespołu" closeLabel="Zamknij decyzję" onClose={onClose} closeDisabled={busy}>
    <div className="requests-dialog-body managed-decision-body">
      {revalidating && <p role="status">Sprawdzanie dostępu do wniosku…</p>}
      {!denied && base && !revalidating && <>
        <p className="managed-decision-person"><strong>{request.user.firstName} {request.user.lastName}</strong><span>Wniosek #{request.id} · {requestRange(request.current ?? request.latestVisibleRevision)}</span></p>
        <RequestRevisionDetails request={request} />
        <p>{explanation(request, action)}</p>
        {action === "reject" && <div className="field"><label htmlFor="decision-reason">Powód odrzucenia</label><textarea id="decision-reason" required maxLength={1000} rows={3} value={reason} disabled={busy || uncertain || conflict} onChange={event => changeReason(event.target.value)} /><p className="field-hint">Uzasadnienie zobaczy autor wniosku.</p></div>}
        {previewBusy && <p role="status">Sprawdzanie wpływu decyzji…</p>}
        {preview && <section className="requests-preview managed-decision-preview" aria-label="Wpływ decyzji na saldo">
          <h3>Wpływ na saldo</h3>
          {preview.years === null ? <p>Bieżące saldo tej osoby nie jest dostępne w Twoim zakresie uprawnień. Decyzja dotyczy widocznego wniosku.</p> : preview.years.length === 0 ? <p>Ten wniosek nie zmienia salda płatnych dni wolnych.</p> : preview.years.map(year => <div className="requests-preview-year" key={year.year}>
            <strong>{year.year}</strong><dl>
              <div><dt>Dostępne teraz</dt><dd>{hoursLabel(year.beforeAvailableHours)}</dd></div>
              <div><dt>Po decyzji</dt><dd>{hoursLabel(year.afterAvailableHours)}</dd></div>
              <div><dt>Rezerwacja</dt><dd>{year.beforeReservedHours} h → {year.afterReservedHours} h</dd></div>
              <div><dt>Zatwierdzone</dt><dd>{year.beforeApprovedHours} h → {year.afterApprovedHours} h</dd></div>
            </dl>
          </div>)}
          <p>To podgląd. Decyzja zostanie zapisana po potwierdzeniu.</p>
        </section>}
      </>}
      {error && <div className="message message-error" role="alert">{error}</div>}
      {(conflict || denied) && <button className="button button-secondary managed-refresh" onClick={onRefresh}>Wczytaj aktualne dane</button>}
    </div>
    <div className="requests-dialog-footer">
      <button className="button button-secondary" disabled={busy} onClick={onClose}>Wróć</button>
      {!denied && !conflict && !revalidating && (preview || uncertain
        ? <button className="button button-primary" disabled={busy} onClick={() => void confirm()}>{busy ? "Zapisywanie…" : uncertain ? "Ponów decyzję" : action === "approve" ? "Potwierdź akceptację" : "Potwierdź odrzucenie"}</button>
        : <button className="button button-primary" disabled={!valid || previewBusy} onClick={() => void inspect()}>{previewBusy ? "Sprawdzanie…" : "Sprawdź wpływ"}</button>)}
    </div>
  </RequestModal>;
}
