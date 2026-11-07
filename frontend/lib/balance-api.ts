import { ApiError, errorMessage, type TeamRole } from "./auth-api";

export type BalancePerson = {
  id: number; firstName: string; lastName: string; active: boolean;
  team: { id: number; name: string } | null;
  employmentType: { id: number; name: string; carriesUnusedHours: boolean } | null;
  role: TeamRole | null; canManage: boolean;
};
export type PeopleResponse = { people: BalancePerson[]; currentYear: number; today: string; isAdministrator: boolean };
export type Carry = { originYear: number; remainingHours: number };
export type Opening = { effectiveDate: string; annualHours: number; currentYearUsedHours: number; carries: Carry[] };
export type Balance = {
  userId: number; year: number; version: string; configured: boolean; annualHours: number | null;
  opening: Opening | null; carriedHours: number; allocatedHours: number; openingUsedHours: number;
  usedHours: number; approvedFutureHours: number; reservedHours: number; changeReservedHours: number;
  availableHours: number; deficitHours: number; displayAvailable: string;
  pools: { originYear: number; allocatedHours: number; reservedHours: number; chargedHours: number; availableHours: number; eligible: boolean }[];
  history: { id: number; action: string; occurredAt: string; actorName: string | null; reason: string | null; before: Record<string, unknown>; after: Record<string, unknown> }[];
};
export type Calendar = { year: number; timezone: string; hoursPerDay: number; minimumYear: number; maximumYear: number; holidays: { date: string; name: string; source: "statutory" | "company"; active: boolean }[] };
export type CalendarChange = { date: string; name: string; active: boolean };
export type CalendarPreview = CalendarChange & {
  previewToken: string; changedRequests: number;
  impacts: { requestId: number; reference: string; userId: number; employeeName: string; status: "approved" | "pending" | "approved_with_pending"; beforeHours: number; afterHours: number; beforePendingHours: number; afterPendingHours: number; action: string }[];
};

export function hoursLabel(hours: number): string {
  const value = Math.abs(hours);
  const days = Math.floor(value / 8);
  const remainder = value % 8;
  const period = `${days} ${days === 1 ? "dzień" : "dni"}${remainder ? ` i ${remainder} h` : ""}`;
  return hours < 0 ? `${hours} h (niedobór: ${period})` : `${hours} h = ${period}`;
}

export function wholeNumber(value: string, min = 0, max = 1000000): number {
  if (!/^\d+$/.test(value)) throw new Error("Wprowadź pełną, nieujemną liczbę.");
  const number = Number(value);
  if (!Number.isSafeInteger(number) || number < min || number > max) throw new Error(`Wprowadź liczbę od ${min} do ${max}.`);
  return number;
}

export function balanceError(error: unknown): string {
  if (!(error instanceof ApiError)) return error instanceof Error ? error.message : "Nie udało się wykonać operacji.";
  const messages: Record<string, string> = {
    CONFLICT: "Dane zmieniły się od ostatniego odczytu. Wczytaj aktualne dane i sprawdź zmianę ponownie.",
    IDEMPOTENCY_CONFLICT: "Ta operacja została już użyta z innymi danymi. Wczytaj aktualne dane przed kolejną zmianą.",
    OPENING_EXISTS: "Dane początkowe są już zapisane. Wczytaj aktualne saldo i użyj korekty.",
    OPENING_REQUIRED: "Najpierw uzupełnij dane początkowe pracownika.",
    LIMIT_NOT_CONFIGURED: "Brak limitu na wybrany rok. Kierownik lub administrator musi go najpierw wpisać.",
    INSUFFICIENT_BALANCE: "Brakuje dostępnych godzin do tej operacji.",
    VALIDATION_FAILED: "Sprawdź pełne godziny, daty, lata zaległości i wymagane uzasadnienie.",
    NOT_FOUND: "Nie znaleziono wskazanych danych. Odśwież widok.",
    CALENDAR_PREVIEW_STALE: "Dane zmieniły się od podglądu. Ponownie sprawdź wpływ zmiany, zanim ją zatwierdzisz.",
    CALENDAR_DATE_NOT_FUTURE: "Firmowe dni wolne można zmieniać wyłącznie dla dat od jutra.",
    CALENDAR_LEGACY_CONTEXT_MISSING: "Nie można przeliczyć jednego ze starszych wniosków: brakuje jednoznacznej historii zasad dla dodawanego dnia. Administrator musi najpierw indywidualnie uporządkować ten wniosek. Zmiana kalendarza nie została zapisana.",
    CALENDAR_POLICY_CONFLICT: "Zapisane zasady nieobecności nie pozwalają dodać tego dnia do wniosku. Administrator musi najpierw uporządkować wniosek. Zmiana kalendarza nie została zapisana.",
    OPENING_DATE_LOCKED: "Data rozpoczęcia ewidencji jest zablokowana, ponieważ istnieją już rozliczane wnioski. Możesz skorygować godziny, zachowując tę datę.",
    OPENING_YEAR_LOCKED: "Roku rozpoczęcia ewidencji nie można zmienić zwykłą korektą.",
    BEFORE_OPENING: "Okres sprzed rozpoczęcia ewidencji popraw przez korektę danych początkowych.",
    YEAR_NOT_STARTED: "Zaległość można udostępnić dopiero po rozpoczęciu danego roku.",
    CARRY_NOT_ALLOWED: "Forma zatrudnienia obowiązująca na dzień startu nie pozwala na przeniesienie zaległych godzin. Usuń starsze pule lub sprawdź przypisanie pracownika.",
    INVALID_YEAR: "Ewidencja obsługuje lata od 2025 do 2100.",
    DAILY_CAPACITY_EXCEEDED: "Zmiana przekroczyłaby 8 godzin nieobecności w jednym dniu. Najpierw uporządkuj kolidujące wnioski.",
    INVALID_TRANSITION: "Wniosek został już rozstrzygnięty lub zmieniony. Wczytaj aktualne dane.",
  };
  return messages[error.code] ?? errorMessage(error);
}

export function nextDate(value: string): string {
  const date = new Date(`${value}T12:00:00Z`);
  date.setUTCDate(date.getUTCDate() + 1);
  return date.toISOString().slice(0, 10);
}
