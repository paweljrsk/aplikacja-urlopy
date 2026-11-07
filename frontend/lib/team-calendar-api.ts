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

// These helpers arrange calendar dates only. Hours and balances always come from the API.
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
