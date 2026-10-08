import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';
import { createServer, request as httpRequest } from 'node:http';

// Runs only the synthetic PHP router and a fresh local Chrome profile.
const directory = resolve('build/b31-local/browser-' + Date.now());
await mkdir(directory, { recursive: true });
const php = process.env.B21_BROWSER_PHP || 'C:/xampp/php/php.exe';
const ini = resolve(process.env.B21_BROWSER_INI || 'build/php-validation.ini');
const chrome = process.env.B21_BROWSER_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const port = 34481;
const upstreamPort = 34480;
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
    if(label==='both-finalized-signed-excess'){
      await evaluate('document.querySelector("#repair-recoveries").scrollIntoView()');
      const focus=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
      await writeFile(resolve(directory,`${width}-economic-viewport.png`),Buffer.from(focus.data,'base64'));
    }
  };
  await call('Page.enable'); await call('Network.enable'); await call('Page.setLifecycleEventsEnabled', { enabled: true });
  await call('Log.enable');
  await call('Network.setCacheDisabled', { cacheDisabled: true });
  for (width of [390, 1280, 1920]) {
    await call('Emulation.setDeviceMetricsOverride', { width, height: 1100, deviceScaleFactor: 1, mobile: width === 390 });
    const createJob = async label => {
      await navigate('/fleet/vehicles/10/damage-repairs/new');
      await fill('[data-work-form]', { summary: `Synthetic recovery ${label} ${width}`, intent_code: 'repair', category_code: 'body', 'conditions[0][canonical_confirmed]': true, 'conditions[1][canonical_confirmed]': true });
      await submit('[data-work-form]');
      return await evaluate('location.pathname');
    };
    let jobPath = await createJob('empty');
    await inspect('no-recovery');
    assert(await evaluate('document.querySelector("#repair-recoveries").innerText.includes("No recovery recorded")'));
    const decision = async (path, values) => {
      const selector = `form[action$="${path}"]`;
      await evaluate(`document.querySelector(${JSON.stringify(selector)}).closest('details').open=true`);
      await fill(selector, values); await submit(selector);
    };
    const finalize = async empty => {
      const values = { confirmed: true, completeness_confirmed: true, scope_review_confirmed: true, note: 'Synthetic recovery completeness note '.repeat(35) };
      if (empty) values.no_recovery_confirmed = true;
      if (await evaluate(`!!document.querySelector('form[action$="/recoveries/finalize"] [name=duplicate_review_confirmed]')`)) {
        values.duplicate_review_confirmed = true; values.duplicate_review_reason = 'Synthetic independently evidenced receipts';
      }
      await decision('/recoveries/finalize', values);
    };
    await finalize(true); await inspect('finalized-no-recovery');
    assert(await evaluate('document.querySelector("#repair-recoveries").innerText.includes("Finalized")'));
    assert.equal(await evaluate('document.querySelector("#repair-recoveries .work-facts > div:last-child dd").innerText'), 'Unknown');
    jobPath = await createJob('receipts');
    const namespace = `bank_transfer:synthetic-browser-${width}`;
    const reference = 'SYNTHETIC-'+width+'-FIRST-'+'R'.repeat(85);
    const payer = 'Synthetic Browser Payer '+width;
    const record = async (kind, amount, parent = '', replacement = null) => {
      await navigate(jobPath + (replacement ? `/recoveries/${replacement}/replacement` : '/recoveries/new'));
      await inspect(replacement ? 'replacement-form' : 'recovery-form');
      await evaluate('document.querySelector("[name=amount]").focus()');
      await call('Input.dispatchKeyEvent', {type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
      await call('Input.dispatchKeyEvent', {type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
      assert.equal(await evaluate('document.activeElement.name'),'currency');
      await fill('[data-recovery-form]', {kind_code:kind, source_type:'guest_direct', amount, currency:'USD', occurred_on:'2026-10-06', payer_snapshot:payer, source_namespace:namespace, source_reference: kind==='recovery' ? reference : 'SYNTHETIC-RETURN-'+width, related_recovery_entry_id:String(parent), source_details:'Synthetic actual bank receipt wholly for one repair job.', note:'Synthetic long recovery note '.repeat(65), confirmed:true, received_confirmed:true, returned_confirmed:true, whole_job_confirmed:true, outside_turo_confirmed:true, not_cost_reduction_confirmed:true, not_duplicate_confirmed:true, ...(replacement ? {reason:'Synthetic evidence correction'} : {}) });
      await evaluate('document.querySelector("[name=repair_document_id]").value=""');
      const pdf=resolve(directory,`synthetic-recovery-${width}-${Date.now()}.pdf`);
      await writeFile(pdf,'%PDF-1.4\n% Synthetic recovery browser '+width+' '+Date.now()+'\n%%EOF\n');
      const upload=async()=>{const root=await call('DOM.getDocument'); const field=await call('DOM.querySelector',{nodeId:root.root.nodeId,selector:'input[type=file]'});await call('DOM.setFileInputFiles',{nodeId:field.nodeId,files:[pdf]});};
      await upload(); await inspect('filled-'+kind+(replacement?'-replacement':''));
      pageLoaded=false; await evaluate('document.querySelector("button[formaction]").click()');
      let reviewed=false;
      for(let n=0;n<150;n++){await delay(100);try{reviewed=pageLoaded && await evaluate('document.readyState==="complete" && location.pathname.endsWith("/recoveries/review") && !!document.querySelector("[name=duplicate_review_fingerprint]")');}catch{}if(reviewed)break;}
      assert(reviewed,'Read-only duplicate review must render');
      await inspect('duplicate-review-'+kind+(replacement?'-replacement':''));
      await fill('[data-recovery-form]',{confirmed:true,duplicate_review_confirmed:true,duplicate_review_reason:'Synthetic receipt evidence reviewed as separate actual fact'});
      await upload();
      const lostAck=width===390&&kind==='recovery'&&!replacement;
      if(lostAck) await evaluate(`const fault=document.createElement('input');fault.type='hidden';fault.name='synthetic_lost_ack';fault.value='1';document.querySelector('[data-recovery-form]').append(fault);`);
      await submit('[data-recovery-form]');
      if(lostAck){
        assert(await evaluate('document.body.innerText.includes("Retry original recovery command")'));
        await inspect('uncertain-recovery-outcome');await navigate(jobPath);
        assert(await evaluate('document.body.innerText.includes("Retry original recovery command")'));
        await inspect('uncertain-recovery-reload');pageLoaded=false;
        await evaluate('document.querySelector("#repair-recoveries button").click()');
        let recovered=false;
        for(let n=0;n<150;n++){await delay(100);try{recovered=pageLoaded&&await evaluate('document.body.innerText.includes("Already saved.") && !document.body.innerText.includes("Retry original recovery command")');}catch{}if(recovered)break;}
        assert(recovered,'Reload must retain and recover original B3 command identity');await inspect('uncertain-recovery-recovered');
      }
      assert.equal(await evaluate('location.pathname'),jobPath,await evaluate('document.body.innerText'));
      return await evaluate('[...document.querySelectorAll("#repair-recoveries a[href$=replacement]")].map(a=>Number(a.pathname.split("/").at(-2))).sort((a,b)=>a-b).at(-1)');
    };
    const receipt=await record('recovery','100.00'); await inspect('recovery-before-cost');
    assert(await evaluate('document.querySelector("#repair-recoveries").innerText.includes("USD 100.00")'));
    assert.equal(await evaluate('document.querySelector("#repair-recoveries .work-facts > div:last-child dd").innerText'), 'Unknown');
    await record('recovery_reversal','20.00',receipt); await inspect('genuine-reversal');
    assert(await evaluate('document.querySelector("#repair-recoveries").innerText.includes("USD 80.00")'));
    await record('recovery','90.00','',receipt); await inspect('same-source-correction');
    assert(await evaluate('document.querySelector("#repair-recoveries").innerText.includes("USD 70.00")'));
    assert(await evaluate('document.querySelector("#repair-recoveries").innerText.includes("Correction replaces recovery")'));
    await finalize(false); await inspect('finalized-positive-recovery');
    assert(await evaluate('document.querySelector("#repair-recoveries").innerText.includes("Finalized")'));
    const download=await evaluate(`(async()=>{const r=await fetch(document.querySelector('#repair-recoveries a[href$="/download"]').href);return{status:r.status,pdf:(await r.text()).startsWith('%PDF'),cache:r.headers.get('cache-control')};})()`);
    assert.equal(download.status,200);assert(download.pdf&&download.cache.includes('no-store'));
    await navigate(jobPath+'/costs/new');
    await fill('form',{kind_code:'invoice',amount:'50.00',currency:'USD',occurred_on:'2026-10-06',vendor_snapshot:'Synthetic Recovery Browser Shop',vendor_reference:'SYNTHETIC-INVOICE-'+width,performed_work_confirmed:true,confirmed:true});
    const costPdf=resolve(directory,`synthetic-invoice-${width}.pdf`);await writeFile(costPdf,'%PDF-1.4\n% Synthetic invoice '+width+'\n%%EOF\n');
    const costUpload=async()=>{const root=await call('DOM.getDocument');const field=await call('DOM.querySelector',{nodeId:root.root.nodeId,selector:'input[type=file]'});await call('DOM.setFileInputFiles',{nodeId:field.nodeId,files:[costPdf]});};
    await costUpload();pageLoaded=false;await evaluate('document.querySelector("button[formaction]").click()');
    let costReviewed=false;
    for(let n=0;n<150;n++){await delay(100);try{costReviewed=pageLoaded&&await evaluate('document.readyState==="complete" && location.pathname.endsWith("/costs/review")');}catch{}if(costReviewed)break;}
    assert(costReviewed,'Cost duplicate review renders');
    await fill('form',{confirmed:true,duplicate_review_confirmed:true,duplicate_review_reason:'Synthetic separate invoice reviewed'});
    await costUpload();await submit('form');
    await decision('/start',{started_at:'2026-10-01T09:00'});await decision('/cancel',{reason:'Synthetic performed work ended'});
    await decision('/costs/finalize',{confirmed:true,note:'Synthetic cost completeness'});
    await inspect('both-finalized-signed-excess');
    assert(await evaluate('document.querySelector("#repair-recoveries").innerText.includes("Final host-borne repair balance: USD -20.00")'));
    assert(await evaluate('document.querySelector("#repair-recoveries").innerText.includes("Recovery exceeds recorded repair cost by USD 20.00")'));
    await decision('/recoveries/invalidate',{confirmed:true,reason:'Synthetic standalone recovery review'});await inspect('explicit-invalidation');
    assert(!await evaluate('document.querySelector("#repair-recoveries").innerText.includes("Final host-borne repair balance")'));
    assert(await evaluate('document.querySelector("#repair-costs").innerText.includes("Final recorded invoiced work cost")'));
    await finalize(false);await inspect('refinalized-economics');
  }
  await writeFile(resolve(directory, 'results.json'), JSON.stringify({ checks: results, keyboard: true, privateEvidence: true, syntheticOnly: true }, null, 2));
  console.log(JSON.stringify({ artifactDirectory: directory, browserChecks: results.length, widths: [390,1280,1920], keyboard: true, privateEvidence: true }));
  await call('Browser.close');
} finally {
  await writeFile(resolve(directory, 'php-server.log'), serverErrors);
  socket?.close(); if (browser.exitCode === null) browser.kill(); if (server.exitCode === null) server.kill();
  proxy.closeAllConnections(); proxy.close();
}
