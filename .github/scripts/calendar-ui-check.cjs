'use strict';

const assert = require('node:assert/strict');
const { mkdirSync } = require('node:fs');
const { join } = require('node:path');
const { chromium } = require('playwright-core');

// Explicitly synthetic responses test presentation and authorization affordances.
// The PHP/MySQL suite separately proves the actual server-side access policy.
const baseUrl = (process.env.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const target = new URL(baseUrl);
assert.ok(target.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(target.hostname));
const screenshotDir = process.env.UI_SCREENSHOT_DIR || '/tmp/urlopy-ui-screens';
mkdirSync(screenshotDir, { recursive: true });
const today = '2026-11-09';
const teams = [{ id: 1, name: 'Zespół testowy' }, { id: 2, name: 'Drugi zespół testowy' }];
const people = new Map([
  [1, { id: 1, firstName: 'Anna', lastName: 'Testowa' }],
  [2, { id: 2, firstName: 'Marek', lastName: 'Kierownik' }],
  [3, { id: 3, firstName: 'Ada', lastName: 'Administrator' }],
  [4, { id: 4, firstName: 'Jan', lastName: 'Testowy' }],
  [5, { id: 5, firstName: 'Ewa', lastName: 'Nowak' }],
  [6, { id: 6, firstName: 'Olga', lastName: 'Demonstracyjna-Bardzo-Długie-Nazwisko' }],
  [7, { id: 7, firstName: 'Kasia', lastName: 'Tylko-Przyszły-Wpis' }],
  [8, { id: 8, firstName: 'Paweł', lastName: 'Tylko-Historyczny-Wpis' }],
  [9, { id: 9, firstName: 'Iga', lastName: 'Bez-Nieobecności' }],
  [10, { id: 10, firstName: 'Olek', lastName: 'Bez-Nieobecności' }],
  [11, { id: 11, firstName: 'Tomasz', lastName: 'Bez-Nieobecności' }],
  [20, { id: 20, firstName: 'Bartosz', lastName: 'Drugi-Zespół' }],
]);
const employee = { ...people.get(1), email: 'calendar_employee@example.test', isAdministrator: false, mustChangePassword: false, team: { ...teams[0], code: 'synthetic-one' }, role: 'employee' };
const manager = { ...employee, ...people.get(2), email: 'calendar_manager@example.test', role: 'manager' };
const administrator = { ...employee, ...people.get(3), email: 'calendar_admin@example.test', isAdministrator: true, team: null, role: null };
const inactiveOrHistorical = new Set([7, 8]);
const noActions = { edit: false, withdraw: false, approve: false, reject: false, change: false, cancel: false, correct: false };
const privateType = 'Syntetyczny rodzaj poufny';
const privateDebug = 'Synthetic debug response must not appear in the UI';
function entry(id, userId, kind, status, dates, hours = 8) {
  return { key: `${id}:${kind}`, requestId: id, userId, kind, status, days: dates.map(date => ({ date, hours })) };
}
const rawEntries = [
  entry(101, 4, 'effective', 'approved', ['2026-11-10']),
  entry(102, 4, 'pending', 'pending', ['2026-11-12'], 3),
  entry(103, 4, 'effective', 'approved', ['2026-11-12'], 5),
  entry(104, 5, 'effective', 'change_pending', ['2026-11-16', '2026-11-17']),
  entry(104, 5, 'proposed_change', 'change_pending', ['2026-11-23', '2026-11-24', '2026-11-25']),
  entry(105, 6, 'effective', 'cancellation_pending', ['2026-11-18']),
  entry(106, 7, 'effective', 'approved', ['2026-12-15']),
  entry(107, 8, 'effective', 'approved', ['2026-10-26']),
  // The server projection intentionally reveals no inaccessible pending change.
  entry(110, 4, 'effective', 'approved', ['2026-11-19'], 4),
  entry(111, 1, 'effective', 'approved', ['2026-11-13']),
  entry(201, 20, 'effective', 'approved', ['2026-11-10']),
];
const teamFor = id => id === 20 ? 2 : 1;
function scopedEntry(raw, from, to, actor) {
  const days = raw.days.filter(day => day.date >= from && day.date <= to);
  if (!days.length) return null;
  return { ...raw, days, startDate: days[0].date, endDate: days.at(-1).date,
    totalHours: days.reduce((sum, day) => sum + day.hours, 0),
    canViewDetails: actor.isAdministrator || actor.role !== 'employee' || actor.id === raw.userId };
}
function calendarResponse(url, actor) {
  const from = url.searchParams.get('from'); const to = url.searchParams.get('to');
  assert.match(from || '', /^\d{4}-\d{2}-\d{2}$/); assert.match(to || '', /^\d{4}-\d{2}-\d{2}$/);
  assert.ok([...url.searchParams.keys()].every(key => ['from', 'to', 'teamId'].includes(key)), 'UI filters must use only the authorized calendar projection.');
  const requestedTeam = url.searchParams.get('teamId');
  assert.ok(actor.isAdministrator || requestedTeam === null, 'Employee/manager cannot choose another team.');
  const selectedTeamId = actor.isAdministrator ? requestedTeam ? Number(requestedTeam) : null : 1;
  const allowed = id => selectedTeamId === null || teamFor(id) === selectedTeamId;
  const entries = rawEntries.filter(item => allowed(item.userId)).map(item => scopedEntry(item, from, to, actor)).filter(Boolean);
  const visiblePeople = [...people.values()].filter(person => allowed(person.id) && person.id !== 3 && !inactiveOrHistorical.has(person.id));
  for (const item of entries) if (!visiblePeople.some(person => person.id === item.userId)) visiblePeople.push(people.get(item.userId));
  return { today, from, to, timezone: 'Europe/Warsaw', teams: actor.isAdministrator ? teams : [teams[0]], selectedTeamId,
    people: visiblePeople, entries, holidays: [{ date: '2026-11-11', name: 'Święto Niepodległości' }].filter(day => day.date >= from && day.date <= to) };
}
function revision(raw, number) {
  const initial = raw.kind !== 'proposed_change';
  return { id: raw.requestId * 10 + number, revision: number, baseRevision: initial ? null : 1, kind: initial ? 'initial' : 'change',
    mode: raw.days.length === 1 && raw.days[0].hours < 8 ? 'hourly' : 'full_days',
    startDate: raw.days[0].date, endDate: raw.days.at(-1).date, days: raw.days,
    totalHours: raw.days.reduce((sum, day) => sum + day.hours, 0),
    absenceType: { id: 1, code: 'synthetic-private', revision: 1, name: privateType, deductsBalance: true },
    submitted: { at: '2026-11-01T08:00:00+00:00', actorName: `${people.get(raw.userId).firstName} ${people.get(raw.userId).lastName}`, reason: null },
    decision: raw.kind === 'effective' ? { outcome: 'approved', at: '2026-11-02T08:00:00+00:00', actorName: 'Marek Kierownik', reason: null } : null };
}
function detail(id) {
  const raw = rawEntries.filter(item => item.requestId === id);
  const effective = raw.find(item => item.kind === 'effective'); const proposed = raw.find(item => item.kind !== 'effective');
  const effectiveRevision = effective ? revision(effective, 1) : null;
  const pending = proposed ? revision(proposed, effective ? 2 : 1)
    : effective?.status === 'cancellation_pending'
      ? { ...revision(effective, 2), baseRevision: 1, kind: 'cancellation', decision: null } : null;
  const current = pending ?? effectiveRevision;
  const history = [effectiveRevision, pending].filter(Boolean);
  const historical = id === 107;
  return { id, reference: `synthetic-calendar-${id}`, version: historical ? null : 1, status: historical ? null : raw[0].status,
    user: people.get(raw[0].userId), currentVisible: !historical, latestVisibleRevision: current,
    current: historical ? null : current, effective: historical ? null : effectiveRevision, pending: historical ? null : pending,
    history, actions: noActions };
}
async function until(predicate, description) {
  const deadline = Date.now() + 10000;
  while (!await predicate()) {
    if (Date.now() > deadline) throw new Error(description);
    await new Promise(resolve => setTimeout(resolve, 20));
  }
}

let step = 'launch';
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  let page;
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1050 }, locale: 'pl-PL', timezoneId: 'Europe/Warsaw' });
    let session = employee; let deniedId = null; let deniedDelay = 0;
    let failPeriodCalendar = false; let calendarFailures = 0; let optionsGate = null;
    const calendarCalls = []; const detailCalls = []; const errors = [];
    await context.route('**/api/**', async route => {
      const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
      const reply = (status, body) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
      const error = (status, code) => reply(status, { error: { code, message: privateDebug } });
      assert.equal(request.method(), 'GET', 'Calendar inspection must never mutate a request.');
      if (!session) return error(401, 'AUTH_REQUIRED');
      if (path === '/api/auth/me') return reply(200, { user: session });
      if (path === '/api/requests/options') {
        if (optionsGate) await optionsGate;
        return reply(200, { today, timezone: 'Europe/Warsaw', hoursPerDay: 8, modes: ['full_days', 'hourly'], absenceTypes: [] });
      }
      if (path === '/api/team-calendar') {
        calendarCalls.push({ from: url.searchParams.get('from'), to: url.searchParams.get('to'), team: url.searchParams.get('teamId') });
        if (failPeriodCalendar && url.searchParams.get('to') !== '2027-01-08') {
          await error(503, 'REQUEST_FAILED'); calendarFailures++; return;
        }
        return reply(200, calendarResponse(url, session));
      }
      if (/^\/api\/balances\/users\/\d+\/2026$/.test(path)) return reply(200, { balance: { userId: session.id, year: 2026, configured: true, availableHours: 80 } });
      const match = path.match(/^\/api\/requests\/(\d+)$/);
      if (match) {
        const id = Number(match[1]); detailCalls.push(id);
        if (id === deniedId) { await new Promise(resolve => setTimeout(resolve, deniedDelay)); return error(403, 'FORBIDDEN'); }
        const item = rawEntries.find(value => value.requestId === id);
        if (!item) return error(404, 'NOT_FOUND');
        if (!session.isAdministrator && (teamFor(item.userId) !== 1 || session.role === 'employee' && item.userId !== session.id)) return error(403, 'FORBIDDEN');
        return reply(200, { request: detail(id) });
      }
      throw new Error(`Unexpected synthetic calendar request ${path}`);
    });
    page = await context.newPage(); page.setDefaultTimeout(10000);
    page.on('pageerror', error => errors.push(error.message));
    await page.clock.setFixedTime(new Date('2026-11-09T10:00:00Z'));
    // These wrapping labels contain the select options too, so exact label text
    // is not a stable Playwright selector. Keep all option/data assertions below.
    const person = page.locator('#calendar-person');
    const status = page.locator('#calendar-status');
    const groups = page.locator('.upcoming-list > li[data-request-id]');
    const group = id => page.locator(`.upcoming-list > li[data-request-id="${id}"]`);
    const ids = async () => (await groups.evaluateAll(elements => elements.map(element => Number(element.dataset.requestId)))).sort((a, b) => a - b);
    const expectIds = async expected => { await until(async () => JSON.stringify(await ids()) === JSON.stringify(expected), `Unexpected visible request groups; expected ${expected.join(',')}`); };
    const clear = () => page.getByRole('button', { name: 'Wyczyść filtry', exact: true }).first().click();
    const loaded = async () => { await page.getByRole('heading', { name: 'Kalendarz zespołu', exact: true }).waitFor(); await page.getByRole('table').waitFor(); await expectIds([101, 102, 103, 104, 105, 106, 110, 111]); };

    step = 'employee calendar grouping and private detail affordances';
    await page.goto(baseUrl); await loaded();
    assert.equal(await person.locator('option[value="7"]').count(), 1, 'People from the upcoming feed must be selectable.');
    assert.equal(await person.locator('option[value="20"]').count(), 0, 'Other-team names must never enter employee filters.');
    assert.equal(await group(104).count(), 1, 'Effective and proposed dates are one request group.');
    await group(104).getByText('Obowiązujący termin', { exact: true }).waitFor();
    await group(104).getByText('Proponowany termin', { exact: true }).waitFor();
    assert.match(await group(104).innerText(), /16 h/); assert.match(await group(104).innerText(), /24 h/);
    assert.doesNotMatch(await group(104).innerText(), /40 h/, 'Versions must not be summed as separate leave.');
    assert.match(await group(105).innerText(), /anulowanie/i);
    assert.equal(await group(101).getByRole('button').count(), 0, 'Coworker private details have no interactive entry point.');
    assert.equal(await page.getByRole('row', { name: /Jan Testowy/ }).locator('button.calendar-entry').count(), 0);
    await page.getByRole('row', { name: /Jan Testowy/ }).locator('span.calendar-entry').first().click();
    assert.equal(await page.getByRole('dialog').count(), 0, 'A coworker calendar chip remains static.');
    assert.deepEqual(detailCalls, [], 'Viewing an employee calendar must not prefetch coworker details.');
    assert.equal(await page.getByText(privateType, { exact: true }).count(), 0);

    step = 'late options success cannot erase a failed calendar retry';
    const calendarPanel = page.locator('.team-calendar-panel');
    failPeriodCalendar = true;
    await page.getByRole('button', { name: 'Następny okres', exact: true }).click();
    await calendarPanel.getByRole('alert').waitFor();
    const previousFailures = calendarFailures;
    let releaseOptions;
    optionsGate = new Promise(resolve => { releaseOptions = resolve; });
    const delayedOptions = page.waitForResponse(response => new URL(response.url()).pathname === '/api/requests/options');
    await calendarPanel.getByRole('button', { name: 'Spróbuj ponownie', exact: true }).click();
    await until(() => calendarFailures > previousFailures, 'The calendar retry must fail before delayed options succeed.');
    await calendarPanel.getByRole('alert').waitFor();
    releaseOptions(); await (await delayedOptions).finished();
    // Let fetch/json handling and React commit settle after the delayed response.
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    assert.equal(await calendarPanel.getByRole('alert').isVisible(), true, 'Successful options must not clear the independent calendar failure.');
    assert.equal(await calendarPanel.locator('.calendar-loading').count(), 0, 'A failed calendar stays retryable instead of spinning forever.');
    assert.equal(await page.getByText(privateDebug, { exact: true }).count(), 0);
    optionsGate = null; failPeriodCalendar = false;
    await calendarPanel.getByRole('button', { name: 'Spróbuj ponownie', exact: true }).click(); await loaded();
    assert.equal(await calendarPanel.getByRole('alert').count(), 0);
    await page.getByRole('button', { name: 'Dzisiaj', exact: true }).click(); await loaded();

    step = 'combined filters apply to calendar and upcoming groups';
    await person.selectOption('4'); await status.selectOption('pending'); await expectIds([102]);
    assert.equal(await page.locator('.team-calendar-table tbody tr').count(), 1);
    assert.equal(await page.locator('.calendar-desktop-view .calendar-entry').count(), 1);
    await page.locator('.calendar-desktop-view .calendar-entry').getByText('3 h', { exact: true }).waitFor();
    await page.getByRole('button', { name: 'Tydzień', exact: true }).click();
    await until(async () => await page.locator('.team-calendar-table thead th').count() === 8, 'Week view has seven date columns.');
    assert.equal(await person.inputValue(), '4'); assert.equal(await status.inputValue(), 'pending'); await expectIds([102]);
    await page.getByRole('button', { name: '2 tygodnie', exact: true }).click();
    await until(async () => await page.locator('.team-calendar-table thead th').count() === 15, 'Two-week view has fourteen date columns.');
    await status.selectOption('approved'); await expectIds([101, 103, 110]);
    assert.equal(await page.locator('.calendar-desktop-view .calendar-entry.is-pending').count(), 0);
    await status.selectOption('changes'); await expectIds([]);
    assert.equal(await page.locator('.calendar-desktop-view .calendar-entry').count(), 0, 'An inaccessible pending change cannot be inferred by a status filter.');
    await person.selectOption('5'); await expectIds([104]);
    await page.getByRole('button', { name: 'Następny okres', exact: true }).click();
    await until(async () => await page.locator('.calendar-desktop-view .calendar-entry.is-proposed_change').count() === 3, 'The next period must display all three proposed dates.');
    assert.equal(await person.inputValue(), '5'); assert.equal(await status.inputValue(), 'changes'); await expectIds([104]);
    await page.getByRole('button', { name: 'Dzisiaj', exact: true }).click();
    await until(async () => await page.locator('.calendar-desktop-view .calendar-entry.is-effective').count() === 2, 'Today returns to the effective dates.');
    await page.screenshot({ path: join(screenshotDir, 'calendar-filters-desktop.png'), fullPage: true });

    step = 'approved filter retains effective change and cancellation requests';
    await person.selectOption(''); await status.selectOption('approved'); await expectIds([101, 103, 104, 105, 106, 110, 111]);
    assert.equal(await group(104).locator('.upcoming-version.is-effective').count(), 1);
    assert.equal(await group(104).locator('.upcoming-version.is-proposed_change').count(), 0, 'Approved means the effective dates, without pending proposal hours.');
    assert.equal(await group(105).locator('.upcoming-version.is-effective').count(), 1);
    assert.match(await group(104).innerText(), /Zmiana oczekuje/); assert.match(await group(105).innerText(), /Anulowanie oczekuje/);
    assert.equal(await page.locator('.calendar-desktop-view .calendar-entry.is-pending, .calendar-desktop-view .calendar-entry.is-proposed_change').count(), 0);
    await status.selectOption('changes'); await expectIds([104, 105]);
    assert.equal(await group(104).locator('.upcoming-version').count(), 2, 'Changes retain both accessible versions in one request group.');
    assert.deepEqual(detailCalls, [], 'Filtering coworker calendar entries never fetches private details.');

    step = 'upcoming-only and history-only people, empty states and reset';
    await person.selectOption('7'); await status.selectOption('approved'); await expectIds([106]);
    assert.equal(await page.locator('.calendar-desktop-view .calendar-entry').count(), 0);
    await status.selectOption('pending'); await expectIds([]);
    assert.match(await page.locator('.upcoming-section').innerText(), /Brak|Nie ma/i);
    await clear(); await expectIds([101, 102, 103, 104, 105, 106, 110, 111]);
    assert.equal(await person.inputValue(), ''); assert.equal(await status.inputValue(), '');
    await page.getByRole('button', { name: 'Poprzedni okres', exact: true }).click();
    await person.locator('option[value="8"]').waitFor({ state: 'attached' });
    assert.equal(await person.locator('option[value="7"]').count(), 1, 'Filter options combine both date windows.');
    await person.selectOption('8');
    await until(async () => await page.locator('.calendar-desktop-view .calendar-entry').count() === 1, 'Historical entry must be selectable.');
    await expectIds([]);
    await page.getByRole('button', { name: 'Dzisiaj', exact: true }).click();
    await until(async () => await page.locator('.calendar-desktop-view .calendar-entry').count() === 0, 'Outside-window selected person has an empty calendar.');
    assert.equal(await person.inputValue(), '8', 'Navigation retains the selected person instead of broadening the view.');
    await clear(); await expectIds([101, 102, 103, 104, 105, 106, 110, 111]);

    step = 'own request details keep calendar context';
    await person.selectOption('1'); await expectIds([111]);
    const ownTrigger = group(111).getByRole('button', { name: 'Szczegóły wniosku 111', exact: true });
    await ownTrigger.click();
    let dialog = page.getByRole('dialog', { name: 'Szczegóły nieobecności', exact: true });
    await dialog.getByRole('link', { name: 'Przejdź do mojego wniosku', exact: true }).waitFor();
    assert.equal(await dialog.getByRole('link', { name: 'Przejdź do mojego wniosku', exact: true }).getAttribute('href'), '/requests?id=111');
    await page.keyboard.press('Escape'); await dialog.waitFor({ state: 'detached' });
    assert.equal(await ownTrigger.evaluate(element => element === document.activeElement), true);
    assert.equal(await person.inputValue(), '1');

    step = 'manager read-only details and preserved filters';
    session = manager; await page.reload(); await loaded();
    await person.selectOption('5'); await status.selectOption('changes'); await expectIds([104]);
    const managerTrigger = group(104).getByRole('button', { name: 'Szczegóły wniosku 104', exact: true });
    await managerTrigger.click(); dialog = page.getByRole('dialog', { name: 'Szczegóły nieobecności', exact: true });
    await dialog.getByRole('heading', { name: 'Obowiązujący urlop', exact: true }).waitFor();
    await dialog.getByRole('heading', { name: 'Proponowana zmiana', exact: true }).waitFor();
    assert.ok(await dialog.getByText(privateType, { exact: true }).count() >= 1);
    assert.equal(await dialog.getByRole('link', { name: 'Przejdź do mojego wniosku', exact: true }).count(), 0);
    assert.equal(await dialog.getByRole('button', { name: /Akceptuj|Odrzuć|Skoryguj|Edytuj wniosek/ }).count(), 0, 'Calendar inspection does not introduce stage 10 decisions.');
    await page.keyboard.press('Tab'); await page.keyboard.press('Shift+Tab');
    assert.equal(await page.evaluate(() => !!document.activeElement?.closest('dialog[open]')), true);
    await page.screenshot({ path: join(screenshotDir, 'calendar-details-desktop.png'), fullPage: true });
    await page.keyboard.press('Escape'); await dialog.waitFor({ state: 'detached' });
    assert.equal(await managerTrigger.evaluate(element => element === document.activeElement), true);
    assert.equal(await person.inputValue(), '5'); assert.equal(await status.inputValue(), 'changes');

    step = 'stale permission, retry and fresh focus authorization';
    deniedId = 104; await managerTrigger.click();
    dialog = page.getByRole('dialog', { name: 'Szczegóły nieobecności', exact: true });
    await dialog.getByRole('alert').waitFor();
    assert.equal(await dialog.getByText(privateType, { exact: true }).count(), 0);
    assert.equal(await dialog.locator('.calendar-request-person, .requests-status, .requests-revisions, .requests-history').count(), 0);
    assert.equal(await page.getByText(privateDebug, { exact: true }).count(), 0);
    deniedId = null; await dialog.getByRole('button', { name: 'Spróbuj ponownie', exact: true }).click();
    await dialog.getByRole('heading', { name: 'Obowiązujący urlop', exact: true }).waitFor();
    const beforeFocus = detailCalls.length; deniedId = 104; deniedDelay = 200;
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await until(() => detailCalls.length > beforeFocus, 'The open details must be reauthorized on focus.');
    assert.equal(await dialog.getByText(privateType, { exact: true }).count(), 0, 'Old private details are removed before a new authorization completes.');
    await dialog.getByRole('alert').waitFor();
    assert.equal(await dialog.locator('.calendar-request-person, .requests-status, .requests-revisions, .requests-history').count(), 0);
    await dialog.getByRole('button', { name: 'Wróć do kalendarza', exact: true }).click();
    await dialog.waitFor({ state: 'detached' }); deniedId = null; deniedDelay = 0;

    step = 'historical-only authorized details';
    await clear(); await page.getByRole('button', { name: 'Poprzedni okres', exact: true }).click();
    await person.locator('option[value="8"]').waitFor({ state: 'attached' }); await person.selectOption('8');
    await page.locator('.calendar-desktop-view button.calendar-entry').click();
    dialog = page.getByRole('dialog', { name: 'Szczegóły nieobecności', exact: true });
    await dialog.getByText('Widoczne są tylko wersje wniosku dostępne w Twoim zakresie uprawnień.', { exact: true }).waitFor();
    await dialog.getByRole('heading', { name: 'Termin wniosku', exact: true }).waitFor();
    await dialog.locator('.requests-history > summary').click();
    assert.equal(await dialog.locator('.requests-history li').count(), 1);
    assert.equal(await dialog.getByRole('heading', { name: 'Proponowana zmiana', exact: true }).count(), 0);
    await page.keyboard.press('Escape'); await dialog.waitFor({ state: 'detached' });

    step = 'deputy details and administrator team filtering';
    session = { ...manager, role: 'deputy' }; await page.reload(); await loaded();
    await group(101).getByRole('button', { name: 'Szczegóły wniosku 101', exact: true }).click();
    dialog = page.getByRole('dialog', { name: 'Szczegóły nieobecności', exact: true });
    await dialog.getByRole('heading', { name: 'Obowiązujący urlop', exact: true }).waitFor();
    await page.keyboard.press('Escape'); await dialog.waitFor({ state: 'detached' });
    session = administrator; await page.reload();
    await page.getByLabel('Zespół', { exact: true }).waitFor();
    await person.locator('option[value="20"]').waitFor({ state: 'attached' });
    await person.selectOption('20'); await status.selectOption('approved'); await expectIds([201]);
    await page.getByLabel('Zespół', { exact: true }).selectOption('1');
    await expectIds([101, 102, 103, 104, 105, 106, 110, 111]);
    assert.equal(await person.inputValue(), ''); assert.equal(await status.inputValue(), '', 'Changing team clears incompatible person/status filters.');
    assert.equal(await person.locator('option[value="20"]').count(), 0);
    await page.getByLabel('Zespół', { exact: true }).selectOption('2'); await expectIds([201]);
    assert.equal(await person.locator('option[value="4"]').count(), 0);
    await group(201).getByRole('button', { name: 'Szczegóły wniosku 201', exact: true }).click();
    dialog = page.getByRole('dialog', { name: 'Szczegóły nieobecności', exact: true });
    await dialog.getByText('Bartosz Drugi-Zespół', { exact: true }).first().waitFor();
    await page.keyboard.press('Escape'); await dialog.waitFor({ state: 'detached' });
    assert.ok(calendarCalls.some(call => call.team === '2' && call.from === today && call.to === '2027-01-08'), 'Team choice scopes the two-month API feed as well.');

    step = 'mobile filters and details, session revocation';
    await page.setViewportSize({ width: 390, height: 844 }); await page.reload();
    await page.locator('.mobile-calendar-day').first().waitFor();
    assert.equal(await page.locator('.mobile-calendar-day').count(), 7);
    await page.getByRole('button', { name: '2 tygodnie', exact: true }).click();
    await until(async () => await page.locator('.mobile-calendar-day').count() === 14, 'Mobile period switch expands to fourteen days.');
    await person.selectOption('6'); await status.selectOption('changes'); await expectIds([105]);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
    await page.screenshot({ path: join(screenshotDir, 'calendar-filters-mobile.png'), fullPage: true });
    await group(105).getByRole('button', { name: 'Szczegóły wniosku 105', exact: true }).click();
    dialog = page.getByRole('dialog', { name: 'Szczegóły nieobecności', exact: true });
    await dialog.getByRole('heading', { name: 'Obowiązujący urlop', exact: true }).waitFor();
    assert.match(await dialog.locator('.requests-explanation').innerText(), /Urlop nadal obowiązuje/);
    await dialog.locator('.requests-history > summary').click();
    assert.equal(await dialog.locator('.requests-history li').count(), 2);
    assert.equal(await dialog.evaluate(element => element.scrollWidth <= element.clientWidth + 1), true);
    await page.screenshot({ path: join(screenshotDir, 'calendar-details-mobile.png'), fullPage: true });
    session = null; await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await page.waitForURL(url => url.pathname === '/login');
    await page.getByRole('heading', { name: 'Zaloguj się', exact: true }).waitFor();
    assert.equal(await page.getByText(privateType, { exact: true }).count(), 0);
    assert.equal(await page.evaluate(() => localStorage.length + sessionStorage.length), 0);
    assert.deepEqual(errors, [], 'No unhandled browser exceptions.');
    console.log('PASS Chrome calendar UI: combined person/status filters in both date windows, grouped effective/proposed versions without summing, hidden-change projection, empty/reset, history/upcoming people, preserved period/filter context, employee/manager/deputy/admin details, server denial and fresh focus reauthorization, team scoping, mobile layout and revoked session. API responses are synthetic.');
    await context.close();
  } catch (error) {
    if (page) await page.screenshot({ path: join(screenshotDir, 'calendar-failure.png'), fullPage: true }).catch(() => {});
    throw error;
  } finally { await browser.close(); }
})().catch(error => { console.error(`Calendar browser check failed at: ${step}`); console.error(error); process.exitCode = 1; });
