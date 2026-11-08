import { ApiError, getApi, postApi } from "./auth-api";
import { balanceError } from "./balance-api";

export type RequestStatus = "pending" | "approved" | "rejected" | "withdrawn" | "cancelled" | "change_pending" | "cancellation_pending";
export type RequestMode = "full_days" | "hourly";
export type RequestFormMode = "create" | "edit" | "change";
export type RequestDay = { date: string; hours: number };
export type RequestRevision = {
  id: number; revision: number; baseRevision: number | null;
  kind: "initial" | "change" | "cancellation";
  mode: RequestMode; startDate: string; endDate: string; totalHours: number; days: RequestDay[];
  absenceType: { id: number; code: string; revision: number; name: string; deductsBalance: boolean };
  submitted: { at: string; actorName: string | null; reason: string | null };
  decision: { outcome: "approved" | "rejected" | "withdrawn" | "superseded"; at: string; actorName: string | null; reason: string | null } | null;
};
export type RequestSummary = {
  id: number; reference: string; version: number | null; status: RequestStatus | null; currentVisible: boolean;
  user: { id: number; firstName: string; lastName: string };
  latestVisibleRevision: RequestRevision;
  actions: Record<"edit" | "withdraw" | "approve" | "reject" | "change" | "cancel" | "correct", boolean>;
};
export type RequestDetail = RequestSummary & {
  current: RequestRevision | null; effective: RequestRevision | null;
  pending: RequestRevision | null; history: RequestRevision[];
};
export type RequestOptions = {
  today: string; timezone: string; hoursPerDay: number; modes: RequestMode[];
  absenceTypes: { id: number; name: string; code: string; revision: number; deductsBalance: boolean; availableForCurrentEmployment: boolean }[];
};
export type RequestApplication = {
  absenceTypeId: number; mode: RequestMode; startDate: string; endDate: string; hours?: number; expectedVersion?: number;
};
export type RequestPreview = {
  totalHours: number; days: RequestDay[]; deductsBalance: boolean; timezone: string;
  years: { year: number; beforeAvailableHours: number; afterAvailableHours: number; additionalReservedHours: number }[];
};
export type RequestOperation = { id: number; reference: string; version: number; revision: number; status: RequestStatus };
export type RequestsPage = { requests: RequestSummary[]; nextBeforeId: number | null };

// A transport-level success is not proof that a mutation was acknowledged.
// Reject incomplete receipts so callers preserve their original idempotency key.
export function requestOperationResponse(result: unknown, expectedId?: number): { operation: RequestOperation } {
  const receipt = typeof result === "object" && result !== null && "operation" in result ? result.operation : null;
  if (typeof receipt !== "object" || receipt === null) throw new ApiError(0, "INVALID_RESPONSE");
  const value = receipt as Record<string, unknown>;
  const positiveInteger = (value: unknown): value is number => typeof value === "number" && Number.isSafeInteger(value) && value > 0;
  if (!positiveInteger(value.id) || expectedId !== undefined && value.id !== expectedId
    || !positiveInteger(value.version) || !positiveInteger(value.revision)
    || typeof value.reference !== "string" || value.reference.trim().length === 0
    || typeof value.status !== "string" || !["pending", "approved", "rejected", "withdrawn", "cancelled", "change_pending", "cancellation_pending"].includes(value.status)) {
    throw new ApiError(0, "INVALID_RESPONSE");
  }
  return { operation: { id: value.id, version: value.version, revision: value.revision, reference: value.reference, status: value.status as RequestStatus } };
}

