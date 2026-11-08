"use client";

import Image from "next/image";
import Link from "next/link";
import { useCallback, useEffect, useRef, useState, type ReactNode } from "react";
import { errorMessage, getCurrentUser, postApi, redirectForAuthError, roleLabel, type CurrentUser } from "@/lib/auth-api";
import { GuideLauncher } from "./guided-tour";

export function NotusBrand({ showLabel = true }: { showLabel?: boolean }) {
  return <Link href="/" prefetch={false} className="notus-brand" aria-label="Notus Urlopy — kalendarz zespołu">
    <Image src="/brand/notus.svg" width={148} height={31} alt="Notus" priority />
    {showLabel && <>
      <span className="notus-brand-divider" aria-hidden="true" />
      <span>Urlopy</span>
    </>}
  </Link>;
}

export function useWorkspaceUser() {
  const [user, setUser] = useState<CurrentUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const request = useRef(0);
  const alive = useRef(false);
  const reload = useCallback(async () => {
    const sequence = ++request.current;
    try {
      const result = await getCurrentUser();
      if (!alive.current || sequence !== request.current) return;
      if (result.user.mustChangePassword) { setUser(null); window.location.replace("/change-password"); return; }
      setUser(previous => JSON.stringify(previous) === JSON.stringify(result.user) ? previous : result.user);
      setError("");
    } catch (failure) {
      if (!alive.current || sequence !== request.current) return;
      setUser(null);
      if (!redirectForAuthError(failure)) setError(errorMessage(failure));
    } finally { if (alive.current && sequence === request.current) setLoading(false); }
  }, []);
  useEffect(() => {
    alive.current = true;
    let active = true;
    void Promise.resolve().then(() => { if (active) void reload(); });
    const onFocus = () => { void reload(); };
    const onPageShow = (event: PageTransitionEvent) => { if (event.persisted) { setUser(null); setLoading(true); void reload(); } };
    window.addEventListener("focus", onFocus);
    window.addEventListener("pageshow", onPageShow);
    return () => { active = false; alive.current = false; window.removeEventListener("focus", onFocus); window.removeEventListener("pageshow", onPageShow); };
  }, [reload]);
  return { user, loading, error, reload };
}

export function AppHeader({ user, active, onNewRequest, onLogout, logoutBusy = false, mode = "employee" }: { user: CurrentUser | null; active: string; onNewRequest?: () => void; onLogout?: () => void; logoutBusy?: boolean; mode?: "employee" | "management" }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const pending = useRef(false);
  async function logout() {
    if (pending.current) return;
    pending.current = true; setBusy(true); setError("");
    try { await postApi("/api/auth/logout"); window.location.replace("/login"); }
    catch (failure) { if (!redirectForAuthError(failure)) setError(errorMessage(failure)); }
    finally { pending.current = false; setBusy(false); }
  }
  return <>
    <header className={`app-header${mode === "management" ? " management-header" : ""}`}>
      <div className="app-header-inner">
        <NotusBrand showLabel={false} />
        {user && <>
          {mode === "employee" ? <nav className="app-navigation" aria-label="Nawigacja główna" data-tour="employee-navigation">
            <Link href="/" prefetch={false} aria-current={active === "calendar" ? "page" : undefined}>Kalendarz</Link>
            <Link href="/requests" prefetch={false} aria-current={active === "requests" ? "page" : undefined}>Moje wnioski</Link>
            <Link href="/balances" prefetch={false} aria-current={active === "balances" ? "page" : undefined}>Moje saldo</Link>
          </nav> : <div className="management-header-label"><span className="management-mode-dot" aria-hidden="true" />Panel zarządzania</div>}
          <div className="app-header-actions">
            <GuideLauncher user={user} />
            {mode === "management" ? <Link href="/" prefetch={false} className="button button-secondary back-to-workspace">Wróć do kalendarza</Link> : <>
              {(user.isAdministrator || user.role === "manager" || user.role === "deputy") && <Link href="/management" prefetch={false} className="management-entry" data-tour="management-entry">Zarządzanie<span aria-hidden="true">↗</span></Link>}
              {onNewRequest ? <button type="button" className="button button-primary new-request-button" data-tour="new-request" onClick={onNewRequest}><span aria-hidden="true">+</span>Złóż wniosek</button> : <Link href="/requests?new=1" prefetch={false} className="button button-primary new-request-button" data-tour="new-request"><span aria-hidden="true">+</span>Złóż wniosek</Link>}
            </>}
            <details className="account-menu">
              <summary aria-label="Menu konta"><span className="account-initials" aria-hidden="true">{user.firstName.slice(0, 1)}{user.lastName.slice(0, 1)}</span><span className="account-menu-name">{user.firstName}<span aria-hidden="true">⌄</span></span></summary>
              <div className="account-menu-panel">
                <strong>{user.firstName} {user.lastName}</strong><small>{roleLabel(user)}</small>
                <Link href="/account" prefetch={false}>Moje konto</Link>
                <button type="button" onClick={onLogout ?? (() => void logout())} disabled={busy || logoutBusy}>{busy || logoutBusy ? "Wylogowywanie…" : "Wyloguj się"}</button>
              </div>
            </details>
          </div>
        </>}
      </div>
    </header>
    {error && <div className="app-header-error message message-error" role="alert">{error}</div>}
  </>;
}

export function AppShell({ user, active, children, onNewRequest }: { user: CurrentUser | null; active: string; children: ReactNode; onNewRequest?: () => void }) {
  return <div className="workspace notus-workspace">
    <a className="skip-link" href="#main">Przejdź do treści</a>
    <AppHeader user={user} active={active} onNewRequest={onNewRequest} />
    <main id="main" className="workspace-main">{children}</main>
    <footer className="workspace-footer"><span>NOTUS · Urlopy zespołu</span><span>Czas dla Ciebie. Dobry plan dla zespołu.</span></footer>
  </div>;
}
