<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Бронирование — [[++site_name]]</title>
<meta name="robots" content="noindex">
<meta name="theme-color" content="#22140E">
<link rel="manifest" href="[[++assets_url]]components/booking/app/manifest.webmanifest">
<link rel="icon" href="[[++assets_url]]components/booking/app/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="[[++assets_url]]components/booking/app/icon-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Golos+Text:wght@400;500;600&family=Prata&display=swap">
</head>
<body class="bk-appbody">
<div class="bk-app" data-bk="app" data-bk-api="[[++assets_url]]components/booking/api.php">
  <header class="bk-app__head">
    <a class="bk-app__brand" href="[[++site_url]]">[[++site_name]]</a>
    <nav class="bk-tabs bk-tabs--app" role="tablist">
      <button type="button" class="bk-tab" role="tab" data-app-tab="book">Забронировать</button>
      <button type="button" class="bk-tab" role="tab" data-app-tab="request">Моя заявка</button>
      <button type="button" class="bk-tab" role="tab" data-app-tab="admin">Администратор</button>
    </nav>
  </header>
  <p class="bk-warn bk-app__offline" data-app-offline hidden>Нет связи с интернетом. Занятость дат не показывается — она всегда загружается заново.</p>
  <main class="bk-app__main">
    <section data-app-pane="book">
      <h1 class="bk-app__h1">Забронировать дом</h1>
      [[!booking.form? &mode=`full` &client=`pwa`]]
    </section>
    <section data-app-pane="request" hidden>
      <h1 class="bk-app__h1">Моя заявка</h1>
      [[!booking.request]]
    </section>
    <section data-app-pane="admin" hidden>
      <form class="bk-box bk-login" data-app-login hidden>
        <h1 class="bk-app__h1">Администратор</h1>
        <p class="bk-muted">Вставьте токен, выпущенный в админке MODX («Бронирование» → «Токены»). Он хранится только на этом телефоне.</p>
        <label class="bk-field"><span>Токен</span><input name="token" autocomplete="off" autocapitalize="off" spellcheck="false" required></label>
        <p class="bk-err" data-app-err role="alert"></p>
        <button class="bk-btn" type="submit">Войти</button>
      </form>
      <div data-app-admin hidden>
        <div class="bk-bar bk-bar--end"><button type="button" class="bk-btn bk-btn--sm bk-btn--ghost" data-app-logout>Выйти</button></div>
        <div class="bk-admin" data-bk-admin-mount data-bk-tabs="grid,list,blocks,prices,holidays,houses"></div>
      </div>
    </section>
  </main>
</div>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    // приложение — /booking/app, Service Worker — /booking/sw.js с областью /booking/
    var base = location.pathname.replace(/app\/?$/, '');
    navigator.serviceWorker.register(base + 'sw.js', { scope: base }).catch(function () {});
  });
}
</script>
</body>
</html>
