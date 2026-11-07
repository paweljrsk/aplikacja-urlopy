'use strict';

// UI behavior uses explicit synthetic responses; real authorization/accounting is
// checked independently by PHP/MySQL and managed-requests-e2e-check.cjs.
const assert = require('node:assert/strict');
const { mkdirSync } = require('node:fs');
const { join } = require('node:path');
const { chromium } = require('playwright-core');
const baseUrl = (process.env.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const target = new URL(baseUrl);
assert.ok(target.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(target.hostname));
const screenshotDir = process.env.UI_SCREENSHOT_DIR || '/tmp/urlopy-ui-screens';
mkdirSync(screenshotDir, { recursive: true });
const today = '2026-11-09';
const team = { id: 1, code: 'synthetic', name: 'Zespół testowy' };
const employee = { id: 1, firstName: 'Anna', lastName: 'Testowa', email: 'management_employee@example.test', role: 'employee', team, isAdministrator: false, mustChangePassword: false };
const manager = { ...employee, id: 2, firstName: 'Marek', lastName: 'Kierownik', email: 'management_manager@example.test', role: 'manager' };
const deputy = { ...employee, id: 3, firstName: 'Ewa', lastName: 'Zastępczyni', email: 'management_deputy@example.test', role: 'deputy' };
const administrator = { ...employee, id: 4, firstName: 'Ada', lastName: 'Administrator', email: 'management_admin@example.test', role: null, team: null, isAdministrator: true };
const other = { ...employee, id: 20, firstName: 'Olga', lastName: 'Demonstracyjna-Bardzo-Długie-Nazwisko', email: 'management_other@example.test', team: { id: 2, code: 'other', name: 'Drugi zespół' } };
const paid = { id: 1, code: 'paid', revision: 1, name: 'Syntetyczny płatny urlop', deductsBalance: true, availableForCurrentEmployment: true };
const options = { today, timezone: 'Europe/Warsaw', hoursPerDay: 8, modes: ['full_days', 'hourly'], absenceTypes: [paid] };
const noActions = { edit: false, withdraw: false, approve: false, reject: false, change: false, cancel: false, correct: false };
const awaiting = ['pending', 'change_pending', 'cancellation_pending'];
const debugMessage = 'Synthetic server debug data must not appear in the UI';
const copy = value => structuredClone(value);
function revision(id, owner, hours, date, kind = 'initial', number = 1) {
  const days = hours > 8 ? [{ date, hours: 8 }, { date: '2026-11-17', hours: hours - 8 }] : [{ date, hours }];
  return { id: id * 10 + number, revision: number, baseRevision: kind === 'initial' ? null : 1, kind,
    mode: hours < 8 ? 'hourly' : 'full_days', startDate: date, endDate: days.at(-1).date, totalHours: hours, days, absenceType: paid,
    submitted: { at: '2026-11-01T08:00:00+00:00', actorName: `${owner.firstName} ${owner.lastName}`, reason: null }, decision: null };
}
function fixture(id, owner, status, hours, date = '2026-11-12') {
  const effective = status !== 'pending' ? revision(id, owner, hours, date) : null;
  if (effective) effective.decision = { outcome: 'approved', at: '2026-11-02T09:00:00+00:00', actorName: 'Marek Kierownik', reason: null };
  const pending = status === 'pending' ? revision(id, owner, hours, date)
    : status === 'change_pending' ? revision(id, owner, 16, '2026-11-16', 'change', 2)
      : status === 'cancellation_pending' ? { ...revision(id, owner, hours, date, 'cancellation', 2) } : null;
  const current = pending ?? effective;
  return { id, reference: `synthetic-managed-${id}`, version: 1, status, currentVisible: true,
    user: { id: owner.id, firstName: owner.firstName, lastName: owner.lastName }, latestVisibleRevision: current,
    current, pending, effective, history: [effective, pending].filter(Boolean), actions: noActions, teamId: owner.team?.id ?? 1 };
}
function snapshot(value) {
  return { revision: value.revision, kind: value.kind, absenceTypeId: value.absenceType.id, policyRevision: 1,
    deductsBalance: value.absenceType.deductsBalance, mode: value.mode, startDate: value.startDate, endDate: value.endDate,
    totalHours: value.totalHours, days: value.days };
}
function operation(value) { return { operation: { id: value.id, reference: value.reference, version: value.version, revision: value.current.revision, status: value.status } }; }
async function until(predicate, description) {
  const deadline = Date.now() + 12000;
  while (!await predicate()) {
    if (Date.now() >= deadline) throw new Error(description);
    await new Promise(resolve => setTimeout(resolve, 20));
  }
}

let step = 'launch';
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  let page;
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1050 }, locale: 'pl-PL', timezoneId: 'Europe/Warsaw' });
    let session = manager;
    const requests = new Map([
      fixture(101, manager, 'pending', 3), fixture(102, employee, 'pending', 5), fixture(103, deputy, 'pending', 2),
      fixture(104, employee, 'change_pending', 8), fixture(105, employee, 'cancellation_pending', 8),
      fixture(106, employee, 'approved', 6, '2026-11-05'), fixture(107, employee, 'pending', 4, '2026-11-19'),
      fixture(201, other, 'pending', 4),
    ].map(value => [value.id, value]));
    const writes = []; const previews = []; const reads = []; const receipts = new Map(); const errors = [];
    let loseResponseId = 101; let malformedReceiptId = 104; let deniedDetailId = null; let conflictId = null; let staleCorrection = false; let deficit = false; let paginate = false;
    let decisionPreviewGate = null; let correctionPreviewGate = null; let heldPreviews = 0;
    const canRead = value => value.id !== deniedDetailId && (session.isAdministrator || (session.role !== 'employee' && value.teamId === session.team?.id) || value.user.id === session.id);
    const canDecide = value => canRead(value) && (session.isAdministrator || session.role === 'manager' || session.role === 'deputy' && value.user.id !== session.id);
    function dto(value) {
      const result = copy(value); delete result.teamId;
      result.actions = { ...noActions, approve: awaiting.includes(value.status) && canDecide(value), reject: awaiting.includes(value.status) && canDecide(value),
        correct: value.status === 'approved' && value.current.startDate < today && canDecide(value) };
      return result;
    }
    await context.route('**/api/**', async route => {
      const req = route.request(); const url = new URL(req.url()); const path = url.pathname; const method = req.method();
      const reply = (status, body) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
      const error = (status, code) => reply(status, { error: { code, message: debugMessage } });
      if (!session) return error(401, 'AUTH_REQUIRED');
      if (path === '/api/auth/me') return reply(200, { user: session });
      if (path === '/api/auth/csrf') return reply(200, { csrfToken: 'synthetic-managed-csrf' });
      if (session.mustChangePassword) return error(403, 'PASSWORD_CHANGE_REQUIRED');
      if (method === 'GET') {
        reads.push(path + url.search);
        if (path === '/api/requests/options') return reply(200, options);
        if (path === '/api/requests') {
          assert.equal(url.searchParams.get('scope'), 'managed');
          if (!session.isAdministrator && !['manager', 'deputy'].includes(session.role)) return error(403, 'FORBIDDEN');
          const status = url.searchParams.get('status'); const cursor = url.searchParams.get('beforeId');
          let values = [...requests.values()].filter(value => canRead(value) && (!status || status === 'awaiting' ? !status || awaiting.includes(value.status) : value.status === status));
          values.sort((a, b) => b.id - a.id);
          if (paginate && !cursor) return reply(200, { requests: [], nextBeforeId: 999 });
          if (cursor) assert.equal(cursor, '999', 'The next page must follow the exact server cursor, even after an empty page.');
          return reply(200, { requests: values.map(dto), nextBeforeId: null });
        }
        const match = path.match(/^\/api\/requests\/(\d+)$/);
        if (match) {
          const value = requests.get(Number(match[1]));
          if (!value) return error(404, 'NOT_FOUND');
          if (value.id === deniedDetailId) { await new Promise(resolve => setTimeout(resolve, 200)); return error(403, 'FORBIDDEN'); }
          return canRead(value) ? reply(200, { request: dto(value) }) : error(403, 'FORBIDDEN');
        }
        throw new Error(`Unexpected synthetic GET ${path}`);
      }
      assert.equal(method, 'POST'); assert.equal(req.headers()['x-csrf-token'], 'synthetic-managed-csrf', 'Every preview and write needs fresh CSRF.');
      const match = path.match(/^\/api\/requests\/(\d+)\/(decision-preview|approve|reject|correction-preview|correct)$/);
      assert.ok(match, `Unexpected synthetic mutation ${path}`);
      const id = Number(match[1]); const action = match[2]; const value = requests.get(id); const body = req.postDataJSON();
      assert.ok(value); if (!canDecide(value)) return error(403, 'FORBIDDEN');
      if (action.endsWith('preview')) {
        assert.equal('operationKey' in body, false); previews.push({ path, body: copy(body) });
        if (body.expectedVersion !== value.version) return error(409, 'CONFLICT');
        const gate = action === 'decision-preview' ? decisionPreviewGate : correctionPreviewGate;
        if (gate) { ++heldPreviews; await gate; }
        if (action === 'decision-preview') {
          assert.ok(['approve', 'reject'].includes(body.action));
          if (body.action === 'reject') assert.ok(body.reason.trim());
          assert.ok(awaiting.includes(value.status));
          const refund = body.action === 'reject' ? value.status === 'pending' ? value.pending.totalHours : value.status === 'change_pending' ? 8 : 0 : value.status === 'cancellation_pending' ? 8 : 0;
          return reply(200, { preview: { id, version: value.version, action: body.action, statusBefore: value.status,
            statusAfter: value.status === 'pending' ? body.action === 'approve' ? 'approved' : 'rejected' : value.status === 'cancellation_pending' && body.action === 'approve' ? 'cancelled' : 'approved',
            years: [{ year: 2026, beforeAvailableHours: 47, afterAvailableHours: 47 + refund,
              beforeReservedHours: value.status === 'pending' ? value.pending.totalHours : value.status === 'change_pending' ? 8 : 0, afterReservedHours: 0,
              beforeApprovedHours: value.effective?.totalHours ?? 0, afterApprovedHours: body.action === 'approve' ? value.status === 'cancellation_pending' ? 0 : value.pending.totalHours : value.effective?.totalHours ?? 0 }] } });
        }
        assert.ok(body.reason.trim()); assert.ok(['change', 'cancel'].includes(body.action));
        if (deficit && !session.isAdministrator) return error(422, 'INSUFFICIENT_BALANCE');
        const after = body.action === 'cancel' ? { ...snapshot(value.current), cancelled: true, totalHours: 0, days: [] } : { ...snapshot(value.current), revision: value.current.revision + 1, kind: 'change', mode: body.mode, startDate: body.startDate, endDate: body.endDate, totalHours: body.mode === 'hourly' ? body.hours : 8,
          days: [{ date: body.startDate, hours: body.mode === 'hourly' ? body.hours : 8 }] };
        const afterHours = after?.totalHours ?? 0;
        return reply(200, { preview: { id, version: value.version, action: body.action, previewToken: `synthetic-preview-${value.version}-${previews.length}`, before: snapshot(value.current), after,
          impact: { hoursByYear: [{ year: 2026, beforeHours: value.current.totalHours, afterHours, deltaHours: afterHours - value.current.totalHours }],
            pools: { before: [{ originYear: 2026, allocatedHours: 80, reservedHours: 5, chargedHours: 6, availableHours: 69 }], after: [{ originYear: 2026, allocatedHours: 80, reservedHours: 5, chargedHours: afterHours, availableHours: deficit ? -2 : 75 - afterHours }] } },
          requiresDeficitConfirmation: deficit } });
      }
      assert.equal(typeof body.operationKey, 'string'); assert.ok(body.operationKey); writes.push({ path, body: copy(body) });
      if (receipts.has(body.operationKey)) {
        const previous = receipts.get(body.operationKey);
        assert.equal(previous.path, path); assert.deepEqual(previous.body, body, 'An uncertain response must be retried with the identical operation key and payload.');
        return reply(200, previous.response);
      }
      if (id === conflictId) {
        conflictId = null; value.version++; value.status = 'approved'; value.effective = value.pending; value.pending = null; value.current = value.effective;
        value.current.decision = { outcome: 'approved', at: '2026-11-09T11:00:00+00:00', actorName: 'Inny Kierownik', reason: null };
        return error(409, 'CONFLICT');
      }
      if (action === 'correct' && staleCorrection) { staleCorrection = false; value.version++; return error(409, 'STALE_PREVIEW'); }
      assert.equal(body.expectedVersion, value.version);
      if (action === 'correct') {
        assert.equal(body.confirm, true); assert.match(body.previewToken, /^synthetic-preview-/); assert.ok(body.reason.trim());
        if (deficit) { assert.equal(session.isAdministrator, true); assert.equal(body.allowDeficit, true); }
        const next = body.action === 'cancel' ? { ...copy(value.current), id: id * 10 + value.history.length + 1, revision: value.history.length + 1, kind: 'cancellation' }
          : revision(id, value.user, body.mode === 'hourly' ? body.hours : 8, body.startDate, 'change', value.history.length + 1);
        next.submitted = { at: '2026-11-09T12:00:00+00:00', actorName: `${session.firstName} ${session.lastName}`, reason: body.reason };
        next.decision = { outcome: 'approved', at: '2026-11-09T12:00:00+00:00', actorName: `${session.firstName} ${session.lastName}`, reason: body.reason };
        value.history.push(next); value.current = next; value.latestVisibleRevision = next; value.effective = body.action === 'cancel' ? null : next; value.status = body.action === 'cancel' ? 'cancelled' : 'approved';
      } else {
        assert.ok(awaiting.includes(value.status));
        const pending = value.pending;
        pending.decision = { outcome: action === 'approve' ? 'approved' : 'rejected', at: '2026-11-09T12:00:00+00:00', actorName: `${session.firstName} ${session.lastName}`, reason: body.reason ?? null };
        if (action === 'reject') { assert.ok(body.reason.trim()); value.status = value.effective ? 'approved' : 'rejected'; }
        else if (pending.kind === 'cancellation') { value.status = 'cancelled'; value.effective = null; }
        else { value.status = 'approved'; value.effective = pending; }
        value.pending = null; value.current = value.effective ?? pending; value.latestVisibleRevision = value.current;
      }
      value.version++; const response = operation(value);
      receipts.set(body.operationKey, { path, body: copy(body), response: copy(response) });
      if (id === malformedReceiptId) { malformedReceiptId = null; return reply(200, { operation: { id } }); }
      if (id === loseResponseId) { loseResponseId = null; await new Promise(resolve => setTimeout(resolve, 150)); return route.abort('failed'); }
      return reply(200, response);
    });
    page = await context.newPage(); page.setDefaultTimeout(12000);
    page.on('pageerror', error => errors.push(error.message));
    await page.clock.setFixedTime(new Date('2026-11-09T10:00:00Z'));

    async function open(id) {
      await page.goto(`${baseUrl}/management?id=${id}`);
      await page.getByRole('heading', { name: 'Obsługa wniosków', exact: true }).waitFor();
      await page.getByRole('heading', { name: 'Szczegóły wniosku', exact: true }).waitFor();
    }
    async function decision(action) {
      await page.getByRole('button', { name: action === 'approve' ? 'Akceptuj' : 'Odrzuć', exact: true }).click();
      const dialog = page.getByRole('dialog', { name: action === 'approve' ? 'Akceptacja wniosku' : 'Odrzucenie wniosku', exact: true });
      await dialog.waitFor(); return dialog;
    }
    async function previewDecision(dialog) {
      await dialog.getByRole('button', { name: 'Sprawdź wpływ', exact: true }).click();
      await dialog.getByRole('region', { name: 'Wpływ decyzji na saldo', exact: true }).waitFor();
    }
    async function correction() {
      await page.getByRole('button', { name: 'Skoryguj przeszły wniosek', exact: true }).click();
      const dialog = page.getByRole('dialog', { name: 'Korekta nieobecności', exact: true });
      await dialog.getByLabel('Uzasadnienie korekty', { exact: true }).waitFor(); return dialog;
    }
    async function previewCorrection(dialog) {
      await dialog.getByRole('button', { name: 'Sprawdź skutki korekty', exact: true }).click();
      await dialog.getByRole('region', { name: 'Podgląd korekty', exact: true }).waitFor();
    }
    const mutationCount = id => writes.filter(write => write.path.match(new RegExp(`/requests/${id}/`))).length;

    step = 'manager queue and own decision, explicit server preview';
    await open(101);
    assert.ok(reads.some(path => path.includes('scope=managed') && path.includes('status=awaiting')));
    await page.locator('[data-request-id="104"]').waitFor(); await page.locator('[data-request-id="105"]').waitFor();
    assert.equal(await page.locator('[data-request-id="201"]').count(), 0, 'Another team must not appear in a manager queue.');
    await page.getByRole('button', { name: 'Zamknij szczegóły', exact: true }).click();
    await page.screenshot({ path: join(screenshotDir, 'managed-requests-desktop.png'), fullPage: true });
    await page.getByRole('button', { name: 'Szczegóły wniosku 101', exact: true }).click();
    let dialog = await decision('approve');
    assert.equal(await dialog.getByRole('button', { name: 'Potwierdź akceptację', exact: true }).count(), 0, 'A decision cannot be committed before its server preview.');
    await previewDecision(dialog);
    assert.match(await dialog.getByRole('region', { name: 'Wpływ decyzji na saldo', exact: true }).innerText(), /47 h/);
    assert.equal(writes.length, 0, 'Inspecting a decision never commits it.');
    await page.keyboard.press('Tab'); await page.keyboard.press('Shift+Tab');
    assert.equal(await page.evaluate(() => !!document.activeElement?.closest('dialog[open]')), true);
    await page.screenshot({ path: join(screenshotDir, 'managed-decision-desktop.png') });
    await dialog.getByRole('button', { name: 'Potwierdź akceptację', exact: true }).dblclick({ force: true });
    await dialog.getByRole('button', { name: 'Ponów decyzję', exact: true }).waitFor();
    assert.equal(mutationCount(101), 1, 'Double click submits one logical decision.');
    const readsBeforeUncertainFocus = reads.filter(path => path === '/api/requests/101').length;
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await until(() => reads.filter(path => path === '/api/requests/101').length > readsBeforeUncertainFocus, 'Focus refreshes background data during an uncertain decision.');
    await dialog.getByRole('button', { name: 'Ponów decyzję', exact: true }).waitFor();
    assert.equal(mutationCount(101), 1, 'Focus must preserve the pending operation rather than resubmit it.');
    await dialog.getByRole('button', { name: 'Ponów decyzję', exact: true }).click();
    await dialog.waitFor({ state: 'detached' });
    assert.equal(mutationCount(101), 2); assert.equal(receipts.size, 1, 'Lost-response retry must not create a second decision.');
    assert.equal(requests.get(101).status, 'approved');
    await until(async () => await page.locator('[data-request-id="101"]').count() === 0, 'Approved own request leaves the awaiting queue.');

    await page.locator('#managed-status').selectOption('approved');
    await page.locator('[data-request-id="101"]').waitFor();
    assert.equal(await page.locator('[data-request-id="102"]').count(), 0, 'Approved history excludes still-pending requests.');
    await page.locator('#managed-status').selectOption('awaiting');
    await page.locator('[data-request-id="102"]').waitFor();

    step = 'rejection requires reason and invalidates its preview when edited';
    await open(102); dialog = await decision('reject');
    assert.equal(await dialog.getByRole('button', { name: 'Sprawdź wpływ', exact: true }).isDisabled(), true);
    await dialog.getByLabel('Powód odrzucenia', { exact: true }).fill('Syntetyczne uzasadnienie pierwsze');
    await previewDecision(dialog);
    assert.match(await dialog.getByRole('region', { name: 'Wpływ decyzji na saldo', exact: true }).innerText(), /52 h/);
    await dialog.getByLabel('Powód odrzucenia', { exact: true }).fill('Syntetyczne uzasadnienie ostateczne');
    assert.equal(await dialog.getByRole('region', { name: 'Wpływ decyzji na saldo', exact: true }).count(), 0);
    assert.equal(await dialog.getByRole('button', { name: 'Potwierdź odrzucenie', exact: true }).count(), 0);
    await previewDecision(dialog); await dialog.getByRole('button', { name: 'Potwierdź odrzucenie', exact: true }).click();
    await dialog.waitFor({ state: 'detached' });
    assert.equal(requests.get(102).status, 'rejected');
    assert.equal(writes.find(write => write.path.endsWith('/102/reject')).body.reason, 'Syntetyczne uzasadnienie ostateczne');

    step = 'changed request comparison, cancellation and author/decision history';
    await open(104);
    await page.getByRole('heading', { name: 'Obowiązujący urlop', exact: true }).waitFor();
    await page.getByRole('heading', { name: 'Proponowana zmiana', exact: true }).waitFor();
    dialog = await decision('approve'); await previewDecision(dialog);
    assert.match(await dialog.getByRole('region', { name: 'Wpływ decyzji na saldo', exact: true }).innerText(), /8 h → 16 h/);
    await dialog.getByRole('button', { name: 'Potwierdź akceptację', exact: true }).click();
    await dialog.getByRole('button', { name: 'Ponów decyzję', exact: true }).waitFor();
    assert.equal(mutationCount(104), 1, 'Malformed successful JSON is an uncertain result, not a confirmed decision.');
    await dialog.getByRole('button', { name: 'Ponów decyzję', exact: true }).click(); await dialog.waitFor({ state: 'detached' });
    assert.equal(mutationCount(104), 2); assert.equal(requests.get(104).effective.totalHours, 16);
    assert.equal(requests.get(104).history.length, 2, 'Receipt retry does not append another revision.');
    await open(105); dialog = await decision('approve'); await previewDecision(dialog);
    assert.match(await dialog.getByRole('region', { name: 'Wpływ decyzji na saldo', exact: true }).innerText(), /55 h/);
    await dialog.getByRole('button', { name: 'Potwierdź akceptację', exact: true }).click(); await dialog.waitFor({ state: 'detached' });
    assert.equal(requests.get(105).status, 'cancelled');
    await page.locator('.requests-history > summary').click();
    const historyText = await page.locator('.requests-history').innerText();
    assert.match(historyText, /Anna Testowa/); assert.match(historyText, /Marek Kierownik/);
    assert.match(historyText, /Zgłoszono/); assert.match(historyText, /Decyzja/);

    step = 'concurrent decision returns a conflict, then fresh state removes actions';
    await open(107); dialog = await decision('approve'); await previewDecision(dialog); conflictId = 107;
    await dialog.getByRole('button', { name: 'Potwierdź akceptację', exact: true }).click();
    await dialog.getByRole('alert').waitFor();
    assert.equal(await dialog.getByRole('button', { name: 'Potwierdź akceptację', exact: true }).count(), 0);
    await dialog.getByRole('button', { name: 'Wczytaj aktualne dane', exact: true }).click(); await dialog.waitFor({ state: 'detached' });
    await page.getByRole('heading', { name: 'Szczegóły wniosku', exact: true }).waitFor();
    await until(async () => await page.getByRole('button', { name: 'Akceptuj', exact: true }).count() === 0, 'An already decided request no longer offers another decision.');
    assert.equal(mutationCount(107), 1);

    step = 'idle focus reauthorization clears stale private decision details';
    await open(103); dialog = await decision('approve'); await previewDecision(dialog);
    const readsBeforeDeniedFocus = reads.filter(path => path === '/api/requests/103').length; deniedDetailId = 103;
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await until(() => reads.filter(path => path === '/api/requests/103').length > readsBeforeDeniedFocus, 'Open decision is reauthorized on focus.');
    assert.equal(await dialog.locator('.managed-decision-person, .requests-revisions, .managed-decision-preview').count(), 0, 'Private data is removed before the new authorization completes.');
    await dialog.getByRole('alert').waitFor();
    assert.equal(await dialog.getByRole('button', { name: 'Potwierdź akceptację', exact: true }).count(), 0);
    assert.equal(await dialog.getByText(paid.name, { exact: true }).count(), 0);
    await dialog.getByRole('button', { name: 'Wróć', exact: true }).click(); await dialog.waitFor({ state: 'detached' });
    await page.locator('.requests-detail .message-error').waitFor();
    deniedDetailId = null;

    step = 'pending decision preview cannot restore details after revoked access';
    await open(103); dialog = await decision('approve');
    let releaseDecisionPreview;
    decisionPreviewGate = new Promise(resolve => { releaseDecisionPreview = resolve; });
    const heldBeforeDecision = heldPreviews;
    const delayedDecisionResponse = page.waitForResponse(response => new URL(response.url()).pathname === '/api/requests/103/decision-preview');
    await dialog.getByRole('button', { name: 'Sprawdź wpływ', exact: true }).click();
    await until(() => heldPreviews > heldBeforeDecision, 'Hold the in-flight decision preview before changing access.');
    deniedDetailId = 103; await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await page.locator('.managed-detail').waitFor({ state: 'detached' });
    await dialog.getByRole('alert').waitFor();
    releaseDecisionPreview(); await (await delayedDecisionResponse).finished(); decisionPreviewGate = null;
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    assert.equal(await dialog.locator('.managed-decision-person, .requests-revisions, .managed-decision-preview').count(), 0, 'A previously authorized preview response cannot revive revoked private decision data.');
    assert.equal(await dialog.getByRole('button', { name: 'Potwierdź akceptację', exact: true }).count(), 0);
    assert.equal(mutationCount(103), 0);
    await dialog.getByRole('button', { name: 'Wróć', exact: true }).click(); await dialog.waitFor({ state: 'detached' });
    await page.locator('.requests-detail .message-error').waitFor(); deniedDetailId = null;

    step = 'deputy may decide another employee request, never own';
    session = deputy; await open(103);
    await until(() => reads.filter(path => path === '/api/requests/103').length > 0, 'Deputy own details should load.');
    assert.equal(await page.getByRole('button', { name: 'Akceptuj', exact: true }).count(), 0);
    assert.equal(await page.getByRole('button', { name: 'Odrzuć', exact: true }).count(), 0);
    assert.equal(await page.locator('[data-request-id="103"]').count(), 0, 'Own deputy request is not offered in the actionable queue.');
    requests.set(108, fixture(108, employee, 'pending', 2, '2026-11-20'));
    await open(108); dialog = await decision('approve'); await previewDecision(dialog);
    await dialog.getByRole('button', { name: 'Potwierdź akceptację', exact: true }).click(); await dialog.waitFor({ state: 'detached' });
    assert.equal(requests.get(108).current.decision.actorName, 'Ewa Zastępczyni');
    assert.equal(mutationCount(103), 0);

    step = 'pending correction preview cannot restore a revoked form';
    session = manager; await open(106); dialog = await correction();
    await dialog.getByLabel('Liczba godzin', { exact: true }).selectOption('4');
    await dialog.getByLabel('Uzasadnienie korekty', { exact: true }).fill('Syntetyczny podgląd przed odebraniem dostępu');
    let releaseCorrectionPreview;
    correctionPreviewGate = new Promise(resolve => { releaseCorrectionPreview = resolve; });
    const heldBeforeCorrection = heldPreviews;
    const delayedCorrectionResponse = page.waitForResponse(response => new URL(response.url()).pathname === '/api/requests/106/correction-preview');
    await dialog.getByRole('button', { name: 'Sprawdź skutki korekty', exact: true }).click();
    await until(() => heldPreviews > heldBeforeCorrection, 'Hold the in-flight correction preview before changing access.');
    deniedDetailId = 106; await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await page.locator('.managed-detail').waitFor({ state: 'detached' });
    await dialog.getByRole('alert').waitFor();
    releaseCorrectionPreview(); await (await delayedCorrectionResponse).finished(); correctionPreviewGate = null;
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    assert.equal(await dialog.locator('.correction-person, .correction-preview, .correction-confirmations').count(), 0, 'A late successful correction preview cannot reveal revoked private data or confirmation controls.');
    assert.equal(await dialog.getByRole('button', { name: 'Zapisz korektę', exact: true }).count(), 0);
    assert.equal(mutationCount(106), 0);
    await dialog.getByRole('button', { name: 'Wróć do wniosku', exact: true }).click(); await dialog.waitFor({ state: 'detached' });
    await page.locator('.requests-detail .message-error').waitFor(); deniedDetailId = null;

    step = 'historical correction preview, invalidation, stale token, unchanged retry';
    session = manager; await open(106); dialog = await correction();
    assert.equal(await dialog.getByRole('button', { name: 'Sprawdź skutki korekty', exact: true }).isDisabled(), true);
    await dialog.getByLabel('Liczba godzin', { exact: true }).selectOption('3');
    await dialog.getByLabel('Uzasadnienie korekty', { exact: true }).fill('Syntetyczna korekta wymiaru');
    await previewCorrection(dialog);
    assert.match(await dialog.getByRole('region', { name: 'Podgląd korekty', exact: true }).innerText(), /6 h → 3 h/);
    assert.equal(await dialog.getByRole('button', { name: 'Zapisz korektę', exact: true }).isDisabled(), true);
    assert.equal(await dialog.getByRole('checkbox', { name: 'Potwierdzam zwiększenie niedoboru', exact: true }).count(), 0);
    await dialog.getByRole('checkbox', { name: 'Potwierdzam przedstawione skutki korekty', exact: true }).check();
    await dialog.getByLabel('Liczba godzin', { exact: true }).selectOption('4');
    assert.equal(await dialog.getByRole('region', { name: 'Podgląd korekty', exact: true }).count(), 0);
    assert.equal(await dialog.getByRole('button', { name: 'Zapisz korektę', exact: true }).isDisabled(), true);
    const readsBeforeDraftFocus = reads.filter(path => path === '/api/requests/106').length;
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await until(() => reads.filter(path => path === '/api/requests/106').length > readsBeforeDraftFocus, 'Correction checks fresh access on focus.');
    await dialog.getByLabel('Uzasadnienie korekty', { exact: true }).waitFor();
    assert.equal(await dialog.getByLabel('Uzasadnienie korekty', { exact: true }).inputValue(), 'Syntetyczna korekta wymiaru');
    assert.equal(await dialog.getByLabel('Liczba godzin', { exact: true }).inputValue(), '4', 'Reauthorization preserves the unsaved draft for the same request version.');
    assert.equal(await dialog.getByRole('region', { name: 'Podgląd korekty', exact: true }).count(), 0);
    await previewCorrection(dialog); await dialog.getByRole('checkbox', { name: 'Potwierdzam przedstawione skutki korekty', exact: true }).check();
    staleCorrection = true; await dialog.getByRole('button', { name: 'Zapisz korektę', exact: true }).click();
    await dialog.getByRole('button', { name: 'Wczytaj aktualne dane', exact: true }).click();
    await dialog.getByLabel('Uzasadnienie korekty', { exact: true }).waitFor();
    assert.equal(await dialog.getByLabel('Uzasadnienie korekty', { exact: true }).inputValue(), '');
    assert.equal(await dialog.getByLabel('Liczba godzin', { exact: true }).inputValue(), '6');
    await dialog.getByLabel('Liczba godzin', { exact: true }).selectOption('3');
    await dialog.getByLabel('Uzasadnienie korekty', { exact: true }).fill('Syntetyczna korekta po odświeżeniu');
    await previewCorrection(dialog); await dialog.getByRole('checkbox', { name: 'Potwierdzam przedstawione skutki korekty', exact: true }).check();
    loseResponseId = 106; await dialog.getByRole('button', { name: 'Zapisz korektę', exact: true }).dblclick({ force: true });
    await dialog.getByRole('button', { name: 'Ponów zapis korekty', exact: true }).waitFor();
    assert.equal(mutationCount(106), 2, 'A stale attempt and one new correction are the only writes before retry.');
    assert.equal(await dialog.getByLabel('Uzasadnienie korekty', { exact: true }).isDisabled(), true);
    assert.equal(await dialog.getByLabel('Liczba godzin', { exact: true }).isDisabled(), true);
    await dialog.getByRole('button', { name: 'Ponów zapis korekty', exact: true }).click(); await dialog.waitFor({ state: 'detached' });
    assert.equal(requests.get(106).effective.totalHours, 3); assert.equal(requests.get(106).history.length, 2);
    assert.equal(mutationCount(106), 3);

    step = 'administrator works in another team and confirms a deficit explicitly';
    session = administrator; await open(201); dialog = await decision('approve'); await previewDecision(dialog);
    await dialog.getByRole('button', { name: 'Potwierdź akceptację', exact: true }).click(); await dialog.waitFor({ state: 'detached' });
    assert.equal(requests.get(201).status, 'approved');
    await page.setViewportSize({ width: 390, height: 844 });
    await open(106); dialog = await correction();
    await dialog.getByLabel('Liczba godzin', { exact: true }).selectOption('7');
    await dialog.getByLabel('Uzasadnienie korekty', { exact: true }).fill('Syntetyczny przypadek jawnego niedoboru');
    deficit = true; await previewCorrection(dialog);
    await dialog.getByRole('checkbox', { name: 'Potwierdzam przedstawione skutki korekty', exact: true }).check();
    assert.equal(await dialog.getByRole('button', { name: 'Zapisz korektę', exact: true }).isDisabled(), true, 'Ordinary confirmation cannot authorize an increased deficit.');
    await dialog.getByRole('checkbox', { name: 'Potwierdzam zwiększenie niedoboru', exact: true }).check();
    assert.equal(await dialog.getByRole('button', { name: 'Zapisz korektę', exact: true }).isEnabled(), true);
    assert.equal(await dialog.evaluate(element => { const b = element.getBoundingClientRect(); return Math.abs(b.x) <= 1 && Math.abs(b.y) <= 1 && Math.abs(b.width - innerWidth) <= 1 && element.scrollWidth <= element.clientWidth + 1; }), true, 'Mobile correction fills the current viewport without horizontal overflow.');
    await page.screenshot({ path: join(screenshotDir, 'managed-correction-mobile.png') });
    await dialog.getByRole('button', { name: 'Zapisz korektę', exact: true }).click(); await dialog.waitFor({ state: 'detached' }); deficit = false;
    assert.equal(requests.get(106).effective.totalHours, 7);
    assert.equal(writes.at(-1).body.allowDeficit, true);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
    await page.screenshot({ path: join(screenshotDir, 'managed-requests-mobile.png'), fullPage: true });

    step = 'an empty page with a cursor remains reachable';
    paginate = true; await page.goto(`${baseUrl}/management`);
    await page.getByRole('heading', { name: 'Obsługa wniosków', exact: true }).waitFor();
    await page.getByRole('button', { name: 'Pokaż kolejne wnioski', exact: true }).click();
    await page.locator('[data-request-id="103"]').waitFor();
    assert.ok(reads.some(path => path.includes('beforeId=999'))); paginate = false;

    step = 'revoked role clears private details and mutation controls';
    session = employee; await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await until(async () => await page.getByRole('button', { name: 'Akceptuj', exact: true }).count() === 0 && await page.locator('[data-request-id]').count() === 0, 'Revoked management access must clear private data.');
    assert.equal(await page.getByText(paid.name, { exact: true }).count(), 0);
    assert.equal(await page.getByText(debugMessage, { exact: true }).count(), 0);
    assert.equal(await page.evaluate(() => localStorage.length + sessionStorage.length), 0);
    assert.deepEqual(errors, [], 'No unhandled browser exceptions.');
    console.log('PASS Chrome managed UI: combined awaiting queue, manager own approval, deputy scope, admin other team, server decision preview, required rejection reason, effective/proposed comparison, cancellation, audit actors, conflict reload, guarded historical correction and deficit, stale preview, idle and in-flight preview reauthorization, malformed receipt and lost-response idempotency, duplicate-click prevention, empty cursor page, keyboard, mobile and role revocation. API responses are synthetic.');

    await context.close();
  } catch (error) {
    if (page) await page.screenshot({ path: join(screenshotDir, 'managed-requests-failure.png'), fullPage: true }).catch(() => {});
    throw error;
  } finally { await browser.close(); }
})().catch(error => { console.error(`Managed browser check failed at: ${step}`); console.error(error); process.exitCode = 1; });
