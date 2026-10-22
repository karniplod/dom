/* Service Worker приложения бронирования (отдаётся страницей /booking/sw.js, область /booking/).
   Кеширует только статику: стили, скрипт, иконки, манифест. Запросы к api.php и страницы — только сеть:
   занятость дат никогда не берётся из кеша (ст. 5.2, FR-026). */
var VERSION = 'booking-{{version}}';
var ASSETS = '{{assets}}';
var STATIC = [
  ASSETS + 'css/booking.css?v={{version}}',
  ASSETS + 'js/booking.js?v={{version}}',
  ASSETS + 'app/manifest.webmanifest',
  ASSETS + 'app/icon.svg',
  ASSETS + 'app/icon-192.png',
  ASSETS + 'app/icon-512.png'
];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(VERSION).then(function (c) { return c.addAll(STATIC); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k.indexOf('booking-') === 0 && k !== VERSION; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;
  // API и SendIt — только сеть, без кеша
  if (url.pathname.indexOf('/api.php') !== -1 || url.pathname.indexOf('/sendit/') !== -1) return;
  // статика компонента — из кеша, затем сеть
  if (url.pathname.indexOf(ASSETS) === 0) {
    e.respondWith(caches.match(e.request).then(function (hit) { return hit || fetch(e.request); }));
    return;
  }
  // страница приложения: сеть; без сети — короткое сообщение (не старая занятость)
  if (e.request.mode === 'navigate') {
    e.respondWith(fetch(e.request).catch(function () {
      return new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        + '<title>Нет связи</title><body style="font:16px/1.5 system-ui,sans-serif;padding:24px;background:#F4EBDF;color:#22140E">'
        + '<h1 style="font-weight:400">Нет связи с интернетом</h1><p>Занятость дат и заявки загружаются только с сервера. Проверьте интернет и обновите страницу.</p>',
        { headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } });
    }));
  }
});
