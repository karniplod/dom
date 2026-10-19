/* Доходные дома Ефремовых — поведение страниц.
   Данные домов приходят в window.HOUSES (собирается из src/houses.py, в MODX — из TV). */
(function () {
  var HOUSES = window.HOUSES || {};

  var rub = function (n) { return n.toLocaleString("ru-RU").replace(/ |,/g, " ") + " ₽"; };
  function plural(n, a, b, c) { var m10 = n % 10, m100 = n % 100; if (m10 === 1 && m100 !== 11) return a; if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return b; return c; }



  // Лайтбокс для фото: ссылки с data-lb открывают диалог с листанием.
  var links = Array.prototype.slice.call(document.querySelectorAll("a[data-lb]"));
  if (links.length) {
    var dlg = document.createElement("dialog"); dlg.className = "lb"; dlg.setAttribute("aria-label", "Фото");
    dlg.innerHTML = '<img alt=""><div class="lb__bar"><button type="button" data-d="-1" aria-label="Предыдущее фото">Назад</button><span class="lb__cap"></span><span><button type="button" data-d="1" aria-label="Следующее фото">Дальше</button> <button type="button" data-close aria-label="Закрыть">Закрыть</button></span></div>';
    document.body.appendChild(dlg);
    var lbImg = dlg.querySelector("img"), lbCap = dlg.querySelector(".lb__cap"), cur = 0, group = links;
    function show(i) { cur = (i + group.length) % group.length; var a = group[cur]; lbImg.src = a.href; lbImg.alt = a.getAttribute("data-cap") || ""; lbCap.textContent = (a.getAttribute("data-cap") || "") + "  " + (cur + 1) + " / " + group.length; }
    links.forEach(function (a) { a.addEventListener("click", function (e) { if (!dlg.showModal) return; e.preventDefault(); group = links.filter(function (x) { return x.getAttribute("data-lb") === a.getAttribute("data-lb"); }); show(group.indexOf(a)); dlg.showModal(); }); });
    dlg.addEventListener("click", function (e) { var t = e.target; if (t === dlg || t.hasAttribute("data-close")) dlg.close(); else if (t.hasAttribute("data-d")) show(cur + Number(t.getAttribute("data-d"))); });
    dlg.addEventListener("keydown", function (e) { if (e.key === "ArrowRight") show(cur + 1); if (e.key === "ArrowLeft") show(cur - 1); });
  }

  var form = document.getElementById("order-form");
  if (!form) return;
  var fHouse = form.querySelector("#f-house"), fIn = form.querySelector("#f-in"), fOut = form.querySelector("#f-out"),
      fGuests = form.querySelector("#f-guests"), est = form.querySelector("#estimate"), errBox = form.querySelector("#f-err");

  // Даты по умолчанию: ближайшая пятница → суббота, чтобы расчёт был виден сразу.
  function iso(d) { return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0"); }
  var today = new Date(); today.setHours(12, 0, 0, 0);
  fIn.min = iso(today); fOut.min = iso(new Date(today.getTime() + 864e5));
  if (!fIn.value) { var fri = new Date(today); fri.setDate(fri.getDate() + ((5 - fri.getDay() + 7) % 7 || 7)); fIn.value = iso(fri); var sat = new Date(fri); sat.setDate(sat.getDate() + 1); fOut.value = iso(sat); }

  function nightType(d) { var w = d.getDay(); return w === 5 ? "fri" : w === 6 ? "sat" : w === 0 ? "sun" : "wd"; }

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
    est.innerHTML = '<div><small>' + h.name + ', ' + nights + ' ' + plural(nights, "сутки", "суток", "суток") + '</small><div class="sum tnum">≈ ' + rub(sum) + '</div></div>' +
      '<small>Заезд с ' + h.checkin + ', выезд до ' + h.checkout + '. Праздничные дни считаются по тарифу выходных, точную сумму назовёт администратор.</small>';
  }
  [fHouse, fIn, fOut].forEach(function (el) { el.addEventListener("change", calc); });
  fIn.addEventListener("change", function () {
    if (fIn.value) { var n = new Date(fIn.value + "T12:00"); n.setDate(n.getDate() + 1); fOut.min = iso(n); if (!fOut.value || fOut.value <= fIn.value) fOut.value = iso(n); calc(); }
  });

  // Кнопки «Оставить заявку» с поводом или домом подставляют значения в форму.
  document.querySelectorAll("[data-occasion],[data-house]").forEach(function (btn) {
    btn.addEventListener("click", function (e) {
      var occ = btn.getAttribute("data-occasion"), house = btn.getAttribute("data-house");
      if (occ) { var r = form.querySelector('input[name="occasion"][value="' + occ + '"]'); if (r) r.checked = true; }
      if (house) { fHouse.value = house; calc(); }
      if (btn.tagName === "BUTTON") { e.preventDefault(); document.getElementById("order").scrollIntoView(); }
      setTimeout(function () { form.querySelector("#f-name").focus({ preventScroll: true }); }, 400);
    });
  });

  // Маска телефона +7 (___) ___-__-__
  var phone = form.querySelector("#f-phone");
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

  form.addEventListener("submit", function (e) {
    e.preventDefault(); // в MODX здесь отправка через AjaxForm / fetch на коннектор
    var problems = [];
    if (!form.querySelector("#f-name").value.trim()) problems.push("укажите имя");
    if (phone.value.replace(/\D/g, "").length < 11) problems.push("проверьте телефон: нужно 10 цифр после +7");
    if (!fHouse.value) problems.push("выберите дом");
    if (!form.querySelector("#f-agree").checked) problems.push("отметьте согласие на обработку данных");
    if (form.querySelector('[name="website"]').value) return; // ловушка для ботов
    if (problems.length) { errBox.textContent = "Чтобы отправить заявку, " + problems.join(", ") + "."; errBox.hidden = false; return; }
    errBox.hidden = true;
    var h = HOUSES[fHouse.value];
    var num = String(Date.now()).slice(-4);
    form.innerHTML = '<div class="form__ok" role="status"><svg viewBox="0 0 815 745" aria-hidden="true"><use href="#fox" width="815" height="745"/></svg><h3>Заявка №' + num + ' принята</h3>' +
      '<p>' + h.name + ': администратор перезвонит, подтвердит даты и назовёт сумму предоплаты.</p>' +
      '<p><a class="link" href="' + h.prepay + '" target="_blank" rel="noopener">Внести предоплату</a> можно после звонка администратора.</p></div>';
  });

  if (form.dataset.house) fHouse.value = form.dataset.house;
  calc();
})();
