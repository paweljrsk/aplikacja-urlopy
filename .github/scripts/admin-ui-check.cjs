const assert = require('node:assert/strict');
const { mkdirSync } = require('node:fs');
const { join } = require('node:path');
const { chromium } = require('playwright-core');

// Browser interaction smoke tests with an explicitly synthetic API. Real authorization,
// persistence and concurrency are tested separately against PHP/MySQL.
const baseUrl = (process.env.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const screenshotDir = process.env.UI_SCREENSHOT_DIR || '/tmp/urlopy-ui-screens';
mkdirSync(screenshotDir, { recursive: true });
const roles = [{ value: 'employee', label: 'Pracownik' }, { value: 'manager', label: 'Kierownik' }, { value: 'deputy', label: 'Zastępca' }];
const admin = { id: 1, firstName: 'Paweł', lastName: 'Testowy', email: 'admin@example.test', isAdministrator: true, mustChangePassword: false, team: null, role: null, active: true, version: 1, employmentType: null, assignments: [] };

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  try {
    const context = await browser.newContext({ viewport: { width: 1366, height: 1000 } });
    let session = null;
    let conflictOnce = false;
    let createCount = 0;
    let adminReads = 0;
    const mutations = [];
    const errors = [];
    const catalog = { teams: [], employmentTypes: [], absenceTypes: [], roles };
    const users = [structuredClone(admin)];
    await context.route('**/api/**', async route => {
      const request = route.request();
      const path = new URL(request.url()).pathname;
      const method = request.method();
      const reply = (status, data) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });
      const error = (status, code) => reply(status, { error: { code, message: 'Synthetic response' } });
      if (path === '/api/auth/csrf') return reply(200, { csrfToken: 'synthetic-csrf' });
      if (!session) return error(401, 'AUTH_REQUIRED');
      if (path === '/api/auth/me') return reply(200, { user: session });
      if (method !== 'GET') {
        assert.equal(request.headers()['x-csrf-token'], 'synthetic-csrf');
        mutations.push({ path, method });
      }
      if (path === '/api/auth/logout') { session = null; return reply(200, { status: 'logged_out' }); }
      if (!session.isAdministrator) return error(403, 'FORBIDDEN');
      if (method === 'GET') {
        adminReads++;
        if (path === '/api/admin/catalog') return reply(200, catalog);
        if (path === '/api/admin/users') return reply(200, { users });
      }
      const data = request.postDataJSON();
      if (path === '/api/admin/catalog/initialize') {
        catalog.employmentTypes = [
          { id: 1, code: 'uop', name: 'Umowa o pracę', active: true, version: 1, carriesUnusedHours: true },
          { id: 2, code: 'b2b', name: 'B2B', active: true, version: 1, carriesUnusedHours: false },
        ];
        catalog.absenceTypes = [
          { id: 1, code: 'paid', name: 'Płatne dni wolne', active: true, version: 1, revision: 1, deductsBalance: true, employmentTypeIds: [1, 2], namesByEmploymentType: { '1': 'Urlop wypoczynkowy' } },
          { id: 2, code: 'unpaid', name: 'Bezpłatne dni wolne', active: true, version: 1, revision: 1, deductsBalance: false, employmentTypeIds: [1, 2], namesByEmploymentType: {} },
          { id: 3, code: 'other', name: 'Inna nieobecność', active: true, version: 1, revision: 1, deductsBalance: false, employmentTypeIds: [1, 2], namesByEmploymentType: {} },
        ];
        return reply(200, catalog);
      }
      if (path === '/api/admin/teams' && method === 'POST') {
        const item = { ...data, id: 1, version: 1 }; catalog.teams.push(item); return reply(201, { item });
      }
      if (path === '/api/admin/users' && method === 'POST') {
        createCount++;
        assert.equal(typeof data.isAdministrator, 'boolean');
        assert.equal(typeof data.teamId, 'number');
        assert.equal(data.adminPassword, 'Synthetic administrator password');
        const team = catalog.teams.find(item => item.id === data.teamId);
        const employmentType = catalog.employmentTypes.find(item => item.id === data.employmentTypeId);
        const user = { id: 2, firstName: data.firstName, lastName: data.lastName, email: data.email, isAdministrator: data.isAdministrator, mustChangePassword: true, active: true, version: 1, team, employmentType, role: data.role, assignments: [{ id: 1, team, employmentType, role: data.role, validFrom: data.validFrom, validUntil: null }] };
        users.push(user);
        await new Promise(resolve => setTimeout(resolve, 100));
        return reply(201, { user, temporaryPassword: 'SyntheticGeneratedTemporaryPassword', expiresAt: '2030-01-01T12:00:00+00:00' });
      }
      if (path === '/api/admin/users/2' && method === 'PATCH') {
        if (conflictOnce) { conflictOnce = false; return error(409, 'CONFLICT'); }
        assert.equal(data.version, users[1].version);
        const { adminPassword, version, ...values } = data;
        assert.equal(adminPassword, 'Synthetic administrator password');
        Object.assign(users[1], values, { version: version + 1 });
        return reply(200, { user: users[1] });
      }
      if (path === '/api/admin/users/2/assignments') {
        assert.equal(data.version, users[1].version);
        assert.equal(data.role, 'deputy');
        const user = users[1];
        user.assignments[0].validUntil = data.validFrom;
        user.assignments.push({ id: 2, team: catalog.teams[0], employmentType: catalog.employmentTypes[1], role: data.role, validFrom: data.validFrom, validUntil: null });
        user.role = data.role; user.version++;
        return reply(200, { user });
      }
      if (path === '/api/admin/users/2/reset-password') return reply(200, { temporaryPassword: 'SyntheticResetTemporaryPassword', expiresAt: '2030-01-01T12:00:00+00:00' });
      if (path === '/api/admin/absence-types/1' && method === 'PATCH') {
        assert.equal(data.deductsBalance, false);
        assert.deepEqual(data.namesByEmploymentType, { '1': 'Urlop wypoczynkowy', '2': 'Płatna przerwa B2B' });
        Object.assign(catalog.absenceTypes[0], data, { version: 2, revision: 2 });
        return reply(200, { item: catalog.absenceTypes[0] });
      }
      return error(404, 'NOT_FOUND');
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${baseUrl}/admin`);
    await page.waitForURL(url => url.pathname === '/login');
    // A committed URL does not mean the redirected screen's session check is done.
    await page.getByRole('heading', { name: 'Zaloguj się', exact: true }).waitFor();
    session = { ...admin, isAdministrator: false };
    const beforeDenied = adminReads;
    await page.goto(`${baseUrl}/admin`);
    await page.getByRole('heading', { name: 'Brak dostępu' }).waitFor();
    assert.equal(adminReads, beforeDenied, 'A non-administrator should not load admin datasets.');
    session = { ...admin, mustChangePassword: true };
    await page.goto(`${baseUrl}/admin`);
    await page.waitForURL(url => url.pathname === '/change-password');
    await page.getByRole('heading', { name: 'Ustaw własne hasło', exact: true }).waitFor();
    session = admin;
    await page.goto(`${baseUrl}/admin`);
    await page.getByRole('button', { name: 'Dodaj słowniki startowe' }).click();
    await page.getByRole('status').filter({ hasText: 'Słowniki startowe są gotowe' }).waitFor();
    await page.getByRole('button', { name: 'Zespoły', exact: true }).click();
    await page.getByRole('button', { name: 'Dodaj zespół', exact: true }).click();
    await page.getByLabel('Kod', { exact: true }).fill('it');
    await page.getByLabel('Nazwa', { exact: true }).fill('Rozwój aplikacji');
    await page.getByRole('button', { name: 'Dodaj pozycję', exact: true }).click();
    await page.getByRole('status').filter({ hasText: 'Dodano pozycję' }).waitFor();
    await page.getByRole('button', { name: 'Użytkownicy', exact: true }).click();
    await page.getByRole('button', { name: 'Dodaj użytkownika', exact: true }).click();
    await page.getByLabel('Imię', { exact: true }).fill('Anna');
    await page.getByLabel('Nazwisko', { exact: true }).fill('Demonstracyjna');
    await page.getByLabel('Służbowy adres e-mail').fill('anna@example.test');
    await page.getByLabel('Zespół pracownika').selectOption('1');
    await page.getByLabel('Forma zatrudnienia pracownika').selectOption('2');
    await page.getByLabel('Twoje hasło administratora').fill('Synthetic administrator password');
    await page.getByRole('button', { name: 'Utwórz konto i hasło' }).dblclick({ force: true });
    await page.getByRole('heading', { name: 'Hasło tymczasowe gotowe' }).waitFor();
    assert.equal(createCount, 1, 'Double click must create only one request.');
    assert.equal(await page.getByLabel('Nowe hasło tymczasowe').getAttribute('type'), 'password');
    await page.getByRole('button', { name: 'Zamknij hasło' }).click();
    assert.equal(await page.getByLabel('Nowe hasło tymczasowe').count(), 0);
    await page.getByLabel('Szukaj', { exact: true }).fill('nieistniejący');
    await page.getByText('Brak pozycji spełniających wybrane kryteria.').waitFor();
    await page.getByLabel('Szukaj', { exact: true }).fill('');
    await page.getByRole('button', { name: 'Edytuj: Anna Demonstracyjna', exact: true }).click();
    conflictOnce = true;
    await page.getByLabel('Imię', { exact: true }).fill('Aneta');
    await page.getByLabel('Twoje hasło administratora').fill('Synthetic administrator password');
    await page.getByRole('button', { name: 'Zapisz dane użytkownika' }).click();
    await page.getByRole('alert').filter({ hasText: 'Stan konta zmienił się' }).waitFor();
    assert.equal(await page.getByLabel('Twoje hasło administratora').inputValue(), '');
    await page.getByRole('button', { name: 'Wczytaj aktualne dane' }).click();
    await page.getByRole('button', { name: 'Edytuj: Anna Demonstracyjna', exact: true }).click();
    await page.getByLabel('Konto aktywne').uncheck();
    assert.equal(await page.getByRole('button', { name: 'Zapisz dane użytkownika' }).isDisabled(), true);
    await page.getByLabel('Potwierdzam zmianę dostępu do konta').check();
    await page.getByLabel('Twoje hasło administratora').fill('Synthetic administrator password');
    await page.getByRole('button', { name: 'Zapisz dane użytkownika' }).click();
    await page.getByRole('status').filter({ hasText: 'Zapisano zmiany konta' }).waitFor();
    await page.getByLabel('Aktywność', { exact: true }).selectOption('inactive');
    assert.equal(await page.getByRole('button', { name: 'Edytuj: Paweł Testowy' }).count(), 0);
    await page.getByRole('button', { name: 'Edytuj: Anna Demonstracyjna', exact: true }).click();
    await page.getByLabel('Konto aktywne').check();
    await page.getByLabel('Twoje hasło administratora').fill('Synthetic administrator password');
    await page.getByRole('button', { name: 'Zapisz dane użytkownika' }).click();
    await page.getByRole('status').filter({ hasText: 'Zapisano zmiany konta' }).waitFor();
    await page.getByLabel('Aktywność', { exact: true }).selectOption('all');
    await page.getByRole('button', { name: 'Edytuj: Anna Demonstracyjna', exact: true }).click();
    await page.getByRole('button', { name: 'Zmień przypisanie', exact: true }).click();
    await page.getByLabel('Rola w zespole').selectOption('deputy');
    await page.getByLabel('Obowiązuje od').fill('2030-01-01');
    await page.getByLabel('Twoje hasło administratora').fill('Synthetic administrator password');
    await page.getByRole('button', { name: 'Zapisz przypisanie' }).click();
    await page.getByRole('status').filter({ hasText: 'Zapisano nowe przypisanie' }).waitFor();
    await page.getByRole('button', { name: 'Edytuj: Anna Demonstracyjna', exact: true }).click();
    await page.getByRole('heading', { name: 'Historia przypisań' }).waitFor();
    assert.equal(await page.locator('.assignment-history li').count(), 2);
    await page.screenshot({ path: join(screenshotDir, 'stage4-users-desktop.png'), fullPage: true });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await page.getByRole('button', { name: 'Reset hasła', exact: true }).click();
    await page.getByLabel('Twoje hasło administratora').fill('Synthetic administrator password');
    await page.getByRole('button', { name: 'Utwórz nowe hasło tymczasowe' }).click();
    await page.getByRole('heading', { name: 'Hasło tymczasowe gotowe' }).waitFor();
    await page.getByRole('button', { name: 'Rodzaje nieobecności', exact: true }).click();
    assert.equal(await page.getByLabel('Nowe hasło tymczasowe').count(), 0);
    await page.getByLabel('Forma zatrudnienia', { exact: true }).selectOption('2');
    await page.getByRole('button', { name: 'Edytuj: Płatne dni wolne', exact: true }).click();
    await page.getByLabel('Pomniejsza wspólne saldo płatnych dni').uncheck();
    await page.getByLabel('Nazwa dla: B2B').fill('Płatna przerwa B2B');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: join(screenshotDir, 'stage4-dictionary-mobile.png'), fullPage: true });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await page.getByRole('button', { name: 'Zapisz pozycję', exact: true }).click();
    await page.getByRole('status').filter({ hasText: 'Zapisano zmiany słownika' }).waitFor();
    assert.equal(await page.evaluate(() => localStorage.length + sessionStorage.length), 0);
    session = { ...admin, isAdministrator: false };
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await page.getByRole('heading', { name: 'Brak dostępu' }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'Edytuj: Płatne dni wolne' }).count(), 0);
    assert.equal(errors.length, 0, 'Browser reported a JavaScript exception.');
    console.log('PASS: admin/anonymous/temporary-password guards, startup dictionaries, team and account creation, duplicate-click prevention, one-time secret cleanup, search/active filters, conflict recovery, deactivate/reactivate, dated assignment history, password reset, absence rules/availability/names, CSRF, no browser storage, revoked admin clearing, desktop/mobile overflow.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
