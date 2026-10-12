import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';
import { createServer } from 'node:http';

// Runs only the synthetic PHP router and a fresh local Chrome profile.
const directory = resolve('build/slice3/browser-' + Date.now());
await mkdir(directory, { recursive: true });
const php = process.env.B21_BROWSER_PHP || 'C:/xampp/php/php.exe';
const ini = resolve(process.env.B21_BROWSER_INI || 'build/php-b1.ini');
const chrome = process.env.B21_BROWSER_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const port = 33481;
const upstreamPort = 33480;
const base = `http://127.0.0.1:${port}`;
const server = spawn(php, ['-c', ini, '-S', `127.0.0.1:${upstreamPort}`, '-t', 'public', 'tests/_support/VehicleWheelDamageBrowserRouter.php'], {
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
    try { const response = await fetch(base + '/fleet/vehicles/10/wheel-damage/new'); if (response.ok) break; if (i === 99) throw new Error(await response.text()); }
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
  const submit = async (selector, button = 'button[type=submit]') => {
    await evaluate(`globalThis.wheelSubmitting=true; document.querySelector(${JSON.stringify(selector)}).requestSubmit(document.querySelector(${JSON.stringify(selector)}).querySelector(${JSON.stringify(button)}))`);
    await delay(300);
    for (let i = 0; i < 150; i++) {
      try { if (await evaluate(`globalThis.wheelSubmitting !== true && document.readyState === 'complete' && (document.querySelector('h1')?.textContent.includes('evidence') || document.querySelector('[role=alert]'))`)) return; } catch { }
      await delay(100);
    }
    throw new Error('Wheel submission failed to navigate: '+ await evaluate('document.body.innerText'));
  };
  const results = [];
  const pngA = resolve(directory, 'synthetic-wheel-context.png');
  const pngB = resolve(directory, 'synthetic-wheel-close-up.png');
  await new Promise((resolve, reject) => {
    const generator = spawn(php, ['-c', ini, '-r', '$i=imagecreatetruecolor(30,20);imagepng($i,$argv[1]);imagefill($i,0,0,imagecolorallocate($i,20,50,90));imagepng($i,$argv[2]);', pngA, pngB], { windowsHide: true, stdio: 'pipe' });
    generator.on('exit', code => code === 0 ? resolve() : reject(new Error('Synthetic image creation failed')));
  });
  const unsupported = resolve(directory, 'synthetic-unsupported.svg');
  await writeFile(unsupported, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
  const counts = async () => (await (await fetch(base + '/synthetic-wheel-counts')).json());
  const files = async paths => {
    const root = await call('DOM.getDocument');
    const input = await call('DOM.querySelector', { nodeId: root.root.nodeId, selector: '#wheel-photos' });
    await call('DOM.setFileInputFiles', { nodeId: input.nodeId, files: paths });
  };
  const inspect = async (width, label) => {
    const metrics = await evaluate(`({width:document.documentElement.scrollWidth,viewport:innerWidth,unlabelled:[...document.querySelectorAll('input:not([type=hidden]),select,textarea')].filter(f=>!f.labels?.length).map(f=>f.name),selectors:[...document.querySelectorAll('.wheel-selectors button')].map(b=>({name:b.getAttribute('aria-label'),width:b.getBoundingClientRect().width,height:b.getBoundingClientRect().height})),accept:document.querySelector('#wheel-photos')?.accept,capture:document.querySelector('#wheel-photos')?.getAttribute('capture'),multiple:document.querySelector('#wheel-photos')?.multiple})`);
    assert.equal(metrics.viewport, width); assert(metrics.width <= width, JSON.stringify(metrics)); assert.deepEqual(metrics.unlabelled, []);
    for (const target of metrics.selectors) { assert(target.name.length > 3); assert(target.width >= 44 && target.height >= 44); }
    if (metrics.accept) { assert.equal(metrics.accept, 'image/jpeg,image/png,image/webp'); assert.equal(metrics.capture, 'environment'); assert(metrics.multiple); }
    const screenshot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    await writeFile(resolve(directory, width + '-' + label + '.png'), Buffer.from(screenshot.data, 'base64'));
    results.push({ width, label, metrics });
  };
  for (const [index, width] of [390, 1280, 1920].entries()) {
    await call('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width === 390 });
    const beforeViews = await counts();
    for (const wheel of ['LF', 'RF', 'RR', 'LR']) {
      await navigate('/fleet/vehicles/10/wheel-damage/new?wheel_code=' + wheel);
      assert.equal(await evaluate('document.querySelector("[name=wheel_code][type=hidden]").value'), wheel);
      assert((await evaluate('document.body.innerText')).includes("Clock positions are referenced with the valve stem at 12 o'clock."));
    }
    assert.deepEqual(await counts(), beforeViews, 'Entry GET and wheel selection create no business records');
    const wheel = ['LF', 'RF', 'RR'][index];
    await navigate('/fleet/vehicles/10/wheel-damage/new?wheel_code=' + wheel);
    await evaluate('document.querySelector(".wheel-selectors button").focus()');
    await call('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    await call('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    assert.equal(await evaluate('document.activeElement.getAttribute("aria-label")'), 'Right front (RF)');
    const note = "Deep 4-inch rash at 2 o'clock; light 3-inch scuff at 8 o'clock. " + 'Synthetic long inspection detail. '.repeat(25);
    await fill('#wheel-occurrence-form', { note, severity_code: 'moderate', discovered_at: '2026-10-10T10:15', trip_id: '100', attribution_type: 'discovered_during_trip', reason: 'Synthetic noncausal discovery context.' });
    await inspect(width, 'new-entry');
    await files([unsupported]);
    const beforeRejected = await counts();
    await submit('#wheel-occurrence-form');
    assert((await evaluate('document.body.innerText')).includes('HEIC, PDF, and SVG are not supported'));
    assert((await evaluate('document.body.innerText')).includes('must be reselected'));
    assert.equal(await evaluate('document.querySelector("#wheel-note").value'), note);
    assert.equal(await evaluate('document.querySelector("#wheel-severity").value'), 'moderate');
    assert.equal(await evaluate('document.querySelector("#wheel-discovered").value'), '2026-10-10T10:15');
    assert.equal(await evaluate('document.querySelector("#wheel-attribution").value'), 'discovered_during_trip');
    assert.equal(await evaluate('document.querySelector("#wheel-photos").files.length'), 0);
    assert.deepEqual(await counts(), beforeRejected);
    await inspect(width, 'validation-preserved');
    await files([pngA, pngB]);
    assert.equal(await evaluate('document.querySelector("#wheel-photos").files.length'), 2);
    await submit('#wheel-occurrence-form');
    assert((await evaluate('document.body.innerText')).includes('Exact event #'));
    const photoUrl = await evaluate('[...document.querySelectorAll("a")].find(a=>a.pathname.includes("/photos/")).pathname');
    const photoResponse = await fetch(base + photoUrl);
    assert.equal(photoResponse.status, 200);
    assert.match(photoResponse.headers.get('cache-control'), /private.*no-store/);
    assert.equal(photoResponse.headers.get('x-content-type-options'), 'nosniff');
    assert.equal(photoResponse.headers.get('content-type'), 'image/png');
    const afterCreate = await counts();
    const occurrenceUrl = await evaluate('location.pathname');
    await navigate(occurrenceUrl);
    assert.deepEqual(await counts(), afterCreate, 'Occurrence GET is pure');
    await navigate('/fleet/vehicles/10/wheel-damage/new?wheel_code=' + wheel);
    assert((await evaluate('document.body.innerText')).includes('Record worsening'));
    assert(!(await evaluate('document.body.innerText')).includes('New curb rash'));
    await fill('#wheel-occurrence-form', { note: 'New deep segment at 5 o\'clock; another at 9 o\'clock.', severity_code: 'severe', discovered_at: '2026-10-10T11:15', attribution_type: 'unknown' });
    await inspect(width, 'worsening-entry');
    await submit('#wheel-occurrence-form');
    assert((await evaluate('document.body.innerText')).includes('Worsens existing'), await evaluate('document.body.innerText'));
    await navigate('/fleet/vehicles/10/wheel-damage/new?wheel_code=' + wheel);
    await fill('#wheel-occurrence-form', { note: 'Inspected unchanged; valve stem at twelve.', observed_at: '2026-10-10T12:17', attribution_type: 'unknown', occurred_at: '' });
    assert((await evaluate('document.body.innerText')).includes('records an observation and does not assign causal responsibility'));
    await inspect(width, 'unchanged-entry');
    await submit('#wheel-occurrence-form', 'button[formaction]');
    assert((await evaluate('document.body.innerText')).includes('Observes existing'), await evaluate('document.body.innerText'));
    const afterObservation = await counts();
    assert.equal(afterObservation.vehicle_damage_items, afterCreate.vehicle_damage_items);
    assert.equal(afterObservation.vehicle_damage_incident_items, afterCreate.vehicle_damage_incident_items + 2);
    results.push({ width, label: 'commands-photos-security-GET-purity', counts: afterObservation });
  }
  await navigate('/fleet/vehicles/10/wheel-damage/new?wheel_code=LR');
  await fill('#wheel-occurrence-form', { note: 'Synthetic stale New intent.', discovered_at: '2026-10-10T09:00' });
  const stalePacket = await evaluate('Object.fromEntries(new FormData(document.querySelector("#wheel-occurrence-form")).entries())');
  delete stalePacket['photos[]'];
  await submit('#wheel-occurrence-form');
  await navigate('/fleet/vehicles/10/wheel-damage/new?wheel_code=LR');
  const currentTokens = await evaluate('Object.fromEntries([...document.querySelectorAll("#wheel-occurrence-form input[type=hidden]")].filter(f=>f.name.includes("csrf")).map(f=>[f.name,f.value]))');
  Object.assign(stalePacket, currentTokens, { command_uuid: randomUUID() });
  const beforeStale = await counts();
  const staleHtml = await evaluate(`(async()=>{const response=await fetch('/fleet/vehicles/10/wheel-damage/new',{method:'POST',body:new URLSearchParams(${JSON.stringify(stalePacket)}),redirect:'follow'}); return await response.text();})()`);
  assert(staleHtml.includes('Wheel state changed'), staleHtml);
  assert.deepEqual(await counts(), beforeStale, 'Stale New remains New and creates no worsening');
  results.push({ label: 'stale-New-no-auto-conversion', counts: beforeStale });
  const csrfBefore = await counts();
  const csrf = await fetch(base + '/fleet/vehicles/10/wheel-damage/new', { method: 'POST', body: new URLSearchParams({ note: 'Synthetic forged submission' }), redirect: 'manual' });
  assert(csrf.status >= 400); assert.deepEqual(await counts(), csrfBefore);
  await writeFile(resolve(directory, 'results.json'), JSON.stringify({ checks: results.length, results, csrf: csrf.status }, null, 2));
  console.log(JSON.stringify({ success: true, checks: results.length, widths: [390,1280,1920], directory }));
} finally {
  socket?.close(); browser.kill(); server.kill(); await new Promise(resolve => proxy.close(resolve));
}
