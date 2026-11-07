"use client";

import Link from "next/link";
import { AdminHistoryPanel } from "./admin-history-panel";
import { AppHeader } from "./app-shell";
import { useCallback, useEffect, useRef, useState, type FormEvent, type ReactNode } from "react";
import { ApiError, errorMessage, getApi, getCurrentUser, mutateApi, postApi, redirectForAuthError, roleLabel, type CurrentUser, type TeamRole } from "@/lib/auth-api";
import { displayDate, warsawToday, type AbsenceType, type AdminUser, type Catalog, type DictionaryItem, type EmploymentType, type TemporaryCredential } from "@/lib/admin-api";

type CatalogSection = "users" | "teams" | "employment-types" | "absence-types";
type Section = CatalogSection | "history";
type Editor = { kind: CatalogSection; id: number | null; mode?: "profile" | "assignment" | "password" };
type Save = (path: string, method: "POST" | "PATCH", data: Record<string, unknown>, message: string, recipient?: string) => Promise<boolean>;
const sections: { value: Section; label: string }[] = [
  { value: "users", label: "Użytkownicy" }, { value: "teams", label: "Zespoły" },
  { value: "employment-types", label: "Formy zatrudnienia" }, { value: "absence-types", label: "Rodzaje nieobecności" },
  { value: "history", label: "Historia" },
];
const teamRoles: Record<TeamRole, string> = { employee: "Pracownik", manager: "Kierownik", deputy: "Zastępca" };

function Notice({ children, error = false }: { children: ReactNode; error?: boolean }) {
  return <div className={`message message-${error ? "error" : "success"}`} role={error ? "alert" : "status"}>{children}</div>;
}
function Field({ id, label, value, onChange, type = "text", hint, required = true, maxLength = 255, pattern }: {
  id: string; label: string; value: string; onChange: (v: string) => void; type?: string; hint?: string; required?: boolean; maxLength?: number; pattern?: string;
}) {
  return <div className="field"><label htmlFor={id}>{label}</label><input id={id} name={id} type={type} value={value} onChange={e => onChange(e.target.value)} required={required} maxLength={maxLength} pattern={pattern} autoComplete={type === "password" ? "current-password" : "off"} aria-describedby={hint ? `${id}-hint` : undefined} />{hint && <p id={`${id}-hint`} className="field-hint">{hint}</p>}</div>;
}
function Check({ id, children, checked, onChange, disabled = false }: { id: string; children: ReactNode; checked: boolean; onChange: (v: boolean) => void; disabled?: boolean }) {
  return <label className="check-field" htmlFor={id}><input id={id} type="checkbox" checked={checked} onChange={e => onChange(e.target.checked)} disabled={disabled} /><span>{children}</span></label>;
}
function Select({ id, label, value, onChange, children }: { id: string; label: string; value: string; onChange: (v: string) => void; children: ReactNode }) {
  return <div className="field"><label htmlFor={id}>{label}</label><select id={id} value={value} onChange={e => onChange(e.target.value)} required>{children}</select></div>;
}
function Status({ active }: { active: boolean }) {
  return <span className={`admin-status ${active ? "is-active" : "is-inactive"}`}>{active ? "Aktywne" : "Nieaktywne"}</span>;
}

