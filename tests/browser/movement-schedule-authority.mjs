import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { openSync, closeSync } from 'node:fs';
import { resolve, sep } from 'node:path';
import { createServer } from 'node:http';

// Isolate each viewport's server, session, database and Chrome profile.
if (!process.env.SCHEDULE_BROWSER_WIDTH) {
  for (const width of [390, 1280, 1920]) {
    const child = spawn(process.execPath, [resolve(process.argv[1])], { windowsHide:true, stdio:'inherit', env:{...process.env,SCHEDULE_BROWSER_WIDTH:String(width)} });
    const code = await new Promise((resolve, reject) => { child.on('error', reject); child.on('exit', resolve); });
    assert.equal(code, 0, 'Synthetic movement viewport ' + width);
  }
  console.log(JSON.stringify({browserChecks:9,widths:[390,1280,1920],keyboard:true,zeroBusinessWrites:true,synthetic:true}));
  process.exit(0);
}
assert(['390','1280','1920'].includes(process.env.SCHEDULE_BROWSER_WIDTH));

// Runs only the synthetic PHP router and a fresh local Chrome profile.
const directory = resolve('build/validation/browser-' + Date.now());
await mkdir(directory, { recursive: true });
const php = process.env.MOVEMENT_BROWSER_PHP || 'C:/xampp/php/php.exe';
const ini = resolve(process.env.MOVEMENT_BROWSER_INI || 'build/php-validation.ini');
const chrome = process.env.MOVEMENT_BROWSER_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const port = 35671;
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
    const scenario = new URL(request.url,base).searchParams.get('scenario') || 'historical';
    const body = await new Promise((resolve,reject)=>{
      const worker=spawn(php,['-c',ini,'tests/_support/MovementPresentationBrowserRouter.php','--render',scenario,request.url],{
        windowsHide:true,env:{...process.env,PHPRC:ini,MOVEMENT_BROWSER_DIR:directory,MOVEMENT_BROWSER_BASEURL:base+'/'},stdio:['ignore','pipe',serverLog],
      });
      const parts=[]; worker.stdout.on('data',part=>parts.push(part)); worker.on('error',reject);
      worker.on('close',code=>code===0?resolve(Buffer.concat(parts)):reject(new Error('Synthetic render failed: '+code)));
    });
    response.writeHead(200,{'Content-Type':path.startsWith('/synthetic-')?'application/json':'text/html','Content-Length':body.length});response.end(body);
  } catch (error) { response.writeHead(500); response.end(String(error)); }
});
await new Promise(resolve => proxy.listen(port, '127.0.0.1', resolve));
let serverErrors = '';
const profile = resolve(directory, 'chrome-profile');
const browser = spawn(chrome, ['--headless', '--disable-gpu', '--in-process-gpu', '--no-sandbox', '--disable-background-networking', '--disable-features=BackForwardCache', '--no-first-run', '--no-default-browser-check', '--remote-allow-origins=*', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let socket;
try {
  for (const scenario of ['historical','schedule','schedule-early','schedule-equal']) {
    const seed = spawn(php, ['-c', ini, 'tests/_support/MovementPresentationBrowserRouter.php', '--seed', scenario], {
      windowsHide:true, env:{...process.env,PHPRC:ini,MOVEMENT_BROWSER_DIR:directory},stdio:['ignore','ignore',serverLog],
    });
    const code = await new Promise((resolve,reject)=>{seed.on('error',reject);seed.on('exit',resolve);});
    assert.equal(code,0,'Isolated fixture seed: '+scenario);
  }
  for (let i = 0; i < 100; i++) {
    try { const response = await fetch(base + '/operations/checklists/102'); if (response.ok) break; if (i === 99) throw new Error(await response.text()); }
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
  const width=Number(process.env.SCHEDULE_BROWSER_WIDTH);
  await call('Page.enable'); await call('Network.enable');
  await call('Emulation.setDeviceMetricsOverride',{width,height:1100,deviceScaleFactor:1,mobile:width===390});
  for(const scenario of ['schedule','schedule-early','schedule-equal']){
    // Warm each disposable schema before browser navigation (Windows SQLite setup can be slow).
    const warm = await fetch(base+'/operations/checklists/102?scenario='+scenario);
    assert(warm.ok, await warm.text());
    await navigate('/operations/checklists/102?scenario='+scenario);
    const before=await evaluate(`fetch('${base}/synthetic-state?scenario=${scenario}').then(r=>r.json())`);
    const header=await evaluate(`document.querySelector('.status-copy').innerText`);
    assert(header.includes(scenario==='schedule-early'?'2026-10-07 10:30:00':'2026-10-07 08:30:00'),header);
    assert.equal((await evaluate('document.body.innerText')).includes('Historical checklist schedule:'),scenario==='schedule');
    const warning=await evaluate(`fetch('${base}/synthetic-schedule-warning?scenario=${scenario}').then(r=>r.json())`);
    assert.equal(warning.warning===null,scenario!=='schedule-early');
    if(scenario==='schedule-early'){assert(warning.warning.includes('10:30 AM'));assert(warning.warning.includes('more than 2 hours early'));}
    const metrics=await evaluate(`({viewport:innerWidth,width:document.documentElement.scrollWidth,css:document.styleSheets.length,unlabelled:[...document.querySelectorAll('input:not([type=hidden]),select,textarea')].filter(el=>!el.labels?.length&&!el.getAttribute('aria-label')).map(el=>el.name)})`);
    assert(metrics.width<=width,JSON.stringify(metrics));assert(metrics.css>0);assert.deepEqual(metrics.unlabelled,[]);
    await evaluate(`document.querySelector('a[href],button,input:not([type=hidden])')?.focus()`);
    assert(await evaluate('document.activeElement!==document.body'));
    const screenshot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
    await writeFile(resolve(directory,`${width}-${scenario}.png`),Buffer.from(screenshot.data,'base64'));
    await navigate('/operations/checklists/102?scenario='+scenario);
    const after=await evaluate(`fetch('${base}/synthetic-state?scenario=${scenario}').then(r=>r.json())`);
    assert.deepEqual(after,before,'Schedule GET/guard did not write business data');
    results.push({scenario,width,header,warning:warning.warning,zeroBusinessWrites:true});
  }
  await writeFile(resolve(directory,'results.json'),JSON.stringify({results,width,synthetic:true},null,2));
  console.log(JSON.stringify({artifactDirectory:directory,browserChecks:results.length,width,zeroBusinessWrites:true}));
  await call('Browser.close');
}finally{
  socket?.close();if(browser.exitCode===null)browser.kill();
  closeSync(serverLog);
  proxy.closeAllConnections();proxy.close();
}
