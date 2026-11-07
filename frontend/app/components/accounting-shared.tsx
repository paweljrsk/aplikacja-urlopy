"use client";

import Link from "next/link";
import { AppHeader } from "./app-shell";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { getCurrentUser, redirectForAuthError, type CurrentUser } from "@/lib/auth-api";
import { balanceError } from "@/lib/balance-api";

export function AccountingNotice({ children, kind = "error" }: { children: ReactNode; kind?: "error" | "success" | "info" }) {
  return <div className={`message message-${kind}`} role={kind === "error" ? "alert" : "status"}>{children}</div>;
}

export function AccountingField({ id, label, value, onChange, type = "text", min, max, hint, required = true, maxLength = 255 }: {
  id: string; label: string; value: string; onChange: (value: string) => void; type?: "text" | "number" | "date"; min?: number | string; max?: number | string; hint?: string; required?: boolean; maxLength?: number;
}) {
  return <div className="field"><label htmlFor={id}>{label}</label><input id={id} name={id} value={value} onChange={event => onChange(event.target.value)} type={type} min={min} max={max} step={type === "number" ? 1 : undefined} required={required} maxLength={maxLength} autoComplete="off" aria-describedby={hint ? `${id}-hint` : undefined} />{hint && <p className="field-hint" id={`${id}-hint`}>{hint}</p>}</div>;
}

export function useOperationKey() {
  const last = useRef<{ body: string; key: string } | null>(null);
  return (body: Record<string, unknown>): string => {
    const serialized = JSON.stringify(body);
    if (last.current?.body !== serialized) last.current = { body: serialized, key: crypto.randomUUID() };
    return last.current.key;
  };
}

export function AccountingGate({ screen, children }: { screen: "balances" | "calendar"; children: (actor: CurrentUser) => ReactNode }) {
  const [actor, setActor] = useState<CurrentUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [forbidden, setForbidden] = useState(false);
  useEffect(() => {
    let alive = true;
    let sequence = 0;
    async function check() {
      const request = ++sequence;
      try {
        const result = await getCurrentUser();
        if (!alive || sequence !== request) return;
        if (result.user.mustChangePassword) { setActor(null); window.location.replace("/change-password"); return; }
        if (screen === "calendar" && !result.user.isAdministrator) { setActor(null); setForbidden(true); return; }
        setActor(result.user); setForbidden(false); setError("");
      } catch (failure) {
        if (!alive || sequence !== request) return;
        setActor(null);
        if (!redirectForAuthError(failure)) setError(balanceError(failure));
      } finally { if (alive && sequence === request) setLoading(false); }
    }
    void check();
    const onFocus = () => { void check(); };
    const onPageShow = (event: PageTransitionEvent) => { if (event.persisted) { setActor(null); setLoading(true); void check(); } };
    window.addEventListener("focus", onFocus); window.addEventListener("pageshow", onPageShow);
    return () => { alive = false; ++sequence; window.removeEventListener("focus", onFocus); window.removeEventListener("pageshow", onPageShow); };
  }, [screen]);
  return <div className="workspace admin-workspace accounting-workspace">
    <a className="skip-link" href="#main">Przejdź do treści</a>
    <AppHeader user={actor} active={screen === "balances" ? "balances" : "calendar-settings"} />
    <main id="main" className="workspace-main accounting-main">
      <div className="workspace-heading"><div><span className="eyebrow">Rozliczenia nieobecności</span><h1>{screen === "balances" ? "Limity i saldo" : "Firmowe dni wolne"}</h1><p>{screen === "balances" ? "Godziny dostępne, zaplanowane i wykorzystane — z historią zmian." : "Zaplanuj dni wolne i sprawdź ich wpływ na złożone wnioski."}</p></div></div>
      {actor && <nav className="accounting-nav" aria-label="Rozliczenia"><Link href="/balances" prefetch={false} aria-current={screen === "balances" ? "page" : undefined}>Limity i saldo</Link>{actor.isAdministrator && <><Link href="/calendar-settings" prefetch={false} aria-current={screen === "calendar" ? "page" : undefined}>Firmowe dni wolne</Link><Link href="/admin" prefetch={false}>Administracja</Link></>}</nav>}
      {loading ? <p role="status">Wczytywanie konta…</p> : forbidden ? <section className="admin-empty"><h2>Brak dostępu</h2><p>Firmowymi dniami wolnymi zarządza administrator.</p></section> : error ? <><AccountingNotice>{error}</AccountingNotice><button className="button button-secondary" onClick={() => window.location.reload()}>Spróbuj ponownie</button></> : actor ? <div key={`${actor.id}:${actor.isAdministrator}:${actor.role}:${actor.team?.id}`}>{children(actor)}</div> : null}
    </main>
    <footer className="workspace-footer">Urlopy zespołu<span>Rozliczenia w pełnych godzinach</span></footer>
  </div>;
}
