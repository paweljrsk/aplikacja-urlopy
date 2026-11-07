'use strict';

// Run once after the synthetic demo fixture on the disposable CI development stack.
// All subprocess output and HTTP bodies stay in memory: they may contain credentials.
const { execFileSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const { resolve } = require('node:path');

let step = 'development-only guards';
function check(condition, description) {
  if (!condition) throw new Error(description);
}

async function main() {
  check(process.env.CI === 'true', 'CI=true is required');
  const base = new URL(process.env.BASE_URL || 'http://127.0.0.1:8080');
  check(base.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(base.hostname)
    && !base.username && !base.password && base.pathname === '/' && !base.search && !base.hash,
  'Only the local development HTTP server is supported');

  step = 'synthetic first-administrator bootstrap';
  // The actual interactive command calls CredentialsService with the real DI configuration.
  // Buffer its one-time password output rather than exposing it to the CI terminal.
  const fixture = JSON.parse(execFileSync('docker', ['compose', 'exec', '-T', 'php', 'php'], {
    cwd: resolve(__dirname, '../..'),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
    timeout: 30000,
    maxBuffer: 65536,
    input: String.raw`<?php
try {
    if ('dev' !== getenv('APP_ENV')) {
        throw new RuntimeException('Development environment required.');
    }
    require 'vendor/autoload.php';
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');
    $kernel = new App\Kernel('dev', true);
    $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
    $application->setAutoExit(false);
    $application->setCatchExceptions(false);
    $email = 'auth-http-'.bin2hex(random_bytes(8)).'@example.test';
    $answers = fopen('php://memory', 'r+');
    fwrite($answers, $email."\nSynthetic\nHttpSmoke\n");
    rewind($answers);
    $input = new Symfony\Component\Console\Input\ArrayInput(['command' => 'app:admin:bootstrap']);
    $input->setInteractive(true);
    $input->setStream($answers);
    $output = new Symfony\Component\Console\Output\BufferedOutput();
    if (0 !== $application->run($input, $output)) {
        throw new RuntimeException('Bootstrap failed.');
    }
    if (1 !== preg_match('/Hasło tymczasowe \(wyświetlane tylko teraz\): ([A-Za-z0-9_-]{32})/', $output->fetch(), $match)) {
        throw new RuntimeException('Bootstrap result missing.');
    }
    echo json_encode(['email' => $email, 'temporaryPassword' => $match[1]], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, "Synthetic auth fixture failed; details suppressed.\n");
    exit(1);
}
`,
  }));
  check(typeof fixture.email === 'string' && /^auth-http-[a-f0-9]{16}@example\.test$/.test(fixture.email)
    && typeof fixture.temporaryPassword === 'string' && fixture.temporaryPassword.length === 32,
  'Unexpected synthetic fixture');

  class Client {
    constructor(cookies = new Map()) { this.cookies = new Map(cookies); }

    async request(path, status, body, csrfToken, method) {
      const headers = { Accept: 'application/json' };
      if (this.cookies.size) headers.Cookie = [...this.cookies].map(([key, value]) => `${key}=${value}`).join('; ');
      if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        headers['X-CSRF-Token'] = csrfToken;
      }
      const response = await fetch(new URL(path, base), {
        method: method || (body === undefined ? 'GET' : 'POST'),
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
        redirect: 'error',
        signal: AbortSignal.timeout(15000),
      });
      for (const cookie of response.headers.getSetCookie()) {
        const pair = cookie.split(';', 1)[0];
        const separator = pair.indexOf('=');
        const name = pair.slice(0, separator);
        const value = pair.slice(separator + 1);
        if (!value || /;\s*max-age=0(?:;|$)/i.test(cookie)) this.cookies.delete(name);
        else this.cookies.set(name, value);
      }
      check(response.status === status, `Expected HTTP ${status}, received ${response.status}`);
      check(response.headers.get('cache-control')?.includes('no-store'), 'Missing no-store response');
      return response.json();
    }

    async post(path, body) {
      const { csrfToken } = await this.request('/api/auth/csrf', 200);
      check(typeof csrfToken === 'string' && csrfToken.length > 0, 'Missing CSRF token');
      check(this.cookies.has('urlopy_session'), 'Missing session cookie');
      return this.request(path, 200, body, csrfToken);
    }
  }

  const client = new Client();
  step = 'temporary-password login through nginx and FPM';
  const login = await client.post('/api/auth/login', { email: fixture.email, password: fixture.temporaryPassword });
  check(login.user?.isAdministrator === true && login.user?.mustChangePassword === true, 'Temporary administrator expected');
  const userId = login.user.id;
  check(Number.isInteger(userId) && userId > 0, 'Missing user ID');

  step = 'session persistence and forced password change';
  const temporaryMe = await client.request('/api/auth/me', 200);
  check(temporaryMe.user?.id === userId && temporaryMe.user?.mustChangePassword === true, 'Session did not persist');
  const blocked = await client.request('/api/admin/users', 403);
  check(blocked.error?.code === 'PASSWORD_CHANGE_REQUIRED', 'Business access was not blocked');

  step = 'password change and old-session revocation';
  const oldSession = new Client(client.cookies);
  const newPassword = randomBytes(32).toString('base64url');
  const changed = await client.post('/api/auth/change-password', {
    currentPassword: fixture.temporaryPassword,
    newPassword,
  });
  check(changed.status === 'password_changed', 'Password change failed');
  const revoked = await oldSession.request('/api/auth/me', 401);
  check(revoked.error?.code === 'AUTH_REQUIRED', 'Old session was not revoked');

  step = 'fresh login with changed password';
  const fresh = new Client();
  const relogin = await fresh.post('/api/auth/login', { email: fixture.email, password: newPassword });
  check(relogin.user?.id === userId && relogin.user?.mustChangePassword === false, 'New password login failed');
  const profile = await fresh.request(`/api/users/${userId}/profile`, 200);
  check(profile.user?.email === fixture.email, 'Own profile unavailable');
  const listed = await fresh.request('/api/admin/users', 200);
  check(Array.isArray(listed.users) && listed.users.some(user => user.id === userId), 'Admin list unavailable');

  step = 'administration dictionaries and real API mutations';
  async function mutate(path, body, status = 200, method = 'POST') {
    const { csrfToken } = await fresh.request('/api/auth/csrf', 200);
    return fresh.request(path, status, body, csrfToken, method);
  }
  const catalog = await mutate('/api/admin/catalog/initialize', {});
  const employment = catalog.employmentTypes.find(item => item.code === 'b2b');
  check(employment && catalog.absenceTypes.length >= 3, 'Starter dictionaries missing');
  const suffix = randomBytes(6).toString('hex');
  const teamResult = await mutate('/api/admin/teams', { code: `http_${suffix}`, name: 'Synthetic HTTP team', active: true }, 201);
  const team = teamResult.item;
  check(team && team.active === true, 'Team creation failed');
  const localDate = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Warsaw', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
  const created = await mutate('/api/admin/users', {
    firstName: 'Synthetic', lastName: 'AdminHttp', email: `admin-http-${suffix}@example.test`,
    isAdministrator: false, teamId: team.id, employmentTypeId: employment.id,
    role: 'employee', validFrom: localDate, adminPassword: newPassword,
  }, 201);
  check(created.user?.team?.id === team.id && typeof created.temporaryPassword === 'string', 'Assigned account creation failed');
  check(!JSON.stringify(created.user).includes(created.temporaryPassword), 'Secret leaked into user DTO');
  const detail = await fresh.request(`/api/admin/users/${created.user.id}`, 200);
  check(detail.user.assignments.length === 1, 'Assignment history missing');
  const updated = await mutate(`/api/admin/teams/${team.id}`, {
    version: team.version, name: 'Synthetic renamed team', active: true,
  }, 200, 'PATCH');
  check(updated.item.name === 'Synthetic renamed team', 'Team edit failed');
  const conflict = await mutate(`/api/admin/teams/${team.id}`, {
    version: team.version, name: 'Stale name', active: true,
  }, 409, 'PATCH');
  check(conflict.error?.code === 'CONFLICT', 'Stale update was not rejected');

  step = 'real balance setup, corrections and stale writes';
  const leaveYear = Number(localDate.slice(0, 4));
  const balancePath = `/api/balances/users/${created.user.id}`;
  const initialBalance = (await fresh.request(`${balancePath}/${leaveYear}`, 200)).balance;
  check(initialBalance.configured === false && initialBalance.annualHours === null, 'Missing limit was not distinguished from zero');
  const openingPayload = {
    operationKey: `http.opening.${suffix}`, expectedVersion: initialBalance.version,
    effectiveDate: localDate, annualHours: 80, currentYearUsedHours: 16, carries: [],
  };
  const openingBalance = (await mutate(`${balancePath}/opening`, openingPayload)).balance;
  check(openingBalance.configured && openingBalance.annualHours === 80 && openingBalance.availableHours === 64, 'Opening balance incorrect');
  const repeatedOpening = (await mutate(`${balancePath}/opening`, openingPayload)).balance;
  check(repeatedOpening.availableHours === 64, 'Repeated opening operation duplicated allocation');
  const limitPayload = { operationKey: `http.limit.${suffix}`, expectedVersion: openingBalance.version, annualHours: 64, reason: 'Synthetic correction of annual limit' };
  const limitedBalance = (await mutate(`${balancePath}/${leaveYear}/limit`, limitPayload)).balance;
  check(limitedBalance.availableHours === 48 && limitedBalance.annualHours === 64, 'Annual limit correction incorrect');
  const staleBalance = await mutate(`${balancePath}/${leaveYear}/limit`, { ...limitPayload, operationKey: `http.stale.${suffix}`, annualHours: 72 }, 409);
  check(staleBalance.error?.code === 'CONFLICT', 'Stale balance correction was accepted');
  const deficit = (await mutate(`${balancePath}/${leaveYear}/limit`, {
    operationKey: `http.deficit.${suffix}`, expectedVersion: limitedBalance.version, annualHours: 8, reason: 'Synthetic explicit deficit',
  })).balance;
  check(deficit.availableHours === -8 && deficit.deficitHours === 8, 'Explicit deficit was hidden');
  const corrected = (await mutate(`${balancePath}/opening/corrections`, {
    operationKey: `http.opening-correction.${suffix}`, expectedVersion: deficit.version,
    effectiveDate: localDate, currentYearUsedHours: 24, carries: [], reason: 'Synthetic opening usage correction',
  })).balance;
  check(corrected.availableHours === -16 && corrected.history.length >= 4, 'Opening correction or audit unavailable');

  step = 'real company calendar preview and explicit application';
  const nextYear = leaveYear + 1;
  const calendarDate = new Date(Date.UTC(nextYear, 1, 15));
  while ([0, 6].includes(calendarDate.getUTCDay())) calendarDate.setUTCDate(calendarDate.getUTCDate() + 1);
  const companyChange = { date: calendarDate.toISOString().slice(0, 10), name: 'Synthetic HTTP company day', active: true };
  const calendarPreview = await mutate('/api/calendar/preview', companyChange);
  check(calendarPreview.changedRequests === 0 && typeof calendarPreview.previewToken === 'string', 'Calendar preview failed');
  const appliedCalendar = await mutate('/api/calendar/apply', { ...companyChange, previewToken: calendarPreview.previewToken, operationKey: `http.calendar.${suffix}`, reason: 'Synthetic future company day' });
  check(appliedCalendar.applied === true, 'Calendar change not applied');
  const calendar = await fresh.request(`/api/calendar/${nextYear}`, 200);
  check(calendar.holidays.some(day => day.date === companyChange.date && day.source === 'company' && day.active), 'Applied company day missing');

  step = 'new account forced change and denied administration';
  const employeeClient = new Client();
  const employeeLogin = await employeeClient.post('/api/auth/login', {
    email: created.user.email, password: created.temporaryPassword,
  });
  check(employeeLogin.user.mustChangePassword === true, 'New account did not require password change');
  const employeePassword = randomBytes(32).toString('base64url');
  await employeeClient.post('/api/auth/change-password', { currentPassword: created.temporaryPassword, newPassword: employeePassword });
  const employeeFresh = new Client();
  await employeeFresh.post('/api/auth/login', { email: created.user.email, password: employeePassword });
  const forbidden = await employeeFresh.request('/api/admin/catalog', 403);
  check(forbidden.error?.code === 'FORBIDDEN', 'Employee can access administration');
  const ownBalance = await employeeFresh.request(`${balancePath}/${leaveYear}`, 200);
  check(ownBalance.balance.availableHours === -16, 'Employee cannot read own balance');
  const scopedPeople = await employeeFresh.request('/api/balances/people', 200);
  check(scopedPeople.people.length === 1 && scopedPeople.people[0].id === created.user.id && !scopedPeople.people[0].canManage, 'Balance people list leaks coworkers or grants edits');
  await employeeFresh.request(`/api/balances/users/${userId}/${leaveYear}`, 403);
  const { csrfToken: employeeCsrf } = await employeeFresh.request('/api/auth/csrf', 200);
  await employeeFresh.request(`${balancePath}/${leaveYear}/limit`, 403, { operationKey: `http.denied.${suffix}`, expectedVersion: corrected.version, annualHours: 999, reason: 'Denied synthetic edit' }, employeeCsrf);
  await employeeFresh.request('/api/calendar/preview', 403, companyChange, employeeCsrf);
  await employeeFresh.post('/api/auth/logout', {});

  step = 'logout and session revocation';
  const loggedOut = await fresh.post('/api/auth/logout', {});
  check(loggedOut.status === 'logged_out', 'Logout failed');
  const anonymous = await fresh.request('/api/auth/me', 401);
  check(anonymous.error?.code === 'AUTH_REQUIRED', 'Logout did not revoke session');
  console.log('Real HTTP smoke passed: auth/session/CSRF, administration, balances/opening/corrections/deficit, stale writes, calendar preview/apply, employee isolation and logout.');
}

main().catch(() => {
  // Do not print exceptions: fetch and child-process errors can contain sensitive input/output.
  console.error(`Real HTTP auth smoke failed at: ${step}. Response bodies and subprocess output suppressed.`);
  process.exitCode = 1;
});
