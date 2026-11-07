import { ApiError, getApi, postApi, type CurrentUser } from "./auth-api";
import type { RequestOperation, RequestStatus, RequestsPage } from "./request-api";

export type ManagedStatus = RequestStatus | "awaiting" | "";
export type DecisionAction = "approve" | "reject";
export type DecisionApplication = { expectedVersion: number; action: DecisionAction; reason?: string };
export type DecisionPreview = {
  id: number; version: number; action: DecisionAction; statusBefore: RequestStatus; statusAfter: RequestStatus;
  years: { year: number; beforeAvailableHours: number; afterAvailableHours: number; beforeReservedHours: number; afterReservedHours: number; beforeApprovedHours: number; afterApprovedHours: number }[] | null;
};
export function canManageRequests(user: CurrentUser) {
  return user.isAdministrator || user.role === "manager" || user.role === "deputy";
}
export function getManagedRequests(status: ManagedStatus, beforeId?: number) {
  const query = new URLSearchParams({ scope: "managed", limit: "20" });
  if (status) query.set("status", status);
  if (beforeId) query.set("beforeId", String(beforeId));
  return getApi<RequestsPage>(`/api/requests?${query}`);
}
export function previewDecision(id: number, body: DecisionApplication) {
  return postApi<{ preview: DecisionPreview }>(`/api/requests/${id}/decision-preview`, body);
}
export async function saveDecision(id: number, body: DecisionApplication, operationKey: string) {
  const { action, ...payload } = body;
  const result = await postApi<{ operation: RequestOperation }>(`/api/requests/${id}/${action}`, { ...payload, operationKey });
  const receipt = result?.operation;
  if (!receipt || receipt.id !== id || !Number.isSafeInteger(receipt.version) || receipt.version < 1
    || !Number.isSafeInteger(receipt.revision) || receipt.revision < 1 || typeof receipt.reference !== "string"
    || !["pending", "approved", "rejected", "withdrawn", "cancelled", "change_pending", "cancellation_pending"].includes(receipt.status)) throw new ApiError(0, "INVALID_RESPONSE");
  return result;
}
