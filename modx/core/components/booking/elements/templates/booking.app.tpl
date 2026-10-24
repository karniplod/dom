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
    <a class="bk-app__brand" href="[[++site_url]]">[[++site_name]]</a>
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
    <section data-app-pane="help" hidden aria-labelledby="help-title">
      <h1 class="bk-app__h1" id="help-title">Как пользоваться</h1>
      <nav class="bk-help__nav" aria-label="Разделы инструкции"><a href="#help-guest" data-help-jump>Гостям</a><a href="#help-admin" data-help-jump>Администраторам</a><a href="#help-install" data-help-jump>Установить на телефон</a></nav>

      <article class="bk-box bk-help" id="help-guest">
        <h2>Гостям</h2>
        <h3>Забронировать Дом</h3>
        <ol>
          <li>Вкладка «Забронировать» → выберите Дом.</li>
          <li>В календаре нажмите день заезда, затем день выезда. Серые дни заняты — их выбрать нельзя.</li>
          <li>Укажите, сколько будет гостей и питомцев: ниже сразу появится стоимость.</li>
          <li>Напишите имя и телефон, отметьте согласие и нажмите «Отправить заявку».</li>
        </ol>
        <p>Вы получите номер заявки и ссылку на неё — сохраните ссылку. Администратор перезвонит и подтвердит бронь. Даты станут вашими после подтверждения: до этого их может занять другая заявка.</p>
        <h3>Только баня, по часам</h3>
        <p>Если у Дома можно снять баню отдельно, под выбором Дома появится «Только баня». Зелёный день — есть свободные часы, красный — баня занята весь день. Выберите день и время «С» и «До»: занятые часы выбрать нельзя, между гостями баню убирают. Цена — за час, ниже сразу видна сумма.</p>
        <h3>Цена и скидка</h3>
        <p>Стоимость суток зависит от дня недели, в праздники и особые даты — своя цена. В цену входит определённое число гостей, за каждого следующего — доплата. Скидка 5% — при бронировании через нашу группу ВКонтакте. Залог 5 000–20 000 ₽ администратор назовёт при подтверждении.</p>
        <h3>Моя заявка</h3>
        <p>Вкладка «Моя заявка»: введите номер и телефон из заявки или откройте ссылку. Там видно, подтверждена ли бронь, сумма и залог. Вы видите только свою заявку — чужие по номеру не открываются без телефона. Если планы изменились — нажмите «Попросить отменить», и администратор свяжется с вами. Отменяет бронь только администратор.</p>
      </article>

      <article class="bk-box bk-help" id="help-admin" hidden>
        <h2>Администраторам</h2>
        <h3>Вход с телефона</h3>
        <ol>
          <li>На компьютере в админке сайта: «Пакеты → Бронирование → Токены».</li>
          <li>Подпишите, чей телефон, и нажмите «Выпустить токен». Ключ показывается один раз — скопируйте и отправьте себе на телефон.</li>
          <li>Здесь, во вкладке «Администратор», вставьте ключ и нажмите «Войти». Ключ запомнится только на этом телефоне (и отдельно — в MAX, если открываете приложение из бота).</li>
        </ol>
        <p>Потеряли телефон — отзовите его ключ в «Токенах». «Выйти» стирает ключ с телефона.</p>
        <h3>Новые заявки</h3>
        <p>О каждой новой заявке и о просьбе гостя отменить бронь приходит письмо на почту администратора и сообщение в общую группу администраторов в MAX. Гостю сайт сам ничего не пишет: подтверждение — ваш звонок.</p>
        <h3>Каждый день</h3>
        <ul>
          <li><b>Календарь</b> — Дома по строкам, даты по столбцам. Зелёное — подтверждено, кружок с числом — новые заявки, оранжевое — гость просит отменить. Нажмите — откроется карточка.</li>
          <li><b>Заявки</b> — список с фильтрами и поиском по имени, телефону, номеру.</li>
          <li><b>Подтвердить</b> — в карточке новой заявки; можно сразу указать залог 5 000–20 000 ₽. Если даты уже заняты другой подтверждённой бронью, система откажет и назовёт её номер.</li>
          <li><b>Залог</b> — «ожидается» → «внесён» → «возвращён» в карточке брони.</li>
          <li><b>Отменить</b> — с причиной: даты освобождаются, запись остаётся в истории.</li>
          <li><b>Гости уехали</b> — «Завершить» в карточке.</li>
          <li><b>Бронь по телефону</b> — во вкладке «Заявки»: сразу подтверждённая бронь за гостя. Скидку 5% отметьте, если гость из группы ВКонтакте.</li>
          <li><b>Закрытые даты</b> — ремонт или своё мероприятие: даты станут серыми для гостей.</li>
        </ul>
        <h3>Баня и Дом</h3>
        <p>Бронь Дома занимает баню: в день заезда — с часа заезда, в день выезда — до выезда плюс уборка Дома. Отдельную бронь бани система сама не поставит вплотную: между гостями — время уборки бани.</p>
        <h3>Цены и настройки</h3>
        <p>«Цены» — сутки Дома и час бани по типам дней. «Праздники» — особые даты со своей ценой для каждого Дома и бани. «Дома и бани» — часы заезда и выезда, уборка, можно ли снять баню отдельно. Изменения сразу видны на сайте.</p>
      </article>

      <article class="bk-box bk-help" id="help-install" hidden>
        <h2>Установить на телефон</h2>
        <p><b>Android (Chrome, Яндекс Браузер):</b> кнопка «Установить» вверху страницы или меню браузера ⋮ → «Установить приложение» («Добавить на главный экран»).</p>
        <p><b>iPhone (Safari):</b> кнопка «Поделиться» внизу экрана → «На экран „Домой“» → «Добавить».</p>
        <p><b>MAX:</b> найдите нашего бота и нажмите кнопку приложения внизу чата — бронирование откроется прямо в MAX, ничего ставить не нужно.</p>
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
