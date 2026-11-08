export type CalendarPerson = { id: number; firstName: string; lastName: string };
export type CalendarEntry = {
  key: string; requestId: number; canViewDetails: boolean; userId: number;
  status: "approved" | "pending" | "change_pending" | "cancellation_pending";
  kind: "effective" | "pending" | "proposed_change";
  days: { date: string; hours: number }[]; startDate: string; endDate: string; totalHours: number;
};
export type TeamCalendar = {
  today: string; from: string; to: string; timezone: string;
  teams: { id: number; name: string }[]; selectedTeamId: number | null;
  people: CalendarPerson[]; entries: CalendarEntry[]; holidays: { date: string; name: string }[];
};

export type CalendarStatusFilter = "" | "approved" | "pending" | "changes";
export type CalendarGroup = { requestId: number; userId: number; entries: CalendarEntry[]; startDate: string };

// Filters only narrow the already-authorized feed. They never infer hidden versions.
export function filterCalendarEntries(entries: CalendarEntry[], personId: number | undefined, status: CalendarStatusFilter): CalendarEntry[] {
  return entries.filter(entry => (personId === undefined || entry.userId === personId) && (
    status === "" || (status === "approved" && entry.kind === "effective")
    || (status === "pending" && entry.kind === "pending")
    || (status === "changes" && (entry.status === "change_pending" || entry.status === "cancellation_pending"))
  ));
}

export function groupCalendarEntries(entries: CalendarEntry[]): CalendarGroup[] {
  const groups = new Map<number, CalendarGroup>();
  for (const entry of entries) {
    const group = groups.get(entry.requestId);
    if (group) {
      group.entries.push(entry);
      if (entry.startDate < group.startDate) group.startDate = entry.startDate;
    } else groups.set(entry.requestId, { requestId: entry.requestId, userId: entry.userId, entries: [entry], startDate: entry.startDate });
  }
  const order = { effective: 0, pending: 1, proposed_change: 2 };
  for (const group of groups.values()) group.entries.sort((a, b) => order[a.kind] - order[b.kind]);
  return [...groups.values()].sort((a, b) => a.startDate.localeCompare(b.startDate) || a.userId - b.userId || a.requestId - b.requestId);
}

// These helpers arrange calendar dates only. Hours and balances always come from the API.
export type CalendarPeriod = 7 | 14 | "month";
export const CALENDAR_MIN_DATE = "2025-01-01";
export const CALENDAR_MAX_DATE = "2100-12-31";

export function shiftMonth(date: string, months: number): string {
  const result = new Date(`${date.slice(0, 7)}-01T12:00:00Z`);
  result.setUTCMonth(result.getUTCMonth() + months);
  return result.toISOString().slice(0, 10);
}

export function calendarRange(anchor: string, period: CalendarPeriod): { from: string; to: string; days: string[] } {
  if (!anchor) return { from: "", to: "", days: [] };
  const start = period === "month" ? `${anchor.slice(0, 7)}-01` : weekStart(anchor);
  const end = period === "month" ? shiftDate(shiftMonth(start, 1), -1) : shiftDate(start, period - 1);
  // At the supported year boundaries a week may be partial; never ask the API
  // for dates outside its calendar. Month views always contain the full month.
  const from = start < CALENDAR_MIN_DATE ? CALENDAR_MIN_DATE : start;
  const to = end > CALENDAR_MAX_DATE ? CALENDAR_MAX_DATE : end;
  const days: string[] = [];
  for (let date = from; date <= to; date = shiftDate(date, 1)) days.push(date);
  return { from, to, days };
}

export function shiftDate(date: string, days: number): string {
  const result = new Date(`${date}T12:00:00Z`);
  result.setUTCDate(result.getUTCDate() + days);
  return result.toISOString().slice(0, 10);
}
export function weekStart(date: string): string {
  return shiftDate(date, -((new Date(`${date}T12:00:00Z`).getUTCDay() + 6) % 7));
}
export function twoMonthsAfter(date: string): string {
  const result = new Date(`${date}T12:00:00Z`);
  const day = result.getUTCDate();
  result.setUTCDate(1); result.setUTCMonth(result.getUTCMonth() + 2);
  const lastDay = new Date(Date.UTC(result.getUTCFullYear(), result.getUTCMonth() + 1, 0)).getUTCDate();
  result.setUTCDate(Math.min(day, lastDay));
  return result.toISOString().slice(0, 10);
}
export function shortDate(date: string): string {
  return new Intl.DateTimeFormat("pl-PL", { day: "numeric", month: "short", timeZone: "UTC" }).format(new Date(`${date}T12:00:00Z`));
}
export function entryLabel(entry: CalendarEntry): string {
  if (entry.kind === "proposed_change") return "Proponowana zmiana";
  return ({ approved: "Zatwierdzony", pending: "Oczekuje", change_pending: "Zatwierdzony · zmiana w toku", cancellation_pending: "Zatwierdzony · anulowanie w toku" })[entry.status];
}
