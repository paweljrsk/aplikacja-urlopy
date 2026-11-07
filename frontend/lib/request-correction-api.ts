import { ApiError, postApi } from "./auth-api";
import { requestConflict, requestError, type RequestDay, type RequestMode, type RequestOperation } from "./request-api";

export type CorrectionApplication = {
  expectedVersion: number; action: "change" | "cancel"; reason: string;
  absenceTypeId?: number; mode?: RequestMode; startDate?: string; endDate?: string; hours?: number;
};
export type CorrectionSnapshot = {
  revision: number; kind: string; absenceTypeId: number; policyRevision: number;
  deductsBalance: boolean; mode: RequestMode; startDate: string; endDate: string;
  totalHours: number; days: RequestDay[]; cancelled?: boolean;
};
export type CorrectionPool = {
  originYear: number; allocatedHours: number; reservedHours: number; chargedHours: number; availableHours: number;
};
export type CorrectionPreview = {
  id: number; version: number; action: "change" | "cancel"; previewToken: string;
  before: CorrectionSnapshot; after: CorrectionSnapshot;
  impact: {
    hoursByYear: { year: number; beforeHours: number; afterHours: number; deltaHours: number }[];
    pools: { before: CorrectionPool[]; after: CorrectionPool[] } | null;
  };
  requiresDeficitConfirmation: boolean;
};
export type CorrectionConfirmation = CorrectionApplication & {
  operationKey: string; previewToken: string; confirm: true; allowDeficit?: true;
};

export async function previewCorrection(id: number, body: CorrectionApplication) {
  const result = await postApi<{ preview: CorrectionPreview }>(`/api/requests/${id}/correction-preview`, body);
  if (!result.preview || result.preview.id !== id || result.preview.version !== body.expectedVersion
    || result.preview.action !== body.action || typeof result.preview.previewToken !== "string") {
    throw new ApiError(0, "INVALID_RESPONSE");
  }
  return result;
}

export async function saveCorrection(id: number, body: CorrectionConfirmation) {
  const result = await postApi<{ operation: RequestOperation }>(`/api/requests/${id}/correct`, body);
  if (!result.operation || result.operation.id !== id) throw new ApiError(0, "INVALID_RESPONSE");
  return result;
}

export function correctionConflict(error: unknown): boolean {
  return requestConflict(error) || error instanceof ApiError && ["STALE_PREVIEW", "PREVIEW_REQUIRED"].includes(error.code);
}

export function correctionError(error: unknown): string {
  if (!(error instanceof ApiError)) return requestError(error);
  const messages: Record<string, string> = {
    REASON_REQUIRED: "Podaj uzasadnienie korekty (1–1000 znaków).",
    STALE_PREVIEW: "Dane zmieniły się od podglądu. Wczytaj aktualny wniosek i ponownie sprawdź skutki korekty.",
    PREVIEW_REQUIRED: "Potrzebny jest aktualny podgląd. Wczytaj wniosek i ponownie sprawdź skutki korekty.",
    CONFIRMATION_REQUIRED: "Potwierdź skutki korekty przed zapisem.",
    DEFICIT_CONFIRMATION_REQUIRED: "Administrator musi osobno potwierdzić zwiększenie niedoboru wskazane w podglądzie.",
    HISTORICAL_CORRECTION_REQUIRED: "Korekta dotyczy nieobecności obejmującej wcześniejsze dni. Dla dzisiejszych i przyszłych wniosków użyj zwykłego obiegu zmiany lub anulowania.",
    FORBIDDEN: "Nie masz uprawnień do tej korekty. Korektę zwiększającą niedobór może wykonać wyłącznie administrator.",
    DAILY_CAPACITY_EXCEEDED: "Korekta przekroczyłaby 8 godzin nieobecności tej osoby w jednym dniu. Sprawdź pozostałe wnioski.",
    INSUFFICIENT_BALANCE: "Brakuje dostępnych godzin do tej korekty. Sprawdź wymiar i saldo pracownika.",
  };
  return messages[error.code] ?? requestError(error);
}
