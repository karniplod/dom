/* Бронирование — один модуль для сайта, «Моей заявки», админки MODX и приложения (PWA / MAX), ст. 7.3.
   Режимы по data-bk: guest (форма и календарь гостя), request (моя заявка), admin (сетка и карточки администратора).
   Занятость всегда берётся с сервера заново и не кешируется (ст. 5.2); проверки дат — только на сервере. */
(function () {
  'use strict';

  var MONTHS = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];
  var MONTHS_GEN = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
  var WD = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
  var STATUS = { 'new': 'Новая', confirmed: 'Подтверждена', cancelled: 'Отменена', completed: 'Завершена' };
  var DEPOSIT = { not_set: 'Не назначен', pending: 'Ожидается', paid: 'Внесён', returned: 'Возвращён' };
  var SOURCE = { site: 'Сайт', pwa: 'Приложение', max: 'MAX', admin: 'Администратор (по телефону)', migrated: 'Перенесена из прежних заявок' };

  // ---------------------------------------------------------------- помощники
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(n) { return String(Math.round(n || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ₽'; }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function toMin(t) { return +String(t).slice(0, 2) * 60 + +String(t).slice(3, 5); }
  function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parse(s) { var p = String(s).split('-'); return new Date(+p[0], +p[1] - 1, +p[2], 12); }
  function addDays(s, n) { var d = parse(s); d.setDate(d.getDate() + n); return iso(d); }
  function human(s, year) { var d = parse(s); return d.getDate() + ' ' + MONTHS_GEN[d.getMonth()] + (year ? ' ' + d.getFullYear() : ''); }
  function nightsBetween(a, b) { var out = []; for (var d = a; d < b; d = addDays(d, 1)) out.push(d); return out; }
  function plural(n, one, few, many) {
    var m = n % 10, h = n % 100;
    return n + ' ' + (m === 1 && h !== 11 ? one : (m >= 2 && m <= 4 && (h < 10 || h >= 20) ? few : many));
  }
  function qs(root, sel) { return root.querySelector(sel); }
  function qsa(root, sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }

  /* Запрос к серверу: гость и приложение — api.php (администратор с токеном Bearer), админка MODX — connector.php */
  function Client(endpoint, opts) {
    this.endpoint = endpoint;
    this.opts = opts || {};
  }
  Client.prototype.call = function (action, params, method) {
    var o = this.opts;
    var body = Object.assign({ action: action }, params || {});
    if (o.client) body.client = o.client;
    var headers = { 'Accept': 'application/json' };
    if (o.token) { headers['Authorization'] = 'Bearer ' + o.token; headers['X-Booking-Token'] = o.token; }
    if (o.modauth) headers['modAuth'] = o.modauth;
    var url = this.endpoint, init = { credentials: 'same-origin', cache: 'no-store', headers: headers };
    if ((method || 'POST') === 'GET') {
      url += (url.indexOf('?') < 0 ? '?' : '&') + new URLSearchParams(body).toString();
      init.method = 'GET';
    } else {
      init.method = 'POST';
      headers['Content-Type'] = 'application/json';
      if (o.modauth) body.HTTP_MODAUTH = o.modauth;
      init.body = JSON.stringify(body);
    }
    return fetch(url, init).then(function (r) {
      return r.json().catch(function () { return { success: false, message: 'Сервер не ответил', data: {} }; })
        .then(function (j) { j.status = r.status; return j; });
    }, function () {
      return { success: false, status: 0, offline: true, message: 'Нет связи с сервером. Занятость дат не показывается — проверьте интернет.', data: {} };
    });
  };

  // ---------------------------------------------------------------- календарь (одна реализация для гостя и администратора)
  /* Месячный календарь ночей. busy — занятые ночи (сутки с заездом в эту дату).
     Выбор: первая дата — заезд (свободная ночь), вторая — выезд; все ночи между ними должны быть свободны. */
  function MonthCalendar(el, opts) {
    this.el = el;
    this.months = opts.months || 1;
    this.single = !!opts.single;
    this.bath = false;   // режим «Только баня»: свободные дни зелёные (по часам), занятые весь день — красные
    this.onRange = opts.onRange;
    this.onMonth = opts.onMonth;
    this.busy = {};
    this.today = opts.today || iso(new Date());
    var t = parse(this.today);
    this.view = new Date(t.getFullYear(), t.getMonth(), 1, 12);
    this.from = null;
    this.to = null;
    this.loading = false;
    this.offline = false;
    var self = this;
    el.addEventListener('click', function (e) {
      var nav = e.target.closest('[data-cal-nav]');
      if (nav) { self.shift(+nav.getAttribute('data-cal-nav')); return; }
      var day = e.target.closest('[data-day]');
      if (day && !day.disabled) self.pick(day.getAttribute('data-day'));
    });
  }
  MonthCalendar.prototype.range = function () {
    var a = this.view, b = new Date(a.getFullYear(), a.getMonth() + this.months, 1, 12);
    return { from: iso(a), to: iso(b) };
  };
  MonthCalendar.prototype.shift = function (n) {
    var v = new Date(this.view.getFullYear(), this.view.getMonth() + n, 1, 12);
    var t = parse(this.today);
    if (v < new Date(t.getFullYear(), t.getMonth(), 1, 12)) return;
    this.view = v;
    this.render();
    if (this.onMonth) this.onMonth(this.range());
  };
  MonthCalendar.prototype.setBusy = function (list, offline) {
    this.busy = {};
    (list || []).forEach(function (d) { this.busy[d] = true; }, this);
    this.offline = !!offline;
    this.loading = false;
    // выбранный диапазон стал занят — сбрасываем
    if (this.from && this.to && nightsBetween(this.from, this.to).some(function (n) { return this.busy[n]; }, this)) {
      this.from = this.to = null;
      if (this.onRange) this.onRange(null, null, 'Выбранные даты уже заняты — выберите другие');
    }
    this.render();
  };
  MonthCalendar.prototype.free = function (a, b) {
    return !nightsBetween(a, b).some(function (n) { return this.busy[n]; }, this);
  };
  MonthCalendar.prototype.pick = function (d) {
    var msg = null;
    if (this.single) {
      this.from = d; this.to = null;
    } else if (!this.from || this.to || d <= this.from) {
      if (this.busy[d]) return;
      this.from = d; this.to = null;
    } else if (this.free(this.from, d)) {
      this.to = d;
    } else {
      msg = 'Между этими датами есть занятые ночи';
      this.from = this.busy[d] ? null : d; this.to = null;
    }
    this.render();
    if (this.onRange) this.onRange(this.from, this.to, msg);
  };
  MonthCalendar.prototype.render = function () {
    var html = '<div class="bk-cal__head"><button type="button" class="bk-cal__nav" data-cal-nav="-1" aria-label="Предыдущий месяц">‹</button>'
      + '<button type="button" class="bk-cal__nav" data-cal-nav="1" aria-label="Следующий месяц">›</button></div><div class="bk-cal__months">';
    var choosingOut = !this.single && this.from && !this.to;
    // первая занятая ночь после заезда может быть днём выезда
    var firstBusyAfter = null;
    if (choosingOut) {
      for (var d = this.from, i = 0; i < 400; i++, d = addDays(d, 1)) { if (this.busy[d]) { firstBusyAfter = d; break; } }
    }
    for (var m = 0; m < this.months; m++) {
      var first = new Date(this.view.getFullYear(), this.view.getMonth() + m, 1, 12);
      var days = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
      var lead = (first.getDay() + 6) % 7;
      html += '<div class="bk-cal__month"><div class="bk-cal__title">' + MONTHS[first.getMonth()] + ' ' + first.getFullYear() + '</div>'
        // без role=grid: внутри кнопки с подписями дат, а не строки и ячейки таблицы
        + '<div class="bk-cal__grid">' + WD.map(function (w) { return '<span class="bk-cal__wd">' + w + '</span>'; }).join('');
      for (var k = 0; k < lead; k++) html += '<span></span>';
      for (var day = 1; day <= days; day++) {
        var s = iso(new Date(first.getFullYear(), first.getMonth(), day, 12));
        var past = s < this.today;
        var busy = !!this.busy[s];
        var cls = ['bk-cal__day'];
        var disabled = past || this.loading || this.offline;
        if (busy) cls.push('is-busy');
        if (past) cls.push('is-past');
        if (!disabled && busy) {
          // занятый день можно выбрать только как выезд
          disabled = !(choosingOut && firstBusyAfter === s && s > this.from);
        }
        if (choosingOut && firstBusyAfter && s > firstBusyAfter) disabled = true;
        if (this.from === s) cls.push('is-from');
        if (this.to === s) cls.push('is-to');
        if (this.from && this.to && s > this.from && s < this.to) cls.push('is-in');
        var state = this.bath ? (busy ? ', баня занята весь день' : (past ? '' : ', баня свободна')) : (busy ? ', занято' : '');
        html += '<button type="button" class="' + cls.join(' ') + '" data-day="' + s + '"' + (disabled ? ' disabled' : '')
          + ' aria-label="' + human(s, true) + state + '">' + day + '</button>';
      }
      html += '</div></div>';
    }
    html += '</div><div class="bk-cal__legend">' + (this.bath
      ? '<span><i class="bk-sw bk-sw--bathfree"></i>баня свободна — по часам</span><span><i class="bk-sw bk-sw--bathbusy"></i>занята весь день</span><span><i class="bk-sw bk-sw--pick"></i>ваш день</span>'
      : '<span><i class="bk-sw bk-sw--free"></i>свободно</span><span><i class="bk-sw bk-sw--busy"></i>занято</span><span><i class="bk-sw bk-sw--pick"></i>ваши даты</span>')
      + '</div>';
    this.el.classList.toggle('bk-cal--bath', this.bath);
    if (this.offline) html += '<p class="bk-warn">Нет связи с сервером — занятость не показывается. Проверьте интернет и обновите.</p>';
    else if (this.loading) html += '<p class="bk-muted">Загружаем занятость…</p>';
    this.el.innerHTML = html;
  };

  // ---------------------------------------------------------------- форма гостя
  function GuestForm(form) {
    var self = this;
    this.form = form;
    this.api = new Client(form.getAttribute('data-bk-api'), { client: (qs(form, '[name=client]') || {}).value || 'site' });
    this.houses = JSON.parse(form.getAttribute('data-bk-houses') || '[]');
    this.today = form.getAttribute('data-bk-today');
    this.compact = form.getAttribute('data-bk-mode') === 'compact';
    this.el = {
      house: qs(form, '[name=house]'), from: qs(form, '[name=date_from]'), to: qs(form, '[name=date_to]'),
      addon: qsa(form, '[name=addon]'), tf: qs(form, '[name=time_from]'), tt: qs(form, '[name=time_to]'),
      guests: qs(form, '[name=guests]'), pets: qs(form, '[name=pets]'), phone: qs(form, '[name=phone]'),
      cal: qs(form, '[data-bk-cal]'), dates: qs(form, '[data-bk-dates]'), quote: qs(form, '[data-bk-quote]'),
      bath: qs(form, '[data-bk-bath]'), kind: qs(form, '[data-bk-kind]'), slots: qs(form, '[data-bk-slots]'),
      dateErr: qs(form, '[data-si-error="dates"]'), done: qs(form.parentNode, '[data-bk-done]'), body: qs(form, '[data-bk-body]'),
      times: qs(form, '[data-bk-times]')
    };
    this.cal = new MonthCalendar(this.el.cal, {
      months: this.compact ? 1 : (window.matchMedia('(max-width: 760px)').matches ? 1 : 2),
      today: this.today,
      onRange: function (a, b, msg) { self.onRange(a, b, msg); },
      onMonth: function () { self.loadBusy(); }
    });
    if (this.compact) {
      this.el.cal.hidden = true;
      this.el.dates.addEventListener('click', function () { self.el.cal.hidden = !self.el.cal.hidden; });
    }
    this.el.house.addEventListener('change', function () { self.onHouse(); });
    [this.el.guests, this.el.pets, this.el.tf, this.el.tt].forEach(function (i) { if (i) i.addEventListener('change', function () { self.quote(); }); });
    this.el.addon.forEach(function (r) { r.addEventListener('change', function () { self.onKind(); }); });
    if (this.el.tf) this.el.tf.addEventListener('change', function () { self.fitTimes(); self.quote(); });   // «До» — по новому «С»
    if (this.el.phone) this.el.phone.addEventListener('input', function () { maskPhone(self.el.phone); });
    /* Проверка полей сразу при нажатии — до SendIt: он сначала решает задачу защиты от ботов (секунды на телефоне)
       и только потом показывает ошибки сервера. Обработчик формы срабатывает раньше обработчика SendIt на document. */
    var btn = qs(form, '[type=submit]'), btnText = btn ? btn.textContent : '';
    this.busy = function (on) {
      self.sending = on;
      clearTimeout(self.busyT);
      if (on) self.busyT = setTimeout(function () { self.busy(false); }, 30000);   // нет связи — SendIt ответа не дождался
      if (!btn) return;
      btn.disabled = on;
      btn.textContent = on ? 'Отправляем…' : btnText;
    };
    form.addEventListener('submit', function (e) {
      var bad = self.sending ? null : self.check();
      if (self.sending || bad) {
        e.preventDefault();
        e.stopPropagation();   // SendIt не запускаем: ошибка уже видна (или отправка уже идёт)
        if (bad) { bad.scrollIntoView({ block: 'center', behavior: 'smooth' }); if (bad.focus) bad.focus({ preventScroll: true }); }
        return;
      }
      self.busy(true);
    });
    qsa(form, 'input, select, textarea').forEach(function (i) {
      i.addEventListener(i.type === 'checkbox' || i.tagName === 'SELECT' ? 'change' : 'input', function () { self.fieldError(i.name, ''); });
    });
    ['si:send:finish', 'si:send:success', 'si:send:error'].forEach(function (n) {
      document.addEventListener(n, function (e) { if (self.owns(e)) self.busy(false); });
    });
    // перед отправкой SendIt: даты выбраны?
    document.addEventListener('si:send:before', function (e) {
      if (!self.owns(e)) return;
      if (!self.el.from.value || (!self.isBath() && !self.el.to.value)) {
        e.preventDefault();
        self.busy(false);
        self.error(self.isBath() ? 'Выберите дату и время бани' : 'Выберите даты заезда и выезда');
      }
    });
    document.addEventListener('si:send:success', function (e) { if (self.owns(e)) self.sent(false); });
    document.addEventListener('si:send:error', function (e) { if (self.owns(e)) self.sent(true, e); });
    this.onHouse();
  }
  GuestForm.prototype.owns = function (e) {
    var t = e.detail && e.detail.target;
    return t && (t === this.form || (t.closest && t.closest('form') === this.form));
  };
  GuestForm.prototype.house = function () {
    var code = this.el.house.value;
    return this.houses.filter(function (h) { return h.code === code; })[0] || null;
  };
  GuestForm.prototype.isBath = function () {
    var r = this.el.addon.filter(function (x) { return x.checked; })[0];
    return !!(r && r.value === '1');
  };
  GuestForm.prototype.fieldError = function (name, msg) {
    var b = qs(this.form, '[data-si-error="' + name + '"]'), f = qs(this.form, '[name="' + name + '"]');
    if (b) b.textContent = msg;
    if (f) { if (msg) f.setAttribute('aria-invalid', 'true'); else f.removeAttribute('aria-invalid'); }
  };
  /* Те же правила, что у пресета SendIt и сервера (окончательно проверяет сервер); возвращает первое неверное поле */
  GuestForm.prototype.check = function () {
    var self = this, first = null, f = this.form;
    var bad = function (name, msg, el) { self.fieldError(name, msg); first = first || el || qs(f, '[name="' + name + '"]'); };
    if (!this.el.house.value) bad('house', 'Выберите Дом');
    if (!this.el.from.value || (!this.isBath() && !this.el.to.value)) {
      this.error(this.isBath() ? 'Выберите дату и время бани' : 'Выберите даты заезда и выезда');
      first = first || this.el.dates || this.el.cal;
    } else this.error('');
    var g = this.el.guests;
    if (g && !(+g.value >= 1)) bad('guests', 'Укажите, сколько будет гостей');
    else if (g && g.max && +g.value > +g.max) bad('guests', 'В этом Доме — до ' + g.max + ' гостей');
    var name = qs(f, '[name=name]');
    if (name && !name.value.trim()) bad('name', 'Как к вам обращаться?');
    if (this.el.phone && this.el.phone.value.replace(/\D/g, '').length < 10) bad('phone', 'Укажите телефон: администратор перезвонит');
    var agree = qs(f, '[name=agree]');
    if (agree && !agree.checked) bad('agree', 'Нужно согласие на обработку данных');
    return first;
  };
  GuestForm.prototype.error = function (msg) {
    if (this.el.dateErr) { this.el.dateErr.textContent = msg || ''; }
  };
  GuestForm.prototype.onHouse = function () {
    var h = this.house();
    if (this.el.kind) this.el.kind.hidden = !(h && h.addon);
    if (!(h && h.addon)) this.el.addon.forEach(function (r) { r.checked = r.value === '0'; });
    if (h && this.el.guests) {
      this.el.guests.max = h.capacity_party;
      this.el.guests.placeholder = 'до ' + h.capacity_party;
      if (+this.el.guests.value > h.capacity_party) this.el.guests.value = h.capacity_party;
    }
    this.onKind();
  };
  GuestForm.prototype.onKind = function () {
    var bath = this.isBath();
    this.cal.single = bath;
    this.cal.bath = bath;
    this.cal.from = this.cal.to = null;
    this.el.from.value = this.el.to.value = '';
    if (this.el.bath) this.el.bath.hidden = !bath;
    if (bath) this.fillTimes();
    this.setDatesText();
    this.loadBusy();
    this.quote();
  };
  GuestForm.prototype.fillTimes = function () {
    var h = this.house();
    if (!h || !h.addon || !this.el.tf) return;
    var opts = '', opts2 = '';
    for (var m = 8 * 60; m <= 23 * 60; m += 30) {
      var t = pad(Math.floor(m / 60)) + ':' + pad(m % 60);
      if (m <= 22 * 60) opts += '<option>' + t + '</option>';
      if (m >= 8 * 60 + h.addon.min_hours * 60) opts2 += '<option>' + t + '</option>';
    }
    this.el.tf.innerHTML = opts;
    this.el.tt.innerHTML = opts2;
    this.el.tf.value = '15:00';
    this.el.tt.value = pad(15 + h.addon.min_hours) + ':00';
    this.busySlots = []; this.buf = 0;
  };
  /* сеанс [a, b) в минутах задевает занятый интервал с перерывом на уборку? (сервер проверяет так же) */
  GuestForm.prototype.clash = function (a, b) {
    var buf = this.buf || 0;
    return (this.busySlots || []).some(function (s) { var p = s.blocked || s.nopad ? 0 : buf; return a < toMin(s.to) + p && b > toMin(s.from) - p; });   // как на сервере
  };
  /* занятые часы недоступны в «С» и «До»; выбранное время, если оно занято, — на первое свободное */
  GuestForm.prototype.fitTimes = function () {
    var self = this, h = this.house(), tf = this.el.tf, tt = this.el.tt;
    if (!h || !h.addon || !tf || !tt) return;
    var min = h.addon.min_hours * 60, last = 23 * 60;
    [].forEach.call(tf.options, function (o) { var m = toMin(o.value); o.disabled = m + min > last || self.clash(m, m + min); });
    var cur = tf.options[tf.selectedIndex];
    if (!cur || cur.disabled) {   // ближайшее свободное к выбранному (при равенстве — позже)
      var want = cur ? toMin(cur.value) : 15 * 60, best = null;
      [].forEach.call(tf.options, function (o) {
        if (o.disabled) return;
        var d = toMin(o.value) - want, k = Math.abs(d) - (d > 0 ? 0.5 : 0);
        if (!best || k < best.k) best = { v: o.value, k: k };
      });
      if (best) tf.value = best.v;
    }
    var from = toMin(tf.value), anyFree = [].some.call(tf.options, function (o) { return !o.disabled; });
    [].forEach.call(tt.options, function (o) { var m = toMin(o.value); o.disabled = !anyFree || m < from + min || self.clash(from, m); });
    var end = tt.options[tt.selectedIndex];
    if (!end || end.disabled) {
      var ok = [].filter.call(tt.options, function (o) { return !o.disabled; })[0];
      if (ok) tt.value = ok.value;
    }
    var err = qs(this.form, '[data-si-error="time"]');
    if (err) err.textContent = anyFree ? '' : 'На этот день свободного времени нет — выберите другой день';
  };
  GuestForm.prototype.loadBusy = function () {
    var self = this, h = this.house();
    this.cal.busy = {};
    if (!h) { this.cal.loading = false; this.cal.render(); return; }
    this.cal.loading = true;
    this.cal.render();
    var r = this.cal.range();
    var req = ++this.req;
    this.api.call('booking_get_availability', { house: h.code, from: r.from, to: r.to }, 'GET').then(function (res) {
      if (req !== self.req) return;
      if (!res.success) { self.cal.setBusy([], !!res.offline); if (!res.offline) self.error(res.message); return; }
      self.cal.setBusy(self.isBath() ? res.data.busy_bath : res.data.busy);
    });
  };
  GuestForm.prototype.req = 0;
  GuestForm.prototype.onRange = function (a, b, msg) {
    var self = this;
    this.el.from.value = a || '';
    this.el.to.value = this.isBath() ? (a || '') : (b || '');
    this.error(msg || '');
    this.setDatesText();
    if (this.isBath() && a) this.loadSlots(a);
    if (this.compact && a && (b || this.isBath())) setTimeout(function () { self.el.cal.hidden = true; }, 250);
    this.quote();
  };
  GuestForm.prototype.setDatesText = function () {
    var a = this.el.from.value, b = this.el.to.value, h = this.house(), t;
    if (this.isBath()) t = a ? human(a) : 'Выберите дату';
    else if (a && b && a !== b) t = human(a) + ' — ' + human(b) + ', ' + plural(nightsBetween(a, b).length, 'сутки', 'суток', 'суток')
      + (h ? ' · заезд ' + h.checkin + ', выезд ' + h.checkout : '');
    else if (a) t = 'Заезд ' + human(a) + ' — выберите выезд';
    else t = this.compact ? 'Выбрать даты' : 'Выберите дату заезда';
    if (this.el.dates) this.el.dates.textContent = t;
  };
  GuestForm.prototype.loadSlots = function (date) {
    var self = this, h = this.house();
    if (!h || !this.el.slots) return;
    this.el.slots.textContent = 'Проверяем время…';
    this.api.call('booking_get_availability', { house: h.code, addon: 1, date: date }, 'GET').then(function (res) {
      if (!res.success) { self.el.slots.textContent = res.message; return; }
      var d = res.data;
      self.busySlots = d.day_busy ? [{ from: '00:00', to: '24:00' }] : d.slots_busy; self.buf = d.buffer_minutes || 0;
      self.fitTimes(); self.quote();
      if (d.day_busy) { self.el.slots.innerHTML = '<span class="bk-warn">В этот день Дом забронирован — баня занята</span>'; return; }
      self.el.slots.innerHTML = (d.slots_busy.length
        ? 'Занято: ' + d.slots_busy.map(function (s) {
            return s.house ? (s.from === '00:00' ? (s.to === '24:00' ? 'весь день — в Доме гости' : 'до ' + esc(s.to) + ' — выезд гостей Дома и уборка') : 'с ' + esc(s.from) + ' — заезд гостей Дома') : esc(s.from + '–' + s.to);
          }).join(', ') + '. Между сеансами ' + d.buffer_minutes + ' мин на уборку.'
        : 'Баня свободна весь день.') + ' Время подтвердит администратор, когда перезвонит.';
    });
  };
  GuestForm.prototype.quote = function () {
    var self = this, h = this.house(), q = this.el.quote;
    if (!q) return;
    var p = { house: h ? h.code : '', date_from: this.el.from.value, date_to: this.el.to.value, guests: this.el.guests.value || 1, pets: this.el.pets ? this.el.pets.value || 0 : 0 };
    if (this.isBath()) { p.addon = 1; p.time_from = this.el.tf.value; p.time_to = this.el.tt.value; }
    if (!h || !p.date_from || (!p.addon && !p.date_to)) { q.innerHTML = ''; return; }
    var req = ++this.qreq;
    q.innerHTML = '<p class="bk-muted">Считаем…</p>';
    this.api.call('booking_quote', p, 'GET').then(function (res) {
      if (req !== self.qreq) return;
      if (!res.success) { q.innerHTML = '<p class="bk-warn">' + esc(res.message) + '</p>'; return; }
      q.innerHTML = renderQuote(res.data, self.isBath());
    });
  };
  GuestForm.prototype.qreq = 0;
  /* Цель Яндекс Метрики по месту заявки (счётчик подключает cookies.js сайта после согласия на cookie):
     lead — любая заявка; lead_booking — страница /booking, lead_house — страница Дома, lead_home — главная, lead_other — прочие */
  GuestForm.prototype.goal = function () {
    if (!window.ym || !window.BN_YM) return;
    var place = this.form.getAttribute('data-bk-mode') === 'full' ? 'booking'
      : (document.getElementById('d-title') ? 'house' : (document.getElementById('prices') ? 'home' : 'other'));
    window.ym(window.BN_YM, 'reachGoal', 'lead', { place: place });
    window.ym(window.BN_YM, 'reachGoal', 'lead_' + place);
  };
  /* Ответ SendIt: при успехе показываем номер и ссылку. При ошибке проверяем — вдруг заявка сохранилась,
     а не ушло только письмо (FR-029): тогда это тоже успех для гостя. */
  GuestForm.prototype.sent = function (isError) {
    var self = this;
    this.api.call('booking_get_request', { mine: 1 }, 'GET').then(function (res) {
      if (!res.success || !res.data.found) return;
      var d = res.data;
      if (isError && (self.lastId === d.id || !self.fresh(d))) return;
      self.lastId = d.id;
      self.showDone(d);
    });
  };
  /* заявка создана этой отправкой: новая и не старше двух минут (хук пишет время в сессию) */
  GuestForm.prototype.fresh = function (d) { return d.status === 'new' && d.age != null && d.age < 120; };
  GuestForm.prototype.showDone = function (d) {
    this.goal();
    var box = this.el.done;
    if (!box) return;
    box.innerHTML = '<h3>Заявка №' + esc(d.id) + ' отправлена</h3>'
      + '<p>' + esc(d.house_name) + ', ' + esc(d.dates) + ', ' + plural(d.guests, 'гость', 'гостя', 'гостей') + ' · ' + money(d.total) + '</p>'
      + '<p>Администратор перезвонит и подтвердит бронь. Даты станут вашими после подтверждения.</p>'
      + (d.link ? '<p><a class="bk-link" href="' + esc(d.link) + '">Ссылка на заявку</a> — сохраните её: там видно статус и можно попросить отмену.</p>' : '')
      + '<button type="button" class="bk-btn bk-btn--ghost" data-bk-again>Отправить ещё одну заявку</button>';
    box.hidden = false;
    this.form.hidden = true;
    var self = this;
    qs(box, '[data-bk-again]').addEventListener('click', function () { box.hidden = true; self.form.hidden = false; self.loadBusy(); });
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };

  function renderQuote(d, bath) {   // bath — «Только баня»: заезд и выезд Дома не показываем
    var rows = d.lines.map(function (l) {
      return '<tr' + (l.amount < 0 ? ' class="bk-quote__disc"' : '') + '><td>' + esc(l.label) + '</td><td>' + (l.amount < 0 ? '−' + money(-l.amount) : money(l.amount)) + '</td></tr>';
    }).join('');
    return '<table class="bk-quote__t"><tbody>' + rows + '</tbody><tfoot><tr><th>Итого</th><th>' + money(d.total) + '</th></tr></tfoot></table>'
      + (d.checkin && !bath ? '<p class="bk-muted">Заезд с ' + esc(d.checkin) + ', выезд до ' + esc(d.checkout) + '</p>' : '');
  }

  function maskPhone(input) {
    var d = input.value.replace(/\D/g, '');
    if (d[0] === '8') d = '7' + d.slice(1);
    if (d && d[0] !== '7') d = '7' + d;
    d = d.slice(0, 11);
    var out = d ? '+7' : '';
    if (d.length > 1) out += ' (' + d.slice(1, 4);
    if (d.length >= 4) out += ')';
    if (d.length > 4) out += ' ' + d.slice(4, 7);
    if (d.length > 7) out += '-' + d.slice(7, 9);
    if (d.length > 9) out += '-' + d.slice(9, 11);
    input.value = out;
  }

  // ---------------------------------------------------------------- моя заявка
  function RequestPage(root) {
    this.root = root;
    this.api = new Client(root.getAttribute('data-bk-api'), { client: root.getAttribute('data-bk-client') || 'site' });
    this.id = root.getAttribute('data-bk-id');
    this.t = root.getAttribute('data-bk-t');
    var self = this;
    var find = qs(root, '[data-bk-find]');
    if (find) find.addEventListener('submit', function (e) {
      e.preventDefault();
      self.id = qs(find, '[name=id]').value.replace(/\D/g, '');
      self.phone = qs(find, '[name=phone]').value;
      self.t = '';
      self.load();
    });
    var ph = qs(root, '[name=phone]');
    if (ph) ph.addEventListener('input', function () { maskPhone(ph); });
    if (this.id && this.t) this.load();
  }
  RequestPage.prototype.creds = function () {
    return this.t ? { id: this.id, t: this.t } : { id: this.id, phone: this.phone };
  };
  RequestPage.prototype.load = function () {
    var self = this, out = qs(this.root, '[data-bk-result]');
    out.innerHTML = '<p class="bk-muted">Загружаем…</p>';
    this.api.call('booking_get_request', this.creds(), 'GET').then(function (res) {
      if (!res.success) { out.innerHTML = '<p class="bk-warn">' + esc(res.message) + '</p>'; return; }
      self.render(res.data);
    });
  };
  RequestPage.prototype.render = function (d) {
    var self = this, out = qs(this.root, '[data-bk-result]');
    var active = (d.status === 'new' || d.status === 'confirmed') && !d.cancellation_requested;
    var canCancel = active && !!d.code;   // отмена — только по личной ссылке (код); найденную по телефону заявку можно лишь посмотреть
    out.innerHTML = '<div class="bk-card">'
      + '<h3>Заявка №' + esc(d.id) + ' <span class="bk-badge bk-badge--' + esc(d.status) + '">' + esc(d.status_label) + '</span></h3>'
      + '<dl class="bk-dl"><dt>Дом</dt><dd>' + esc(d.house_name) + (d.addon ? ', баня' : '') + '</dd>'
      + '<dt>Даты</dt><dd>' + esc(d.dates) + (d.addon ? '' : ' · заезд с ' + esc(d.checkin) + ', выезд до ' + esc(d.checkout)) + '</dd>'
      + '<dt>Гостей</dt><dd>' + esc(d.guests) + (d.pets ? ', питомцев: ' + esc(d.pets) : '') + '</dd>'
      + '<dt>Сумма</dt><dd>' + money(d.total) + '</dd>'
      + (d.deposit_amount ? '<dt>Залог</dt><dd>' + money(d.deposit_amount) + ' — ' + esc(d.deposit_label) + '</dd>' : '')
      + '</dl>'
      + (d.status === 'new' ? '<p class="bk-muted">Администратор перезвонит и подтвердит бронь. До подтверждения даты могут занять другие гости.</p>' : '')
      + (d.cancellation_requested ? '<p class="bk-note-orange">Вы попросили отменить бронь — администратор свяжется с вами.</p>' : '')
      + (canCancel ? '<form class="bk-cancel" data-bk-cancel><label class="bk-field"><span>Почему отменяете (необязательно)</span><textarea name="reason" rows="2" maxlength="1000"></textarea></label>'
        + '<button class="bk-btn bk-btn--ghost" type="submit">Попросить отменить</button></form>' : '')
      + (active && !canCancel ? '<p class="bk-muted">Отменить бронь можно по ссылке на заявку из письма или сообщения — или позвоните администратору.</p>' : '')
      + '</div>';
    var f = qs(out, '[data-bk-cancel]');
    if (f) f.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!window.confirm('Попросить администратора отменить заявку №' + d.id + '?')) return;
      var p = self.creds();
      p.reason = qs(f, '[name=reason]').value;
      f.querySelector('button').disabled = true;
      self.api.call('booking_request_cancel', p).then(function (res) {
        if (!res.success) { window.alert(res.message); f.querySelector('button').disabled = false; return; }
        self.render(res.data);
      });
    });
  };

  // ---------------------------------------------------------------- администратор: сетка и карточки, справочники
  var TABS = {
    grid: 'Календарь', list: 'Заявки', stats: 'Статистика', houses: 'Дома и бани', prices: 'Цены', holidays: 'Праздники',
    blocks: 'Закрытые даты', tokens: 'Токены', migrate: 'Перенос заявок', help: 'Инструкция'
  };

  function Admin(root, client) {
    this.root = root;
    (Admin.all = Admin.all || []).push(this);
    this.api = client || new Client(root.getAttribute('data-bk-endpoint'), { modauth: root.getAttribute('data-bk-modauth') });
    this.tabs = (root.getAttribute('data-bk-tabs') || 'grid,list').split(',').filter(function (t) { return TABS[t]; });   // неизвестную вкладку не показываем
    this.houses = [];
    var t = new Date();
    this.gridFrom = iso(new Date(t.getFullYear(), t.getMonth(), t.getDate() - 2, 12));
    this.gridDays = window.matchMedia('(max-width: 760px)').matches ? 14 : 31;
    this.listFilter = { status: 'new,confirmed', house: '', q: '', from: '', to: '', page: 1 };
    var self = this;
    root.innerHTML = '<nav class="bk-tabs" role="tablist">' + this.tabs.map(function (k) {
      return '<button type="button" role="tab" class="bk-tab" data-tab="' + k + '">' + TABS[k] + '</button>';
    }).join('') + '</nav><div class="bk-msg" role="status" aria-live="polite"></div><div class="bk-pane"></div>'
      + '<dialog class="bk-dialog"><div class="bk-dialog__box"><button type="button" class="bk-dialog__x" data-close aria-label="Закрыть">×</button><div class="bk-dialog__body"></div></div></dialog>';
    this.pane = qs(root, '.bk-pane');
    this.dialog = qs(root, '.bk-dialog');
    this.msgEl = qs(root, '.bk-msg');
    root.addEventListener('click', function (e) {
      var tab = e.target.closest('[data-tab]');
      if (tab) { self.show(tab.getAttribute('data-tab')); return; }
      if (e.target.closest('[data-close]')) { self.dialog.close(); return; }
      var open = e.target.closest('[data-open]');
      if (open) { self.card(+open.getAttribute('data-open')); }
    });
    this.dialog.addEventListener('click', function (e) { if (e.target === self.dialog) self.dialog.close(); });
    this.api.call('booking_manage_house', { op: 'list' }).then(function (res) {
      if (res.success) self.houses = res.data.items;
      var hash = /id=(\d+)/.exec(location.hash);
      self.show(self.tabs[0]);
      if (hash) self.card(+hash[1]);
    });
  }
  Admin.prototype.msg = function (text, bad) {
    this.msgEl.textContent = text || '';
    this.msgEl.className = 'bk-msg' + (bad ? ' is-bad' : (text ? ' is-ok' : ''));
    var el = this.msgEl;
    clearTimeout(this.msgT);
    if (text && !bad) this.msgT = setTimeout(function () { el.textContent = ''; el.className = 'bk-msg'; }, 5000);
  };
  Admin.prototype.fail = function (res) {
    if (res.status === 401) this.msg('Нужен вход администратора: ' + res.message, true);
    else this.msg(res.message || 'Ошибка', true);
  };
  Admin.prototype.show = function (tab) {
    this.current = tab;
    qsa(this.root, '[data-tab]').forEach(function (b) { b.setAttribute('aria-selected', b.getAttribute('data-tab') === tab ? 'true' : 'false'); });
    this.msg('');
    this['tab_' + tab]();
  };
  Admin.prototype.houseOptions = function (sel, empty) {
    return (empty ? '<option value="">' + empty + '</option>' : '') + this.houses.map(function (h) {
      return '<option value="' + esc(h.code) + '"' + (h.code === sel ? ' selected' : '') + '>' + esc(h.name) + '</option>';
    }).join('');
  };
  Admin.prototype.form = function (el, fn) {
    var self = this;
    el.addEventListener('submit', function (e) {
      e.preventDefault();
      var data = {};
      qsa(el, '[name]').forEach(function (i) {
        if (i.type === 'checkbox') data[i.name] = i.checked ? 1 : 0;
        else if (i.type === 'radio') { if (i.checked) data[i.name] = i.value; }
        else data[i.name] = i.value;
      });
      var btn = el.querySelector('[type=submit]');
      if (btn) btn.disabled = true;
      Promise.resolve(fn(data, e.submitter)).then(function () { if (btn) btn.disabled = false; });
    });
  };

  // --- календарь-сетка: строки — дома и бани, столбцы — даты (docs/04)
  Admin.prototype.tab_grid = function () {
    var self = this;
    var to = addDays(this.gridFrom, this.gridDays);
    var MAXD = 92;   // столько дней сервер отдаёт за раз (Bookings::MAX_RANGE_DAYS)
    var spans = [[7, 'Неделя'], [14, '2 недели'], [31, 'Месяц'], [92, '3 месяца']];
    // период: стрелки листают на его длину, «с» и «по» — любые даты, кнопки — длина одним нажатием
    this.pane.innerHTML = '<div class="bk-bar bk-bar--period"><button type="button" class="bk-btn bk-btn--sm bk-btn--ghost" data-g="-1" aria-label="Предыдущий период">‹ назад</button>'
      + '<button type="button" class="bk-btn bk-btn--sm bk-btn--ghost" data-g="0">сегодня</button>'
      + '<button type="button" class="bk-btn bk-btn--sm bk-btn--ghost" data-g="1" aria-label="Следующий период">вперёд ›</button>'
      + '<label class="bk-period">с <input type="date" data-gfrom value="' + esc(this.gridFrom) + '"></label>'
      + '<label class="bk-period">по <input type="date" data-gto value="' + esc(addDays(to, -1)) + '"></label>'
      + '<span class="bk-seg" role="group" aria-label="Длина периода">' + spans.map(function (s) {
        return '<button type="button" class="bk-btn bk-btn--sm ' + (s[0] === self.gridDays ? '' : 'bk-btn--ghost') + '" data-gd="' + s[0] + '" aria-pressed="' + (s[0] === self.gridDays) + '">' + s[1] + '</button>';
      }).join('') + '</span></div>'
      + '<div class="bk-bar"><span class="bk-legend"><i class="bk-sw bk-sw--ok"></i>подтверждена <i class="bk-sw bk-sw--new"></i>новые заявки <i class="bk-sw bk-sw--cancel"></i>просят отмену <i class="bk-sw bk-sw--block"></i>закрыто</span></div>'
      + '<div class="bk-gridwrap"><p class="bk-muted">Загружаем…</p></div>';
    qsa(this.pane, '[data-g]').forEach(function (b) {
      b.addEventListener('click', function () {
        var n = +b.getAttribute('data-g');
        if (n === 0) { var t = new Date(); self.gridFrom = iso(new Date(t.getFullYear(), t.getMonth(), t.getDate() - 2, 12)); }
        else self.gridFrom = addDays(self.gridFrom, n * self.gridDays);
        self.tab_grid();
      });
    });
    qsa(this.pane, '[data-gd]').forEach(function (b) {
      b.addEventListener('click', function () { self.gridDays = +b.getAttribute('data-gd'); self.tab_grid(); });
    });
    qs(this.pane, '[data-gfrom]').addEventListener('change', function (e) {
      if (!e.target.value) return;
      self.gridFrom = e.target.value;
      self.tab_grid();
    });
    qs(this.pane, '[data-gto]').addEventListener('change', function (e) {
      if (!e.target.value) return;
      var days = Math.round((parse(e.target.value) - parse(self.gridFrom)) / 864e5) + 1;
      if (days < 1) { self.gridFrom = e.target.value; days = 1; }   // «по» раньше «с» — период с этого дня
      if (days > MAXD) { window.alert('За один раз — не больше ' + MAXD + ' дней (3 месяца). Показываю первые ' + MAXD + '.'); days = MAXD; }
      self.gridDays = days;
      self.tab_grid();
    });
    this.api.call('booking_grid', { from: this.gridFrom, to: to }).then(function (res) {
      var wrap = qs(self.pane, '.bk-gridwrap');
      if (!res.success) { wrap.innerHTML = '<p class="bk-warn">' + esc(res.message) + '</p>'; return; }
      wrap.innerHTML = self.renderGrid(res.data);
    });
  };
  Admin.prototype.renderGrid = function (g) {
    var head = '<tr><th class="bk-g__name"></th>' + g.dates.map(function (d) {
      var dt = parse(d), wd = (dt.getDay() + 6) % 7;
      return '<th class="' + (wd >= 4 ? 'is-we' : '') + (d === g.today ? ' is-today' : '') + '"><span>' + WD[wd] + '</span>' + dt.getDate()
        + (dt.getDate() === 1 || d === g.dates[0] ? '<em>' + MONTHS_GEN[dt.getMonth()].slice(0, 3) + '</em>' : '') + '</th>';
    }).join('') + '</tr>';
    var body = g.rows.map(function (r) {
      var cells = g.dates.map(function (d) {
        var c = r.cells[d] || {}, cls = [], inner = '', tip = [];
        if (r.type === 'house') {
          if (c.booking) {
            var b = c.booking;
            cls.push(b.cancel ? 'is-cancel' : (b.status === 'completed' ? 'is-done' : 'is-ok'));
            if (b.start) cls.push('is-start');
            tip.push('№' + b.id + ' ' + b.guest + ', ' + plural(b.guests, 'гость', 'гостя', 'гостей') + (b.cancel ? ' — просят отмену' : ''));
            inner = '<button type="button" data-open="' + b.id + '">' + (b.start ? esc(b.guest.split(' ')[0]) : '') + '</button>';
          }
          if (c.blocked) { cls.push('is-block'); tip.push('Закрыто: ' + c.blocked); }
          if (c['new']) {
            tip.push('Новые: ' + c['new'].map(function (n) { return '№' + n.id + ' ' + n.guest; }).join(', '));
            inner += '<button type="button" class="bk-g__new" data-open="' + c['new'][0].id + '" aria-label="Новые заявки: ' + c['new'].length + '">' + c['new'].length + '</button>';
          }
        } else {
          if (c.blocked) { cls.push('is-block'); tip.push('Закрыто: ' + c.blocked); }
          (c.sessions || []).forEach(function (s) {
            tip.push('№' + s.id + ' ' + s.from + '–' + s.to + ' ' + s.guest + ' (' + STATUS[s.status] + ')');
            inner += '<button type="button" class="bk-g__sess bk-g__sess--' + s.status + (s.cancel ? ' is-cancel' : '') + '" data-open="' + s.id + '">' + esc(s.from) + '</button>';
          });
        }
        return '<td class="' + cls.join(' ') + '"' + (tip.length ? ' title="' + esc(tip.join('\n')) + '"' : '') + '>' + inner + '</td>';
      }).join('');
      return '<tr class="bk-g__' + r.type + '"><th class="bk-g__name" scope="row">' + esc(r.name) + '</th>' + cells + '</tr>';
    }).join('');
    return '<table class="bk-g">' + '<thead>' + head + '</thead><tbody>' + body + '</tbody></table>';
  };

  // --- карточка брони
  Admin.prototype.card = function (id, link) {
    var self = this, box = qs(this.dialog, '.bk-dialog__body');
    box.innerHTML = '<p class="bk-muted">Загружаем…</p>';
    if (!this.dialog.open) this.dialog.showModal();
    this.api.call('booking_get', { id: id }).then(function (res) {
      if (!res.success) { box.innerHTML = '<p class="bk-warn">' + esc(res.message) + '</p>'; return; }
      self.renderCard(Object.assign(res.data, link ? { link: link } : {}));
    });
  };
  // личная ссылка гостя: показывается один раз (в базе только хеш); отправить — копией, через «Поделиться» или СМС
  Admin.prototype.linkBox = function (d) {
    var text = (d.status === 'new' ? 'Ваша заявка' : 'Ваша бронь') + ' №' + d.id + ': ' + d.link;
    var phone = String(d.guest_phone || '').replace(/[^\d+]/g, '');
    return '<div class="bk-guestlink"><b>Ссылка для гостя</b><p class="bk-muted">Отправьте гостю: по ней он видит заявку, её статус и может попросить отмену. Ссылка показывается один раз — отправьте её сейчас.</p>'
      + '<input class="bk-guestlink__url" readonly value="' + esc(d.link) + '" aria-label="Ссылка для гостя">'
      + '<div class="bk-guestlink__btns"><button type="button" class="bk-btn bk-btn--sm" data-link-copy>Копировать</button>'
      + (navigator.share ? '<button type="button" class="bk-btn bk-btn--sm bk-btn--ghost" data-link-share>Отправить…</button>' : '')
      + (phone ? '<a class="bk-btn bk-btn--sm bk-btn--ghost" href="sms:' + esc(phone) + '?&body=' + encodeURIComponent(text) + '">СМС гостю</a>' : '')
      + '</div></div>';
  };
  Admin.prototype.bindLinkBox = function (box, d) {
    var inp = qs(box, '.bk-guestlink__url');
    if (!inp) return;
    var copy = qs(box, '[data-link-copy]'), share = qs(box, '[data-link-share]');
    copy.addEventListener('click', function () {
      var done = function () { copy.textContent = 'Скопировано'; };
      inp.select();
      if (navigator.clipboard) navigator.clipboard.writeText(d.link).then(done, function () { document.execCommand('copy'); done(); });
      else { document.execCommand('copy'); done(); }
    });
    if (share) share.addEventListener('click', function () {
      navigator.share({ title: 'Доходные дома Ефремовых', text: (d.status === 'new' ? 'Ваша заявка' : 'Ваша бронь') + ' №' + d.id, url: d.link }).catch(function () {});
    });
  };
  Admin.prototype.renderCard = function (d) {
    var self = this, box = qs(this.dialog, '.bk-dialog__body');
    var active = d.status === 'new' || d.status === 'confirmed';
    var lines = (d.lines || []).map(function (l) {
      return '<tr' + (l.amount < 0 ? ' class="bk-quote__disc"' : '') + '><td>' + esc(l.label) + '</td><td>' + (l.amount < 0 ? '−' + money(-l.amount) : money(l.amount)) + '</td></tr>';
    }).join('');
    var log = (d.log || []).map(function (l) {
      return '<li><time>' + esc(l.created_at) + '</time> ' + esc(l.actor) + ' (' + esc(l.interface) + '): ' + esc(l.action) + '</li>';
    }).join('');
    var overlaps = (d.overlaps || []).map(function (o) {
      return '<li><button type="button" class="bk-link" data-open="' + o.id + '">№' + o.id + '</button> ' + esc(o.guest) + ', ' + esc(o.dates) + ' — ' + esc(STATUS[o.status]) + '</li>';
    }).join('');
    box.innerHTML = '<h3>' + (d.addon ? 'Баня' : (d.status === 'new' ? 'Заявка' : 'Бронь')) + ' №' + d.id + ' <span class="bk-badge bk-badge--' + d.status + '">' + esc(STATUS[d.status]) + '</span>'
      + (d.cancellation_requested && active ? ' <span class="bk-badge bk-badge--cancel">просят отмену</span>' : '') + '</h3>'
      + '<dl class="bk-dl"><dt>Дом</dt><dd>' + esc(d.house_name) + '</dd><dt>Даты</dt><dd>' + esc(d.dates) + '</dd>'
      + '<dt>Гость</dt><dd>' + esc(d.guest_name) + ', <a href="tel:' + esc(d.guest_phone) + '">' + esc(d.guest_phone) + '</a></dd>'
      + '<dt>Гостей</dt><dd>' + d.guests + (d.pets ? ', питомцев: ' + d.pets : '') + '</dd>'
      + (d.guest_comment ? '<dt>Комментарий</dt><dd>' + esc(d.guest_comment) + '</dd>' : '')
      + (d.cancellation_reason ? '<dt>Отмена</dt><dd>' + esc(d.cancellation_reason) + '</dd>' : '')
      + '<dt>Залог</dt><dd>' + (d.deposit_amount ? money(d.deposit_amount) + ' — ' : '') + esc(DEPOSIT[d.deposit_status]) + '</dd>'
      + '<dt>Источник</dt><dd>' + esc(SOURCE[d.source] || d.source) + (d.legacy_lead_id ? ' (прежняя заявка №' + d.legacy_lead_id + ')' : '') + '</dd></dl>'
      + '<table class="bk-quote__t"><tbody>' + lines + '</tbody><tfoot><tr><th>Итого</th><th>' + money(d.total) + '</th></tr></tfoot></table>'
      + (overlaps ? '<div class="bk-overlaps"><b>На эти даты ещё:</b><ul>' + overlaps + '</ul></div>' : '')
      + (d.link ? this.linkBox(d) : '')
      + '<div class="bk-actions">'
      + (d.status === 'new' ? '<form data-act="confirm" class="bk-inline"><label class="bk-field"><span>Залог, ₽ (5 000–20 000, необязательно)</span><input name="deposit_amount" type="number" min="5000" max="20000" step="500" inputmode="numeric"></label><button class="bk-btn" type="submit">Подтвердить</button></form>' : '')
      + (d.status === 'confirmed' ? '<button type="button" class="bk-btn bk-btn--ghost" data-act="complete">Гости уехали — завершить</button>' : '')
      + (active && !d.link ? '<button type="button" class="bk-btn bk-btn--ghost" data-act="link">Ссылка для гостя</button>' : '')
      + (d.status !== 'new' ? '<form data-act="deposit" class="bk-inline"><label class="bk-field"><span>Залог, ₽</span><input name="deposit_amount" type="number" min="5000" max="20000" step="500" value="' + (d.deposit_amount || '') + '"' + (d.status !== 'confirmed' ? ' readonly' : '') + '></label>'
        + '<label class="bk-field"><span>Статус залога</span><select name="deposit_status">' + Object.keys(DEPOSIT).map(function (k) { return '<option value="' + k + '"' + (k === d.deposit_status ? ' selected' : '') + '>' + DEPOSIT[k] + '</option>'; }).join('') + '</select></label>'
        + '<button class="bk-btn bk-btn--ghost" type="submit">Сохранить залог</button></form>' : '')
      + (active ? '<form data-act="cancel" class="bk-inline"><label class="bk-field"><span>Причина отмены</span><input name="reason" required maxlength="500"' + (d.cancellation_requested ? ' value="По просьбе гостя"' : '') + '></label><button class="bk-btn bk-btn--danger" type="submit">Отменить</button></form>' : '')
      + (active ? '<details class="bk-edit"><summary>Изменить даты, гостей, контакты</summary><form data-act="update" class="bk-formgrid">'
        + (d.addon
          ? '<label class="bk-field"><span>Дата</span><input name="date_from" type="date" value="' + d.date_from + '"></label><label class="bk-field"><span>С</span><input name="time_from" type="time" step="1800" value="' + d.time_from + '"></label><label class="bk-field"><span>До</span><input name="time_to" type="time" step="1800" value="' + d.time_to + '"></label>'
          : '<label class="bk-field"><span>Заезд</span><input name="date_from" type="date" value="' + d.date_from + '"></label><label class="bk-field"><span>Выезд</span><input name="date_to" type="date" value="' + d.date_to + '"></label>')
        + '<label class="bk-field"><span>Гостей</span><input name="guests_count" type="number" min="1" value="' + d.guests + '"></label>'
        + '<label class="bk-field"><span>Питомцев</span><input name="pets_count" type="number" min="0" value="' + d.pets + '"></label>'
        + '<label class="bk-field"><span>Имя</span><input name="guest_name" value="' + esc(d.guest_name) + '"></label>'
        + '<label class="bk-field"><span>Телефон</span><input name="guest_phone" value="' + esc(d.guest_phone) + '"></label>'
        + '<label class="bk-field bk-full"><span>Заметка администратора</span><textarea name="admin_note" rows="2">' + esc(d.admin_note) + '</textarea></label>'
        + '<div class="bk-full"><button class="bk-btn bk-btn--ghost" type="submit">Сохранить</button></div></form></details>'
        : '<form data-act="update" class="bk-formgrid"><label class="bk-field bk-full"><span>Заметка администратора</span><textarea name="admin_note" rows="2">' + esc(d.admin_note) + '</textarea></label><div class="bk-full"><button class="bk-btn bk-btn--ghost" type="submit">Сохранить заметку</button></div></form>')
      + '</div>'
      + (log ? '<details class="bk-log"><summary>Журнал</summary><ul>' + log + '</ul></details>' : '');

    var act = function (action, params, okText) {
      params.id = d.id;
      return self.api.call(action, params).then(function (res) {
        if (!res.success) {
          var c = res.data && res.data.conflict;
          window.alert(res.message + (c && c.booking_id ? '\nОткройте бронь №' + c.booking_id + ' в календаре.' : ''));
          return;
        }
        self.msg(okText);
        self.renderCard(Object.assign(res.data, { log: d.log, overlaps: d.overlaps }));
        self.card(d.id);
        if (self.current === 'grid' || self.current === 'list') self.show(self.current);
      });
    };
    qsa(box, 'form[data-act]').forEach(function (f) {
      var a = f.getAttribute('data-act');
      self.form(f, function (data) {
        if (a === 'confirm') return act('booking_confirm', { deposit_amount: data.deposit_amount }, 'Бронь №' + d.id + ' подтверждена');
        if (a === 'cancel') {
          if (!window.confirm('Отменить №' + d.id + '? Даты освободятся.')) return;
          return act('booking_cancel', { reason: data.reason }, 'Бронь №' + d.id + ' отменена');
        }
        if (a === 'deposit') {
          var p = { deposit_status: data.deposit_status };
          if (d.status === 'confirmed') p.deposit_amount = data.deposit_amount;
          return act('booking_set_deposit', p, 'Залог сохранён');
        }
        if (a === 'update') return act('booking_update', data, 'Изменения сохранены');
      });
    });
    var c = qs(box, '[data-act=complete]');
    if (c) c.addEventListener('click', function () { act('booking_complete', {}, 'Бронь №' + d.id + ' завершена'); });
    var l = qs(box, '[data-act=link]');
    if (l) l.addEventListener('click', function () {
      if (!window.confirm('Выдать гостю новую ссылку на №' + d.id + '? Прежняя ссылка, если её отправляли, перестанет работать.')) return;
      self.api.call('booking_guest_link', { id: d.id }).then(function (res) {
        if (!res.success) { window.alert(res.message); return; }
        self.card(d.id, res.data.link);
      });
    });
    this.bindLinkBox(box, d);
  };

  // --- список заявок и бронь по телефону
  Admin.prototype.tab_list = function () {
    var self = this, f = this.listFilter;
    var st = [['new,confirmed', 'Активные'], ['new', 'Новые'], ['cancel_requested', 'Просят отмену'], ['confirmed', 'Подтверждённые'], ['completed', 'Завершённые'], ['cancelled', 'Отменённые'], ['', 'Все']];
    this.pane.innerHTML = '<form class="bk-bar" data-filter><select name="status">' + st.map(function (s) { return '<option value="' + s[0] + '"' + (s[0] === f.status ? ' selected' : '') + '>' + s[1] + '</option>'; }).join('') + '</select>'
      + '<select name="house">' + this.houseOptions(f.house, 'Все Дома') + '</select><input name="q" type="search" placeholder="Имя, телефон, №" value="' + esc(f.q) + '">'
      // период: брони, у которых проживание попадает в эти даты (и для выгрузки в Excel)
      + '<label class="bk-period">с <input name="from" type="date" value="' + esc(f.from || '') + '" aria-label="Проживание с"></label>'
      + '<label class="bk-period">по <input name="to" type="date" value="' + esc(f.to || '') + '" aria-label="Проживание по"></label>'
      + '<button class="bk-btn bk-btn--sm bk-btn--ghost" type="submit">Найти</button>'
      + '<button class="bk-btn bk-btn--sm" type="button" data-manual>Бронь по телефону</button>'
      + '<button class="bk-btn bk-btn--sm bk-btn--ghost" type="button" data-export>Выгрузить в Excel</button></form><div class="bk-listwrap"><p class="bk-muted">Загружаем…</p></div>';
    var ff = qs(this.pane, '[data-filter]');
    this.form(ff, function (data) { Object.assign(f, data, { page: 1 }); self.tab_list(); });
    qs(this.pane, '[data-manual]').addEventListener('click', function () { self.manual(); });
    qs(this.pane, '[data-export]').addEventListener('click', function (e) {
      qsa(ff, 'select[name], input[name]').forEach(function (i) { f[i.name] = i.value; });   // фильтр как в форме, даже без «Найти»
      self.exportCsv(e.currentTarget);
    });
    this.api.call('booking_list', f).then(function (res) {
      var w = qs(self.pane, '.bk-listwrap');
      if (!res.success) { w.innerHTML = '<p class="bk-warn">' + esc(res.message) + '</p>'; return; }
      var d = res.data;
      if (!d.items.length) { w.innerHTML = '<p class="bk-muted">Ничего не найдено</p>'; return; }
      w.innerHTML = '<table class="bk-table bk-table--list"><thead><tr><th>№</th><th>Статус</th><th>Дом</th><th>Даты</th><th>Гость</th><th>Гостей</th><th>Сумма</th><th>Залог</th><th>Создана</th></tr></thead><tbody>'
        + d.items.map(function (r) {
          return '<tr data-open="' + r.id + '" tabindex="0"><td>' + r.id + '</td><td><span class="bk-badge bk-badge--' + r.status + '">' + STATUS[r.status] + '</span>'
            + (r.cancellation_requested && (r.status === 'new' || r.status === 'confirmed') ? ' <span class="bk-badge bk-badge--cancel">отмена?</span>' : '') + '</td>'
            + '<td>' + esc(r.house_name) + (r.addon ? ', баня' : '') + '</td><td>' + esc(r.dates) + '</td><td>' + esc(r.guest_name) + '<br><small>' + esc(r.guest_phone) + '</small></td>'
            + '<td>' + r.guests + '</td><td>' + money(r.total) + '</td><td>' + (r.deposit_amount ? money(r.deposit_amount) + '<br><small>' + DEPOSIT[r.deposit_status] + '</small>' : '—') + '</td>'
            + '<td><small>' + esc(r.created_at) + '</small></td></tr>';
        }).join('') + '</tbody></table>'
        + (d.total > d.per_page ? '<div class="bk-bar"><button type="button" class="bk-btn bk-btn--sm bk-btn--ghost" data-p="-1"' + (d.page <= 1 ? ' disabled' : '') + '>‹</button> стр. ' + d.page + ' из ' + Math.ceil(d.total / d.per_page)
          + ' <button type="button" class="bk-btn bk-btn--sm bk-btn--ghost" data-p="1"' + (d.page * d.per_page >= d.total ? ' disabled' : '') + '>›</button></div>' : '');
      qsa(w, '[data-p]').forEach(function (b) { b.addEventListener('click', function () { f.page += +b.getAttribute('data-p'); self.tab_list(); }); });
    });
  };
  /* Выгрузка в Excel: все заявки по текущему фильтру — CSV через «;» с BOM (Excel открывает кириллицу).
     Ячейка с «= + - @» в начале (и после пробелов) — с апострофом: иначе Excel выполнит её как формулу */
  Admin.prototype.exportCsv = function (btn) {
    var self = this;
    btn.disabled = true;
    this.api.call('booking_list', Object.assign({}, this.listFilter, { all: 1, page: 1 })).then(function (res) {
      btn.disabled = false;
      if (!res.success) { window.alert(res.message || 'Не удалось выгрузить'); return; }
      var cell = function (v) {
        var s = String(v == null ? '' : v);
        if (/^\s*[=+\-@]|^[\t\r]/.test(s)) s = "'" + s;
        return /[";\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
      };
      var rows = [['№', 'Создана', 'Статус', 'Дом', 'Что', 'Даты', 'Гостей', 'Питомцев', 'Сумма, ₽', 'Скидка, ₽', 'Залог, ₽', 'Залог', 'Имя', 'Телефон',
        'Комментарий гостя', 'Просит отменить', 'Причина отмены', 'Источник', 'Заметка администратора']];
      res.data.items.forEach(function (r) {
        rows.push([r.id, r.created_at, STATUS[r.status] || r.status, r.house_name, r.addon ? 'баня' : 'Дом', r.dates, r.guests, r.pets, r.total,
          r.quote_discount || '', r.deposit_amount || '', DEPOSIT[r.deposit_status] || '', r.guest_name, r.guest_phone, r.guest_comment,
          r.cancellation_requested ? 'да' : '', r.cancellation_reason, SOURCE[r.source] || r.source, r.admin_note]);
      });
      var csv = '﻿' + rows.map(function (r) { return r.map(cell).join(';'); }).join('\r\n');
      var a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
      var fl = self.listFilter;
      a.download = 'bronirovanie-' + (fl.from || fl.to ? (fl.from || '…') + '_' + (fl.to || '…') : iso(new Date())) + '.csv';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
      if (res.data.total > res.data.items.length) window.alert('Выгружено ' + res.data.items.length + ' из ' + res.data.total + ' — уточните фильтр');
    });
  };
  Admin.prototype.manual = function () {
    var self = this, box = qs(this.dialog, '.bk-dialog__body');
    box.innerHTML = '<h3>Бронь по телефону</h3><p class="bk-muted">Заявка за гостя, который не может оформить её сам. После создания появится ссылка — отправьте её гостю, по ней он увидит свою заявку.</p>'
      + '<form class="bk-formgrid" data-manual-form><label class="bk-field bk-full"><span>Что создать</span><select name="status" data-manual-status>'
      + '<option value="new">Заявку — даты не заняты, подтвердить после предоплаты</option>'
      + '<option value="confirmed">Подтверждённую бронь — даты займутся сразу</option></select></label>'
      + '<label class="bk-field"><span>Дом</span><select name="house" required>' + this.houseOptions('') + '</select></label>'
      + '<label class="bk-field"><span>Только баня</span><select name="addon"><option value="0">нет, Дом на сутки</option><option value="1">да, баня по часам</option></select></label>'
      + '<label class="bk-field"><span>Заезд / дата</span><input name="date_from" type="date" required></label><label class="bk-field"><span>Выезд</span><input name="date_to" type="date"></label>'
      + '<label class="bk-field"><span>Баня с</span><input name="time_from" type="time" step="1800"></label><label class="bk-field"><span>Баня до</span><input name="time_to" type="time" step="1800"></label>'
      + '<label class="bk-field"><span>Гостей</span><input name="guests" type="number" min="1" required></label><label class="bk-field"><span>Питомцев</span><input name="pets" type="number" min="0" value="0"></label>'
      + '<label class="bk-field"><span>Имя гостя</span><input name="guest_name" required></label><label class="bk-field"><span>Телефон</span><input name="guest_phone" type="tel" required></label>'
      + '<label class="bk-field" data-manual-deposit hidden><span>Залог, ₽ (необязательно)</span><input name="deposit_amount" type="number" min="5000" max="20000" step="500"></label>'
      + '<label class="bk-field bk-full"><span>Комментарий гостя</span><textarea name="guest_comment" rows="2"></textarea></label>'
      + '<label class="bk-field bk-full"><span>Заметка администратора</span><textarea name="admin_note" rows="2"></textarea></label>'
      + '<div class="bk-full"><button class="bk-btn" type="submit" data-manual-submit>Создать заявку</button></div></form>';
    if (!this.dialog.open) this.dialog.showModal();
    var st = qs(box, '[data-manual-status]');
    st.addEventListener('change', function () {
      var conf = st.value === 'confirmed';
      qs(box, '[data-manual-deposit]').hidden = !conf;   // залог у заявки назначают при подтверждении
      qs(box, '[data-manual-submit]').textContent = conf ? 'Создать подтверждённую бронь' : 'Создать заявку';
    });
    this.form(qs(box, '[data-manual-form]'), function (data) {
      if (data.status !== 'confirmed') delete data.deposit_amount;
      return self.api.call('booking_create_manual', data).then(function (res) {
        if (!res.success) { window.alert(res.message); return; }
        self.msg(res.data.status === 'new' ? 'Заявка №' + res.data.id + ' создана — подтвердите после предоплаты' : 'Бронь №' + res.data.id + ' создана и подтверждена');
        self.card(res.data.id, res.data.link);
        if (self.current === 'grid' || self.current === 'list') self.show(self.current);
      });
    });
  };

  // --- статистика для владельца и директора
  // Цвета Домов — по порядку Домов (проверены на различимость, в т. ч. при дальтонизме); общий показатель — фирменный.
  var SERIES = ['#2a78d6', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948', '#eb6834'];
  var ONE = '#B04E09';
  var MONTHS = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
  var MONTHS_FULL = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];
  var WEEK = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
  var WEEK_FULL = ['понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота', 'воскресенье'];
  function num(n, d) { return String(d ? Math.round(n * 10) / 10 : Math.round(n)).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
  function pct(n) { return num(n, 1) + '%'; }
  function rub(n) {   // компактно: 6,3 млн ₽ / 314 тыс ₽
    var a = Math.abs(n);
    if (a >= 1e6) return num(n / 1e6, 1) + ' млн ₽';
    if (a >= 1e4) return num(n / 1e3) + ' тыс ₽';
    return money(n);
  }
  function ticks(max) {   // 0 и 3–4 круглых деления
    if (max <= 0) return [0, 1];
    var step = Math.pow(10, Math.floor(Math.log10(max / 3)));
    [1, 2, 2.5, 5, 10].some(function (m) { if (max / (step * m) <= 4) { step *= m; return true; } return false; });
    var out = [];
    for (var v = 0; v < max + step * 0.999; v += step) out.push(v);
    return out;
  }
  function monthLabel(m, i, all) {
    var mm = +m.slice(5, 7) - 1;
    return MONTHS[mm] + (i === 0 || mm === 0 || all.length <= 1 ? ' ' + m.slice(2, 4) : '');
  }

  Admin.prototype.tab_stats = function () {
    var self = this;
    var f = this.statsFilter || (this.statsFilter = { preset: '12m', from: '', to: '' });
    var t = new Date();
    var P = {
      '12m': ['Последние 12 месяцев', new Date(t.getFullYear(), t.getMonth() - 11, 1), new Date(t.getFullYear(), t.getMonth() + 1, 0)],
      year: ['Этот год', new Date(t.getFullYear(), 0, 1), new Date(t.getFullYear(), 11, 31)],
      prev: ['Прошлый год', new Date(t.getFullYear() - 1, 0, 1), new Date(t.getFullYear() - 1, 11, 31)],
      month: ['Этот месяц', new Date(t.getFullYear(), t.getMonth(), 1), new Date(t.getFullYear(), t.getMonth() + 1, 0)],
      next: ['Следующие 3 месяца', new Date(t.getFullYear(), t.getMonth(), t.getDate()), new Date(t.getFullYear(), t.getMonth() + 3, t.getDate())],
      custom: ['Свой период']
    };
    if (P[f.preset] && P[f.preset][1]) { f.from = iso(P[f.preset][1]); f.to = iso(P[f.preset][2]); }
    this.pane.innerHTML = '<form class="bk-bar" data-stats-filter><select name="preset" aria-label="Период">'
      + Object.keys(P).map(function (k) { return '<option value="' + k + '"' + (k === f.preset ? ' selected' : '') + '>' + P[k][0] + '</option>'; }).join('') + '</select>'
      + '<label class="bk-period">с <input name="from" type="date" value="' + esc(f.from) + '" aria-label="Заезд с"></label>'
      + '<label class="bk-period">по <input name="to" type="date" value="' + esc(f.to) + '" aria-label="Заезд по"></label>'
      + '<button class="bk-btn bk-btn--sm bk-btn--ghost" type="submit">Показать</button>'
      + '<button class="bk-btn bk-btn--sm bk-btn--ghost" type="button" data-stats-export>Выгрузить в Excel</button></form>'
      + '<div class="bk-stats"><p class="bk-muted">Считаем…</p></div>';
    var ff = qs(this.pane, '[data-stats-filter]');
    qs(ff, '[name=preset]').addEventListener('change', function (e) { f.preset = e.target.value; if (f.preset !== 'custom') self.tab_stats(); });
    qsa(ff, 'input[type=date]').forEach(function (i) { i.addEventListener('change', function () { qs(ff, '[name=preset]').value = 'custom'; }); });
    this.form(ff, function (data) { f.preset = data.preset; f.from = data.from; f.to = data.to; return self.loadStats(); });
    qs(this.pane, '[data-stats-export]').addEventListener('click', function () { if (self.stats) self.statsCsv(self.stats); });
    this.loadStats();
  };
  Admin.prototype.loadStats = function () {
    var self = this, f = this.statsFilter, box = qs(this.pane, '.bk-stats');
    if (!box) return;
    box.classList.add('is-loading');   // прежние графики остаются, пока считаем новые
    return this.api.call('booking_stats', { from: f.from, to: f.to }).then(function (res) {
      if (self.current !== 'stats') return;
      box.classList.remove('is-loading');
      if (!res.success) { self.fail(res); return; }
      self.stats = res.data;
      self.renderStats(box, res.data);
    });
  };
  // поворот телефона или окно шире/уже — перерисовать подписи графиков
  window.addEventListener('resize', function () {
    clearTimeout(Admin.resizeT);
    Admin.resizeT = setTimeout(function () {
      (Admin.all || []).forEach(function (a) {
        var box = a.current === 'stats' && a.stats && qs(a.pane, '.bk-stats');
        if (box && (box.clientWidth < 560) !== a.statsNarrow) a.renderStats(box, a.stats);
      });
    }, 250);
  });

  Admin.prototype.renderStats = function (box, d) {
    var self = this, k = d.kpi, p = d.prev;
    var color = {}, tips = [];
    var narrow = box.clientWidth < 560;   // телефон: подписи через одну, без чисел над столбцами (они в подсказке и таблице)
    this.statsNarrow = narrow;
    d.houses.forEach(function (h, i) { color[h.code] = SERIES[i % SERIES.length]; });
    var tip = function (title, rows) { tips.push({ title: title, rows: rows }); return ' tabindex="0" data-tip="' + (tips.length - 1) + '"'; };
    var dfmt = function (s) { return s.slice(8, 10) + '.' + s.slice(5, 7) + '.' + s.slice(0, 4); };

    // сравнение с прошлым таким же периодом: стрелка и слова, цвет — хорошо/плохо
    var delta = function (cur, old, upGood, isPct) {
      if (old === null || old === undefined || (!old && !cur)) return '';
      var diff = isPct ? cur - old : (old ? (cur - old) * 100 / old : null);
      if (diff === null) return '<span class="bk-kpi__d">прошлый период — 0</span>';
      if (Math.abs(diff) < 0.5) return '<span class="bk-kpi__d">как в прошлом периоде</span>';
      var good = (diff > 0) === upGood;
      return '<span class="bk-kpi__d ' + (good ? 'is-good' : 'is-bad') + '">' + (diff > 0 ? '▲ +' : '▼ −') + num(Math.abs(diff), isPct) + (isPct ? ' п.п.' : '%') + ' <span>к прошлому периоду</span></span>';
    };
    var tile = function (label, value, sub, dl) {
      return '<div class="bk-kpi"><span class="bk-kpi__l">' + label + '</span><b class="bk-kpi__v">' + value + '</b>' + (sub ? '<span class="bk-kpi__s">' + sub + '</span>' : '') + (dl || '') + '</div>';
    };
    var legend = function () {
      return d.houses.length < 2 ? '' : '<ul class="bk-legend2">' + d.houses.map(function (h) { return '<li><i style="background:' + color[h.code] + '"></i>' + esc(h.name) + '</li>'; }).join('') + '</ul>';
    };
    var table = function (head, rows) {
      return '<details class="bk-tview"><summary>Таблицей</summary><div class="bk-tview__w"><table class="bk-table"><thead><tr>' + head.map(function (h) { return '<th>' + h + '</th>'; }).join('') + '</tr></thead><tbody>'
        + rows.map(function (r) { return '<tr>' + r.map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table></div></details>';
    };
    // столбцы: categories — подписи, stacks[i] — [{code|null, value}], fmt — подпись значения
    var columns = function (cats, stacks, fmt, tipTitles, opt) {
      opt = opt || {};
      var totals = stacks.map(function (s) { return s.reduce(function (a, x) { return a + x.value; }, 0); });
      var tk = ticks(Math.max.apply(null, totals.concat([opt.min || 0])));
      var top = tk[tk.length - 1];
      var every = cats.length > 18 ? 3 : (cats.length > 12 || (narrow && cats.length > 7) ? 2 : 1);
      return '<div class="bk-cc"><div class="bk-cc__plot">'
        + tk.map(function (v) { return '<div class="bk-cc__grid" style="bottom:' + (v * 100 / top) + '%"><span>' + (opt.tick ? opt.tick(v) : num(v)) + '</span></div>'; }).join('')
        + '<div class="bk-cc__cols">' + cats.map(function (c, i) {
          var s = stacks[i], tot = totals[i];
          var rows = s.length > 1 ? s.map(function (x) { return { color: color[x.code], name: (d.houses.filter(function (h) { return h.code === x.code; })[0] || {}).name, value: fmt(x.value) }; }).concat([{ name: 'Всего', value: fmt(tot) }])
            : [{ color: ONE, name: opt.series || '', value: fmt(tot) }];
          return '<div class="bk-cc__col"' + tip(tipTitles[i], rows) + '>'
            + (tot && cats.length <= (narrow ? 7 : 14) ? '<span class="bk-cc__cap">' + (opt.cap ? opt.cap(tot) : fmt(tot)) + '</span>' : '')
            + '<div class="bk-cc__stack" style="height:' + (tot * 100 / top) + '%">'
            + s.filter(function (x) { return x.value > 0; }).map(function (x) { return '<i style="flex-grow:' + x.value + ';background:' + (x.code ? color[x.code] : ONE) + '"></i>'; }).join('')
            + '</div></div>';
        }).join('') + '</div></div>'
        + '<div class="bk-cc__x">' + cats.map(function (c, i) { return '<span>' + (i % every ? '' : esc(c)) + '</span>'; }).join('') + '</div></div>';
    };
    // горизонтальные полосы: rows — [{label, value, color?, text?}]
    var bars = function (rows, fmt, max) {
      max = max || Math.max.apply(null, rows.map(function (r) { return r.value; }).concat([1]));
      return '<div class="bk-hb">' + rows.map(function (r) {
        return '<div class="bk-hb__r"' + tip(r.label, [{ color: r.color || ONE, name: r.tipName || '', value: r.text || fmt(r.value) }]) + '><span class="bk-hb__l">' + esc(r.label) + '</span>'
          + '<span class="bk-hb__t"><i style="width:' + (r.value * 100 / max) + '%;background:' + (r.color || ONE) + '"></i></span><span class="bk-hb__v">' + (r.text || fmt(r.value)) + '</span></div>';
      }).join('') + '</div>';
    };
    var card = function (title, sub, body, wide) {
      return '<section class="bk-box bk-sc' + (wide ? ' bk-sc--wide' : '') + '"><h3>' + title + '</h3>' + (sub ? '<p class="bk-muted bk-sc__sub">' + sub + '</p>' : '') + body + '</section>';
    };

    var short = function (v) { return v ? rub(v).replace(/\s₽$/, '') : '0'; };
    var mcats = d.months.map(function (m, i, all) { return monthLabel(m.month, i, all); });
    var mfull = d.months.map(function (m) { return MONTHS_FULL[+m.month.slice(5, 7) - 1] + ' ' + m.month.slice(0, 4); });
    var stackBy = function (field) {
      return d.months.map(function (m) { return d.houses.map(function (h) { return { code: h.code, value: m[field][h.code] || 0 }; }); });
    };
    var byHouse = d.by_house.slice().sort(function (a, b) { return b.occupancy - a.occupancy; });
    var top = byHouse[0], peak = d.months.slice().sort(function (a, b) { return b.occupancy - a.occupancy; })[0];
    var wk = d.weekdays, wkdays = wk.slice(0, 4).map(function (w) { return w.occupancy; }), wkend = [wk[4].occupancy, wk[5].occupancy];
    var leadHalf = k.lead_median;

    // главное словами — только факты из цифр
    var facts = [];
    if (k.bookings) {
      if (top && byHouse.length > 1) facts.push('Больше всего спрос — <b>' + esc(top.name) + '</b>: занято ' + pct(top.occupancy) + ' ночей, ' + top.bookings + ' ' + pword(top.bookings, 'бронь', 'брони', 'броней') + '.');
      if (peak && d.months.length > 2) facts.push('Пик сезона — <b>' + MONTHS_FULL[+peak.month.slice(5, 7) - 1] + ' ' + peak.month.slice(0, 4) + '</b>: занято ' + pct(peak.occupancy) + ' ночей.');
      facts.push('Ночи пятницы и субботы заняты на ' + pct(Math.min.apply(null, wkend)) + '–' + pct(Math.max.apply(null, wkend)) + ', будни (пн–чт) — на ' + pct(Math.min.apply(null, wkdays)) + '–' + pct(Math.max.apply(null, wkdays)) + '.');
      if (leadHalf !== null) facts.push('Половина гостей бронирует за <b>' + leadHalf + ' ' + pword(leadHalf, 'день', 'дня', 'дней') + '</b> до заезда и меньше.');
      if (k.repeat_guests) facts.push('Повторные гости — ' + k.repeat_guests + ' из ' + k.unique_guests + ' (бывали у вас раньше или приезжали снова).');
    }

    var houseRows = d.by_house.map(function (h) {
      return ['<i class="bk-sw2" style="background:' + color[h.code] + '"></i>' + esc(h.name), h.bookings, h.nights, pct(h.occupancy), money(h.revenue), money(h.avg_check), num(h.avg_guests, 1), h.cancelled,
        h.bath_sessions ? h.bath_sessions + ' / ' + num(h.bath_hours, 1) + ' ч / ' + money(h.bath_revenue) : '—'];
    });
    var SRC = { site: 'Сайт', pwa: 'Приложение', max: 'MAX', admin: 'По телефону', migrated: 'Прежние заявки' };

    box.innerHTML = '<p class="bk-muted bk-stats__note">Заезды с ' + dfmt(d.from) + ' по ' + dfmt(d.to) + ' · сравнение с ' + dfmt(d.prev_from) + ' – ' + dfmt(d.prev_to)
      + '. Брони — подтверждённые и завершённые; сумма — по расчёту брони (предоплата в системе не ведётся).</p>'
      + '<div class="bk-kpis">'
      + '<div class="bk-kpi bk-kpi--hero"><span class="bk-kpi__l">Сумма броней</span><b class="bk-kpi__v">' + money(k.revenue) + '</b>' + delta(k.revenue, p.revenue, true)
      + (k.bath_revenue ? '<span class="bk-kpi__s">из них баня по часам — ' + money(k.bath_revenue) + '</span>' : '') + '</div>'
      + tile('Броней Домов', num(k.bookings), k.nights + ' ' + pword(k.nights, 'ночь', 'ночи', 'ночей'), delta(k.bookings, p.bookings, true))
      + tile('Загрузка', pct(k.occupancy), 'занятых ночей из всех', delta(k.occupancy, p.occupancy, true, true))
      + tile('Средний чек', money(k.avg_check), money(k.avg_night) + ' за ночь', delta(k.avg_check, p.avg_check, true))
      + tile('Гостей в брони', num(k.avg_guests, 1), 'в среднем, ' + num(k.avg_stay, 1) + ' ' + pword(Math.round(k.avg_stay), 'ночь', 'ночи', 'ночей'), delta(k.avg_guests, p.avg_guests, true))
      + tile('Заявки → брони', pct(k.conversion), k.requests + ' ' + pword(k.requests, 'заявка', 'заявки', 'заявок') + (k.pending ? ', ещё не решено ' + k.pending : ''), delta(k.conversion, p.conversion, true, true))
      + tile('Отмены', pct(k.cancel_rate), k.cancelled + ' из решённых', delta(k.cancel_rate, p.cancel_rate, false, true))
      + (k.bath_sessions || p.bath_sessions ? tile('Баня по часам', num(k.bath_sessions), num(k.bath_hours, 1) + ' ч', delta(k.bath_sessions, p.bath_sessions, true)) : '')
      + tile('Уже вперёд', money(d.ahead.revenue), d.ahead.bookings + ' ' + pword(d.ahead.bookings, 'бронь', 'брони', 'броней') + ' · ближайшие 30 ночей заняты на ' + pct(d.ahead.occupancy30))
      + '</div>'
      + (facts.length ? '<section class="bk-box bk-facts"><h3>Главное</h3><ul>' + facts.map(function (x) { return '<li>' + x + '</li>'; }).join('') + '</ul></section>' : '')
      + (!k.bookings && !k.requests ? '<p class="bk-muted">За этот период заездов нет — выберите другой период.</p>' : '')
      + '<div class="bk-sgrid">'
      + card('Сезонность: занятые ночи по месяцам', 'Сколько ночей Домов занято в каждом месяце; загрузка — в подсказке и таблице.', legend()
        + columns(mcats, stackBy('nights'), function (v) { return num(v) + ' ' + pword(v, 'ночь', 'ночи', 'ночей'); }, mfull, { cap: function (v) { return v; }, min: 1 })
        + table(['Месяц'].concat(d.houses.map(function (h) { return esc(h.name); })).concat(['Всего ночей', 'Загрузка', 'Броней']), d.months.map(function (m, i) {
          return [mfull[i]].concat(d.houses.map(function (h) { return m.nights[h.code] || 0; })).concat([Object.keys(m.nights).reduce(function (a, c) { return a + m.nights[c]; }, 0), pct(m.occupancy), m.bookings]);
        })), true)
      + card('Сумма броней по месяцам', 'По месяцу заезда, Дома и баня по часам.', legend()
        + columns(mcats, stackBy('revenue'), money, mfull, { cap: short, tick: short })
        + table(['Месяц'].concat(d.houses.map(function (h) { return esc(h.name); })).concat(['Всего']), d.months.map(function (m, i) {
          return [mfull[i]].concat(d.houses.map(function (h) { return money(m.revenue[h.code] || 0); })).concat([money(Object.keys(m.revenue).reduce(function (a, c) { return a + m.revenue[c]; }, 0))]);
        })), true)
      + card('Какие Дома больше берут', 'Загрузка — доля занятых ночей за период.', bars(byHouse.map(function (h) { return { label: h.name, value: h.occupancy, color: color[h.code], tipName: h.bookings + ' ' + pword(h.bookings, 'бронь', 'брони', 'броней') + ', ' + money(h.revenue) }; }), pct, 100)
        + '<h4 class="bk-sc__h4">Сумма броней</h4>' + bars(byHouse.map(function (h) { return { label: h.name, value: h.revenue + h.bath_revenue, color: color[h.code], text: rub(h.revenue + h.bath_revenue) }; }), rub)
        + table(['Дом', 'Броней', 'Ночей', 'Загрузка', 'Сумма', 'Средний чек', 'Гостей в среднем', 'Отмен', 'Баня по часам: сеансов / часов / сумма'], houseRows))
      + card('Загрузка по дням недели', 'Доля занятых ночей: ночь пятницы — с пятницы на субботу.',
        columns(WEEK, wk.map(function (w) { return [{ code: null, value: w.occupancy }]; }), pct, WEEK_FULL.map(function (w) { return 'Ночь, ' + w; }), { cap: function (v) { return Math.round(v) + '%'; }, tick: function (v) { return v + '%'; }, min: 10, series: 'занято ночей' })
        + table(['День', 'Занято ночей', 'Загрузка', 'Заездов в этот день'], wk.map(function (w, i) { return [WEEK_FULL[i], w.nights, pct(w.occupancy), w.checkins]; })))
      + card('За сколько дней до заезда бронируют', 'От заявки до заезда; без перенесённых прежних заявок.', bars(d.lead.map(function (x) { return { label: x.label, value: x.count, tipName: 'броней' }; }), num)
        + table(['Срок', 'Броней'], d.lead.map(function (x) { return [x.label, x.count]; })))
      + card('На сколько ночей', '', bars(d.stay.map(function (x) { return { label: x.label, value: x.count, tipName: 'броней' }; }), num)
        + '<h4 class="bk-sc__h4">Сколько гостей</h4>' + bars(d.group.map(function (x) { return { label: x.label, value: x.count, tipName: 'броней' }; }), num)
        + table(['', 'Броней'], d.stay.concat(d.group).map(function (x) { return [x.label + (d.group.indexOf(x) >= 0 ? ' гостей' : ''), x.count]; })))
      + card('Откуда заявки', 'Заявки — все, брони — подтверждённые и завершённые.', bars(d.sources.map(function (s) { return { label: SRC[s.source] || s.source, value: s.bookings, text: s.bookings + ' из ' + s.requests, tipName: 'броней из заявок, ' + money(s.revenue) }; }), num)
        + table(['Откуда', 'Заявок', 'Броней', 'Сумма'], d.sources.map(function (s) { return [SRC[s.source] || esc(s.source), s.requests, s.bookings, money(s.revenue)]; })))
      + '</div><div class="bk-tip" role="tooltip" hidden></div>';

    // подсказка при наведении и при фокусе с клавиатуры; текст — через textContent
    var tipEl = qs(box, '.bk-tip');
    var showTip = function (el, x, y) {
      var tdat = tips[+el.getAttribute('data-tip')];
      if (!tdat) return;
      tipEl.textContent = '';
      var h = document.createElement('b'); h.textContent = tdat.title; tipEl.appendChild(h);
      tdat.rows.forEach(function (r) {
        var row = document.createElement('div'); row.className = 'bk-tip__r';
        if (r.color) { var key = document.createElement('i'); key.style.background = r.color; row.appendChild(key); }
        var v = document.createElement('strong'); v.textContent = r.value; row.appendChild(v);
        if (r.name) { var n = document.createElement('span'); n.textContent = r.name; row.appendChild(n); }
        tipEl.appendChild(row);
      });
      tipEl.hidden = false;
      var bb = box.getBoundingClientRect(), tw = tipEl.offsetWidth, th = tipEl.offsetHeight;
      var left = Math.min(Math.max(4, x - bb.left + 14), bb.width - tw - 4);
      var top = y - bb.top - th - 12;
      tipEl.style.left = left + 'px';
      tipEl.style.top = (top < 0 ? y - bb.top + 18 : top) + 'px';
    };
    qsa(box, '[data-tip]').forEach(function (el) {
      el.addEventListener('pointermove', function (e) { showTip(el, e.clientX, e.clientY); });
      el.addEventListener('pointerleave', function () { tipEl.hidden = true; });
      el.addEventListener('focus', function () { var r = el.getBoundingClientRect(); showTip(el, r.left + r.width / 2, r.top); });
      el.addEventListener('blur', function () { tipEl.hidden = true; });
    });
  };
  function pword(n, one, few, many) {
    n = Math.abs(Math.round(n)) % 100;
    var n1 = n % 10;
    if (n > 10 && n < 20) return many;
    if (n1 > 1 && n1 < 5) return few;
    return n1 === 1 ? one : many;
  }
  Admin.prototype.statsCsv = function (d) {
    var cell = function (v) { v = String(v == null ? '' : v); return /[;"\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    var k = d.kpi, rows = [['Статистика за период', d.from + ' — ' + d.to], [],
      ['Сумма броней', k.revenue], ['Броней Домов', k.bookings], ['Ночей', k.nights], ['Загрузка, %', k.occupancy], ['Средний чек', k.avg_check], ['Средняя цена ночи', k.avg_night],
      ['Ночей в брони', k.avg_stay], ['Гостей в брони', k.avg_guests], ['Заявок', k.requests], ['Заявки → брони, %', k.conversion], ['Отмены, %', k.cancel_rate],
      ['Бронируют за, дней (медиана)', k.lead_median == null ? '' : k.lead_median], ['Баня по часам: сеансов', k.bath_sessions], ['Баня по часам: часов', k.bath_hours], ['Баня по часам: сумма', k.bath_revenue],
      ['Гостей (разных телефонов)', k.unique_guests], ['Из них повторные', k.repeat_guests], [],
      ['Дом', 'Броней', 'Ночей', 'Загрузка, %', 'Сумма', 'Средний чек', 'Гостей в среднем', 'Отмен', 'Баня: сеансов', 'Баня: часов', 'Баня: сумма']];
    d.by_house.forEach(function (h) { rows.push([h.name, h.bookings, h.nights, h.occupancy, h.revenue, h.avg_check, h.avg_guests, h.cancelled, h.bath_sessions, h.bath_hours, h.bath_revenue]); });
    rows.push([], ['Месяц'].concat(d.houses.map(function (h) { return h.name + ': ночей'; })).concat(d.houses.map(function (h) { return h.name + ': сумма'; })).concat(['Загрузка, %', 'Броней', 'Заявок']));
    d.months.forEach(function (m) {
      rows.push([m.month].concat(d.houses.map(function (h) { return m.nights[h.code] || 0; })).concat(d.houses.map(function (h) { return m.revenue[h.code] || 0; })).concat([m.occupancy, m.bookings, m.requests]));
    });
    rows.push([], ['День недели', 'Занято ночей', 'Загрузка, %', 'Заездов']);
    d.weekdays.forEach(function (w, i) { rows.push([WEEK_FULL[i], w.nights, w.occupancy, w.checkins]); });
    var csv = '﻿' + rows.map(function (r) { return r.map(cell).join(';'); }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    a.download = 'statistika-' + d.from + '_' + d.to + '.csv';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
  };

  // --- дома и бани
  Admin.prototype.tab_houses = function () {
    var self = this;
    this.api.call('booking_manage_house', { op: 'list' }).then(function (res) {
      if (!res.success) { self.fail(res); return; }
      self.houses = res.data.items;
      self.pane.innerHTML = res.data.items.map(function (h) {
        var a = h.addon || {};
        return '<section class="bk-box"><h3>' + esc(h.name) + ' <small>(' + esc(h.code) + ')</small>' + (h.active ? '' : ' <span class="bk-badge bk-badge--cancelled">скрыт</span>') + '</h3>'
          + '<form class="bk-formgrid" data-house="' + h.id + '"><input type="hidden" name="id" value="' + h.id + '">'
          + '<label class="bk-field"><span>Название</span><input name="name" value="' + esc(h.name) + '" required></label>'
          + '<label class="bk-field"><span>Адрес</span><input name="address" value="' + esc(h.address) + '"></label>'
          + '<label class="bk-field"><span>Ночуют</span><input name="capacity_sleep" type="number" min="1" value="' + h.capacity_sleep + '"></label>'
          + '<label class="bk-field"><span>Отдыхают (максимум в заявке)</span><input name="capacity_party" type="number" min="1" value="' + h.capacity_party + '"></label>'
          + '<label class="bk-field"><span>Заезд</span><input name="checkin_time" type="time" value="' + h.checkin_time + '"></label>'
          + '<label class="bk-field"><span>Выезд</span><input name="checkout_time" type="time" value="' + h.checkout_time + '"></label>'
          + '<label class="bk-field"><span>Уборка Дома после выезда, мин</span><input name="cleanup_minutes" type="number" min="0" max="600" value="' + (h.cleanup_minutes != null ? h.cleanup_minutes : 60) + '"></label>'
          + '<label class="bk-field"><span>Порядок</span><input name="sort" type="number" min="0" value="' + h.sort + '"></label>'
          + '<label class="bk-check"><input type="checkbox" name="active"' + (h.active ? ' checked' : '') + '> показывать в форме</label>'
          + '<div class="bk-full"><button class="bk-btn bk-btn--ghost" type="submit">Сохранить Дом</button></div></form>'
          + '<form class="bk-formgrid" data-addon="' + esc(h.code) + '"><input type="hidden" name="house" value="' + esc(h.code) + '">'
          + '<label class="bk-check bk-full"><input type="checkbox" name="bookable_separately"' + (h.addon_bookable_separately ? ' checked' : '') + '> баню можно бронировать отдельно (по часам)</label>'
          + '<label class="bk-field"><span>Цена бани за час, ₽</span><input name="price_per_hour" type="number" min="1" value="' + (a.price_per_hour || '') + '"></label>'
          + '<label class="bk-field"><span>Минимум часов</span><input name="min_hours" type="number" min="1" max="24" value="' + (a.min_hours || 2) + '"></label>'
          + '<label class="bk-field"><span>Перерыв между сеансами, мин</span><input name="buffer_minutes" type="number" min="0" max="240" value="' + (a.buffer_minutes != null ? a.buffer_minutes : 60) + '"></label>'
          + '<div class="bk-full"><button class="bk-btn bk-btn--ghost" type="submit">Сохранить баню</button></div></form></section>';
      }).join('');
      qsa(self.pane, 'form[data-house]').forEach(function (f) {
        self.form(f, function (data) {
          data.op = 'save';
          return self.api.call('booking_manage_house', data).then(function (r) { r.success ? self.msg('Дом сохранён') : self.fail(r); });
        });
      });
      qsa(self.pane, 'form[data-addon]').forEach(function (f) {
        self.form(f, function (data) {
          data.op = 'save';
          return self.api.call('booking_manage_addon', data).then(function (r) { r.success ? self.msg('Баня сохранена') : self.fail(r); });
        });
      });
    });
  };

  // --- цены
  Admin.prototype.tab_prices = function () {
    var self = this;
    this.api.call('booking_manage_prices', { op: 'list' }).then(function (res) {
      if (!res.success) { self.fail(res); return; }
      var L = res.data.labels;
      self.pane.innerHTML = '<p class="bk-muted">Цена суток — по дню заезда этих суток. Праздники — по правилу (вкладка «Праздники») или по особой цене даты. Скидка сайта считается от всей суммы.</p>'
        + res.data.items.map(function (p) {
          return '<section class="bk-box"><h3>' + esc(p.name) + '</h3><form class="bk-formgrid" data-prices><input type="hidden" name="house" value="' + esc(p.house) + '">'
            + ['weekday', 'friday', 'saturday', 'sunday'].map(function (t) {
              return '<label class="bk-field"><span>' + esc(L[t]) + ', ₽</span><input name="price_' + t + '" type="number" min="1" required value="' + (p.rules[t] || '') + '"></label>';
            }).join('')
            + (p.addon_on ? '<p class="bk-muted bk-full">Баня отдельно, ₽ за час. Пусто — базовая цена ' + money(p.addon_base) + ' из «Дома и бани»</p>'
              + ['weekday', 'friday', 'saturday', 'sunday'].map(function (t) {
                return '<label class="bk-field"><span>Баня: ' + esc(L[t].toLowerCase()) + ', ₽/час</span><input name="addon_' + t + '" type="number" min="1" placeholder="' + (p.addon_base || '') + '" value="' + ((p.addon || {})[t] || '') + '"></label>';
              }).join('') : '')
            + '<label class="bk-field"><span>Гостей в цене</span><input name="extra_guest_limit" type="number" min="1" value="' + p.extra_guest_limit + '"></label>'
            + '<label class="bk-field"><span>Доплата за гостя сверх, ₽</span><input name="extra_guest_price" type="number" min="0" value="' + p.extra_guest_price + '"></label>'
            + '<label class="bk-field"><span>Питомец, ₽</span><input name="pet_price" type="number" min="0" value="' + p.pet_price + '"></label>'
            + '<div class="bk-full"><button class="bk-btn bk-btn--ghost" type="submit">Сохранить цены</button></div></form></section>';
        }).join('');
      qsa(self.pane, 'form[data-prices]').forEach(function (f) {
        self.form(f, function (data) {
          data.op = 'save';
          return self.api.call('booking_manage_prices', data).then(function (r) { r.success ? self.msg('Цены сохранены — на сайте обновятся сразу') : self.fail(r); });
        });
      });
    });
  };

  // --- праздники с особыми ценами по домам
  Admin.prototype.tab_holidays = function () {
    var self = this;
    this.api.call('booking_manage_holiday', { op: 'list' }).then(function (res) {
      if (!res.success) { self.fail(res); return; }
      var T = res.data.types;
      var typeOpts = function (sel) { return Object.keys(T).map(function (k) { return '<option value="' + k + '"' + (k === sel ? ' selected' : '') + '>' + esc(T[k]) + '</option>'; }).join(''); };
      var priceInputs = function (prices, addonPrices) {
        return self.houses.map(function (h) {
          return '<label class="bk-field"><span>Особая цена: ' + esc(h.name) + ', ₽</span><input name="price_' + esc(h.code) + '" type="number" min="1" placeholder="по правилу" value="' + ((prices || {})[h.code] || '') + '"></label>'
            + (h.addon_bookable_separately ? '<label class="bk-field"><span>Баня: ' + esc(h.name) + ', ₽/час</span><input name="addon_' + esc(h.code) + '" type="number" min="1" placeholder="по типу дня" value="' + ((addonPrices || {})[h.code] || '') + '"></label>' : '');
        }).join('');
      };
      var formHtml = function (h) {
        h = h || {};
        return '<form class="bk-formgrid" data-holiday="' + (h.id || '') + '"><label class="bk-field"><span>Дата</span><input name="date" type="date" required value="' + (h.date || '') + '"></label>'
          + '<label class="bk-field"><span>Тип</span><select name="holiday_type">' + typeOpts(h.holiday_type) + '</select></label>'
          + '<label class="bk-field bk-full"><span>Название</span><input name="comment" maxlength="255" value="' + esc(h.comment || '') + '" placeholder="Например: 8 Марта"></label>'
          + priceInputs(h.prices, h.addon_prices) + '<div class="bk-full"><button class="bk-btn bk-btn--ghost" type="submit">' + (h.id ? 'Сохранить' : 'Добавить праздник') + '</button>'
          + (h.id ? ' <button class="bk-btn bk-btn--danger" type="button" data-del="' + h.id + '">Удалить</button>' : '') + '</div></form>';
      };
      self.pane.innerHTML = '<section class="bk-box"><h3>Новая праздничная дата</h3>' + formHtml() + '</section>'
        + (res.data.items.length ? res.data.items.map(function (h) {
          return '<section class="bk-box"><h3>' + human(h.date, true) + (h.comment ? ' — ' + esc(h.comment) : '') + ' <small>по тарифу «' + esc(h.rule_day) + '»</small></h3>' + formHtml(h) + '</section>';
        }).join('') : '<p class="bk-muted">Праздников пока нет.</p>');
      qsa(self.pane, 'form[data-holiday]').forEach(function (f) {
        self.form(f, function (data) {
          var p = { op: 'save', id: f.getAttribute('data-holiday'), date: data.date, holiday_type: data.holiday_type, comment: data.comment, prices: {}, addon_prices: {} };
          self.houses.forEach(function (h) { p.prices[h.code] = data['price_' + h.code] || ''; p.addon_prices[h.code] = data['addon_' + h.code] || ''; });
          return self.api.call('booking_manage_holiday', p).then(function (r) { if (r.success) { self.msg('Праздник сохранён'); self.tab_holidays(); } else self.fail(r); });
        });
      });
      qsa(self.pane, '[data-del]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!window.confirm('Удалить праздник?')) return;
          self.api.call('booking_manage_holiday', { op: 'delete', id: b.getAttribute('data-del') }).then(function (r) { r.success ? self.tab_holidays() : self.fail(r); });
        });
      });
    });
  };

  // --- закрытые даты
  Admin.prototype.tab_blocks = function () {
    var self = this;
    this.api.call('booking_manage_block', { op: 'list' }).then(function (res) {
      if (!res.success) { self.fail(res); return; }
      self.pane.innerHTML = '<section class="bk-box"><h3>Закрыть даты</h3><p class="bk-muted">Дом: закрываются ночи с первой даты до ночи перед последней (как бронь «заезд — выезд»). Баня: дни включительно, время — по желанию.</p>'
        + '<form class="bk-formgrid" data-block><label class="bk-field"><span>Дом</span><select name="house" required>' + self.houseOptions('') + '</select></label>'
        + '<label class="bk-check"><input type="checkbox" name="addon"> только баня</label>'
        + '<label class="bk-field"><span>С</span><input name="date_from" type="date" required></label><label class="bk-field"><span>По</span><input name="date_to" type="date" required></label>'
        + '<label class="bk-field"><span>Баня с (время)</span><input name="time_from" type="time" step="1800"></label><label class="bk-field"><span>Баня до</span><input name="time_to" type="time" step="1800"></label>'
        + '<label class="bk-field bk-full"><span>Причина</span><input name="reason" required maxlength="255" placeholder="Ремонт, своё мероприятие…"></label>'
        + '<div class="bk-full"><button class="bk-btn" type="submit">Закрыть</button></div></form></section>'
        + (res.data.items.length ? '<table class="bk-table"><thead><tr><th>Дом</th><th>Что</th><th>Даты</th><th>Причина</th><th>Кто</th><th></th></tr></thead><tbody>'
          + res.data.items.map(function (s) {
            return '<tr><td>' + esc(s.house_name) + '</td><td>' + (s.addon_id ? 'баня' + (s.time_from ? ' ' + s.time_from.slice(0, 5) + '–' + s.time_to.slice(0, 5) : '') : 'Дом') + '</td>'
              + '<td>' + human(s.date_from) + ' — ' + human(s.date_to) + '</td><td>' + esc(s.reason) + '</td><td><small>' + esc(s.created_by) + '</small></td>'
              + '<td><button type="button" class="bk-btn bk-btn--sm bk-btn--ghost" data-del="' + s.id + '">Открыть</button></td></tr>';
          }).join('') + '</tbody></table>' : '<p class="bk-muted">Закрытых дат нет.</p>');
      self.form(qs(self.pane, 'form[data-block]'), function (data) {
        data.op = 'save';
        return self.api.call('booking_manage_block', data).then(function (r) { if (r.success) { self.msg('Даты закрыты'); self.tab_blocks(); } else self.fail(r); });
      });
      qsa(self.pane, '[data-del]').forEach(function (b) {
        b.addEventListener('click', function () {
          self.api.call('booking_manage_block', { op: 'delete', id: b.getAttribute('data-del') }).then(function (r) { r.success ? self.tab_blocks() : self.fail(r); });
        });
      });
    });
  };

  // --- токены для телефона (PWA, MAX)
  Admin.prototype.tab_tokens = function () {
    var self = this, app = this.root.getAttribute('data-bk-app');
    this.api.call('booking_issue_token', { op: 'list' }).then(function (res) {
      if (!res.success) { self.fail(res); return; }
      self.pane.innerHTML = '<section class="bk-box"><h3>Вход с телефона</h3><p>Приложение: ' + (app ? '<a class="bk-link" href="' + esc(app) + '" target="_blank" rel="noopener">' + esc(app) + '</a>' : '—')
        + '. Откройте его на телефоне, вкладка «Администратор», вставьте токен. Тот же адрес — для кнопки мини-приложения в боте MAX.</p>'
        + '<form class="bk-inline" data-issue><label class="bk-field"><span>Чей телефон</span><input name="label" required maxlength="100" placeholder="Телефон Ольги"></label><button class="bk-btn" type="submit">Выпустить токен</button></form>'
        + '<div data-new-token></div></section>'
        // номер (chat_id) группы администраторов и канала — для maxnotify3.max_recipient_ids и поста в канал, без SSH
        + '<section class="bk-box"><h3>MAX: группы и каналы бота</h3><p class="bk-muted">Добавьте бота в группу или канал администратором, напишите там любое сообщение и нажмите кнопку. '
        + 'Номер со знаком минус впишите в системную настройку <code>maxnotify3.max_recipient_ids</code> (несколько — через запятую).</p>'
        + '<p><button type="button" class="bk-btn bk-btn--ghost" data-maxchats>Найти группы и каналы</button></p><div data-maxout></div></section>'
        // бот отвечает гостям: MAX шлёт ему сообщения только на подключённый адрес сайта
        + '<section class="bk-box"><h3>MAX: бот отвечает гостям</h3><p class="bk-muted">Гости пишут боту в личные сообщения — он отвечает по ценам, заезду, бане, правилам и адресам с сайта. '
        + 'Чтобы MAX передавал боту сообщения, подключите адрес сайта. Нужны токен бота и https.</p><div data-botout><p class="bk-muted">Спрашиваем MAX…</p></div></section>'
        + (res.data.items.length ? '<table class="bk-table"><thead><tr><th>Подпись</th><th>Пользователь</th><th>Выпущен</th><th>Последний вход</th><th></th></tr></thead><tbody>'
          + res.data.items.map(function (t) {
            return '<tr><td>' + esc(t.label) + '</td><td>' + esc(t.user) + '</td><td><small>' + esc(t.created_at) + '</small></td><td><small>' + esc(t.last_used_at || '—') + '</small></td>'
              + '<td>' + (t.revoked_at ? 'отозван' : '<button type="button" class="bk-btn bk-btn--sm bk-btn--danger" data-revoke="' + t.id + '">Отозвать</button>') + '</td></tr>';
          }).join('') + '</tbody></table>' : '');
      self.form(qs(self.pane, 'form[data-issue]'), function (data) {
        data.op = 'issue';
        return self.api.call('booking_issue_token', data).then(function (r) {
          if (!r.success) { self.fail(r); return; }
          qs(self.pane, '[data-new-token]').innerHTML = '<p class="bk-note-orange">Токен показывается один раз — скопируйте сейчас:</p><p><code class="bk-token">' + esc(r.data.token) + '</code></p>';
        });
      });
      qs(self.pane, '[data-maxchats]').addEventListener('click', function (e) {
        var btn = e.currentTarget, out = qs(self.pane, '[data-maxout]');
        btn.disabled = true;
        out.innerHTML = '<p class="bk-muted">Спрашиваем MAX…</p>';
        self.api.call('booking_max_chats', {}).then(function (r) {
          btn.disabled = false;
          if (!r.success) { out.innerHTML = '<p class="bk-warn">' + esc(r.message) + '</p>'; return; }
          var d = r.data, now = String(d.recipients || '').split(/[\s,;]+/);
          out.innerHTML = '<p>Бот: <b>' + esc(d.bot) + '</b>' + (d.recipients ? '. Сейчас уведомления уходят в: <code>' + esc(d.recipients) + '</code>' : '. Номер для уведомлений ещё не вписан') + '</p>'
            + (d.items.length ? '<table class="bk-table"><thead><tr><th>Номер (chat_id)</th><th>Что это</th><th></th></tr></thead><tbody>' + d.items.map(function (c) {
                return '<tr><td><code class="bk-token">' + esc(c.id) + '</code></td><td>' + esc(c.title) + '</td><td>' + (now.indexOf(c.id) >= 0 ? 'уже получает уведомления' : '') + '</td></tr>';
              }).join('') + '</tbody></table>'
              : '<p class="bk-note-orange">' + (d.webhook ? 'Пока пусто. Напишите сообщение в группе или канале, где бот администратор, и нажмите ещё раз — бот запомнит номер.'
                : 'Групп не найдено. Бот добавлен в группу администратором? Напишите в группе сообщение и нажмите ещё раз.') + '</p>');
        });
      });
      var botOut = qs(self.pane, '[data-botout]');
      var bot = function (op) {
        qsa(botOut, 'button').forEach(function (b) { b.disabled = true; });
        self.api.call('booking_max_webhook', { op: op }).then(function (r) {
          if (!r.success) { botOut.innerHTML = '<p class="bk-warn">' + esc(r.message) + '</p>'; return; }
          if (r.data.no_token) { botOut.innerHTML = '<p class="bk-muted">Сначала впишите токен бота в системную настройку <code>maxnotify3.max_token</code> и откройте вкладку снова.</p>'; return; }
          var on = r.data.webhook && r.data.enabled;
          botOut.innerHTML = '<p>' + (on ? '<b>Бот отвечает гостям.</b> Адрес: <code>' + esc(r.data.url) + '</code>'
              : (r.data.webhook ? 'Адрес подключён, но бот выключен.' : 'Бот не подключён — гостям он не отвечает.')) + '</p>'
            + '<p>' + (on ? '<button type="button" class="bk-btn bk-btn--ghost" data-bot="off">Отключить</button> <button type="button" class="bk-btn bk-btn--ghost" data-bot="on">Подключить заново</button>'
              : '<button type="button" class="bk-btn" data-bot="on">Подключить бота</button>') + '</p>';
          qsa(botOut, '[data-bot]').forEach(function (b) {
            b.addEventListener('click', function () {
              if (b.getAttribute('data-bot') === 'off' && !window.confirm('Отключить бота? Гости перестанут получать ответы.')) return;
              bot(b.getAttribute('data-bot'));
            });
          });
        });
      };
      bot('status');
      qsa(self.pane, '[data-revoke]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!window.confirm('Отозвать токен? Телефон потеряет доступ администратора.')) return;
          self.api.call('booking_issue_token', { op: 'revoke', id: b.getAttribute('data-revoke') }).then(function (r) { r.success ? self.tab_tokens() : self.fail(r); });
        });
      });
    });
  };

  // --- инструкция для администраторов: с сервера, только вошедшим (в коде страниц для гостей её нет)
  Admin.prototype.tab_help = function () {
    var self = this;
    this.pane.innerHTML = '<p class="bk-muted">Загружаем…</p>';
    this.api.call('booking_help', {}).then(function (r) {
      if (!r.success) { self.fail(r); return; }
      self.pane.innerHTML = r.data.html;
      qsa(self.pane, '.bk-help__toc a').forEach(function (a) {
        a.addEventListener('click', function (e) {
          e.preventDefault();
          var t = self.pane.querySelector(a.getAttribute('href'));
          if (!t) return;
          // в админке MODX прокручивается своя область, а не страница: scrollIntoView сдвинул бы всю админку
          for (var p = t.parentElement; p && p !== document.body; p = p.parentElement) {
            var o = getComputedStyle(p).overflowY;
            if ((o === 'auto' || o === 'scroll') && p.scrollHeight > p.clientHeight) {
              p.scrollTo({ top: p.scrollTop + t.getBoundingClientRect().top - p.getBoundingClientRect().top - 12, behavior: 'smooth' });
              return;
            }
          }
          t.scrollIntoView({ behavior: 'smooth' });
        });
      });
    });
  };

  // --- перенос прежних заявок сайта
  Admin.prototype.tab_migrate = function () {
    var self = this;
    this.pane.innerHTML = '<section class="bk-box"><h3>Перенос прежних заявок</h3><p class="bk-muted">Заявки из раздела «Заявки» переносятся в брони: новые и «связались» — новыми, подтверждённые — подтверждёнными (если даты свободны), выполненные — завершёнными, отклонённые — отменёнными. Повторный запуск ничего не дублирует.</p>'
      + '<div class="bk-bar"><button type="button" class="bk-btn bk-btn--ghost" data-m="preview">Посмотреть</button><button type="button" class="bk-btn" data-m="run">Перенести</button>'
      + '<button type="button" class="bk-btn bk-btn--danger" data-m="retire">Вывести прежние «Заявки» из работы</button></div><div data-mout></div></section>';
    var LABEL = { move: 'будет перенесена', exists: 'уже перенесена', no_house: 'Дом не найден', bad_dates: 'неверные даты' };
    qsa(this.pane, '[data-m]').forEach(function (b) {
      b.addEventListener('click', function () {
        var op = b.getAttribute('data-m');
        if (op === 'run' && !window.confirm('Перенести прежние заявки?')) return;
        if (op === 'retire' && !window.confirm('Снять пункт меню «Заявки» и переименовать таблицы bania_* в *_archived? Делайте это после переноса и после замены формы на сайте.')) return;
        var out = qs(self.pane, '[data-mout]');
        out.innerHTML = '<p class="bk-muted">Выполняется…</p>';
        self.api.call('booking_migrate_leads', { op: op }).then(function (r) {
          if (!r.success) { out.innerHTML = '<p class="bk-warn">' + esc(r.message) + '</p>'; return; }
          var d = r.data;
          if (op === 'retire') { out.innerHTML = '<p>' + esc((d.done || []).join('; ') || 'Нечего выводить') + '</p>'; return; }
          out.innerHTML = (d.report ? '<p>Перенесено: ' + d.report.moved + ', с конфликтом дат (стали новыми): ' + d.report.conflicts + ', пропущено: ' + d.report.skipped + ', ошибок: ' + d.report.errors + '</p>' : '')
            + (d.message ? '<p>' + esc(d.message) + '</p>' : '')
            + (d.items && d.items.length ? '<table class="bk-table"><thead><tr><th>Заявка</th><th>Дом</th><th>Даты</th><th>Было</th><th>Станет</th><th>Итог</th></tr></thead><tbody>'
              + d.items.map(function (i) {
                return '<tr><td>№' + i.lead_id + ' ' + esc(i.name) + '</td><td>' + esc(i.house_name) + '</td><td>' + esc(i.dates) + '</td><td>' + esc(i.old_status) + '</td>'
                  + '<td>' + esc(i.new_status ? STATUS[i.new_status] : '—') + '</td><td>' + esc(i.result ? (i.result === 'conflict' ? 'конфликт дат' : i.result === 'error' ? 'ошибка: ' + i.error : 'перенесена') : LABEL[i.action])
                  + (i.booking_id ? ' → <button type="button" class="bk-link" data-open="' + i.booking_id + '">№' + i.booking_id + '</button>' : '') + '</td></tr>';
              }).join('') + '</tbody></table>' : '');
        });
      });
    });
  };

  // ---------------------------------------------------------------- приложение (PWA / мини-приложение MAX)
  function App(root) {
    var self = this;
    this.root = root;
    this.api = root.getAttribute('data-bk-api');
    this.client = /MAX|max\.ru/i.test(navigator.userAgent) || /[?&]client=max/.test(location.search) ? 'max' : 'pwa';
    // источник заявки гостя — из MAX или из приложения на телефоне (форма отрисована с client=pwa)
    qsa(root, 'input[name=client]').forEach(function (i) { i.value = self.client; });
    qsa(root, '[data-app-tab]').forEach(function (b) {
      b.addEventListener('click', function () { self.tab(b.getAttribute('data-app-tab')); });
    });
    var login = qs(root, '[data-app-login]');
    if (login) login.addEventListener('submit', function (e) {
      e.preventDefault();
      var t = qs(login, '[name=token]').value.trim();
      self.tryToken(t, true);
    });
    var out = qs(root, '[data-app-logout]');
    if (out) out.addEventListener('click', function () {
      if (!window.confirm('Выйти из режима администратора? Для входа снова понадобится токен.')) return;
      self.setToken('');
      self.tab('admin');
    });
    window.addEventListener('online', function () { self.net(); });
    window.addEventListener('offline', function () { self.net(); });
    this.net();
    // кнопка «?» — инструкция; «Вернуться» — на вкладку, с которой пришли
    var help = qs(root, '[data-app-help]');
    if (help) help.addEventListener('click', function () { self.tab(self.current === 'help' ? (self.prev || 'book') : 'help'); window.scrollTo(0, 0); });
    var back = qs(root, '[data-app-back]');
    if (back) back.addEventListener('click', function () { self.tab(self.prev || 'book'); window.scrollTo(0, 0); });
    qsa(root, '[data-help-jump]').forEach(function (a) {
      a.addEventListener('click', function (e) { e.preventDefault(); self.helpShow(a.getAttribute('href').slice(1)); });
    });
    this.install();
    var h = location.hash.replace('#', '').split('&')[0];   // #admin&id=3 — из сообщения MAX о заявке
    this.tab(['admin', 'request', 'help'].indexOf(h) >= 0 ? h : 'book');
  }
  /* Установка на телефон: Android/Chrome — системное окно (beforeinstallprompt), iPhone — подсказка «На экран „Домой“».
     Уже установлено (окно без адресной строки) — не показываем; полоску сверху можно скрыть крестиком. */
  App.prototype.install = function () {
    var self = this, root = this.root, bar = qs(root, '[data-app-install]'), txt = qs(root, '[data-app-install-text]');
    if (!bar) return;
    // внутри MAX страница не ставится на телефон (нет окна установки) — предлагаем открыть её в браузере
    if (this.client === 'max') { this.installMax(bar, txt); return; }
    var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    var ios = /iPhone|iPad|iPod/i.test(navigator.userAgent);
    var hidden = false;
    try { hidden = localStorage.getItem('bk-install-hide') === '1'; } catch (e) { /* хранилище недоступно */ }
    var btns = qsa(root, '[data-app-install-btn]');
    var hideAll = function () { bar.hidden = true; btns.forEach(function (b) { b.hidden = true; }); };
    var show = function (iosHint) {
      if (standalone) return;
      if (!hidden) bar.hidden = false;
      btns.forEach(function (b) { b.hidden = false; });
      if (iosHint) {
        txt.textContent = 'На iPhone: «Поделиться» внизу экрана → «На экран „Домой“».';
        btns.forEach(function (b) { b.textContent = 'Как установить'; });
      }
    };
    btns.forEach(function (b) {
      b.addEventListener('click', function () {
        var p = window.bkInstallPrompt;
        if (p) {
          p.prompt();
          p.userChoice.then(function () { window.bkInstallPrompt = null; hideAll(); });
          return;
        }
        if (self.current !== 'help') self.tab('help');
        self.helpShow('help-install');
      });
    });
    qs(root, '[data-app-install-close]').addEventListener('click', function () {
      bar.hidden = true;
      try { localStorage.setItem('bk-install-hide', '1'); } catch (e) { /* хранилище недоступно */ }
    });
    if (window.bkInstallPrompt) show(false);
    document.addEventListener('bk:installable', function () { show(false); });
    window.addEventListener('appinstalled', hideAll);
    if (ios) show(true);
  };
  /* MAX: ссылка открывается во внешнем браузере через мост MAX (st.max.ru, подключается только в MAX); нет моста — копируем адрес */
  App.prototype.installMax = function (bar, txt) {
    var root = this.root, url = location.origin + location.pathname, hidden = false;
    try { hidden = localStorage.getItem('bk-install-hide') === '1'; } catch (e) { /* хранилище недоступно */ }
    if (!window.WebApp) {
      var s = document.createElement('script');
      s.src = 'https://st.max.ru/js/max-web-app.js';
      s.async = true;
      document.head.appendChild(s);
    }
    txt.textContent = 'В MAX приложение не ставится на экран. Откройте страницу в браузере телефона — там будет кнопка «Установить».';
    var btns = qsa(root, '[data-app-install-btn]');
    btns.forEach(function (b) {
      b.hidden = false;
      b.textContent = 'Открыть в браузере';
      b.addEventListener('click', function () {
        if (window.WebApp && window.WebApp.openLink) { window.WebApp.openLink(url); return; }
        var done = function () { txt.textContent = 'Адрес скопирован: вставьте его в браузер телефона — ' + url; };
        if (navigator.clipboard) navigator.clipboard.writeText(url).then(done, function () { txt.textContent = 'Откройте в браузере: ' + url; });
        else txt.textContent = 'Откройте в браузере: ' + url;
      });
    });
    qs(root, '[data-app-install-close]').addEventListener('click', function () {
      bar.hidden = true;
      try { localStorage.setItem('bk-install-hide', '1'); } catch (e) { /* хранилище недоступно */ }
    });
    if (!hidden) bar.hidden = false;
  };
  /* Инструкция: один раздел на экране — «Гостям», «Администраторам» или «Установить» */
  App.prototype.helpShow = function (id) {
    this.helpPart = id;
    qsa(this.root, '.bk-help[id]').forEach(function (a) { a.hidden = a.id !== id; });
    qsa(this.root, '[data-help-jump]').forEach(function (a) {
      if (a.getAttribute('href') === '#' + id) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
  };
  App.prototype.token = function () { try { return localStorage.getItem('bk-admin-token') || ''; } catch (e) { return ''; } };
  App.prototype.setToken = function (t) { try { if (t) localStorage.setItem('bk-admin-token', t); else localStorage.removeItem('bk-admin-token'); } catch (e) { /* хранилище недоступно */ } };
  App.prototype.net = function () {
    var w = qs(this.root, '[data-app-offline]');
    if (w) w.hidden = navigator.onLine !== false;
  };
  App.prototype.tab = function (name) {
    if (name !== this.current) { this.prev = this.current; this.current = name; }
    var fab = qs(this.root, '[data-app-help]');
    if (fab) { fab.textContent = name === 'help' ? '×' : '?'; fab.setAttribute('aria-label', name === 'help' ? 'Закрыть инструкцию' : 'Как пользоваться'); }
    qsa(this.root, '[data-app-tab]').forEach(function (b) { b.setAttribute('aria-selected', b.getAttribute('data-app-tab') === name ? 'true' : 'false'); });
    qsa(this.root, '[data-app-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-app-pane') !== name; });
    if (name === 'admin') this.admin();
    if (name === 'help') this.helpShow(this.helpPart || 'help-guest');
    this.adminShown();
  };
  /* Вход администратора: форма токена или панель; «Выйти» в шапке — только при открытой панели */
  App.prototype.adminShown = function (on) {
    var login = qs(this.root, '[data-app-login]'), box = qs(this.root, '[data-app-admin]'), out = qs(this.root, '[data-app-logout]');
    if (on !== undefined) { login.hidden = on; box.hidden = !on; }
    if (out) out.hidden = !(this.current === 'admin' && !box.hidden);
  };
  /* Вкладка администратора — только с действующим токеном (ст. 3.4); без него — только гостевая часть */
  App.prototype.admin = function () {
    var t = this.token();
    var login = qs(this.root, '[data-app-login]'), box = qs(this.root, '[data-app-admin]');
    if (!t) { this.adminShown(false); return; }
    this.tryToken(t, false);
  };
  App.prototype.tryToken = function (t, fresh) {
    var self = this, login = qs(this.root, '[data-app-login]'), box = qs(this.root, '[data-app-admin]'), err = qs(login, '[data-app-err]');
    // токен — латиница, цифры, «-» и «_»; иное браузер даже не отправит в заголовке
    if (!/^[A-Za-z0-9_-]{20,128}$/.test(t)) {
      self.setToken('');
      err.textContent = 'Токен не подошёл: скопируйте его из админки целиком';
      self.adminShown(false);
      return;
    }
    var client = new Client(this.api, { token: t, client: this.client });
    client.call('booking_manage_house', { op: 'list' }).then(function (res) {
      if (res.offline) { err.textContent = res.message; self.adminShown(false); return; }
      if (!res.success) {
        self.setToken('');
        err.textContent = res.status === 401 ? 'Токен не подошёл или отозван' : res.message;
        self.adminShown(false);
        return;
      }
      err.textContent = '';
      if (fresh) self.setToken(t);
      self.adminShown(true);
      var mount = qs(box, '[data-bk-admin-mount]');
      if (!self.adminView) self.adminView = new Admin(mount, client);
    });
  };

  // ---------------------------------------------------------------- запуск
  function boot() {
    qsa(document, 'form[data-bk="guest"]').forEach(function (f) { if (!f.bk) f.bk = new GuestForm(f); });
    qsa(document, '[data-bk="request"]').forEach(function (r) { if (!r.bk) r.bk = new RequestPage(r); });
    qsa(document, '[data-bk="admin"]').forEach(function (r) { if (!r.bk) r.bk = new Admin(r); });
    qsa(document, '[data-bk="app"]').forEach(function (r) { if (!r.bk) r.bk = new App(r); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
  window.BookingUI = { boot: boot, Client: Client, MonthCalendar: MonthCalendar };
})();
