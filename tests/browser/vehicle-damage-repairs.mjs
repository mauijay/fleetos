import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';
import { createServer } from 'node:http';

// Runs only the synthetic PHP router and a fresh local Chrome profile.
const directory = resolve('build/b21-review/browser-' + Date.now());
await mkdir(directory, { recursive: true });
const php = process.env.B21_BROWSER_PHP || 'C:/xampp/php/php.exe';
const ini = resolve(process.env.B21_BROWSER_INI || 'build/php-b1.ini');
const chrome = process.env.B21_BROWSER_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const port = 33471;
const upstreamPort = 33470;
const base = `http://127.0.0.1:${port}`;
const server = spawn(php, ['-c', ini, '-S', `127.0.0.1:${upstreamPort}`, '-t', 'public', 'tests/_support/VehicleDamageRepairBrowserRouter.php'], {
  windowsHide: true, env: { ...process.env, PHPRC: ini, XDEBUG_MODE: 'off', B21_BROWSER_DIR: directory, B21_BROWSER_BASEURL: base + '/' }, stdio: ['ignore', 'ignore', 'pipe'],
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
    const upstream = await fetch(`http://127.0.0.1:${upstreamPort}` + request.url, { method: request.method, headers, body: request.method === 'POST' ? Buffer.concat(chunks) : undefined, redirect: 'manual' });
    const outputHeaders = Object.fromEntries(upstream.headers);
    delete outputHeaders['transfer-encoding']; delete outputHeaders['content-encoding'];
    if (upstream.headers.getSetCookie().length) outputHeaders['set-cookie'] = upstream.headers.getSetCookie();
    const body = Buffer.from(await upstream.arrayBuffer()); outputHeaders['content-length'] = body.length;
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
    try { const response = await fetch(base + '/fleet/vehicles/10/damage-repairs'); if (response.ok) break; if (i === 99) throw new Error(await response.text()); }
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
  const evaluate = async expression => { const result = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }); if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails)); return result.result.value; };
  const navigate = async path => {
    const navigation = await call('Page.navigate', { url: base + path });
    assert(!navigation.errorText, 'Navigation error: ' + JSON.stringify(navigation));
    await delay(200);
    let ready = false;
    for (let i = 0; i < 150; i++) {
      try { ready = await evaluate(`document.querySelector('h1,h2,h3') != null && location.pathname === ${JSON.stringify(path.split('?')[0])}`); if (ready) break; } catch { /* Context is being replaced during navigation. */ }
      await delay(100);
    }
    assert(ready, 'Navigation completed: ' + path + ': ' + await evaluate('location.href + " " + document.body.innerText.slice(0,800)') + serverErrors.slice(-1200));
    assert(!/Exception:|Error:/.test(await evaluate('document.body.innerText')), await evaluate('document.body.innerText'));
  };
  const fill = async (selector, values) => {
    const valid = await evaluate(`(() => { const form = document.querySelector(${JSON.stringify(selector)}); for (const [name,value] of Object.entries(${JSON.stringify(values)})) { const field = [...form.elements].find(field => field.name === name); if (!field) throw new Error('Missing field '+name); if (field.type === 'checkbox') field.checked = Boolean(value); else field.value = value; } return form.checkValidity(); })()`);
    assert(valid, 'Form must be valid: ' + selector);
  };
  const submit = async selector => {
    const previousCommand = await evaluate(`document.querySelector(${JSON.stringify(selector)}).querySelector('[name=command_key]').value`);
    await evaluate(`document.querySelector(${JSON.stringify(selector)}).requestSubmit()`);
    for (let i = 0; i < 150; i++) {
      await delay(100);
      try {
        const changed = await evaluate(`document.querySelector(${JSON.stringify(selector)})?.querySelector('[name=command_key]')?.value !== ${JSON.stringify(previousCommand)}`);
        if (changed) { assert(!await evaluate('document.body.innerText.includes("Work was not saved.")'), await evaluate('document.body.innerText')); return; }
      } catch (error) { if (error.code === 'ERR_ASSERTION') throw error; }
    }
    throw new Error('Submission did not navigate: ' + selector + ': ' + await evaluate(`JSON.stringify({url:location.href,text:document.body.innerText,form:document.querySelector(${JSON.stringify(selector)})?.outerHTML})`));
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
  for (width of [390, 1280, 1920]) {
    await call('Emulation.setDeviceMetricsOverride', { width, height: 1100, deviceScaleFactor: 1, mobile: width === 390 });
    await navigate('/fleet/vehicles/10'); await inspect('existing-damage-workspace');
    await navigate('/fleet/vehicles/10/damage-repairs'); await inspect('list');
    await navigate('/fleet/vehicles/10/damage-repairs/new'); await inspect('create');
    await evaluate(`document.querySelector('[name=summary]').focus()`);
    await call('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 }); await call('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    assert.equal(await evaluate('document.activeElement.name'), 'intent_code');
    await fill('[data-work-form]', { summary: `Synthetic browser work ${width}`, intent_code: 'repair', category_code: 'body', 'conditions[0][canonical_confirmed]': true, 'conditions[1][canonical_confirmed]': true });
    await submit('[data-work-form]'); await inspect('multi-condition-job');
    const jobPath = await evaluate('location.pathname');
    // A committed command replay must preserve the original receipt and report it clearly.
    await evaluate(`document.querySelector('form[action$="/schedule"]').closest('details').open = true`);
    await fill('form[action$="/schedule"]', { scheduled_at: '2026-10-01T10:00' });
    const savedSchedule = await evaluate(`Object.fromEntries(new FormData(document.querySelector('form[action$="/schedule"]')))`);
    await submit('form[action$="/schedule"]');
    const replay = await evaluate(`(async () => { const form = document.querySelector('form[action$="/schedule"]'); const data = new URLSearchParams(${JSON.stringify(savedSchedule)}); const csrf = [...form.elements].find(field => field.name.startsWith('csrf')); data.set(csrf.name, csrf.value); const response = await fetch(form.action, {method:'POST',body:data}); return (await response.text()).includes('Already saved.'); })()`);
    assert(replay, 'Replay feedback');
    await navigate(jobPath);
    await evaluate(`document.querySelector('form[action$="/schedule"]').closest('details').open = true`);
    await fill('form[action$="/schedule"]', { scheduled_at: '2026-10-02T10:00' }); await submit('form[action$="/schedule"]');
    const stale = await evaluate(`Object.fromEntries(new FormData(document.querySelector('form[action$="/schedule"]')))`);
    await evaluate(`document.querySelector('form[action$="/start"]').closest('details').open = true`);
    await fill('form[action$="/start"]', { started_at: '2026-10-01T09:00' }); await submit('form[action$="/start"]');
    const staleRejected = await evaluate(`(async () => { const csrf = [...document.querySelector('form').elements].find(field=>field.name.startsWith('csrf')); const data = new URLSearchParams(${JSON.stringify(stale)}); data.set('scheduled_at','2026-10-02T10:00'); data.set(csrf.name,csrf.value); const response = await fetch(${JSON.stringify(base)}+${JSON.stringify(jobPath)}+'/schedule',{method:'POST',body:data}); return (await response.text()).includes('Work changed since this form was opened.'); })()`);
    assert(staleRejected, 'Stale-state feedback'); await navigate(jobPath);
    await evaluate(`document.querySelector('form[action$="/defer"]').closest('details').open = true`);
    await fill('form[action$="/defer"]', { reason: 'Synthetic delay' }); await submit('form[action$="/defer"]');
    await evaluate(`document.querySelector('form[action$="/resume"]').closest('details').open = true`);
    await fill('form[action$="/resume"]', { target_status: 'in_progress', reason: 'Synthetic continuing work' }); await submit('form[action$="/resume"]');
    await evaluate(`document.querySelector('form[action$="/complete"]').closest('details').open = true`);
    await fill('form[action$="/complete"]', { 'outcomes[0][result_code]': 'repair_reported', 'outcomes[0][note]': 'Synthetic reported repair', 'outcomes[1][result_code]': 'unchanged', 'outcomes[1][note]': 'Synthetic finding '.repeat(80), completed_at: '2026-10-02T11:00', completion_note: 'Synthetic work completed' });
    await inspect('completion-form'); await submit('form[action$="/complete"]'); await inspect('completed-job');
    const confirmPath = await evaluate(`[...document.querySelectorAll('a')].find(link=>link.textContent.includes('Inspect and confirm')).pathname`);
    await navigate(confirmPath); await inspect('repair-preview');
    await fill('form', { inspected_at: '2026-10-02T12:00', inspection_note: 'Synthetic inspected repair', confirmed: true }); await submit('form');
    const reopenPath = await evaluate(`[...document.querySelectorAll('a')].find(link=>link.textContent.includes('Review and reopen')).pathname`);
    await navigate(reopenPath); await inspect('condition-reopen');
    await fill('form', { observed_at: '2026-10-03T10:00', note: 'Synthetic residual damage', reason_category_code: 'residual_damage', confirmed: true }); await submit('form');
    assert(await evaluate('document.body.innerText.includes("Partially repaired")'), 'Residual result');
    await evaluate(`document.querySelector('form[action$="/reopen"]').closest('details').open = true`);
    await fill('form[action$="/reopen"]', { same_work_order_confirmed: true, reason_category_code: 'continuing_order', reason: 'Synthetic same work order' }); await submit('form[action$="/reopen"]');
    await evaluate(`document.querySelector('form[action$="/cancel"]').closest('details').open = true`);
    await fill('form[action$="/cancel"]', { reason: 'Synthetic cancellation' }); await submit('form[action$="/cancel"]'); await inspect('cancelled-job');
    await navigate('/synthetic-checklist'); await inspect('checklist');
    assert(await evaluate(`document.querySelector('.work-badge') != null`), 'Checklist badge');
    assert(!await evaluate(`!!document.querySelector('form[action*="damage-repairs"]')`), 'No checklist work forms');
    await navigate('/fleet/vehicles/10/damage-repairs/new');
    await evaluate(`document.querySelector('[name=performed_confirmed]').closest('details').open = true`);
    await fill('[data-work-form]', { summary: 'Synthetic historical mitigation', intent_code: 'mitigation', recording_mode: 'historical_mitigation', 'conditions[0][canonical_confirmed]': true, performed_confirmed: true, recording_reason: 'Synthetic historical backfill', completion_note: 'Temporary protection; exact time unknown' });
    await submit('[data-work-form]'); await inspect('historical-mitigation');
  }
  await navigate('/fleet/vehicles/10/damage/3/reopen'); width = 1920; await inspect('legacy-reopen');
  await fill('form', { observed_at: '2026-10-03T10:00', reason_category_code: 'repair_failure', note: 'Synthetic legacy failure', confirmed: true });
  await submit('form'); await inspect('legacy-restored');
  const csrfRejected = await evaluate(`(async()=>{const response=await fetch('/fleet/vehicles/10/damage-repairs',{method:'POST',body:new URLSearchParams({summary:'Synthetic missing token'})});return (await response.text()).includes('SecurityException');})()`);
  assert(csrfRejected, 'Missing CSRF is rejected');
  await writeFile(resolve(directory, 'results.json'), JSON.stringify({ checks: results, keyboard: true, replay: true, stale: true, csrf: true }, null, 2));
  console.log(JSON.stringify({ artifactDirectory: directory, browserChecks: results.length, widths: [390,1280,1920], keyboard: true, replay: true, stale: true, csrf: true }));
  await call('Browser.close');
} finally {
  socket?.close(); if (browser.exitCode === null) browser.kill(); if (server.exitCode === null) server.kill();
  proxy.closeAllConnections(); proxy.close();
}
