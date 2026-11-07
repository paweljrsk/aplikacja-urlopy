"use client";

import Link from "next/link";
import { useEffect, useRef, useState, useSyncExternalStore, type CSSProperties } from "react";
import { getApi, redirectForAuthError, type CurrentUser } from "@/lib/auth-api";
import { balanceError, type Balance } from "@/lib/balance-api";
import { entryLabel, shiftDate, shortDate, twoMonthsAfter, weekStart, type CalendarEntry, type TeamCalendar } from "@/lib/team-calendar-api";
import { AppShell, useWorkspaceUser } from "./app-shell";
import { RequestDialog } from "./request-dialog";

function subscribeMobile(callback: () => void) {
  const query = window.matchMedia("(max-width: 760px)");
  query.addEventListener("change", callback);
  return () => query.removeEventListener("change", callback);
}
function mobileSnapshot() { return window.matchMedia("(max-width: 760px)").matches; }
function serverMobileSnapshot() { return false; }
function dateLabel(date: string, options: Intl.DateTimeFormatOptions) { return new Intl.DateTimeFormat("pl-PL", { ...options, timeZone: "UTC" }).format(new Date(`${date}T12:00:00Z`)); }
function availableLabel(hours: number) {
  if (hours < 0) return `${Math.abs(hours)} h niedoboru`;
  const days = Math.floor(hours / 8), rest = hours % 8;
  return days ? `${days} ${days === 1 ? "dzień" : "dni"}${rest ? ` i ${rest} h` : ""}` : `${hours} h`;
}

export function TeamCalendarScreen() {
  const { user, loading, error, reload } = useWorkspaceUser();
  if (!user) return <AppShell user={null} active="calendar"><section className="dashboard-state">{loading ? <><span className="spinner" aria-hidden="true" /><h1>Wczytywanie kalendarza</h1><p role="status">Sprawdzamy Twoją sesję…</p></> : <><h1>Nie udało się wczytać konta</h1><div className="message message-error" role="alert">{error}</div><button className="button button-secondary" onClick={() => void reload()}>Spróbuj ponownie</button></>}</section></AppShell>;
  return <CalendarWorkspace key={`${user.id}:${user.team?.id}:${user.role}:${user.isAdministrator}`} user={user} />;
}

