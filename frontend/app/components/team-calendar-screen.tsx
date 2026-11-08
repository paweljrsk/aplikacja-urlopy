"use client";

import Link from "next/link";
import { useEffect, useRef, useState, type CSSProperties } from "react";
import { getApi, redirectForAuthError, type CurrentUser } from "@/lib/auth-api";
import { balanceError, type Balance } from "@/lib/balance-api";
import { CALENDAR_MAX_DATE, CALENDAR_MIN_DATE, calendarRange, entryLabel, filterCalendarEntries, groupCalendarEntries, shiftDate, shiftMonth, shortDate, twoMonthsAfter, type CalendarEntry, type CalendarGroup, type CalendarPeriod, type CalendarPerson, type CalendarStatusFilter, type TeamCalendar } from "@/lib/team-calendar-api";
import { AppShell, useWorkspaceUser } from "./app-shell";
import { RequestDialog } from "./request-dialog";
import { CalendarRequestDialog } from "./calendar-request-dialog";

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
  const [period, setPeriod] = useState<CalendarPeriod>("month");
  const [today, setToday] = useState("");
  const [anchor, setAnchor] = useState("");
  const [team, setTeam] = useState("");
  const [person, setPerson] = useState<CalendarPerson | null>(null);
  const [status, setStatus] = useState<CalendarStatusFilter>("");
  const [detailId, setDetailId] = useState<number | null>(null);
  const [calendar, setCalendar] = useState<TeamCalendar | null>(null);
  const [upcoming, setUpcoming] = useState<TeamCalendar | null>(null);
  const [balance, setBalance] = useState<Balance | null>(null);
  const [error, setError] = useState("");
  const [optionsError, setOptionsError] = useState("");
  const [upcomingError, setUpcomingError] = useState("");
  const [balanceFailure, setBalanceFailure] = useState("");
  const [refresh, setRefresh] = useState(0);
  const [dialog, setDialog] = useState(false);
  const [notice, setNotice] = useState("");
  const [allUpcoming, setAllUpcoming] = useState(false);
  const periodRequest = useRef(0);
  const { from, to, days } = calendarRange(anchor, period);
  const previous = from ? period === "month" ? shiftMonth(from, -1) : shiftDate(from, -period) : "";
  const next = from ? period === "month" ? shiftMonth(from, 1) : shiftDate(from, period) : "";

  useEffect(() => {
    let alive = true;
    getApi<{ today: string }>("/api/requests/options").then(options => {
      if (alive) { setToday(options.today); setAnchor(previous => previous || options.today); setOptionsError(""); }
    }).catch(failure => { if (alive && !redirectForAuthError(failure)) setOptionsError(balanceError(failure)); });
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

  const peopleById = new Map([...(upcoming?.people ?? []), ...(calendar?.people ?? [])].map(item => [item.id, item]));
  // Keep the selected person when navigating to a period without their entries.
  // Team/role changes clear this local selection rather than reusing another scope.
  if (person && !peopleById.has(person.id)) peopleById.set(person.id, person);
  const filterPeople = [...peopleById.values()].sort((a, b) => `${a.lastName} ${a.firstName}`.localeCompare(`${b.lastName} ${b.firstName}`, "pl") || a.id - b.id);
  const calendarEntries = filterCalendarEntries(calendar?.entries ?? [], person?.id, status);
  const calendarPeople = (calendar?.people ?? []).filter(item => (!person || item.id === person.id) && (!status || calendarEntries.some(entry => entry.userId === item.id)));
  const upcomingGroups = groupCalendarEntries(filterCalendarEntries(upcoming?.entries ?? [], person?.id, status));
  const visibleUpcoming = allUpcoming ? upcomingGroups : upcomingGroups.slice(0, 8);
  const filtered = person !== null || status !== "";
  const hasChanges = calendarEntries.some(entry => entry.status === "change_pending" || entry.status === "cancellation_pending");
  const loadedPeriod = calendar?.from === from && calendar?.to === to;
  const holidays = new Map(calendar?.holidays.map(day => [day.date, day.name]));
  function changePeriod(value: CalendarPeriod) {
    if (value === period) return;
    setPeriod(value);
    const range = calendarRange(anchor, value);
    if (range.from !== from || range.to !== to) { setCalendar(null); setError(""); }
  }
  function navigate(value: string) {
    const range = calendarRange(value, period);
    setAnchor(value);
    if (range.from !== from || range.to !== to) { setCalendar(null); setError(""); }
  }
  function clearFilters() { setPerson(null); setStatus(""); setAllUpcoming(false); }
  function changeTeam(value: string) { setTeam(value); setCalendar(null); setUpcoming(null); setDetailId(null); clearFilters(); }
  function retry() { setError(""); setOptionsError(""); setRefresh(value => value + 1); }
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
    <section className="calendar-filters" aria-label="Filtry kalendarza i listy">
      <div className="calendar-filter-fields">
        <label htmlFor="calendar-person">Pracownik<select id="calendar-person" value={person?.id ?? ""} onChange={event => { setPerson(peopleById.get(Number(event.target.value)) ?? null); setAllUpcoming(false); }}><option value="">Wszyscy pracownicy</option>{filterPeople.map(item => <option key={item.id} value={item.id}>{item.firstName} {item.lastName}{item.id === user.id ? " (Ty)" : ""}</option>)}</select></label>
        <label htmlFor="calendar-status">Status nieobecności<select id="calendar-status" value={status} onChange={event => { setStatus(event.target.value as CalendarStatusFilter); setAllUpcoming(false); }}><option value="">Wszystkie statusy</option><option value="approved">Zatwierdzone</option><option value="pending">Oczekujące</option><option value="changes">Zmiany i anulowania</option></select></label>
        {filtered && <button type="button" className="button button-secondary calendar-filter-reset" onClick={clearFilters}>Wyczyść filtry</button>}
      </div>
      <p className="calendar-filter-hint">Filtry dotyczą kalendarza i listy poniżej.{status === "approved" && " Zatwierdzony urlop obowiązuje także podczas oczekiwania na zmianę lub anulowanie."}{status === "pending" && " Oczekujące to nowe wnioski; propozycje zmian znajdziesz w „Zmiany i anulowania”."}</p>
    </section>
    <section className="team-calendar-panel" aria-label="Kalendarz nieobecności" data-tour="team-calendar">
      <div className="calendar-toolbar">
        <div className="calendar-period-heading"><span className="calendar-icon" aria-hidden="true">▦</span><div><h2>{from ? dateLabel(from, { month: "long", year: "numeric" }) : "Kalendarz"}</h2><p>{from && `${shortDate(from)} – ${shortDate(to)}`}</p></div></div>
        <div className="calendar-controls">
          {user.isAdministrator && (calendar?.teams.length ?? upcoming?.teams.length ?? 0) > 0 && <label className="calendar-team-select"><span className="sr-only">Zespół</span><select aria-label="Zespół" value={team} onChange={event => changeTeam(event.target.value)}><option value="">Wszystkie zespoły</option>{(calendar?.teams ?? upcoming?.teams ?? []).map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>}
          <div className="period-toggle" role="group" aria-label="Długość okresu"><button type="button" aria-pressed={period === 7} onClick={() => changePeriod(7)}>Tydzień</button><button type="button" aria-pressed={period === 14} onClick={() => changePeriod(14)}>2 tygodnie</button><button type="button" aria-pressed={period === "month"} onClick={() => changePeriod("month")}>Miesiąc</button></div>
          <div className="calendar-pagination"><button type="button" className="calendar-arrow" aria-label="Poprzedni okres" disabled={!previous || from <= CALENDAR_MIN_DATE} onClick={() => navigate(previous < CALENDAR_MIN_DATE ? CALENDAR_MIN_DATE : previous)}>‹</button><button type="button" className="calendar-today" disabled={!today} onClick={() => navigate(today)}>Dzisiaj</button><button type="button" className="calendar-arrow" aria-label="Następny okres" disabled={!next || to >= CALENDAR_MAX_DATE} onClick={() => navigate(next)}>›</button></div>
        </div>
      </div>
      {(optionsError || error) ? <div className="calendar-empty"><div className="message message-error" role="alert">{optionsError || error}</div><button className="button button-secondary" onClick={retry}>Spróbuj ponownie</button></div> : !loadedPeriod ? <div className="calendar-loading" role="status"><span className="spinner" aria-hidden="true" />Wczytywanie nieobecności…</div> : filtered && calendarEntries.length === 0 ? <div className="calendar-empty" role="status"><h3>Brak nieobecności dla wybranych filtrów</h3><p>W tym okresie nie ma pasujących wpisów. Zmień okres lub wyczyść filtry.</p></div> : calendar.people.length === 0 ? <div className="calendar-empty"><h3>Tu pojawią się plany zespołu</h3><p>{user.team || user.isAdministrator ? "W tym okresie nie ma jeszcze osób do wyświetlenia." : "Poproś administratora o przypisanie do zespołu."}</p></div> : <>
        <div className="calendar-desktop-view"><div className="calendar-scroll" tabIndex={0} role="region" aria-label="Tabela nieobecności, przewijana poziomo">
          <table className={`team-calendar-table${period === "month" ? " is-month" : ""}`} style={{ "--calendar-days": days.length } as CSSProperties}>
            <caption className="sr-only">Nieobecności zespołu, {shortDate(from)} – {shortDate(to)}. Godziny oznaczają wymiar nieobecności.</caption>
            <thead><tr><th scope="col" className="calendar-person-heading">Pracownik <span>{calendarPeople.length}</span></th>{days.map(day => <th key={day} scope="col" data-date={day} className={`${isWeekend(day) || holidays.has(day) ? "is-day-off " : ""}${day === today ? "is-today" : ""}`} title={holidays.get(day)}><span>{dateLabel(day, { weekday: "short" }).replace(".", "")}</span><strong>{Number(day.slice(8))}</strong>{holidays.has(day) && <span className="sr-only">{holidays.get(day)}</span>}</th>)}</tr></thead>
            <tbody>{calendarPeople.map(person => <tr key={person.id}><th scope="row"><span className="person-initials" aria-hidden="true">{person.firstName.slice(0, 1)}{person.lastName.slice(0, 1)}</span><span className="calendar-person-name">{person.firstName} {person.lastName}{person.id === user.id && <small>Ty</small>}</span></th>{days.map(day => {
              const entries = calendarEntries.filter(entry => entry.userId === person.id && entry.days.some(item => item.date === day));
              return <td key={day} className={`${isWeekend(day) || holidays.has(day) ? "is-day-off " : ""}${day === today ? "is-today" : ""}`}>{entries.map(entry => <EntryChip key={entry.key} entry={entry} hours={entry.days.find(item => item.date === day)!.hours} onOpen={setDetailId} name={`${person.firstName} ${person.lastName}`} date={day} />)}{entries.length === 0 && <span className="sr-only">Brak nieobecności</span>}</td>;
            })}</tr>)}</tbody>
          </table>
        </div></div>
        <div className="calendar-mobile-view">{days.map(day => {
          const entries = calendarEntries.filter(entry => entry.days.some(item => item.date === day));
          return <section key={day} data-date={day} className={`mobile-calendar-day${day === today ? " is-today" : ""}${isWeekend(day) || holidays.has(day) ? " is-day-off" : ""}`}><div className="mobile-day-label"><strong>{Number(day.slice(8))}</strong><span>{dateLabel(day, { weekday: "short" })}</span>{day === today && <small>Dziś</small>}</div><div className="mobile-day-entries">{holidays.has(day) && <small className="mobile-holiday">{holidays.get(day)}</small>}{entries.length ? entries.map(entry => {
            const person = calendar.people.find(item => item.id === entry.userId);
            return <div key={entry.key} className="mobile-calendar-entry"><span>{person?.firstName} {person?.lastName}{person?.id === user.id && <small> · Ty</small>}{(entry.status === "change_pending" || entry.status === "cancellation_pending") && <small className="mobile-entry-context">{entryLabel(entry)}</small>}</span><EntryChip entry={entry} hours={entry.days.find(item => item.date === day)!.hours} onOpen={setDetailId} name={`${person?.firstName} ${person?.lastName}`} date={day} /></div>;
          }) : <p>{isWeekend(day) ? "Weekend" : holidays.has(day) ? "Dzień wolny" : "Brak nieobecności"}</p>}</div></section>;
        })}</div>
        <div className="calendar-legend"><span><i className="legend-approved" aria-hidden="true" />✓ Zatwierdzony</span><span><i className="legend-pending" aria-hidden="true" />◷ Oczekuje</span><span><i className="legend-change" aria-hidden="true" />↗ Proponowana zmiana</span><span className="legend-hint">Godziny to wymiar nieobecności.</span>{hasChanges && <p className="calendar-change-hint">Do decyzji o zmianie lub anulowaniu obowiązuje zatwierdzony termin. Propozycja zmiany dotyczy tego samego urlopu; godzin obu wersji nie sumujemy.</p>}</div>
      </>}
    </section>
    <section className="upcoming-section" aria-labelledby="upcoming-heading" data-tour="upcoming-absences">
      <div className="upcoming-heading"><div><span className="eyebrow">Spójrz trochę dalej</span><h2 id="upcoming-heading">Nadchodzące nieobecności</h2><p>Najbliższe 2 miesiące{today ? ` · ${shortDate(today)} – ${shortDate(shiftDate(twoMonthsAfter(today), -1))}` : ""}</p></div><Link href="/requests" prefetch={false} className="text-link">Moje wnioski <span aria-hidden="true">→</span></Link></div>
      {upcomingError ? <div className="message message-error" role="alert">{upcomingError}<button type="button" className="text-button" onClick={retry}>Spróbuj ponownie</button></div> : !upcoming ? <div className="upcoming-empty" role="status">Wczytywanie najbliższych planów…</div> : !upcomingGroups.length ? <div className="upcoming-empty" role="status"><span aria-hidden="true">☀</span><h3>{filtered ? "Brak nadchodzących nieobecności dla wybranych filtrów" : "Przed Wami otwarty kalendarz"}</h3><p>{filtered ? "W kolejnych dwóch miesiącach nie ma pasujących wpisów. Możesz zmienić lub wyczyścić filtry." : "W najbliższych dwóch miesiącach nie ma zgłoszonych nieobecności."}</p></div> : <><ul className="upcoming-list">{visibleUpcoming.map(group => <UpcomingRequest key={group.requestId} group={group} person={upcoming.people.find(item => item.id === group.userId)} actorId={user.id} onOpen={setDetailId} />)}</ul>{upcomingGroups.length > 8 && <button type="button" className="button button-secondary upcoming-more" onClick={() => setAllUpcoming(!allUpcoming)}>{allUpcoming ? "Pokaż mniej" : `Pokaż wszystkie (${upcomingGroups.length})`}</button>}</>}
    </section>
    {dialog && <RequestDialog mode="create" onClose={() => setDialog(false)} onSaved={saved} initialDate={today || undefined} />}
    {detailId !== null && <CalendarRequestDialog key={detailId} requestId={detailId} actorId={user.id} onClose={() => setDetailId(null)} />}
  </AppShell>;
}

function isWeekend(date: string) { const day = new Date(`${date}T12:00:00Z`).getUTCDay(); return day === 0 || day === 6; }
function EntryChip({ entry, hours, onOpen, name, date }: { entry: CalendarEntry; hours: number; onOpen: (id: number) => void; name: string; date: string }) {
  const label = `${name}, ${shortDate(date)}, ${hours} h, ${entryLabel(entry)}`;
  const className = `calendar-entry is-${entry.kind}`;
  const content = <><span aria-hidden="true">{entry.kind === "effective" ? "✓" : entry.kind === "proposed_change" ? "↗" : "◷"}</span><span>{hours} h</span>{entry.kind === "effective" && entry.status === "cancellation_pending" && <sup aria-hidden="true">×</sup>}{entry.kind === "effective" && entry.status === "change_pending" && <sup aria-hidden="true">↗</sup>}</>;
  return entry.canViewDetails ? <button type="button" className={className} aria-label={`Szczegóły: ${label}`} title={label} onClick={() => onOpen(entry.requestId)}>{content}</button> : <span className={className} aria-label={label} title={label}>{content}</span>;
}

function UpcomingRequest({ group, person, actorId, onOpen }: { group: CalendarGroup; person?: CalendarPerson; actorId: number; onOpen: (id: number) => void }) {
  const change = group.entries.some(entry => entry.status === "change_pending");
  const cancellation = group.entries.some(entry => entry.status === "cancellation_pending");
  const primary = group.entries[0];
  return <li data-request-id={group.requestId} className={change || cancellation ? "has-pending-change" : ""}>
    <span className="upcoming-date"><strong>{Number(group.startDate.slice(8))}</strong><small>{dateLabel(group.startDate, { month: "short" })}</small></span>
    <div className="upcoming-person"><strong>{person?.firstName} {person?.lastName}{person?.id === actorId && <small> · Ty</small>}</strong>
      {group.entries.map(entry => <span className={`upcoming-version is-${entry.kind}`} key={entry.key}>
        {(change || cancellation) && <b>{entry.kind === "effective" ? "Obowiązujący termin" : "Proponowany termin"}</b>}
        {shortDate(entry.startDate)}{entry.startDate !== entry.endDate ? ` – ${shortDate(entry.endDate)}` : ""} <span aria-hidden="true">·</span> {entry.totalHours} h
      </span>)}
      {change && <small className="upcoming-change-note">Zmiana dotyczy tego samego urlopu i wymaga decyzji.</small>}
      {cancellation && <small className="upcoming-change-note">Urlop obowiązuje do zaakceptowania anulowania.</small>}
    </div>
    <span className={`calendar-status is-${change ? "proposed_change" : primary.kind}`}>{change ? "↗ Zmiana oczekuje" : cancellation ? "× Anulowanie oczekuje" : `${primary.kind === "effective" ? "✓ " : "◷ "}${entryLabel(primary)}`}</span>
    {group.entries.some(entry => entry.canViewDetails) && <button type="button" className="upcoming-details" onClick={() => onOpen(group.requestId)} aria-label={`Szczegóły wniosku ${group.requestId}`}>Szczegóły <span aria-hidden="true">↗</span></button>}
  </li>;
}
