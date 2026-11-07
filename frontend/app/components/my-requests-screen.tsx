"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { redirectForAuthError, type CurrentUser } from "@/lib/auth-api";
import {
  getMyRequests, getRequest, requestAction, requestConflict, requestError, requestRange, requestStatusLabel, unconfirmedRequest,
  type RequestDetail, type RequestFormMode, type RequestStatus, type RequestsPage,
} from "@/lib/request-api";
import { AppShell, useWorkspaceUser } from "./app-shell";
import { RequestDialog, RequestModal } from "./request-dialog";
import { RequestHistory, RequestRevisionDetails, RequestStatusBadge } from "./request-details";
import "./requests.css";

function RequestActionDialog({ request, action, onClose, onSaved, onRefresh }: {
  request: RequestDetail; action: "withdraw" | "cancel"; onClose: () => void; onSaved: () => void; onRefresh: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [uncertain, setUncertain] = useState(false);
  const [conflict, setConflict] = useState(false);
  const submitting = useRef(false);
  const key = useRef<string | null>(null);
  const proposal = request.pending?.kind !== "initial" && !!request.effective;
  async function confirm() {
    if (submitting.current || conflict || request.version === null) return;
    submitting.current = true; setBusy(true); setError("");
    key.current ??= crypto.randomUUID();
    try {
      await requestAction(request.id, action, request.version, key.current);
      onSaved();
    } catch (failure) {
      if (redirectForAuthError(failure)) return;
      setUncertain(unconfirmedRequest(failure));
      setConflict(requestConflict(failure));
      setError(unconfirmedRequest(failure) ? "Nie otrzymaliśmy potwierdzenia. Ponów tę samą operację — godziny nie zostaną zwrócone drugi raz. Przed inną zmianą sprawdź aktualny wniosek." : requestError(failure));
    } finally { submitting.current = false; setBusy(false); }
  }
  const title = action === "cancel" ? "Poproś o anulowanie urlopu" : proposal ? "Wycofaj propozycję" : "Wycofaj wniosek";
  return <RequestModal title={title} onClose={onClose} closeDisabled={busy}>
    <div className="requests-dialog-body">
      <p className="requests-confirm-date">{requestRange(request.current ?? request.latestVisibleRevision)}</p>
      <p>{action === "cancel" ? "Urlop będzie nadal obowiązywał do decyzji przełożonego. Godziny wrócą do salda dopiero po zaakceptowaniu anulowania." : proposal ? "Dotychczasowy zaakceptowany urlop pozostanie bez zmian. Zwolnimy tylko dodatkową rezerwację tej propozycji." : "Wniosek przestanie oczekiwać na decyzję. Zarezerwowane godziny wrócą do dostępnego salda."}</p>
      {error && <div className="message message-error" role="alert">{error}</div>}
      {conflict && <button className="button button-secondary" onClick={onRefresh}>Wczytaj aktualne dane</button>}
    </div>
    <div className="requests-dialog-footer"><button className="button button-secondary" onClick={onClose} disabled={busy}>Wróć</button><button className="button button-primary" onClick={() => void confirm()} disabled={busy || conflict}>{busy ? "Wysyłanie…" : uncertain ? "Ponów wysłanie" : action === "cancel" ? "Wyślij prośbę o anulowanie" : "Potwierdź wycofanie"}</button></div>
  </RequestModal>;
}

function RequestDetails({ request, onClose, onEdit, onAction, actorId }: {
  request: RequestDetail; onClose: () => void; onEdit: (mode: "edit" | "change") => void; onAction: (action: "withdraw" | "cancel") => void; actorId: number;
}) {
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => { heading.current?.focus(); }, [request.id]);
  const own = request.user.id === actorId;
  return <section className="requests-detail" aria-labelledby="request-detail-title">
    <div className="requests-detail-header"><div><span className="requests-eyebrow">Wniosek #{request.id}</span><h2 id="request-detail-title" ref={heading} tabIndex={-1}>Szczegóły wniosku</h2>{!own && <p>{request.user.firstName} {request.user.lastName}</p>}</div><button className="button button-secondary button-small" onClick={onClose}>Zamknij szczegóły</button></div>
    <RequestRevisionDetails request={request} />
    {own && <div className="requests-inline-actions">
      {request.actions.edit && <button className="button button-primary" onClick={() => onEdit("edit")}>Edytuj wniosek</button>}
      {request.actions.change && <button className="button button-primary" onClick={() => onEdit("change")}>Zmień termin lub wymiar</button>}
      {request.actions.withdraw && <button className="button button-secondary" onClick={() => onAction("withdraw")}>{request.effective ? "Wycofaj propozycję" : "Wycofaj wniosek"}</button>}
      {request.actions.cancel && <button className="button button-secondary" onClick={() => onAction("cancel")}>Anuluj urlop</button>}
    </div>}
    <RequestHistory request={request} />
  </section>;
}

