import { getApi, postApi, type CurrentUser } from "./auth-api";
import { requestOperationResponse, type RequestStatus, type RequestsPage } from "./request-api";

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
  return requestOperationResponse(await postApi<unknown>(`/api/requests/${id}/${action}`, { ...payload, operationKey }), id);
}
