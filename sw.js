/* Koelony service worker: keeps the app shell available offline.
   Data (api.php) is never cached here — the app keeps its own copy.
   Pages are always fetched fresh (no-store) so a newly uploaded version shows at once;
   the free host's bot-check page (aes.js) is never cached. */
const C='kc-shell-v139';
self.addEventListener('install',e=>{e.waitUntil(caches.open(C).then(c=>c.addAll(['manifest.json','icon-192.png'])).catch(()=>{}));self.skipWaiting()});
self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(k=>Promise.all(k.filter(x=>x!==C).map(x=>caches.delete(x)))));self.clients.claim()});
self.addEventListener('fetch',e=>{const u=new URL(e.request.url);
  if(e.request.method!=='GET'||u.origin!==location.origin||u.pathname.endsWith('api.php'))return;
  const page=e.request.mode==='navigate'||u.pathname.endsWith('/')||u.pathname.endsWith('.html');
  e.respondWith((async()=>{try{const r=await fetch(e.request,page?{cache:'no-store'}:{});
      if(r.ok&&r.type==='basic'){const cp=r.clone();const txt=page?await cp.clone().text():'';
        if(!page||(!txt.includes('/aes.js')&&txt.includes('APP_VER'))){const c=await caches.open(C);await c.put(page?'index.html':e.request,cp)}}
      return r}catch(err){return (await caches.match(page?'index.html':e.request))||(await caches.match('index.html'))||Response.error()}})())});
