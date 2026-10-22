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

  // ---------------------------------------------------------------- помощники
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(n) { return String(Math.round(n || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ₽'; }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
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
        + '<div class="bk-cal__grid" role="grid">' + WD.map(function (w) { return '<span class="bk-cal__wd">' + w + '</span>'; }).join('');
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
        html += '<button type="button" class="' + cls.join(' ') + '" data-day="' + s + '"' + (disabled ? ' disabled' : '')
          + ' aria-label="' + human(s, true) + (busy ? ', занято' : '') + '">' + day + '</button>';
      }
      html += '</div></div>';
    }
    html += '</div><div class="bk-cal__legend"><span><i class="bk-sw bk-sw--free"></i>свободно</span><span><i class="bk-sw bk-sw--busy"></i>занято</span>'
      + '<span><i class="bk-sw bk-sw--pick"></i>ваши даты</span></div>';
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
    if (this.el.phone) this.el.phone.addEventListener('input', function () { maskPhone(self.el.phone); });
    // перед отправкой SendIt: даты выбраны?
    document.addEventListener('si:send:before', function (e) {
      if (!self.owns(e)) return;
      if (!self.el.from.value || (!self.isBath() && !self.el.to.value)) {
        e.preventDefault();
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
      self.cal.setBusy(res.data.busy);
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
      if (d.day_busy) { self.el.slots.innerHTML = '<span class="bk-warn">В этот день дом забронирован — баня занята</span>'; return; }
      self.el.slots.innerHTML = d.slots_busy.length
        ? 'Занято: ' + d.slots_busy.map(function (s) { return esc(s.from + '–' + s.to); }).join(', ') + '. Между сеансами ' + d.buffer_minutes + ' мин на уборку.'
        : 'Весь день свободен.';
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
      q.innerHTML = renderQuote(res.data);
    });
  };
  GuestForm.prototype.qreq = 0;
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

  function renderQuote(d) {
    var rows = d.lines.map(function (l) {
      return '<tr' + (l.amount < 0 ? ' class="bk-quote__disc"' : '') + '><td>' + esc(l.label) + '</td><td>' + (l.amount < 0 ? '−' + money(-l.amount) : money(l.amount)) + '</td></tr>';
    }).join('');
    return '<table class="bk-quote__t"><tbody>' + rows + '</tbody><tfoot><tr><th>Итого</th><th>' + money(d.total) + '</th></tr></tfoot></table>'
      + (d.checkin ? '<p class="bk-muted">Заезд с ' + esc(d.checkin) + ', выезд до ' + esc(d.checkout) + '</p>' : '');
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
    var canCancel = (d.status === 'new' || d.status === 'confirmed') && !d.cancellation_requested;
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
    grid: 'Календарь', list: 'Заявки', houses: 'Дома и бани', prices: 'Цены', holidays: 'Праздники',
    blocks: 'Закрытые даты', tokens: 'Токены', migrate: 'Перенос заявок'
  };

  function Admin(root, client) {
    this.root = root;
    this.api = client || new Client(root.getAttribute('data-bk-endpoint'), { modauth: root.getAttribute('data-bk-modauth') });
    this.tabs = (root.getAttribute('data-bk-tabs') || 'grid,list').split(',');
    this.houses = [];
    var t = new Date();
    this.gridFrom = iso(new Date(t.getFullYear(), t.getMonth(), t.getDate() - 2, 12));
    this.gridDays = window.matchMedia('(max-width: 760px)').matches ? 14 : 31;
    this.listFilter = { status: 'new,confirmed', house: '', q: '', page: 1 };
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
    this.pane.innerHTML = '<div class="bk-bar"><button type="button" class="bk-btn bk-btn--sm bk-btn--ghost" data-g="-7">‹ неделя</button>'
      + '<button type="button" class="bk-btn bk-btn--sm bk-btn--ghost" data-g="0">сегодня</button>'
      + '<button type="button" class="bk-btn bk-btn--sm bk-btn--ghost" data-g="7">неделя ›</button>'
      + '<span class="bk-legend"><i class="bk-sw bk-sw--ok"></i>подтверждена <i class="bk-sw bk-sw--new"></i>новые заявки <i class="bk-sw bk-sw--cancel"></i>просят отмену <i class="bk-sw bk-sw--block"></i>закрыто</span></div>'
      + '<div class="bk-gridwrap"><p class="bk-muted">Загружаем…</p></div>';
    qsa(this.pane, '[data-g]').forEach(function (b) {
      b.addEventListener('click', function () {
        var n = +b.getAttribute('data-g');
        if (n === 0) { var t = new Date(); self.gridFrom = iso(new Date(t.getFullYear(), t.getMonth(), t.getDate() - 2, 12)); }
        else self.gridFrom = addDays(self.gridFrom, n);
        self.tab_grid();
      });
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
  Admin.prototype.card = function (id) {
    var self = this, box = qs(this.dialog, '.bk-dialog__body');
    box.innerHTML = '<p class="bk-muted">Загружаем…</p>';
    if (!this.dialog.open) this.dialog.showModal();
    this.api.call('booking_get', { id: id }).then(function (res) {
      if (!res.success) { box.innerHTML = '<p class="bk-warn">' + esc(res.message) + '</p>'; return; }
      self.renderCard(res.data);
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
    box.innerHTML = '<h3>' + (d.addon ? 'Баня' : 'Бронь') + ' №' + d.id + ' <span class="bk-badge bk-badge--' + d.status + '">' + esc(STATUS[d.status]) + '</span>'
      + (d.cancellation_requested && active ? ' <span class="bk-badge bk-badge--cancel">просят отмену</span>' : '') + '</h3>'
      + '<dl class="bk-dl"><dt>Дом</dt><dd>' + esc(d.house_name) + '</dd><dt>Даты</dt><dd>' + esc(d.dates) + '</dd>'
      + '<dt>Гость</dt><dd>' + esc(d.guest_name) + ', <a href="tel:' + esc(d.guest_phone) + '">' + esc(d.guest_phone) + '</a></dd>'
      + '<dt>Гостей</dt><dd>' + d.guests + (d.pets ? ', питомцев: ' + d.pets : '') + '</dd>'
      + (d.guest_comment ? '<dt>Комментарий</dt><dd>' + esc(d.guest_comment) + '</dd>' : '')
      + (d.cancellation_reason ? '<dt>Отмена</dt><dd>' + esc(d.cancellation_reason) + '</dd>' : '')
      + '<dt>Залог</dt><dd>' + (d.deposit_amount ? money(d.deposit_amount) + ' — ' : '') + esc(DEPOSIT[d.deposit_status]) + '</dd>'
      + '<dt>Источник</dt><dd>' + esc(d.source) + (d.legacy_lead_id ? ' (прежняя заявка №' + d.legacy_lead_id + ')' : '') + '</dd></dl>'
      + '<table class="bk-quote__t"><tbody>' + lines + '</tbody><tfoot><tr><th>Итого</th><th>' + money(d.total) + '</th></tr></tfoot></table>'
      + (overlaps ? '<div class="bk-overlaps"><b>На эти даты ещё:</b><ul>' + overlaps + '</ul></div>' : '')
      + '<div class="bk-actions">'
      + (d.status === 'new' ? '<form data-act="confirm" class="bk-inline"><label class="bk-field"><span>Залог, ₽ (5 000–20 000, необязательно)</span><input name="deposit_amount" type="number" min="5000" max="20000" step="500" inputmode="numeric"></label><button class="bk-btn" type="submit">Подтвердить</button></form>' : '')
      + (d.status === 'confirmed' ? '<button type="button" class="bk-btn bk-btn--ghost" data-act="complete">Гости уехали — завершить</button>' : '')
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
  };

  // --- список заявок и бронь по телефону
  Admin.prototype.tab_list = function () {
    var self = this, f = this.listFilter;
    var st = [['new,confirmed', 'Активные'], ['new', 'Новые'], ['cancel_requested', 'Просят отмену'], ['confirmed', 'Подтверждённые'], ['completed', 'Завершённые'], ['cancelled', 'Отменённые'], ['', 'Все']];
    this.pane.innerHTML = '<form class="bk-bar" data-filter><select name="status">' + st.map(function (s) { return '<option value="' + s[0] + '"' + (s[0] === f.status ? ' selected' : '') + '>' + s[1] + '</option>'; }).join('') + '</select>'
      + '<select name="house">' + this.houseOptions(f.house, 'Все дома') + '</select><input name="q" type="search" placeholder="Имя, телефон, №" value="' + esc(f.q) + '">'
      + '<button class="bk-btn bk-btn--sm bk-btn--ghost" type="submit">Найти</button>'
      + '<button class="bk-btn bk-btn--sm" type="button" data-manual>Бронь по телефону</button></form><div class="bk-listwrap"><p class="bk-muted">Загружаем…</p></div>';
    var ff = qs(this.pane, '[data-filter]');
    this.form(ff, function (data) { Object.assign(f, data, { page: 1 }); self.tab_list(); });
    qs(this.pane, '[data-manual]').addEventListener('click', function () { self.manual(); });
    this.api.call('booking_list', f).then(function (res) {
      var w = qs(self.pane, '.bk-listwrap');
      if (!res.success) { w.innerHTML = '<p class="bk-warn">' + esc(res.message) + '</p>'; return; }
      var d = res.data;
      if (!d.items.length) { w.innerHTML = '<p class="bk-muted">Ничего не найдено</p>'; return; }
      w.innerHTML = '<table class="bk-table"><thead><tr><th>№</th><th>Статус</th><th>Дом</th><th>Даты</th><th>Гость</th><th>Гостей</th><th>Сумма</th><th>Залог</th><th>Создана</th></tr></thead><tbody>'
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
  Admin.prototype.manual = function () {
    var self = this, box = qs(this.dialog, '.bk-dialog__body');
    box.innerHTML = '<h3>Бронь по телефону</h3><p class="bk-muted">Сразу подтверждённая: даты займутся. Те же проверки пересечений, что и при подтверждении.</p>'
      + '<form class="bk-formgrid" data-manual-form><label class="bk-field"><span>Дом</span><select name="house" required>' + this.houseOptions('') + '</select></label>'
      + '<label class="bk-field"><span>Только баня</span><select name="addon"><option value="0">нет, дом на сутки</option><option value="1">да, баня по часам</option></select></label>'
      + '<label class="bk-field"><span>Заезд / дата</span><input name="date_from" type="date" required></label><label class="bk-field"><span>Выезд</span><input name="date_to" type="date"></label>'
      + '<label class="bk-field"><span>Баня с</span><input name="time_from" type="time" step="1800"></label><label class="bk-field"><span>Баня до</span><input name="time_to" type="time" step="1800"></label>'
      + '<label class="bk-field"><span>Гостей</span><input name="guests" type="number" min="1" required></label><label class="bk-field"><span>Питомцев</span><input name="pets" type="number" min="0" value="0"></label>'
      + '<label class="bk-field"><span>Имя гостя</span><input name="guest_name" required></label><label class="bk-field"><span>Телефон</span><input name="guest_phone" type="tel" required></label>'
      + '<label class="bk-field"><span>Залог, ₽ (необязательно)</span><input name="deposit_amount" type="number" min="5000" max="20000" step="500"></label>'
      + '<label class="bk-check"><input type="checkbox" name="apply_discount"> скидка 5% — гость из группы ВКонтакте</label>'
      + '<label class="bk-field bk-full"><span>Комментарий гостя</span><textarea name="guest_comment" rows="2"></textarea></label>'
      + '<label class="bk-field bk-full"><span>Заметка администратора</span><textarea name="admin_note" rows="2"></textarea></label>'
      + '<div class="bk-full"><button class="bk-btn" type="submit">Создать подтверждённую бронь</button></div></form>';
    if (!this.dialog.open) this.dialog.showModal();
    this.form(qs(box, '[data-manual-form]'), function (data) {
      return self.api.call('booking_create_manual', data).then(function (res) {
        if (!res.success) { window.alert(res.message); return; }
        self.msg('Бронь №' + res.data.id + ' создана и подтверждена');
        self.card(res.data.id);
        if (self.current === 'grid' || self.current === 'list') self.show(self.current);
      });
    });
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
          + '<label class="bk-field"><span>Порядок</span><input name="sort" type="number" min="0" value="' + h.sort + '"></label>'
          + '<label class="bk-check"><input type="checkbox" name="active"' + (h.active ? ' checked' : '') + '> показывать в форме</label>'
          + '<div class="bk-full"><button class="bk-btn bk-btn--ghost" type="submit">Сохранить дом</button></div></form>'
          + '<form class="bk-formgrid" data-addon="' + esc(h.code) + '"><input type="hidden" name="house" value="' + esc(h.code) + '">'
          + '<label class="bk-check bk-full"><input type="checkbox" name="bookable_separately"' + (h.addon_bookable_separately ? ' checked' : '') + '> баню можно бронировать отдельно (по часам)</label>'
          + '<label class="bk-field"><span>Цена бани за час, ₽</span><input name="price_per_hour" type="number" min="1" value="' + (a.price_per_hour || '') + '"></label>'
          + '<label class="bk-field"><span>Минимум часов</span><input name="min_hours" type="number" min="1" max="24" value="' + (a.min_hours || 2) + '"></label>'
          + '<label class="bk-field"><span>Перерыв между сеансами, мин</span><input name="buffer_minutes" type="number" min="0" max="240" value="' + (a.buffer_minutes != null ? a.buffer_minutes : 30) + '"></label>'
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
      var priceInputs = function (prices) {
        return self.houses.map(function (h) {
          return '<label class="bk-field"><span>Особая цена: ' + esc(h.name) + ', ₽</span><input name="price_' + esc(h.code) + '" type="number" min="1" placeholder="по правилу" value="' + ((prices || {})[h.code] || '') + '"></label>';
        }).join('');
      };
      var formHtml = function (h) {
        h = h || {};
        return '<form class="bk-formgrid" data-holiday="' + (h.id || '') + '"><label class="bk-field"><span>Дата</span><input name="date" type="date" required value="' + (h.date || '') + '"></label>'
          + '<label class="bk-field"><span>Тип</span><select name="holiday_type">' + typeOpts(h.holiday_type) + '</select></label>'
          + '<label class="bk-field bk-full"><span>Название</span><input name="comment" maxlength="255" value="' + esc(h.comment || '') + '" placeholder="Например: 8 Марта"></label>'
          + priceInputs(h.prices) + '<div class="bk-full"><button class="bk-btn bk-btn--ghost" type="submit">' + (h.id ? 'Сохранить' : 'Добавить праздник') + '</button>'
          + (h.id ? ' <button class="bk-btn bk-btn--danger" type="button" data-del="' + h.id + '">Удалить</button>' : '') + '</div></form>';
      };
      self.pane.innerHTML = '<section class="bk-box"><h3>Новая праздничная дата</h3>' + formHtml() + '</section>'
        + (res.data.items.length ? res.data.items.map(function (h) {
          return '<section class="bk-box"><h3>' + human(h.date, true) + (h.comment ? ' — ' + esc(h.comment) : '') + ' <small>по тарифу «' + esc(h.rule_day) + '»</small></h3>' + formHtml(h) + '</section>';
        }).join('') : '<p class="bk-muted">Праздников пока нет.</p>');
      qsa(self.pane, 'form[data-holiday]').forEach(function (f) {
        self.form(f, function (data) {
          var p = { op: 'save', id: f.getAttribute('data-holiday'), date: data.date, holiday_type: data.holiday_type, comment: data.comment, prices: {} };
          self.houses.forEach(function (h) { p.prices[h.code] = data['price_' + h.code] || ''; });
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
            return '<tr><td>' + esc(s.house_name) + '</td><td>' + (s.addon_id ? 'баня' + (s.time_from ? ' ' + s.time_from.slice(0, 5) + '–' + s.time_to.slice(0, 5) : '') : 'дом') + '</td>'
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
      qsa(self.pane, '[data-revoke]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!window.confirm('Отозвать токен? Телефон потеряет доступ администратора.')) return;
          self.api.call('booking_issue_token', { op: 'revoke', id: b.getAttribute('data-revoke') }).then(function (r) { r.success ? self.tab_tokens() : self.fail(r); });
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
    var LABEL = { move: 'будет перенесена', exists: 'уже перенесена', no_house: 'дом не найден', bad_dates: 'неверные даты' };
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
    if (out) out.addEventListener('click', function () { self.setToken(''); self.tab('admin'); });
    window.addEventListener('online', function () { self.net(); });
    window.addEventListener('offline', function () { self.net(); });
    this.net();
    this.tab(location.hash === '#admin' ? 'admin' : (location.hash === '#request' ? 'request' : 'book'));
  }
  App.prototype.token = function () { try { return localStorage.getItem('bk-admin-token') || ''; } catch (e) { return ''; } };
  App.prototype.setToken = function (t) { try { if (t) localStorage.setItem('bk-admin-token', t); else localStorage.removeItem('bk-admin-token'); } catch (e) { /* хранилище недоступно */ } };
  App.prototype.net = function () {
    var w = qs(this.root, '[data-app-offline]');
    if (w) w.hidden = navigator.onLine !== false;
  };
  App.prototype.tab = function (name) {
    qsa(this.root, '[data-app-tab]').forEach(function (b) { b.setAttribute('aria-selected', b.getAttribute('data-app-tab') === name ? 'true' : 'false'); });
    qsa(this.root, '[data-app-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-app-pane') !== name; });
    if (name === 'admin') this.admin();
  };
  /* Вкладка администратора — только с действующим токеном (ст. 3.4); без него — только гостевая часть */
  App.prototype.admin = function () {
    var t = this.token();
    var login = qs(this.root, '[data-app-login]'), box = qs(this.root, '[data-app-admin]');
    if (!t) { login.hidden = false; box.hidden = true; return; }
    this.tryToken(t, false);
  };
  App.prototype.tryToken = function (t, fresh) {
    var self = this, login = qs(this.root, '[data-app-login]'), box = qs(this.root, '[data-app-admin]'), err = qs(login, '[data-app-err]');
    var client = new Client(this.api, { token: t, client: this.client });
    client.call('booking_manage_house', { op: 'list' }).then(function (res) {
      if (res.offline) { err.textContent = res.message; login.hidden = false; box.hidden = true; return; }
      if (!res.success) {
        self.setToken('');
        err.textContent = res.status === 401 ? 'Токен не подошёл или отозван' : res.message;
        login.hidden = false; box.hidden = true;
        return;
      }
      err.textContent = '';
      if (fresh) self.setToken(t);
      login.hidden = true; box.hidden = false;
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
