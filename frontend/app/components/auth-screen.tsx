"use client";

import Link from "next/link";
import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import { AppShell, NotusBrand } from "./app-shell";
import {
  ApiError,
  errorMessage,
  getCurrentUser,
  postApi,
  redirectForAuthError,
  roleLabel,
  type CurrentUser,
} from "@/lib/auth-api";

type Screen = "login" | "change-password" | "home";

function Brand() {
  return <NotusBrand />;
}

function Message({ children, kind = "error" }: { children: ReactNode; kind?: "error" | "success" | "info" }) {
  return <div className={`message message-${kind}`} role={kind === "error" ? "alert" : "status"}>{children}</div>;
}

function PasswordField({ id, label, value, onChange, autoComplete = "current-password", hint, disabled = false }: {
  id: string; label: string; value: string; onChange: (value: string) => void;
  autoComplete?: "current-password" | "new-password"; hint?: string; disabled?: boolean;
}) {
  const [visible, setVisible] = useState(false);
  return <div className="field">
    <label htmlFor={id}>{label}</label>
    <div className="password-input">
      <input id={id} name={id} type={visible ? "text" : "password"} value={value}
        onChange={event => onChange(event.target.value)} autoComplete={autoComplete}
        required disabled={disabled} maxLength={autoComplete === "new-password" ? 256 : 4096}
        aria-describedby={hint ? `${id}-hint` : undefined} />
      <button type="button" className="password-toggle" aria-label={`${visible ? "Ukryj" : "Pokaż"}: ${label.toLowerCase()}`}
        aria-pressed={visible} onClick={() => setVisible(!visible)} disabled={disabled}>{visible ? "Ukryj" : "Pokaż"}</button>
    </div>
    {hint && <p className="field-hint" id={`${id}-hint`}>{hint}</p>}
  </div>;
}

function AuthFrame({ children }: { children: ReactNode }) {
  return <div className="auth-page">
    <header className="auth-brand"><Brand /><span className="internal-label">Aplikacja wewnętrzna</span></header>
    <main id="main" className="auth-grid">
      <section className="auth-intro" aria-label="Urlopy zespołu">
        <span className="eyebrow">Wspólne plany. Więcej spokoju.</span>
        <h1>Dobry odpoczynek<br />zaczyna się od planu.</h1>
        <p>Twoje miejsce na sprawy związane<br className="desktop-break" /> z nieobecnościami w zespole.</p>
        <div className="intro-decoration" aria-hidden="true"><span /><span /><span /><span /><span /></div>
        <div className="intro-footnote"><span className="status-dot" />Dostęp dla członków zespołu</div>
      </section>
      <section className="auth-card">{children}</section>
    </main>
    <footer className="auth-footer">Urlopy zespołu<span>Wewnętrzna aplikacja firmowa</span></footer>
  </div>;
}

