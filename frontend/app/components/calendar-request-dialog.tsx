"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { ApiError, redirectForAuthError } from "@/lib/auth-api";
import { getRequest, requestError, type RequestDetail } from "@/lib/request-api";
import { RequestHistory, RequestRevisionDetails } from "./request-details";
import { RequestModal } from "./request-dialog";

type Props = { requestId: number; actorId: number; onClose: () => void };
type DetailState = { request: RequestDetail | null; loading: boolean; error: string };

export function CalendarRequestDialog(props: Props) {
  // A different selection or actor must never briefly render the previous DTO.
  return <CalendarRequestDialogContent key={`${props.requestId}:${props.actorId}`} {...props} />;
}

function CalendarRequestDialogContent({ requestId, actorId, onClose }: Props) {
  const [state, setState] = useState<DetailState>({ request: null, loading: true, error: "" });
  const alive = useRef(false);
  const sequence = useRef(0);
  const reload = useCallback(async () => {
    const current = ++sequence.current;
    setState({ request: null, loading: true, error: "" });
    try {
      const { request } = await getRequest(requestId);
      if (!alive.current || current !== sequence.current) return;
      if (request.id !== requestId) throw new ApiError(0, "INVALID_RESPONSE");
      setState({ request, loading: false, error: "" });
    } catch (failure) {
      if (!alive.current || current !== sequence.current) return;
      const redirected = redirectForAuthError(failure);
      const error = failure instanceof ApiError && failure.status === 403
        ? "Nie masz dostępu do szczegółów tej nieobecności."
        : failure instanceof ApiError && failure.status === 404
          ? "Ta nieobecność nie jest już dostępna. Zamknij szczegóły i odśwież kalendarz."
          : requestError(failure);
      setState({ request: null, loading: false, error: redirected ? "Sesja wymaga ponownego potwierdzenia." : error });
    }
  }, [requestId]);

  useEffect(() => {
    alive.current = true;
    let active = true;
    void Promise.resolve().then(() => { if (active) void reload(); });
    const refresh = () => { void reload(); };
    const hide = () => {
      ++sequence.current;
      setState({ request: null, loading: true, error: "" });
    };
    const onVisibility = () => { if (document.visibilityState === "visible") refresh(); else hide(); };
    const onPageShow = (event: PageTransitionEvent) => { if (event.persisted) refresh(); };
    window.addEventListener("focus", refresh);
    window.addEventListener("pagehide", hide);
    window.addEventListener("pageshow", onPageShow);
    document.addEventListener("visibilitychange", onVisibility);
    return () => {
      active = false; alive.current = false;
      window.removeEventListener("focus", refresh);
      window.removeEventListener("pagehide", hide);
      window.removeEventListener("pageshow", onPageShow);
      document.removeEventListener("visibilitychange", onVisibility);
    };
  }, [reload]);

  const { request, loading, error } = state;
  return <RequestModal title="Szczegóły nieobecności" eyebrow="Kalendarz zespołu" closeLabel="Zamknij szczegóły" onClose={onClose}>
    <div className="requests-dialog-body calendar-request-dialog-content" aria-busy={loading}>
      {loading ? <p role="status">Wczytywanie szczegółów nieobecności…</p> : error ? <>
        <div className="message message-error" role="alert">{error}</div>
        <button type="button" className="button button-secondary calendar-request-retry" onClick={() => void reload()}>Spróbuj ponownie</button>
      </> : request && <>
        <div className="calendar-request-person"><strong>{request.user.firstName} {request.user.lastName}</strong><span>Wniosek #{request.id}</span></div>
        <RequestRevisionDetails request={request} />
        <RequestHistory request={request} />
      </>}
    </div>
    <div className="requests-dialog-footer">
      <button type="button" className="button button-secondary" onClick={onClose}>Wróć do kalendarza</button>
      {request?.user.id === actorId && !(request.actions.approve || request.actions.reject || request.actions.correct) && <Link href={`/requests?id=${request.id}`} prefetch={false} className="button button-primary">Przejdź do mojego wniosku</Link>}
      {request && (request.actions.approve || request.actions.reject || request.actions.correct) && <Link href={`/management/requests?id=${request.id}`} prefetch={false} className="button button-primary">Obsłuż wniosek</Link>}
    </div>
  </RequestModal>;
}
