const assert = require('node:assert/strict');
const { mkdirSync, writeFileSync } = require('node:fs');
const { createServer } = require('node:http');
const { join } = require('node:path');
const { chromium } = require('playwright-core');

// This smoke test mocks /api explicitly; PHP functional tests exercise the real backend.
// Only synthetic identities and credentials are used. No app dependency is required.
const baseUrl = (process.env.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const expectedOrigin = new URL(baseUrl).origin;
const atPath = path => url => url.origin === expectedOrigin && url.pathname === path;
const screenshotDir = process.env.UI_SCREENSHOT_DIR || '/tmp/urlopy-ui-screens';
mkdirSync(screenshotDir, { recursive: true });
(async () => {
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH || '/usr/bin/google-chrome',args:['--no-sandbox','--disable-dev-shm-usage']});
 try {
 const context=await browser.newContext({viewport:{width:1366,height:1000}});
 // Inspect real HTML responses before mocking the application API.
 for (const path of ['/login', '/', '/management/admin']) {
  const response=await context.request.get(`${baseUrl}${path}`);
  assert.equal(response.status(),200,`HTML route ${path} must be available.`);
  const headers=response.headers();
  assert.equal(headers['x-frame-options'],'DENY');
  assert.match(headers['content-security-policy'],/frame-ancestors 'none'/);
  assert.match(headers['content-security-policy'],/object-src 'none'/);
  assert.match(headers['content-security-policy'],/base-uri 'self'/);
  assert.equal(headers['x-content-type-options'],'nosniff');
  assert.equal(headers['referrer-policy'],'strict-origin-when-cross-origin');
  assert.match(headers['permissions-policy'],/camera=\(\)/);
  assert.equal(headers['x-powered-by'],undefined);
 }
 // Serve the attack document over real HTTP at the same loopback host and a
 // different port. This is cross-origin but same-site, avoiding unrelated local
 // network permissions and out-of-process iframe logging differences.
 const applicationAddress=new URL(baseUrl);
 assert.equal(applicationAddress.protocol,'http:','Synthetic framing probe requires local HTTP.');
 assert.ok(['localhost','127.0.0.1','[::1]'].includes(applicationAddress.hostname),'Synthetic framing probe requires a loopback application.');
 const frameUrl=`${baseUrl}/login`;
 const probeServer=createServer((_request,response)=>{
  response.writeHead(200,{'Content-Type':'text/html; charset=utf-8'});
  response.end(`<!doctype html><title>Synthetic framing probe</title><iframe src="${frameUrl}"></iframe>`);
 });
 await new Promise((resolve,reject)=>{probeServer.once('error',reject);probeServer.listen(0,applicationAddress.hostname.replace(/^\[|\]$/g,''),resolve);});
 const attackerAddress=new URL(baseUrl);
 attackerAddress.port=String(probeServer.address().port);
 const attackerUrl=attackerAddress.href;
 assert.notEqual(attackerAddress.origin,expectedOrigin);
 const attacker=await context.newPage();
 const devtools=await context.newCDPSession(attacker);
 const diagnostic={attackerUrl,frameUrl,requests:[],responses:[],failures:[],securityLogs:[],networkFailures:[],frameUrls:[],blockEvidence:null};
 const frameRequestIds=new Set();
 let unblock;
 const observedBlock=new Promise(resolve=>{unblock=resolve;});
 const recordBlock=evidence=>{if(!diagnostic.blockEvidence){diagnostic.blockEvidence=evidence;unblock();}};
 const onRequest=request=>{if(request.url()===frameUrl) diagnostic.requests.push({url:request.url(),navigation:request.isNavigationRequest()});};
 const onResponse=response=>{
  if(response.url()!==frameUrl) return;
  const headers=response.headers();
  diagnostic.responses.push({status:response.status(),csp:headers['content-security-policy'],xFrameOptions:headers['x-frame-options']});
 };
 const onRequestFailed=request=>{
  if(request.url()!==frameUrl) return;
  const error=request.failure()?.errorText;
  diagnostic.failures.push({url:request.url(),navigation:request.isNavigationRequest(),error});
  if(request.isNavigationRequest() && /^net::ERR_BLOCKED_BY_(RESPONSE|CSP)(?:\..+)?$/.test(error || '')) recordBlock({source:'requestfailed',error});
 };
 context.on('request',onRequest); context.on('response',onResponse); context.on('requestfailed',onRequestFailed);
 devtools.on('Log.entryAdded',({entry})=>{
  if(entry.source!=='security') return;
  diagnostic.securityLogs.push({source:entry.source,text:entry.text});
  if(/frame-ancestors|X-Frame-Options/i.test(entry.text)) recordBlock({source:'security-log',text:entry.text});
 });
 devtools.on('Network.requestWillBeSent',event=>{if(event.request.url===frameUrl)frameRequestIds.add(event.requestId);});
 devtools.on('Network.loadingFailed',event=>{
  if(!frameRequestIds.has(event.requestId)) return;
  diagnostic.networkFailures.push({errorText:event.errorText,blockedReason:event.blockedReason});
  if(event.blockedReason==='csp') recordBlock({source:'network-csp',blockedReason:event.blockedReason});
 });
 let timeout;
 try {
  await Promise.all([devtools.send('Log.enable'),devtools.send('Network.enable')]);
  const deadline=new Promise((_,reject)=>{timeout=setTimeout(()=>reject(new Error('Chrome did not report a framing-policy/network block; see frame-probe.json.')),15000);});
  await Promise.all([Promise.race([observedBlock,deadline]),attacker.goto(attackerUrl)]);
  assert.equal(await attacker.frameLocator('iframe').locator('input[type="password"]').count(),0,'Login must not render after the browser reports the iframe navigation block.');
 } finally {
  clearTimeout(timeout);
  diagnostic.frameUrls=attacker.frames().map(frame=>frame.url());
  mkdirSync('/tmp/urlopy-security',{recursive:true});
  writeFileSync('/tmp/urlopy-security/frame-probe.json',JSON.stringify(diagnostic,null,2));
  context.off('request',onRequest); context.off('response',onResponse); context.off('requestfailed',onRequestFailed);
  await devtools.detach(); await attacker.close();
  await new Promise(resolve=>{probeServer.close(resolve);probeServer.closeAllConnections();});
 }
 let session=null; let adminReads=0; const mutations=[]; const pageErrors=[];
 const employee={id:2,firstName:'Anna',lastName:'Testowa',email:'anna@example.test',isAdministrator:false,mustChangePassword:false,team:{id:1,code:'it',name:'Rozwój aplikacji'},role:'employee'};
 const admin={id:1,firstName:'Paweł',lastName:'Testowy',email:'admin@example.test',isAdministrator:true,mustChangePassword:false,team:null,role:null};
 await context.route('**/api/**', async route=>{
  const request=route.request(); const path=new URL(request.url()).pathname;
  const reply=(status,data)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(data)});
  const error=(status,code)=>reply(status,{error:{code,message:'Synthetic error'}});
  if(path==='/api/auth/csrf') return reply(200,{csrfToken:'synthetic-csrf'});
  if(request.method()==='POST') {
   assert.ok(request.headers()['x-csrf-token'] === 'synthetic-csrf', 'Mutation is missing the expected CSRF header.');
   mutations.push(path);
  }
  if(path==='/api/auth/login') {
   const data=request.postDataJSON();
   if(data.password==='wrong') return error(401,'INVALID_CREDENTIALS');
   session=data.email==='admin@example.test'?admin:{...employee,mustChangePassword:data.password==='temporary'};
   return reply(200,{user:session});
  }
  if(!session) return error(401,'AUTH_REQUIRED');
  if(path==='/api/auth/me') return reply(200,{user:session});
  if(path==='/api/auth/logout'){session=null;return reply(200,{status:'logged_out'});}
  if(path==='/api/auth/change-password'){session=null;return reply(200,{status:'password_changed'});}
  if(session.mustChangePassword) return error(403,'PASSWORD_CHANGE_REQUIRED');
  if(path==='/api/admin/users') { adminReads++; return reply(200,{users:[admin,employee]}); }
  if(path==='/api/admin/users/2/reset-password') {
   if(request.postDataJSON().adminPassword==='wrong') return error(422,'INVALID_CURRENT_PASSWORD');
   return reply(200,{temporaryPassword:'SyntheticTemporaryPassword12345',expiresAt:'2026-11-07T20:00:00+00:00'});
  }
  return error(404,'NOT_FOUND');
 });
 const page=await context.newPage(); page.on('pageerror',e=>pageErrors.push(e.message));
 await page.goto(`${baseUrl}/`);
 await page.waitForURL(atPath('/login'));
 await page.getByRole('heading',{name:'Zaloguj się'}).waitFor();
 await page.screenshot({path:join(screenshotDir, 'stage3-login-desktop.png'),fullPage:true});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
 await page.getByLabel('Służbowy adres e-mail').fill('anna@example.test');
 await page.getByLabel('Hasło',{exact:true}).fill('wrong');
 await page.getByRole('button',{name:'Zaloguj się',exact:true}).click();
 await page.getByRole('alert').filter({hasText:'Nieprawidłowy e-mail lub hasło'}).waitFor();
 assert.equal(await page.getByLabel('Hasło',{exact:true}).inputValue(),'');
 await page.getByLabel('Hasło',{exact:true}).fill('temporary');
 await page.getByRole('button',{name:'Zaloguj się',exact:true}).click();
 await page.waitForURL(atPath('/change-password'));
 await page.getByRole('heading',{name:'Ustaw własne hasło'}).waitFor();
 await page.getByLabel('Hasło tymczasowe',{exact:true}).fill('temporary');
 await page.getByLabel('Nowe hasło',{exact:true}).fill('nowe dlugie haslo testowe');
 await page.getByLabel('Powtórz nowe hasło',{exact:true}).fill('inne dlugie haslo testowe');
 const prior=mutations.length;
 await page.getByRole('button',{name:'Zapisz nowe hasło'}).click();
 await page.getByRole('alert').filter({hasText:'muszą być takie same'}).waitFor();
 assert.equal(mutations.length,prior);
 await page.getByLabel('Powtórz nowe hasło',{exact:true}).fill('nowe dlugie haslo testowe');
 await page.getByRole('button',{name:'Zapisz nowe hasło'}).click();
 await page.waitForURL(url => atPath('/login')(url) && url.searchParams.get('changed') === '1');
 await page.getByRole('status').filter({hasText:'Hasło zostało zmienione'}).waitFor();
 await page.setViewportSize({width:390,height:844});
 await page.screenshot({path:join(screenshotDir, 'stage3-login-mobile.png'),fullPage:true});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
 await page.getByLabel('Służbowy adres e-mail').fill('admin@example.test');
 await page.getByLabel('Hasło',{exact:true}).fill('admin accepted');
 await page.getByRole('button',{name:'Zaloguj się',exact:true}).click();
 await page.waitForURL(atPath('/'));
 // The post-login landing page is the team calendar. Account/password controls
 // retain their own regression coverage at the dedicated account route.
 await page.goto(`${baseUrl}/account`);
 await page.getByRole('heading',{name:'Cześć, Paweł.'}).waitFor();
 // Administrative credential reset moved to /management/admin; its wrong-password,
 // successful reset, masked secret and cleanup checks live in admin-ui-check.cjs.
 assert.equal(await page.getByRole('heading',{name:'Dostęp do kont'}).count(),0);
 assert.equal(await page.getByRole('button',{name:/Ustaw hasło tymczasowe/}).count(),0);
 assert.equal(await page.locator('input[type="password"]').count(),0);
 assert.equal(adminReads,0,'Personal account does not load the administrative user directory.');
 await page.screenshot({path:join(screenshotDir, 'account-mobile.png'),fullPage:true});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
 await page.setViewportSize({width:1366,height:1000});
 await page.screenshot({path:join(screenshotDir, 'account-desktop.png'),fullPage:true});
 assert.equal(await page.evaluate(()=>localStorage.length+sessionStorage.length),0);
 await page.getByLabel('Menu konta',{exact:true}).click();
 await page.getByRole('button',{name:'Wyloguj się',exact:true}).click();
 await page.waitForURL(atPath('/login'));
 await page.getByRole('heading',{name:'Zaloguj się'}).waitFor();
 assert.equal(pageErrors.length,0,'Browser reported a JavaScript exception.');
 console.log('PASS: HTML security headers, cross-origin framing blocked, anonymous guard, login failure/password clearing, forced change, mismatch validation, successful change/logout, personal account without administrative reset controls, CSRF headers, empty browser storage, desktop/mobile overflow, no page errors.');
 } finally {
  await browser.close();
 }
})().catch(e=>{console.error(e);process.exit(1)});