export function AuthScreen({ screen }: { screen: Screen }) {
  const [user, setUser] = useState<CurrentUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState("");
  const [loginNotice, setLoginNotice] = useState("");
  const [loggingOut, setLoggingOut] = useState(false);
  const [logoutError, setLogoutError] = useState("");

  useEffect(() => {
    let disposed = false;
    let requestNumber = 0;
    async function refresh() {
      const currentRequest = ++requestNumber;
      try {
        const result = await getCurrentUser();
        if (disposed || currentRequest !== requestNumber) return;
        if (screen === "login") {
          window.location.replace(result.user.mustChangePassword ? "/change-password" : "/");
          return;
        }
        if (screen === "home" && result.user.mustChangePassword) {
          window.location.replace("/change-password");
          return;
        }
        setUser(result.user);
        setLoadError("");
        setLoading(false);
      } catch (error) {
        if (disposed || currentRequest !== requestNumber) return;
        setUser(null);
        if (screen !== "login" && redirectForAuthError(error)) return;
        if (screen === "login" && error instanceof ApiError && error.status === 401) {
          const query = new URLSearchParams(window.location.search);
          setLoginNotice(query.has("changed") ? "Hasło zostało zmienione. Zaloguj się nowym hasłem." : query.has("expired") ? "Sesja wygasła. Zaloguj się ponownie." : "");
          setLoadError("");
        } else {
          setLoadError(errorMessage(error));
        }
        setLoading(false);
      }
    }
    void refresh();
    const onFocus = () => { void refresh(); };
    const onPageShow = (event: PageTransitionEvent) => {
      if (event.persisted) {
        setUser(null);
        setLoading(true);
        void refresh();
      }
    };
    window.addEventListener("focus", onFocus);
    window.addEventListener("pageshow", onPageShow);
    return () => {
      disposed = true;
      window.removeEventListener("focus", onFocus);
      window.removeEventListener("pageshow", onPageShow);
    };
  }, [screen]);

  async function logout() {
    if (loggingOut) return;
    setLoggingOut(true);
    setLogoutError("");
    try {
      await postApi("/api/auth/logout");
      setUser(null);
      window.location.replace("/login");
    } catch (error) {
      if (!redirectForAuthError(error)) setLogoutError(errorMessage(error));
    } finally {
      setLoggingOut(false);
    }
  }

  if (loading) return <AuthFrame><div className="loading-panel" role="status"><span className="spinner" aria-hidden="true" /><h2>Sprawdzamy Twoją sesję</h2><p>Za chwilę przejdziesz dalej.</p></div></AuthFrame>;
  if (screen === "login") return <AuthFrame><LoginForm notice={loginNotice} initialError={loadError} /></AuthFrame>;
  if (!user) return <AuthFrame><span className="eyebrow">Połączenie z aplikacją</span><h2>Nie udało się wczytać konta</h2><Message>{loadError}</Message><button className="button button-primary" onClick={() => window.location.reload()}>Spróbuj ponownie</button></AuthFrame>;
  if (screen === "change-password") return <AuthFrame>
    <PasswordChangeForm user={user} />
    <div className="form-footer">
      {!user.mustChangePassword && <Link href="/account" prefetch={false}>Wróć do konta</Link>}
      <button className="text-button" disabled={loggingOut} onClick={() => void logout()}>{loggingOut ? "Wylogowywanie…" : "Wyloguj się"}</button>
    </div>
    {logoutError && <Message>{logoutError}</Message>}
  </AuthFrame>;

  return <AppShell user={user} active="account">
      <div className="workspace-heading"><div><span className="eyebrow">Twoje miejsce w zespole</span><h1>Cześć, {user.firstName}.</h1><p>Jesteś zalogowany. Poniżej znajdziesz dane swojego konta.</p></div><span className="account-badge"><span className="status-dot" />{roleLabel(user)}</span></div>
      {logoutError && <Message>{logoutError}</Message>}
      <section className="profile-card" aria-labelledby="profile-title">
        <div className="profile-heading"><div className="avatar" aria-hidden="true">{user.firstName.slice(0, 1)}{user.lastName.slice(0, 1)}</div><div><h2 id="profile-title">{user.firstName} {user.lastName}</h2><p>Twoje konto</p></div><Link className="button button-secondary" href="/change-password" prefetch={false}>Zmień hasło</Link></div>
        <dl className="profile-details"><div><dt>Adres e-mail</dt><dd>{user.email}</dd></div><div><dt>Zespół</dt><dd>{user.team?.name ?? "Brak bieżącego przypisania"}</dd></div><div><dt>Rola</dt><dd>{roleLabel(user)}</dd></div></dl>
      </section>
      <section className="admin-setup"><div><h2>Moje saldo urlopu</h2><p>Sprawdź dostępne godziny oraz historię rozliczeń.</p></div><Link href="/balances" className="button button-primary" prefetch={false}>Przejdź do salda</Link></section>
      <section className="admin-setup"><div><h2>Plany Twojego zespołu</h2><p>Sprawdź nadchodzące nieobecności lub złóż nowy wniosek.</p></div><Link href="/" className="button button-secondary" prefetch={false}>Przejdź do kalendarza</Link></section>
  </AppShell>;
}

