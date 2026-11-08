"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { ApiError, redirectForAuthError, type CurrentUser } from "@/lib/auth-api";
import { canManageRequests, getManagedRequests, type DecisionAction, type ManagedStatus } from "@/lib/managed-requests-api";
import { getRequest, requestError, requestRange, requestStatusLabel, type RequestDetail, type RequestStatus, type RequestsPage } from "@/lib/request-api";
import { useWorkspaceUser } from "./app-shell";
import { ManagementShell } from "./management-shell";
import { RequestHistory, RequestRevisionDetails, RequestStatusBadge } from "./request-details";
import { RequestDecisionDialog } from "./request-decision-dialog";
import { RequestCorrectionDialog } from "./request-correction-dialog";
import "./requests.css";
import "./managed-requests.css";

function ManagedWorkspace({ user }: { user: CurrentUser }) {
  const [status, setStatus] = useState<ManagedStatus>("awaiting");
  const [page, setPage] = useState<RequestsPage | null>(null);
  const [loading, setLoading] = useState(true);
  const [listError, setListError] = useState("");
  const [moreBusy, setMoreBusy] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const [selected, setSelected] = useState<number | null>(null);
  const [detail, setDetail] = useState<RequestDetail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState("");
  const [action, setAction] = useState<DecisionAction | null>(null);
  const [modalRequest, setModalRequest] = useState<RequestDetail | null>(null);
  const [correction, setCorrection] = useState(false);
  const [notice, setNotice] = useState("");
  const alive = useRef(true);
  const listSequence = useRef(0);
  const detailSequence = useRef(0);
  const fetchingMore = useRef(false);
  const heading = useRef<HTMLHeadingElement>(null);
  const trigger = useRef<HTMLElement | null>(null);
  useEffect(() => { alive.current = true; return () => { alive.current = false; }; }, []);

  const readDetail = useCallback(async (id: number) => {
    const sequence = ++detailSequence.current;
    setSelected(id); setDetail(null); setDetailError(""); setDetailLoading(true);
    try {
      const result = await getRequest(id);
      if (!alive.current || sequence !== detailSequence.current) return;
      if (result.request.id !== id) throw new ApiError(0, "INVALID_RESPONSE");
      setDetail(result.request);
    } catch (failure) {
      if (alive.current && sequence === detailSequence.current && !redirectForAuthError(failure)) setDetailError(requestError(failure));
    } finally { if (alive.current && sequence === detailSequence.current) setDetailLoading(false); }
  }, []);
  useEffect(() => {
    let active = true;
    const id = new URLSearchParams(window.location.search).get("id");
    if (id && /^[1-9]\d*$/.test(id) && Number.isSafeInteger(Number(id))) {
      void Promise.resolve().then(() => { if (active) void readDetail(Number(id)); });
    }
    return () => { active = false; };
  }, [readDetail]);
  useEffect(() => { if (detail && !action && !correction) heading.current?.focus(); }, [detail, action, correction]);
  useEffect(() => {
    const sequence = ++listSequence.current;
    let active = true;
    void Promise.resolve().then(async () => {
      if (!active) return;
      setLoading(true); setPage(null); setListError("");
      try {
        const result = await getManagedRequests(status);
        if (active && sequence === listSequence.current) setPage(result);
      } catch (failure) { if (active && sequence === listSequence.current && !redirectForAuthError(failure)) setListError(requestError(failure)); }
      finally { if (active && sequence === listSequence.current) setLoading(false); }
    });
    return () => { active = false; listSequence.current = sequence + 1; };
  }, [status, attempt]);
  useEffect(() => {
    const refresh = () => {
      setAttempt(value => value + 1);
      if (selected !== null) void readDetail(selected);
    };
    const visibility = () => { if (document.visibilityState === "visible") refresh(); };
    window.addEventListener("focus", refresh);
    document.addEventListener("visibilitychange", visibility);
    return () => { window.removeEventListener("focus", refresh); document.removeEventListener("visibilitychange", visibility); };
  }, [action, correction, readDetail, selected]);

  async function more() {
    if (fetchingMore.current || !page?.nextBeforeId) return;
    fetchingMore.current = true; setMoreBusy(true); setListError("");
    const sequence = listSequence.current;
    try {
      const next = await getManagedRequests(status, page.nextBeforeId);
      if (alive.current && sequence === listSequence.current) setPage(current => current ? {
        requests: [...current.requests, ...next.requests.filter(item => !current.requests.some(old => old.id === item.id))], nextBeforeId: next.nextBeforeId,
      } : next);
    } catch (failure) {
      if (alive.current && sequence === listSequence.current && !redirectForAuthError(failure)) {
        if (failure instanceof ApiError && failure.status === 403) setPage(null);
        setListError(requestError(failure));
      }
    } finally { fetchingMore.current = false; if (alive.current) setMoreBusy(false); }
  }
  function open(id: number) {
    trigger.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    setNotice(""); window.history.replaceState(null, "", `/management/requests?id=${id}`); void readDetail(id);
  }
  function close() {
    ++detailSequence.current; setSelected(null); setDetail(null); setDetailLoading(false); setDetailError("");
    window.history.replaceState(null, "", "/management/requests");
    if (trigger.current?.isConnected) trigger.current.focus();
  }
  function refreshDetail() {
    setAction(null); setCorrection(false); setModalRequest(null); setAttempt(value => value + 1);
    if (selected !== null) void readDetail(selected);
  }
  function saved(message: string) { setNotice(message); refreshDetail(); }
  const visible = (page?.requests ?? []).filter(row => status !== "awaiting" || row.actions.approve || row.actions.reject);

  return <ManagementShell user={user} active="requests">
    <div className="requests-page-heading managed-page-heading"><div><span className="requests-eyebrow">Wspólne plany, jasne decyzje</span><h1>Obsługa wniosków</h1><p>{user.isAdministrator ? "Wnioski ze wszystkich dostępnych zespołów." : "Wnioski Twojego zespołu i dostępna historia."}</p></div><Link href="/management/balances" prefetch={false} className="button button-secondary">Salda zespołu <span aria-hidden="true">↗</span></Link></div>
    {notice && <div className="message message-success" role="status">{notice}</div>}
    {detailLoading && <p className="requests-loading" role="status">Wczytywanie szczegółów wniosku…</p>}
    {detailError && <section className="requests-detail"><p className="message message-error" role="alert">{detailError}</p><div className="requests-inline-actions"><button className="button button-secondary" onClick={() => { if (selected !== null) void readDetail(selected); }}>Wczytaj aktualne dane</button><button className="button button-secondary" onClick={close}>Zamknij szczegóły</button></div></section>}
    {detail && <section className="requests-detail managed-detail" aria-labelledby="managed-detail-title">
      <div className="requests-detail-header"><div><span className="requests-eyebrow">Wniosek #{detail.id}</span><h2 id="managed-detail-title" tabIndex={-1} ref={heading}>Szczegóły wniosku</h2><p>{detail.user.firstName} {detail.user.lastName}{detail.user.id === user.id && " · Twój wniosek"}</p></div><button className="button button-secondary button-small" onClick={close}>Zamknij szczegóły</button></div>
      <RequestRevisionDetails request={detail} />
      {(detail.actions.approve || detail.actions.reject || detail.actions.correct) ? <div className="managed-actions">
        <p>{detail.actions.approve || detail.actions.reject ? "Przed potwierdzeniem zobaczysz wpływ decyzji na saldo." : "Korekta przeszłości wymaga uzasadnienia i potwierdzenia podglądu."}</p>
        <div className="requests-inline-actions">
          {detail.actions.approve && <button className="button button-primary" onClick={() => { setModalRequest(detail); setAction("approve"); }}>Akceptuj</button>}
          {detail.actions.reject && <button className="button button-secondary" onClick={() => { setModalRequest(detail); setAction("reject"); }}>Odrzuć</button>}
          {detail.actions.correct && <button className="button button-secondary" onClick={() => { setModalRequest(detail); setCorrection(true); }}>Skoryguj przeszły wniosek</button>}
        </div>
      </div> : <p className="requests-scope-note">{detail.user.id === user.id && user.role === "deputy" && !user.isAdministrator ? "Decyzję o Twoim wniosku podejmuje kierownik lub administrator." : "Ten wniosek jest dostępny do podglądu. Nie ma obecnie operacji do wykonania w Twoim zakresie uprawnień."}</p>}
      <RequestHistory request={detail} />
    </section>}
    <section className="requests-list-panel managed-list-panel" aria-label="Lista obsługiwanych wniosków" data-tour="managed-requests">
      <div className="requests-list-toolbar"><h2>{status === "awaiting" ? "Do Twojej decyzji" : "Wnioski i historia"}</h2><div className="field"><label htmlFor="managed-status">Pokaż wnioski</label><select id="managed-status" value={status} disabled={moreBusy} onChange={event => { setStatus(event.target.value as ManagedStatus); setNotice(""); }}><option value="awaiting">Do decyzji</option><option value="">Wszystkie i historia</option>{(["pending", "change_pending", "cancellation_pending", "approved", "rejected", "withdrawn", "cancelled"] as RequestStatus[]).map(value => <option key={value} value={value}>{requestStatusLabel(value)}</option>)}</select></div></div>
      {loading ? <p className="requests-loading" role="status">Wczytywanie wniosków…</p> : visible.length ? <ul className="requests-list managed-list">{visible.map(row => <li key={row.id} data-request-id={row.id} className={selected === row.id ? "is-selected" : ""}>
        <div className="managed-request-person"><strong>{row.user.firstName} {row.user.lastName}</strong>{row.user.id === user.id && <small>Twój wniosek</small>}<span>Wniosek #{row.id}</span></div>
        <div className="requests-row-date"><strong>{requestRange(row.latestVisibleRevision)}</strong><span>{row.latestVisibleRevision.absenceType.name}</span>{row.status === "change_pending" && <small>Proponowana zmiana · oba terminy w szczegółach</small>}{row.status === "cancellation_pending" && <small>Urlop obowiązuje do akceptacji anulowania</small>}</div>
        <span className="requests-row-hours">{row.latestVisibleRevision.totalHours} h</span><RequestStatusBadge status={row.status} />
        <button className="button button-secondary button-small" aria-label={`Szczegóły wniosku ${row.id}`} onClick={() => open(row.id)}>Szczegóły <span aria-hidden="true">↗</span></button>
      </li>)}</ul> : !listError && <div className="requests-empty"><span className="requests-empty-icon" aria-hidden="true">✓</span><h3>{status === "awaiting" ? "Brak wniosków do Twojej decyzji" : "Brak wniosków w tym widoku"}</h3><p>{page?.nextBeforeId ? "Możesz sprawdzić kolejne pozycje dostępnej historii." : "Zmień filtr, aby przejrzeć dostępne wnioski i wcześniejsze decyzje."}</p></div>}
      {listError && <div className="requests-list-error"><div className="message message-error" role="alert">{listError}</div><button className="button button-secondary" onClick={() => setAttempt(value => value + 1)}>Odśwież listę</button></div>}
      {!!page?.nextBeforeId && <div className="requests-load-more"><button className="button button-secondary" disabled={moreBusy} onClick={() => void more()}>{moreBusy ? "Wczytywanie…" : "Pokaż kolejne wnioski"}</button></div>}
    </section>
    {action && modalRequest && <RequestDecisionDialog key={`${modalRequest.id}:${modalRequest.version}:${action}`} request={modalRequest} action={action} onClose={refreshDetail} onRefresh={refreshDetail} onSaved={() => saved(action === "approve" ? "Akceptacja została zapisana. Wniosek i saldo są aktualne." : "Odrzucenie i uzasadnienie zostały zapisane.")} />}
    {correction && modalRequest && <RequestCorrectionDialog key={`${modalRequest.id}:${modalRequest.version}`} request={modalRequest} user={user} onClose={refreshDetail} onSaved={() => saved("Korekta została zapisana wraz z uzasadnieniem i historią.")} />}
  </ManagementShell>;
}

export function ManagedRequestsScreen() {
  const { user, loading, error, reload } = useWorkspaceUser();
  if (!user) return <ManagementShell user={null} active="requests"><section className="dashboard-state"><h1>Obsługa wniosków</h1>{loading ? <p role="status">Wczytywanie konta…</p> : <><div className="message message-error" role="alert">{error}</div><button className="button button-secondary" onClick={() => void reload()}>Spróbuj ponownie</button></>}</section></ManagementShell>;
  if (!canManageRequests(user)) return <ManagementShell user={user} active="requests"><section className="dashboard-state"><h1>Brak dostępu do obsługi wniosków</h1><p>Ten widok jest dostępny dla kierownika, zastępcy i administratora.</p><Link href="/" className="button button-secondary">Wróć do kalendarza</Link></section></ManagementShell>;
  return <ManagedWorkspace key={`${user.id}:${user.team?.id}:${user.role}:${user.isAdministrator}`} user={user} />;
}
