import { ApiError, postApi } from "./auth-api";
import type { RequestMode } from "./request-api";

export type DurationInput = { mode: RequestMode; startDate: string; endDate: string; hours?: number };
export type RequestDuration = { workingDays: number; totalHours: number; hoursPerDay: number; timezone: string };

export async function getRequestDuration(input: DurationInput): Promise<RequestDuration> {
  const response = await postApi<{ duration?: RequestDuration }>("/api/requests/duration", input);
  const value = response?.duration;
  if (!value || !Number.isSafeInteger(value.workingDays) || value.workingDays < 0
    || !Number.isSafeInteger(value.totalHours) || value.totalHours < 0
    || value.hoursPerDay !== 8 || value.timezone !== "Europe/Warsaw"
    || (input.mode === "full_days" ? value.totalHours !== value.workingDays * value.hoursPerDay
      : value.workingDays > 1 || value.totalHours !== value.workingDays * input.hours!)) {
    throw new ApiError(0, "INVALID_RESPONSE");
  }
  return value;
}
