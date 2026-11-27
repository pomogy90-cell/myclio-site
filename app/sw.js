/* КЛИО · Service Worker · v4 (27.11.2026)
   API и HTML — всегда из сети; изображения — сначала сеть (фото из админки
   обновляются сразу), офлайн — кэш; прочая статика — cache-first. */
var CACHE = 'klio-v4';
var STATIC = [
  './', 'index.html', 'manifest.webmanifest',
  'icon-192.png', 'icon-512.png', 'apple-touch-icon.png',
  'img/klio-logo-180.jpg', 'img/klio-resepshen.webp', 'img/klio-karta.webp',
  'img/klio-strizhka-brashing.webp', 'img/klio-manikyur-gel-lak.webp',
  'img/klio-pedikyur-spa.webp', 'img/klio-massazh-spiny.webp',
  'img/klio-kosmetologiya-maska.webp', 'img/klio-brovi-korrekciya.webp',
  'img/klio-solyariy.webp', 'img/klio-spa-programmy.jpg'
];

self.addEventListener('install', function(e){
  e.waitUntil(caches.open(CACHE).then(function(c){ return c.addAll(STATIC); }).then(function(){ return self.skipWaiting(); }));
});

self.addEventListener('activate', function(e){
  e.waitUntil(caches.keys().then(function(keys){
    return Promise.all(keys.map(function(k){ if(k !== CACHE) return caches.delete(k); }));
  }).then(function(){ return self.clients.claim(); }));
});

self.addEventListener('fetch', function(e){
  var url = new URL(e.request.url);
  if (e.request.method !== 'GET') return;                 /* POST (заявки) — только сеть */
  if (url.pathname.indexOf('/api/') === 0) return;         /* API не кэшируем */
  if (url.pathname.endsWith('.html') || url.pathname.endsWith('/app/')) {
    /* HTML: сначала сеть, при офлайне — кэш */
    e.respondWith(
      fetch(e.request).then(function(r){
        var copy = r.clone(); caches.open(CACHE).then(function(c){ c.put(e.request, copy); });
        return r;
      }).catch(function(){ return caches.match(e.request).then(function(m){ return m || caches.match('index.html'); }); })
    );
    return;
  }
  /* изображения: сначала сеть (замена фото в админке видна сразу), офлайн — кэш */
  if (/\.(webp|jpe?g|png|svg|ico|gif)$/i.test(url.pathname)) {
    e.respondWith(
      fetch(e.request).then(function(r){
        if (r.ok && url.origin === location.origin) {
          var copy = r.clone(); caches.open(CACHE).then(function(c){ c.put(e.request, copy); });
        }
        return r;
      }).catch(function(){ return caches.match(e.request); })
    );
    return;
  }
  /* остальная статика: сначала кэш, при промахе — сеть */
  e.respondWith(
    caches.match(e.request).then(function(m){
      return m || fetch(e.request).then(function(r){
        if (r.ok && url.origin === location.origin) {
          var copy = r.clone(); caches.open(CACHE).then(function(c){ c.put(e.request, copy); });
        }
        return r;
      });
    })
  );
});
