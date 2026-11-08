'use strict';

// Real Chrome → nginx → PHP/MySQL. No synthetic route responses; isolated CI fixture only.
// Do not record traces/screenshots or print generated credentials/subprocess output.
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
  step = 'isolated system fixture';
  const fixture = JSON.parse(execFileSync('docker', ['compose', 'exec', '-T', '-e', 'CI=true',
    '-e', 'SYSTEM_UI_FIXTURE=ALLOW_SYNTHETIC_CI', 'php', 'php', 'tests/Support/system-ui-fixture.php'],
  { cwd: resolve(__dirname, '../..'), encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 30000, maxBuffer: 65536 }));
  for (const name of ['employee', 'manager', 'newmanager', 'administrator', 'transferred']) {
    const account = fixture[name];
    check(account && Number.isInteger(account.id) && account.id > 0
      && /^system_ui_[a-f0-9]{16}_(employee|manager|newmanager|administrator|transferred)@example\.test$/.test(account.email)
      && /^[a-f0-9]{48}$/.test(account.password), 'Unexpected synthetic system account');
  }
  for (const key of ['start', 'end', 'changeDate', 'unpaidDate', 'december', 'january']) {
    check(/^\d{4}-\d{2}-\d{2}$/.test(fixture[key]), 'Unexpected synthetic working date');
  }
  check(fixture.start < fixture.end && fixture.end < fixture.changeDate && fixture.changeDate < fixture.unpaidDate
    && fixture.start.slice(0, 4) === fixture.unpaidDate.slice(0, 4) && fixture.december < fixture.january
    && fixture.december.slice(0, 4) === String(fixture.boundaryYear)
    && fixture.january.slice(0, 4) === String(fixture.boundaryYear + 1)
    && Number.isInteger(fixture.historicalRequestId) && Number.isInteger(fixture.paidId)
    && Number.isInteger(fixture.unpaidId) && fixture.timezone === 'Europe/Warsaw', 'Unexpected system fixture dates/identifiers');
  step = 'Chrome launch';
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  try {
    let pageErrors = 0;
    async function json(response, expected = 200) {
      check(response.status() === expected, `Expected HTTP ${expected}, received ${response.status()}`);
      check(response.headers()['cache-control']?.includes('no-store'), 'Missing no-store API response');
      return response.json();
    }
    async function get(client, path, expected = 200) { return json(await client.get(path), expected); }
    async function post(client, path, data, expected = 200) {
      const { csrfToken } = await get(client, '/api/auth/csrf');
      check(typeof csrfToken === 'string' && csrfToken.length > 0, 'Missing CSRF token');
      return json(await client.post(path, { data, headers: { 'X-CSRF-Token': csrfToken } }), expected);
    }
    async function actor(name, uiLogin = false) {
      const context = await browser.newContext({ baseURL: base.href, viewport: { width: 1440, height: 1050 }, timezoneId: 'Europe/Warsaw' });
      const page = await context.newPage(); page.setDefaultTimeout(20000); page.on('pageerror', () => { ++pageErrors; });
      if (uiLogin) {
        await page.goto('/login');
        await page.getByLabel('Służbowy adres e-mail', { exact: true }).fill(fixture[name].email);
        await page.getByLabel('Hasło', { exact: true }).fill(fixture[name].password);
        await page.getByRole('button', { name: 'Zaloguj się', exact: true }).click();
        await page.waitForURL(base.href);
      } else await post(context.request, '/api/auth/login', { email: fixture[name].email, password: fixture[name].password });
      check((await get(context.request, '/api/auth/me')).user.id === fixture[name].id, 'Actor session did not persist');
      return { context, page, client: context.request };
    }
    const responseFor = (page, path, match = () => true) => page.waitForResponse(response => new URL(response.url()).pathname === path
      && response.request().method() === 'POST' && match(response.request().postDataJSON()));
    const detail = async (client, id) => (await get(client, `/api/requests/${id}`)).request;
    const balance = async (client, id, year) => (await get(client, `/api/balances/users/${id}/${year}`)).balance;
    async function ownDetails(page, id) {
      await page.goto('/requests');
      await page.getByRole('button', { name: `Szczegóły wniosku ${id}`, exact: true }).click();
      await page.getByRole('heading', { name: 'Szczegóły wniosku', exact: true }).waitFor();
    }
    async function approveUI(actor, id, status = 'approved') {
      await actor.page.goto(`/management/requests?id=${id}`);
      await actor.page.getByRole('heading', { name: 'Szczegóły wniosku', exact: true }).waitFor();
      await actor.page.getByRole('button', { name: 'Akceptuj', exact: true }).click();
      const dialog = actor.page.getByRole('dialog', { name: 'Akceptacja wniosku', exact: true });
      const before = await detail(actor.client, id);
      const previewResponse = responseFor(actor.page, `/api/requests/${id}/decision-preview`);
      await dialog.getByRole('button', { name: 'Sprawdź wpływ', exact: true }).click();
      const preview = (await json(await previewResponse)).preview;
      check(preview.id === id && preview.statusAfter === status, 'Decision preview has an incorrect result');
      check((await detail(actor.client, id)).version === before.version, 'Decision preview wrote a request version');
      await dialog.getByRole('region', { name: 'Wpływ decyzji na saldo', exact: true }).waitFor();
      const response = responseFor(actor.page, `/api/requests/${id}/approve`);
      await dialog.getByRole('button', { name: 'Potwierdź akceptację', exact: true }).click();
      check((await json(await response)).operation.status === status, 'Approval did not persist the expected status');
      await dialog.waitFor({ state: 'detached' });
      return detail(actor.client, id);
    }
    const employee = await actor('employee', true);
    const manager = await actor('manager');
    const year = Number(fixture.start.slice(0, 4));
    step = 'full-day request, automatic working-day count and immutable preview';
    await employee.page.goto('/requests');
    await employee.page.getByRole('button', { name: 'Złóż wniosek', exact: true }).first().click();
    let dialog = employee.page.getByRole('dialog', { name: 'Nowy wniosek', exact: true });
    await dialog.getByLabel('Rodzaj nieobecności', { exact: true }).selectOption(String(fixture.paidId));
    const countResponse = responseFor(employee.page, '/api/requests/duration', body => body.startDate === fixture.start && body.endDate === fixture.end && body.mode === 'full_days');
    const previewResponse = responseFor(employee.page, '/api/requests/preview', body => body.startDate === fixture.start && body.endDate === fixture.end);
    await dialog.getByLabel('Data od', { exact: true }).fill(fixture.start);
    await dialog.getByLabel('Data do', { exact: true }).fill(fixture.end);
    const duration = (await json(await countResponse)).duration;
    check(duration.workingDays === 2 && duration.totalHours === 16, 'Automatic duration must count two working days');
    await dialog.getByRole('status', { name: 'Obliczony wymiar urlopu', exact: true }).getByText('2 dni', { exact: false }).waitFor();
    const preview = (await json(await previewResponse)).preview;
    check(preview.totalHours === 16 && preview.days.length === 2, 'Full-day preview must contain two working days');
    await dialog.getByRole('status', { name: 'Podsumowanie wniosku', exact: true }).getByText('16 h', { exact: true }).first().waitFor();
    check((await balance(employee.client, fixture.employee.id, year)).availableHours === 80
      && (await get(employee.client, '/api/requests?scope=mine')).requests.length === 0, 'Duration and preview changed stored data');
    const createdResponse = responseFor(employee.page, '/api/requests');
    await dialog.getByRole('button', { name: 'Złóż wniosek', exact: true }).click();
    const created = (await json(await createdResponse, 201)).operation;
    await dialog.waitFor({ state: 'detached' });
    check(created.status === 'pending' && (await balance(employee.client, fixture.employee.id, year)).availableHours === 64, 'Full-day request must reserve sixteen hours');
    step = 'manager approves full-day request without a second charge';
    let request = await approveUI(manager, created.id);
    check(request.effective.totalHours === 16 && (await balance(employee.client, fixture.employee.id, year)).availableHours === 64, 'Approval must preserve available hours');

    step = 'employee changes full days to five hours and manager approves the refund';
    await ownDetails(employee.page, created.id);
    await employee.page.getByRole('button', { name: 'Zmień termin lub wymiar', exact: true }).click();
    dialog = employee.page.getByRole('dialog', { name: 'Zmień termin lub wymiar', exact: true });
    await dialog.getByRole('radio', { name: /Część dnia/ }).check();
    const changePreviewResponse = responseFor(employee.page, `/api/requests/${created.id}/change-preview`, body => body.mode === 'hourly' && body.hours === 5 && body.startDate === fixture.changeDate);
    await dialog.getByLabel('Data', { exact: true }).fill(fixture.changeDate);
    await dialog.getByLabel('Liczba godzin', { exact: true }).selectOption('5');
    check((await json(await changePreviewResponse)).preview.totalHours === 5, 'Change preview must calculate five hours');
    await dialog.getByRole('status', { name: 'Podsumowanie wniosku', exact: true }).getByText('5 h', { exact: true }).first().waitFor();
    const changedResponse = responseFor(employee.page, `/api/requests/${created.id}/change`);
    await dialog.getByRole('button', { name: 'Wyślij propozycję', exact: true }).click();
    check((await json(await changedResponse)).operation.status === 'change_pending', 'Change proposal did not persist');
    await dialog.waitFor({ state: 'detached' });
    check((await balance(employee.client, fixture.employee.id, year)).availableHours === 64, 'Reduction must not refund before approval');
    request = await approveUI(manager, created.id);
    check(request.effective.totalHours === 5 && request.effective.startDate === fixture.changeDate
      && (await balance(employee.client, fixture.employee.id, year)).availableHours === 75, 'Approved reduction must refund eleven hours');
    step = 'employee cancellation and manager confirmation refund exactly once';
    await ownDetails(employee.page, created.id);
    await employee.page.getByRole('button', { name: 'Anuluj urlop', exact: true }).click();
    dialog = employee.page.getByRole('dialog', { name: 'Poproś o anulowanie urlopu', exact: true });
    const cancelledResponse = responseFor(employee.page, `/api/requests/${created.id}/cancel`);
    await dialog.getByRole('button', { name: 'Wyślij prośbę o anulowanie', exact: true }).click();
    check((await json(await cancelledResponse)).operation.status === 'cancellation_pending', 'Cancellation proposal did not persist');
    await dialog.waitFor({ state: 'detached' });
    check((await balance(employee.client, fixture.employee.id, year)).availableHours === 75, 'Cancellation proposal must not refund early');
    request = await approveUI(manager, created.id, 'cancelled');
    const settled = await balance(employee.client, fixture.employee.id, year);
    check(settled.availableHours === 80 && settled.reservedHours === 0 && settled.approvedFutureHours === 0
      && request.history.length === 3 && request.history.every(revision => revision.decision?.actorName === 'Synthetic Manager'), 'Cancellation must restore all hours and retain each decision');

    step = 'unpaid absences keep balance but share the eight-hour daily limit';
    const unpaidBody = hours => ({ absenceTypeId: fixture.unpaidId, mode: 'hourly', startDate: fixture.unpaidDate, endDate: fixture.unpaidDate, hours, operationKey: randomUUID() });
    const unpaidThree = (await post(employee.client, '/api/requests', unpaidBody(3), 201)).operation;
    await post(employee.client, '/api/requests', unpaidBody(5), 201);
    const overbooked = await post(employee.client, '/api/requests', unpaidBody(1), 409);
    check(overbooked.error?.code === 'DAILY_CAPACITY_EXCEEDED', 'Unpaid daily capacity must reject the ninth hour');
    check((await balance(employee.client, fixture.employee.id, year)).availableHours === 80, 'Unpaid requests changed paid balance');
    request = await approveUI(manager, unpaidThree.id);
    check(request.effective.absenceType.deductsBalance === false && (await balance(employee.client, fixture.employee.id, year)).availableHours === 80, 'Unpaid approval changed paid balance');

    step = 'one cross-year request charges each year only for its working day';
    const boundary = { absenceTypeId: fixture.paidId, mode: 'full_days', startDate: fixture.december, endDate: fixture.january };
    const boundaryPreview = (await post(employee.client, '/api/requests/preview', boundary)).preview;
    check(boundaryPreview.totalHours === 16 && boundaryPreview.days.length === 2 && boundaryPreview.years.length === 2
      && boundaryPreview.years.every(value => value.additionalReservedHours === 8 && value.afterAvailableHours === value.beforeAvailableHours - 8), 'Year boundary must reserve eight hours per year');
    const boundaryRequest = (await post(employee.client, '/api/requests', { ...boundary, operationKey: randomUUID() }, 201)).operation;
    await approveUI(manager, boundaryRequest.id);
    for (const boundaryYear of [fixture.boundaryYear, fixture.boundaryYear + 1]) {
      const annual = await balance(employee.client, fixture.employee.id, boundaryYear);
      check(annual.availableHours === 72 && annual.approvedFutureHours === 8 && annual.reservedHours === 0, 'Cross-year approval charged the wrong annual pool');
    }

    step = 'transfer hides current balance from old manager and history from new manager';
    const newManager = await actor('newmanager');
    const admin = await actor('administrator');
    const historical = await detail(manager.client, fixture.historicalRequestId);
    check(historical.actions.correct && historical.effective.totalHours === 6, 'Old manager must retain historical correction scope');
    check((await get(newManager.client, `/api/requests/${fixture.historicalRequestId}`, 403)).error.code === 'FORBIDDEN', 'New manager must not read old-team details');
    check((await get(manager.client, `/api/balances/users/${fixture.transferred.id}/${fixture.pastYear}`, 403)).error.code === 'FORBIDDEN', 'Old manager must not read transferred employee balance');
    const historicalBefore = await balance(admin.client, fixture.transferred.id, fixture.pastYear);
    await manager.page.setViewportSize({ width: 390, height: 844 });
    await manager.page.goto(`/management/requests?id=${fixture.historicalRequestId}`);
    await manager.page.getByRole('button', { name: 'Skoryguj przeszły wniosek', exact: true }).click();
    dialog = manager.page.getByRole('dialog', { name: 'Korekta nieobecności', exact: true });
    await dialog.getByLabel('Liczba godzin', { exact: true }).selectOption('3');
    const reason = 'Synthetic system correction after team transfer';
    await dialog.getByLabel('Uzasadnienie korekty', { exact: true }).fill(reason);
    const correctionPreviewResponse = responseFor(manager.page, `/api/requests/${fixture.historicalRequestId}/correction-preview`);
    await dialog.getByRole('button', { name: 'Sprawdź skutki korekty', exact: true }).click();
    const correctionPreview = (await json(await correctionPreviewResponse)).preview;
    check(correctionPreview.before.totalHours === 6 && correctionPreview.after.totalHours === 3
      && correctionPreview.impact.pools === null, 'Historical preview must hide current pools after transfer');
    check(JSON.stringify(await balance(admin.client, fixture.transferred.id, fixture.pastYear)) === JSON.stringify(historicalBefore), 'Historical preview mutated accounting');
    await dialog.getByRole('region', { name: 'Podgląd korekty', exact: true }).waitFor();
    await dialog.getByRole('checkbox', { name: 'Potwierdzam przedstawione skutki korekty', exact: true }).check();
    const correctionResponse = responseFor(manager.page, `/api/requests/${fixture.historicalRequestId}/correct`);
    await dialog.getByRole('button', { name: 'Zapisz korektę', exact: true }).click();
    check((await json(await correctionResponse)).operation.status === 'approved', 'Transferred historical correction did not persist');
    await dialog.waitFor({ state: 'detached' });
    await manager.page.reload();
    await manager.page.getByRole('heading', { name: 'Obowiązujący urlop', exact: true }).waitFor();
    request = await detail(admin.client, fixture.historicalRequestId);
    const historicalAfter = await balance(admin.client, fixture.transferred.id, fixture.pastYear);
    check(request.effective.totalHours === 3 && request.history.length === 2
      && request.current.decision.actorName === 'Synthetic Manager' && request.current.decision.reason === reason
      && historicalAfter.availableHours === historicalBefore.availableHours + 3, 'Transfer correction lost history or refunded an incorrect amount');
    check((await get(newManager.client, `/api/requests/${fixture.historicalRequestId}`, 403)).error.code === 'FORBIDDEN', 'Correction must not expose historical details to the new manager');
    check(await manager.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Historical management view overflows mobile width');
    check(pageErrors === 0, 'Browser reported an application error');
    for (const item of [employee, manager, newManager, admin]) { await item.context.close(); }
    console.log('Real system E2E passed: automatic working-day count, full-day submit/approve, hourly reduction with delayed refund, cancellation approval and audit, unpaid daily capacity without balance use, cross-year annual allocation, historical correction after team transfer with hidden current pools, persistence and mobile layout.');
  } finally { await browser.close(); }
}
main().catch(error => {
  console.error(`Real system E2E failed at: ${step}. Browser details and subprocess output suppressed.`);
  if (error instanceof SmokeAssertionError) console.error(error.message);
  process.exitCode = 1;
});
