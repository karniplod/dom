/* Доходные дома Ефремовых — поведение страниц.
   Данные домов приходят в window.HOUSES (собирается из src/houses.py, в MODX — из TV). */
(function () {
  var HOUSES = window.HOUSES || {};
  var DISCOUNT = HOUSES._discount || 0; delete HOUSES._discount;
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var calm = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  var rub = function (n) { return n.toLocaleString("ru-RU").replace(/ |,/g, " ") + " ₽"; };
  function plural(n, a, b, c) { var m10 = n % 10, m100 = n % 100; if (m10 === 1 && m100 !== 11) return a; if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return b; return c; }

  // Плавные окна на <dialog>: форма заявки, мобильное меню, просмотр фото.
  var openCount = 0;
  function openModal(dlg) {
    if (!dlg || dlg.open || !dlg.showModal) return;
    dlg.showModal(); openCount++;
    document.documentElement.classList.add("is-locked");
    requestAnimationFrame(function () { requestAnimationFrame(function () { dlg.classList.add("is-open"); }); });
  }
  function closeModal(dlg, then) {
    if (!dlg || !dlg.open || dlg.classList.contains("is-closing")) return;
    dlg.classList.remove("is-open"); dlg.classList.add("is-closing");
    setTimeout(function () {
      dlg.classList.remove("is-closing"); dlg.close();
      if (--openCount <= 0) { openCount = 0; document.documentElement.classList.remove("is-locked"); }
      if (then) then();
    }, calm ? 0 : 320);
  }
  $$("dialog.modal").forEach(function (dlg) {
    dlg.addEventListener("cancel", function (e) { e.preventDefault(); closeModal(dlg); });
    dlg.addEventListener("click", function (e) { if (e.target === dlg || e.target.closest("[data-close]")) closeModal(dlg); });
  });

  // Текущий пункт меню — по адресу страницы (в MODX шапка одна на все страницы).
  var here = location.pathname.replace(/\/$/, "");
  $$(".nav a, .nav__house, .drawer__nav a").forEach(function (a) {
    if (a.hasAttribute("aria-current") || a.hash) return;
    if (a.pathname.replace(/\/$/, "") === here && here !== "") { a.setAttribute("aria-current", "page"); var d = a.closest(".nav__drop"); if (d) d.querySelector(".nav__btn").classList.add("is-current"); }
  });

  // Меню: выпадающий список домов на десктопе, выезжающая панель на телефоне.
  $$(".nav__drop").forEach(function (drop) {
    var btn = drop.querySelector(".nav__btn");
    function set(on) { drop.classList.toggle("is-open", on); btn.setAttribute("aria-expanded", on ? "true" : "false"); }
    btn.addEventListener("click", function (e) { e.stopPropagation(); set(!drop.classList.contains("is-open")); });
    document.addEventListener("click", function (e) { if (!drop.contains(e.target)) set(false); });
    drop.addEventListener("keydown", function (e) { if (e.key === "Escape") { set(false); btn.focus(); } });
    drop.addEventListener("focusout", function (e) { if (!drop.contains(e.relatedTarget)) set(false); });
  });
  var drawer = document.getElementById("drawer");
  $$("[data-drawer]").forEach(function (b) { b.addEventListener("click", function () { openModal(drawer); }); });
  if (drawer) $$("a", drawer).forEach(function (a) { a.addEventListener("click", function () { if (a.hash && a.pathname === location.pathname) closeModal(drawer); }); });

  // Лайтбокс для фото: ссылки с data-lb открывают окно. Каждая группа data-lb листается отдельно.
  var links = $$("a[data-lb]");
  if (links.length) {
    var ARR = function (d) { return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' + (d < 0 ? "m15 5-7 7 7 7" : "m9 5 7 7-7 7") + '"/></svg>'; };
    var dlg = document.createElement("dialog"); dlg.className = "modal lb"; dlg.setAttribute("aria-label", "Просмотр фото");
    dlg.innerHTML = '<div class="lb__stage"><img alt=""><button class="lb__nav lb__prev" type="button" data-d="-1" aria-label="Предыдущее фото">' + ARR(-1) + '</button><button class="lb__nav lb__next" type="button" data-d="1" aria-label="Следующее фото">' + ARR(1) + '</button></div>' +
      '<div class="lb__bar"><span class="lb__cap"></span><span class="lb__count tnum"></span></div>' +
      '<button class="lb__x" type="button" data-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>';
    document.body.appendChild(dlg);
    var lbImg = dlg.querySelector("img"), lbCap = dlg.querySelector(".lb__cap"), lbCount = dlg.querySelector(".lb__count"), cur = 0, group = links;
    function show(i) {
      cur = (i + group.length) % group.length; var a = group[cur];
      lbImg.classList.add("is-swap");
      var pre = new Image(); pre.onload = pre.onerror = function () { lbImg.src = a.href; lbImg.alt = a.getAttribute("data-cap") || ""; lbImg.classList.remove("is-swap"); }; pre.src = a.href;
      lbCap.textContent = a.getAttribute("data-cap") || ""; lbCount.textContent = (cur + 1) + " / " + group.length;
      dlg.classList.toggle("is-single", group.length < 2);
    }
    links.forEach(function (a) { a.addEventListener("click", function (e) { if (!dlg.showModal) return; e.preventDefault(); group = links.filter(function (x) { return x.getAttribute("data-lb") === a.getAttribute("data-lb"); }); show(group.indexOf(a)); openModal(dlg); }); });
    dlg.addEventListener("cancel", function (e) { e.preventDefault(); closeModal(dlg); });
    dlg.addEventListener("click", function (e) {
      var t = e.target, d = t.closest("[data-d]");
      if (d) show(cur + Number(d.getAttribute("data-d")));
      else if (t === dlg || t.classList.contains("lb__stage") || t.closest("[data-close]")) closeModal(dlg);
    });
    dlg.addEventListener("keydown", function (e) { if (e.key === "ArrowRight") show(cur + 1); if (e.key === "ArrowLeft") show(cur - 1); });
    // Свайп на телефоне
    var sx = 0, sy = 0;
    dlg.addEventListener("touchstart", function (e) { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
    dlg.addEventListener("touchend", function (e) { var dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy; if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) show(cur + (dx < 0 ? 1 : -1)); }, { passive: true });
  }

  // Лента фото: перетаскивание мышью с инерцией, кнопки листания. На телефоне — родная прокрутка.
  $$("[data-rail]").forEach(function (box) {
    var rail = box.querySelector(".strip"), down = false, moved = false, x0 = 0, l0 = 0, lastX = 0, lastT = 0, v = 0, raf = 0;
    function glide() { v *= 0.94; if (Math.abs(v) < 0.05) return; rail.scrollLeft -= v * 16; raf = requestAnimationFrame(glide); }
    rail.addEventListener("pointerdown", function (e) {
      if (e.pointerType !== "mouse" || e.button !== 0) return;
      down = true; moved = false; x0 = lastX = e.clientX; l0 = rail.scrollLeft; lastT = performance.now(); v = 0; cancelAnimationFrame(raf);
    });
    window.addEventListener("pointermove", function (e) {
      if (!down) return;
      var dx = e.clientX - x0;
      if (!moved && Math.abs(dx) > 5) { moved = true; rail.classList.add("is-drag"); }
      if (!moved) return;
      var t = performance.now(); v = 0.8 * ((e.clientX - lastX) / Math.max(1, t - lastT)) + 0.2 * v; lastX = e.clientX; lastT = t;
      rail.scrollLeft = l0 - dx;
    });
    window.addEventListener("pointerup", function () {
      if (!down) return; down = false; rail.classList.remove("is-drag");
      if (moved && !calm) raf = requestAnimationFrame(glide);
    });
    rail.addEventListener("click", function (e) { if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; } }, true);
    rail.addEventListener("dragstart", function (e) { e.preventDefault(); });
    $$("[data-step]", box).forEach(function (b) {
      b.addEventListener("click", function () { cancelAnimationFrame(raf); rail.scrollBy({ left: Number(b.getAttribute("data-step")) * rail.clientWidth * 0.8, behavior: calm ? "auto" : "smooth" }); });
    });
    function edges() { var max = rail.scrollWidth - rail.clientWidth - 2; box.classList.toggle("at-start", rail.scrollLeft <= 2); box.classList.toggle("at-end", rail.scrollLeft >= max); }
    rail.addEventListener("scroll", edges, { passive: true }); edges();
  });

  // Отзывы: длинный текст обрезан, кнопка «Читать полностью» раскрывает.
  $$(".rev").forEach(function (r) {
    var t = r.querySelector(".rev__text"), b = r.querySelector(".rev__more");
    if (!t || !b) return;
    if (t.scrollHeight > t.clientHeight + 4) b.hidden = false;
    b.addEventListener("click", function () { var open = r.classList.toggle("is-open"); b.textContent = open ? "Свернуть" : "Читать полностью"; });
  });

  // Частые вопросы: плавное раскрытие ответа.
  $$(".faq details").forEach(function (d) {
    var s = d.querySelector("summary"), a = d.querySelector(".faq__a");
    if (!a || !a.animate) return;
    s.addEventListener("click", function (e) {
      if (calm) return;
      e.preventDefault();
      if (d.classList.contains("is-anim")) return;
      d.classList.add("is-anim");
      if (!d.open) {
        d.open = true;
        var h = a.scrollHeight;
        a.animate([{ height: "0px", opacity: 0 }, { height: h + "px", opacity: 1 }], { duration: 320, easing: "cubic-bezier(.2,.7,.2,1)" }).onfinish = function () { d.classList.remove("is-anim"); };
      } else {
        d.classList.add("is-closing");
        a.animate([{ height: a.offsetHeight + "px", opacity: 1 }, { height: "0px", opacity: 0 }], { duration: 260, easing: "ease-in-out" }).onfinish = function () { d.open = false; d.classList.remove("is-anim", "is-closing"); };
      }
    });
  });

  // Фильтр статей в блоге.
  $$("[data-cat-filter]").forEach(function (bar) {
    var cards = $$("[data-cat]", document.querySelector(bar.getAttribute("data-cat-filter")));
    $$("button", bar).forEach(function (b) {
      b.addEventListener("click", function () {
        var c = b.getAttribute("data-c");
        $$("button", bar).forEach(function (x) { x.setAttribute("aria-pressed", x === b ? "true" : "false"); });
        cards.forEach(function (card) { card.hidden = c !== "all" && card.getAttribute("data-cat") !== c; });
      });
    });
  });

  // Копировать ссылку на статью.
  $$("[data-copy]").forEach(function (b) {
    b.addEventListener("click", function () {
      var done = function () { b.textContent = "Ссылка скопирована"; };
      if (navigator.clipboard) navigator.clipboard.writeText(location.href).then(done, function () {}); else done();
    });
  });

  // Формы заявки: в окне #order-modal и в блоке «Оставьте заявку» внизу страницы. Каждая работает сама по себе.
  function iso(d) { return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0"); }
  function nightType(d) { var w = d.getDay(); return w === 5 ? "fri" : w === 6 ? "sat" : w === 0 ? "sun" : "wd"; }

  function setupForm(form) {
    var q = function (sel) { return form.querySelector(sel); };
    var fHouse = q('[name="house"]'), fIn = q('[name="checkin"]'), fOut = q('[name="checkout"]'),
        fGuests = q('[name="guests"]'), est = q(".estimate"), errBox = q(".err"), phone = q('[name="phone"]');

    // Даты по умолчанию: ближайшая пятница → суббота, чтобы расчёт был виден сразу.
    var today = new Date(); today.setHours(12, 0, 0, 0);
    fIn.min = iso(today); fOut.min = iso(new Date(today.getTime() + 864e5));
    if (!fIn.value) { var fri = new Date(today); fri.setDate(fri.getDate() + ((5 - fri.getDay() + 7) % 7 || 7)); fIn.value = iso(fri); var sat = new Date(fri); sat.setDate(sat.getDate() + 1); fOut.value = iso(sat); }

    function calc() {
      var h = HOUSES[fHouse.value];
      var a = fIn.value ? new Date(fIn.value + "T12:00") : null, b = fOut.value ? new Date(fOut.value + "T12:00") : null;
      if (h) { fGuests.max = h.max; fGuests.placeholder = "до " + h.max; }
      if (!h || !a || !b || b <= a) {
        est.innerHTML = '<span>Выберите дом и даты — покажем примерную стоимость.</span>';
        return;
      }
      var nights = Math.round((b - a) / 864e5), sum = 0, d = new Date(a);
      for (var i = 0; i < nights; i++) { sum += h.prices[nightType(d)]; d.setDate(d.getDate() + 1); }
      // Доплата за гостей сверх нормы дома — один раз за всё время отдыха.
      var g = parseInt(fGuests.value, 10) || 0, over = Math.max(0, Math.min(g, h.max) - h.base);
      sum += over * h.extra;
      var note = over ? ', ' + over + ' ' + plural(over, "гость", "гостя", "гостей") + ' сверх ' + h.base + ' (+' + rub(over * h.extra) + ')' : '';
      est.innerHTML = '<div><small>' + h.name + ', ' + nights + ' ' + plural(nights, "сутки", "суток", "суток") + note + '</small><div class="sum tnum">≈ ' + rub(sum) + '</div>' + (DISCOUNT ? '<div class="sum-disc tnum">со скидкой ' + DISCOUNT + '% на сайте ≈ ' + rub(Math.round(sum * (100 - DISCOUNT) / 100 / 10) * 10) + '</div>' : '') + '</div>' +
        '<small>Заезд с ' + h.checkin + ', выезд до ' + h.checkout + '. В цене до ' + h.base + ' гостей, далее ' + rub(h.extra) + ' за человека. Праздничные дни считаются по тарифу выходных, точную сумму назовёт администратор.</small>';
    }
    [fHouse, fIn, fOut].forEach(function (el) { el.addEventListener("change", calc); });
    fGuests.addEventListener("input", calc);
    fIn.addEventListener("change", function () {
      if (fIn.value) { var n = new Date(fIn.value + "T12:00"); n.setDate(n.getDate() + 1); fOut.min = iso(n); if (!fOut.value || fOut.value <= fIn.value) fOut.value = iso(n); calc(); }
    });

    // Маска телефона +7 (___) ___-__-__
    phone.addEventListener("input", function () {
      var d = phone.value.replace(/\D/g, "");
      if (d[0] === "8" || d[0] === "7") d = d.slice(1);
      d = d.slice(0, 10);
      var out = "+7";
      if (d.length) out += " (" + d.slice(0, 3);
      if (d.length >= 3) out += ") " + d.slice(3, 6);
      if (d.length >= 6) out += "-" + d.slice(6, 8);
      if (d.length >= 8) out += "-" + d.slice(8, 10);
      phone.value = out;
    });

    function done(num, h) {
      var inModal = !!form.closest("dialog");
      form.innerHTML = '<div class="form__ok" role="status"><svg viewBox="0 0 815 745" aria-hidden="true"><use href="#fox" width="815" height="745"/></svg><h3>Заявка' + (num ? ' №' + num : '') + ' принята</h3>' +
        '<p>' + h.name + ': администратор перезвонит, подтвердит даты и назовёт сумму предоплаты' + (DISCOUNT ? ' с учётом скидки ' + DISCOUNT + '%' : '') + '.</p>' +
        '<p><a class="link" href="' + h.prepay + '">Внести предоплату</a> можно после звонка администратора.</p>' +
        (inModal ? '<div><button class="btn btn--sm" type="button" data-close>Закрыть</button></div>' : '') + '</div>';
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var problems = [];
      if (!q('[name="name"]').value.trim()) problems.push("укажите имя");
      if (phone.value.replace(/\D/g, "").length < 11) problems.push("проверьте телефон: нужно 10 цифр после +7");
      if (!fHouse.value) problems.push("выберите дом");
      if (!q('[name="agree"]').checked) problems.push("отметьте согласие на обработку данных");
      if (q('[name="website"]').value) return; // ловушка для ботов
      if (problems.length) { errBox.textContent = "Чтобы отправить заявку, " + problems.join(", ") + "."; errBox.hidden = false; return; }
      errBox.hidden = true;
      var h = HOUSES[fHouse.value];
      var action = form.getAttribute("action");
      if (!action) { done(String(Date.now()).slice(-4), h); return; }   // статичная версия без сервера
      // На сайте (MODX) заявка уходит на сервер: сохраняется в «Заявках», приходит письмом
      var btn = q('button[type="submit"]'); btn.disabled = true; btn.textContent = "Отправляем…";
      fetch(action, { method: "POST", body: new FormData(form), headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" }, credentials: "same-origin" })
        .then(function (r) { return r.json().catch(function () { return { ok: false, message: "Сервер ответил с ошибкой (" + r.status + ")." }; }); })
        .then(function (res) {
          if (res && res.ok) { done(res.id, h); if (window.ym && window.BN_YM) window.ym(window.BN_YM, "reachGoal", "lead"); return; }
          errBox.textContent = (res && res.message) || "Не получилось отправить заявку. Позвоните нам, пожалуйста."; errBox.hidden = false;
          btn.disabled = false; btn.textContent = "Отправить заявку";
        })
        .catch(function () {
          errBox.textContent = "Нет связи с сервером. Проверьте интернет или позвоните нам."; errBox.hidden = false;
          btn.disabled = false; btn.textContent = "Отправить заявку";
        });
    });

    if (form.dataset.house && HOUSES[form.dataset.house]) fHouse.value = form.dataset.house;
    calc();
    return { form: form, house: fHouse, calc: calc };
  }

  var forms = $$("form.order-form").map(setupForm);
  var modal = document.getElementById("order-modal");
  var mForm = forms.filter(function (f) { return modal && modal.contains(f.form); })[0];

  // Любая кнопка с data-order открывает форму в окне; data-house и data-occasion подставляют значения.
  $$("[data-order]").forEach(function (btn) {
    btn.addEventListener("click", function (e) {
      e.preventDefault();
      if (!mForm) return;
      var occ = btn.getAttribute("data-occasion"), house = btn.getAttribute("data-house");
      if (occ) { var r = mForm.form.querySelector('input[name="occasion"][value="' + occ + '"]'); if (r) r.checked = true; }
      if (house && HOUSES[house]) { mForm.house.value = house; mForm.calc(); }
      var go = function () { openModal(modal); setTimeout(function () { var n = mForm.form.querySelector('[name="name"]'); if (n) n.focus({ preventScroll: true }); }, 350); };
      if (drawer && drawer.open) closeModal(drawer, go); else go();
    });
  });

  // Страница предоплаты: кнопка оплаты работает только после согласия с условиями возврата
  $$("[data-pay-agree]").forEach(function (box) {
    var btn = document.querySelector("[data-pay-btn]");
    if (!btn) return;
    var sync = function () { btn.classList.toggle("is-off", !box.checked); btn.setAttribute("aria-disabled", box.checked ? "false" : "true"); };
    btn.addEventListener("click", function (e) { if (!box.checked) { e.preventDefault(); box.focus(); box.closest("label").classList.add("is-need"); } });
    box.addEventListener("change", function () { box.closest("label").classList.remove("is-need"); sync(); });
    sync();
  });
})();
