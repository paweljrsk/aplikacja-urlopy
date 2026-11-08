'use strict';

const assert = require('node:assert/strict');
const { mkdirSync } = require('node:fs');
const { join } = require('node:path');
const { chromium } = require('playwright-core');

// Synthetic browser API: proves route separation, tour interaction and absence of
// writes. Actual role enforcement and accounting remain covered by PHP/MySQL/E2E.
const baseUrl = (process.env.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const base = new URL(baseUrl);
assert.ok(base.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(base.hostname));
const screenshotDir = process.env.UI_SCREENSHOT_DIR || '/tmp/urlopy-ui-screens';
mkdirSync(screenshotDir, { recursive: true });
const today = '2026-11-09';
const year = 2026;
const team = { id: 1, code: 'synthetic', name: 'Zespół testowy' };
const employmentType = { id: 1, code: 'uop', name: 'Umowa o pracę', active: true, version: 1, carriesUnusedHours: true };
const employee = { id: 1, firstName: 'Anna', lastName: 'Testowa', email: 'guide_employee@example.test', isAdministrator: false, mustChangePassword: false, team, role: 'employee' };
const manager = { ...employee, id: 2, firstName: 'Marek', lastName: 'Kierownik', email: 'guide_manager@example.test', role: 'manager' };
const deputy = { ...employee, id: 3, firstName: 'Ewa', lastName: 'Zastępczyni', email: 'guide_deputy@example.test', role: 'deputy' };
const administrator = { ...employee, id: 4, firstName: 'Ada', lastName: 'Administratorka', email: 'guide_admin@example.test', isAdministrator: true, team: null, role: null };
const people = [employee, manager, deputy, administrator];
const canManage = user => user && (user.isAdministrator || ['manager', 'deputy'].includes(user.role));
const catalog = {
  teams: [{ ...team, active: true, version: 1 }], employmentTypes: [employmentType],
  absenceTypes: [{ id: 1, code: 'paid', name: 'Płatne dni wolne', active: true, version: 1, revision: 1, deductsBalance: true, employmentTypeIds: [1], namesByEmploymentType: {} }],
  roles: [{ value: 'employee', label: 'Pracownik' }, { value: 'manager', label: 'Kierownik' }, { value: 'deputy', label: 'Zastępca' }],
};
function balanceFor(id, selectedYear) {
  return { userId: id, year: selectedYear, version: '1', configured: true, annualHours: 80,
    opening: { effectiveDate: `${selectedYear}-01-01`, annualHours: 80, currentYearUsedHours: 0, carries: [] },
    carriedHours: 0, allocatedHours: 80, openingUsedHours: 0, usedHours: 0, approvedFutureHours: 0,
    reservedHours: 0, changeReservedHours: 0, availableHours: 80, deficitHours: 0, displayAvailable: '80 h', pools: [], history: [] };
}
const noActions = { edit: false, withdraw: false, approve: false, reject: false, change: false, cancel: false, correct: false };
const revision = { id: 9001, revision: 1, baseRevision: null, kind: 'initial', mode: 'full_days', startDate: '2026-11-12', endDate: '2026-11-12', totalHours: 8,
  days: [{ date: '2026-11-12', hours: 8 }], absenceType: { id: 1, code: 'paid', revision: 1, name: 'Płatne dni wolne', deductsBalance: true },
  submitted: { at: '2026-11-09T08:00:00+00:00', actorName: 'Anna Testowa', reason: null }, decision: null };
const request = { id: 900, reference: 'synthetic-guide-request', version: 1, status: 'pending', currentVisible: true, user: employee,
  latestVisibleRevision: revision, actions: noActions, current: revision, effective: null, pending: revision, history: [revision] };

let step = 'launch';
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1050 }, timezoneId: 'Europe/Warsaw' });
    let session = employee;
    let authGate = null;
    const writes = [];
    const reads = [];
    const pageErrors = [];
    await context.route('**/api/**', async route => {
      const incoming = route.request(); const url = new URL(incoming.url()); const path = url.pathname;
      const reply = (status, value) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(value) });
      const failure = (status, code) => reply(status, { error: { code } });
      if (incoming.method() !== 'GET') { writes.push(path); return failure(405, 'METHOD_NOT_ALLOWED'); }
      reads.push(path + url.search);
      if (path === '/api/auth/csrf') return reply(200, { csrfToken: 'synthetic-guide-csrf' });
      if (!session) return failure(401, 'AUTH_REQUIRED');
      if (path === '/api/auth/me') {
        if (authGate) { const held = authGate; authGate = null; held.started(); await held.wait; }
        return reply(200, { user: session });
      }
      if (path === '/api/requests/options') return reply(200, { today, timezone: 'Europe/Warsaw', hoursPerDay: 8, modes: ['full_days', 'hourly'], absenceTypes: catalog.absenceTypes.map(type => ({ ...type, availableForCurrentEmployment: true })) });
      if (path === '/api/team-calendar') return reply(200, { today, from: url.searchParams.get('from'), to: url.searchParams.get('to'), timezone: 'Europe/Warsaw', teams: [team], selectedTeamId: 1, people: [employee, manager, deputy], entries: [], holidays: [] });
      if (path === '/api/balances/people') return reply(200, {
        // An administrator receives more people from the real API too. The own
        // screen must actively select only itself and never expose these editors.
        people: (canManage(session) ? people : [session]).map(person => ({ ...person, active: true, employmentType, canManage: session.isAdministrator || session.role === 'manager' && session.id !== person.id })),
        currentYear: year, today, isAdministrator: session.isAdministrator,
      });
      const balanceMatch = path.match(/^\/api\/balances\/users\/(\d+)\/(\d+)$/);
      if (balanceMatch) return reply(200, { balance: balanceFor(Number(balanceMatch[1]), Number(balanceMatch[2])) });
      if (path === '/api/requests') {
        if (url.searchParams.get('scope') === 'managed' && !canManage(session)) return failure(403, 'FORBIDDEN');
        return reply(200, { requests: [], nextBeforeId: null });
      }
      if (path === '/api/requests/900') return canManage(session) ? reply(200, { request }) : failure(403, 'FORBIDDEN');
      if (path.startsWith('/api/admin/') && !session.isAdministrator) return failure(403, 'FORBIDDEN');
      if (path === '/api/admin/catalog') return reply(200, catalog);
      if (path === '/api/admin/users') return reply(200, { users: people.map(person => ({ ...person, active: true, version: 1, employmentType, assignments: [] })) });
      if (path === '/api/admin/history') return reply(200, { events: [], nextBeforeId: null });
      const calendarMatch = path.match(/^\/api\/calendar\/(\d+)$/);
      if (calendarMatch) return session.isAdministrator ? reply(200, { year: Number(calendarMatch[1]), timezone: 'Europe/Warsaw', hoursPerDay: 8, minimumYear: 2025, maximumYear: 2100, holidays: [] }) : failure(403, 'FORBIDDEN');
      return failure(404, 'NOT_FOUND');
    });
    const page = await context.newPage(); page.setDefaultTimeout(15000);
    page.on('pageerror', error => pageErrors.push(error.message));
    const launch = () => page.getByRole('button', { name: 'Przewodnik', exact: true }).first();
    const dialog = () => page.getByRole('dialog', { name: 'Poznaj aplikację', exact: true });
    const next = () => dialog().getByRole('button', { name: 'Dalej', exact: true }).click();
    const waitStep = async (kind, index) => {
      await page.locator(`[data-guide-kind="${kind}"][data-guide-step="${index}"]`).waitFor();
      await page.locator('[data-guide-highlight="true"]').waitFor();
      await dialog().getByText(`Krok ${index} z`, { exact: false }).waitFor();
    };
    async function openChoice(choice) {
      await launch().click(); await dialog().waitFor();
      await dialog().getByRole('button', { name: choice, exact: true }).click();
    }
    async function waitForLauncherFocus() {
      // The dialog unmounts before the next animation frame restores focus.
      // Wait for the user-visible outcome, not merely removal of the overlay.
      await page.waitForFunction(() => document.activeElement instanceof HTMLButtonElement
        && document.activeElement.getAttribute('data-guide-launcher') === 'true'
        && !document.activeElement.disabled, undefined, { timeout: 5000 });
      assert.equal(await page.evaluate(() => document.activeElement?.getAttribute('data-guide-launcher')), 'true', 'Closing restores focus to an available guide launcher.');
    }
    async function closeGuide() {
      await page.keyboard.press('Escape'); await dialog().waitFor({ state: 'hidden' });
      await waitForLauncherFocus();
      assert.equal(await page.locator('[data-guide-highlight="true"]').count(), 0);
    }
    async function assertFits() {
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Module/tour page must not overflow horizontally.');
      const box = await dialog().locator('.guide-card').boundingBox();
      assert.ok(box && box.x >= -1 && box.y >= -1 && box.x + box.width <= page.viewportSize().width + 1 && box.y + box.height <= page.viewportSize().height + 1, 'Guide controls fit inside the viewport.');
      const highlight = await page.locator('[data-guide-highlight="true"]').boundingBox();
      assert.ok(highlight && highlight.width > 0 && highlight.height > 0 && highlight.x < page.viewportSize().width && highlight.y < page.viewportSize().height && highlight.x + highlight.width > 0 && highlight.y + highlight.height > 0, 'Highlighted target intersects the viewport.');
    }

    step = 'own balance remains personal for every role';
    for (const actor of people) {
      session = actor; reads.length = 0;
      await page.goto(`${baseUrl}/balances?view=team&personId=1`);
      await page.getByRole('heading', { name: `${actor.firstName} ${actor.lastName} · ${year}`, exact: true }).waitFor();
      assert.equal(await page.getByLabel('Osoba', { exact: true }).count(), 0);
      assert.equal(await page.locator('.team-balances-table').count(), 0);
      assert.equal(await page.locator('.balance-management, .accounting-nav').count(), 0);
      assert.equal(await page.getByRole('button', { name: /Zmień limit roczny|Koryguj dane początkowe|Ustaw dane początkowe/ }).count(), 0);
      const balanceReads = reads.filter(path => /^\/api\/balances\/users\//.test(path));
      assert.ok(balanceReads.length > 0 && balanceReads.every(path => path.startsWith(`/api/balances/users/${actor.id}/`)), 'Own balance never fetches another person, even for an administrator or manipulated URL.');
      if (actor.isAdministrator) await page.screenshot({ path: join(screenshotDir, 'own-balance-admin.png'), fullPage: true });
    }

    step = 'employee module denial without management data';
    session = employee;
    for (const path of ['/management', '/management/requests', '/management/balances', '/management/admin', '/management/calendar']) {
      reads.length = 0; await page.goto(baseUrl + path);
      await page.getByRole('heading', { name: /^Brak dostępu/ }).waitFor();
      assert.equal(reads.some(read => read.startsWith('/api/admin/') || read.startsWith('/api/calendar/') || read.startsWith('/api/balances/') || read.includes('scope=managed')), false, 'Denied pages do not request management datasets.');
    }

    step = 'module navigation and role-scoped choices';
    for (const actor of [manager, deputy, administrator]) {
      session = actor; await page.goto(`${baseUrl}/management`);
      await page.locator('[data-tour="management-overview"]').waitFor();
      const nav = page.locator('[data-tour="management-navigation"]');
      await nav.locator('a[href="/management/requests"]').waitFor();
      await nav.locator('a[href="/management/balances"]').waitFor();
      assert.equal(await nav.locator('a[href="/management/admin"]').count(), actor.isAdministrator ? 1 : 0);
      assert.equal(await nav.locator('a[href="/management/calendar"]').count(), actor.isAdministrator ? 1 : 0);
      await launch().click(); await dialog().waitFor();
      await dialog().getByRole('button', { name: 'Pracownik', exact: true }).waitFor();
      assert.equal(await dialog().getByRole('button', { name: 'Administrator', exact: true }).count(), actor.isAdministrator ? 1 : 0);
      await dialog().getByRole('button', { name: actor.isAdministrator ? 'Administrator' : actor.role === 'manager' ? 'Kierownik' : 'Zastępca', exact: true }).waitFor();
      await closeGuide();
      if (actor.isAdministrator) {
        await page.screenshot({ path: join(screenshotDir, 'module-hub-desktop.png'), fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
        await page.screenshot({ path: join(screenshotDir, 'module-hub-mobile.png'), fullPage: true });
        await page.setViewportSize({ width: 1440, height: 1050 });
      }
    }

    step = 'legacy routes preserve their destination';
    session = administrator;
    for (const [oldPath, newPath] of [['/admin', '/management/admin'], ['/calendar-settings', '/management/calendar'], ['/management?id=900', '/management/requests?id=900']]) {
      await page.goto(baseUrl + oldPath);
      await page.waitForURL(url => url.pathname + url.search === newPath);
    }

    step = 'delayed guide opening does not cover a different screen';
    session = employee; await page.goto(baseUrl + '/');
    await page.locator('[data-tour="team-calendar"]').waitFor();
    let releaseAuth; let startedAuth;
    const authStarted = new Promise(resolve => { startedAuth = resolve; });
    authGate = { started: startedAuth, wait: new Promise(resolve => { releaseAuth = resolve; }) };
    await launch().click(); await authStarted;
    await page.getByRole('navigation', { name: 'Nawigacja główna', exact: true }).getByRole('link', { name: 'Moje wnioski', exact: true }).click();
    await page.waitForURL(url => url.pathname === '/requests');
    await page.locator('[data-tour="my-requests"]').waitFor();
    releaseAuth();
    await page.waitForFunction(() => document.querySelector('[data-guide-launcher]')?.disabled === false);
    assert.equal(await dialog().count(), 0, 'A delayed session response cannot open a guide after the user navigated elsewhere.');

    step = 'employee tour navigation, keyboard and focus';
    session = employee; await page.goto(baseUrl + '/');
    await launch().click(); await dialog().waitFor();
    assert.equal(await dialog().getByRole('button', { name: /Administrator|Kierownik|Zastępca/, exact: true }).count(), 0);
    await dialog().getByRole('button', { name: 'Pracownik', exact: true }).click();
    await waitStep('employee', 1); await assertFits();
    await page.screenshot({ path: join(screenshotDir, 'guide-employee-desktop.png'), fullPage: true });
    for (const key of ['Tab', 'Tab', 'Tab', 'Tab', 'Tab', 'Tab', 'Shift+Tab', 'Shift+Tab', 'Shift+Tab', 'Shift+Tab', 'Shift+Tab', 'Shift+Tab']) {
      await page.keyboard.press(key);
      assert.equal(await dialog().evaluate(element => element.contains(document.activeElement)), true, 'Keyboard focus stays within the guide.');
    }
    await next(); await waitStep('employee', 2);
    await dialog().getByRole('button', { name: 'Wstecz', exact: true }).click(); await waitStep('employee', 1);
    for (let index = 2; index <= 6; index++) { await next(); await waitStep('employee', index); }
    await dialog().getByRole('button', { name: 'Zakończ', exact: true }).click();
    await dialog().waitFor({ state: 'hidden' });
    await waitForLauncherFocus();
    await page.setViewportSize({ width: 390, height: 844 });
    await openChoice('Pracownik'); await waitStep('employee', 1); await next(); await waitStep('employee', 2); await assertFits();
    await page.screenshot({ path: join(screenshotDir, 'guide-employee-mobile.png'), fullPage: true });
    await closeGuide(); await page.setViewportSize({ width: 1440, height: 1050 });

    step = 'manager, deputy and administrator complete role tours';
    for (const [actor, kind, choice, count] of [[manager, 'manager', 'Kierownik', 5], [deputy, 'deputy', 'Zastępca', 5], [administrator, 'administrator', 'Administrator', 6]]) {
      session = actor; await page.goto(`${baseUrl}/management`);
      await openChoice(choice); await waitStep(kind, 1);
      if (actor.isAdministrator) await page.screenshot({ path: join(screenshotDir, 'guide-admin-desktop.png'), fullPage: true });
      for (let index = 2; index <= count; index++) { await next(); await waitStep(kind, index); }
      await dialog().getByRole('button', { name: 'Zakończ', exact: true }).click(); await dialog().waitFor({ state: 'hidden' });
      await waitForLauncherFocus();
    }

    step = 'missing target fallback and dismissal';
    session = employee; await page.goto(baseUrl + '/');
    await page.locator('[data-tour="team-calendar"]').waitFor();
    await launch().click(); await dialog().waitFor();
    await page.locator('[data-tour="team-calendar"]').evaluate(element => element.removeAttribute('data-tour'));
    await dialog().getByRole('button', { name: 'Pracownik', exact: true }).click();
    await page.locator('[data-guide-kind="employee"][data-guide-step="1"]').waitFor();
    assert.equal(await dialog().getByRole('button', { name: 'Dalej', exact: true }).isDisabled(), true, 'Navigation waits for the target or the explicit fallback.');
    await dialog().getByText('Nie można podświetlić tego elementu', { exact: false }).waitFor({ timeout: 15000 });
    await dialog().getByRole('button', { name: 'Spróbuj ponownie', exact: true }).waitFor();
    await closeGuide();

    step = 'role and session loss dismiss active guides';
    session = administrator; await page.goto(`${baseUrl}/management`); await openChoice('Administrator'); await waitStep('administrator', 1);
    session = { ...administrator, isAdministrator: false, role: 'employee', team };
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await dialog().waitFor({ state: 'hidden' });
    assert.equal(await page.locator('[data-guide-highlight="true"]').count(), 0);
    session = employee; await page.goto(baseUrl + '/'); await openChoice('Pracownik'); await waitStep('employee', 1);
    session = null; await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    // The account and guide reauthorize independently. Concurrent 401 redirects
    // can supersede one navigation; assert the settled URL and rendered login.
    const loginDeadline = Date.now() + 12000;
    while (new URL(page.url()).pathname !== '/login') {
      if (Date.now() >= loginDeadline) throw new Error('Expired session must reach the login page.');
      await new Promise(resolve => setTimeout(resolve, 20));
    }
    await page.getByRole('heading', { name: 'Zaloguj się', exact: true }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/login');
    assert.equal(await page.locator('[data-guide-step]').count(), 0);
    assert.deepEqual(writes, [], 'Tours, navigation and personal balance viewing perform no mutations.');
    assert.deepEqual(pageErrors, [], 'No unhandled browser errors.');
    assert.equal(await page.evaluate(() => localStorage.length + sessionStorage.length), 0, 'Guides do not persist identity, data or progress in browser storage.');
    console.log('PASS Chrome module/tours: own balances read-only for all roles, no management dataset on denied routes, role navigation, legacy destinations, employee/manager/deputy/admin guided steps, next/back/completion/Escape/focus trap, mobile layout, missing target fallback, revocation and expired session, zero mutations and browser storage. API responses are synthetic.');
    await context.close();
  } finally { await browser.close(); }
})().catch(error => { console.error(`Module/tour UI failed at: ${step}`); console.error(error); process.exit(1); });
