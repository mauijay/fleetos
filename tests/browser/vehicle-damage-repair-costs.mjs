import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';
import { createServer, request as httpRequest } from 'node:http';

// Runs only the synthetic PHP router and a fresh local Chrome profile.
const directory = resolve('build/b23-review/browser-' + Date.now());
await mkdir(directory, { recursive: true });
const php = process.env.B21_BROWSER_PHP || 'C:/xampp/php/php.exe';
const ini = resolve(process.env.B21_BROWSER_INI || 'build/php-b1.ini');
const chrome = process.env.B21_BROWSER_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const port = 34471;
const upstreamPort = 34470;
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
    assert(valid, 'Form must be valid: ' + selector);
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

    await navigate(jobPath); await inspect('unknown-cost');
    assert(await evaluate('document.body.innerText.includes("Repair cost: Unknown")'));
    const recordFact = async (kind, amount, parent = '', {zero = false, replacement = null} = {}) => {
      await navigate(jobPath + (replacement ? `/costs/${replacement}/replacement` : '/costs/new'));
      assert.equal(await evaluate('document.querySelector("[name=amount]").value'), '');
      assert.equal(await evaluate('document.querySelector("[name=occurred_on]").value'), '');
      await evaluate('document.querySelector("[name=amount]").focus()');
      await call('Input.dispatchKeyEvent', {type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
      await call('Input.dispatchKeyEvent', {type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
      assert.equal(await evaluate('document.activeElement.name'),'currency');
      await fill('form',{kind_code:kind,amount,currency:'USD',occurred_on:'2026-10-06',vendor_snapshot:'Synthetic Cost Browser Vendor',vendor_reference:'Synthetic cost reference '.repeat(4),note:'Synthetic cost note '.repeat(60),related_cost_entry_id:String(parent),performed_work_confirmed:kind==='invoice',verified_zero_confirmed:zero,confirmed:true,...(replacement?{reason:'Synthetic browser correction'}:{})});
      const pdf = resolve(directory, `synthetic-cost-${width}-${Date.now()}.pdf`);
      await writeFile(pdf,'%PDF-1.4\n% Synthetic cost browser '+width+' '+Date.now()+'\n%%EOF\n');
      const upload = async () => { const root=await call('DOM.getDocument'); const field=await call('DOM.querySelector',{nodeId:root.root.nodeId,selector:'input[type=file]'}); await call('DOM.setFileInputFiles',{nodeId:field.nodeId,files:[pdf]}); };
      await upload(); await inspect('cost-form-'+kind);
      await evaluate('document.querySelector("button[formaction]").click()');
      for(let n=0;n<150;n++){ await delay(100); try{if(await evaluate('location.pathname.endsWith("/costs/review") && document.body.innerText.includes("Duplicate review")'))break;}catch{} if(n===149)throw new Error('Duplicate review did not render'); }
      assert(!await evaluate('document.body.innerText.includes("Work was not saved.")'),await evaluate('document.body.innerText'));
      await inspect('duplicate-review-'+kind);
      await fill('form',{confirmed:true,duplicate_review_confirmed:true,duplicate_review_reason:'Synthetic explicit separate fact review'});
      await upload();
      const exerciseLostAck = kind === 'payment' && !parent && !replacement && amount === '25.00';
      if (exerciseLostAck) await evaluate(`const fault=document.createElement('input');fault.type='hidden';fault.name='synthetic_lost_ack';fault.value='1';document.querySelector('form').append(fault);`);
      const original = await evaluate(`(() => {const data=new FormData(document.querySelector('form'));data.delete('repair_document');return Object.fromEntries(data);})()`);
      await submit('form'); await inspect('recorded-'+kind);
      if (exerciseLostAck) {
        assert(await evaluate('document.body.innerText.includes("Retry original cost command")'));
        await inspect('uncertain-cost-outcome');
        await navigate(jobPath);
        assert(await evaluate('document.body.innerText.includes("Retry original cost command")'));
        assert(!await evaluate('document.querySelector("#repair-costs").innerText.includes("Record invoice / credit / payment / refund")'));
        await inspect('uncertain-cost-reload');
        pageLoaded = false;
        await evaluate('document.querySelector("#repair-costs button").click()');
        let recovered = false;
        for (let n=0;n<150;n++) {
          await delay(100);
          try { recovered = pageLoaded && await evaluate('document.body.innerText.includes("Already saved.") && !document.body.innerText.includes("Retry original cost command")'); } catch {}
          if (recovered) break;
        }
        assert(recovered, 'The unresolved actual COMMIT must recover its original receipt after reload.');
        await inspect('uncertain-cost-recovered');
      }
      if (kind === 'payment' && !parent && !replacement) {
        delete original.synthetic_lost_ack;
        const replay = await evaluate(`(async()=>{const csrf=[...document.querySelector('form').elements].find(f=>f.name.startsWith('csrf'));const data=new URLSearchParams(${JSON.stringify(original)});data.set(csrf.name,csrf.value);const r=await fetch(${JSON.stringify(jobPath)}+'/costs',{method:'POST',body:data});const text=await r.text();return{ok:text.includes('Already saved.'),status:r.status};})()`);
        assert(replay.ok,'B2.3 frozen-descriptor replay: '+JSON.stringify(replay));
        await navigate(jobPath); await inspect('cost-same-key-replay');
        if (exerciseLostAck) {
          const stale = await evaluate(`(async()=>{const csrf=[...document.querySelector('form').elements].find(f=>f.name.startsWith('csrf'));const data=new URLSearchParams(${JSON.stringify(original)});data.set('command_key',crypto.randomUUID());data.set(csrf.name,csrf.value);const r=await fetch(${JSON.stringify(jobPath)}+'/costs',{method:'POST',body:data});return(await r.text()).includes('Work changed since this form was opened');})()`);
          assert(stale, 'A distinct stale cost command must fail without changing history.');
          await navigate(jobPath); await inspect('stale-cost-rejected');
        }
      }
      return await evaluate('[...document.querySelectorAll("#repair-costs a[href$=replacement]")].map(a=>Number(a.pathname.split("/").at(-2))).sort((a,b)=>a-b).at(-1)');
    };
    const unpaidInvoice = await recordFact('invoice','1.00'); await inspect('invoice-without-payment');
    assert(await evaluate('document.querySelector("#repair-costs").innerText.includes("No vendor payments recorded")'));
    const unpaidVoid = `form[action$="/costs/${unpaidInvoice}/void"]`;
    await evaluate(`document.querySelector(${JSON.stringify(unpaidVoid)}).closest('details').open=true`);
    await fill(unpaidVoid,{confirmed:true,reason:'Synthetic unpaid source correction'}); await submit(unpaidVoid);
    const deposit = await recordFact('payment','25.00'); await inspect('deposit-without-invoice');
    assert(await evaluate('document.body.innerText.includes("Repair cost: Unknown")'));
    await recordFact('payment_refund','5.00',deposit); await inspect('vendor-refund');
    await recordFact('invoice','0.00','',{zero:true}); await inspect('verified-zero-invoice');
    assert(await evaluate('document.body.innerText.includes("invoiced work cost so far: USD 0.00")'));
    const charged = await recordFact('invoice','100.00'); await inspect('invoice-partial-payment');
    const partial = await recordFact('invoice','30.00'); await inspect('partial-invoices');
    await recordFact('invoice_credit','20.00',charged); await inspect('genuine-credit');
    const corrected = await recordFact('invoice','40.00','',{replacement:partial}); await inspect('atomic-replacement');
    const voidSelector=`form[action$="/costs/${corrected}/void"]`;
    await evaluate(`document.querySelector(${JSON.stringify(voidSelector)}).closest('details').open=true`);
    await fill(voidSelector,{confirmed:true,reason:'Synthetic browser void correction'}); await submit(voidSelector); await inspect('void-history');
    await recordFact('invoice','100.00'); await inspect('reviewed-duplicate-invoice');
    const startSelector='form[action$="/start"]';
    await evaluate(`document.querySelector(${JSON.stringify(startSelector)}).closest('details').open=true`);
    await fill(startSelector,{started_at:'2026-10-01T09:00'}); await submit(startSelector);
    const cancelSelector='form[action$="/cancel"]';
    await evaluate(`document.querySelector(${JSON.stringify(cancelSelector)}).closest('details').open=true`);
    await fill(cancelSelector,{reason:'Synthetic performed work ended'}); await submit(cancelSelector);
    const finalize = async()=>{const selector='form[action$="/costs/finalize"]';await evaluate(`document.querySelector(${JSON.stringify(selector)}).closest('details').open=true`);await fill(selector,{confirmed:true,note:'Synthetic completeness confirmation'});await submit(selector);};
    await finalize(); await inspect('finalized-invoiced-cost');
    await recordFact('payment','15.00'); assert(await evaluate('document.body.innerText.includes("Final recorded invoiced work cost")')); await inspect('payment-preserves-finalization');
    const invalidSelector='form[action$="/costs/invalidate"]';
    await evaluate(`document.querySelector(${JSON.stringify(invalidSelector)}).closest('details').open=true`);
    await fill(invalidSelector,{confirmed:true,reason:'Synthetic explicit completeness review'}); await submit(invalidSelector); await inspect('explicit-invalidation');
    await finalize(); await recordFact('invoice_credit','10.00',charged); await inspect('credit-invalidates-finalization');
    assert(!await evaluate('document.querySelector("#repair-costs").innerText.includes("Final recorded invoiced work cost")'));
    await finalize(); await navigate(jobPath); await inspect('finalized-before-reopen');
    const reopenSelector='form[action$="/reopen"]';
    await evaluate(`document.querySelector(${JSON.stringify(reopenSelector)}).closest('details').open=true`);
    await fill(reopenSelector,{reason_category_code:'continuing_order',reason:'Synthetic continuing effort',same_work_order_confirmed:true}); await submit(reopenSelector); await inspect('reopen-invalidates-finalization');
    const costDownload=await evaluate(`(async()=>{const a=document.querySelector('#repair-costs a[href$="/download"]');const r=await fetch(a.href);return{status:r.status,pdf:(await r.text()).startsWith('%PDF'),cache:r.headers.get('cache-control')};})()`);
    assert.equal(costDownload.status,200); assert(costDownload.pdf&&costDownload.cache.includes('no-store'));
    await navigate('/synthetic-checklist'); await inspect('checklist'); assert(!await evaluate('document.body.innerText.includes("1234.50")')); assert(!await evaluate(`!!document.querySelector('form[action*="/estimates"]')`));
  }
  const csrfRejected = await evaluate(`(async()=>{const response=await fetch('/fleet/vehicles/10/damage-repairs',{method:'POST',body:new URLSearchParams({summary:'Synthetic missing token'})});return (await response.text()).includes('SecurityException');})()`);
  assert(csrfRejected, 'Missing CSRF is rejected');
  await writeFile(resolve(directory, 'results.json'), JSON.stringify({ checks: results, keyboard: true, replay: true, csrf: true }, null, 2));
  console.log(JSON.stringify({ artifactDirectory: directory, browserChecks: results.length, widths: [390,1280,1920], keyboard: true, replay: true, csrf: true }));
  await call('Browser.close');
} finally {
  await writeFile(resolve(directory, 'php-server.log'), serverErrors);
  socket?.close(); if (browser.exitCode === null) browser.kill(); if (server.exitCode === null) server.kill();
  proxy.closeAllConnections(); proxy.close();
}
