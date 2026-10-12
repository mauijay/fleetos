import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';
import { createServer, request as httpRequest } from 'node:http';

// Isolate each viewport's server, session, database and Chrome profile.
if (!process.env.MOVEMENT_BROWSER_WIDTH) {
  for (const width of [390, 1280, 1920]) {
    const child = spawn(process.execPath, [resolve(process.argv[1])], { windowsHide:true, stdio:'inherit', env:{...process.env,MOVEMENT_BROWSER_WIDTH:String(width)} });
    const code = await new Promise((resolve, reject) => { child.on('error', reject); child.on('exit', resolve); });
    assert.equal(code, 0, 'Synthetic movement viewport ' + width);
  }
  console.log(JSON.stringify({browserChecks:21,widths:[390,1280,1920],keyboard:true,zeroBusinessWrites:true,synthetic:true}));
  process.exit(0);
}
assert(['390','1280','1920'].includes(process.env.MOVEMENT_BROWSER_WIDTH));

// Runs only the synthetic PHP router and a fresh local Chrome profile.
const directory = resolve('build/handoff-condition-local/browser-' + Date.now());
await mkdir(directory, { recursive: true });
const php = process.env.MOVEMENT_BROWSER_PHP || 'C:/xampp/php/php.exe';
const ini = resolve(process.env.MOVEMENT_BROWSER_INI || 'build/php-validation.ini');
const chrome = process.env.MOVEMENT_BROWSER_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const port = 35571;
const upstreamPort = 35570;
const base = `http://127.0.0.1:${port}`;
const server = spawn(php, ['-c', ini, '-S', `127.0.0.1:${upstreamPort}`, '-t', 'public', 'tests/_support/MovementPresentationBrowserRouter.php'], {
  windowsHide: true, env: { ...process.env, PHPRC: ini, XDEBUG_MODE: 'off', MOVEMENT_BROWSER_DIR: directory, MOVEMENT_BROWSER_BASEURL: base + '/' }, stdio: ['ignore', 'ignore', 'pipe'],
});
// Serve built assets outside PHP's single-threaded development server, preserving one browser origin.
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
    const chunks = []; for await (const chunk of request) chunks.push(chunk);
    const headers = { ...request.headers, connection: 'close' }; delete headers.host;
    // One socket per PHP request avoids idle pooled sockets blocking the Windows SAPI.
    const upstream = await new Promise((resolve, reject) => {
      const outgoing = httpRequest({ hostname:'127.0.0.1', port:upstreamPort, path:request.url, method:request.method, headers, agent:false }, incoming => {
        const parts = []; incoming.on('data', part => parts.push(part));
        incoming.on('end', () => resolve({status:incoming.statusCode,headers:incoming.headers,body:Buffer.concat(parts)}));
        incoming.on('error', reject);
      });
      outgoing.on('error', reject); outgoing.setTimeout(30000, () => outgoing.destroy(new Error('Synthetic upstream timeout')));
      outgoing.end(Buffer.concat(chunks));
    });
    const outputHeaders = { ...upstream.headers };
    delete outputHeaders['transfer-encoding']; delete outputHeaders['content-encoding'];
    const body = upstream.body; outputHeaders['content-length'] = body.length;
    response.writeHead(upstream.status, outputHeaders); response.end(body);
  } catch (error) { response.writeHead(500); response.end(String(error)); }
});
await new Promise(resolve => proxy.listen(port, '127.0.0.1', resolve));
let serverErrors = '';
server.stderr.on('data', data => { serverErrors += data; });
const profile = resolve(directory, 'chrome-profile');
const browser = spawn(chrome, ['--headless', '--disable-gpu', '--in-process-gpu', '--no-sandbox', '--disable-background-networking', '--disable-features=BackForwardCache', '--no-first-run', '--no-default-browser-check', '--remote-allow-origins=*', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let socket;
try {
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
  const results = [];
  let width;
  const inspect = async label => {
    for (let i = 0; i < 50; i++) { if (await evaluate(`document.querySelector('link[rel=stylesheet]')?.sheet != null`)) break; await delay(100); }
    const metrics = await evaluate(`({url:location.pathname,viewport:innerWidth,width:document.documentElement.scrollWidth,unlabelled:[...document.querySelectorAll('input:not([type=hidden]),select,textarea')].filter(field=>!field.labels?.length).map(field=>field.name),stylesheet:document.querySelector('link[rel=stylesheet]')?.sheet != null})`);
    if (metrics.viewport !== width || metrics.width > width) { metrics.overflow = await evaluate(`Array.from(document.querySelectorAll('main *')).filter(el => el.clientWidth > 0 && el.scrollWidth > el.clientWidth + 1).map(el => ({tag:el.tagName,class:el.className,width:el.clientWidth,scroll:el.scrollWidth,text:el.textContent.trim().slice(0,100)})).slice(0,25)`); }
    assert.equal(metrics.viewport, width, JSON.stringify(metrics)); assert(metrics.width <= width, label + ': overflow ' + JSON.stringify(metrics)); assert.deepEqual(metrics.unlabelled, []); assert(metrics.stylesheet, 'Stylesheet loaded: ' + JSON.stringify({metrics,network:network.slice(-5)}));
    const screenshot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    await writeFile(resolve(directory, `${width}-${label}.png`), Buffer.from(screenshot.data, 'base64'));
    results.push({ width, label, ...metrics });
  };
  await call('Page.enable'); await call('Network.enable'); await call('Page.setLifecycleEventsEnabled', { enabled: true });
  await call('Log.enable');
  await call('Network.setCacheDisabled', { cacheDisabled: true });
  const state = scenario => evaluate(`fetch('${base}/synthetic-state?scenario=${scenario}').then(response=>response.json())`);
  width = Number(process.env.MOVEMENT_BROWSER_WIDTH);
  await call('Emulation.setDeviceMetricsOverride', { width, height:1100, deviceScaleFactor:1, mobile:width===390 });
  const scenarios = ['both', 'charge', 'cleanliness', 'none', 'direct', 'mixed', 'long'];
  for (const scenario of scenarios) {
    const name = 'condition-' + scenario;
    await navigate('/operations/checklists/102?scenario=' + name);
    const before = await state(name);
    const card = await evaluate(`document.querySelector('[aria-labelledby="pickup-fact-heading"]').innerText`);
    assert(card.includes('Guest handoff recorded'), card);
    assert(card.includes('9:35 PM'), card);
    assert(!card.includes('Not captured'), card);
    const condition = await evaluate(`document.querySelector('.handoff-condition').textContent.replace(/\\s+/g, ' ').trim()`);
    assert.equal(condition.includes('Last confirmed before handoff'), !['none', 'direct'].includes(scenario), condition);
    assert.equal(condition.includes('From staging at'), !['none', 'direct'].includes(scenario), condition);
    assert.equal(condition.includes('8:45 PM Honolulu'), !['none', 'direct'].includes(scenario), condition);
    assert.equal(condition.includes('Confirmed at handoff'), ['direct', 'mixed'].includes(scenario), condition);
    assert.equal(condition.includes('No prior observation'), scenario === 'none', condition);
    assert.equal(condition.includes('Cleanliness'), !['charge', 'none'].includes(scenario), condition);
    assert.equal(condition.includes('Charge'), !['cleanliness', 'none'].includes(scenario), condition);
    assert.equal(condition.includes('80%'), !['cleanliness', 'none', 'direct'].includes(scenario), condition);
    assert.equal(condition.includes('61%'), scenario === 'direct', condition);
    assert.equal(condition.includes('Dirty'), ['direct', 'mixed'].includes(scenario), condition);
    const values = await evaluate(`Array.from(document.querySelectorAll('.handoff-condition dd'), dd => dd.firstChild.textContent.trim())`);
    assert.equal(values.includes('Clean'), ['both', 'cleanliness', 'long'].includes(scenario), condition);
    const bounds = await evaluate(`(() => {const card=document.querySelector('[aria-labelledby="pickup-fact-heading"]'); const r=card.getBoundingClientRect(); return [...card.querySelectorAll('.handoff-condition dd,.movement-fact-detail')].every(el=>{const b=el.getBoundingClientRect(); return b.left>=r.left && b.right<=r.right && el.scrollWidth<=el.clientWidth+1;});})()`);
    assert(bounds, 'Condition/location overflow: ' + scenario);
    await evaluate(`document.querySelector('[aria-labelledby="pickup-fact-heading"] .fact-actions a').focus()`);
    assert.equal(await evaluate(`document.activeElement.textContent.trim()`), 'Correct pickup');
    assert.deepEqual(await state(name), before, 'GET and focus remain zero-write: ' + scenario);
    await inspect('handoff-' + scenario);
  }
  await writeFile(resolve(directory,'results.json'),JSON.stringify({checks:results,widths:[width],keyboard:true,zeroBusinessWrites:true,synthetic:true},null,2));
  console.log(JSON.stringify({artifactDirectory:directory,browserChecks:results.length,widths:[width],keyboard:true,zeroBusinessWrites:true}));
  await call('Browser.close');
} finally {
  await writeFile(resolve(directory, 'server.log'), serverErrors);
  socket?.close(); if (browser.exitCode === null) browser.kill(); if (server.exitCode === null) server.kill();
  proxy.closeAllConnections(); proxy.close();
}
