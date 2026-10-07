import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';
import { createServer, request as httpRequest } from 'node:http';

// Isolate each viewport's server, session, database and Chrome profile.
if (!process.env.HNL_BROWSER_WIDTH) {
  for (const width of [390, 1280, 1920]) {
    const child = spawn(process.execPath, [resolve(process.argv[1])], { windowsHide:true, stdio:'inherit', env:{...process.env,HNL_BROWSER_WIDTH:String(width)} });
    const code = await new Promise((resolve, reject) => { child.on('error', reject); child.on('exit', resolve); });
    assert.equal(code, 0, 'Synthetic HNL viewport ' + width);
  }
  console.log(JSON.stringify({browserChecks:9,widths:[390,1280,1920],keyboard:true,validationFocus:true,prg:true,replay:true,csrf:true,ev:true,ice:true}));
  process.exit(0);
}
assert(['390','1280','1920'].includes(process.env.HNL_BROWSER_WIDTH));

// Runs only the synthetic PHP router and a fresh local Chrome profile.
const directory = resolve('build/v0302-local/browser-' + Date.now());
await mkdir(directory, { recursive: true });
const php = process.env.HNL_BROWSER_PHP || 'C:/xampp/php/php.exe';
const ini = resolve(process.env.HNL_BROWSER_INI || 'build/php-validation.ini');
const chrome = process.env.HNL_BROWSER_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const port = 35471;
const upstreamPort = 35470;
const base = `http://127.0.0.1:${port}`;
const server = spawn(php, ['-c', ini, '-S', `127.0.0.1:${upstreamPort}`, '-t', 'public', 'tests/_support/HnlStagingChecklistBrowserRouter.php'], {
  windowsHide: true, env: { ...process.env, PHPRC: ini, XDEBUG_MODE: 'off', HNL_BROWSER_DIR: directory, HNL_BROWSER_BASEURL: base + '/' }, stdio: ['ignore', 'ignore', 'pipe'],
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
  const fill = async (selector, values) => {
    const valid = await evaluate(`(() => { const form = document.querySelector(${JSON.stringify(selector)}); for (const [name,value] of Object.entries(${JSON.stringify(values)})) { const field = [...form.elements].find(field => field.name === name); if (!field) throw new Error('Missing field '+name); if (field.type === 'checkbox') field.checked = Boolean(value); else field.value = value; field.dispatchEvent(new Event('change',{bubbles:true})); } return form.checkValidity(); })()`);
    assert(valid, 'Form must be valid: ' + selector);
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
  const state = () => evaluate(`fetch('/synthetic-state').then(response=>response.json())`);
  const stable = value => JSON.parse(JSON.stringify(value, (key, item) => ['as_of', 'age_seconds'].includes(key) ? undefined : item));
  const waitFor = async expression => {
    for (let i = 0; i < 150; i++) {
      await delay(100);
      try { if (await evaluate(expression)) return; } catch { /* PRG replaces the context. */ }
    }
    throw new Error('Expected browser state: ' + expression + ': ' + await evaluate('document.body.innerText'));
  };
  for (width of [Number(process.env.HNL_BROWSER_WIDTH)]) {
    console.log('Synthetic HNL viewport ' + width);
    await call('Emulation.setDeviceMetricsOverride', { width, height: 1100, deviceScaleFactor: 1, mobile: width === 390 });
    await call('Network.setCookie', { name:'hnl_synthetic_fixture', value:String(width), url:base, path:'/' });
    if (width === 390) await call('Emulation.setScriptExecutionDisabled', { value:true });
    await navigate('/operations/checklists/102');
    const before = await state();
    const requirement = before.readiness['102'].requirements.find(r=>r.code==='airport_staging');
    assert.equal(before.custody.custody, 'operator'); assert.equal(before.position.location_class, 'home');
    assert.equal(requirement.status, 'unsatisfied'); assert(requirement.blocking && requirement.actionable);
    const target = await evaluate(`(() => { const link=document.querySelector('#checklist-action-airport_staging a'); const form=document.querySelector(link.hash); return {text:link.textContent,hash:link.hash,count:document.querySelectorAll(link.hash).length,action:new URL(form.action).pathname,method:form.method,hidden:form.hidden,stage:form.querySelector('button').textContent,guest:!!form.querySelector('button.secondary-action')}; })()`);
    assert.equal(target.text, 'Record facts'); assert.equal(target.hash, '#handoff-entry'); assert.equal(target.count, 1);
    assert.equal(target.action, '/operations/checklists/102/stage-at-hnl'); assert.equal(target.method, 'post');
    assert.equal(target.hidden, false); assert.equal(target.stage, 'Stage at HNL'); assert.equal(target.guest, false);
    await evaluate(`document.querySelector('#checklist-action-airport_staging a').click()`);
    assert.equal(await evaluate('location.hash'), '#handoff-entry'); await inspect('historical-home-staging-form');
    if (width === 390) {
      await call('Emulation.setScriptExecutionDisabled', { value:false });
      await navigate('/operations/checklists/102');
    }
    await evaluate(`document.querySelector('#handoff-entry textarea[name=note]').focus()`);
    await call('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    await call('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    assert.equal(await evaluate('document.activeElement.textContent'), 'Stage at HNL');
    const localTime = await evaluate(`new Date(Date.now()-60000).toLocaleString('sv-SE',{timeZone:'Pacific/Honolulu'}).replace(' ','T').slice(0,16)`);
    await fill('#handoff-entry', { occurred_on:localTime.slice(0,10), occurred_time:localTime.slice(11), occurred_at:localTime, location_class:'airport_hnl', airport_garage_code:'international', airport_parking_level:'7', airport_parking_row:'G', cleanliness:'clean', energy_percent:width===1280?'70':'85', note:'Synthetic HNL browser staging' });
    assert.equal(await evaluate(`document.querySelector('#handoff-entry [name=airport_parking_row]').disabled`), false);
    assert.equal(await evaluate(`document.querySelector('#handoff-entry [name=airport_parking_level]').disabled`), false);
    assert.equal(await evaluate(`!!document.querySelector('#handoff-entry [name*=stall]')`), false);
    // Missing observed cleanliness must return to an actionable form through real PRG.
    await fill('#handoff-entry', {cleanliness:''});
    await evaluate(`document.querySelector('#handoff-entry button.primary-action').click()`);
    await waitFor(`!!document.querySelector('.import-message.tone-danger')`);
    assert.equal(await evaluate('location.hash'), '#handoff-entry');
    await waitFor(`document.activeElement.id === 'handoff-entry'`);
    assert.equal((await state()).events.length, before.events.length);
    assert.equal(await evaluate(`document.querySelector('#handoff-entry [name=energy_percent]').value`), width===1280?'70':'85');
    await inspect('validation-prg-focus');
    assert.equal(await evaluate(`document.querySelector('#handoff-entry').tabIndex`), -1);
    await evaluate(`document.querySelector('#handoff-entry').focus()`); assert.equal(await evaluate('document.activeElement.id'), 'handoff-entry');
    await fill('#handoff-entry', { occurred_on:localTime.slice(0,10), occurred_time:localTime.slice(11), occurred_at:localTime, location_class:'airport_hnl', airport_garage_code:'international', airport_parking_level:'7', airport_parking_row:'G', cleanliness:'clean', energy_percent:width===1280?'70':'85', note:'Synthetic HNL browser staging' });
    const saved = await evaluate(`Object.fromEntries(new FormData(document.querySelector('#handoff-entry')))`);
    await evaluate(`document.querySelector('#handoff-entry button.primary-action').click()`);
    await waitFor(`document.body.innerText.includes('Vehicle staged at HNL. Guest pickup is not yet confirmed.')`);
    assert(!await evaluate(`!!document.querySelector('#checklist-action-airport_staging.is-pending')`));
    assert.equal(await evaluate(`new URL(document.querySelector('#handoff-entry').action).pathname`), '/operations/checklists/102/confirm-guest-pickup');
    await inspect('staged-prg-readiness');
    const after = await state(); const stage = after.events.at(-1);
    assert.equal(after.events.length,before.events.length+1); assert.equal(after.assessments.length,1);
    assert.equal(after.airport_audits,before.airport_audits+1); assert.equal(stage.event_code,'vehicle_staged');
    assert.equal(Number(stage.turo_trip_normalized_id),102); assert.equal(stage.airport_garage_code,'international');
    assert.equal(Number(stage.airport_parking_level),7); assert.equal(stage.airport_parking_row,'G');
    assert.equal(after.custody.custody,'operator'); assert.equal(after.position.location_class,'airport_hnl');
    assert.equal(Number(after.position.event_id),Number(stage.id)); assert.deepEqual(after.events.slice(0,3),before.events);
    assert.equal(after.readiness['102'].requirements.find(r=>r.code==='airport_staging').status,'satisfied');
    assert.equal(after.readiness['102'].requirements.find(r=>r.code==='guest_handoff').status,'unsatisfied');
    assert.deepEqual(stable(after.readiness['100']),stable(before.readiness['100'])); assert.deepEqual(stable(after.readiness['103']),stable(before.readiness['103']));
    assert(await evaluate(`[...document.querySelectorAll('.movement-fact-summary dt')].some(label=>label.textContent===${JSON.stringify(width===1280?'Fuel':'Charge')})`));
    await evaluate(`(() => { const data=${JSON.stringify(saved)};const token=document.querySelector('#handoff-entry input[type=hidden][name^=csrf]');data[token.name]=token.value;const form=document.createElement('form');form.method='post';form.action='/operations/checklists/102/stage-at-hnl';for(const [name,value] of Object.entries(data)){const field=document.createElement('input');field.type='hidden';field.name=name;field.value=value;form.append(field);}document.body.append(form);form.submit();})()`);
    await waitFor(`document.body.innerText.includes('This pickup is already staged or confirmed.')`);
    assert.deepEqual((await state()).events,after.events); assert.equal((await state()).airport_audits,after.airport_audits);
    await evaluate(`(() => {const form=document.createElement('form');form.method='post';form.action='/operations/checklists/102/stage-at-hnl';const field=document.createElement('input');field.name='location_class';field.value='airport_hnl';form.append(field);document.body.append(form);form.submit();})()`);
    await waitFor(`document.body.innerText.includes('SecurityException')`);
    assert.deepEqual((await state()).events,after.events);
  }
  await writeFile(resolve(directory,'results.json'),JSON.stringify({checks:results,widths:[width],keyboard:true,validationFocus:true,prg:true,replay:true,csrf:true,synthetic:true,energy:width===1280?'gasoline':'electric'},null,2));
  console.log(JSON.stringify({artifactDirectory:directory,browserChecks:results.length,widths:[width],keyboard:true,validationFocus:true,prg:true,replay:true,csrf:true,energy:width===1280?'gasoline':'electric'}));
  await call('Browser.close');
} finally {
  await writeFile(resolve(directory, 'server.log'), serverErrors);
  socket?.close(); if (browser.exitCode === null) browser.kill(); if (server.exitCode === null) server.kill();
  proxy.closeAllConnections(); proxy.close();
}
