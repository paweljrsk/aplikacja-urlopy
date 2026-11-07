"use client";

import { hoursLabel } from "@/lib/balance-api";
import { requestRange, requestStatusLabel, requestTimestamp, type RequestDetail, type RequestRevision, type RequestStatus } from "@/lib/request-api";

export function RequestStatusBadge({ status }: { status: RequestStatus | null }) {
  return <span className={`requests-status requests-status-${status ?? "history"}`}><span aria-hidden="true">{status === "approved" ? "✓" : status?.includes("pending") ? "◷" : "−"}</span>{requestStatusLabel(status)}</span>;
}

function RevisionCard({ revision, title, tone = "" }: { revision: RequestRevision; title: string; tone?: string }) {
  return <section className={`requests-revision-card ${tone}`}><h3>{title}</h3><strong>{requestRange(revision)}</strong><span>{revision.absenceType.name}</span><p>{hoursLabel(revision.totalHours)}</p>
    {revision.mode === "hourly" && <small>Nieobecność przez część dnia, bez określonej pory.</small>}
  </section>;
}

// The API has already authorized every returned revision separately. Never infer
// the status or contents of a missing current/effective/pending version.
export function RequestRevisionDetails({ request }: { request: RequestDetail }) {
  const last = request.current ?? request.latestVisibleRevision;
  return <>
    <RequestStatusBadge status={request.status} />
    {!request.currentVisible && <p className="requests-scope-note">Widoczne są tylko wersje wniosku dostępne w Twoim zakresie uprawnień.</p>}
    {(request.status === "change_pending" || request.status === "cancellation_pending") && <p className="requests-explanation">{request.status === "change_pending" ? "Zmiana czeka na decyzję. Do tego czasu obowiązuje zatwierdzony termin." : "Anulowanie czeka na decyzję. Urlop nadal obowiązuje, a saldo pozostaje bez zmian."}</p>}
    <div className="requests-revisions">
      {request.effective && <RevisionCard revision={request.effective} title="Obowiązujący urlop" tone="is-effective" />}
      {request.pending && request.pending.kind !== "cancellation" && <RevisionCard revision={request.pending} title={request.pending.kind === "change" ? "Proponowana zmiana" : "Oczekujący wniosek"} tone="is-proposed" />}
      {request.pending?.kind === "cancellation" && !request.effective && <RevisionCard revision={request.pending} title="Prośba o anulowanie" tone="is-proposed" />}
      {!request.effective && !request.pending && <RevisionCard revision={last} title="Termin wniosku" />}
    </div>
    {last.decision?.reason && <div className="requests-decision-reason"><strong>Powód decyzji</strong><p>{last.decision.reason}</p></div>}
  </>;
}

export function RequestHistory({ request }: { request: RequestDetail }) {
  const history = [...request.history].reverse();
  return <details className="requests-history"><summary>Historia wniosku <span>{history.length} {history.length === 1 ? "wersja" : history.length < 5 ? "wersje" : "wersji"}</span></summary>
    <ol>{history.map(revision => <li key={revision.id}><div className="requests-history-heading"><strong>{revision.kind === "cancellation" ? "Prośba o anulowanie" : revision.kind === "change" ? "Zmiana wniosku" : "Wniosek"} · wersja {revision.revision}</strong><span>{revision.decision ? ({ approved: "Zaakceptowano", rejected: "Odrzucono", withdrawn: "Wycofano", superseded: "Zastąpiono nowszą wersją" })[revision.decision.outcome] : "Oczekuje na decyzję"}</span></div>
      <p>{requestRange(revision)} · {revision.totalHours} h · {revision.absenceType.name}</p>
      <small>Zgłoszono: {requestTimestamp(revision.submitted.at)}{revision.submitted.actorName && ` · ${revision.submitted.actorName}`}</small>
      {revision.submitted.reason && <p className="requests-history-reason">Uzasadnienie korekty: {revision.submitted.reason}</p>}
      {revision.decision && <><small>Decyzja: {requestTimestamp(revision.decision.at)}{revision.decision.actorName && ` · ${revision.decision.actorName}`}</small>{revision.decision.reason && <p className="requests-history-reason">Uzasadnienie: {revision.decision.reason}</p>}</>}
    </li>)}</ol>
  </details>;
}