function RequestsWorkspace({ user }: { user: CurrentUser }) {
  const [filter, setFilter] = useState<RequestStatus | "">("");
  const [page, setPage] = useState<RequestsPage | null>(null);
  const [listLoading, setListLoading] = useState(true);
  const [listError, setListError] = useState("");
  const [moreLoading, setMoreLoading] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const [detail, setDetail] = useState<RequestDetail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState("");
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [modal, setModal] = useState<RequestFormMode | null>(null);
  const [action, setAction] = useState<"withdraw" | "cancel" | null>(null);
  const [notice, setNotice] = useState("");
  const detailSequence = useRef(0);
  const listSequence = useRef(0);
  const alive = useRef(true);
  const loadingMore = useRef(false);

  useEffect(() => { alive.current = true; return () => { alive.current = false; }; }, []);
  const readDetail = useCallback(async (id: number) => {
    const sequence = ++detailSequence.current;
    setSelectedId(id); setDetailLoading(true); setDetailError(""); setDetail(null);
    try {
      const result = await getRequest(id);
      if (alive.current && sequence === detailSequence.current) setDetail(result.request);
    } catch (failure) {
      if (alive.current && sequence === detailSequence.current && !redirectForAuthError(failure)) setDetailError(requestError(failure));
    } finally { if (alive.current && sequence === detailSequence.current) setDetailLoading(false); }
  }, []);

  useEffect(() => {
    const query = new URLSearchParams(window.location.search);
    const id = query.get("id");
    let active = true;
    if (query.get("new") === "1") {
      void Promise.resolve().then(() => {
        if (!active) return;
        setModal("create");
        query.delete("new");
        window.history.replaceState(null, "", `/requests${query.size ? `?${query}` : ""}`);
      });
    }
    if (id && /^[1-9]\d*$/.test(id) && Number.isSafeInteger(Number(id))) {
      void Promise.resolve().then(() => { if (active) void readDetail(Number(id)); });
    }
    return () => { active = false; };
  }, [readDetail]);

  useEffect(() => {
    let active = true;
    ++listSequence.current;
    void (async () => {
      setListLoading(true); setListError(""); setPage(null);
      try {
        const result = await getMyRequests(filter);
        if (active) setPage(result);
      } catch (failure) { if (active && !redirectForAuthError(failure)) setListError(requestError(failure)); }
      finally { if (active) setListLoading(false); }
    })();
    return () => { active = false; };
  }, [filter, attempt]);

  async function more() {
    if (loadingMore.current || !page?.nextBeforeId) return;
    loadingMore.current = true; setMoreLoading(true); setListError("");
    const sequence = listSequence.current;
    try {
      const result = await getMyRequests(filter, page.nextBeforeId);
      if (alive.current && sequence === listSequence.current) setPage(previous => previous ? { requests: [...previous.requests, ...result.requests.filter(row => !previous.requests.some(old => old.id === row.id))], nextBeforeId: result.nextBeforeId } : result);
    } catch (failure) { if (alive.current && sequence === listSequence.current && !redirectForAuthError(failure)) setListError(requestError(failure)); }
    finally { loadingMore.current = false; if (alive.current) setMoreLoading(false); }
  }

  function openDetail(id: number) {
    window.history.replaceState(null, "", `/requests?id=${id}`);
    void readDetail(id);
  }
  function closeDetail() {
    ++detailSequence.current; setSelectedId(null); setDetail(null); setDetailError(""); setDetailLoading(false);
    window.history.replaceState(null, "", "/requests");
  }
  function saved(message: string) {
    setModal(null); setAction(null); setNotice(message); setAttempt(value => value + 1);
    if (modal === "create") closeDetail();
    else if (selectedId !== null) void readDetail(selectedId);
  }
  function refreshAction() { setAction(null); if (selectedId !== null) void readDetail(selectedId); setAttempt(value => value + 1); }
  function create() { setModal("create"); setNotice(""); }

  return <AppShell user={user} active="requests" onNewRequest={create}>
    <div className="requests-page-heading"><div><span className="requests-eyebrow">Twoje nieobecności</span><h1>Moje wnioski</h1><p>Terminy, decyzje i historia Twoich urlopów.</p></div></div>
    {notice && <div className="message message-success" role="status">{notice}</div>}
    {detailLoading && <p className="requests-loading" role="status">Wczytywanie szczegółów wniosku…</p>}
    {detailError && <div className="requests-detail"><div className="message message-error" role="alert">{detailError}</div><div className="requests-inline-actions"><button className="button button-secondary" onClick={() => { if (selectedId !== null) void readDetail(selectedId); }}>Wczytaj aktualne dane</button><button className="button button-secondary" onClick={closeDetail}>Zamknij szczegóły</button></div></div>}
    {detail && <RequestDetails request={detail} actorId={user.id} onClose={closeDetail} onEdit={setModal} onAction={setAction} />}
    <section className="requests-list-panel" aria-label="Lista moich wniosków">
      <div className="requests-list-toolbar"><h2>Wszystkie wnioski</h2><div className="field"><label htmlFor="request-status-filter">Status</label><select id="request-status-filter" value={filter} disabled={moreLoading} onChange={event => setFilter(event.target.value as RequestStatus | "")}><option value="">Wszystkie statusy</option>{(["pending", "approved", "change_pending", "cancellation_pending", "rejected", "withdrawn", "cancelled"] as RequestStatus[]).map(status => <option key={status} value={status}>{requestStatusLabel(status)}</option>)}</select></div></div>
      {listLoading ? <p className="requests-loading" role="status">Wczytywanie wniosków…</p> : page && page.requests.length ? <ul className="requests-list">{page.requests.map(row => <li key={row.id} className={selectedId === row.id ? "is-selected" : ""}><div className="requests-row-date"><strong>{requestRange(row.latestVisibleRevision)}</strong><span>{row.latestVisibleRevision.absenceType.name}</span>{row.status === "change_pending" && <small>Termin proponowany · dotychczasowy urlop w szczegółach</small>}</div><span className="requests-row-hours">{row.latestVisibleRevision.totalHours} h</span><RequestStatusBadge status={row.status} /><button className="button button-secondary button-small" aria-label={`Szczegóły wniosku ${row.id}`} onClick={() => openDetail(row.id)}>Szczegóły <span aria-hidden="true">↗</span></button></li>)}</ul>
        : !listError && <div className="requests-empty"><span className="requests-empty-icon" aria-hidden="true">☷</span><h3>{filter ? "Brak wniosków o tym statusie" : "Tu pojawią się Twoje wnioski"}</h3><p>{filter ? "Wybierz inny status, aby zobaczyć pozostałe wnioski." : "Wybierz termin odpoczynku i wyślij pierwszy wniosek."}</p>{!filter && <button className="button button-primary" onClick={create}>Złóż pierwszy wniosek</button>}</div>}
      {listError && <div className="requests-list-error"><p className="message message-error" role="alert">{listError}</p><button className="button button-secondary" onClick={() => setAttempt(value => value + 1)}>Odśwież listę</button></div>}
      {!!page?.nextBeforeId && <div className="requests-load-more"><button className="button button-secondary" disabled={moreLoading} onClick={() => void more()}>{moreLoading ? "Wczytywanie…" : "Pokaż kolejne wnioski"}</button></div>}
    </section>
    {modal && <RequestDialog mode={modal} request={modal === "create" ? undefined : detail ?? undefined} onClose={() => setModal(null)} onSaved={() => saved(modal === "change" ? "Propozycja zmiany została wysłana. Dotychczasowy urlop obowiązuje do decyzji." : modal === "edit" ? "Zmiana została zapisana. Wniosek nadal oczekuje na decyzję." : "Wniosek został wysłany i oczekuje na decyzję.")} />}
    {action && detail && <RequestActionDialog request={detail} action={action} onClose={() => setAction(null)} onSaved={() => saved(action === "cancel" ? "Prośba o anulowanie została wysłana. Urlop i saldo pozostają bez zmian do decyzji." : "Wycofanie zostało zapisane, a odpowiednia rezerwacja zwolniona.")} onRefresh={refreshAction} />}
  </AppShell>;
}

export function MyRequestsScreen() {
  const { user, loading, error, reload } = useWorkspaceUser();
  if (loading) return <main className="requests-gate" role="status">Wczytywanie konta…</main>;
  if (error) return <main className="requests-gate"><div className="message message-error" role="alert">{error}</div><button className="button button-secondary" onClick={reload}>Spróbuj ponownie</button></main>;
  return user ? <RequestsWorkspace key={`${user.id}:${user.team?.id}:${user.role}:${user.isAdministrator}`} user={user} /> : null;
}
