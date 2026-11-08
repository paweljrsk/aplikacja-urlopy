"use client";

import { usePathname, useRouter } from "next/navigation";
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from "react";
import { getCurrentUser, redirectForAuthError, type CurrentUser } from "@/lib/auth-api";
import { guideOptions, guideSteps, type GuideKind } from "@/lib/tour-steps";
import "./guided-tour.css";

type Session = { user: CurrentUser; kind: GuideKind | null; index: number };
type TourContext = { open: (user: CurrentUser) => void; busy: boolean };
const GuideContext = createContext<TourContext | null>(null);
const scope = (user: CurrentUser) => `${user.id}:${user.isAdministrator}:${user.role}:${user.team?.id}:${user.mustChangePassword}`;

export function GuideLauncher({ user, label = "Przewodnik" }: { user: CurrentUser | null; label?: string }) {
  const context = useContext(GuideContext);
  if (!user || !context || user.mustChangePassword) return null;
  return <button type="button" className="guide-launcher" data-guide-launcher="true" aria-haspopup="dialog" disabled={context.busy} onClick={() => context.open(user)}><span aria-hidden="true">?</span>{label}</button>;
}

export function GuidedTourProvider({ children }: { children: ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [session, setSession] = useState<Session | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState("");
  const sequence = useRef(0);
  const pending = useRef(false);
  const trigger = useRef<HTMLElement | null>(null);
  const expectedRoute = useRef<{ from: string; to: string } | null>(null);
  const noticeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const close = useCallback((message = "") => {
    ++sequence.current; pending.current = false; expectedRoute.current = null;
    setSession(null); setBusy(false); setNotice(message);
    if (noticeTimer.current) clearTimeout(noticeTimer.current);
    if (message) noticeTimer.current = setTimeout(() => setNotice(""), 7000);
    requestAnimationFrame(() => {
      const element = trigger.current?.isConnected ? trigger.current : document.querySelector<HTMLElement>("[data-guide-launcher]");
      element?.focus({ preventScroll: true });
    });
  }, []);

  useEffect(() => () => { ++sequence.current; if (noticeTimer.current) clearTimeout(noticeTimer.current); }, []);

  async function open(user: CurrentUser) {
    // An open editor owns focus and its draft. Do not start a second modal above it.
    if (pending.current || document.querySelector("dialog[open]")) return;
    pending.current = true; setBusy(true); setNotice("");
    trigger.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const startingUrl = window.location.href;
    const request = ++sequence.current;
    try {
      const fresh = (await getCurrentUser()).user;
      if (request !== sequence.current) return;
      // The user may have opened an editor or navigated while access was loading.
      if (window.location.href !== startingUrl || document.querySelector("dialog[open]")) return;
      if (fresh.id !== user.id || fresh.mustChangePassword) { close("Dostęp do konta zmienił się. Odśwież stronę przed uruchomieniem przewodnika."); return; }
      setSession({ user: fresh, kind: null, index: 0 });
    } catch (failure) {
      if (request !== sequence.current) return;
      close("Nie udało się uruchomić przewodnika. Spróbuj ponownie.");
      redirectForAuthError(failure);
    } finally { if (request === sequence.current) { pending.current = false; setBusy(false); } }
  }

  async function move(kind: GuideKind, index: number) {
    if (!session || pending.current) return;
    pending.current = true; setBusy(true);
    const request = ++sequence.current;
    try {
      const fresh = (await getCurrentUser()).user;
      if (request !== sequence.current) return;
      if (scope(fresh) !== scope(session.user) || !guideOptions(fresh).some(option => option.kind === kind)) {
        close("Twoje uprawnienia zmieniły się. Otwórz przewodnik ponownie."); return;
      }
      const step = guideSteps(kind)[index];
      if (!step) { close("Przewodnik ukończony. Możesz wrócić do niego w dowolnej chwili."); return; }
      const from = window.location.pathname;
      const supersedesNavigation = expectedRoute.current !== null;
      expectedRoute.current = from !== step.path ? { from, to: step.path } : null;
      setSession({ user: fresh, kind, index });
      if (from !== step.path || window.location.search || supersedesNavigation) router.push(step.path, { scroll: false });
    } catch (failure) {
      if (request !== sequence.current) return;
      close("Nie udało się potwierdzić dostępu. Otwórz przewodnik ponownie.");
      redirectForAuthError(failure);
    } finally { if (request === sequence.current) { pending.current = false; setBusy(false); } }
  }

  useEffect(() => {
    if (!session) return;
    let alive = true;
    const originalScope = scope(session.user);
    async function checkAccess() {
      try {
        const fresh = (await getCurrentUser()).user;
        if (alive && scope(fresh) !== originalScope) close("Twoje uprawnienia zmieniły się. Otwórz przewodnik ponownie.");
      } catch (failure) {
        if (alive) { close("Sesja lub dostęp do aplikacji zmieniły się. Przewodnik został zamknięty."); redirectForAuthError(failure); }
      }
    }
    const visibility = () => { if (document.visibilityState === "visible") void checkAccess(); };
    const leave = () => close();
    window.addEventListener("focus", checkAccess);
    window.addEventListener("popstate", leave);
    document.addEventListener("visibilitychange", visibility);
    return () => { alive = false; window.removeEventListener("focus", checkAccess); window.removeEventListener("popstate", leave); document.removeEventListener("visibilitychange", visibility); };
  }, [session, close]);

  useEffect(() => {
    if (!session?.kind) return;
    const destination = guideSteps(session.kind)[session.index].path;
    if (pathname === destination) { expectedRoute.current = null; return; }
    if (expectedRoute.current?.from === pathname && expectedRoute.current.to === destination) return;
    // Navigation initiated outside the guide must not leave an orphaned overlay.
    const frame = requestAnimationFrame(() => close());
    return () => cancelAnimationFrame(frame);
  }, [pathname, session, close]);

  return <GuideContext.Provider value={{ open, busy: busy || session !== null }}>
    {children}
    {notice && <div className="guide-notice" role="status">{notice}</div>}
    {session && <GuideDialog session={session} busy={busy} close={close} move={(kind, index) => void move(kind, index)} />}
  </GuideContext.Provider>;
}

type Rectangle = { top: number; left: number; width: number; height: number };
type Placement = { key: string; rect: Rectangle | null; card: CSSProperties; status: "waiting" | "ready" | "missing" };

function GuideDialog({ session, busy, close, move }: { session: Session; busy: boolean; close: (message?: string) => void; move: (kind: GuideKind, index: number) => void }) {
  const dialog = useRef<HTMLDialogElement>(null);
  const card = useRef<HTMLDivElement>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const [attempt, setAttempt] = useState(0);
  const steps = useMemo(() => session.kind ? guideSteps(session.kind) : [], [session.kind]);
  const step = steps[session.index];
  const key = `${session.kind}:${session.index}:${attempt}`;
  const [placement, setPlacement] = useState<Placement | null>(null);
  const current = placement?.key === key ? placement : null;
  const ready = current?.status === "ready";
  const waiting = Boolean(step) && (!current || current.status === "waiting");

  useEffect(() => {
    const element = dialog.current;
    if (!element) return;
    const overflow = document.body.style.overflow;
    element.showModal(); document.body.style.overflow = "hidden";
    function trap(event: KeyboardEvent) {
      if (event.key !== "Tab" || !element?.open) return;
      const controls = Array.from(element.querySelectorAll<HTMLElement>("button, [href], [tabindex]"))
        .filter(control => control.tabIndex >= 0 && !control.matches(":disabled") && control.getClientRects().length > 0);
      if (!controls.length) { event.preventDefault(); heading.current?.focus(); return; }
      const first = controls[0]; const last = controls[controls.length - 1];
      const active = document.activeElement;
      const outside = !controls.some(control => control === active);
      if (outside || (event.shiftKey && active === first) || (!event.shiftKey && active === last)) {
        event.preventDefault(); (event.shiftKey ? last : first).focus();
      }
    }
    document.addEventListener("keydown", trap, true);
    heading.current?.focus({ preventScroll: true });
    return () => { document.removeEventListener("keydown", trap, true); element.close(); document.body.style.overflow = overflow; };
  }, []);

  useEffect(() => {
    const frame = requestAnimationFrame(() => heading.current?.focus({ preventScroll: true }));
    return () => cancelAnimationFrame(frame);
  }, [key]);

  useEffect(() => {
    if (!step) return;
    let alive = true;
    let target: HTMLElement | null = null;
    let frame = 0;
    let observer: ResizeObserver | null = null;
    let interval: ReturnType<typeof setInterval> | null = null;
    const started = performance.now();
    function publish(rect: Rectangle | null, status: Placement["status"]) {
      if (!alive) return;
      const value: Placement = { key, rect, status, card: cardPosition(rect, card.current?.offsetHeight ?? 300) };
      setPlacement(previous => JSON.stringify(previous) === JSON.stringify(value) ? previous : value);
    }
    function measure() {
      if (!target) return;
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(() => {
        if (!alive) return;
        if (!target?.isConnected || window.location.pathname !== step.path) { publish(null, "missing"); return; }
        const bounds = target.getBoundingClientRect();
        const top = Math.max(8, bounds.top - 6);
        const left = Math.max(8, bounds.left - 6);
        const right = Math.min(window.innerWidth - 8, bounds.right + 6);
        const bottom = Math.min(window.innerHeight - 8, bounds.bottom + 6);
        publish(right > left && bottom > top ? { top, left, width: right - left, height: bottom - top } : null, "ready");
      });
    }
    function findTarget() {
      if (!alive) return;
      const found = window.location.pathname === step.path ? document.querySelector<HTMLElement>(`[data-tour="${step.target}"]`) : null;
      if (found && found.getClientRects().length > 0) {
        target = found;
        if (interval) clearInterval(interval);
        found.scrollIntoView({ block: "center", inline: "nearest", behavior: "instant" });
        observer = new ResizeObserver(measure);
        observer.observe(found);
        if (card.current) observer.observe(card.current);
        measure();
      } else if (performance.now() - started > 6500) {
        if (interval) clearInterval(interval);
        publish(null, "missing");
      }
    }
    frame = requestAnimationFrame(() => { publish(null, "waiting"); findTarget(); });
    interval = setInterval(findTarget, 100);
    window.addEventListener("resize", measure);
    window.addEventListener("scroll", measure, true);
    return () => { alive = false; cancelAnimationFrame(frame); if (interval) clearInterval(interval); observer?.disconnect(); window.removeEventListener("resize", measure); window.removeEventListener("scroll", measure, true); };
  }, [step, key]);

  const options = guideOptions(session.user);
  const guideLabel = options.find(option => option.kind === session.kind)?.label;
  return <dialog ref={dialog} className="guided-tour-layer" aria-label="Poznaj aplikację" aria-describedby="guide-description" data-guide-kind={session.kind ?? "chooser"} data-guide-step={session.kind ? session.index + 1 : undefined}
    onCancel={event => { event.preventDefault(); close(); }}>
    {ready && current.rect ? <div data-guide-highlight="true" className="guide-spotlight" aria-hidden="true" style={current.rect} /> : <div className="guide-scrim" aria-hidden="true" />}
    <div ref={card} className={`guide-card${session.kind ? " is-step" : " is-chooser"}`} style={session.kind ? current?.card : undefined}>
      <div className="guide-card-top"><span className="guide-eyebrow">{session.kind ? `Przewodnik · ${guideLabel}` : "Dobry początek"}</span><button type="button" className="guide-close" aria-label="Zamknij przewodnik" onClick={() => close()}>×</button></div>
      {session.kind && <div className="guide-progress" aria-label={`Krok ${session.index + 1} z ${steps.length}`}><span style={{ width: `${(session.index + 1) / steps.length * 100}%` }} /></div>}
      <h2 id="guide-title" ref={heading} tabIndex={-1}>{step?.title ?? "Poznaj aplikację"}</h2>
      <p id="guide-description">{step?.body ?? "Krótka wycieczka po ekranach, których potrzebujesz. Wybierz swoją ścieżkę."}</p>
      {step ? <>
        {step.tip && <p className="guide-tip"><span aria-hidden="true">✦</span>{step.tip}</p>}
        {waiting && <p className="guide-state" role="status">Przechodzimy do właściwego miejsca…</p>}
        {current?.status === "missing" && <div className="guide-state guide-state-warning" role="status"><p>Nie można podświetlić tego elementu. Ekran może być jeszcze niedostępny. Możesz ponowić próbę lub przejść dalej.</p><button type="button" className="text-button" onClick={() => setAttempt(value => value + 1)}>Spróbuj ponownie</button></div>}
        <div className="guide-step-footer"><span className="guide-counter" aria-live="polite">Krok {session.index + 1} z {steps.length}</span><div className="guide-buttons"><button type="button" className="button button-secondary" disabled={busy || waiting || session.index === 0} onClick={() => move(session.kind!, session.index - 1)}>Wstecz</button><button type="button" className="button button-primary" disabled={busy || waiting} onClick={() => move(session.kind!, session.index + 1)}>{session.index === steps.length - 1 ? "Zakończ" : "Dalej"}</button></div></div>
        <button type="button" className="guide-skip" onClick={() => close()}>Pomiń przewodnik</button>
      </> : <>
        <div className="guide-options">{options.map((option, index) => <button type="button" key={option.kind} className="guide-option" aria-label={option.label} disabled={busy} onClick={() => move(option.kind, 0)}><span className="guide-option-number" aria-hidden="true">0{index + 1}</span><span><strong>{option.label}</strong><small>{option.description}</small><em>{option.duration}</em></span><span className="guide-option-arrow" aria-hidden="true">↗</span></button>)}</div>
        <p className="guide-choice-note">Przewodnik przechodzi między ekranami, ale niczego nie zapisuje. Zakończ edycję rozpoczętego formularza przed wybraniem ścieżki.</p>
      </>}
    </div>
  </dialog>;
}

function cardPosition(rect: Rectangle | null, measuredHeight: number): CSSProperties {
  const width = Math.min(400, window.innerWidth - 32);
  const height = Math.min(measuredHeight, window.innerHeight - 32);
  if (window.innerWidth <= 640) return { left: 16, right: 16, bottom: 16, top: "auto" };
  if (!rect) return { left: Math.max(16, (window.innerWidth - width) / 2), top: Math.max(16, (window.innerHeight - height) / 2) };
  let left = rect.left + rect.width + 20;
  let top = rect.top;
  if (left + width > window.innerWidth - 16) {
    left = rect.left - width - 20;
    if (left < 16) {
      left = Math.min(Math.max(16, rect.left), window.innerWidth - width - 16);
      top = rect.top + rect.height + 20;
      if (top + height > window.innerHeight - 16) top = rect.top - height - 20;
    }
  }
  return { left: Math.max(16, Math.min(left, window.innerWidth - width - 16)), top: Math.max(16, Math.min(top, window.innerHeight - height - 16)) };
}
