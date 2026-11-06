export type TeamRole = "employee" | "manager" | "deputy";

export type CurrentUser = {
  id: number;
  firstName: string;
  lastName: string;
  email: string;
  isAdministrator: boolean;
  mustChangePassword: boolean;
  team: { id: number; code: string; name: string } | null;
  role: TeamRole | null;
};

export class ApiError extends Error {
  constructor(public readonly status: number, public readonly code: string) {
    super(code);
    this.name = "ApiError";
  }
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  let response: Response;
  try {
    response = await fetch(path, {
      ...options,
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json", ...options.headers },
    });
  } catch {
    throw new ApiError(0, "NETWORK_ERROR");
  }

  let result: unknown;
  try {
    result = await response.json();
  } catch {
    throw new ApiError(response.status, "INVALID_RESPONSE");
  }

  if (!response.ok) {
    const code =
      typeof result === "object" && result !== null && "error" in result &&
      typeof result.error === "object" && result.error !== null && "code" in result.error &&
      typeof result.error.code === "string" ? result.error.code : "REQUEST_FAILED";
    // Do not retain or display raw server responses that might contain debug data.
    throw new ApiError(response.status, code);
  }

  return result as T;
}

export function getCurrentUser() {
  return request<{ user: CurrentUser }>("/api/auth/me");
}

export function getAdminUsers() {
  return request<{ users: CurrentUser[] }>("/api/admin/users");
}

export async function postApi<T>(path: string, body: Record<string, string> = {}): Promise<T> {
  // Read again after login/session rotation; never persist CSRF or credentials.
  const { csrfToken } = await request<{ csrfToken: string }>("/api/auth/csrf");
  if (typeof csrfToken !== "string" || csrfToken.length === 0) {
    throw new ApiError(0, "INVALID_RESPONSE");
  }
  return request<T>(path, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
    body: JSON.stringify(body),
  });
}

export function errorMessage(error: unknown): string {
  if (!(error instanceof ApiError)) return "Nie udało się wykonać operacji. Spróbuj ponownie.";
  const messages: Record<string, string> = {
    INVALID_CREDENTIALS: "Nieprawidłowy e-mail lub hasło. Jeśli używasz hasła tymczasowego, sprawdź jego ważność z administratorem.",
    LOGIN_THROTTLED: "Zbyt wiele prób logowania. Odczekaj chwilę i spróbuj ponownie.",
    AUTH_REQUIRED: "Sesja wygasła. Zaloguj się ponownie.",
    PASSWORD_CHANGE_REQUIRED: "Zmień hasło tymczasowe, aby korzystać z aplikacji.",
    CSRF_INVALID: "Nie udało się potwierdzić sesji. Odśwież stronę i spróbuj ponownie.",
    FORBIDDEN: "Nie masz uprawnień do tej operacji.",
    INVALID_CURRENT_PASSWORD: "Podane aktualne hasło jest nieprawidłowe.",
    INVALID_ADMIN_PASSWORD: "Hasło administratora jest nieprawidłowe.",
    VALIDATION_FAILED: "Sprawdź dane formularza i dostępność konta. Jeśli problem się powtarza, skontaktuj się z administratorem.",
    CREDENTIAL_THROTTLED: "Zbyt wiele prób potwierdzenia hasła. Odczekaj minutę i spróbuj ponownie.",
    CONFLICT: "Stan konta zmienił się podczas operacji. Odśwież stronę i spróbuj ponownie.",
    NOT_FOUND: "Nie znaleziono wskazanego konta. Odśwież listę użytkowników.",
    NETWORK_ERROR: "Brak połączenia z aplikacją. Sprawdź połączenie i spróbuj ponownie.",
    INVALID_RESPONSE: "Aplikacja nie zwróciła prawidłowej odpowiedzi. Spróbuj ponownie później.",
  };
  return messages[error.code] ?? (error.status === 429 ? messages.LOGIN_THROTTLED : "Nie udało się wykonać operacji. Spróbuj ponownie lub skontaktuj się z administratorem.");
}

export function redirectForAuthError(error: unknown): boolean {
  if (error instanceof ApiError && error.status === 401) {
    window.location.replace("/login?expired=1");
    return true;
  }
  if (error instanceof ApiError && error.code === "PASSWORD_CHANGE_REQUIRED") {
    window.location.replace("/change-password");
    return true;
  }
  return false;
}

export function roleLabel(user: CurrentUser): string {
  if (user.isAdministrator) return "Administrator";
  if (!user.role) return "Bez roli zespołowej";
  return ({ employee: "Pracownik", manager: "Kierownik", deputy: "Zastępca" } as const)[user.role];
}
