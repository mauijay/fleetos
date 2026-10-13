import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';
import { createServer } from 'node:http';

// Runs only the synthetic PHP router and a fresh local Chrome profile.
const directory = resolve('build/slice6/browser-' + Date.now());
await mkdir(directory, { recursive: true });
const php = process.env.B21_BROWSER_PHP || 'C:/xampp/php/php.exe';
const ini = resolve(process.env.B21_BROWSER_INI || 'build/php-b1.ini');
const chrome = process.env.B21_BROWSER_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const port = 33681;
const upstreamPort = 33680;
const base = `http://127.0.0.1:${port}`;
const server = spawn(php, ['-c', ini, '-S', `127.0.0.1:${upstreamPort}`, '-t', 'public', 'tests/_support/VehicleWheelRepairBrowserRouter.php'], {
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
    await evaluate(`setTimeout(()=>document.querySelector(${JSON.stringify(selector)}).requestSubmit(),0)`);
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
  const state = async () => (await fetch(base + '/synthetic-wheel-state', { headers: { Cookie: 'slice6_width=' + width } })).json();
  const pureNavigate = async path => { const before = await state(); await navigate(path); assert.equal((await state()).hash, before.hash, 'GET purity: ' + path); };
  for (width of [390, 1280, 1920]) {
    await call('Network.setCookie', {name:'slice6_width', value:String(width), url:base});
    await call('Emulation.setDeviceMetricsOverride', { width, height:1100, deviceScaleFactor:1, mobile:width===390 });
    await pureNavigate('/fleet/vehicles/10/damage-repairs/new'); await inspect('wheel-selection');
    let text = await evaluate('document.body.innerText');
    for (const wheel of ['LF','RF','RR','LR']) assert(text.includes(wheel + ' Wheel'), text);
    await evaluate("document.querySelector('[name=summary]').focus()");
    await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
    await call('Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
    assert.equal(await evaluate('document.activeElement.name'),'intent_code');
    const selected = await evaluate(`Object.fromEntries([...document.querySelectorAll('input[name$="[canonical_item_id]"]')].filter(e=>[1,2,3].includes(Number(e.value))).map(e=>[e.name.replace('canonical_item_id','canonical_confirmed'),true]))`);
    await fill('[data-work-form]',{summary:'Synthetic batch wheel shop',intent_code:'repair',category_code:'wheel_tire',...selected});
    await submit('[data-work-form]'); await inspect('batch-job');
    const jobPath = await evaluate('location.pathname');
    assert.equal((await state()).members.length,3);
    await evaluate(`document.querySelector('form[action$="/start"]').closest('details').open=true`);
    await fill('form[action$="/start"]',{started_at:'2026-10-01T09:00'}); await submit('form[action$="/start"]');
    for (const member of (await state()).members.filter(row=>[1,2].includes(Number(row.vehicle_damage_item_id)))) {
      await pureNavigate(jobPath + '/conditions/' + member.id + '/confirm-repair'); await inspect('confirm-' + member.id);
      await fill('form',{inspected_at:'2026-10-01T10:00',inspection_note:'Synthetic inspected exact wheel',confirmed:true}); await submit('form');
    }
    await evaluate(`document.querySelector('form[action$="/complete"]').closest('details').open=true`);
    const outcomes = Object.fromEntries((await state()).members.flatMap((m,i)=>[[`outcomes[${i}][result_code]`,[1,2].includes(Number(m.vehicle_damage_item_id))?'repaired':'partially_repaired'],[`outcomes[${i}][note]`,'Synthetic independently assessed outcome']]));
    await fill('form[action$="/complete"]',{...outcomes,completed_at:'2026-10-01T11:00',completion_note:'Synthetic partial visit'});
    await submit('form[action$="/complete"]'); await inspect('partial-repair');
    let current = await state(); assert.deepEqual(current.conditions.slice(0,3).map(row=>row.status_code),['repaired','repaired','open']);
    await pureNavigate('/fleet/vehicles/10/wheel-history/LF?episode=1'); await inspect('repaired-episode-history');
    await pureNavigate(jobPath);
    const later = await evaluate(`(() => {const f=document.createElement('form');f.method='post';f.action='/synthetic-later-episode';const csrf=[...document.querySelector('form').elements].find(e=>e.name.startsWith('csrf'));f.append(csrf.cloneNode());document.body.append(f);setTimeout(()=>f.requestSubmit(),0);return true;})()`); assert(later);
    await delay(500); await navigate(jobPath); await inspect('historical-job-later-episode');
    text=await evaluate('document.body.innerText'); assert(text.includes('This job still targets its original episode'),text);
    current=await state(); assert.deepEqual(current.members.map(row=>Number(row.vehicle_damage_item_id)).sort(),[1,2,3]);
    await pureNavigate('/fleet/vehicles/10/damage/1/reopen'); await inspect('occupied-reopen');
    assert(!await evaluate('!!document.querySelector("form")'),'Occupied slot has no reopen form');
    assert(await evaluate('document.body.innerText.includes("Reopening requires review")'));
    await pureNavigate('/fleet/vehicles/10/wheel-history/LF'); await inspect('later-active-history');
    await pureNavigate('/fleet/vehicles/10/damage-repairs/new'); await inspect('conflict-selection');
    assert(await evaluate('document.body.innerText.includes("Review conflicting records")'));
    assert.equal(await evaluate('[...document.querySelectorAll("input[type=checkbox]:disabled")].length'),2);
    await evaluate(`document.querySelector('.wheel-repair-context a').focus()`);
    for (const modifiers of [0,8]) {
      await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9,modifiers});
      await call('Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9,modifiers});
    }
    const link=await evaluate(`(() => {const a=document.activeElement;return {text:a.textContent,outline:getComputedStyle(a).outlineStyle,height:a.getBoundingClientRect().height};})()`);
    assert(link.text.includes('episode')); assert.notEqual(link.outline,'none','Visible keyboard focus');
    assert(link.height>=44,'History link touch target');
  }
  await writeFile(resolve(directory, 'results.json'), JSON.stringify({ checks: results, keyboard: true, getPurity: true, exactEpisodes: true, partialRepair: true }, null, 2));
  console.log(JSON.stringify({ artifactDirectory: directory, browserChecks: results.length, widths: [390,1280,1920], keyboard: true, getPurity: true, exactEpisodes: true, partialRepair: true }));
  await call('Browser.close');
} finally {
  socket?.close(); if (browser.exitCode === null) browser.kill(); if (server.exitCode === null) server.kill();
  proxy.closeAllConnections(); proxy.close();
}