export function AdminScreen() {
  const [actor, setActor] = useState<CurrentUser | null>(null);
  const [catalog, setCatalog] = useState<Catalog | null>(null);
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [section, setSection] = useState<Section>("users");
  const [editor, setEditor] = useState<Editor | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [forbidden, setForbidden] = useState(false);
  const [query, setQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [teamFilter, setTeamFilter] = useState("");
  const [employmentFilter, setEmploymentFilter] = useState("");
  const [temporary, setTemporary] = useState<(TemporaryCredential & { recipient: string }) | null>(null);
  const pending = useRef(false);
  const lifetime = useRef(0);
  const editorHeading = useRef<HTMLHeadingElement>(null);

  const clearPrivateData = useCallback(() => {
    ++lifetime.current;
    setActor(null); setCatalog(null); setUsers([]); setEditor(null); setTemporary(null); setLoading(false);
  }, []);

  const handleError = useCallback((failure: unknown) => {
    if (failure instanceof ApiError && (failure.status === 401 || failure.code === "PASSWORD_CHANGE_REQUIRED" || failure.status === 403 && failure.code === "FORBIDDEN")) {
      clearPrivateData();
      if (!redirectForAuthError(failure)) setForbidden(true);
    } else setError(errorMessage(failure));
  }, [clearPrivateData]);

  const loadData = useCallback(async () => {
    const [catalogResult, userResult] = await Promise.all([getApi<Catalog>("/api/admin/catalog"), getApi<{ users: AdminUser[] }>("/api/admin/users")]);
    return { catalog: catalogResult, users: userResult.users };
  }, []);

  useEffect(() => {
    const generation = ++lifetime.current;
    let checking = false;
    async function checkSession(initial = false) {
      if (checking) return;
      checking = true;
      try {
        const { user } = await getCurrentUser();
        if (lifetime.current !== generation) return;
        if (user.mustChangePassword) { clearPrivateData(); window.location.replace("/change-password"); return; }
        if (!user.isAdministrator) { clearPrivateData(); setForbidden(true); return; }
        setActor(user);
        if (initial) {
          const result = await loadData();
          if (lifetime.current !== generation) return;
          setCatalog(result.catalog); setUsers(result.users);
        }
      } catch (failure) { if (lifetime.current === generation) handleError(failure); }
      finally { checking = false; if (lifetime.current === generation) setLoading(false); }
    }
    void checkSession(true);
    const onFocus = () => { void checkSession(); };
    const onPageShow = (event: PageTransitionEvent) => { if (event.persisted) { setTemporary(null); void checkSession(true); } };
    const onHidden = () => { if (document.visibilityState === "hidden") setTemporary(null); };
    window.addEventListener("focus", onFocus);
    window.addEventListener("pageshow", onPageShow);
    document.addEventListener("visibilitychange", onHidden);
    return () => { lifetime.current = generation + 1; window.removeEventListener("focus", onFocus); window.removeEventListener("pageshow", onPageShow); document.removeEventListener("visibilitychange", onHidden); };
  }, [clearPrivateData, handleError, loadData]);

  useEffect(() => { if (editor) editorHeading.current?.focus(); }, [editor]);

  const save: Save = async (path, method, data, message, recipient) => {
    if (pending.current) return false;
    pending.current = true; setBusy(true); setError(""); setNotice(""); setTemporary(null);
    const generation = lifetime.current;
    let committed = false;
    try {
      const result = await mutateApi<Partial<TemporaryCredential>>(path, method, data);
      committed = true;
      if (generation !== lifetime.current) return true;
      setEditor(null); setNotice(message);
      if (result.temporaryPassword && result.expiresAt) setTemporary({ temporaryPassword: result.temporaryPassword, expiresAt: result.expiresAt, recipient: recipient ?? "Nowe konto" });
      const refreshed = await loadData();
      if (generation === lifetime.current) { setCatalog(refreshed.catalog); setUsers(refreshed.users); }
      return true;
    } catch (failure) {
      if (generation === lifetime.current) {
        handleError(failure);
        if (committed) setNotice(`${message} Odśwież listę, aby zobaczyć aktualne dane. Nie powtarzaj zapisu.`);
      }
      return committed;
    } finally { pending.current = false; if (generation === lifetime.current) setBusy(false); }
  };

  async function refresh() {
    if (pending.current) return;
    pending.current = true; setBusy(true); setError(""); setEditor(null); setTemporary(null);
    const generation = lifetime.current;
    try {
      const result = await loadData();
      if (generation === lifetime.current) { setCatalog(result.catalog); setUsers(result.users); }
    } catch (failure) { if (generation === lifetime.current) handleError(failure); }
    finally { pending.current = false; if (generation === lifetime.current) setBusy(false); }
  }

  async function logout() {
    if (pending.current) return;
    pending.current = true; setBusy(true); setTemporary(null);
    try { await postApi("/api/auth/logout"); clearPrivateData(); window.location.replace("/login"); }
    catch (failure) { handleError(failure); pending.current = false; setBusy(false); }
  }

  function openEditor(next: Editor) {
    if (pending.current) return;
    setEditor(next); setTemporary(null); setError(""); setNotice("");
  }
  function changeSection(next: Section) {
    setSection(next); setEditor(null); setTemporary(null); setError(""); setNotice(""); setQuery(""); setStatusFilter("all"); setTeamFilter(""); setEmploymentFilter("");
  }

  const selectedUser = editor?.kind === "users" && editor.id !== null ? users.find(user => user.id === editor.id) : undefined;
  const filtered = (item: { active: boolean }, text: string) => (statusFilter === "all" || item.active === (statusFilter === "active")) && text.toLocaleLowerCase("pl").includes(query.toLocaleLowerCase("pl"));
  const filteredUsers = users.filter(user => filtered(user, `${user.firstName} ${user.lastName} ${user.email}`) && (!teamFilter || String(user.team?.id) === teamFilter));
  const dictionaries = !catalog ? [] : section === "teams" ? catalog.teams : section === "employment-types" ? catalog.employmentTypes : catalog.absenceTypes;
  const filteredDictionaries = dictionaries.filter(item => filtered(item, `${item.name} ${item.code}`) && (!employmentFilter || section !== "absence-types" || (item as AbsenceType).employmentTypeIds.includes(Number(employmentFilter))));

  return <div className="workspace admin-workspace">
    <a className="skip-link" href="#main">Przejdź do treści</a>
    <AppHeader user={actor} active="admin" onLogout={() => void logout()} logoutBusy={busy} />
    <main id="main" className="workspace-main admin-main">
      <div className="workspace-heading"><div><span className="eyebrow">Ustawienia aplikacji</span><h1>Administracja</h1><p>Użytkownicy, struktura zespołów i rodzaje nieobecności.</p></div>{actor && <span className="account-badge">Administrator</span>}</div>
      {loading ? <p role="status">Wczytywanie administracji…</p> : forbidden ? <section className="profile-card"><h2>Brak dostępu</h2><p>Ten obszar jest dostępny wyłącznie dla administratora.</p><Link href="/" prefetch={false}>Wróć do konta</Link></section> : <>
        {error && <Notice error>{error}{actor && <div className="notice-actions"><button className="text-button" disabled={busy} onClick={() => void refresh()}>Wczytaj aktualne dane</button></div>}</Notice>}
        {notice && <Notice>{notice}</Notice>}
        {!catalog && !error && <Notice error>Nie udało się wczytać danych.</Notice>}
        {catalog && actor && <>
          <nav className="admin-nav" aria-label="Obszary administracji">{sections.map(item => <button key={item.value} className={section === item.value ? "is-selected" : ""} aria-current={section === item.value ? "page" : undefined} disabled={busy} onClick={() => changeSection(item.value)}>{item.label}</button>)}</nav>
          {section === "history" ? <AdminHistoryPanel onAccessError={handleError} /> : <>
          {temporary && <TemporaryPassword key={`${temporary.recipient}-${temporary.expiresAt}`} credential={temporary} onClose={() => setTemporary(null)} />}
          {catalog.employmentTypes.length === 0 || catalog.absenceTypes.length === 0 ? <section className="admin-setup"><div><h2>Przygotuj słowniki startowe</h2><p>Dodaj UoP, B2B oraz trzy podstawowe rodzaje nieobecności. Istniejące pozycje pozostaną zachowane.</p></div><button className="button button-secondary" disabled={busy} onClick={() => void save("/api/admin/catalog/initialize", "POST", {}, "Słowniki startowe są gotowe.")}>Dodaj słowniki startowe</button></section> : null}
          <section aria-labelledby="list-heading">
            <div className="admin-section-heading"><h2 id="list-heading">{sections.find(item => item.value === section)?.label}</h2><div className="admin-actions"><button className="button button-secondary" disabled={busy} onClick={() => void refresh()}>Odśwież</button><button className="button button-primary" disabled={busy} onClick={() => openEditor({ kind: section, id: null })}>{section === "users" ? "Dodaj użytkownika" : section === "teams" ? "Dodaj zespół" : section === "employment-types" ? "Dodaj formę zatrudnienia" : "Dodaj rodzaj nieobecności"}</button></div></div>
            <div className="admin-filters"><Field id="admin-search" label="Szukaj" value={query} onChange={setQuery} required={false} maxLength={255} /><Select id="admin-status" label="Aktywność" value={statusFilter} onChange={setStatusFilter}><option value="all">Wszystkie</option><option value="active">Aktywne</option><option value="inactive">Nieaktywne</option></Select>{section === "users" && <Select id="admin-team-filter" label="Zespół" value={teamFilter} onChange={setTeamFilter}><option value="">Wszystkie zespoły</option>{catalog.teams.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</Select>}{section === "absence-types" && <Select id="admin-employment-filter" label="Forma zatrudnienia" value={employmentFilter} onChange={setEmploymentFilter}><option value="">Wszystkie formy</option>{catalog.employmentTypes.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</Select>}</div>
            <p className="admin-count" aria-live="polite">Wyniki: {section === "users" ? filteredUsers.length : filteredDictionaries.length}</p>
            {section === "users" ? <ul className="admin-list">{filteredUsers.map(user => <li key={user.id}><div className="admin-item-main"><strong>{user.firstName} {user.lastName}{user.id === actor.id && <span className="you-label">Ty</span>}</strong><span>{user.email}</span><small>{user.team?.name ?? "Bez bieżącego zespołu"} · {roleLabel(user)} · {user.employmentType?.name ?? "Bez formy zatrudnienia"}</small></div><div className="admin-item-actions"><Status active={user.active} /><button className="button button-secondary button-small" disabled={busy} aria-label={`Edytuj: ${user.firstName} ${user.lastName}`} onClick={() => openEditor({ kind: "users", id: user.id, mode: "profile" })}>Edytuj i zobacz historię</button></div></li>)}</ul> : <ul className="admin-list">{filteredDictionaries.map(item => <li key={item.id}><div className="admin-item-main"><strong>{item.name}</strong><span>Kod: {item.code}</span>{section === "employment-types" && <small>{(item as EmploymentType).carriesUnusedHours ? "Niewykorzystane godziny przechodzą na następny rok" : "Niewykorzystane godziny nie przechodzą"}</small>}{section === "absence-types" && <><small>{(item as AbsenceType).deductsBalance ? "Pomniejsza saldo" : "Bez wpływu na saldo"} · Wymaga akceptacji</small><small>Dostępność: {catalog.employmentTypes.filter(type => (item as AbsenceType).employmentTypeIds.includes(type.id)).map(type => type.name).join(", ") || "Brak"}</small></>}</div><div className="admin-item-actions"><Status active={item.active} /><button className="button button-secondary button-small" disabled={busy} aria-label={`Edytuj: ${item.name}`} onClick={() => openEditor({ kind: section, id: item.id })}>Edytuj</button></div></li>)}</ul>}
            {(section === "users" ? filteredUsers.length : filteredDictionaries.length) === 0 && <div className="admin-empty"><p>Brak pozycji spełniających wybrane kryteria.</p><p>Zmień filtry lub dodaj nową pozycję.</p></div>}
          </section>
          {editor && <section className="admin-editor" aria-labelledby="editor-heading"><div className="reset-heading"><h2 id="editor-heading" ref={editorHeading} tabIndex={-1}>{editor.kind === "users" ? editor.id === null ? "Nowy użytkownik" : `${selectedUser?.firstName ?? ""} ${selectedUser?.lastName ?? ""}` : editor.id === null ? "Nowa pozycja słownika" : "Edycja pozycji słownika"}</h2><button className="button button-secondary button-small" disabled={busy} onClick={() => setEditor(null)}>Zamknij edycję</button></div>
            {editor.kind === "users" ? <UserEditor key={`${editor.id}-${selectedUser?.version}-${editor.mode}`} user={selectedUser} actor={actor} catalog={catalog} mode={editor.mode ?? "profile"} busy={busy} save={save} onMode={mode => openEditor({ ...editor, mode })} /> : <DictionaryEditor key={`${editor.kind}-${editor.id}`} kind={editor.kind} item={dictionaries.find(item => item.id === editor.id)} catalog={catalog} busy={busy} save={save} />}
          </section>}
          </>}
        </>}
      </>}
    </main>
    <footer className="workspace-footer">Urlopy zespołu<span>Administracja</span></footer>
  </div>;
}

function TemporaryPassword({ credential, onClose }: { credential: TemporaryCredential & { recipient: string }; onClose: () => void }) {
  const [visible, setVisible] = useState(false);
  const [copyStatus, setCopyStatus] = useState("");
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => { heading.current?.focus(); }, []);
  async function copy() {
    try { await navigator.clipboard.writeText(credential.temporaryPassword); setCopyStatus("Skopiowano hasło."); }
    catch { setCopyStatus("Nie udało się skopiować. Zaznacz hasło i skopiuj je ręcznie."); }
  }
  return <section className="reset-panel temporary-result" aria-labelledby="temporary-title"><div className="reset-heading"><h2 id="temporary-title" ref={heading} tabIndex={-1}>Hasło tymczasowe gotowe</h2><button className="button button-secondary button-small" onClick={onClose}>Zamknij hasło</button></div><p><strong>{credential.recipient}</strong></p><p>Przekaż je użytkownikowi. Po zamknięciu lub opuszczeniu tej karty nie będzie ponownie widoczne. W razie potrzeby wygeneruj nowe.</p><label htmlFor="admin-temporary-password">Nowe hasło tymczasowe</label><div className="temporary-copy"><input id="admin-temporary-password" type={visible ? "text" : "password"} value={credential.temporaryPassword} readOnly autoComplete="off" /><button className="button button-secondary" onClick={() => setVisible(!visible)} aria-pressed={visible}>{visible ? "Ukryj hasło" : "Pokaż hasło"}</button><button className="button button-secondary" onClick={() => void copy()}>Kopiuj hasło</button></div><p>Ważne do: {new Intl.DateTimeFormat("pl-PL", { timeZone: "Europe/Warsaw", dateStyle: "short", timeStyle: "short" }).format(new Date(credential.expiresAt))} (czas Warszawy). Użytkownik musi zmienić hasło przy pierwszym logowaniu.</p>{copyStatus && <p role="status">{copyStatus}</p>}</section>;
}

function AssignmentFields({ catalog, team, setTeam, employment, setEmployment, role, setRole, date, setDate }: {
  catalog: Catalog; team: string; setTeam: (v: string) => void; employment: string; setEmployment: (v: string) => void; role: string; setRole: (v: string) => void; date: string; setDate: (v: string) => void;
}) {
  return <><Select id="user-team" label="Zespół pracownika" value={team} onChange={setTeam}><option value="">Wybierz zespół</option>{catalog.teams.filter(item => item.active).map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</Select><Select id="user-employment" label="Forma zatrudnienia pracownika" value={employment} onChange={setEmployment}><option value="">Wybierz formę zatrudnienia</option>{catalog.employmentTypes.filter(item => item.active).map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</Select><Select id="user-role" label="Rola w zespole" value={role} onChange={setRole}>{catalog.roles.map(item => <option key={item.value} value={item.value}>{item.label}</option>)}</Select><Field id="user-valid-from" label="Obowiązuje od" type="date" value={date} onChange={setDate} /></>;
}

function UserEditor({ user, actor, catalog, mode, busy, save, onMode }: { user?: AdminUser; actor: CurrentUser; catalog: Catalog; mode: "profile" | "assignment" | "password"; busy: boolean; save: Save; onMode: (mode: "profile" | "assignment" | "password") => void }) {
  const [firstName, setFirstName] = useState(user?.firstName ?? "");
  const [lastName, setLastName] = useState(user?.lastName ?? "");
  const [email, setEmail] = useState(user?.email ?? "");
  const [active, setActive] = useState(user?.active ?? true);
  const [administrator, setAdministrator] = useState(user?.isAdministrator ?? false);
  const [team, setTeam] = useState(user?.team ? String(user.team.id) : "");
  const [employment, setEmployment] = useState(user?.employmentType ? String(user.employmentType.id) : "");
  const [role, setRole] = useState<string>(user?.role ?? "employee");
  const [date, setDate] = useState(warsawToday);
  const [password, setPassword] = useState("");
  const [confirmed, setConfirmed] = useState(false);
  const hasRefs = catalog.teams.some(item => item.active) && catalog.employmentTypes.some(item => item.active);

  useEffect(() => {
    const clear = () => { if (document.visibilityState === "hidden") setPassword(""); };
    document.addEventListener("visibilitychange", clear);
    return () => document.removeEventListener("visibilitychange", clear);
  }, []);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (busy) return;
    const adminPassword = password;
    setPassword("");
    const name = `${firstName.trim()} ${lastName.trim()}`;
    if (!user) {
      await save("/api/admin/users", "POST", { firstName: firstName.trim(), lastName: lastName.trim(), email: email.trim(), isAdministrator: administrator, teamId: Number(team), employmentTypeId: Number(employment), role, validFrom: date, adminPassword }, "Utworzono konto użytkownika.", name);
    } else if (mode === "assignment") {
      await save(`/api/admin/users/${user.id}/assignments`, "POST", { version: user.version, teamId: Number(team), employmentTypeId: Number(employment), role, validFrom: date, adminPassword }, "Zapisano nowe przypisanie. Historia i rozliczenia zostały zachowane.");
    } else if (mode === "password") {
      await save(`/api/admin/users/${user.id}/reset-password`, "POST", { adminPassword }, "Wygenerowano nowe hasło tymczasowe.", `${user.firstName} ${user.lastName}`);
    } else {
      await save(`/api/admin/users/${user.id}`, "PATCH", { version: user.version, firstName: firstName.trim(), lastName: lastName.trim(), email: email.trim(), active, isAdministrator: administrator, adminPassword }, "Zapisano zmiany konta.");
    }
  }

  return <>
    {user && <nav className="admin-subnav" aria-label="Edycja użytkownika"><button className={mode === "profile" ? "is-selected" : ""} aria-current={mode === "profile" ? "page" : undefined} disabled={busy} onClick={() => onMode("profile")}>Dane i aktywność</button><button className={mode === "assignment" ? "is-selected" : ""} aria-current={mode === "assignment" ? "page" : undefined} disabled={busy} onClick={() => onMode("assignment")}>Zmień przypisanie</button>{user.id !== actor.id && user.active && <button className={mode === "password" ? "is-selected" : ""} aria-current={mode === "password" ? "page" : undefined} disabled={busy} onClick={() => onMode("password")}>Reset hasła</button>}</nav>}
    {(!user || mode === "assignment") && !hasRefs && <div className="message message-info" role="status">Najpierw dodaj aktywny zespół i formę zatrudnienia w odpowiednich zakładkach.</div>}
    <form onSubmit={event => void submit(event)} aria-busy={busy}>
      <fieldset disabled={busy} className="admin-fieldset">
        {mode === "profile" && <div className="admin-form-grid"><Field id="user-first-name" label="Imię" value={firstName} onChange={setFirstName} /><Field id="user-last-name" label="Nazwisko" value={lastName} onChange={setLastName} /><Field id="user-email" label="Służbowy adres e-mail" type="email" value={email} onChange={setEmail} maxLength={254} /><div className="admin-checks">{user && <Check id="user-active" checked={active} onChange={setActive}>Konto aktywne — dostęp do logowania</Check>}<Check id="user-administrator" checked={administrator} onChange={setAdministrator}>Administrator — dostęp do wszystkich zespołów</Check></div></div>}
        {(!user || mode === "assignment") && <><div className="admin-form-grid"><AssignmentFields catalog={catalog} team={team} setTeam={setTeam} employment={employment} setEmployment={setEmployment} role={role} setRole={setRole} date={date} setDate={setDate} /></div>{user && <p className="field-hint">Nowe przypisanie obowiązuje od wskazanego dnia. Poprzednie dane zostaną zachowane. Jeśli ostatnie przypisanie zostało użyte we wnioskach, wybierz datę późniejszą od jego początku. Zmiana uprawnień zakończy dotychczasowe sesje użytkownika.</p>}</>}
        {mode === "password" && <p>Nowe hasło zastąpi obecne i zakończy dotychczasowe sesje użytkownika. Hasło tymczasowe będzie ważne przez 24 godziny i wymaga zmiany przy pierwszym logowaniu.</p>}
        {user && mode === "profile" && (!active || administrator !== user.isAdministrator) && <div className="message message-info"><p>{!active ? "Wyłączenie konta odbierze dostęp do aplikacji. Historia pozostanie zachowana." : "Zmiana uprawnień administratora wpłynie na dostęp do wszystkich zespołów."}{user.id === actor.id ? " Zmieniasz własne konto; możesz utracić dostęp do tej strony." : ""}</p><Check id="user-confirm-access" checked={confirmed} onChange={setConfirmed}>Potwierdzam zmianę dostępu do konta</Check></div>}
        <div className="admin-confirm-password"><Field id="user-admin-password" label="Twoje hasło administratora" type="password" value={password} onChange={setPassword} maxLength={1024} hint="Potwierdź zapis obecnym hasłem swojego konta." /></div>
        <button className="button button-primary" disabled={busy || ((!user || mode === "assignment") && !hasRefs) || Boolean(user && mode === "profile" && (!active || administrator !== user.isAdministrator) && !confirmed)} type="submit">{busy ? "Zapisywanie…" : !user ? "Utwórz konto i hasło" : mode === "assignment" ? "Zapisz przypisanie" : mode === "password" ? "Utwórz nowe hasło tymczasowe" : "Zapisz dane użytkownika"}</button>
      </fieldset>
    </form>
    {user && <section className="assignment-history" aria-labelledby="assignment-history-title"><h3 id="assignment-history-title">Historia przypisań</h3>{user.assignments.length === 0 ? <p>Brak przypisań. Możesz dodać pierwsze przypisanie w zakładce „Zmień przypisanie”.</p> : <ol>{[...user.assignments].sort((a, b) => b.validFrom.localeCompare(a.validFrom)).map(assignment => <li key={assignment.id}><div><strong>{assignment.team.name}</strong><span>{assignment.employmentType.name} · {teamRoles[assignment.role]}</span></div><p>Od {displayDate(assignment.validFrom)}{assignment.validUntil ? <> do {displayDate(assignment.validUntil)} <small>(bez tego dnia)</small></> : " — bez daty końcowej"}</p></li>)}</ol>}<p className="field-hint">Po zmianie zespołu historia pozostaje dostępna według dat nieobecności. Wniosek obejmujący oba zespoły obsługuje administrator. Zmiana przypisania nie przelicza już złożonych wniosków.</p></section>}
  </>;
}

function DictionaryEditor({ kind, item, catalog, busy, save }: { kind: Exclude<Section, "users">; item?: DictionaryItem; catalog: Catalog; busy: boolean; save: Save }) {
  const absence = kind === "absence-types" ? item as AbsenceType | undefined : undefined;
  const [code, setCode] = useState(item?.code ?? "");
  const [name, setName] = useState(item?.name ?? "");
  const [active, setActive] = useState(item?.active ?? true);
  const [deductsBalance, setDeductsBalance] = useState(absence?.deductsBalance ?? false);
  const [employmentIds, setEmploymentIds] = useState<number[]>(absence?.employmentTypeIds ?? []);
  const [names, setNames] = useState<Record<string, string>>(absence?.namesByEmploymentType ?? {});
  const [localError, setLocalError] = useState("");

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (busy) return;
    if (kind === "absence-types" && employmentIds.length === 0) { setLocalError("Wybierz co najmniej jedną formę zatrudnienia."); return; }
    setLocalError("");
    const data: Record<string, unknown> = { name: name.trim(), active };
    if (item) data.version = item.version; else data.code = code.trim();
    if (kind === "employment-types" && !item) data.carriesUnusedHours = code.trim() === "uop";
    if (kind === "absence-types") {
      data.deductsBalance = deductsBalance; data.employmentTypeIds = employmentIds;
      data.namesByEmploymentType = Object.fromEntries(employmentIds.map(id => [String(id), (names[id] ?? "").trim()]).filter(([, value]) => value !== ""));
    }
    await save(`/api/admin/${kind}${item ? `/${item.id}` : ""}`, item ? "PATCH" : "POST", data, item ? "Zapisano zmiany słownika." : "Dodano pozycję słownika.");
  }

  return <form onSubmit={event => void submit(event)} aria-busy={busy}>
    {localError && <Notice error>{localError}</Notice>}
    <fieldset className="admin-fieldset" disabled={busy}>
      <div className="admin-form-grid"><div>{item ? <div className="field"><label htmlFor="dictionary-code">Kod</label><input id="dictionary-code" value={item.code} readOnly /><p className="field-hint">Kod pozostaje niezmienny.</p></div> : <Field id="dictionary-code" label="Kod" value={code} onChange={setCode} maxLength={64} pattern="[a-z][a-z0-9_]{0,63}" hint="Małe litery bez polskich znaków, cyfry i podkreślenia. Pierwszy znak: litera." />}</div><Field id="dictionary-name" label="Nazwa" value={name} onChange={setName} /><div className="admin-checks"><Check id="dictionary-active" checked={active} onChange={setActive}>Pozycja aktywna</Check>{kind === "absence-types" && <Check id="dictionary-deducts" checked={deductsBalance} onChange={setDeductsBalance}>Pomniejsza wspólne saldo płatnych dni</Check>}</div></div>
      {kind === "employment-types" && <p className="message message-info">{(item ? (item as EmploymentType).carriesUnusedHours : code.trim() === "uop") ? "UoP: niewykorzystane godziny przechodzą na kolejne lata." : "Dla tej formy niewykorzystane godziny nie przechodzą na kolejny rok."} Zasada jest ustalana przy tworzeniu i pozostaje niezmienna. Dla umowy o pracę użyj kodu „uop”.</p>}
      {kind === "absence-types" && <fieldset className="absence-availability"><legend>Dostępność i nazwy dla form zatrudnienia</legend><p className="field-hint">Wybierz co najmniej jedną formę. Opcjonalna nazwa zastępuje nazwę główną dla danej formy.</p>{catalog.employmentTypes.length === 0 && <p>Najpierw dodaj formy zatrudnienia.</p>}{catalog.employmentTypes.map(type => <div key={type.id} className="availability-row"><Check id={`available-${type.id}`} checked={employmentIds.includes(type.id)} disabled={!type.active && !employmentIds.includes(type.id)} onChange={checked => setEmploymentIds(ids => checked ? [...ids, type.id] : ids.filter(id => id !== type.id))}>{type.name}{!type.active && " (nieaktywna)"}</Check>{employmentIds.includes(type.id) && <Field id={`name-${type.id}`} label={`Nazwa dla: ${type.name}`} value={names[type.id] ?? ""} onChange={value => setNames({ ...names, [type.id]: value })} required={false} hint="Puste pole oznacza nazwę główną." />}</div>)}</fieldset>}
      <p className="field-hint">{kind === "absence-types" ? "Wszystkie rodzaje wymagają akceptacji. Zmiana zasad dotyczy nowych wniosków; istniejące zachowują poprzednią wersję." : "Dezaktywacja ukrywa pozycję przy nowych przypisaniach, zachowując historię."}</p>
      <button type="submit" className="button button-primary" disabled={busy}>{busy ? "Zapisywanie…" : item ? "Zapisz pozycję" : "Dodaj pozycję"}</button>
    </fieldset>
  </form>;
}
