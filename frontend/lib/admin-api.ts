import type { CurrentUser, TeamRole } from "./auth-api";

export type DictionaryItem = { id: number; code: string; name: string; active: boolean; version: number };
export type EmploymentType = DictionaryItem & { carriesUnusedHours: boolean };
export type AbsenceType = DictionaryItem & {
  revision: number;
  deductsBalance: boolean;
  employmentTypeIds: number[];
  namesByEmploymentType: Record<string, string>;
};
export type Assignment = {
  id: number;
  team: Pick<DictionaryItem, "id" | "code" | "name">;
  employmentType: Pick<DictionaryItem, "id" | "code" | "name">;
  role: TeamRole;
  validFrom: string;
  validUntil: string | null;
};
export type AdminUser = CurrentUser & {
  active: boolean;
  version: number;
  employmentType: Pick<DictionaryItem, "id" | "code" | "name"> | null;
  assignments: Assignment[];
};
export type Catalog = {
  teams: DictionaryItem[];
  employmentTypes: EmploymentType[];
  absenceTypes: AbsenceType[];
  roles: { value: TeamRole; label: string }[];
};
export type TemporaryCredential = { temporaryPassword: string; expiresAt: string };

export function warsawToday(): string {
  const parts = new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Warsaw", year: "numeric", month: "2-digit", day: "2-digit" }).formatToParts(new Date());
  const part = (type: Intl.DateTimeFormatPartTypes) => parts.find(p => p.type === type)?.value;
  return `${part("year")}-${part("month")}-${part("day")}`;
}

export function displayDate(value: string): string {
  return /^\d{4}-\d{2}-\d{2}$/.test(value) ? value.split("-").reverse().join(".") : value;
}
