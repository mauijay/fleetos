import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { openSync, closeSync } from 'node:fs';
import { resolve, sep } from 'node:path';
import { createServer } from 'node:http';

// Runs only the synthetic PHP router and a fresh local Chrome profile.
const directory = resolve('build/reconciliation-slice2-validation/browser-' + Date.now());
await mkdir(directory, { recursive: true });
const php = process.env.RECONCILIATION_BROWSER_PHP || 'C:/xampp/php/php.exe';
const ini = resolve(process.env.RECONCILIATION_BROWSER_INI || 'build/php-validation.ini');
const chrome = process.env.RECONCILIATION_BROWSER_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const port = 35672;
const base = `http://127.0.0.1:${port}`;
const serverLogPath = resolve(directory, 'server.log');
const serverLog = openSync(serverLogPath, 'a');
// Each synthetic GET boots the real controller in a fresh PHP CLI process.
// This avoids the Windows development SAPI's stalled persistent sockets.
const assetsRoot = resolve('public/build');
const proxy = createServer(async (request, response) => {
  try {
    const path = new URL(request.url, base).pathname;
    if (path.startsWith('/build/')) {
      const file = resolve(assetsRoot, decodeURIComponent(path.slice(7)));
      if (!file.startsWith(assetsRoot + sep)) { response.writeHead(400); response.end(); return; }
      const body = await readFile(file);
      response.writeHead(200, { 'Content-Type': file.endsWith('.css') ? 'text/css' : 'text/javascript', 'Content-Length': body.length });
      response.end(body); return;
    }
    if (request.method !== 'GET') { response.writeHead(405); response.end(); return; }
    if (path === '/favicon.ico') { response.writeHead(204); response.end(); return; }
    const scenario = /reconciliation_fixture=(p0|p1|checklist|historical|healthy|pagination)/.exec(request.headers.cookie || '')?.[1] || 'p0';
    const body = await new Promise((resolve,reject)=>{
      const worker=spawn(php,['-c',ini,'tests/_support/OperationalReconciliationBrowserRouter.php','--render',scenario,request.url],{
        windowsHide:true,env:{...process.env,PHPRC:ini,RECONCILIATION_BROWSER_DIR:directory,RECONCILIATION_BROWSER_BASEURL:base+'/'},stdio:['ignore','pipe',serverLog],
      });
      const parts=[]; worker.stdout.on('data',part=>parts.push(part)); worker.on('error',reject);
      worker.on('close',code=>code===0?resolve(Buffer.concat(parts)):reject(new Error('Synthetic render failed: '+code)));
    });
    const result=JSON.parse(body.toString()); response.writeHead(result.status,{'Content-Type':path.startsWith('/synthetic-')?'application/json':'text/html'});response.end(result.body);
  } catch (error) { response.writeHead(500); response.end(String(error)); }
});
await new Promise(resolve => proxy.listen(port, '127.0.0.1', resolve));
let serverErrors = '';
const profile = resolve(directory, 'chrome-profile');
const browser = spawn(chrome, ['--headless', '--disable-gpu', '--in-process-gpu', '--no-sandbox', '--disable-background-networking', '--disable-features=BackForwardCache', '--no-first-run', '--no-default-browser-check', '--remote-allow-origins=*', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let socket;
try {
  for (const scenario of ['p0','p1','checklist','historical','healthy','pagination']) {
    const seed = spawn(php, ['-c', ini, 'tests/_support/OperationalReconciliationBrowserRouter.php', '--seed', scenario], {
      windowsHide:true, env:{...process.env,PHPRC:ini,RECONCILIATION_BROWSER_DIR:directory},stdio:['ignore','ignore',serverLog],
    });
    const code = await new Promise((resolve,reject)=>{seed.on('error',reject);seed.on('exit',resolve);});
    assert.equal(code,0,'Isolated fixture seed: '+scenario);
  }
  for (let i = 0; i < 100; i++) {
    try { const response = await fetch(base + '/operations/reconciliation'); if (response.ok) break; if (i === 99) throw new Error(await response.text()); }
    catch (error) { if (i === 99) throw new Error(`${error.message}\n${serverErrors}`); }
    await delay(100);
  }
  let debugPort;
  for (let i = 0; i < 100; i++) { try { debugPort = (await readFile(resolve(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]; break; } catch { await delay(100); } }
  const tabs = await (await fetch(`http://127.0.0.1:${debugPort}/json/list`)).json();
  socket = new WebSocket(tabs.find(tab => tab.type === 'page').webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
  let id = 0;
  const pending = new Map();
  const network = [];
  socket.onmessage = ({ data }) => {
    const message = JSON.parse(data);
    if (message.method === 'Network.responseReceived' && message.params.response.url.endsWith('.css')) network.push({ url: message.params.response.url, status: message.params.response.status, mime: message.params.response.mimeType });
    if (message.method === 'Network.loadingFailed') network.push(message.params);
    if (message.method === 'Log.entryAdded') network.push(message.params.entry);
    if (pending.has(message.id)) { const promise = pending.get(message.id); pending.delete(message.id); message.error ? promise.reject(new Error(JSON.stringify(message.error))) : promise.resolve(message.result); }
  };
  const call = (method, params = {}) => new Promise((resolve, reject) => {
    const requestId = ++id; const timer = setTimeout(() => reject(new Error(method + ' timeout')), 15000);
    pending.set(requestId, { resolve: value => { clearTimeout(timer); resolve(value); }, reject });
    socket.send(JSON.stringify({ id: requestId, method, params }));
  });
  const evaluate = async expression => { try { const result = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }); if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails)); return result.result.value; } catch (error) { throw new Error(error.message + ': ' + expression.slice(0, 180)); } };
  const navigate = async path => {
    await evaluate('globalThis.hnlNavigationMarker = true');
    const navigation = await call('Page.navigate', { url: base + path });
    assert(!navigation.errorText, 'Navigation error: ' + JSON.stringify(navigation));
    await delay(200);
    let ready = false;
    for (let i = 0; i < 150; i++) {
      try { ready = await evaluate(`!globalThis.hnlNavigationMarker && document.readyState === 'complete' && document.querySelector('h1,h2,h3') != null && location.pathname === ${JSON.stringify(path.split('?')[0])}`); if (ready) break; } catch { /* Context is being replaced during navigation. */ }
      await delay(100);
    }
    assert(ready, 'Navigation completed: ' + path + ': ' + await evaluate('location.href + " " + document.body.innerText.slice(0,800)') + serverErrors.slice(-1200));
    assert(!/Exception:|Error:/.test(await evaluate('document.body.innerText')), await evaluate('document.body.innerText'));
  };

  const results=[];
  await call('Page.enable'); await call('Network.enable');
  const fixture=async scenario=>call('Network.setCookie',{name:'reconciliation_fixture',value:scenario,url:base,path:'/'});
  for(const width of [390,1280,1920]) {
    await call('Emulation.setDeviceMetricsOverride',{width,height:1100,deviceScaleFactor:1,mobile:width===390});
    for(const scenario of ['p0','p1','checklist','historical','healthy']) {
      await fixture(scenario); await navigate('/operations/reconciliation');
      const body=await evaluate('document.body.innerText');
      assert(body.includes('Operational Reconciliation'));
      if(scenario==='p0'){assert(body.includes('P0'));assert(body.includes('movement.old_guest_custody'));assert(body.includes('Movement authority'));assert(body.includes('Import context'));}
      if(scenario==='p1'){for(const text of ['P1','Cleaning','Energy / Fuel','Next confirmed pickup','CURRENT_GENUINE'])assert(body.includes(text),text);}
      if(scenario==='checklist'){for(const text of ['P2','Canonical checklist #20000','Obsolete checklist #20001'])assert(body.includes(text),text);assert(!body.includes('movement.import_current_missing_handoff'));}
      if(scenario==='historical'){assert(body.includes('Historical review'));assert(body.includes('extras.missing_fulfillment'));assert(!body.includes('Current blocker'));}
      if(scenario==='healthy')assert(body.includes('No operational reconciliation issues found.'));
      const metrics=await evaluate(`({width:document.documentElement.scrollWidth,css:document.styleSheets.length,unlabelled:[...document.querySelectorAll('input:not([type=hidden]),select')].filter(e=>!e.labels?.length).map(e=>e.name),actions:[...document.querySelectorAll('main button,main a')].filter(e=>e.getBoundingClientRect().height<44).map(e=>e.textContent),mutations:document.querySelectorAll('main form[method=post]').length})`);
      assert(metrics.width<=width,JSON.stringify(metrics));assert(metrics.css>0);assert.deepEqual(metrics.unlabelled,[]);assert.deepEqual(metrics.actions,[]);assert.equal(metrics.mutations,0);
      await evaluate(`document.querySelector('#filter-severity').focus()`);
      await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
      await call('Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
      assert.equal(await evaluate('document.activeElement.id'),'filter-scope');
      assert(await evaluate(`getComputedStyle(document.activeElement).outlineStyle!=='none'`));
      const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
      await writeFile(resolve(directory,`${width}-${scenario}.png`),Buffer.from(shot.data,'base64'));
      const state=await evaluate(`fetch('/synthetic-state').then(r=>r.json())`);
      assert.deepEqual(state,JSON.parse(await readFile(resolve(directory,scenario+'-before.json'),'utf8')),'Read-only browser fixture '+scenario);
      results.push({width,scenario,metrics,keyboard:true,zeroBusinessWrites:true});
    }
    await fixture('pagination');
    for(const query of ['severity=P0','scope=CURRENT','vehicle=1000','category=movement','severity=P0&scope=CURRENT&vehicle=1000&category=movement','severity=P0&scope=CURRENT&category=position&q=Synthetic','page=2']) {
      await navigate('/operations/reconciliation?'+query);
      assert(!await evaluate('!!document.querySelector("[role=alert]")'));
    }
    await navigate('/operations/reconciliation?severity=P0&scope=CURRENT&category=position&q=Synthetic');
    const next=await evaluate(`document.querySelector('a[rel=next]').getAttribute('href')`);
    assert(next.includes('severity=P0'));assert(next.includes('scope=CURRENT'));assert(next.includes('category=position'));assert(next.includes('q=Synthetic'));
    await navigate(next);assert.equal(await evaluate('document.querySelectorAll(".reconciliation-issue").length'),9);
    await navigate('/operations/reconciliation?vehicle=20');assert(await evaluate('!!document.querySelector("[role=alert]")'));assert.equal(await evaluate('document.querySelectorAll(".reconciliation-issue").length'),0);
    await fixture('p0');await navigate('/');assert(await evaluate(`document.body.textContent.includes('P0 authority review')`),await evaluate('document.body.innerText.slice(0,1600)'));
    results.push({width,filters:true,pagination:true,invalidFilterSafe:true,commandCenter:true});
  }
  // Existing destinations must render successfully; navigation occurs after purity checks.
  await fixture('p0'); await navigate('/operations/reconciliation');
  const links=await evaluate(`[...new Set([...document.querySelectorAll('.reconciliation-review-links a')].map(e=>e.getAttribute('href')))]`);
  for(const href of links){const response=await fetch(base+href,{headers:{Cookie:'reconciliation_fixture=p0'}});assert(response.ok,href+': '+await response.text());}
  await writeFile(resolve(directory,'results.json'),JSON.stringify({results,widths:[390,1280,1920],reviewLinks:links,synthetic:true},null,2));
  console.log(JSON.stringify({artifactDirectory:directory,browserChecks:results.length,widths:[390,1280,1920],reviewLinks:links.length,zeroBusinessWrites:true}));
  await call('Browser.close');
} finally { socket?.close();if(browser.exitCode===null)browser.kill();closeSync(serverLog);proxy.closeAllConnections();proxy.close(); }
