'use strict';

// Real browser → nginx → PHP/MySQL. No route interception or fabricated API responses.
// Only disposable CI accounts are created. Never log subprocess output or credentials.
const { execFileSync } = require('node:child_process');
const { randomUUID } = require('node:crypto');
const { resolve } = require('node:path');
const { chromium } = require('playwright-core');

let step = 'development-only guards';
class SmokeAssertionError extends Error {}
function check(condition, description) {
  if (!condition) throw new SmokeAssertionError(description);
}

async function main() {
  check(process.env.CI === 'true', 'CI=true is required');
  const base = new URL(process.env.BASE_URL || 'http://127.0.0.1:8080');
  check(base.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(base.hostname)
    && !base.username && !base.password && base.pathname === '/' && !base.search && !base.hash,
  'Only the local development HTTP server is supported');

  step = 'isolated synthetic employee and manager fixture';
  const fixture = JSON.parse(execFileSync('docker', [
    'compose', 'exec', '-T', '-e', 'CI=true', '-e', 'REQUEST_UI_FIXTURE=ALLOW_SYNTHETIC_CI',
    'php', 'php', 'tests/Support/request-ui-fixture.php',
  ], { cwd: resolve(__dirname, '../..'), encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 30000, maxBuffer: 65536 }));
  for (const name of ['employee', 'manager']) {
    const account = fixture[name];
    check(account && Number.isInteger(account.id) && account.id > 0
      && /^request_ui_[a-f0-9]{16}_(employee|manager)@example\.test$/.test(account.email)
      && /^[a-f0-9]{48}$/.test(account.password), 'Unexpected synthetic account fixture');
  }
  check(fixture.employee.id !== fixture.manager.id && Number.isInteger(fixture.absenceTypeId)
    && /^\d{4}-\d{2}-\d{2}$/.test(fixture.date) && /^\d{4}-\d{2}-\d{2}$/.test(fixture.changeDate)
    && fixture.date < fixture.changeDate && fixture.date.slice(0, 4) === String(fixture.year)
    && fixture.changeDate.slice(0, 4) === String(fixture.year) && fixture.timezone === 'Europe/Warsaw',
  'Unexpected synthetic working dates');

  step = 'Chrome launch';
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  try {
    const context = await browser.newContext({ baseURL: base.href, viewport: { width: 1440, height: 1050 }, timezoneId: 'Europe/Warsaw' });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    let pageErrors = 0;
    page.on('pageerror', () => { ++pageErrors; });
    async function json(response, status = 200) {
      check(response.status() === status, `Expected HTTP ${status}, received ${response.status()}`);
      check(response.headers()['cache-control']?.includes('no-store'), 'Missing no-store API response');
      return response.json();
    }
    async function get(client, path) { return json(await client.get(path)); }
    async function post(client, path, data) {
      const { csrfToken } = await get(client, '/api/auth/csrf');
      check(typeof csrfToken === 'string' && csrfToken.length > 0, 'Missing CSRF token');
      return json(await client.post(path, { data, headers: { 'X-CSRF-Token': csrfToken } }));
    }
    function responseFor(path, matches = () => true) {
      return page.waitForResponse(response => new URL(response.url()).pathname === path
        && response.request().method() === 'POST' && matches(response.request().postDataJSON()));
    }
    async function showPreview(dialog, hours) {
      await dialog.getByRole('status', { name: 'Podsumowanie wniosku', exact: true })
        .getByText(`${hours} h`, { exact: true }).first().waitFor();
    }
    const balancePath = `/api/balances/users/${fixture.employee.id}/${fixture.year}`;

    step = 'real employee login and persisted session';
    await page.goto('/login');
    await page.getByLabel('Służbowy adres e-mail', { exact: true }).fill(fixture.employee.email);
    await page.getByLabel('Hasło', { exact: true }).fill(fixture.employee.password);
    await page.getByRole('button', { name: 'Zaloguj się', exact: true }).click();
    await page.waitForURL(base.href);
    check((await get(context.request, '/api/auth/me')).user?.id === fixture.employee.id, 'Employee session did not persist');
    await page.goto('/requests');
    await page.getByRole('heading', { name: 'Moje wnioski', exact: true }).waitFor();
    const before = (await get(context.request, balancePath)).balance;
    check(before.availableHours === 80 && before.reservedHours === 0, 'Isolated initial balance must equal 80 hours');

    step = 'default full-day form and real eight-hour preview';
    await page.getByRole('button', { name: 'Złóż wniosek', exact: true }).first().click();
    let dialog = page.getByRole('dialog', { name: 'Nowy wniosek', exact: true });
    await dialog.getByLabel('Rodzaj nieobecności', { exact: true }).selectOption(String(fixture.absenceTypeId));
    check(await dialog.getByRole('radio', { name: /Całe dni/ }).isChecked(), 'Full days must be the default');
    const fullResponse = responseFor('/api/requests/preview', body => body.mode === 'full_days'
      && body.startDate === fixture.date && body.endDate === fixture.date);
    await dialog.getByLabel('Data od', { exact: true }).fill(fixture.date);
    await dialog.getByLabel('Data do', { exact: true }).fill(fixture.date);
    const full = (await json(await fullResponse)).preview;
    check(full.totalHours === 8 && full.days.length === 1 && full.days[0].date === fixture.date
      && full.years[0].beforeAvailableHours === 80 && full.years[0].afterAvailableHours === 72, 'Full-day preview must calculate eight hours');
    await showPreview(dialog, 8);

    step = 'three-hour preview without a balance mutation';
    const hourlyResponse = responseFor('/api/requests/preview', body => body.mode === 'hourly'
      && body.hours === 3 && body.startDate === fixture.date && body.endDate === fixture.date);
    await dialog.getByRole('radio', { name: /Część dnia/ }).check();
    await dialog.getByLabel('Liczba godzin', { exact: true }).selectOption('3');
    const hourly = (await json(await hourlyResponse)).preview;
    check(hourly.totalHours === 3 && hourly.days[0].hours === 3 && hourly.timezone === 'Europe/Warsaw'
      && hourly.years[0].afterAvailableHours === 77, 'Hourly preview must calculate three hours');
    await showPreview(dialog, 3);
    check(JSON.stringify((await get(context.request, balancePath)).balance) === JSON.stringify(before), 'Form previews changed persisted balance or audit history');
    check((await get(context.request, '/api/requests?scope=mine')).requests.length === 0, 'Form preview created a stored request');

    step = 'form submission and persisted request after page reload';
    const creationResponse = responseFor('/api/requests');
    await dialog.getByRole('button', { name: 'Złóż wniosek', exact: true }).click();
    const created = (await json(await creationResponse, 201)).operation;
    check(Number.isInteger(created?.id) && created.status === 'pending', 'Form did not create a pending request');
    await dialog.waitFor({ state: 'detached' });
    const detailsButton = page.getByRole('button', { name: `Szczegóły wniosku ${created.id}`, exact: true });
    await detailsButton.waitFor();
    await page.reload();
    await detailsButton.waitFor();
    await detailsButton.click();
    await page.getByRole('heading', { name: 'Szczegóły wniosku', exact: true }).waitFor();
    let detail = (await get(context.request, `/api/requests/${created.id}`)).request;
    check(detail.status === 'pending' && detail.pending?.totalHours === 3
      && detail.pending.startDate === fixture.date && detail.pending.endDate === fixture.date
      && detail.history.length === 1, 'Reload lost the submitted three-hour request');
    let balance = (await get(context.request, balancePath)).balance;
    check(balance.availableHours === 77 && balance.reservedHours === 3 && balance.approvedFutureHours === 0, 'Submission must reserve exactly three hours');

    step = 'manager decision in a separate real session';
    const manager = await browser.newContext({ baseURL: base.href });
    const login = await post(manager.request, '/api/auth/login', { email: fixture.manager.email, password: fixture.manager.password });
    check(login.user?.id === fixture.manager.id, 'Separate manager login failed');
    const approved = (await post(manager.request, `/api/requests/${created.id}/approve`, { operationKey: randomUUID(), expectedVersion: detail.version })).operation;
    check(approved.status === 'approved', 'Manager did not approve the request');
    await page.reload();
    await page.getByRole('button', { name: 'Zmień termin lub wymiar', exact: true }).waitFor();
    const details = page.getByRole('region', { name: 'Szczegóły wniosku', exact: true });
    await details.getByText('Zaakceptowany', { exact: true }).waitFor();
    detail = (await get(context.request, `/api/requests/${created.id}`)).request;
    balance = (await get(context.request, balancePath)).balance;
    check(detail.status === 'approved' && detail.effective.totalHours === 3 && detail.pending === null
      && balance.availableHours === 77 && balance.reservedHours === 0 && balance.approvedFutureHours === 3,
    'Approved read must retain the leave and consume only the original three hours');

    step = 'real change form, additional reservation and retained approved leave';
    await page.getByRole('button', { name: 'Zmień termin lub wymiar', exact: true }).click();
    dialog = page.getByRole('dialog', { name: 'Zmień termin lub wymiar', exact: true });
    const changePreviewResponse = responseFor(`/api/requests/${created.id}/change-preview`, body => body.hours === 5 && body.startDate === fixture.changeDate);
    await dialog.getByLabel('Data', { exact: true }).fill(fixture.changeDate);
    await dialog.getByLabel('Liczba godzin', { exact: true }).selectOption('5');
    const changePreview = (await json(await changePreviewResponse)).preview;
    check(changePreview.totalHours === 5 && changePreview.years[0].beforeAvailableHours === 77
      && changePreview.years[0].afterAvailableHours === 75 && changePreview.years[0].additionalReservedHours === 2,
    'Change preview must reserve only the additional two hours');
    await showPreview(dialog, 5);
    const changeResponse = responseFor(`/api/requests/${created.id}/change`);
    await dialog.getByRole('button', { name: 'Wyślij propozycję', exact: true }).click();
    check((await json(await changeResponse)).operation.status === 'change_pending', 'Change form did not create a pending proposal');
    await dialog.waitFor({ state: 'detached' });
    await page.getByRole('button', { name: 'Wycofaj propozycję', exact: true }).waitFor();
    await page.reload();
    await page.getByRole('button', { name: 'Wycofaj propozycję', exact: true }).waitFor();
    await page.getByRole('heading', { name: 'Obowiązujący urlop', exact: true }).waitFor();
    await page.getByRole('heading', { name: 'Proponowana zmiana', exact: true }).waitFor();
    detail = (await get(context.request, `/api/requests/${created.id}`)).request;
    balance = (await get(context.request, balancePath)).balance;
    check(detail.status === 'change_pending' && detail.effective.startDate === fixture.date && detail.effective.totalHours === 3
      && detail.pending.startDate === fixture.changeDate && detail.pending.totalHours === 5 && detail.history.length === 2
      && balance.availableHours === 75 && balance.changeReservedHours === 2 && balance.approvedFutureHours === 3,
    'Pending change must preserve the approved leave and reserve only its increase');

    step = 'employee withdraws proposal and retains the approved leave';
    await page.getByRole('button', { name: 'Wycofaj propozycję', exact: true }).click();
    dialog = page.getByRole('dialog', { name: 'Wycofaj propozycję', exact: true });
    const withdrawResponse = responseFor(`/api/requests/${created.id}/withdraw`);
    await dialog.getByRole('button', { name: 'Potwierdź wycofanie', exact: true }).click();
    check((await json(await withdrawResponse)).operation.status === 'approved', 'Proposal withdrawal did not restore approved status');
    await dialog.waitFor({ state: 'detached' });
    await page.getByRole('button', { name: 'Zmień termin lub wymiar', exact: true }).waitFor();
    detail = (await get(context.request, `/api/requests/${created.id}`)).request;
    balance = (await get(context.request, balancePath)).balance;
    check(detail.status === 'approved' && detail.effective.startDate === fixture.date && detail.effective.totalHours === 3
      && detail.pending === null && balance.availableHours === 77 && balance.changeReservedHours === 0,
    'Withdrawal must release only the additional reservation');
    check(pageErrors === 0, 'Browser reported an application error');
    await manager.close();
    await context.close();
    console.log('Real request E2E passed: employee login, 8h default, 3h preview and submission, preview rollback, reload persistence, separate manager approval, approved read, 5h change with 2h reservation and proposal withdrawal.');
  } finally { await browser.close(); }
}

main().catch(error => {
  // Playwright and child-process errors can include filled passwords or captured JSON.
  console.error(`Real request E2E failed at: ${step}. Browser details and subprocess output suppressed.`);
  if (error instanceof SmokeAssertionError) console.error(error.message);
  process.exitCode = 1;
});
