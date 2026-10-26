<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<!-- SendIt подключает свои скрипты относительным путём «assets/…»: на вложенной странице /booking/app без base они не найдутся -->
<base href="[[++base_url]]">
<title>Бронирование — [[++site_name]]</title>
<meta name="robots" content="noindex">
<meta name="theme-color" content="#22140E">
<link rel="manifest" href="[[++assets_url]]components/booking/app/manifest.webmanifest">
<link rel="icon" href="[[++assets_url]]components/booking/app/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="[[++assets_url]]components/booking/app/icon-192.png">
<!-- шрифты со своего сервера: IP посетителей не уходит в Google (152-ФЗ) -->
<link rel="stylesheet" href="[[++assets_url]]components/booking/fonts/fonts.css">
</head>
<body class="bk-appbody">
<div class="bk-app" data-bk="app" data-bk-api="[[++assets_url]]components/booking/api.php">
  <header class="bk-app__head">
    <div class="bk-app__top">
      <a class="bk-app__brand" href="[[++site_url]]">[[++site_name]]</a>
      <!-- «Выйти» — в шапке, подальше от вкладок администратора: видна только вошедшему, на вкладке «Администратор» -->
      <button type="button" class="bk-app__logout" data-app-logout hidden>Выйти</button>
    </div>
    <nav class="bk-tabs bk-tabs--app" role="tablist">
      <button type="button" class="bk-tab" role="tab" data-app-tab="book">Забронировать</button>
      <button type="button" class="bk-tab" role="tab" data-app-tab="request">Моя заявка</button>
      <button type="button" class="bk-tab" role="tab" data-app-tab="admin">Администратор</button>
    </nav>
  </header>
  <p class="bk-warn bk-app__offline" data-app-offline hidden>Нет связи с интернетом. Занятость дат не показывается — она всегда загружается заново.</p>
  <main class="bk-app__main">
    <div class="bk-install" data-app-install hidden>
      <p><b>Приложение на телефон</b><span data-app-install-text>Бронирование одним касанием с главного экрана — без поиска сайта.</span></p>
      <button type="button" class="bk-btn bk-btn--sm" data-app-install-btn>Установить</button>
      <button type="button" class="bk-install__x" data-app-install-close aria-label="Скрыть">×</button>
    </div>
    <section data-app-pane="book">
      <h1 class="bk-app__h1">Забронировать Дом</h1>
      [[!booking.form? &mode=`full` &client=`pwa`]]
    </section>
    <section data-app-pane="request" hidden>
      <h1 class="bk-app__h1">Моя заявка</h1>
      [[!booking.request]]
    </section>
    <section data-app-pane="admin" hidden>
      <form class="bk-box bk-login" data-app-login hidden>
        <h1 class="bk-app__h1">Администратор</h1>
        <p class="bk-muted">Вставьте токен из админки сайта («Бронирование» → «Токены»). Он хранится только на этом телефоне.</p>
        <label class="bk-field"><span>Токен</span><input name="token" autocomplete="off" autocapitalize="off" spellcheck="false" required></label>
        <p class="bk-err" data-app-err role="alert"></p>
        <button class="bk-btn" type="submit">Войти</button>
      </form>
      <div data-app-admin hidden>
        <div class="bk-admin" data-bk-admin-mount data-bk-tabs="grid,list,stats,blocks,prices,holidays,houses,help"></div>
      </div>
    </section>
    <section data-app-pane="help" hidden aria-labelledby="help-title">
      <h1 class="bk-app__h1" id="help-title">Как пользоваться</h1>
      <nav class="bk-help__nav" aria-label="Разделы инструкции"><a href="#help-guest" data-help-jump>Гостям</a><a href="#help-install" data-help-jump>Установить на телефон</a></nav>

      <article class="bk-box bk-help" id="help-guest">
        <h2>Гостям</h2>
        <h3>Как забронировать Дом</h3>
        <ol>
          <li>Вкладка «Забронировать» → выберите Дом.</li>
          <li>В календаре нажмите день заезда, затем день выезда. Серые дни заняты, прошедшие выбрать нельзя. Внизу видно часы заезда и выезда этого Дома.</li>
          <li>Укажите, сколько будет гостей и питомцев: ниже сразу появится стоимость по дням.</li>
          <li>Напишите имя и телефон, при желании — комментарий (повод, пожелания), отметьте согласие и нажмите «Отправить заявку».</li>
        </ol>
        <p>Вы получите номер заявки и ссылку на неё — сохраните ссылку. Администратор перезвонит, подтвердит даты и назовёт предоплату и залог. Даты станут вашими после подтверждения: до этого их может занять другой гость.</p>
        <h3>Только баня, по часам</h3>
        <p>Если у Дома можно снять баню отдельно, под выбором Дома появится «Только баня». Зелёный день — есть свободные часы, красный — баня занята весь день. Выберите день и время «С» и «До»: занятые часы выбрать нельзя, между гостями баню убирают. Цена — за час, ниже сразу видна сумма.</p>
        <h3>Цена, скидка, залог</h3>
        <ul>
          <li>Стоимость суток зависит от дня недели; в праздники и особые даты — своя цена. Баня и бассейн входят в цену суток.</li>
          <li>В цену входит определённое число гостей, за каждого следующего — доплата; питомец — тоже доплата.</li>
          <li>Скидка 5% — при бронировании через нашу группу ВКонтакте.</li>
          <li>Предоплата 50% — после звонка администратора. Залог 5 000–20 000 ₽ возвращается при выезде.</li>
        </ul>
        <h3>Моя заявка</h3>
        <p>Вкладка «Моя заявка»: откройте ссылку из заявки или введите номер и телефон. Там видно, подтверждена ли бронь, даты, сумма и залог. Чужую заявку без телефона не открыть.</p>
        <h3>Если планы изменились</h3>
        <p>В «Моей заявке» нажмите «Попросить отменить» и напишите причину — администратор свяжется с вами. Отменяет бронь только администратор. Можно и просто позвонить.</p>
        <h3>Вопросы</h3>
        <p>Напишите нашему боту в MAX — он сразу ответит про цены, заезд, баню и правила. Или позвоните администратору.</p>
        <p class="bk-muted">Вы администратор? Вкладка «Администратор» → вставьте свой ключ. Подробная инструкция для администраторов откроется после входа.</p>
      </article>

      

      <article class="bk-box bk-help" id="help-install" hidden>
        <h2>Установить на телефон</h2>
        <p><b>Android (Chrome, Яндекс Браузер):</b> кнопка «Установить» вверху страницы или меню браузера ⋮ → «Установить приложение» («Добавить на главный экран»).</p>
        <p><b>iPhone (Safari):</b> кнопка «Поделиться» внизу экрана → «На экран „Домой“» → «Добавить».</p>
        <p><b>MAX:</b> найдите нашего бота и нажмите кнопку приложения внизу чата — бронирование откроется прямо в MAX, ничего ставить не нужно. Значок на экран телефона из MAX не ставится: нажмите вверху «Открыть в браузере» и установите оттуда.</p>
        <p>Значок с лисой появится на главном экране и откроет бронирование в отдельном окне. Свободные даты всегда загружаются с сайта заново — без интернета приложение их не показывает.</p>
        <p><button type="button" class="bk-btn bk-btn--sm" data-app-install-btn hidden>Установить приложение</button></p>
      </article>
      <p><button type="button" class="bk-btn bk-btn--ghost bk-btn--sm" data-app-back>← Вернуться</button></p>
    </section>
  </main>
  <button type="button" class="bk-help-fab" data-app-help aria-label="Как пользоваться">?</button>
</div>
<script>
// Chrome присылает предложение установить приложение до загрузки booking.js — запоминаем его
window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); window.bkInstallPrompt = e; document.dispatchEvent(new Event('bk:installable')); });
</script>
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