function LoginForm({ notice, initialError }: { notice: string; initialError: string }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setError("");
    try {
      const { user } = await postApi<{ user: CurrentUser }>("/api/auth/login", { email: email.trim(), password });
      setPassword("");
      window.location.replace(user.mustChangePassword ? "/change-password" : "/");
    } catch (error) {
      setPassword("");
      setError(errorMessage(error));
      setBusy(false);
    }
  }

  return <>
    <span className="eyebrow">Witaj ponownie</span><h2>Zaloguj się</h2><p className="card-description">Użyj służbowego adresu e-mail i hasła do aplikacji.</p>
    {notice && <Message kind="success">{notice}</Message>}
    {(error || initialError) && <Message>{error || initialError}</Message>}
    <form className="auth-form" onSubmit={event => void submit(event)} aria-busy={busy}>
      <div className="field"><label htmlFor="email">Służbowy adres e-mail</label><input id="email" name="email" type="email" autoComplete="username" value={email} onChange={event => setEmail(event.target.value)} required maxLength={254} disabled={busy} /></div>
      <PasswordField id="password" label="Hasło" value={password} onChange={setPassword} disabled={busy} />
      <button className="button button-primary button-wide" disabled={busy} type="submit">{busy ? "Logowanie…" : "Zaloguj się"}<span aria-hidden="true">→</span></button>
    </form>
    <div className="help-box"><strong>Pierwsze logowanie lub brak hasła?</strong><p>Konto i hasło tymczasowe otrzymasz od administratora. Przy pierwszym logowaniu ustawisz własne hasło.</p></div>
  </>;
}

function PasswordChangeForm({ user }: { user: CurrentUser }) {
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    const length = Array.from(newPassword).length;
    if (length < 15 || length > 128) { setError("Nowe hasło musi mieć od 15 do 128 znaków."); return; }
    if (newPassword !== confirmation) { setError("Nowe hasło i jego powtórzenie muszą być takie same."); return; }
    if (newPassword === currentPassword) { setError("Nowe hasło musi różnić się od aktualnego."); return; }
    setBusy(true);
    setError("");
    try {
      await postApi("/api/auth/change-password", { currentPassword, newPassword });
      setCurrentPassword(""); setNewPassword(""); setConfirmation("");
      window.location.replace("/login?changed=1");
    } catch (error) {
      setCurrentPassword(""); setNewPassword(""); setConfirmation("");
      if (!redirectForAuthError(error)) setError(errorMessage(error));
      setBusy(false);
    }
  }

  return <>
    <span className="eyebrow">Bezpieczeństwo konta</span><h2>{user.mustChangePassword ? "Ustaw własne hasło" : "Zmień hasło"}</h2>
    <p className="card-description">{user.mustChangePassword ? "Zanim przejdziesz dalej, zastąp hasło tymczasowe własnym." : "Wybierz nowe hasło do swojego konta."}</p>
    <p className="account-email">{user.email}</p>
    {error && <Message>{error}</Message>}
    <form className="auth-form" onSubmit={event => void submit(event)} aria-busy={busy}>
      <PasswordField id="current-password" label={user.mustChangePassword ? "Hasło tymczasowe" : "Aktualne hasło"} value={currentPassword} onChange={setCurrentPassword} disabled={busy} />
      <PasswordField id="new-password" label="Nowe hasło" value={newPassword} onChange={setNewPassword} autoComplete="new-password" hint="Od 15 do 128 znaków. Możesz użyć długiej frazy ze spacjami." disabled={busy} />
      <PasswordField id="confirm-password" label="Powtórz nowe hasło" value={confirmation} onChange={setConfirmation} autoComplete="new-password" disabled={busy} />
      <p className="field-hint">Zmiana hasła wyloguje Cię ze wszystkich sesji. Następnie zalogujesz się nowym hasłem.</p>
      <button className="button button-primary button-wide" type="submit" disabled={busy}>{busy ? "Zapisywanie…" : "Zapisz nowe hasło"}<span aria-hidden="true">→</span></button>
    </form>
  </>;
}
