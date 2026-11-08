const assert = require('node:assert/strict');
const { mkdirSync } = require('node:fs');
const { join } = require('node:path');
const { chromium } = require('playwright-core');

// These are browser interaction tests against an explicitly synthetic API.
// They do not replace the real PHP/MySQL tests of permissions, accounting or persistence.
const baseUrl = (process.env.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const screenshotDir = process.env.UI_SCREENSHOT_DIR || '/tmp/urlopy-ui-screens';
mkdirSync(screenshotDir, { recursive: true });
const today = '2026-11-09';
const user = { id: 2, firstName: 'Anna', lastName: 'Testowa', email: 'anna@example.test', isAdministrator: false, mustChangePassword: false, team: { id: 1, code: 'it', name: 'Rozwój aplikacji' }, role: 'employee' };
const paid = { id: 1, code: 'paid', revision: 1, name: 'Urlop wypoczynkowy', deductsBalance: true, availableForCurrentEmployment: true };
const options = { today, timezone: 'Europe/Warsaw', hoursPerDay: 8, modes: ['full_days', 'hourly'], absenceTypes: [paid, { ...paid, id: 2, code: 'unpaid', name: 'Urlop bezpłatny', deductsBalance: false }] };
const allFalse = { edit: false, withdraw: false, approve: false, reject: false, change: false, cancel: false, correct: false };
const waitFor = async (predicate, message) => {
  const deadline = Date.now() + 10000;
  while (!predicate()) {
    if (Date.now() >= deadline) throw new Error(message);
    await new Promise(resolve => setTimeout(resolve, 20));
  }
};
function revision(id, hours, dates, kind = 'initial', number = 1) {
  return { id: id * 10 + number, revision: number, baseRevision: kind === 'initial' ? null : 1, kind,
    mode: dates.length === 1 && hours < 8 ? 'hourly' : 'full_days', startDate: dates[0], endDate: dates.at(-1), totalHours: hours,
    days: dates.map(date => ({ date, hours: dates.length === 1 ? hours : 8 })), absenceType: paid,
    submitted: { at: '2026-11-09T08:00:00+00:00', actorName: 'Anna Testowa', reason: null }, decision: null };
}
function requestFixture(id, value, status = 'pending') {
  if (status === 'approved') value.decision = { outcome: 'approved', at: '2026-11-09T09:00:00+00:00', actorName: 'Marek Kierownik', reason: null };
  return { id, reference: `synthetic-request-${id}`, version: 1, status, currentVisible: true,
    user: { id: user.id, firstName: user.firstName, lastName: user.lastName }, latestVisibleRevision: value,
    current: value, effective: status === 'approved' ? value : null, pending: status === 'pending' ? value : null,
    history: [value], actions: { ...allFalse, ...(status === 'approved' ? { change: true, cancel: true } : { edit: true, withdraw: true }) } };
}
function operation(value) { return { operation: { id: value.id, reference: value.reference, version: value.version, revision: value.current.revision, status: value.status } }; }
function calendarResponse(url) {
  const from = url.searchParams.get('from'); const to = url.searchParams.get('to');
  assert.match(from || '', /^\d{4}-\d{2}-\d{2}$/); assert.match(to || '', /^\d{4}-\d{2}-\d{2}$/);
  const people = Array.from({ length: 10 }, (_, index) => ({ id: index + 2, firstName: index ? `Osoba ${index}` : 'Anna', lastName: index === 9 ? 'Demonstracyjna-Bardzo-Długie-Nazwisko' : 'Testowa' }));
  const raw = [
    { key: 'effective-101', requestId: 101, canViewDetails: false, userId: 3, status: 'approved', kind: 'effective', days: [{ date: '2026-11-09', hours: 8 }, { date: '2026-11-10', hours: 8 }] },
    { key: 'pending-102', requestId: 102, canViewDetails: false, userId: 4, status: 'pending', kind: 'pending', days: [{ date: '2026-11-12', hours: 3 }] },
    { key: 'effective-103', requestId: 103, canViewDetails: false, userId: 4, status: 'approved', kind: 'effective', days: [{ date: '2026-11-12', hours: 5 }] },
    { key: 'effective-104', requestId: 104, canViewDetails: false, userId: 11, status: 'approved', kind: 'effective', days: [{ date: '2026-12-15', hours: 8 }] },
    { key: 'effective-105', requestId: 105, canViewDetails: false, userId: 5, status: 'approved', kind: 'effective', days: [{ date: '2026-12-31', hours: 8 }, { date: '2027-01-04', hours: 8 }] },
  ];
  const entries = raw.map(entry => ({ ...entry, days: entry.days.filter(day => day.date >= from && day.date <= to) })).filter(entry => entry.days.length).map(entry => ({ ...entry, startDate: entry.days[0].date, endDate: entry.days.at(-1).date, totalHours: entry.days.reduce((sum, day) => sum + day.hours, 0) }));
  return { today, from, to, timezone: 'Europe/Warsaw', teams: [{ id: 1, name: user.team.name }], selectedTeamId: 1, people, entries, holidays: [{ date: '2026-11-11', name: 'Święto Niepodległości' }, { date: '2027-01-01', name: 'Nowy Rok' }].filter(day => day.date >= from && day.date <= to) };
}

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1050 } });
    let session = user;
    const requests = new Map(); const receipts = new Map();
    const writes = []; const previews = []; const calendarCalls = []; const errors = []; const durationCalls = [];
    let durationFailure = false; let delayedDuration = null; let durationHeld = false;
    let available = 80; let loseCreateResponse = true; let malformedCreateReceipt = true; let malformedWithdrawalReceipt = true; let malformedCancellationReceipt = true;
    let listFailure = false; let denyPreview = false; let conflictNextEdit = false;
    await context.route('**/api/**', async route => {
      const req = route.request(); const url = new URL(req.url()); const path = url.pathname; const method = req.method();
      const reply = (status, body) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
      const error = (status, code) => reply(status, { error: { code, message: 'Synthetic private debug text must never be displayed.' } });
      if (path === '/api/auth/csrf') return reply(200, { csrfToken: 'synthetic-request-csrf' });
      if (!session) return error(401, 'AUTH_REQUIRED');
      if (path === '/api/auth/me') return reply(200, { user: session });
      if (session.mustChangePassword) return error(403, 'PASSWORD_CHANGE_REQUIRED');
      if (method === 'GET') {
        if (path === '/api/requests/options') return reply(200, options);
        if (path === '/api/team-calendar') { calendarCalls.push({ from: url.searchParams.get('from'), to: url.searchParams.get('to') }); return reply(200, calendarResponse(url)); }
        if (/^\/api\/balances\/users\/2\/\d+$/.test(path)) return reply(200, { balance: { userId: 2, year: Number(path.split('/').at(-1)), configured: true, availableHours: available, deficitHours: 0, displayAvailable: `${available} h`, annualHours: 80, openingUsedHours: 0, usedHours: 0, approvedFutureHours: 0, reservedHours: 80 - available, changeReservedHours: 0, carriedHours: 0, allocatedHours: 80, pools: [], history: [] } });
        if (path === '/api/requests') {
          assert.equal(url.searchParams.get('scope'), 'mine', 'Employee list must use own scope.');
          if (listFailure) return error(503, 'REQUEST_FAILED');
          const status = url.searchParams.get('status');
          return reply(200, { requests: [...requests.values()].filter(value => !status || value.status === status).reverse(), nextBeforeId: null });
        }
        const match = path.match(/^\/api\/requests\/(\d+)$/);
        if (match) return requests.has(Number(match[1])) ? reply(200, { request: requests.get(Number(match[1])) }) : error(404, 'NOT_FOUND');
        throw new Error(`Unexpected synthetic GET ${path}`);
      }
      assert.equal(method, 'POST', `Unexpected synthetic method ${method} ${path}`);
      assert.equal(req.headers()['x-csrf-token'], 'synthetic-request-csrf', `Missing CSRF on ${path}`);
      const body = req.postDataJSON();
      if (path === '/api/requests/duration') {
        durationCalls.push(structuredClone(body));
        assert.deepEqual(Object.keys(body).sort(), (body.mode === 'hourly' ? ['mode', 'startDate', 'endDate', 'hours'] : ['mode', 'startDate', 'endDate']).sort());
        if (durationFailure) return error(503, 'REQUEST_FAILED');
        let workingDays = 0;
        for (let date = new Date(`${body.startDate}T12:00:00Z`); date.toISOString().slice(0, 10) <= body.endDate; date.setUTCDate(date.getUTCDate() + 1)) {
          if (![0, 6].includes(date.getUTCDay()) && !['2026-11-11', '2026-11-12'].includes(date.toISOString().slice(0, 10))) ++workingDays;
        }
        if (delayedDuration && body.startDate === '2026-11-23' && body.endDate === '2026-11-25') { durationHeld = true; await delayedDuration; }
        return reply(200, { duration: { workingDays, totalHours: workingDays * (body.mode === 'hourly' ? body.hours : 8), hoursPerDay: 8, timezone: 'Europe/Warsaw' } });
      }
      if (path.endsWith('/preview') || path.endsWith('-preview')) {
        assert.equal('operationKey' in body, false, 'Preview must not claim a mutation key.');
        assert.equal(typeof body.absenceTypeId, 'number');
        previews.push({ path, body });
        if (denyPreview) return error(422, 'INSUFFICIENT_BALANCE');
        const isChange = path.endsWith('/change-preview'); const isEdit = path.endsWith('/edit-preview');
        const hours = isChange ? 24 : body.mode === 'hourly' ? body.hours : 8;
        if (body.mode === 'hourly') { assert.equal(body.startDate, body.endDate); assert.equal(Number.isInteger(body.hours), true); assert.ok(body.hours >= 1 && body.hours <= 7); }
        else assert.equal('hours' in body, false, 'Whole-day input must not contain a stale hourly field.');
        const additional = isChange ? 8 : isEdit ? hours - 3 : hours;
        return reply(200, { preview: { totalHours: hours, days: [{ date: body.startDate, hours: Math.min(8, hours) }], deductsBalance: body.absenceTypeId === 1, timezone: 'Europe/Warsaw', years: [{ year: 2026, beforeAvailableHours: available, afterAvailableHours: available - additional, additionalReservedHours: additional }] } });
      }
      if (path === '/api/auth/logout') { session = null; return reply(200, { status: 'logged_out' }); }
      assert.equal(typeof body.operationKey, 'string'); assert.ok(body.operationKey.length > 0);
      writes.push({ path, body: structuredClone(body) });
      if (receipts.has(body.operationKey)) {
        const cached = receipts.get(body.operationKey);
        assert.equal(path, cached.path); assert.deepEqual(body, cached.body, 'Retry must preserve the complete original payload.');
        if (path === '/api/requests' && malformedCreateReceipt) {
          malformedCreateReceipt = false;
          return reply(201, { operation: { ...cached.response.operation, id: 0 } });
        }
        return reply(path === '/api/requests' ? 201 : 200, cached.response);
      }
      let value;
      if (path === '/api/requests') {
        assert.equal(body.mode, 'hourly'); assert.equal(body.hours, 3); assert.equal(body.startDate, '2026-11-10'); assert.equal(body.endDate, body.startDate);
        value = requestFixture(1, revision(1, 3, ['2026-11-10'])); requests.set(1, value); available = 77;
      } else {
        const match = path.match(/^\/api\/requests\/(\d+)\/(edit|change|withdraw|cancel)$/);
        assert.ok(match, `Unexpected mutation ${path}`);
        value = requests.get(Number(match[1])); assert.ok(value); assert.equal(body.expectedVersion, value.version);
        const action = match[2];
        if (action === 'edit' && conflictNextEdit) { conflictNextEdit = false; value.version++; return error(409, 'CONFLICT'); }
        value.version++;
        if (action === 'edit') {
          assert.equal(body.hours, 5); assert.equal(body.mode, 'hourly');
          value.history[0].decision = { outcome: 'superseded', at: '2026-11-09T10:00:00+00:00', actorName: 'Anna Testowa', reason: null };
          const next = revision(1, 5, ['2026-11-10'], 'initial', 2);
          Object.assign(value, { current: next, pending: next, latestVisibleRevision: next }); value.history.push(next); available = 75;
        } else if (action === 'change') {
          assert.equal(body.mode, 'full_days'); assert.equal(body.startDate, '2026-11-23'); assert.equal(body.endDate, '2026-11-25');
          const next = revision(2, 24, ['2026-11-23', '2026-11-24', '2026-11-25'], 'change', 2);
          Object.assign(value, { status: 'change_pending', current: next, pending: next, latestVisibleRevision: next, actions: { ...allFalse, withdraw: true } }); value.history.push(next); available = 56;
        } else if (action === 'cancel') {
          const next = { ...structuredClone(value.effective), id: 24, revision: 4, kind: 'cancellation', baseRevision: 1, decision: null };
          Object.assign(value, { status: 'cancellation_pending', current: next, pending: next, latestVisibleRevision: next, actions: { ...allFalse, withdraw: true } }); value.history.push(next);
          assert.equal(available, 64, 'Cancellation proposal fixture must retain existing charge.');
        } else if (action === 'withdraw') {
          value.pending.decision = { outcome: 'withdrawn', at: '2026-11-09T11:00:00+00:00', actorName: 'Anna Testowa', reason: null };
          if (value.effective) { Object.assign(value, { status: 'approved', pending: null, current: value.effective, actions: { ...allFalse, change: true, cancel: true } }); available = 64; }
          else { Object.assign(value, { status: 'withdrawn', pending: null, actions: allFalse }); available = 80; }
        }
      }
      const response = operation(value); receipts.set(body.operationKey, { path, body: structuredClone(body), response: structuredClone(response) });
      if (path === '/api/requests' && loseCreateResponse) { loseCreateResponse = false; await new Promise(resolve => setTimeout(resolve, 100)); return route.abort('failed'); }
      if (path === '/api/requests/1/withdraw' && malformedWithdrawalReceipt) {
        malformedWithdrawalReceipt = false; return reply(200, { operation: { id: value.id } });
      }
      if (path === '/api/requests/2/cancel' && malformedCancellationReceipt) {
        malformedCancellationReceipt = false; return reply(200, { operation: { ...response.operation, id: 999 } });
      }
      return reply(path === '/api/requests' ? 201 : 200, response);
    });

    const page = await context.newPage(); page.on('pageerror', error => errors.push(error.message));
    await page.clock.setFixedTime(new Date('2026-11-09T10:00:00Z'));
    await page.goto(`${baseUrl}/requests`);
    await page.getByRole('heading', { name: 'Moje wnioski', exact: true }).waitFor();
    await page.getByRole('heading', { name: 'Tu pojawią się Twoje wnioski', exact: true }).waitFor();
    const create = page.getByRole('button', { name: 'Złóż wniosek', exact: true }).first();
    await create.click();
    let dialog = page.getByRole('dialog', { name: 'Nowy wniosek', exact: true });
    await dialog.getByRole('status', { name: 'Podsumowanie wniosku' }).waitFor();
    assert.equal(await dialog.getByRole('radio', { name: /Całe dni/ }).isChecked(), true);
    await dialog.getByRole('status', { name: 'Podsumowanie wniosku' }).getByText('8 h', { exact: true }).waitFor();
    assert.equal(writes.length, 0, 'Opening and previewing must not submit a request.');
    await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => !!document.activeElement?.closest('dialog[open]')), true, 'Keyboard focus must enter the modal.');
    await page.keyboard.press('Shift+Tab');
    assert.equal(await page.evaluate(() => !!document.activeElement?.closest('dialog[open]')), true, 'Reverse Tab must stay in the modal.');
    await page.keyboard.press('Escape'); await dialog.waitFor({ state: 'detached' });
    assert.equal(await create.evaluate(element => element === document.activeElement), true, 'Closing restores focus to the trigger.');

    // The shared header links from account/balance/admin screens to this URL.
    await page.goto(`${baseUrl}/requests?new=1`);
    dialog = page.getByRole('dialog', { name: 'Nowy wniosek', exact: true });
    await dialog.getByRole('status', { name: 'Podsumowanie wniosku' }).waitFor();
    await page.keyboard.press('Escape'); await dialog.waitFor({ state: 'detached' });
    assert.equal(new URL(page.url()).searchParams.has('new'), false, 'Closing the linked form clears its one-shot URL intent.');

    // The count is independent of balance validation and follows only the newest dates.
    denyPreview = true; await create.click(); dialog = page.getByRole('dialog', { name: 'Nowy wniosek', exact: true });
    const duration = dialog.getByRole('status', { name: 'Obliczony wymiar urlopu', exact: true });
    await dialog.getByLabel('Data od', { exact: true }).fill('2026-11-10');
    await dialog.getByLabel('Data do', { exact: true }).fill('2026-11-16');
    await duration.getByText('3 dni', { exact: false }).waitFor();
    assert.match(await duration.innerText(), /24 h/);
    await dialog.getByRole('alert').filter({ hasText: 'Brakuje dostępnych godzin' }).waitFor();
    assert.equal(await dialog.getByRole('button', { name: 'Złóż wniosek', exact: true }).isDisabled(), true, 'A duration count does not authorize submission.');
    await page.screenshot({ path: join(screenshotDir, 'request-working-days-desktop.png') });
    await dialog.getByLabel('Data od', { exact: true }).fill('2026-11-14');
    await dialog.getByLabel('Data do', { exact: true }).fill('2026-11-15');
    await duration.getByText('0 dni', { exact: false }).waitFor();
    await duration.getByText('W tym terminie nie ma dni roboczych.', { exact: true }).waitFor();
    let releaseDuration;
    delayedDuration = new Promise(resolve => { releaseDuration = resolve; });
    await dialog.getByLabel('Data od', { exact: true }).fill('2026-11-23');
    await dialog.getByLabel('Data do', { exact: true }).fill('2026-11-25');
    await waitFor(() => durationHeld, 'The older duration response should be held.');
    const oldDurationResponse = page.waitForResponse(response => response.url().endsWith('/api/requests/duration') && response.request().postDataJSON().endDate === '2026-11-25');
    await dialog.getByLabel('Data od', { exact: true }).fill('2026-11-26');
    assert.equal(await duration.getByText('3 dni', { exact: false }).count(), 0, 'Changing dates removes the previous count immediately.');
    await duration.getByText('1 dzień', { exact: false }).waitFor();
    releaseDuration(); await (await oldDurationResponse).finished(); delayedDuration = null;
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    assert.match(await duration.innerText(), /1 dzień/); assert.doesNotMatch(await duration.innerText(), /3 dni/);
    durationFailure = true; await dialog.getByLabel('Data od', { exact: true }).fill('2026-11-27');
    await duration.getByText('Nie udało się obliczyć wymiaru urlopu.', { exact: true }).waitFor();
    assert.equal(await duration.locator('strong').count(), 0, 'An error cannot retain a stale count.');
    durationFailure = false; await duration.getByRole('button', { name: 'Ponów obliczenie dni', exact: true }).click();
    await duration.getByText('1 dzień', { exact: false }).waitFor();
    assert.equal(writes.length, 0, 'Automatic duration reads never write a request or balance.');
    await page.keyboard.press('Escape'); await dialog.getByRole('button', { name: 'Zamknij formularz', exact: true }).last().click();
    await dialog.waitFor({ state: 'detached' }); denyPreview = false;

    await create.click(); dialog = page.getByRole('dialog', { name: 'Nowy wniosek', exact: true });
    await dialog.getByLabel('Data od', { exact: true }).fill('2026-11-10');
    await dialog.getByRole('radio', { name: /Część dnia/ }).check();
    await dialog.getByLabel('Liczba godzin', { exact: true }).selectOption('3');
    await dialog.getByRole('status', { name: 'Podsumowanie wniosku' }).getByText('3 h', { exact: true }).waitFor();
    assert.equal(await dialog.getByLabel('Data', { exact: true }).inputValue(), '2026-11-10');
    await dialog.getByText('77 h = 9 dni i 5 h', { exact: true }).waitFor();
    assert.equal(await dialog.evaluate(element => {
      const bounds = element.getBoundingClientRect();
      return Math.abs(bounds.x + bounds.width / 2 - innerWidth / 2) <= 2
        && Math.abs(bounds.y + bounds.height / 2 - innerHeight / 2) <= 2;
    }), true, 'The desktop request dialog must be centered in the viewport.');
    await page.screenshot({ path: join(screenshotDir, 'request-hourly-desktop.png'), fullPage: true });
    await dialog.getByRole('button', { name: 'Złóż wniosek', exact: true }).dblclick({ force: true });
    await dialog.getByRole('button', { name: 'Ponów wysłanie', exact: true }).waitFor();
    assert.equal(writes.length, 1, 'Double click must start only one submission.');
    assert.equal(await dialog.getByLabel('Data', { exact: true }).isDisabled(), true, 'Uncertain submission freezes the original payload.');
    await dialog.getByRole('button', { name: 'Ponów wysłanie', exact: true }).click();
    await waitFor(() => writes.length === 2, 'The first retry must reach the receipt replay.');
    await dialog.getByRole('button', { name: 'Ponów wysłanie', exact: true }).waitFor();
    assert.equal(await dialog.getByLabel('Data', { exact: true }).isDisabled(), true, 'An incomplete HTTP 201 receipt must keep the original submission frozen.');
    assert.equal(requests.size, 1); assert.equal(available, 77);
    await dialog.getByRole('button', { name: 'Ponów wysłanie', exact: true }).click();
    await dialog.waitFor({ state: 'detached' });
    assert.equal(writes.length, 3); assert.deepEqual(writes[0], writes[1]); assert.deepEqual(writes[1], writes[2]); assert.equal(requests.size, 1);
    await page.getByRole('button', { name: 'Szczegóły wniosku 1', exact: true }).click();
    await page.getByRole('button', { name: 'Edytuj wniosek', exact: true }).click();
    dialog = page.getByRole('dialog', { name: 'Edytuj wniosek', exact: true });
    assert.equal(await dialog.getByLabel('Liczba godzin', { exact: true }).inputValue(), '3');
    await dialog.getByLabel('Liczba godzin', { exact: true }).selectOption('5');
    await dialog.getByText('75 h = 9 dni i 3 h', { exact: true }).waitFor();
    await dialog.getByRole('button', { name: 'Zapisz zmianę', exact: true }).click();
    await dialog.waitFor({ state: 'detached' });
    assert.equal(available, 75); assert.equal(requests.get(1).history.length, 2);
    await page.locator('.requests-history > summary').click();
    await page.getByText('Zastąpiono nowszą wersją', { exact: true }).waitFor();
    assert.equal(await page.locator('.requests-history li').count(), 2, 'Both versions remain in the visible history.');
    await page.getByRole('button', { name: 'Wycofaj wniosek', exact: true }).click();
    await page.getByRole('button', { name: 'Potwierdź wycofanie', exact: true }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Ponów wysłanie', exact: true }).waitFor();
    const withdrawnVersion = requests.get(1).version;
    assert.equal(available, 80, 'The first withdrawal committed despite its incomplete receipt.');
    await page.getByRole('dialog').getByRole('button', { name: 'Ponów wysłanie', exact: true }).click();
    await page.getByRole('dialog').waitFor({ state: 'detached' });
    assert.equal(requests.get(1).version, withdrawnVersion, 'Withdrawal receipt retry must not create another version or refund.');
    assert.deepEqual(writes.at(-1), writes.at(-2));
    await waitFor(() => requests.get(1).status === 'withdrawn', 'Withdrawal was not sent.');
    assert.equal(available, 80);
    await page.getByLabel('Status', { exact: true }).selectOption('pending');
    await page.getByRole('heading', { name: 'Brak wniosków o tym statusie', exact: true }).waitFor();
    await page.getByLabel('Status', { exact: true }).selectOption('');
    await page.getByRole('button', { name: 'Szczegóły wniosku 1', exact: true }).waitFor();

    requests.clear(); requests.set(2, requestFixture(2, revision(2, 16, ['2026-11-16', '2026-11-17']), 'approved')); available = 64;
    await page.goto(`${baseUrl}/requests?id=2`);
    await page.getByRole('button', { name: 'Zmień termin lub wymiar', exact: true }).click();
    dialog = page.getByRole('dialog', { name: 'Zmień termin lub wymiar', exact: true });
    await dialog.getByLabel('Data od', { exact: true }).fill('2026-11-23');
    await dialog.getByLabel('Data do', { exact: true }).fill('2026-11-25');
    await dialog.getByText('Dodatkowa rezerwacja: 8 h.', { exact: true }).waitFor();
    await dialog.getByText('56 h = 7 dni', { exact: true }).waitFor();
    await dialog.getByRole('button', { name: 'Wyślij propozycję', exact: true }).click();
    await dialog.waitFor({ state: 'detached' });
    await page.getByRole('button', { name: 'Wycofaj propozycję', exact: true }).waitFor();
    assert.equal(requests.get(2).effective.totalHours, 16); assert.equal(requests.get(2).pending.totalHours, 24);
    await page.getByRole('heading', { name: 'Obowiązujący urlop', exact: true }).waitFor();
    await page.getByRole('heading', { name: 'Proponowana zmiana', exact: true }).waitFor();
    await page.locator('.requests-revision-card.is-effective').getByText('16 h = 2 dni', { exact: true }).waitFor();
    await page.locator('.requests-revision-card.is-proposed').getByText('24 h = 3 dni', { exact: true }).waitFor();
    await page.screenshot({ path: join(screenshotDir, 'request-effective-and-proposed.png'), fullPage: true });
    await page.getByRole('button', { name: 'Wycofaj propozycję', exact: true }).click();
    await page.getByRole('button', { name: 'Potwierdź wycofanie', exact: true }).click();
    await page.getByRole('dialog').waitFor({ state: 'detached' });
    await waitFor(() => requests.get(2).status === 'approved', 'Proposal withdrawal was not sent.');
    await page.getByRole('button', { name: 'Anuluj urlop', exact: true }).click();
    assert.equal(available, 64);
    await page.getByRole('button', { name: 'Wyślij prośbę o anulowanie', exact: true }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Ponów wysłanie', exact: true }).waitFor();
    const cancellationHistoryLength = requests.get(2).history.length;
    assert.equal(available, 64, 'A malformed cancellation receipt does not authorize an early refund.');
    await page.getByRole('dialog').getByRole('button', { name: 'Ponów wysłanie', exact: true }).click();
    await page.getByRole('dialog').waitFor({ state: 'detached' });
    assert.deepEqual(writes.at(-1), writes.at(-2));
    assert.equal(requests.get(2).history.length, cancellationHistoryLength, 'Replaying cancellation cannot add another proposal.');
    await waitFor(() => requests.get(2).status === 'cancellation_pending', 'Cancellation proposal was not sent.');
    assert.equal(available, 64, 'No early refund on cancellation proposal.');
    const cancellationBadge = page.getByRole('region', { name: 'Szczegóły wniosku', exact: true }).locator('.requests-status-cancellation_pending');
    await cancellationBadge.waitFor();
    assert.match((await cancellationBadge.innerText()).trim(), /Anulowanie oczekuje$/, 'The visible detail badge must show the pending cancellation status.');
    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Request history must fit mobile width.');
    await page.screenshot({ path: join(screenshotDir, 'requests-mobile.png'), fullPage: true });

    await page.getByRole('button', { name: 'Złóż wniosek', exact: true }).first().click();
    dialog = page.getByRole('dialog', { name: 'Nowy wniosek', exact: true });
    await dialog.getByRole('radio', { name: /Część dnia/ }).check();
    await dialog.getByRole('status', { name: 'Podsumowanie wniosku' }).getByText('3 h', { exact: true }).waitFor();
    await page.screenshot({ path: join(screenshotDir, 'request-hourly-mobile.png'), fullPage: true });
    await page.keyboard.press('Escape');
    await dialog.getByRole('heading', { name: 'Zamknąć formularz?', exact: true }).waitFor();
    await dialog.getByRole('button', { name: 'Zamknij formularz', exact: true }).last().click();
    await dialog.waitFor({ state: 'detached' });

    // Failed preview never enables submission or leaks the raw backend message.
    denyPreview = true;
    await page.getByRole('button', { name: 'Złóż wniosek', exact: true }).first().click();
    dialog = page.getByRole('dialog', { name: 'Nowy wniosek', exact: true });
    await dialog.getByRole('alert').filter({ hasText: 'Brakuje dostępnych godzin' }).waitFor();
    assert.equal(await dialog.getByRole('button', { name: 'Złóż wniosek', exact: true }).isDisabled(), true);
    assert.equal(await page.getByText('Synthetic private debug text must never be displayed.').count(), 0);
    assert.equal(await dialog.evaluate(element => element.getBoundingClientRect().width <= innerWidth + 1 && element.scrollWidth <= element.clientWidth + 1), true, 'Mobile modal fits the viewport without internal horizontal overflow.');
    await page.screenshot({ path: join(screenshotDir, 'request-validation-mobile.png'), fullPage: true });
    await page.keyboard.press('Escape'); await dialog.waitFor({ state: 'detached' }); denyPreview = false;

    // A concurrently modified request must be reloaded before it can be submitted again.
    requests.clear(); requests.set(1, requestFixture(1, revision(1, 3, ['2026-11-10']))); available = 77;
    await page.goto(`${baseUrl}/requests?id=1`);
    await page.getByRole('button', { name: 'Edytuj wniosek', exact: true }).click();
    dialog = page.getByRole('dialog', { name: 'Edytuj wniosek', exact: true });
    await dialog.getByLabel('Liczba godzin', { exact: true }).selectOption('5');
    await dialog.getByText('75 h = 9 dni i 3 h', { exact: true }).waitFor();
    conflictNextEdit = true;
    await dialog.getByRole('button', { name: 'Zapisz zmianę', exact: true }).click();
    await dialog.getByRole('button', { name: 'Wczytaj aktualne dane', exact: true }).waitFor();
    assert.equal(await dialog.getByRole('button', { name: 'Zapisz zmianę', exact: true }).isDisabled(), true);
    await dialog.getByRole('button', { name: 'Wczytaj aktualne dane', exact: true }).click();
    await dialog.getByRole('status', { name: 'Podsumowanie wniosku' }).waitFor();
    assert.equal(await dialog.getByLabel('Liczba godzin', { exact: true }).inputValue(), '3');
    await page.keyboard.press('Escape'); await dialog.waitFor({ state: 'detached' });

    listFailure = true; await page.goto(`${baseUrl}/requests`);
    await page.getByRole('alert').waitFor(); assert.equal(await page.getByRole('button', { name: 'Szczegóły wniosku 1' }).count(), 0);
    listFailure = false;
    const mobileCalendarStart = calendarCalls.length;
    await page.goto(`${baseUrl}/`);
    await page.getByRole('heading', { name: 'Kalendarz zespołu', exact: true }).waitFor();
    await page.getByRole('heading', { name: 'Nadchodzące nieobecności', exact: true }).waitFor();
    await waitFor(() => calendarCalls.slice(mobileCalendarStart).some(call => call.from === '2026-11-01' && call.to === '2026-11-30'), 'Mobile calendar must request the full current month by default.');
    await page.locator('.mobile-calendar-day').first().waitFor();
    assert.equal(await page.locator('.mobile-calendar-day').count(), 30);
    assert.equal(await page.getByRole('button', { name: 'Miesiąc', exact: true }).getAttribute('aria-pressed'), 'true');
    assert.equal(await page.getByRole('button', { name: 'Złóż wniosek', exact: true }).first().isVisible(), true);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Calendar must fit mobile width.');
    await page.screenshot({ path: join(screenshotDir, 'calendar-home-mobile.png'), fullPage: true });
    const desktopCalendarStart = calendarCalls.length;
    await page.setViewportSize({ width: 1440, height: 1050 }); await page.reload();
    await page.getByRole('heading', { name: 'Kalendarz zespołu', exact: true }).waitFor();
    await waitFor(() => calendarCalls.slice(desktopCalendarStart).some(call => call.from === '2026-11-01' && call.to === '2026-11-30'), 'Desktop calendar must request the full current month by default.');
    await waitFor(() => calendarCalls.some(call => (Date.parse(call.to) - Date.parse(call.from)) / 86400000 >= 58), 'Upcoming list must request approximately two months.');
    await page.getByRole('table').waitFor();
    assert.equal(await page.locator('.team-calendar-table thead th').count(), 31, 'Desktop table has one name column and thirty November dates.');
    assert.equal(await page.getByRole('button', { name: 'Miesiąc', exact: true }).getAttribute('aria-pressed'), 'true');
    assert.equal(await page.locator('.team-calendar-table tbody tr').count(), 10, 'All ten synthetic team members are rendered.');
    const splitDay = page.getByRole('table').getByRole('row', { name: /Osoba 2 Testowa/ });
    await splitDay.getByText('3 h', { exact: true }).waitFor();
    await splitDay.getByText('5 h', { exact: true }).waitFor();
    assert.equal(await page.locator('.calendar-desktop-view a.calendar-entry').count(), 0, 'Coworker calendar does not expose private detail links.');
    assert.equal(await page.getByText('Urlop wypoczynkowy', { exact: true }).count(), 0, 'Calendar does not display coworkers’ absence types.');
    await page.getByRole('button', { name: 'Tydzień', exact: true }).click();
    await page.getByRole('table').waitFor();
    assert.equal(await page.locator('.team-calendar-table thead th').count(), 8);
    await page.getByRole('button', { name: '2 tygodnie', exact: true }).click();
    await page.getByRole('table').waitFor();
    await page.screenshot({ path: join(screenshotDir, 'calendar-home-desktop.png'), fullPage: true });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
    for (let period = 0; period < 3; period++) {
      await page.getByRole('button', { name: 'Następny okres', exact: true }).click();
      await page.getByRole('table').waitFor();
    }
    await waitFor(() => calendarCalls.some(call => call.from === '2026-12-21' && call.to === '2027-01-03'), 'Calendar navigation must cross the year boundary correctly.');
    await page.getByRole('table').getByText('Nowy Rok', { exact: true }).waitFor({ state: 'attached' });
    assert.equal(await page.locator('.team-calendar-table thead th').count(), 15);
    await page.screenshot({ path: join(screenshotDir, 'calendar-year-boundary.png'), fullPage: true });
    await page.getByRole('button', { name: 'Dzisiaj', exact: true }).click();
    await page.getByRole('table').waitFor();
    session = null;
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await page.waitForURL(url => url.pathname === '/login');
    await page.getByRole('heading', { name: 'Zaloguj się', exact: true }).waitFor();
    assert.equal(await page.getByText('Demonstracyjna-Bardzo-Długie-Nazwisko', { exact: true }).count(), 0);
    assert.equal(await page.evaluate(() => localStorage.length + sessionStorage.length), 0);
    assert.deepEqual(errors, [], 'No unhandled browser exceptions.');
    console.log('PASS Chrome employee UI: default 8 h and hourly 3 h, server preview, keyboard modal/focus return, duplicate-click guard and lost-response retry, pending edit/withdraw, effective/proposed separation, cancellation without early refund, backend validation, conflict/reload, mobile layout, calendar periods/upcoming/CTA, revoked session and empty browser storage. API responses are synthetic.');
    await context.close();
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
