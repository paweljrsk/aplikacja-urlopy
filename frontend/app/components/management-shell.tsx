"use client";

import Link from "next/link";
import type { ReactNode } from "react";
import type { CurrentUser } from "@/lib/auth-api";
import { canManageRequests } from "@/lib/managed-requests-api";
import { AppHeader } from "./app-shell";

export type ManagementSection = "overview" | "requests" | "balances" | "admin" | "calendar";

export function ManagementShell({ user, active, children, onLogout, logoutBusy = false }: {
  user: CurrentUser | null; active: ManagementSection; children: ReactNode;
  onLogout?: () => void; logoutBusy?: boolean;
}) {
  const permitted = user !== null && canManageRequests(user);
  const link = (section: ManagementSection, href: string, label: string, number: string) => <Link
    href={href} prefetch={false} aria-current={active === section ? "page" : undefined}
  ><span className="management-nav-icon" aria-hidden="true">{number}</span>{label}<span className="management-nav-arrow" aria-hidden="true">›</span></Link>;

  return <div className="workspace notus-workspace management-workspace">
    <a className="skip-link" href="#main">Przejdź do treści</a>
    <AppHeader user={user} active="management" mode={permitted ? "management" : "employee"} onLogout={onLogout} logoutBusy={logoutBusy} />
    <div className={`management-layout${permitted ? "" : " management-layout-denied"}`}>
      {permitted && <aside className="management-sidebar">
        <div className="management-sidebar-heading"><span className="eyebrow">Przestrzeń zespołu</span><strong>Zarządzanie</strong></div>
        <nav aria-label="Zarządzanie" data-tour="management-navigation">
          {link("overview", "/management", "Przegląd", "⌂")}
          {link("requests", "/management/requests", "Obsługa wniosków", "✓")}
          {link("balances", "/management/balances", "Salda i limity", "◷")}
          {user.isAdministrator && <>
            <span className="management-nav-group">Administracja</span>
            {link("admin", "/management/admin", "Użytkownicy i ustawienia", "⚙")}
            {link("calendar", "/management/calendar", "Firmowe dni wolne", "▦")}
          </>}
        </nav>
        <p className="management-scope">{user.isAdministrator ? "Dostęp do wszystkich zespołów" : user.role === "deputy" ? "Obsługa zespołu · zastępca" : "Obsługa własnego zespołu"}</p>
      </aside>}
      <main id="main" className="management-main">{children}</main>
    </div>
    <footer className="workspace-footer"><span>NOTUS · Urlopy zespołu</span><span>Panel zarządzania</span></footer>
  </div>;
}
