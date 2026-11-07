"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { ApiError, errorMessage, getApi, redirectForAuthError } from "@/lib/auth-api";
import { requestTimestamp } from "@/lib/request-api";
import "./admin-history.css";

type HistoryEvent = {
  id: number; action: string; occurredAt: string; actorName: string | null;
  subject: { id: number; firstName: string; lastName: string } | null;
  targetType: string; targetReference: string;
  changes: { field: string; label: string; before: string | null; after: string | null }[];
};
type HistoryPage = { events: HistoryEvent[]; nextBeforeId: number | null };
const actions: Record<string, string> = {
  "admin.user_created": "Utworzono konto",
  "admin.user_updated": "Zmieniono konto lub dostęp",
  "admin.assignment_changed": "Zmieniono przypisanie pracownika",
  "admin.team_saved": "Zapisano zespół",
  "admin.employment_type_saved": "Zapisano formę zatrudnienia",
  "admin.absence_type_version_created": "Zapisano wersję rodzaju nieobecności",
  "admin.catalog_initialized": "Przygotowano słowniki startowe",
  "security.password_changed": "Zmieniono hasło",
  "security.password_reset": "Zresetowano hasło",
  "security.admin_bootstrapped": "Utworzono pierwszego administratora",
  "security.admin_recovered": "Przywrócono dostęp administratora",
};
const targets: Record<string, string> = { user: "Konto", team: "Zespół", employment_type: "Forma zatrudnienia", absence_type: "Rodzaj nieobecności", catalog: "Słowniki" };

export function AdminHistoryPanel({ onAccessError }: { onAccessError: (failure: unknown) => void }) {
  const [events, setEvents] = useState<HistoryEvent[]>([]);
  const [cursor, setCursor] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [moreLoading, setMoreLoading] = useState(false);
  const [error, setError] = useState("");
  const sequence = useRef(0);
  const pending = useRef(false);
  const accessError = useRef(onAccessError);
  useEffect(() => { accessError.current = onAccessError; }, [onAccessError]);

  const load = useCallback(async (beforeId?: number) => {
    if (beforeId && pending.current) return;
    const current = ++sequence.current;
    pending.current = true; setError("");
    if (beforeId) setMoreLoading(true); else { setEvents([]); setCursor(null); setLoading(true); }
    try {
      const query = new URLSearchParams({ limit: "20" });
      if (beforeId) query.set("beforeId", String(beforeId));
      const result = await getApi<HistoryPage>(`/api/admin/history?${query}`);
      if (sequence.current !== current) return;
      setEvents(previous => beforeId ? [...previous, ...result.events.filter(item => !previous.some(old => old.id === item.id))] : result.events);
      setCursor(result.nextBeforeId);
    } catch (failure) {
      if (sequence.current !== current) return;
      if (failure instanceof ApiError && (failure.status === 401 || failure.status === 403)) {
        setEvents([]); setCursor(null); accessError.current(failure);
      } else if (!redirectForAuthError(failure)) setError(errorMessage(failure));
    } finally {
      if (sequence.current === current) { pending.current = false; setLoading(false); setMoreLoading(false); }
    }
  }, []);

  useEffect(() => {
    let active = true;
    void Promise.resolve().then(() => { if (active) void load(); });
    const invalidate = () => { ++sequence.current; };
    const refresh = () => { void load(); };
    const hide = () => { ++sequence.current; pending.current = false; setEvents([]); setCursor(null); setLoading(true); };
    const visibility = () => { if (document.visibilityState === "visible") refresh(); else hide(); };
    window.addEventListener("focus", refresh);
    document.addEventListener("visibilitychange", visibility);
    return () => { active = false; invalidate(); window.removeEventListener("focus", refresh); document.removeEventListener("visibilitychange", visibility); };
  }, [load]);

  return <section className="admin-history-panel" aria-labelledby="admin-history-title">
    <div className="admin-section-heading"><div><h2 id="admin-history-title">Historia administracji</h2><p className="field-hint">Zmiany kont, przypisań i słowników wraz z autorem i czasem. Historia limitów jest dostępna przy saldzie osoby.</p></div><button type="button" className="button button-secondary" disabled={loading || moreLoading} onClick={() => void load()}>Odśwież historię</button></div>
    {error && <div className="message message-error" role="alert">{error}</div>}
    {loading ? <p role="status">Wczytywanie historii administracji…</p> : <>
      {!events.length && !error && <p className="field-hint" role="status">Brak zapisanych operacji administracyjnych.</p>}
      <ol className="admin-audit-list">{events.map(event => <li key={event.id}>
        <div className="admin-audit-heading"><strong>{actions[event.action] ?? "Operacja administracyjna"}</strong><time dateTime={event.occurredAt}>{requestTimestamp(event.occurredAt)}</time></div>
        <p className="admin-audit-subject">{event.subject ? `${event.subject.firstName} ${event.subject.lastName}` : `${targets[event.targetType] ?? "Pozycja"}: ${event.targetReference}`}</p>
        <p className="field-hint">Autor operacji: {event.actorName ?? "System"}</p>
        {event.changes.length > 0 && <details><summary>Pokaż zmienione wartości</summary><dl className="history-values">{event.changes.map((change, index) => <div key={`${change.field}-${index}`}><dt>{change.label}</dt><dd><span>{change.before ?? "—"}</span><span aria-hidden="true"> → </span><span>{change.after ?? "—"}</span></dd></div>)}</dl></details>}
      </li>)}</ol>
      {cursor !== null && <button type="button" className="button button-secondary" disabled={moreLoading} onClick={() => void load(cursor)}>{moreLoading ? "Wczytywanie…" : "Pokaż wcześniejsze operacje"}</button>}
    </>}
  </section>;
}
