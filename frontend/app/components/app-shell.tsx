"use client";

import Image from "next/image";
import Link from "next/link";
import { useCallback, useEffect, useRef, useState, type ReactNode } from "react";
import { errorMessage, getCurrentUser, postApi, redirectForAuthError, roleLabel, type CurrentUser } from "@/lib/auth-api";

export function NotusBrand() {
  return <Link href="/" prefetch={false} className="notus-brand" aria-label="Notus Urlopy — kalendarz zespołu">
    <Image src="/brand/notus.svg" width={148} height={31} alt="Notus" priority />
    <span className="notus-brand-divider" aria-hidden="true" /><span>Urlopy</span>
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

export function AppHeader({ user, active, onNewRequest, onLogout, logoutBusy = false }: { user: CurrentUser | null; active: string; onNewRequest?: () => void; onLogout?: () => void; logoutBusy?: boolean }) {
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
    <header className="app-header">
      <div className="app-header-inner">
        <NotusBrand />
        {user && <>
          <nav className={`app-navigation${user.isAdministrator || user.role === "manager" || user.role === "deputy" ? " has-management" : ""}`} aria-label="Nawigacja główna">
            <Link href="/" prefetch={false} aria-current={active === "calendar" ? "page" : undefined}>Kalendarz</Link>
            <Link href="/requests" prefetch={false} aria-current={active === "requests" ? "page" : undefined}>Moje wnioski</Link>
            <Link href="/balances" prefetch={false} aria-current={active === "balances" ? "page" : undefined}>Moje saldo</Link>
            {(user.isAdministrator || user.role === "manager" || user.role === "deputy") && <Link href="/management" prefetch={false} aria-current={active === "management" ? "page" : undefined}>Obsługa wniosków</Link>}
          </nav>
          <div className="app-header-actions">
            {onNewRequest ? <button type="button" className="button button-primary new-request-button" onClick={onNewRequest}><span aria-hidden="true">+</span>Złóż wniosek</button> : <Link href="/requests?new=1" prefetch={false} className="button button-primary new-request-button"><span aria-hidden="true">+</span>Złóż wniosek</Link>}
            <details className="account-menu">
              <summary aria-label="Menu konta"><span className="account-initials" aria-hidden="true">{user.firstName.slice(0, 1)}{user.lastName.slice(0, 1)}</span><span className="account-menu-name">{user.firstName}<span aria-hidden="true">⌄</span></span></summary>
              <div className="account-menu-panel">
                <strong>{user.firstName} {user.lastName}</strong><small>{roleLabel(user)}</small>
                <Link href="/account" prefetch={false}>Moje konto</Link>
                {(user.isAdministrator || user.role === "manager" || user.role === "deputy") && <Link href="/balances?view=team" prefetch={false}>Salda zespołu</Link>}
                {user.isAdministrator && <><Link href="/admin" prefetch={false}>Administracja</Link><Link href="/calendar-settings" prefetch={false}>Firmowe dni wolne</Link></>}
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
