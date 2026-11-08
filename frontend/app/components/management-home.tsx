"use client";

import Link from "next/link";
import { canManageRequests } from "@/lib/managed-requests-api";
import { useWorkspaceUser } from "./app-shell";
import { ManagementShell } from "./management-shell";

export function ManagementHome() {
  const { user, loading, error, reload } = useWorkspaceUser();
  return <ManagementShell user={user} active="overview">
    {loading ? <p role="status">Wczytywanie panelu…</p> : error ? <><p className="message message-error" role="alert">{error}</p><button className="button button-secondary" onClick={reload}>Spróbuj ponownie</button></> : user && canManageRequests(user) ? <>
      <section className="management-welcome" data-tour="management-overview">
        <div><span className="eyebrow">Wszystko w jednym miejscu</span><h1>Zarządzanie zespołem</h1><p>Decyzje, limity i ustawienia. Wybierz sprawę, którą chcesz się zająć.</p></div>
        <span className="management-welcome-mark" aria-hidden="true">✳</span>
      </section>
      <div className="management-cards">
        <Link href="/management/requests" prefetch={false} className="management-card">
          <span className="management-card-icon" aria-hidden="true">✓</span><div className="management-card-text"><h2>Obsługa wniosków</h2><p>Akceptuj, odrzucaj i sprawdzaj zmiany. Wszystkie decyzje poprzedza podgląd.</p><span>Przejdź do wniosków <span aria-hidden="true">→</span></span></div>
        </Link>
        <Link href="/management/balances" prefetch={false} className="management-card">
          <span className="management-card-icon is-teal" aria-hidden="true">◷</span><div className="management-card-text"><h2>Salda i limity</h2><p>{user.role === "deputy" && !user.isAdministrator ? "Sprawdź dostępne godziny zespołu i historię rozliczeń." : "Sprawdź salda, ustaw limity i uzupełnij dane początkowe pracowników."}</p><span>Otwórz zestawienie <span aria-hidden="true">→</span></span></div>
        </Link>
        {user.isAdministrator && <>
          <Link href="/management/admin" prefetch={false} className="management-card" data-tour="administration-entry">
            <span className="management-card-icon is-violet" aria-hidden="true">⚙</span><div className="management-card-text"><h2>Użytkownicy i ustawienia</h2><p>Konta, zespoły, role, formy zatrudnienia, rodzaje nieobecności oraz historia zmian.</p><span>Otwórz administrację <span aria-hidden="true">→</span></span></div>
          </Link>
          <Link href="/management/calendar" prefetch={false} className="management-card">
            <span className="management-card-icon is-amber" aria-hidden="true">▦</span><div className="management-card-text"><h2>Firmowe dni wolne</h2><p>Zaplanuj dodatkowe dni wolne i sprawdź ich wpływ na urlopy przed zapisem.</p><span>Otwórz kalendarz firmowy <span aria-hidden="true">→</span></span></div>
          </Link>
        </>}
      </div>
      <section className="management-help"><span aria-hidden="true">✦</span><div><h2>Pierwszy raz w panelu?</h2><p>Wybierz „Przewodnik” u góry. Pokażemy Ci najważniejsze miejsca krok po kroku.</p></div></section>
    </> : <section className="admin-empty"><h1>Brak dostępu</h1><p>Panel jest dostępny dla kierownika, zastępcy i administratora.</p><Link href="/" className="button button-secondary">Wróć do kalendarza</Link></section>}
  </ManagementShell>;
}
