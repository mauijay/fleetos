import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';
import { createServer, request as httpRequest } from 'node:http';

// Runs only the synthetic PHP router and a fresh local Chrome profile.
const directory = resolve('build/b32a-local/browser-' + Date.now());
await mkdir(directory, { recursive: true });
const php = process.env.B21_BROWSER_PHP || 'C:/xampp/php/php.exe';
const ini = resolve(process.env.B21_BROWSER_INI || 'build/php-validation.ini');
const chrome = process.env.B21_BROWSER_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const port = 34581;
const upstreamPort = 34580;
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
    // A separate native HTTP connection avoids pooled fetch transport on PHP's close-only server.
    const upstream = await new Promise((resolve, reject) => {
      const upstreamRequest = httpRequest(`http://127.0.0.1:${upstreamPort}` + request.url, { method: request.method, headers, agent: false }, upstreamResponse => {
        const received = [];
        upstreamResponse.on('data', chunk => received.push(chunk));
        upstreamResponse.on('error', reject);
        upstreamResponse.on('end', () => resolve({ status: upstreamResponse.statusCode, headers: upstreamResponse.headers, body: Buffer.concat(received) }));
      });
      upstreamRequest.on('error', reject);
      upstreamRequest.end(request.method === 'POST' ? Buffer.concat(chunks) : undefined);
    });
    const outputHeaders = { ...upstream.headers };
    delete outputHeaders['transfer-encoding']; delete outputHeaders['content-encoding'];
    delete outputHeaders.connection; delete outputHeaders.host;
    outputHeaders.connection = 'close';
    const body = upstream.body; outputHeaders['content-length'] = body.length;
    response.writeHead(upstream.status, outputHeaders);
    for (let offset = 0; offset < body.length; offset += 16384) {
      if (!response.write(body.subarray(offset, offset + 16384))) await new Promise(resolve => response.once('drain', resolve));
    }
    response.end();
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
  let pageLoaded = false;
  socket.onmessage = ({ data }) => {
    const message = JSON.parse(data);
    if (message.method === 'Page.loadEventFired') pageLoaded = true;
    if (message.method === 'Network.responseReceived' && message.params.type === 'Document') network.push({url:message.params.response.url,status:message.params.response.status});
    if (message.method === 'Network.loadingFinished') network.push(message.params);
    if (message.method === 'Network.responseReceived' && message.params.response.url.endsWith('.css')) network.push({ url: message.params.response.url, status: message.params.response.status, mime: message.params.response.mimeType });
    if (message.method === 'Network.loadingFailed') network.push(message.params);
    if (message.method === 'Log.entryAdded') network.push(message.params.entry);
    if (pending.has(message.id)) { const promise = pending.get(message.id); pending.delete(message.id); message.error ? promise.reject(new Error(JSON.stringify(message.error))) : promise.resolve(message.result); }
  };
  const call = (method, params = {}) => new Promise((resolve, reject) => {
    const requestId = ++id; const timer = setTimeout(() => reject(new Error(method + ' timeout')), 30000);
    pending.set(requestId, { resolve: value => { clearTimeout(timer); resolve(value); }, reject });
    socket.send(JSON.stringify({ id: requestId, method, params }));
  });
  const evaluate = async expression => { const result = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }); if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails)); return result.result.value; };
  const navigate = async path => {
    pageLoaded = false;
    const navigation = await call('Page.navigate', { url: base + path });
    assert(!navigation.errorText, 'Navigation error: ' + JSON.stringify(navigation));
    await delay(200);
    let ready = false;
    for (let i = 0; i < 150; i++) {
      try { ready = pageLoaded && await evaluate(`document.querySelector('h1,h2,h3') != null && location.pathname === ${JSON.stringify(path.split('?')[0])}`); if (ready) break; } catch { /* Context is being replaced during navigation. */ }
      await delay(100);
    }
    assert(ready, 'Navigation completed: ' + path + ': ' + await evaluate('location.href + " " + document.body.innerText.slice(0,800)') + serverErrors.slice(-1200));
    assert(!/Exception:|Error:/.test(await evaluate('document.body.innerText')), await evaluate('document.body.innerText'));
  };
  const fill = async (selector, values) => {
    const valid = await evaluate(`(() => { const form = document.querySelector(${JSON.stringify(selector)}); for (const [name,value] of Object.entries(${JSON.stringify(values)})) { const field = [...form.elements].find(field => field.name === name); if (!field) throw new Error('Missing field '+name); if (field.type === 'checkbox') field.checked = Boolean(value); else field.value = value; } return form.checkValidity(); })()`);
    assert(valid, 'Form must be valid: ' + selector + ': ' + await evaluate(`JSON.stringify([...document.querySelector(${JSON.stringify(selector)}).elements].filter(f=>!f.checkValidity()).map(f=>({name:f.name,value:f.value,error:f.validationMessage})))`));
  };
  const submit = async selector => {
    const previousCommand = await evaluate(`document.querySelector(${JSON.stringify(selector)}).querySelector('[name=command_key]').value`);
    pageLoaded = false;
    await evaluate(`document.querySelector(${JSON.stringify(selector)}).requestSubmit()`);
    for (let i = 0; i < 150; i++) {
      await delay(100);
      if (!pageLoaded) continue;
      try {
        const changed = await evaluate(`document.querySelector(${JSON.stringify(selector)})?.querySelector('[name=command_key]')?.value !== ${JSON.stringify(previousCommand)}`);
        if (changed && await evaluate('!!document.querySelector("body > script[type=module]")')) { assert(!await evaluate('document.body.innerText.includes("Work was not saved.")'), await evaluate('document.body.innerText')); return; }
      } catch (error) { if (error.code === 'ERR_ASSERTION') throw error; }
    }
    throw new Error('Submission did not navigate: ' + selector + ': ' + JSON.stringify(network.slice(-5)) + ': ' + await evaluate(`JSON.stringify({url:location.href,ready:document.readyState,heading:!!document.querySelector("h1"),tail:document.documentElement.outerHTML.slice(-700),network:performance.getEntriesByType("resource").map(r=>r.name),text:document.body.innerText.slice(0,1500)})`));
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
    const pair = await (await fetch(base + '/synthetic-b32a')).json();
    const jobPath = `/fleet/vehicles/10/damage-repairs/${pair.job}`;
    await fetch(base + `/synthetic-b32a?action=archive&expense=${pair.expense}`);
    await navigate(jobPath); await inspect('no-candidate');
    assert(await evaluate('document.querySelector("#financial-reconciliation").innerText.includes("No current vehicle expense candidates")'));
    await fetch(base + `/synthetic-b32a?action=restore&expense=${pair.expense}`);
    await fetch(base + `/synthetic-b32a?action=mismatch&expense=${pair.expense}`);
    await navigate(jobPath); await inspect('amount-mismatch');
    assert(await evaluate('document.querySelector("#financial-reconciliation").innerText.includes("amount differs")'));
    await fetch(base + `/synthetic-b32a?action=restore&expense=${pair.expense}`);
    await navigate('/synthetic-b32a-report'); await inspect('report-before');
    const reportBefore = await evaluate('document.querySelector(".financial-report-summary").innerText + document.querySelector(".financial-results-table").innerText');
    await navigate(jobPath); await inspect('exact-candidate');
    assert(await evaluate('document.querySelector("#financial-reconciliation").innerText.includes("Exact amount candidate")'));
    const before = await (await fetch(base + '/synthetic-b32a?action=stats')).json();
    const preview = jobPath + `/financial-reconciliations/preview?cost_root_entry_id=${pair.root}&operating_expense_id=${pair.expense}`;
    await navigate(preview); await inspect('complete-pair-preview');
    const after = await (await fetch(base + '/synthetic-b32a?action=stats')).json();
    assert.deepEqual(after, before, 'Application GET preview is pure.');
    const confirmations = { confirmed:true, whole_fact_confirmed:true, no_other_job_confirmed:true, no_other_expense_confirmed:true, not_partial_confirmed:true, reason:'Synthetic ' + 'R'.repeat(1990) };
    await fill('form[action$="/financial-reconciliations"]', confirmations);
    await evaluate(`(() => { const field=document.createElement('input'); field.type='hidden'; field.name='synthetic_lost_ack'; field.value='1'; document.querySelector('form[action$="/financial-reconciliations"]').append(field); })()`);
    await submit('form[action$="/financial-reconciliations"]'); await inspect('active-reconciliation');
    assert(await evaluate('document.querySelector("#financial-reconciliation").innerText.includes("active")'));
    assert(await evaluate(`document.querySelector('#financial-reconciliation').innerText.includes(${JSON.stringify(confirmations.reason)})`), 'The complete 2,000-character unbroken reason remains visible without overflow.');
    const activeStats = await (await fetch(base + '/synthetic-b32a?action=stats')).json();
    assert(await evaluate('document.body.innerText.includes("Retry original reconciliation command")'));
    await evaluate(`(() => {const form=document.querySelector('#financial-reconciliation form[action$="/financial-reconciliations"]'); const edited=document.createElement('input'); edited.type='hidden'; edited.name='reason'; edited.value='Synthetic edited retry must be ignored'; form.append(edited); form.requestSubmit();})()`);
    for(let i=0;i<100;i++){await delay(100);try{if(!await evaluate('document.body.innerText.includes("Retry original reconciliation command")'))break;}catch{}}
    await navigate(jobPath); await inspect('lost-ack-retained-retry');
    assert(!await evaluate('document.body.innerText.includes("Retry original reconciliation command")'));
    assert.deepEqual(await (await fetch(base + '/synthetic-b32a?action=stats')).json(), activeStats, 'Retained original HTTP retry adds no economic rows, events or audits.');
    assert.deepEqual(activeStats.expenses, before.expenses, 'Expense report authority amounts/date unchanged.');
    const download = await evaluate(`(async()=>{const a=document.querySelector('#repair-costs a[href$="/download"]');const r=await fetch(a.href);return{status:r.status,pdf:(await r.text()).startsWith('%PDF'),cache:r.headers.get('cache-control')};})()`);
    assert.equal(download.status, 200); assert(download.pdf); assert.match(download.cache, /no-store/);
    assert.equal((await fetch(base + '/private/repair-documents/synthetic-invoice.pdf')).status, 404);
    await navigate('/synthetic-b32a-report'); await inspect('report-after');
    assert.equal(await evaluate('document.querySelector(".financial-report-summary").innerText + document.querySelector(".financial-results-table").innerText'), reportBefore);
    const shared = await (await fetch(base + `/synthetic-b32a?action=shared-pair&expense=${pair.expense}`)).json();
    await navigate(`/fleet/vehicles/10/damage-repairs/${shared.job}`); await inspect('duplicate-expense-reservation');
    assert(!await evaluate(`!!document.querySelector('#financial-reconciliation a[href*="operating_expense_id=${pair.expense}"]')`));
    await navigate(jobPath);
    const active = activeStats.reconciliations.find(row=>Number(row.cost_root_entry_id)===pair.root && row.status_code==='active');
    await navigate(preview + `&reconciliation_id=${active.id}`); await inspect('replacement-preview');
    await fill('form[action$="/financial-reconciliations/replace"]', confirmations);
    await submit('form[action$="/financial-reconciliations/replace"]'); await inspect('replacement-history');
    await fetch(base + `/synthetic-b32a?action=stale&expense=${pair.expense}`);
    await navigate(jobPath); await inspect('stale-reconciliation');
    assert(await evaluate('document.querySelector("#financial-reconciliation").innerText.includes("stale / review required")'));
    const invalidation = 'form[action$="/financial-reconciliations/invalidate"]';
    await evaluate(`document.querySelector('${invalidation}').closest('details').open=true`);
    await fill(invalidation,{confirmed:true,reason:'Synthetic source review'}); await submit(invalidation); await inspect('invalidated-history');
    await fetch(base + `/synthetic-b32a?action=review&expense=${pair.expense}`);
    await navigate(preview); await inspect('date-and-descriptor-review');
    await fill('form[action$="/financial-reconciliations"]', {...confirmations,date_difference_reason:'Synthetic reporting period precedes invoice issue',descriptor_review_confirmed:true,descriptor_review_reason:'Synthetic evidence establishes complete source identity'});
    await submit('form[action$="/financial-reconciliations"]'); await inspect('reviewed-date-difference');
    await fetch(base + `/synthetic-b32a?action=archive&expense=${pair.expense}`);
    await navigate(jobPath); await inspect('archived-source');
    await call('Input.dispatchKeyEvent', {type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
    await call('Input.dispatchKeyEvent', {type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
    assert(await evaluate('document.activeElement !== document.body'), 'Keyboard focus moves to controls.');
  }
  await writeFile(resolve(directory, 'results.json'), JSON.stringify({ checks: results, keyboard: true, privateEvidence: true, syntheticOnly: true }, null, 2));
  console.log(JSON.stringify({ artifactDirectory: directory, browserChecks: results.length, widths: [390,1280,1920], keyboard: true, privateEvidence: true }));
  await call('Browser.close');
} finally {
  await writeFile(resolve(directory, 'php-server.log'), serverErrors);
  socket?.close(); if (browser.exitCode === null) browser.kill(); if (server.exitCode === null) server.kill();
  proxy.closeAllConnections(); proxy.close();
}
