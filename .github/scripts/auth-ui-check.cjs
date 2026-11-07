const assert = require('node:assert/strict');
const { mkdirSync } = require('node:fs');
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
 let session=null; const mutations=[]; const pageErrors=[];
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
  if(path==='/api/admin/users') return reply(200,{users:[admin,employee]});
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
 await page.getByRole('heading',{name:'Dostęp do kont'}).waitFor();
 assert.equal(await page.getByRole('button',{name:'Ustaw hasło tymczasowe: Paweł Testowy'}).count(),0);
 await page.getByRole('button',{name:'Ustaw hasło tymczasowe: Anna Testowa'}).click();
 await page.getByLabel('Twoje hasło administratora',{exact:true}).fill('wrong');
 await page.getByRole('button',{name:'Potwierdź i utwórz hasło'}).click();
 await page.getByRole('alert').filter({hasText:'Podane aktualne hasło jest nieprawidłowe'}).waitFor();
 assert.equal(await page.getByLabel('Twoje hasło administratora',{exact:true}).inputValue(),'');
 await page.getByLabel('Twoje hasło administratora',{exact:true}).fill('admin accepted');
 await page.getByRole('button',{name:'Potwierdź i utwórz hasło'}).click();
 await page.getByRole('heading',{name:'Hasło tymczasowe gotowe'}).waitFor();
 const secret=page.getByLabel('Nowe hasło tymczasowe',{exact:true});
 assert.equal(await secret.getAttribute('type'),'password');
 assert.ok(await secret.inputValue() === 'SyntheticTemporaryPassword12345', 'The one-time credential was not rendered.');
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
 await page.screenshot({path:join(screenshotDir, 'stage3-admin-mobile.png'),fullPage:true});
 await page.getByRole('button',{name:'Zamknij',exact:true}).click();
 assert.equal(await page.getByLabel('Nowe hasło tymczasowe',{exact:true}).count(),0);
 await page.setViewportSize({width:1366,height:1000});
 await page.screenshot({path:join(screenshotDir, 'stage3-admin-desktop.png'),fullPage:true});
 assert.equal(await page.evaluate(()=>localStorage.length+sessionStorage.length),0);
 await page.getByLabel('Menu konta',{exact:true}).click();
 await page.getByRole('button',{name:'Wyloguj się',exact:true}).click();
 await page.waitForURL(atPath('/login'));
 await page.getByRole('heading',{name:'Zaloguj się'}).waitFor();
 assert.equal(pageErrors.length,0,'Browser reported a JavaScript exception.');
 console.log('PASS: anonymous guard, login failure/password clearing, forced change, mismatch validation, successful change/logout, admin self-reset hidden, reset confirmation failure/success, one-time secret cleanup, CSRF headers, empty browser storage, desktop/mobile overflow, no page errors.');
 } finally {
  await browser.close();
 }
})().catch(e=>{console.error(e);process.exit(1)});