function CalendarWorkspace({ user }: { user: CurrentUser }) {
  const mobile = useSyncExternalStore(subscribeMobile, mobileSnapshot, serverMobileSnapshot);
  const [chosenPeriod, setChosenPeriod] = useState<7 | 14 | null>(null);
  const period = chosenPeriod ?? (mobile ? 7 : 14);
  const [today, setToday] = useState("");
  const [from, setFrom] = useState("");
  const [team, setTeam] = useState("");
  const [calendar, setCalendar] = useState<TeamCalendar | null>(null);
  const [upcoming, setUpcoming] = useState<TeamCalendar | null>(null);
  const [balance, setBalance] = useState<Balance | null>(null);
  const [error, setError] = useState("");
  const [upcomingError, setUpcomingError] = useState("");
  const [balanceFailure, setBalanceFailure] = useState("");
  const [refresh, setRefresh] = useState(0);
  const [dialog, setDialog] = useState(false);
  const [notice, setNotice] = useState("");
  const [allUpcoming, setAllUpcoming] = useState(false);
  const periodRequest = useRef(0);
  const to = from ? shiftDate(from, period - 1) : "";

  useEffect(() => {
    let alive = true;
    getApi<{ today: string }>("/api/requests/options").then(options => {
      if (alive) { setToday(options.today); setFrom(previous => previous || weekStart(options.today)); setError(""); }
    }).catch(failure => { if (alive && !redirectForAuthError(failure)) setError(balanceError(failure)); });
    return () => { alive = false; };
  }, [refresh]);

  useEffect(() => {
    if (!from) return;
    const sequence = ++periodRequest.current;
    const query = new URLSearchParams({ from, to });
    if (user.isAdministrator && team) query.set("teamId", team);
    getApi<TeamCalendar>(`/api/team-calendar?${query}`).then(result => {
      if (sequence === periodRequest.current) { setCalendar(result); setError(""); }
    }).catch(failure => {
      if (sequence !== periodRequest.current) return;
      setCalendar(null);
      if (!redirectForAuthError(failure)) setError(balanceError(failure));
    });
    return () => { periodRequest.current = sequence + 1; };
  }, [from, to, team, user.isAdministrator, refresh]);

  useEffect(() => {
    if (!today) return;
    let alive = true;
    const query = new URLSearchParams({ from: today, to: shiftDate(twoMonthsAfter(today), -1) });
    if (user.isAdministrator && team) query.set("teamId", team);
    getApi<TeamCalendar>(`/api/team-calendar?${query}`).then(result => {
      if (alive) { setUpcoming(result); setUpcomingError(""); }
    }).catch(failure => { if (alive) { setUpcoming(null); if (!redirectForAuthError(failure)) setUpcomingError(balanceError(failure)); } });
    getApi<{ balance: Balance }>(`/api/balances/users/${user.id}/${today.slice(0, 4)}`).then(result => {
      if (alive) { setBalance(result.balance); setBalanceFailure(""); }
    }).catch(failure => { if (alive) { setBalance(null); if (!redirectForAuthError(failure)) setBalanceFailure(balanceError(failure)); } });
    return () => { alive = false; };
  }, [today, team, user.id, user.isAdministrator, refresh]);

  const days = from ? Array.from({ length: period }, (_, i) => shiftDate(from, i)) : [];
  const upcomingEntries = [...(upcoming?.entries ?? [])].sort((a, b) => a.startDate.localeCompare(b.startDate) || a.userId - b.userId || a.key.localeCompare(b.key));
  const visibleUpcoming = allUpcoming ? upcomingEntries : upcomingEntries.slice(0, 8);
  const loadedPeriod = calendar?.from === from && calendar?.to === to;
  const holidays = new Map(calendar?.holidays.map(day => [day.date, day.name]));
  function changePeriod(value: 7 | 14) { setChosenPeriod(value); setCalendar(null); setError(""); }
  function navigate(value: string) { setFrom(value); setCalendar(null); setError(""); }
  function changeTeam(value: string) { setTeam(value); setCalendar(null); setUpcoming(null); setAllUpcoming(false); }
  function retry() { setError(""); setRefresh(value => value + 1); }
  function saved() { setDialog(false); setNotice("Wniosek został wysłany. Jego status sprawdzisz w Moich wnioskach."); setCalendar(null); setUpcoming(null); setBalance(null); setRefresh(value => value + 1); }

  return <AppShell user={user} active="calendar" onNewRequest={() => setDialog(true)}>
    <section className="dashboard-heading">
      <div><span className="eyebrow">Dobrze mieć wspólny plan</span><h1>Kalendarz <span>zespołu</span></h1><p>{user.team?.name ?? "Nieobecności w zespole"} <span aria-hidden="true">·</span> Zobacz, kto i kiedy odpoczywa.</p></div>
      <Link href="/balances" prefetch={false} className={`dashboard-balance${balance && balance.availableHours < 0 ? " has-deficit" : ""}`}>
        <span className="balance-leaf" aria-hidden="true"><svg width="25" height="25" viewBox="0 0 24 24" fill="none"><path d="M19 4C10 3 4 6 5 13c.6 4 6 6 9 3 3-2 5-7 5-12Z" stroke="currentColor" strokeWidth="1.6"/><path d="m4 21 10-11" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round"/></svg></span>
        <div><span className="dashboard-balance-label">{balance?.availableHours && balance.availableHours < 0 ? "Twoje saldo" : "Do wykorzystania"}</span><strong>{balance ? balance.configured ? availableLabel(balance.availableHours) : "Brak ustawionego limitu" : balanceFailure ? "Sprawdź swoje saldo" : "Wczytywanie…"}</strong><small>{balance?.configured ? `${balance.availableHours} h · ${balance.year}` : "Moje saldo"}</small></div><span aria-hidden="true">↗</span>
      </Link>
    </section>
    {notice && <div className="message message-success dashboard-notice" role="status">{notice}<button type="button" aria-label="Zamknij komunikat" onClick={() => setNotice("")}>×</button></div>}
    {balanceFailure && <p className="field-hint" role="status">Saldo: {balanceFailure}</p>}
    <section className="team-calendar-panel" aria-label="Kalendarz nieobecności">
      <div className="calendar-toolbar">
        <div className="calendar-period-heading"><span className="calendar-icon" aria-hidden="true">▦</span><div><h2>{from ? dateLabel(from, { month: "long", year: "numeric" }) : "Kalendarz"}</h2><p>{from && `${shortDate(from)} – ${shortDate(to)}`}</p></div></div>
        <div className="calendar-controls">
          {user.isAdministrator && (calendar?.teams.length ?? upcoming?.teams.length ?? 0) > 0 && <label className="calendar-team-select"><span className="sr-only">Zespół</span><select aria-label="Zespół" value={team} onChange={event => changeTeam(event.target.value)}><option value="">Wszystkie zespoły</option>{(calendar?.teams ?? upcoming?.teams ?? []).map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>}
          <div className="period-toggle" role="group" aria-label="Długość okresu"><button type="button" aria-pressed={period === 7} onClick={() => changePeriod(7)}>Tydzień</button><button type="button" aria-pressed={period === 14} onClick={() => changePeriod(14)}>2 tygodnie</button></div>
          <div className="calendar-pagination"><button type="button" className="calendar-arrow" aria-label="Poprzedni okres" disabled={!from || shiftDate(from, -period) < "2025-01-01"} onClick={() => navigate(shiftDate(from, -period))}>‹</button><button type="button" className="calendar-today" disabled={!today} onClick={() => navigate(weekStart(today))}>Dzisiaj</button><button type="button" className="calendar-arrow" aria-label="Następny okres" disabled={!to || shiftDate(to, period) > "2100-12-31"} onClick={() => navigate(shiftDate(from, period))}>›</button></div>
        </div>
      </div>
      {error ? <div className="calendar-empty"><div className="message message-error" role="alert">{error}</div><button className="button button-secondary" onClick={retry}>Spróbuj ponownie</button></div> : !loadedPeriod ? <div className="calendar-loading" role="status"><span className="spinner" aria-hidden="true" />Wczytywanie nieobecności…</div> : calendar.people.length === 0 ? <div className="calendar-empty"><h3>Tu pojawią się plany zespołu</h3><p>{user.team || user.isAdministrator ? "W tym okresie nie ma jeszcze osób do wyświetlenia." : "Poproś administratora o przypisanie do zespołu."}</p></div> : <>
        <div className="calendar-desktop-view"><div className="calendar-scroll" tabIndex={0} role="region" aria-label="Tabela nieobecności, przewijana poziomo">
          <table className="team-calendar-table" style={{ "--calendar-days": period } as CSSProperties}>
            <caption className="sr-only">Nieobecności zespołu, {shortDate(from)} – {shortDate(to)}. Godziny oznaczają wymiar nieobecności.</caption>
            <thead><tr><th scope="col" className="calendar-person-heading">Pracownik <span>{calendar.people.length}</span></th>{days.map(day => <th key={day} scope="col" className={`${isWeekend(day) || holidays.has(day) ? "is-day-off " : ""}${day === today ? "is-today" : ""}`} title={holidays.get(day)}><span>{dateLabel(day, { weekday: "short" }).replace(".", "")}</span><strong>{Number(day.slice(8))}</strong>{holidays.has(day) && <span className="sr-only">{holidays.get(day)}</span>}</th>)}</tr></thead>
            <tbody>{calendar.people.map(person => <tr key={person.id}><th scope="row"><span className="person-initials" aria-hidden="true">{person.firstName.slice(0, 1)}{person.lastName.slice(0, 1)}</span><span className="calendar-person-name">{person.firstName} {person.lastName}{person.id === user.id && <small>Ty</small>}</span></th>{days.map(day => {
              const entries = calendar.entries.filter(entry => entry.userId === person.id && entry.days.some(item => item.date === day));
              return <td key={day} className={`${isWeekend(day) || holidays.has(day) ? "is-day-off " : ""}${day === today ? "is-today" : ""}`}>{entries.map(entry => <EntryChip key={entry.key} entry={entry} hours={entry.days.find(item => item.date === day)!.hours} own={person.id === user.id} name={`${person.firstName} ${person.lastName}`} date={day} />)}{entries.length === 0 && <span className="sr-only">Brak nieobecności</span>}</td>;
            })}</tr>)}</tbody>
          </table>
        </div></div>
        <div className="calendar-mobile-view">{days.map(day => {
          const entries = calendar.entries.filter(entry => entry.days.some(item => item.date === day));
          return <section key={day} className={`mobile-calendar-day${day === today ? " is-today" : ""}${isWeekend(day) || holidays.has(day) ? " is-day-off" : ""}`}><div className="mobile-day-label"><strong>{Number(day.slice(8))}</strong><span>{dateLabel(day, { weekday: "short" })}</span>{day === today && <small>Dziś</small>}</div><div className="mobile-day-entries">{holidays.has(day) && <small className="mobile-holiday">{holidays.get(day)}</small>}{entries.length ? entries.map(entry => {
            const person = calendar.people.find(item => item.id === entry.userId);
            return <div key={entry.key} className="mobile-calendar-entry"><span>{person?.firstName} {person?.lastName}{person?.id === user.id && <small> · Ty</small>}</span><EntryChip entry={entry} hours={entry.days.find(item => item.date === day)!.hours} own={entry.userId === user.id} name={`${person?.firstName} ${person?.lastName}`} date={day} /></div>;
          }) : <p>{isWeekend(day) ? "Weekend" : holidays.has(day) ? "Dzień wolny" : "Brak nieobecności"}</p>}</div></section>;
        })}</div>
        <div className="calendar-legend"><span><i className="legend-approved" aria-hidden="true" />✓ Zatwierdzony</span><span><i className="legend-pending" aria-hidden="true" />◷ Oczekuje</span><span><i className="legend-change" aria-hidden="true" />↗ Proponowana zmiana</span><span className="legend-hint">Godziny to wymiar nieobecności.</span></div>
      </>}
    </section>
    <section className="upcoming-section" aria-labelledby="upcoming-heading">
      <div className="upcoming-heading"><div><span className="eyebrow">Spójrz trochę dalej</span><h2 id="upcoming-heading">Nadchodzące nieobecności</h2><p>Najbliższe 2 miesiące{today ? ` · ${shortDate(today)} – ${shortDate(shiftDate(twoMonthsAfter(today), -1))}` : ""}</p></div><Link href="/requests" prefetch={false} className="text-link">Moje wnioski <span aria-hidden="true">→</span></Link></div>
      {upcomingError ? <div className="message message-error" role="alert">{upcomingError}<button type="button" className="text-button" onClick={retry}>Spróbuj ponownie</button></div> : !upcoming ? <div className="upcoming-empty" role="status">Wczytywanie najbliższych planów…</div> : !upcomingEntries.length ? <div className="upcoming-empty"><span aria-hidden="true">☀</span><h3>Przed Wami otwarty kalendarz</h3><p>W najbliższych dwóch miesiącach nie ma zgłoszonych nieobecności.</p></div> : <><ul className="upcoming-list">{visibleUpcoming.map(entry => {
        const person = upcoming.people.find(item => item.id === entry.userId);
        return <li key={entry.key}><span className="upcoming-date"><strong>{Number(entry.startDate.slice(8))}</strong><small>{dateLabel(entry.startDate, { month: "short" })}</small></span><div className="upcoming-person"><strong>{person?.firstName} {person?.lastName}{person?.id === user.id && <small> · Ty</small>}</strong><span>{shortDate(entry.startDate)}{entry.startDate !== entry.endDate ? ` – ${shortDate(entry.endDate)}` : ""} <span aria-hidden="true">·</span> {entry.totalHours} h</span></div><span className={`calendar-status is-${entry.kind}`}>{entry.kind === "effective" ? "✓ " : entry.kind === "proposed_change" ? "↗ " : "◷ "}{entryLabel(entry)}</span>{entry.userId === user.id && entry.canViewDetails && <Link href={`/requests?id=${entry.requestId}`} prefetch={false} className="upcoming-details" aria-label={`Szczegóły wniosku od ${shortDate(entry.startDate)}`}>Szczegóły <span aria-hidden="true">↗</span></Link>}</li>;
      })}</ul>{upcomingEntries.length > 8 && <button type="button" className="button button-secondary upcoming-more" onClick={() => setAllUpcoming(!allUpcoming)}>{allUpcoming ? "Pokaż mniej" : `Pokaż wszystkie (${upcomingEntries.length})`}</button>}</>}
    </section>
    {dialog && <RequestDialog mode="create" onClose={() => setDialog(false)} onSaved={saved} initialDate={today || undefined} />}
  </AppShell>;
}

function isWeekend(date: string) { const day = new Date(`${date}T12:00:00Z`).getUTCDay(); return day === 0 || day === 6; }
function EntryChip({ entry, hours, own, name, date }: { entry: CalendarEntry; hours: number; own: boolean; name: string; date: string }) {
  const label = `${name}, ${shortDate(date)}, ${hours} h, ${entryLabel(entry)}`;
  const className = `calendar-entry is-${entry.kind}`;
  const content = <><span aria-hidden="true">{entry.kind === "effective" ? "✓" : entry.kind === "proposed_change" ? "↗" : "◷"}</span><span>{hours} h</span>{entry.kind === "effective" && entry.status === "cancellation_pending" && <sup aria-hidden="true">×</sup>}{entry.kind === "effective" && entry.status === "change_pending" && <sup aria-hidden="true">↗</sup>}</>;
  return own && entry.canViewDetails ? <Link href={`/requests?id=${entry.requestId}`} prefetch={false} className={className} aria-label={label} title={label}>{content}</Link> : <span className={className} aria-label={label} title={label}>{content}</span>;
}
