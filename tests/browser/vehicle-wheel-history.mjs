import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { mkdir, readFile, writeFile, unlink } from 'node:fs/promises';
import { resolve, sep } from 'node:path';
import { createServer } from 'node:http';

// Runs only the synthetic PHP router and a fresh local Chrome profile.
const directory = resolve('build/slice4/browser-' + Date.now());
await mkdir(directory, { recursive: true });
const php = process.env.B21_BROWSER_PHP || 'C:/xampp/php/php.exe';
const ini = resolve(process.env.B21_BROWSER_INI || 'build/php-b1.ini');
const chrome = process.env.B21_BROWSER_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const port = 33581;
const upstreamPort = 33580;
const base = `http://127.0.0.1:${port}`;
const server = spawn(php, ['-c', ini, '-S', `127.0.0.1:${upstreamPort}`, '-t', 'public', 'tests/_support/VehicleWheelHistoryBrowserRouter.php'], {
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
    try { const response = await fetch(base + '/fleet/vehicles/10/damage-ledger'); if (response.ok) break; const body=await response.text(); throw new Error(body==='TypeError: fetch failed'?'Server starting':'Seed: '+body); }
    catch (error) { if (error.message.startsWith('Seed:') || i === 99) throw new Error(`${error.message}\n${serverErrors}`); }
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
    await evaluate('globalThis.wheelHistoryNavigating=true');
    const navigation = await call('Page.navigate', { url: base + path });
    assert(!navigation.errorText, 'Navigation error: ' + JSON.stringify(navigation));
    await delay(200);
    let ready = false;
    for (let i = 0; i < 150; i++) {
      try { ready = await evaluate(`globalThis.wheelHistoryNavigating !== true && document.readyState === 'complete' && document.querySelector('h1,h2,h3') != null && location.pathname + location.search === ${JSON.stringify(path)}`); if (ready) break; } catch { /* Context is being replaced during navigation. */ }
      await delay(100);
    }
    assert(ready, 'Navigation completed: ' + path + ': ' + await evaluate('location.href + " " + document.body.innerText.slice(0,800)') + serverErrors.slice(-1200));
    assert(!/Exception:|Error:/.test(await evaluate('document.body.innerText')), await evaluate('document.body.innerText'));
  };
  const results=[];
  const counts=async()=>await(await fetch(base+'/synthetic-wheel-counts')).json();
  const fixture=JSON.parse(await readFile(resolve(directory,'fixture.json'),'utf8'));
  const occurrence='/fleet/vehicles/10/wheel-history/'+fixture.condition+'/occurrences/'+fixture.membership;
  const before=await counts();
  for(const width of [390,1280,1920]){
    await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width===390});
    for(const [label,path] of [['ledger','/fleet/vehicles/10/damage-ledger'],['history','/fleet/vehicles/10/wheel-history/LF'],['repair-boundary','/fleet/vehicles/10/wheel-history/RR'],['comparison',occurrence]]){
      await navigate(path);
      const text=await evaluate('document.body.innerText');assert(text.includes("Clock positions are referenced with the valve stem at 12 o'clock."));
      const metrics=await evaluate(`({width:document.documentElement.scrollWidth,viewport:innerWidth,cells:[...document.querySelectorAll('.wheel-cell')].map(a=>({code:a.querySelector('strong').textContent.split(' · ')[0],name:a.getAttribute('aria-label'),top:a.getBoundingClientRect().top,height:a.getBoundingClientRect().height,width:a.getBoundingClientRect().width})),targets:[...document.querySelectorAll('main a')].map(a=>a.getBoundingClientRect().height)})`);
      assert.equal(metrics.viewport,width);assert(metrics.width<=width,JSON.stringify(metrics));assert(metrics.targets.every(h=>h>=44));
      if(metrics.cells.length){assert.deepEqual(metrics.cells.map(c=>c.code),['LF','RF','RR','LR']);for(const c of metrics.cells){assert(c.name.length>15);assert(c.height>=44&&c.width>=44);}if(width===390){assert.equal(metrics.cells[0].top,metrics.cells[1].top);assert(metrics.cells[2].top>metrics.cells[0].top);}assert(text.includes('Review conflicting records'));assert(text.includes('Wheel position unknown'));assert(text.includes('Accepted unrepaired'));}
      if(label==='history'){assert(text.includes('Occurrence timeline'));assert(text.includes('Next history'));assert(text.includes('Episode evidence — occurrence unknown'));}
      if(label==='repair-boundary'){assert(text.includes('Episode 1')&&text.includes('Episode 2'));assert(text.includes('Repaired')&&text.includes('Open'));}
      if(label==='comparison'){assert(text.includes('Observed before this guest'));assert(text.includes('Frozen pre-handoff observation.'));assert(text.includes('Occurrence photos'));assert(text.includes('Evidence unavailable'));assert(text.includes('Full wheel context'));assert(text.includes('Close-up'));assert(text.includes('Uploaded/recorded later'));assert(text.includes('Next photos'));}
      await evaluate('document.querySelector("main a").focus()');await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});await call('Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});assert(await evaluate('document.activeElement.tagName === "A" && document.activeElement.matches(":focus-visible")'));
      const screenshot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});await writeFile(resolve(directory,width+'-'+label+'.png'),Buffer.from(screenshot.data,'base64'));results.push({width,label,metrics});
    }
    await navigate('/fleet/vehicles/10/wheel-history/LR');const conflictText=await evaluate('document.body.innerText');assert(conflictText.includes('View all current wheel conditions'));assert(!conflictText.includes('Selected condition #'));
    await navigate('/fleet/vehicles/10/wheel-history/LF?timeline_page=2');assert(!(await evaluate('document.body.innerText')).includes('Next history'));assert((await evaluate('document.body.innerText')).includes('Previous history'));
    await navigate('/fleet/vehicles/10/damage-ledger?wheel=RF&physical_status=resolved&identity=current&q=paginated');const next=await evaluate('[...document.querySelectorAll("a")].find(a=>a.textContent.includes("Next records"))?.getAttribute("href")');assert(next&&next.includes('wheel=RF')&&next.includes('physical_status=resolved')&&next.includes('q=paginated'));await navigate('/fleet/vehicles/10/damage-ledger'+next);
    await navigate(occurrence);const photo=await evaluate('document.querySelector(".wheel-photo-grid a")?.getAttribute("href")');assert(photo);const response=await fetch(base+photo);assert.equal(response.status,200);assert.match(response.headers.get('cache-control'),/private.*no-store/);assert.equal(response.headers.get('x-content-type-options'),'nosniff');assert.equal(response.headers.get('content-type'),'image/png');
    const nextPhotos=await evaluate('[...document.querySelectorAll("a")].find(a=>a.textContent.includes("Next photos"))?.getAttribute("href")');await navigate(occurrence+nextPhotos);assert(!(await evaluate('document.body.innerText')).includes('Next photos'));
    assert.deepEqual(await counts(),before,'Repeated read navigation and private evidence GETs make zero business writes');results.push({width,label:'pagination-private-evidence-GET-purity'});
  }
  assert.equal((await fetch(base+'/fleet/vehicles/10/damage-ledger?wheel=XX')).status,404);
  assert.equal((await fetch(base+'/fleet/vehicles/10/wheel-history/999999/occurrences/'+fixture.membership)).status,404);
  await writeFile(resolve(directory,'results.json'),JSON.stringify({success:true,checks:results.length,results,before,after:await counts()},null,2));console.log(JSON.stringify({success:true,checks:results.length,widths:[390,1280,1920],directory}));
} finally {
  socket?.close(); browser.kill(); server.kill(); await new Promise(resolve => proxy.close(resolve));
  try {const files=JSON.parse(await readFile(resolve(directory,'created-files.json'),'utf8'));const privateRoot=resolve('writable/uploads/damage-evidence/company-1/vehicle-10');for(const path of files){const absolute=resolve(path);assert(absolute.startsWith(privateRoot+sep));try{await unlink(absolute);}catch(error){if(error.code!=='ENOENT')throw error;}}}catch(error){if(error.code!=='ENOENT')throw error;}
}
