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
  console.log(JSON.stringify({browserChecks:30,widths:[390,1280,1920],keyboard:true,zeroBusinessWrites:true,synthetic:true}));
  process.exit(0);
}
assert(['390','1280','1920'].includes(process.env.MOVEMENT_BROWSER_WIDTH));

// Runs only the synthetic PHP router and a fresh local Chrome profile.
const directory = resolve('build/v0321-local/browser-' + Date.now());
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
  // The explanation must remain visible with application JavaScript disabled.
  await call('Emulation.setScriptExecutionDisabled', { value:true });
  await navigate('/operations/checklists/102?scenario=historical');
  const before = await state('historical');
  const text = await evaluate('document.body.innerText');
  assert(text.includes('Earlier staging is historical and no longer satisfies this pickup.'));
  assert(text.includes('A later vehicle recovery occurred'));
  for (const time of ['2:47 PM Honolulu','4:42 PM Honolulu','4:58 PM Honolulu']) assert(text.includes(time), time);
  assert(text.includes('current vehicle lifecycle belongs to a later reservation'));
  assert(text.includes('Historical staging and current vehicle position alone do not satisfy it'));
  assert(text.includes('Guest pickup confirmation is unavailable'));
  assert(!text.includes('activity recorded on'));
  assert.equal(await evaluate(`!!document.querySelector('form[action$="/stage-at-hnl"],form[action$="/confirm-guest-pickup"]')`), false);
  assert.equal(await evaluate(`document.querySelectorAll('#checklist-action-airport_staging a,#checklist-action-airport_staging button').length`), 0);
  // Keyboard navigation to a readiness anchor reaches the explanatory status.
  await call('Emulation.setScriptExecutionDisabled', { value:false });
  await evaluate(`document.querySelector('#historical-staging-explanation').focus()`);
  assert.equal(await evaluate('document.activeElement.id'), 'historical-staging-explanation');
  const anchor = await evaluate(`(() => { const link=document.querySelector('a[href="#historical-staging-explanation"]'); if(!link) throw new Error('Missing staging explanation link'); link.focus(); return link.hash; })()`);
  assert.equal(anchor, '#historical-staging-explanation');
  await call('Input.dispatchKeyEvent', { type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13 });
  await call('Input.dispatchKeyEvent', { type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13 });
  assert.equal(await evaluate('location.hash'), anchor);
  await inspect('historical-stage-explanation');
  assert.deepEqual(await state('historical'),before);
  for (const scenario of ['recovery','later']) {
    const controlBefore = await state(scenario);
    await navigate(`/operations/checklists/102?scenario=${scenario}`);
    const explanation = await evaluate(`document.querySelector('#historical-staging-explanation').innerText`);
    assert(explanation.includes('Earlier staging is historical'));
    assert(!/recorded on|recorded at|entered on|entered at/.test(explanation));
    assert(explanation.includes(scenario==='recovery'?'vehicle recovery occurred at':'activity that occurred at'));
    assert.equal(explanation.includes('belongs to a later reservation'),scenario==='later');
    assert.equal(await evaluate(`!!document.querySelector('form[action$="/stage-at-hnl"]')`),scenario==='recovery');
    assert.equal(await evaluate(`!!document.querySelector('form[action$="/confirm-guest-pickup"]')`),false);
    await inspect(scenario+'-historical-stage');
    assert.deepEqual(await state(scenario),controlBefore);
  }
  for (const id of [200,201]) {
    await navigate(`/operations/checklists/${id}?scenario=historical`);
    const context = await evaluate(`document.querySelector('.trip-context').innerText`);
    assert(context.includes('Scheduled pickup location')); assert(context.includes('Scheduled return location'));
    assert(context.includes('Unknown'));
    const facts = await evaluate(`document.querySelector('.trip-facts-grid').innerText`);
    assert(facts.includes('Guest handoff recorded')); assert(facts.includes('Vehicle recovery recorded'));
    assert(facts.includes('Waikiki Hotel')); assert(facts.includes('Synthetic Garden Hotel'));
    await inspect(id===200?'scheduled-unknown-pickup':'scheduled-unknown-return');
  }
  await navigate('/operations/vehicles/20/trip-history?scenario=historical');
  const history = await evaluate('document.body.innerText');
  assert(history.includes('Scheduled pickup location')); assert(history.includes('Scheduled return location'));
  assert(history.includes('Scheduled pickup location: Unknown')); assert(history.includes('Scheduled return location: Unknown'));
  await inspect('scheduled-context-history');
  assert.deepEqual(await state('historical'),before);
  for (const scenario of ['valid','position']) {
    const controlBefore = await state(scenario);
    await navigate(`/operations/checklists/102?scenario=${scenario}`);
    assert.equal(await evaluate(`new URL(document.querySelector('#handoff-entry').action).pathname`), '/operations/checklists/102/confirm-guest-pickup');
    assert.equal(await evaluate(`!!document.querySelector('#historical-staging-explanation')`), false);
    assert.equal(await evaluate(`!!document.querySelector('form[action$="/stage-at-hnl"]')`), false);
    await inspect(scenario+'-current-stage-control');
    assert.deepEqual(await state(scenario),controlBefore);
  }
  const guestBefore = await state('guest');
  await navigate('/operations/checklists/102?scenario=guest');
  assert.equal(await evaluate(`!!document.querySelector('form[action$="/stage-at-hnl"],form[action$="/confirm-guest-pickup"]')`), false);
  assert((await evaluate('document.body.innerText')).includes('Guest pickup confirmation is unavailable'));
  await inspect('guest-custody-unavailable');
  assert.deepEqual(await state('guest'),guestBefore);
  const handoffBefore = await state('handoff');
  await navigate('/operations/checklists/102?scenario=handoff');
  assert((await evaluate('document.body.innerText')).includes('Rented'));
  assert((await evaluate(`document.querySelector('.trip-facts-grid').innerText`)).includes('Guest handoff recorded'));
  assert.equal(await evaluate(`!!document.querySelector('#historical-staging-explanation')`),false);
  assert.equal(await evaluate(`!!document.querySelector('form[action$="/stage-at-hnl"],form[action$="/confirm-guest-pickup"]')`),false);
  await inspect('next-day-handoff-rented');
  assert.deepEqual(await state('handoff'),handoffBefore);
  await writeFile(resolve(directory,'results.json'),JSON.stringify({checks:results,widths:[width],keyboard:true,zeroBusinessWrites:true,synthetic:true},null,2));
  console.log(JSON.stringify({artifactDirectory:directory,browserChecks:results.length,widths:[width],keyboard:true,zeroBusinessWrites:true}));
  await call('Browser.close');
} finally {
  await writeFile(resolve(directory, 'server.log'), serverErrors);
  socket?.close(); if (browser.exitCode === null) browser.kill(); if (server.exitCode === null) server.kill();
  proxy.closeAllConnections(); proxy.close();
}
