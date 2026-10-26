/* Пачка фото в админке MODX: «Галерея» Дома, «Лента фото» главной, скриншоты отзывов Дома.
   Подключает плагин bnUpload (установщик сайта) на странице Дома и главной; настройки — window.bnUpload.
   В браузере: фото уменьшается до 1600 px (JPEG) и получает копии WebP 480/800/1200/1600 — как tools/make_webp.py,
   имя — латиницей без повторов; загрузка — штатным Browser/File/Upload (права и типы файлов MODX), строка дописывается в поле. */
(function () {
  var C = window.bnUpload;
  if (!C || !window.fetch || !window.createImageBitmap) return;
  var WIDTHS = [480, 800, 1200, 1600];
  var TR = { 'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'e', 'ж': 'zh', 'з': 'z', 'и': 'i', 'й': 'j', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n',
    'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u', 'ф': 'f', 'х': 'h', 'ц': 'c', 'ч': 'ch', 'ш': 'sh', 'щ': 'shch', 'ъ': '', 'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu', 'я': 'ya' };

  var css = document.createElement('style');
  css.textContent = '.bnup{margin:10px 0 4px;padding:12px;border:1px dashed #b9a48c;border-radius:8px;background:#fbf7f1}'
    + '.bnup__row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.bnup__st{font-size:13px;color:#6b5a48}.bnup__st b{color:#a33}'
    + '.bnup__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;margin-top:10px}'
    + '.bnup__it{position:relative;background:#fff;border:1px solid #e3d8ca;border-radius:6px;overflow:hidden;font-size:12px}'
    + '.bnup__it img{display:block;width:100%;height:84px;object-fit:cover;background:#eee}.bnup__it input{width:100%;box-sizing:border-box;border:0;border-top:1px solid #eee;padding:4px 6px;font-size:12px}'
    + '.bnup__n{position:absolute;left:4px;top:4px;background:rgba(0,0,0,.6);color:#fff;border-radius:3px;padding:0 5px}'
    + '.bnup__b{position:absolute;top:4px;display:flex;gap:2px;right:4px}.bnup__b button{border:0;border-radius:3px;background:rgba(0,0,0,.6);color:#fff;cursor:pointer;padding:1px 6px;font-size:13px;line-height:18px}'
    + '.bnup__b button:hover{background:#a33}.bnup__drop{outline:2px solid #c2681b}';
  document.head.appendChild(css);

  function slug(name) {
    var s = name.replace(/\.[^.]+$/, '').toLowerCase().split('').map(function (ch) { return TR[ch] !== undefined ? TR[ch] : ch; }).join('');
    s = s.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
    return s || 'foto';
  }
  function api(params, files) {
    var fd = new FormData();
    fd.append('HTTP_MODAUTH', C.auth);
    fd.append('source', C.source);
    fd.append('wctx', 'mgr');
    Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
    (files || []).forEach(function (f, i) { fd.append('file' + i, f.blob, f.name); });
    return fetch(C.connector, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'modAuth': C.auth } })
      .then(function (r) { return r.json(); })
      .catch(function () { return { success: false, message: 'нет связи с сервером' }; });
  }
  function list(dir) {
    return api({ action: 'Browser/Directory/GetFiles', dir: dir }).then(function (r) {
      return (r && r.results ? r.results : []).map(function (f) { return f.name; });
    });
  }
  function toBlob(canvas, type, q) { return new Promise(function (ok) { canvas.toBlob(ok, type, q); }); }
  function draw(bmp, w) {
    var c = document.createElement('canvas');
    c.width = w; c.height = Math.round(bmp.height * w / bmp.width);
    c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
    return c;
  }
  /* одно фото → JPEG (не шире max) и, если webp, копии нужных ширин */
  function prepare(file, base, max, webp) {
    return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
      var w = Math.min(bmp.width, max), out = [];
      var jobs = [toBlob(draw(bmp, w), 'image/jpeg', 0.86).then(function (b) { out.push({ name: base + '.jpg', blob: b }); })];
      if (webp) {
        var ws = WIDTHS.filter(function (x) { return x < w; });
        if (w <= WIDTHS[WIDTHS.length - 1] && (!ws.length || w - ws[ws.length - 1] > 120)) ws.push(w);
        ws.forEach(function (x) {
          jobs.push(toBlob(draw(bmp, x), 'image/webp', 0.72).then(function (b) { if (b && b.type === 'image/webp') out.push({ name: base + '-' + x + '.webp', blob: b }); }));
        });
      }
      return Promise.all(jobs).then(function () { return out; });
    });
  }
  function freeName(want, taken) {
    var n = want, i = 2;
    while (taken[n + '.jpg']) n = want + '-' + (i++);
    taken[n + '.jpg'] = true;
    return n;
  }
  /* загрузить пачку: каждое фото отдельным запросом (JPEG + его WebP); onDone(имя файла) — после каждого */
  function upload(files, dir, opt, st, onDone) {
    files = Array.prototype.slice.call(files).filter(function (f) { return /^image\//.test(f.type) || /\.(jpe?g|png|webp|heic)$/i.test(f.name); });
    if (!files.length) return Promise.resolve();
    var ok = 0, bad = [];
    st.textContent = 'Готовим…';
    return list(dir).then(function (names) {
      var taken = {};
      names.forEach(function (n) { taken[n] = true; });
      var chain = Promise.resolve();
      files.forEach(function (f, i) {
        chain = chain.then(function () {
          st.textContent = 'Загружаем ' + (i + 1) + ' из ' + files.length + ': ' + f.name + '…';
          var base = freeName(opt.name ? opt.name(taken) : slug(f.name), taken);
          return prepare(f, base, opt.max, opt.webp).then(function (parts) {
            return api({ action: 'Browser/File/Upload', path: dir }, parts).then(function (r) {
              if (r && r.success) { ok++; onDone(base + '.jpg'); } else bad.push(f.name + ' — ' + ((r && r.message) || 'ошибка загрузки'));
            });
          }, function () { bad.push(f.name + ' — формат не читается (фото iPhone HEIC сохраните как JPEG)'); });
        });
      });
      return chain;
    }).then(function () {
      st.innerHTML = 'Загружено: ' + ok + (bad.length ? '. <b>Не загружены: ' + bad.map(esc).join('; ') + '</b>' : '') + (ok ? '. Не забудьте «Сохранить».' : '');
    });
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function changed(ta) {
    ['input', 'change', 'keyup'].forEach(function (n) { ta.dispatchEvent(new Event(n, { bubbles: true })); });
    if (window.MODx && MODx.fireResourceFormChange) MODx.fireResourceFormChange();
  }
  function box(after, title) {
    var el = document.createElement('div');
    el.className = 'bnup';
    el.innerHTML = '<div class="bnup__row"><b>' + esc(title) + '</b></div><div class="bnup__row" style="margin-top:8px"><span data-extra></span>'
      + '<button type="button" class="x-btn primary-button" data-pick style="padding:6px 14px">Загрузить фото — можно сразу несколько</button>'
      + '<input type="file" accept="image/*" multiple hidden><span class="bnup__st" data-st>Или перетащите фото сюда.</span></div><div class="bnup__grid" data-grid></div>';
    after.parentNode.insertBefore(el, after.nextSibling);
    var input = el.querySelector('input');
    el.querySelector('[data-pick]').addEventListener('click', function () { input.click(); });
    el.addEventListener('dragover', function (e) { e.preventDefault(); el.classList.add('bnup__drop'); });
    el.addEventListener('dragleave', function () { el.classList.remove('bnup__drop'); });
    return el;
  }

  /* поле «файл | подпись» (Галерея Дома, Лента фото): загрузка, миниатюры, порядок, подпись, удаление строки */
  function field(f, ta) {
    var el = box(ta, f.title), grid = el.querySelector('[data-grid]'), st = el.querySelector('[data-st]'), sel = null;
    if (f.houses) {
      sel = document.createElement('select');
      sel.innerHTML = f.houses.map(function (h) { return '<option value="' + esc(h.code) + '">в папку: ' + esc(h.name) + '</option>'; }).join('');
      sel.style.marginRight = '6px';
      el.querySelector('[data-extra]').appendChild(sel);
    }
    var dirOf = function () { return f.houses ? f.root + sel.value + '/' : f.dir; };
    var lines = function () { return ta.value.split(/\r?\n/); };
    // миниатюра: «pool.jpg» — из папки Дома (f.url), «assets/img/ru/x.jpg» — как написано
    var src = function (file) { return C.base + (file.indexOf('/') >= 0 ? file.replace(/^\/+/, '') : f.url + file); };
    var render = function () {
      var items = [];
      lines().forEach(function (ln, i) {
        var t = ln.trim();
        if (!t || t[0] === '#') return;
        var p = t.split('|'), file = p[0].trim(), cap = p.slice(1).join('|').trim();
        items.push('<div class="bnup__it" data-i="' + i + '"><span class="bnup__n">' + (items.length + 1) + '</span>'
          + '<span class="bnup__b"><button type="button" data-mv="-1" title="Раньше">←</button><button type="button" data-mv="1" title="Позже">→</button><button type="button" data-rm title="Убрать из списка (файл остаётся в папке)">×</button></span>'
          + '<img src="' + esc(src(file)) + '" alt="" loading="lazy"><input value="' + esc(cap) + '" placeholder="подпись" title="' + esc(file) + '"></div>');
      });
      grid.innerHTML = items.join('');
    };
    var setLines = function (arr) { ta.value = arr.join('\n').replace(/^\n+/, ''); changed(ta); render(); };
    grid.addEventListener('click', function (e) {
      var it = e.target.closest('.bnup__it');
      if (!it) return;
      var i = +it.getAttribute('data-i'), arr = lines();
      if (e.target.hasAttribute('data-rm')) { arr.splice(i, 1); setLines(arr); return; }
      var mv = e.target.getAttribute('data-mv');
      if (mv) {
        var idx = [];
        arr.forEach(function (l, k) { if (l.trim() && l.trim()[0] !== '#') idx.push(k); });
        var pos = idx.indexOf(i), to = idx[pos + (+mv)];
        if (to === undefined) return;
        var t = arr[i]; arr[i] = arr[to]; arr[to] = t;
        setLines(arr);
      }
    });
    grid.addEventListener('change', function (e) {
      if (e.target.tagName !== 'INPUT') return;
      var it = e.target.closest('.bnup__it'), i = +it.getAttribute('data-i'), arr = lines();
      arr[i] = arr[i].split('|')[0].trim() + ' | ' + e.target.value.replace(/\|/g, '/').trim();
      setLines(arr);
    });
    ta.addEventListener('input', function (e) { if (e.isTrusted) render(); });
    var go = function (files) {
      el.classList.remove('bnup__drop');
      var dir = dirOf(), prefix = f.houses ? 'assets/img/' + sel.value + '/' : '';
      upload(files, dir, { max: 1600, webp: true }, st, function (name) {
        var arr = lines().filter(function (l, k, a) { return l.trim() || k < a.length - 1; });
        arr.push(prefix + name + ' | ');
        setLines(arr);
      });
    };
    el.querySelector('input').addEventListener('change', function (e) { go(e.target.files); e.target.value = ''; });
    el.addEventListener('drop', function (e) { e.preventDefault(); go(e.dataTransfer.files); });
    render();
  }

  /* скриншоты отзывов Дома: файлы assets/img/reviews/<код>-N.jpg — показ, загрузка, удаление (сразу, без «Сохранить») */
  function reviews(r, after) {
    var el = box(after, 'Скриншоты отзывов этого Дома — лентой над QR-кодами на сайте'), grid = el.querySelector('[data-grid]'), st = el.querySelector('[data-st]');
    var show = function () {
      list(r.dir).then(function (names) {
        var mine = names.filter(function (n) { return n.indexOf(r.code + '-') === 0 && /\.(jpe?g|png|webp)$/i.test(n); }).sort();
        grid.innerHTML = mine.length ? mine.map(function (n, i) {
          return '<div class="bnup__it"><span class="bnup__n">' + (i + 1) + '</span><span class="bnup__b"><button type="button" data-del="' + esc(n) + '" title="Удалить файл">×</button></span>'
            + '<img src="' + esc(C.base + r.url + n) + '?t=' + Date.now() + '" alt=""><input value="' + esc(n) + '" readonly></div>';
        }).join('') : '<span class="bnup__st">Скриншотов пока нет — на сайте ленты нет, видны только QR-коды.</span>';
      });
    };
    grid.addEventListener('click', function (e) {
      var n = e.target.getAttribute('data-del');
      if (!n || !window.confirm('Удалить скриншот ' + n + '? Он сразу пропадёт с сайта.')) return;
      api({ action: 'Browser/File/Remove', file: r.dir + n }).then(function (res) { st.textContent = res.success ? 'Удалён: ' + n : (res.message || 'Не удалось удалить'); show(); });
    });
    var go = function (files) {
      el.classList.remove('bnup__drop');
      var name = function (taken) {   // <код>-<следующий номер>
        var k = 1;
        while (Object.keys(taken).some(function (t) { return t.replace(/\.[^.]+$/, '') === r.code + '-' + k; })) k++;
        return r.code + '-' + k;
      };
      upload(files, r.dir, { max: 1200, webp: false, name: name }, st, function () {}).then(function () {
        st.innerHTML = st.innerHTML.replace('. Не забудьте «Сохранить».', '. На сайте — сразу (очистите кэш, если не видно).');
        show();
      });
    };
    el.querySelector('input').addEventListener('change', function (e) { go(e.target.files); e.target.value = ''; });
    el.addEventListener('drop', function (e) { e.preventDefault(); go(e.dataTransfer.files); });
    show();
  }

  /* поля MODX появляются не сразу (вкладки ExtJS) — ждём textarea полей */
  var tries = 0;
  (function wait() {
    var done = true;
    (C.fields || []).forEach(function (f) {
      if (f.ready) return;
      var ta = document.getElementById('tv' + f.tv);
      if (ta) { f.ready = true; field(f, ta); if (f.reviewsAfter && C.reviews) reviews(C.reviews, ta.parentNode.querySelector('.bnup') || ta); } else done = false;
    });
    if (!done && tries++ < 60) setTimeout(wait, 500);
  })();
})();