export function getRequestOptions() { return getApi<RequestOptions>("/api/requests/options"); }
export function getRequest(id: number) { return getApi<{ request: RequestDetail }>(`/api/requests/${id}`); }
export function getMyRequests(status: RequestStatus | "" = "", beforeId?: number) {
  const query = new URLSearchParams({ scope: "mine", limit: "20" });
  if (status) query.set("status", status);
  if (beforeId) query.set("beforeId", String(beforeId));
  return getApi<RequestsPage>(`/api/requests?${query}`);
}
export function previewRequest(mode: RequestFormMode, body: RequestApplication, id?: number) {
  const path = mode === "create" ? "/api/requests/preview" : `/api/requests/${id}/${mode}-preview`;
  return postApi<{ preview: RequestPreview }>(path, body);
}
export async function saveRequest(mode: RequestFormMode, body: RequestApplication, operationKey: string, id?: number) {
  const path = mode === "create" ? "/api/requests" : `/api/requests/${id}/${mode}`;
  return requestOperationResponse(await postApi<unknown>(path, { ...body, operationKey }), mode === "create" ? undefined : id);
}
export async function requestAction(id: number, action: "withdraw" | "cancel", expectedVersion: number, operationKey: string) {
  return requestOperationResponse(await postApi<unknown>(`/api/requests/${id}/${action}`, { expectedVersion, operationKey }), id);
}
export function requestStatusLabel(status: RequestStatus | null): string {
  return status ? ({ pending: "Oczekuje na decyzję", approved: "Zaakceptowany", rejected: "Odrzucony", withdrawn: "Wycofany", cancelled: "Anulowany", change_pending: "Zmiana oczekuje", cancellation_pending: "Anulowanie oczekuje" })[status] : "Historia wniosku";
}
export function requestDate(value: string): string {
  return new Intl.DateTimeFormat("pl-PL", { day: "numeric", month: "short", year: "numeric", timeZone: "UTC" }).format(new Date(`${value}T12:00:00Z`));
}
export function requestRange(revision: { startDate: string; endDate: string }): string {
  return revision.startDate === revision.endDate ? requestDate(revision.startDate) : `${requestDate(revision.startDate)} – ${requestDate(revision.endDate)}`;
}
export function requestTimestamp(value: string): string {
  return new Intl.DateTimeFormat("pl-PL", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Warsaw" }).format(new Date(value));
}
export function unconfirmedRequest(error: unknown): boolean {
  return error instanceof ApiError && (error.status === 0 || error.status >= 500 || error.code === "INVALID_RESPONSE");
}
export function requestConflict(error: unknown): boolean {
  return error instanceof ApiError && ["CONFLICT", "INVALID_TRANSITION", "IDEMPOTENCY_CONFLICT"].includes(error.code);
}
export function requestError(error: unknown): string {
  if (!(error instanceof ApiError)) return "Nie udało się wykonać operacji. Spróbuj ponownie.";
  const messages: Record<string, string> = {
    VALIDATION_FAILED: "Sprawdź daty i wymiar. Całe dni mogą tworzyć zakres, a 1–7 godzin dotyczy jednego dnia.",
    NO_WORKING_DAYS: "W wybranym terminie nie ma dni roboczych. Wybierz inny termin.",
    PAST_REQUEST: "Własny wniosek może obejmować daty od dzisiaj. Korektę wcześniejszego terminu uzgodnij z przełożonym.",
    ABSENCE_TYPE_UNAVAILABLE: "Ten rodzaj nieobecności nie jest dostępny dla wybranego terminu. Wybierz inny rodzaj lub skontaktuj się z przełożonym.",
    ASSIGNMENT_REQUIRED: "Brakuje przypisania do zespołu lub formy zatrudnienia dla wybranej daty. Skontaktuj się z administratorem.",
    INACTIVE_ASSIGNMENT: "Zespół lub forma zatrudnienia dla wybranej daty są nieaktywne. Skontaktuj się z administratorem.",
    LEGACY_CONTEXT_MISSING: "Starszy wniosek wymaga sprawdzenia przez administratora. Nie zmieniono jego danych.",
    CONFLICT: "Wniosek lub saldo zmieniły się w międzyczasie. Wczytaj aktualne dane i sprawdź je przed ponownym wysłaniem.",
    INVALID_TRANSITION: "Wniosek został już zmieniony lub rozstrzygnięty. Wczytaj aktualne dane.",
    NOT_FOUND: "Nie znaleziono tego wniosku. Odśwież listę.",
    FORBIDDEN: "Nie masz już dostępu do tej operacji. Odśwież dane.",
    DAILY_CAPACITY_EXCEEDED: "W tym terminie masz już inną nieobecność. Łączny wymiar nie może przekraczać 8 godzin dziennie.",
    INSUFFICIENT_BALANCE: "Brakuje dostępnych godzin. Zmień termin lub wymiar albo sprawdź saldo z przełożonym.",
  };
  return messages[error.code] ?? balanceError(error);
}
