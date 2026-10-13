import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { mkdir, readFile, writeFile, unlink } from 'node:fs/promises';
import { resolve, sep } from 'node:path';
import { createServer } from 'node:http';

// Runs only the synthetic PHP router and a fresh local Chrome profile.
const directory = resolve('build/slice5/browser-' + Date.now());
await mkdir(directory, { recursive: true });
const php = process.env.B21_BROWSER_PHP || 'C:/xampp/php/php.exe';
const ini = resolve(process.env.B21_BROWSER_INI || 'build/php-b1.ini');
const chrome = process.env.B21_BROWSER_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const port = 33591;
const upstreamPort = 33590;
const base = `http://127.0.0.1:${port}`;
const server = spawn(php, ['-c', ini, '-S', `127.0.0.1:${upstreamPort}`, '-t', 'public', 'tests/_support/VehicleWheelChecklistBrowserRouter.php'], {
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
    const requestId = ++id; const timer = setTimeout(() => reject(new Error(method + ' timeout: ' + String(params.expression ?? '').slice(0,180))), 15000);
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
  const photo=resolve(directory,'synthetic-wheel.png');
  let fixtureWidth=390;
  const state=async()=>{const response=await fetch(base+'/synthetic-wheel-counts',{headers:{Cookie:'wheel_checklist_fixture='+fixtureWidth},signal:AbortSignal.timeout(10000)});assert.equal(response.status,200);return response.json();};
  const formPath=(wheel,action)=>'/operations/checklists/100/wheel-curb-rash/'+wheel+'/'+action;
  const submit=async path=>{
    await evaluate('globalThis.wheelHistoryNavigating=true;setTimeout(()=>document.querySelector("#staging-wheel-form").requestSubmit(),0)');
    let ready=false;
    for(let i=0;i<150;i++){
      try{ready=await evaluate(`globalThis.wheelHistoryNavigating!==true && document.readyState==='complete' && location.pathname===${JSON.stringify(path)}`);if(ready)break;}catch{}
      await delay(100);
    }
    assert(ready,'POST navigation: '+path+': '+await evaluate('document.body.innerText.slice(0,1000)')+serverErrors.slice(-1000));
  };
  const metrics=async width=>{
    const m=await evaluate(`({width:document.documentElement.scrollWidth,viewport:innerWidth,cells:[...document.querySelectorAll('.wheel-cell')].map(a=>({code:a.querySelector('strong').textContent.split(' \u00b7 ')[0],name:a.getAttribute('aria-label'),top:a.getBoundingClientRect().top,height:a.getBoundingClientRect().height})),targets:[...document.querySelectorAll('.wheel-checklist-entry a,.wheel-checklist-entry button,.wheel-checklist-entry input,.wheel-checklist-entry select,.wheel-optional>summary,.staging-wheel-actions a')].filter(a=>a.getBoundingClientRect().height>0).map(a=>a.getBoundingClientRect().height)})`);
    assert.equal(m.viewport,width);assert(m.width<=width,JSON.stringify(m));assert(m.targets.every(h=>h>=44),JSON.stringify(m));
    if(m.cells.length){assert.deepEqual(m.cells.map(c=>c.code),['LF','RF','RR','LR']);assert(m.cells.every(c=>c.height>=44&&c.name.length>15));if(width===390){assert.equal(m.cells[0].top,m.cells[1].top);assert(m.cells[2].top>m.cells[0].top);}}
    return m;
  };
  for(const width of [390,1280,1920]){
    fixtureWidth=width;
    await call('Network.setCookie',{name:'wheel_checklist_fixture',value:String(width),url:base});
    await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width===390});
    await navigate('/operations/checklists/100');
    const before=await state();
    const initial=await evaluate('document.body.innerText');
    for(const text of ['No active rash recorded','Open rash','Review conflicting records','Wheel position unknown','Other wheel crack','Generic body scratch','Wheel review is optional'])assert(initial.includes(text),text);
    assert(await evaluate(`document.querySelector('[data-wheel-actions="RR"]').querySelectorAll('a').length===1`));
    await evaluate('document.querySelector(".wheel-optional").open=true');
    const stripMetrics=await metrics(width);
    const screenshot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});await writeFile(resolve(directory,width+'-checklist.png'),Buffer.from(screenshot.data,'base64'));
    results.push({width,label:'strip-actions-unknown-conflict-generic-mobile',metrics:stripMetrics});
    for(const path of ['/operations/checklists/100',formPath('RF','worsening'),formPath('RF','unchanged'),'/fleet/vehicles/10/wheel-history/RF'])await navigate(path);
    assert.deepEqual((await state()).rows,before.rows,'Repeated GETs create zero Damage/business writes');results.push({width,label:'GET-purity'});
    await navigate(formPath('LF','new'));
    assert((await evaluate('document.body.innerText')).includes("Clock positions are referenced with the valve stem at 12 o'clock."));
    assert(await evaluate('!document.querySelector("[name=trip_id]")&&!document.querySelector("[name=checklist_id]")'));
    await evaluate('document.querySelector("#wheel-note").value="Synthetic new rash at 2 o?clock";document.querySelector("#wheel-photo-role").value="full_wheel"');
    const documentNode=await call('DOM.getDocument');const fileNode=await call('DOM.querySelector',{nodeId:documentNode.root.nodeId,selector:'#wheel-photos'});await call('DOM.setFileInputFiles',{nodeId:fileNode.nodeId,files:[photo]});
    await metrics(width);await submit('/operations/checklists/100');
    assert((await evaluate('document.body.innerText')).includes('Left front (LF): New curb rash recorded.'));
    assert(await evaluate(`!document.querySelector('[data-wheel-actions="LF"] a[href$="/new"]')`));
    results.push({width,label:'new-rash-photo-upload-PRG'});
    await navigate('/fleet/vehicles/10/wheel-history/LF');
    const occurrence=await evaluate('[...document.querySelectorAll("a")].find(a=>a.getAttribute("href")?.includes("/occurrences/")).getAttribute("href")');assert(occurrence);await navigate(occurrence);
    assert((await evaluate('document.body.innerText')).includes('Full wheel context'));
    const privateUrl=await evaluate('document.querySelector(".wheel-photo-grid a").getAttribute("href")');
    const privateHeaders=await evaluate(`fetch(${JSON.stringify(privateUrl)}).then(r=>({status:r.status,cache:r.headers.get('cache-control'),type:r.headers.get('content-type'),sniff:r.headers.get('x-content-type-options')}))`);
    assert.equal(privateHeaders.status,200);assert.match(privateHeaders.cache,/private.*no-store/);assert.equal(privateHeaders.sniff,'nosniff');assert.equal(privateHeaders.type,'image/png');results.push({width,label:'private-occurrence-photo-history'});
    await navigate(formPath('LF','worsening'));await evaluate('document.querySelector("#wheel-note").value="Synthetic worsening at 8 o?clock";document.querySelector("#wheel-severity").value="severe";if(document.querySelector("#wheel-baseline")){document.querySelector("#wheel-baseline").selectedIndex=1}');await submit('/operations/checklists/100');
    assert((await evaluate('document.body.innerText')).includes('Worsened on this trip'));results.push({width,label:'worsening'});
    await navigate(formPath('LF','unchanged'));const unchanged=await evaluate('document.body.innerText');assert(unchanged.includes('It does not assign responsibility to the current guest.'));assert(await evaluate('document.querySelector("#wheel-observed").required && !document.querySelector("[name=severity_code]")'));
    await evaluate('document.querySelector("#wheel-note").value="Explicit unchanged observation"');await submit('/operations/checklists/100');
    assert((await evaluate('document.body.innerText')).includes('Observed on this trip - noncausal observation'));results.push({width,label:'explicit-unchanged-noncausal'});
    const after=await state();assert.deepEqual(after.firewall,before.firewall);assert.deepEqual(after.readiness,before.readiness);assert.deepEqual(after.custody,before.custody);assert.deepEqual(after.position,before.position);results.push({width,label:'movement-readiness-firewall'});
    await navigate(formPath('LF','worsening'));await evaluate('document.querySelector("#wheel-note").required=false;document.querySelector("#wheel-note").value=""');const beforeInvalid=await state();await submit(formPath('LF','worsening'));assert((await evaluate('document.body.innerText')).includes('Wheel report was not saved.'));assert(await evaluate('document.querySelector("#wheel-note").getAttribute("aria-describedby").includes("wheel-errors")'));assert.deepEqual((await state()).rows,beforeInvalid.rows);results.push({width,label:'validation-errors-zero-write'});
    await navigate(formPath('LR','new'));
    const concurrent=await evaluate(`(async()=>{const f=document.querySelector('#staging-wheel-form');const d=new FormData();const token=f.querySelector('[name^="csrf"]');d.set(token.name,token.value);d.set('wheel','LR');const r=await fetch('/synthetic-concurrent-rash',{method:'POST',body:d});const result=await r.json();token.value=result.csrf;return result;})()`);assert(concurrent.success);
    const staleBefore=await state();await evaluate('document.querySelector("#wheel-note").value="Stale new form"');await submit(formPath('LR','new'));
    assert((await evaluate('document.body.innerText')).includes('not been reinterpreted'));assert(!await evaluate('Boolean(document.querySelector("#staging-wheel-form"))'));assert.deepEqual((await state()).rows,staleBefore.rows);results.push({width,label:'stale-new-not-reinterpreted'});
    await navigate('/operations/checklists/105');assert((await evaluate('document.body.innerText')).includes('Return context'));assert(!await evaluate('Boolean(document.querySelector(".wheel-optional"))'));
    await navigate('/operations/checklists/104');assert((await evaluate('document.body.innerText')).includes('older schedule snapshot'));results.push({width,label:'return-stale-context-read-only'});
    await navigate(formPath('RF','worsening'));assert((await evaluate('document.body.innerText')).includes('Long recorded rash at 2'));await metrics(width);
    await evaluate('document.querySelector("main a").focus()');await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});await call('Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});assert(await evaluate('document.activeElement.matches(":focus-visible")'));results.push({width,label:'long-description-keyboard-focus'});
    const beforeReads=await state();for(const path of ['/operations/checklists/100',formPath('LF','unchanged'),'/fleet/vehicles/10/wheel-history/LF',occurrence])await navigate(path);assert.deepEqual((await state()).rows,beforeReads.rows);results.push({width,label:'post-command-GET-purity'});
    assert.equal(await evaluate('fetch("/operations/checklists/200/wheel-curb-rash/LF/new").then(r=>r.status)'),404);
  }
  await writeFile(resolve(directory,'results.json'),JSON.stringify({success:true,checks:results.length,widths:[390,1280,1920],results},null,2));console.log(JSON.stringify({success:true,checks:results.length,widths:[390,1280,1920],directory}));
} finally {
  await writeFile(resolve(directory,'server.log'),serverErrors);
  socket?.close(); browser.kill(); server.kill(); await new Promise(resolve => proxy.close(resolve));
  try {const files=JSON.parse(await readFile(resolve(directory,'created-files.json'),'utf8'));const privateRoot=resolve('writable/uploads/damage-evidence/company-1/vehicle-10');for(const path of files){const absolute=resolve(path);assert(absolute.startsWith(privateRoot+sep));try{await unlink(absolute);}catch(error){if(error.code!=='ENOENT')throw error;}}}catch(error){if(error.code!=='ENOENT')throw error;}
}
