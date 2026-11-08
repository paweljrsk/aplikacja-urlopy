'use strict';

// A small, sequential local CI measurement. Never target remote/production services.
const { execFileSync } = require('node:child_process');
const { mkdirSync, writeFileSync } = require('node:fs');
const { resolve, join } = require('node:path');
const os = require('node:os');
const { performance } = require('node:perf_hooks');
const { chromium } = require('playwright-core');
let step = 'guards';
class MeasurementError extends Error {}
function check(value, message) { if (!value) throw new MeasurementError(message); }
const reportDir = resolve(process.env.PERFORMANCE_DIR || '/tmp/urlopy-performance');
const report = {
  protocolVersion: 1, startedAt: new Date().toISOString(), status: 'running',
  proposedBudgets: { apiP95Ms: 2000, browserReadyP95Ms: 5000, agreedSla: false },
  methodology: { apiWarmups: 2, apiSamples: 10, browserWarmups: 1, browserSamples: 3,
    percentile: 'nearest rank; p95 is the maximum with these small sample counts',
    concurrency: 'sequential API/navigation samples; team balances use existing UI maximum four concurrent requests',
    frontend: 'Next development server after production-build validation; warm navigation, no artificial CPU/network throttling',
    scope: 'local GitHub Actions Docker environment, not production capacity or an agreed SLA' },
  datasetPlan: { people: 100, teams: 5, requests: 2000, requestsPerPerson: 20, managerScopePeople: 20 },
  environment: { node: process.version, os: os.type(), release: os.release(), arch: os.arch(),
    cpuModel: os.cpus()[0]?.model, logicalCpus: os.cpus().length, hostMemoryBytes: os.totalmem() },
  results: [], warnings: [],
};
function saveReport() { mkdirSync(reportDir, { recursive: true }); writeFileSync(join(reportDir, 'report.json'), JSON.stringify(report, null, 2)); }
function stats(values) {
  const sorted = [...values].sort((a, b) => a - b);
  const rank = p => Math.round(sorted[Math.ceil(p * sorted.length) - 1] * 10) / 10;
  return { samples: values.length, p50Ms: rank(.5), p95Ms: rank(.95), minMs: rank(1 / sorted.length), maxMs: rank(1), samplesMs: values.map(v => Math.round(v * 10) / 10) };
}
async function measure(name, kind, action, warmups, samples) {
  step = name;
  for (let i = 0; i < warmups; ++i) await action();
  const times = [];
  for (let i = 0; i < samples; ++i) { const start = performance.now(); await action(); times.push(performance.now() - start); }
  const result = { name, kind, ...stats(times) };
  const budget = kind === 'api' ? report.proposedBudgets.apiP95Ms : report.proposedBudgets.browserReadyP95Ms;
  result.proposedBudgetMs = budget; result.withinProposedBudget = result.p95Ms <= budget;
  if (!result.withinProposedBudget) report.warnings.push(`${name}: p95 ${result.p95Ms} ms exceeds proposed ${budget} ms diagnostic budget`);
  report.results.push(result); saveReport();
  console.log(`Performance ${name}: n=${samples}, p50=${result.p50Ms} ms, p95=${result.p95Ms} ms, proposed budget ${budget} ms: ${result.withinProposedBudget ? 'within' : 'EXCEEDED'}.`);
}
async function main() {
  check(process.env.CI === 'true', 'CI=true required.');
  const base = new URL(process.env.BASE_URL || 'http://127.0.0.1:8080');
  check(base.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(base.hostname) && !base.username && !base.password
    && base.pathname === '/' && !base.search && !base.hash, 'Only local development HTTP is permitted.');
  saveReport();
  console.log(`Performance protocol before measurement: ${JSON.stringify({ ...report, results: undefined, warnings: undefined })}`);
  step = 'isolated synthetic dataset';
  const fixtureStart = performance.now();
  const fixture = JSON.parse(execFileSync('docker', ['compose', 'exec', '-T', '-e', 'CI=true', '-e', 'PERFORMANCE_FIXTURE=ALLOW_SYNTHETIC_CI',
    'php', 'php', 'tests/Support/performance-fixture.php'], { cwd: resolve(__dirname, '../..'), encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 300000, maxBuffer: 65536 }));
  for (const name of ['manager', 'employee']) check(fixture[name] && /^performance_[a-f0-9]{16}_\d+@example\.test$/.test(fixture[name].email)
    && /^[a-f0-9]{48}$/.test(fixture[name].password) && Number.isInteger(fixture[name].id), 'Unexpected synthetic account.');
  check(fixture.dataset.people === 100 && fixture.dataset.requests === 2000 && fixture.dataset.teams === 5, 'Unexpected dataset size.');
  for (const field of ['from', 'to', 'upcomingTo']) check(/^\d{4}-\d{2}-\d{2}$/.test(fixture[field]), 'Unexpected synthetic date.');
  report.fixtureSetupMs = Math.round(performance.now() - fixtureStart);
  report.dataset = fixture.dataset; report.databaseTotals = fixture.databaseTotals;
  report.environment = { ...report.environment, ...fixture.runtime };
  report.dates = { from: fixture.from, to: fixture.to, upcomingTo: fixture.upcomingTo, year: fixture.year };
  // The artifact contains only counts, runtime versions and timings, never fixture credentials or IDs.
  saveReport(); console.log(`Performance actual environment/dataset before requests: ${JSON.stringify({ environment: report.environment, dataset: report.dataset, databaseTotals: report.databaseTotals, dates: report.dates })}`);
  step = 'Chrome';
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  report.environment.chrome = browser.version();
  try {
    const context = await browser.newContext({ baseURL: base.href, viewport: { width: 1440, height: 1050 }, timezoneId: 'Europe/Warsaw' });
    async function get(path) {
      const response = await context.request.get(path, { timeout: 30000 });
      check(response.status() === 200, `API measurement received HTTP ${response.status()}.`);
      check(response.headers()['cache-control']?.includes('no-store'), 'Missing private API no-store response.');
      return response.json();
    }
    const csrf = await get('/api/auth/csrf');
    const login = await context.request.post('/api/auth/login', { data: { email: fixture.manager.email, password: fixture.manager.password }, headers: { 'X-CSRF-Token': csrf.csrfToken } });
    check(login.status() === 200, 'Synthetic manager login failed.');
    check((await get('/api/auth/me')).user.id === fixture.manager.id, 'Unexpected manager session.');
    const measurements = [
      ['calendar_14_days', `/api/team-calendar?from=${fixture.from}&to=${fixture.to}`, data => data.people.length === 20 && data.entries.length > 0],
      ['calendar_61_days', `/api/team-calendar?from=${fixture.from}&to=${fixture.upcomingTo}`, data => data.people.length === 20 && data.entries.length === 400],
      ['own_requests_page', '/api/requests?scope=mine&limit=20', data => data.requests.length === 20],
      ['managed_queue_page', '/api/requests?scope=managed&limit=20&status=awaiting', data => data.requests.length === 20],
      ['balance_one_person', `/api/balances/users/${fixture.employee.id}/${fixture.year}`, data => data.balance.configured === true],
      ['team_people', '/api/balances/people', data => data.people.length === 20],
    ];
    for (const [name, path, validate] of measurements) await measure(name, 'api', async () => check(validate(await get(path)), `Unexpected data in ${name}.`), 2, 10);
    const page = await context.newPage(); page.setDefaultTimeout(30000);
    let pageErrors = 0; let apiErrors = 0; let pageApiRequests = 0;
    page.on('pageerror', () => { ++pageErrors; });
    page.on('response', response => { if (new URL(response.url()).pathname.startsWith('/api/')) { ++pageApiRequests; if (response.status() >= 400) ++apiErrors; } });
    const ready = {
      calendar: async () => { await page.locator('.team-calendar-table tbody tr').first().waitFor({ state: 'attached' }); await page.locator('.upcoming-list li').first().waitFor(); },
      requests: async () => { await page.locator('.requests-list > li').first().waitFor(); },
      management: async () => { await page.locator('.managed-list > li').first().waitFor(); },
      team_balances: async () => { await page.locator('.team-balances-table tbody tr').first().waitFor(); check(await page.locator('.team-balances-table tbody tr').count() === 20, 'Team overview did not render all people.'); },
    };
    report.browserApiResponsesPerSample = {};
    for (const viewport of [{ name: 'desktop', width: 1440, height: 1050 }, { name: 'mobile', width: 390, height: 844 }]) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      for (const [name, path] of [['calendar', '/'], ['requests', '/requests'], ['management', '/management/requests'], ['team_balances', '/management/balances']]) {
        const label = `${name}_${viewport.name}`;
        const counts = [];
        await measure(label, 'browser', async () => {
          const before = pageApiRequests;
          await page.goto(path, { waitUntil: 'domcontentloaded' }); await ready[name]();
          check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Horizontal overflow in ${label}.`);
          check(pageErrors === 0 && apiErrors === 0, `Browser or API error in ${label}.`);
          counts.push(pageApiRequests - before);
        }, 1, 3);
        report.browserApiResponsesPerSample[label] = counts.slice(1);
      }
    }
    await context.close();
    report.status = report.warnings.length ? 'measured_with_budget_warnings' : 'measured_within_proposed_budgets';
    report.completedAt = new Date().toISOString(); saveReport();
    console.log(`Performance measurement complete: ${report.results.length} cases, ${report.warnings.length} diagnostic budget warnings. Results are local warm measurements, not a production SLA.`);
    for (const warning of report.warnings) console.log(`Performance warning: ${warning}`);
  } finally { await browser.close(); }
}
main().catch(error => {
  report.status = 'failed'; report.failedStep = step; report.completedAt = new Date().toISOString(); saveReport();
  console.error(`Performance check failed at ${step}; credentials, responses and subprocess details suppressed.`);
  if (error instanceof MeasurementError) console.error(error.message);
  process.exitCode = 1;
});
