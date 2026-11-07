const assert = require('node:assert/strict');
const { mkdirSync } = require('node:fs');
const { join } = require('node:path');
const { chromium } = require('playwright-core');

// Synthetic API: checks actual browser form behavior, not PHP accounting correctness.
const baseUrl = (process.env.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const screenshotDir = process.env.UI_SCREENSHOT_DIR || '/tmp/urlopy-ui-screens';
mkdirSync(screenshotDir, { recursive: true });
const year = Number(new Intl.DateTimeFormat('en', { timeZone: 'Europe/Warsaw', year: 'numeric' }).format(new Date()));
const admin = { id: 1, firstName: 'Adam', lastName: 'Testowy', email: 'admin@example.test', isAdministrator: true, mustChangePassword: false, team: { id: 1, name: 'IT', code: 'it' }, role: 'manager' };
const employee = { ...admin, id: 2, firstName: 'Anna', lastName: 'Testowa', email: 'anna@example.test', isAdministrator: false, role: 'employee' };
const manager = { ...admin, isAdministrator: false };
const employmentType = { id: 1, name: 'UoP', carriesUnusedHours: true };
const balances = new Map();
let opening = null;
let revision = 1;
let used = 0;
function balanceFor(id, selectedYear) {
  const annualHours = balances.get(`${id}:${selectedYear}`) ?? null;
  const currentOpening = id === 2 ? opening : null;
  const openingUsed = currentOpening && selectedYear === year ? used : 0;
  const carry = currentOpening && selectedYear === year ? currentOpening.carries.reduce((sum, item) => sum + item.remainingHours, 0) : 0;
  const availableHours = (annualHours ?? 0) + carry - openingUsed;
  return { userId: id, year: selectedYear, version: String(revision), configured: annualHours !== null, annualHours, opening: currentOpening, carriedHours: carry, allocatedHours: (annualHours ?? 0) + carry, openingUsedHours: openingUsed, usedHours: 0, approvedFutureHours: 0, reservedHours: 0, changeReservedHours: 0, availableHours, deficitHours: Math.max(0, -availableHours), displayAvailable: `${availableHours} h`, pools: currentOpening ? [{ originYear: selectedYear, allocatedHours: annualHours ?? 0, reservedHours: 0, chargedHours: openingUsed, availableHours: (annualHours ?? 0) - openingUsed, eligible: true }] : [], history: [] };
}

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  try {
    const context = await browser.newContext({ viewport: { width: 1366, height: 1000 } });
    let session = null;
    let openingWrites = 0;
    let staleLimit = false;
    let staleCalendar = false;
    let applyWrites = 0;
    let calendarReads = 0;
    const errors = [];
    const operationKeys = [];
    const holidays = [{ date: `${year}-12-24`, name: 'Wigilia', source: 'statutory', active: true }];
    await context.route('**/api/**', async route => {
      const request = route.request();
      const path = new URL(request.url()).pathname;
      const method = request.method();
      const reply = (status, data) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });
      const error = (status, code) => reply(status, { error: { code } });
      if (path === '/api/auth/csrf') return reply(200, { csrfToken: 'synthetic-balance-csrf' });
      if (!session) return error(401, 'AUTH_REQUIRED');
      if (path === '/api/auth/me') return reply(200, { user: session });
      if (path === '/api/balances/people') {
        const people = (session.role === 'employee' ? [employee] : [admin, employee]).map(person => ({ ...person, active: true, employmentType, canManage: session.isAdministrator || session.role === 'manager' && session.id !== person.id }));
        return reply(200, { people, currentYear: year, today: `${year}-06-15`, isAdministrator: session.isAdministrator });
      }
      let match = path.match(/^\/api\/balances\/users\/(\d+)\/(\d+)$/);
      if (method === 'GET' && match) return reply(200, { balance: balanceFor(Number(match[1]), Number(match[2])) });
      match = path.match(/^\/api\/calendar\/(\d+)$/);
      if (method === 'GET' && match) { calendarReads++; return reply(200, { year: Number(match[1]), timezone: 'Europe/Warsaw', hoursPerDay: 8, minimumYear: 2025, maximumYear: 2100, holidays }); }
      assert.equal(method, 'POST', `Unexpected synthetic API request: ${method} ${path}`);
      assert.equal(request.headers()['x-csrf-token'], 'synthetic-balance-csrf', `Missing CSRF header: ${method} ${path}`);
      const data = request.postDataJSON();
      if (path.startsWith('/api/balances/') && method === 'POST') {
        operationKeys.push(data.operationKey);
        assert.equal(typeof data.operationKey, 'string');
        assert.equal(data.expectedVersion, String(revision));
      }
      if (path === '/api/balances/users/2/opening') {
        openingWrites++;
        assert.equal(data.annualHours, 208, '26 days must be sent as 208 integer hours.');
        assert.equal(data.currentYearUsedHours, 8);
        assert.deepEqual(data.carries, [{ originYear: year - 1, remainingHours: 16 }]);
        opening = { effectiveDate: data.effectiveDate, annualHours: data.annualHours, currentYearUsedHours: data.currentYearUsedHours, carries: data.carries };
        used = data.currentYearUsedHours; balances.set(`2:${year}`, data.annualHours); revision++;
        await new Promise(resolve => setTimeout(resolve, 120));
        return reply(200, { balance: balanceFor(2, year) });
      }
      if (path === '/api/balances/users/2/opening/corrections') {
        assert.equal(data.currentYearUsedHours, 12);
        assert.equal(data.reason, 'Korekta wykorzystania');
        used = data.currentYearUsedHours; opening = { ...opening, currentYearUsedHours: used, carries: data.carries }; revision++;
        return reply(200, { balance: balanceFor(2, year) });
      }
      match = path.match(/^\/api\/balances\/users\/2\/(\d+)\/limit$/);
      if (match) {
        if (staleLimit) { staleLimit = false; return error(409, 'CONFLICT'); }
        assert.equal(Number.isInteger(data.annualHours), true);
        balances.set(`2:${match[1]}`, data.annualHours); revision++;
        return reply(200, { balance: balanceFor(2, Number(match[1])) });
      }
      if (path === '/api/calendar/preview') return reply(200, { ...data, previewToken: 'synthetic-preview', changedRequests: 1, impacts: [{ requestId: 5, reference: 'TEST-5', userId: 2, employeeName: 'Anna Testowa', status: 'approved', beforeHours: 8, afterHours: data.active ? 0 : 16, beforePendingHours: 0, afterPendingHours: 0, action: data.active ? 'Anulowanie urlopu' : 'Przeliczenie godzin' }] });
      if (path === '/api/calendar/apply') {
        applyWrites++;
        assert.equal(data.previewToken, 'synthetic-preview');
        assert.equal(data.reason, 'Dzień firmowy');
        assert.equal(typeof data.operationKey, 'string');
        if (staleCalendar) { staleCalendar = false; return error(409, 'CONFLICT'); }
        holidays.push({ date: data.date, name: data.name, source: 'company', active: data.active });
        return reply(200, { applied: true });
      }
      return error(404, 'NOT_FOUND');
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${baseUrl}/balances`);
    await page.waitForURL(url => url.pathname === '/login');
    // The URL commits before the login screen finishes checking its session.
    // Keep this session anonymous until that check settles; otherwise the login
    // screen can redirect to the home page while the next navigation is starting.
    await page.getByRole('heading', { name: 'Zaloguj się', exact: true }).waitFor();
    session = admin;
    await page.goto(`${baseUrl}/balances`);
    await page.getByLabel('Osoba', { exact: true }).selectOption('2');
    await page.getByRole('button', { name: 'Ustaw dane początkowe', exact: true }).click();
    await page.getByLabel('Jednostka limitu').selectOption('days');
    await page.getByLabel('Limit roku rozpoczęcia', { exact: true }).fill('26');
    await page.getByText('Zostanie zapisane: 208 h = 26 dni.').waitFor();
    await page.getByLabel('Wykorzystane z bieżącej puli przed startem (h)', { exact: true }).fill('8');
    await page.getByRole('button', { name: 'Dodaj rok zaległości' }).click();
    await page.getByLabel('Pozostałe godziny 1').fill('16');
    await page.getByRole('button', { name: 'Zapisz dane początkowe', exact: true }).dblclick({ force: true });
    await page.getByRole('status').filter({ hasText: 'Zapisano dane początkowe.' }).waitFor();
    assert.equal(openingWrites, 1, 'Double click must not create duplicate opening transactions.');
    await page.getByRole('button', { name: 'Koryguj dane początkowe' }).click();
    await page.getByLabel('Wykorzystane z bieżącej puli przed startem (h)', { exact: true }).fill('12');
    await page.getByLabel('Uzasadnienie zmiany', { exact: true }).fill('Korekta wykorzystania');
    await page.getByRole('button', { name: 'Zapisz korektę', exact: true }).click();
    await page.getByRole('status').filter({ hasText: 'Zapisano korektę' }).waitFor();
    await page.getByLabel('Rok salda').fill(String(year + 1));
    await page.getByRole('button', { name: 'Pokaż saldo', exact: true }).click();
    await page.getByText(`Limit na rok ${year + 1} nie został jeszcze ustawiony. Brak limitu nie oznacza limitu 0 godzin.`).waitFor();
    await page.getByRole('button', { name: 'Ustaw limit roczny', exact: true }).click();
    await page.getByLabel(`Limit na rok ${year + 1}`, { exact: true }).fill('0');
    await page.getByRole('button', { name: 'Zapisz limit', exact: true }).click();
    await page.getByRole('status').filter({ hasText: 'Zapisano limit roczny.' }).waitFor();
    await page.getByText('Limit ustawiony', { exact: true }).waitFor();
    assert.equal(await page.getByText('Nie ustawiono', { exact: true }).count(), 0, 'Zero must be shown as configured, not missing.');
    await page.getByRole('button', { name: 'Koryguj dane początkowe' }).click();
    await page.getByRole('heading', { name: `Anna Testowa · ${year}`, exact: true }).waitFor();
    assert.equal(await page.getByLabel('Wykorzystane z bieżącej puli przed startem (h)', { exact: true }).inputValue(), '12', 'Correction must start from latest corrected values.');
    await page.getByRole('button', { name: 'Zamknij formularz' }).click();
    await page.getByRole('button', { name: 'Zmień limit roczny' }).click();
    await page.getByLabel(`Limit na rok ${year}`, { exact: true }).fill('0');
    await page.getByLabel('Uzasadnienie zmiany', { exact: true }).fill('Korekta limitu');
    staleLimit = true;
    await page.getByRole('button', { name: 'Zapisz limit', exact: true }).click();
    await page.getByRole('alert').filter({ hasText: 'Dane zmieniły się' }).waitFor();
    await page.getByRole('button', { name: 'Zapisz limit', exact: true }).click();
    await page.getByRole('status').filter({ hasText: 'Zapisano limit roczny.' }).waitFor();
    assert.equal(operationKeys.at(-1), operationKeys.at(-2), 'Unchanged retry reuses operation key.');
    await page.screenshot({ path: join(screenshotDir, 'balance-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true, 'Mobile page must not overflow horizontally.');
    await page.screenshot({ path: join(screenshotDir, 'balance-mobile.png'), fullPage: true });
    session = manager;
    await page.goto(`${baseUrl}/balances`);
    await page.getByText('Twój limit i dane początkowe zmienia administrator.').waitFor();
    assert.equal(await page.getByRole('button', { name: 'Ustaw dane początkowe', exact: true }).count(), 0);
    session = { ...manager, role: 'deputy' };
    await page.goto(`${baseUrl}/balances`);
    await page.getByLabel('Osoba', { exact: true }).selectOption('2');
    await page.getByText('Masz dostęp do odczytu. Limity ustawia kierownik lub administrator.').waitFor();
    assert.equal(await page.getByRole('button', { name: 'Zmień limit roczny' }).count(), 0);
    const beforeDenied = calendarReads;
    await page.goto(`${baseUrl}/calendar-settings`);
    await page.getByRole('heading', { name: 'Brak dostępu', exact: true }).waitFor();
    assert.equal(calendarReads, beforeDenied, 'Non-admin must not load company calendar management.');
    session = admin;
    await page.goto(`${baseUrl}/calendar-settings`);
    await page.getByLabel('Data dnia firmowego').fill(`${year + 1}-01-14`);
    await page.getByLabel('Nazwa dnia firmowego').fill('Dzień zespołu');
    await page.getByRole('button', { name: 'Sprawdź wpływ zmiany' }).click();
    await page.getByRole('heading', { name: 'Podgląd zmiany', exact: true }).waitFor();
    await page.getByText('Anulowanie urlopu', { exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'Zatwierdź zmianę i korekty' }).isDisabled(), true);
    await page.getByLabel('Uzasadnienie zmiany kalendarza').fill('Dzień firmowy');
    await page.getByLabel('Potwierdzam zmianę dnia firmowego oraz wszystkie pokazane korekty wniosków.').check();
    staleCalendar = true;
    await page.getByRole('button', { name: 'Zatwierdź zmianę i korekty' }).click();
    await page.getByRole('alert').filter({ hasText: 'Dane zmieniły się' }).waitFor();
    assert.equal(await page.getByRole('heading', { name: 'Podgląd zmiany', exact: true }).count(), 0, 'Conflict invalidates preview.');
    await page.getByRole('button', { name: 'Sprawdź wpływ zmiany' }).click();
    await page.getByRole('heading', { name: 'Podgląd zmiany', exact: true }).waitFor();
    await page.getByLabel('Potwierdzam zmianę dnia firmowego oraz wszystkie pokazane korekty wniosków.').check();
    await page.screenshot({ path: join(screenshotDir, 'calendar-preview-mobile.png'), fullPage: true });
    await page.getByRole('button', { name: 'Zatwierdź zmianę i korekty' }).click();
    await page.getByRole('status').filter({ hasText: 'Zapisano dzień firmowy' }).waitFor();
    assert.equal(applyWrites, 2);
    await page.getByRole('button', { name: `Edytuj dzień 14.01.${year + 1}` }).click();
    await page.getByLabel('Dzień wolny od pracy', { exact: false }).uncheck();
    await page.getByRole('button', { name: 'Sprawdź wpływ zmiany' }).click();
    await page.getByText('Przeliczenie godzin', { exact: true }).waitFor();
    await page.getByLabel('Nazwa dnia firmowego').fill('Nowa nazwa');
    assert.equal(await page.getByRole('heading', { name: 'Podgląd zmiany', exact: true }).count(), 0, 'Editing input invalidates preview.');
    session = employee;
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await page.getByRole('heading', { name: 'Brak dostępu', exact: true }).waitFor();
    assert.equal(await page.getByText('Anna Testowa', { exact: true }).count(), 0, 'Privilege loss removes private preview.');
    assert.deepEqual(errors, [], 'No unhandled browser errors.');
    assert.equal(await page.evaluate(() => localStorage.length + sessionStorage.length), 0);
    console.log('PASS Chrome accounting UI: scoped views, integer day conversion, opening, correction from latest values, zero versus missing, conflict/retry idempotency, own manager/deputy readonly, calendar preview confirmation/cancellation/stale refresh, edit invalidation, revocation, desktop/mobile screenshots. API synthetic; accounting correctness tested separately.');
    await context.close();
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
