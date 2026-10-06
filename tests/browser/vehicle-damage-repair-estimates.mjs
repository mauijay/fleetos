import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';
import { createServer } from 'node:http';

// Runs only the synthetic PHP router and a fresh local Chrome profile.
const directory = resolve('build/b22-review/browser-' + Date.now());
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
    const createQuote = async ({historical = false, revision = null, vendor = 'Synthetic browser vendor'} = {}) => {
      await navigate(jobPath + (revision ? `/estimates/${revision}/revision` : '/estimates/new')); await inspect(historical ? 'historical-form' : 'quote-form');
      await fill('form', {recording_mode: historical ? 'historical_incomplete' : 'current_quote', amount: '1234.50', currency: 'USD', amount_confirmed: true, currency_confirmed: true, vendor_snapshot: historical ? '' : vendor, quote_date: historical ? '' : '2026-10-06', vendor_confirmed: !historical, date_confirmed: !historical, scope_confirmed: !historical, historical_recording_reason: historical ? 'Synthetic incomplete quote source' : '', vendor_quote_reference: 'Synthetic reference '.repeat(6), note: 'Synthetic long quote note '.repeat(60)});
      await evaluate(`document.querySelectorAll('[name="scope_membership_ids[]"]').forEach(field=>field.checked=true); const supersede = document.querySelector('[name=supersede_previous_confirmed]'); if(supersede) supersede.checked=true;`);
      if (!historical) {
        const pdf = resolve(directory, `synthetic-${width}-${Date.now()}.pdf`); await writeFile(pdf, '%PDF-1.4\n% Synthetic browser source '+width+' '+Date.now()+'\n%%EOF\n');
        const root = await call('DOM.getDocument'); const field = await call('DOM.querySelector',{nodeId:root.root.nodeId,selector:'input[type=file]'}); await call('DOM.setFileInputFiles',{nodeId:field.nodeId,files:[pdf]});
      }
      await inspect('quote-filled'); await submit('form'); await inspect(historical ? 'historical-quote' : 'current-quote');
      return await evaluate(`[...document.querySelectorAll('a[href$="/revision"]')].at(-1).pathname.split('/').at(-2)`);
    };
    const act = async (id, action) => {
      const selector = `form[action$="/estimates/${id}/${action}"]`;
      await evaluate(`document.querySelector(${JSON.stringify(selector)}).closest('details').open=true`);
      await fill(selector,{confirmed:true,reason:'Synthetic browser quote decision'}); await inspect('decision-'+action); await submit(selector);
    };
    const historical = await createQuote({historical:true}); assert(await evaluate('document.body.innerText.includes("source details incomplete")'), await evaluate('document.body.innerText'));
    await act(historical,'reject');
    const first = await createQuote({vendor:'Synthetic vendor '.repeat(10)}); await act(first,'accept');
    const revised = await createQuote({revision:first}); assert(await evaluate('document.body.innerText.includes("Accepted estimate")')); await act(revised,'accept');
    assert(await evaluate('document.body.innerText.includes("Superseded")'));
    const competing = await createQuote({vendor:'Synthetic competing vendor'});
    const stale = await evaluate(`Object.fromEntries(new FormData(document.querySelector('form[action$="/estimates/${competing}/accept"]')))`);
    await act(competing,'accept'); await inspect('competing-selection');
    const replay = await evaluate(`(async()=>{const csrf=[...document.querySelector('form').elements].find(f=>f.name.startsWith('csrf'));const data=new URLSearchParams(${JSON.stringify(stale)});data.set('confirmed','1');data.set('reason','Synthetic browser quote decision');data.set(csrf.name,csrf.value);const r=await fetch(location.pathname+'/estimates/${competing}/accept',{method:'POST',body:data});return (await r.text()).includes('Already saved.');})()`); assert(replay,'B2.2 replay feedback');
    await navigate(jobPath);
    const staleRejected = await evaluate(`(async()=>{const csrf=[...document.querySelector('form').elements].find(f=>f.name.startsWith('csrf'));const data=new URLSearchParams(${JSON.stringify(stale)});data.set('command_key',crypto.randomUUID());data.set('confirmed','1');data.set('reason','Synthetic stale decision');data.set(csrf.name,csrf.value);const r=await fetch(location.pathname+'/estimates/${competing}/accept',{method:'POST',body:data});return (await r.text()).includes('Work changed since this form was opened.');})()`); assert(staleRejected,'B2.2 stale feedback'); await navigate(jobPath);
    await act(competing,'withdraw');
    const archiveSelector = await evaluate(`document.querySelector('form[action$="/archive"]').getAttribute('action')`);
    await evaluate(`document.querySelector('form[action$="/archive"]').closest('details').open=true`); await fill('form[action$="/archive"]',{confirmed:true,reason:'Synthetic source archival'}); await submit('form[action$="/archive"]'); await inspect('archived-source');
    const download = await evaluate(`(async()=>{const a=document.querySelector('a[href$="/download"]');const r=await fetch(a.href);return {status:r.status,mime:r.headers.get('content-type'),disposition:r.headers.get('content-disposition'),cache:r.headers.get('cache-control'),nosniff:r.headers.get('x-content-type-options'),pdf:(await r.text()).startsWith('%PDF')};})()`);
    assert.equal(download.status,200); assert(download.mime.startsWith('application/pdf')); assert(download.disposition.startsWith('attachment;')); assert(download.cache.includes('no-store')&&download.cache.includes('private')); assert.equal(download.nosniff,'nosniff'); assert(download.pdf);
    await navigate('/synthetic-checklist'); await inspect('checklist'); assert(!await evaluate('document.body.innerText.includes("1234.50")')); assert(!await evaluate(`!!document.querySelector('form[action*="/estimates"]')`));
  }
  const csrfRejected = await evaluate(`(async()=>{const response=await fetch('/fleet/vehicles/10/damage-repairs',{method:'POST',body:new URLSearchParams({summary:'Synthetic missing token'})});return (await response.text()).includes('SecurityException');})()`);
  assert(csrfRejected, 'Missing CSRF is rejected');
  await writeFile(resolve(directory, 'results.json'), JSON.stringify({ checks: results, keyboard: true, replay: true, stale: true, csrf: true }, null, 2));
  console.log(JSON.stringify({ artifactDirectory: directory, browserChecks: results.length, widths: [390,1280,1920], keyboard: true, replay: true, stale: true, csrf: true }));
  await call('Browser.close');
} finally {
  socket?.close(); if (browser.exitCode === null) browser.kill(); if (server.exitCode === null) server.kill();
  proxy.closeAllConnections(); proxy.close();
}
