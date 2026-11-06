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

    async request(path, status, body, csrfToken) {
      const headers = { Accept: 'application/json' };
      if (this.cookies.size) headers.Cookie = [...this.cookies].map(([key, value]) => `${key}=${value}`).join('; ');
      if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        headers['X-CSRF-Token'] = csrfToken;
      }
      const response = await fetch(new URL(path, base), {
        method: body === undefined ? 'GET' : 'POST',
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

  step = 'logout and session revocation';
  const loggedOut = await fresh.post('/api/auth/logout', {});
  check(loggedOut.status === 'logged_out', 'Logout failed');
  const anonymous = await fresh.request('/api/auth/me', 401);
  check(anonymous.error?.code === 'AUTH_REQUIRED', 'Logout did not revoke session');
  console.log('Real HTTP auth smoke passed: CSRF, session persistence, forced change, revocation, admin access and logout.');
}

main().catch(() => {
  // Do not print exceptions: fetch and child-process errors can contain sensitive input/output.
  console.error(`Real HTTP auth smoke failed at: ${step}. Response bodies and subprocess output suppressed.`);
  process.exitCode = 1;
});
