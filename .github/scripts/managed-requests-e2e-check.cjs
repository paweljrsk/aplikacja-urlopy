'use strict';

// Real Chrome → nginx → PHP/MySQL, with isolated, explicitly guarded CI fixtures.
// No route interception. Never print credentials, subprocess output or Playwright traces.
const { execFileSync } = require('node:child_process');
const { resolve } = require('node:path');
const { randomUUID } = require('node:crypto');
const { chromium } = require('playwright-core');
let step = 'development-only guards';
class SmokeAssertionError extends Error {}
function check(condition, description) { if (!condition) throw new SmokeAssertionError(description); }

async function main() {
  check(process.env.CI === 'true', 'CI=true is required');
  const base = new URL(process.env.BASE_URL || 'http://127.0.0.1:8080');
  check(base.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(base.hostname)
    && !base.username && !base.password && base.pathname === '/' && !base.search && !base.hash,
  'Only the local development HTTP server is supported');
  step = 'isolated synthetic management fixture';
  const fixture = JSON.parse(execFileSync('docker', [
    'compose', 'exec', '-T', '-e', 'CI=true', '-e', 'MANAGED_REQUEST_UI_FIXTURE=ALLOW_SYNTHETIC_CI',
    'php', 'php', 'tests/Support/managed-request-ui-fixture.php',
  ], { cwd: resolve(__dirname, '../..'), encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 30000, maxBuffer: 65536 }));
  for (const name of ['employee', 'manager', 'deputy', 'administrator', 'otheremployee']) {
    const account = fixture[name];
    check(account && Number.isInteger(account.id) && account.id > 0
      && /^managed_ui_[a-f0-9]{16}_(employee|manager|deputy|administrator|otheremployee)@example\.test$/.test(account.email)
      && /^[a-f0-9]{48}$/.test(account.password), 'Unexpected synthetic fixture account');
  }
  check(Number.isInteger(fixture.historicalRequestId) && fixture.historicalRequestId > 0
    && Number.isInteger(fixture.absenceTypeId) && /^\d{4}-\d{2}-\d{2}$/.test(fixture.date)
    && /^\d{4}-\d{2}-\d{2}$/.test(fixture.pastDate) && fixture.pastDate < fixture.date
    && fixture.date.slice(0, 4) === String(fixture.year) && fixture.pastDate.slice(0, 4) === String(fixture.pastYear)
    && fixture.timezone === 'Europe/Warsaw', 'Unexpected synthetic working dates');
  for (const id of Object.values(fixture.requests)) check(Number.isInteger(id) && id > 0, 'Unexpected synthetic request identifier');

  step = 'Chrome launch';
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  try {
    async function json(response, expected = 200) {
      check(response.status() === expected, `Expected HTTP ${expected}, received ${response.status()}`);
      check(response.headers()['cache-control']?.includes('no-store'), 'Missing no-store API response');
      return response.json();
    }
    async function get(client, path) { return json(await client.get(path)); }
    async function post(client, path, data, expected = 200) {
      const { csrfToken } = await get(client, '/api/auth/csrf');
      check(typeof csrfToken === 'string' && csrfToken.length > 0, 'Missing CSRF token');
      return json(await client.post(path, { data, headers: { 'X-CSRF-Token': csrfToken } }), expected);
    }
    let pageErrors = 0;
    async function actorContext(name, loginByUI = false) {
      const context = await browser.newContext({ baseURL: base.href, viewport: { width: 1440, height: 1050 }, timezoneId: 'Europe/Warsaw' });
      const page = await context.newPage(); page.setDefaultTimeout(15000); page.on('pageerror', () => { ++pageErrors; });
      const account = fixture[name];
      if (loginByUI) {
        await page.goto('/login');
        await page.getByLabel('Służbowy adres e-mail', { exact: true }).fill(account.email);
        await page.getByLabel('Hasło', { exact: true }).fill(account.password);
        await page.getByRole('button', { name: 'Zaloguj się', exact: true }).click();
        await page.waitForURL(base.href);
      } else await post(context.request, '/api/auth/login', { email: account.email, password: account.password });
      check((await get(context.request, '/api/auth/me')).user.id === account.id, 'Actor session did not persist');
      return { context, page, client: context.request };
    }
    function responseFor(page, path) {
      return page.waitForResponse(response => new URL(response.url()).pathname === path && response.request().method() === 'POST');
    }
    async function open(page, id) {
      await page.goto(`/management/requests?id=${id}`);
      await page.getByRole('heading', { name: 'Obsługa wniosków', exact: true }).waitFor();
      await page.getByRole('heading', { name: 'Szczegóły wniosku', exact: true }).waitFor();
    }
    async function approve(actor, id, expectedHours) {
      await open(actor.page, id);
      await actor.page.getByRole('button', { name: 'Akceptuj', exact: true }).click();
      const dialog = actor.page.getByRole('dialog', { name: 'Akceptacja wniosku', exact: true });
      const previewResponse = responseFor(actor.page, `/api/requests/${id}/decision-preview`);
      await dialog.getByRole('button', { name: 'Sprawdź wpływ', exact: true }).click();
      const preview = (await json(await previewResponse)).preview;
      check(preview.id === id && preview.action === 'approve' && preview.statusAfter === 'approved'
        && Array.isArray(preview.years) && preview.years.some(year => year.beforeReservedHours >= expectedHours
          && year.afterReservedHours === year.beforeReservedHours - expectedHours
          && year.afterApprovedHours === year.beforeApprovedHours + expectedHours
          && year.beforeAvailableHours === year.afterAvailableHours), 'Decision preview must convert a reservation without another charge');
      await dialog.getByRole('region', { name: 'Wpływ decyzji na saldo', exact: true }).waitFor();
      const pending = (await get(actor.client, `/api/requests/${id}`)).request;
      check(pending.status === 'pending' && pending.pending.totalHours === expectedHours, 'Decision preview must not persist the decision');
      const response = responseFor(actor.page, `/api/requests/${id}/approve`);
      await dialog.getByRole('button', { name: 'Potwierdź akceptację', exact: true }).click();
      const operation = (await json(await response)).operation;
      check(operation.id === id && operation.status === 'approved', 'Decision did not save an approval');
      await dialog.waitFor({ state: 'detached' });
      await actor.page.reload();
      await actor.page.getByRole('heading', { name: 'Obowiązujący urlop', exact: true }).waitFor();
      const detail = (await get(actor.client, `/api/requests/${id}`)).request;
      check(detail.status === 'approved' && detail.effective.totalHours === expectedHours && detail.pending === null, 'Approved request did not persist after reload');
      return detail;
    }

    step = 'manager login and own three-hour approval in the UI';
    const manager = await actorContext('manager', true);
    const ownBalancePath = `/api/balances/users/${fixture.manager.id}/${fixture.year}`;
    const ownBefore = (await get(manager.client, ownBalancePath)).balance;
    const own = await approve(manager, fixture.requests.manager, 3);
    const ownAfter = (await get(manager.client, ownBalancePath)).balance;
    check(own.current.decision.actorName === 'Synthetic Manager' && ownAfter.availableHours === ownBefore.availableHours
      && ownAfter.reservedHours === ownBefore.reservedHours - 3 && ownAfter.approvedFutureHours === ownBefore.approvedFutureHours + 3,
    'Own manager approval must preserve available hours and record the actor');

    step = 'deputy sees own request without controls and server rejects own decision';
    const deputy = await actorContext('deputy');
    await open(deputy.page, fixture.requests.deputy);
    const deputyOwn = (await get(deputy.client, `/api/requests/${fixture.requests.deputy}`)).request;
    check(!deputyOwn.actions.approve && !deputyOwn.actions.reject, 'Deputy own decision permissions must be false');
    check(await deputy.page.getByRole('button', { name: 'Akceptuj', exact: true }).count() === 0
      && await deputy.page.getByRole('button', { name: 'Odrzuć', exact: true }).count() === 0, 'Deputy own decision controls must not be rendered');
    const forbidden = await post(deputy.client, `/api/requests/${fixture.requests.deputy}/approve`, { expectedVersion: deputyOwn.version, operationKey: randomUUID() }, 403);
    check(forbidden.error?.code === 'FORBIDDEN', 'Server must reject direct own deputy approval');
    check((await get(deputy.client, `/api/requests/${fixture.requests.deputy}`)).request.status === 'pending', 'Forbidden deputy decision changed the request');

    step = 'deputy approves another employee through the UI';
    const employeeBalancePath = `/api/balances/users/${fixture.employee.id}/${fixture.year}`;
    const employeeBefore = (await get(deputy.client, employeeBalancePath)).balance;
    const delegated = await approve(deputy, fixture.requests.employee, 5);
    const employeeAfter = (await get(deputy.client, employeeBalancePath)).balance;
    check(delegated.current.decision.actorName === 'Synthetic Deputy' && employeeBefore.availableHours === employeeAfter.availableHours
      && employeeAfter.approvedFutureHours === employeeBefore.approvedFutureHours + 5, 'Deputy approval must preserve balance and record the distinct decision maker');

    step = 'administrator approves a request in the second team';
    const admin = await actorContext('administrator');
    await admin.page.setViewportSize({ width: 390, height: 844 });
    const other = await approve(admin, fixture.requests.otheremployee, 4);
    check(other.current.decision.actorName === 'Synthetic Administrator', 'Administrator second-team decision actor was not recorded');
    check(await admin.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Management screen overflows mobile width');

    step = 'historical correction preview uses actual accounting and writes nothing';
    await open(manager.page, fixture.historicalRequestId);
    const historyBefore = (await get(manager.client, `/api/requests/${fixture.historicalRequestId}`)).request;
    const pastBalancePath = `/api/balances/users/${fixture.employee.id}/${fixture.pastYear}`;
    const beforeCorrection = (await get(manager.client, pastBalancePath)).balance;
    check(historyBefore.status === 'approved' && historyBefore.effective.totalHours === 6 && historyBefore.history.length === 1, 'Expected an isolated approved six-hour historical request');
    await manager.page.getByRole('button', { name: 'Skoryguj przeszły wniosek', exact: true }).click();
    const dialog = manager.page.getByRole('dialog', { name: 'Korekta nieobecności', exact: true });
    await dialog.getByLabel('Liczba godzin', { exact: true }).selectOption('3');
    const reason = 'Synthetic browser correction from six to three hours';
    await dialog.getByLabel('Uzasadnienie korekty', { exact: true }).fill(reason);
    const previewResponse = responseFor(manager.page, `/api/requests/${fixture.historicalRequestId}/correction-preview`);
    await dialog.getByRole('button', { name: 'Sprawdź skutki korekty', exact: true }).click();
    const preview = (await json(await previewResponse)).preview;
    check(preview.before.totalHours === 6 && preview.after.totalHours === 3 && preview.requiresDeficitConfirmation === false
      && preview.impact.hoursByYear.some(year => year.year === fixture.pastYear && year.beforeHours === 6 && year.afterHours === 3 && year.deltaHours === -3), 'Historical preview must calculate a three-hour refund');
    await dialog.getByRole('region', { name: 'Podgląd korekty', exact: true }).waitFor();
    check(JSON.stringify((await get(manager.client, pastBalancePath)).balance) === JSON.stringify(beforeCorrection), 'Historical preview mutated balance or audit');
    check((await get(manager.client, `/api/requests/${fixture.historicalRequestId}`)).request.history.length === 1, 'Historical preview created a stored revision');
    check(await dialog.getByRole('button', { name: 'Zapisz korektę', exact: true }).isDisabled(), 'Historical correction requires explicit confirmation');

    step = 'confirmed correction persists revision, reason, decision and exact refund';
    await dialog.getByRole('checkbox', { name: 'Potwierdzam przedstawione skutki korekty', exact: true }).check();
    const correctionResponse = responseFor(manager.page, `/api/requests/${fixture.historicalRequestId}/correct`);
    await dialog.getByRole('button', { name: 'Zapisz korektę', exact: true }).click();
    check((await json(await correctionResponse)).operation.status === 'approved', 'Historical correction did not remain approved');
    await dialog.waitFor({ state: 'detached' });
    await manager.page.reload(); await manager.page.getByRole('heading', { name: 'Obowiązujący urlop', exact: true }).waitFor();
    const corrected = (await get(manager.client, `/api/requests/${fixture.historicalRequestId}`)).request;
    const correctedBalance = (await get(manager.client, pastBalancePath)).balance;
    check(corrected.effective.totalHours === 3 && corrected.pending === null && corrected.history.length === 2
      && corrected.current.submitted.actorName === 'Synthetic Manager' && corrected.current.submitted.reason === reason
      && corrected.current.decision.actorName === 'Synthetic Manager' && corrected.current.decision.reason === reason,
    'Correction audit must preserve author, decision maker, reason and prior revision');
    check(correctedBalance.availableHours === beforeCorrection.availableHours + 3
      && correctedBalance.usedHours === beforeCorrection.usedHours - 3, 'Historical correction must refund exactly three hours');
    await manager.page.locator('.requests-history > summary').click();
    const visibleHistory = await manager.page.locator('.requests-history').innerText();
    check(visibleHistory.includes('Synthetic Employee') && visibleHistory.includes('Synthetic Manager') && visibleHistory.includes(reason), 'Visible history must distinguish the original employee and correction actor');
    check(pageErrors === 0, 'Browser reported an application error');
    await Promise.all([manager.context.close(), deputy.context.close(), admin.context.close()]);
    console.log('Real management E2E passed: manager own approval, deputy own denial and employee approval, administrator second-team decision, immutable decision/correction previews, historical correction 6h→3h, exact refund, persistent revisions and actor/reason history.');
  } finally { await browser.close(); }
}
main().catch(error => {
  console.error(`Real management E2E failed at: ${step}. Browser details and subprocess output suppressed.`);
  if (error instanceof SmokeAssertionError) console.error(error.message);
  process.exitCode = 1;
});
