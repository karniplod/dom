"""Сборка сайта: python build.py  (Windows: py build.py)

src/   — исходники: данные домов (houses.py), статьи (blog.py), стили, скрипт, логотип
site/  — готовые страницы, robots.txt, sitemap.xml, rss.xml, llms.txt (не править руками)
build/ — служебная сборка для превью в Claude (в git не попадает)
"""
import os, sys, json, re, html, math, glob
from urllib.parse import quote
ROOT = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(ROOT, "src"))
from houses import H, SITE, PHONE, PHONE_RAW
# Превью на GitHub Pages: SITE_URL=https://karniplod.github.io/dom — мета-теги и картинки ведут туда, поиск закрыт.
PREVIEW = bool(os.environ.get("SITE_URL"))
SITE = os.environ.get("SITE_URL", SITE).rstrip("/")
from blog import POSTS, CATS
from legal import DOCS, DATE as LEGAL_DATE, COOKIE_VERSION, OPERATOR
from reviews import PLATFORM_KEY, REVIEWS, REVIEW_LINKS, REVIEW_PLATFORMS, HOUSE_REVIEW_LINKS, REVIEW_SHOTS

SRC, SITE_DIR, ART = (os.path.join(ROOT, d) for d in ("src", "site", os.path.join("build", "artifact")))
rd = lambda n: open(os.path.join(SRC, n), encoding="utf8").read()
esc = html.escape
def jpeg_size(path):
    """Ширина и высота JPEG без Pillow — для og:image:width/height."""
    with open(path, "rb") as f:
        data = f.read()
    i = 2
    while i < len(data):
        if data[i] != 0xFF: i += 1; continue
        m = data[i + 1]
        if m in (0xC0, 0xC1, 0xC2):
            return int.from_bytes(data[i + 7:i + 9], "big"), int.from_bytes(data[i + 5:i + 7], "big")
        i += 2 + int.from_bytes(data[i + 2:i + 4], "big")
    return None
def money(n): return f"{n:,}".replace(",", " ") + " ₽"
def minp(h): return min(h["prices"])

DISCOUNT = 5  # %, только при бронировании через группу ВКонтакте (решение владельца 22.10.2026)
VK, MAIL = "https://vk.com/russianbath59", "efremova7676@mail.ru"
MAX = ""   # ссылка на чат в MAX (https://max.ru/…); пусто — ссылки MAX на сайте нет. В MODX — «Конфигурация» → «Контакты»
BRAND = "Доходные Дома Ефремовых"
FOX = '<svg viewBox="0 0 815 745" aria-hidden="true"><use href="#fox" width="815" height="745"/></svg>'
ICONS = {
 "kitchen": '<path d="M5 3v8a2 2 0 0 0 2 2v8M9 3v8a2 2 0 0 1-2 2M7 3v6"/><path d="M17 21V3c-2.2 1-3.5 3.6-3.5 7.5V13H17"/>',
 "steam": '<path d="M3 20h18"/><path d="M5 16c1.5-1 3-1 4.5 0s3 1 4.5 0 3-1 4.5 0"/><path d="M8 11c-1-1.5 1-2.5 0-4s1-2.5 0-4M12 11c-1-1.5 1-2.5 0-4s1-2.5 0-4M16 11c-1-1.5 1-2.5 0-4s1-2.5 0-4"/>',
 "cue": '<circle cx="8" cy="15" r="4"/><circle cx="16.5" cy="17.5" r="2.5"/><path d="M11 12 21 2"/>',
 "fire": '<path d="M12 21c-4 0-7-2.6-7-6.5 0-3.6 3-5.5 4-9 2 1.5 3 3.5 3 5.5 1-1 1.5-2 1.5-3.5 2.5 2 4.5 4.6 4.5 7 0 3.9-2 6.5-6 6.5Z"/>',
 "bed": '<path d="M3 19v-8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v8M3 15h18M3 19v2M21 19v2"/><path d="M6 9V6a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3M12 9V6a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/>',
 "snow": '<path d="M12 2v20M3.3 7l17.4 10M3.3 17 20.7 7"/><path d="m9 4 3 2 3-2M9 20l3-2 3 2"/>',
 "tv": '<rect x="3" y="5" width="18" height="12" rx="1"/><path d="M8 21h8M12 17v4"/>',
 "car": '<path d="M5 17h14M4 17v-4l2-5h12l2 5v4M4 17v2M20 17v2"/><circle cx="7.5" cy="14" r="1"/><circle cx="16.5" cy="14" r="1"/>',
 "chair": '<path d="M7 3h6a2 2 0 0 1 2 2v7l4 3v3H9l-4-5V5a2 2 0 0 1 2-2Z"/><path d="M9 18v3M17 18v3"/>',
 "floor": '<path d="M3 20h18M5 16h14"/><path d="M8 12c-1-1.5 1-2.5 0-4M12 12c-1-1.5 1-2.5 0-4M16 12c-1-1.5 1-2.5 0-4"/>',
 "phone": '<path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 3 5a2 2 0 0 1 2-2Z"/>',
 "chat": '<path d="M4 5h16v11H9l-5 4V5Z"/><path d="M8 10h8M8 13h5"/>',
 "mail": '<rect x="3" y="5" width="18" height="14" rx="1"/><path d="m3 7 9 6 9-6"/>',
 "pin": '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.5"/>',
 "cube": '<path d="M12 3 4 7.5v9L12 21l8-4.5v-9L12 3Z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>',
 "card": '<rect x="3" y="5" width="18" height="14" rx="1"/><path d="M3 10h18M7 15h4"/>',
}
# Сборщик MODX (tools/modx_build.py) ставит MARK = True: данные и повторы обводятся метками <!--bn:имя-->…<!--/bn:имя-->,
# по ним он делает чанки и шаблоны. Статичный сайт собирается без меток.
MARK = False
def mark(name, html):
    return f"<!--bn:{name}-->{html}<!--/bn:{name}-->" if MARK else html

def max_link(html):
    """Фрагмент со ссылкой на MAX ({url} — адрес): без ссылки — ничего. Для MODX — метка max, сборщик делает из неё условие по bn_max_url"""
    if MARK:
        return f"<!--bn:max-->{html.replace('{url}', '[[++bn_max_url]]')}<!--/bn:max-->"
    return html.replace("{url}", MAX) if MAX else ""

def icon(n): return f'<svg viewBox="0 0 24 24" aria-hidden="true">{ICONS[n]}</svg>'
CHEV = '<svg class="chev" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>'
XICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>'
WEBP_W = (480, 800, 1200, 1600)   # ширины копий — как в tools/make_webp.py

def img(path, alt, cls="", lazy=True, sizes="(max-width: 760px) 100vw, 420px"):   # 420px — карточки, лента, блоки главной
    """Фото: <picture> с WebP нужной ширины (копии — tools/make_webp.py), JPEG — запасной. Не lazy — первый экран: грузится первым"""
    tag = (f'<img src="img/{path}.jpg" alt="{esc(alt)}"{f" class={chr(34)}{cls}{chr(34)}" if cls else ""}'
           f'{" loading=" + chr(34) + "lazy" + chr(34) if lazy else " fetchpriority=" + chr(34) + "high" + chr(34)} decoding="async">')
    name = os.path.basename(path)   # только «имя-ширина.webp»: шаблон «имя-*» цепляет и копии «имя-2-…»
    ws = sorted(int(m.group(1)) for f in glob.glob(os.path.join(SITE_DIR, "img", glob.escape(path) + "-*.webp"))
                if (m := re.fullmatch(re.escape(name) + r"-(\d+)\.webp", os.path.basename(f))))
    if not ws:
        return tag
    srcset = ", ".join(f"img/{path}-{w}.webp {w}w" for w in ws)
    return f'<picture><source type="image/webp" srcset="{srcset}" sizes="{sizes}">{tag}</picture>'

DAYS = ["Понедельник – четверг", "Пятница", "Суббота", "Воскресенье"]
MONTHS = ["января", "февраля", "марта", "апреля", "мая", "июня", "июля", "августа", "сентября", "октября", "ноября", "декабря"]
def ru_date(iso): y, m, d = map(int, iso.split("-")); return f"{d} {MONTHS[m - 1]} {y}"
def post_file(p): return f'blog-{p["slug"]}.html'
def read_min(p): return max(2, math.ceil(len(re.sub(r"<[^>]+>", " ", p["lead"] + "".join(s[2] for s in p["sections"])).split()) / 180))

# ---------- общие куски ----------
def header(active=None):
    houses = mark("navHouses", "".join(mark(f"item:navHouse:{k}", f'''<a class="nav__house h-{k}" href="{v["file"]}"{' aria-current="page"' if k == active else ""}>{img(k + "/" + v["card_photos"][0], v["name"], sizes="96px")}<span><b>{v["name"]}</b><small>{v["addr"]}</small><em class="tnum">от {money(minp(v))} за сутки</em></span></a>''') for k, v in H.items()))
    cur = lambda key: ' aria-current="page"' if key == active else ""
    in_houses = ' class="nav__btn is-current"' if active in H else ' class="nav__btn"'
    return f'''<header class="top"><div class="wrap top__in">
  <a class="brand" href="index.html">{FOX}<span><b>{BRAND}</b><small>Дома с баней и бассейном в Перми</small></span></a>
  <nav class="nav" aria-label="Основное меню">{mark("menu:nav", f"""
    <a href="index.html"{cur("home")}>Главная</a>
    <div class="nav__drop"><button{in_houses} type="button" aria-expanded="false" aria-controls="nav-houses">Дома{CHEV}</button>
      <div class="nav__panel" id="nav-houses">{houses}<a class="nav__all" href="index.html#prices">Сравнить Дома и цены</a></div></div>
    <a href="index.html#order">Бронирование</a>
    <a href="blog.html"{cur("blog")}>Блог</a>
    <a href="kontakty.html"{cur("contacts")}>Контакты</a>
  """)}</nav>
  <a class="top__phone tnum" href="tel:{PHONE_RAW}">{PHONE}</a>
  <button class="btn btn--sm top__cta" type="button" data-order>Оставить заявку</button>
  <button class="burger" type="button" data-drawer aria-label="Открыть меню" aria-controls="drawer"><span></span><span></span><span></span></button>
</div></header>'''

def drawer():
    houses = mark("drawerHouses", "".join(mark(f"item:drawerHouse:{k}", f'<a class="drawer__house h-{k}" href="{v["file"]}"><b>{v["name"]}</b><span class="tnum">от {money(minp(v))}</span><small>{v["addr"]}</small></a>') for k, v in H.items()))
    return f'''<dialog class="modal drawer" id="drawer" aria-label="Меню">
  <div class="drawer__head"><a class="brand" href="index.html">{FOX}<span><b>{BRAND}</b></span></a><button class="x" type="button" data-close aria-label="Закрыть меню">{XICON}</button></div>
  <nav class="drawer__nav" aria-label="Меню">
    <p class="drawer__lab">Дома</p>{houses}
    <div class="drawer__links">{mark("menu:drawer", '<a href="index.html">Главная</a><a href="index.html#order">Бронирование</a><a href="blog.html">Блог</a><a href="kontakty.html">Контакты</a>')}</div>
  </nav>
  <div class="drawer__foot">
    <a class="drawer__phone tnum" href="tel:{PHONE_RAW}">{PHONE}</a>
    <p>{max_link('<a href="{url}" target="_blank" rel="noopener">MAX</a> · ')}<a href="{VK}" target="_blank" rel="noopener">ВКонтакте</a></p>
    <button class="btn" type="button" data-order>Оставить заявку</button>
  </div>
</dialog>'''

def form(house="", p="f"):
    """Форма заявки. p — префикс id: «f» в окне, «o» в блоке внизу страницы."""
    opts = mark("formOptions", "".join(mark(f"item:formOption:{k}", f'<option value="{k}"{" selected" if k == house else ""}>{v["name"]} — до {v["max"]} гостей</option>') for k, v in H.items()))
    return f'''<form class="form order-form" id="{p}-form" novalidate>
  <div class="field full"><label for="{p}-house">Дом</label><select id="{p}-house" name="house"><option value="">Выберите Дом</option>{opts}</select></div>
  <div class="field"><label for="{p}-in">Заезд</label><input id="{p}-in" name="checkin" type="date"></div>
  <div class="field"><label for="{p}-out">Выезд</label><input id="{p}-out" name="checkout" type="date"></div>
  <div class="estimate" aria-live="polite"></div>
  <div class="field"><label for="{p}-guests">Сколько гостей</label><input id="{p}-guests" name="guests" type="number" min="1" max="20" inputmode="numeric" placeholder="до 20"></div>
  <div class="field"><label for="{p}-contact">Как с вами связаться</label><select id="{p}-contact" name="contact"><option>Позвонить</option><option>Telegram</option><option>MAX</option><option>WhatsApp</option></select></div>
  <fieldset class="field full"><legend>Повод</legend><div class="chips">
    <label><input type="radio" name="occasion" value="birthday"><span>День рождения</span></label>
    <label><input type="radio" name="occasion" value="corporate"><span>Корпоратив</span></label>
    <label><input type="radio" name="occasion" value="friends"><span>Отдых с друзьями</span></label>
    <label><input type="radio" name="occasion" value="family"><span>Семья</span></label>
    <label><input type="radio" name="occasion" value="other"><span>Другое</span></label>
  </div></fieldset>
  <div class="field"><label for="{p}-name">Имя</label><input id="{p}-name" name="name" type="text" autocomplete="name"></div>
  <div class="field"><label for="{p}-phone">Телефон</label><input id="{p}-phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" placeholder="+7 (___) ___-__-__"></div>
  <div class="field full"><label for="{p}-comment">Комментарий</label><textarea id="{p}-comment" name="comment" placeholder="Например: приедем с собакой, нужен детский стульчик"></textarea></div>
  <div class="hp" aria-hidden="true"><label for="{p}-website">Сайт</label><input id="{p}-website" name="website" tabindex="-1" autocomplete="off"></div>
  <label class="check full"><input type="checkbox" id="{p}-agree" name="agree"><span>Даю <a class="link" href="soglasie.html" target="_blank">согласие на обработку персональных данных</a> и принимаю <a class="link" href="politika.html" target="_blank">Политику обработки персональных данных</a></span></label>
  <label class="check full"><input type="checkbox" id="{p}-sms" name="sms"><span>Можно прислать SMS о брони и после отдыха</span></label>
  <p class="err full" role="alert" hidden></p>
  <div class="full"><button class="btn" type="submit">Отправить заявку</button></div>
</form>'''

def order_modal(house=""):
    return f'''<dialog class="modal omodal" id="order-modal" aria-labelledby="om-title">
  <div class="omodal__box">
    <button class="x" type="button" data-close aria-label="Закрыть форму">{XICON}</button>
    <div class="omodal__head"><h2 id="om-title">Оставить заявку</h2><p class="omodal__disc"><a href="{VK}" target="_blank" rel="noopener">Скидка {DISCOUNT}% при бронировании через ВКонтакте</a></p><p>Перезвоним, подтвердим даты и назовём сумму предоплаты, это 50% от стоимости. Или позвоните: <a class="nb tnum" href="tel:{PHONE_RAW}">{PHONE}</a></p></div>
    {form(house)}
  </div>
</dialog>'''

def footer(house=""):
    links = mark("footHouses", "".join(mark(f"item:footHouse:{k}", f'<a href="{v["file"]}">{v["name"]}, {v["addr"]}</a>') for k, v in H.items()))
    return f'''<footer class="foot"><div class="wrap foot__in">
  {FOX}
  <div>
    <p><a href="index.html">{BRAND}</a>, аренда домов с баней и бассейном в Перми посуточно</p>
    <p>ИП Ефремова Ольга Анатольевна, ИНН 590301252919, ОГРНИП 325595800149589</p>
    <p>Бронирование: <a class="nb tnum" href="tel:{PHONE_RAW}">{PHONE}</a>, <a href="mailto:{MAIL}">{MAIL}</a></p>
    <p class="foot__docs"><a href="politika.html">Политика обработки персональных данных</a> · <a href="soglasie.html">Согласие на обработку персональных данных</a> · <a href="cookie.html">Политика cookie</a> · <button type="button" data-cookie-settings>Настройки cookie</button></p>
  </div>
  <nav aria-label="Дома">{links}</nav>
  <nav aria-label="Разделы">{mark("menu:foot", '<a href="index.html">Главная</a><a href="index.html#order">Бронирование</a><a href="blog.html">Блог</a><a href="kontakty.html">Контакты</a>')}</nav>
</div></footer>
<div class="mbar"><a class="btn btn--ghost" href="tel:{PHONE_RAW}">Позвонить</a><button class="btn" type="button" data-order>Заявка</button></div>
{order_modal(house)}
{drawer()}'''

def order_band(house="", photo="en/facade", title="Оставьте заявку", alt="Английский Дом, фасад"):
    if house:
        h = H[house]
        extra = f'<p>{h["addr"]}, заезд с {h["checkin"]}, выезд до {h["checkout"]}</p><p><a class="btn" href="{h["prepay"]}">Внести предоплату</a></p><p>После того, как администратор подтвердил даты</p>'
    else:
        extra = max_link('<p><a class="link" href="{url}" target="_blank" rel="noopener">Написать в MAX</a></p>') + f'<p><a class="link" href="{VK}" target="_blank" rel="noopener">Мы во ВКонтакте</a> · <a class="link" href="kontakty.html">Все контакты</a></p>' + mark("appLink", "")   # в MODX — ссылка на приложение бронирования (bnAppLink)
    return f'''<section class="band band--soft on-light" id="order" aria-labelledby="order-title"><div class="wrap order">
  <div class="order__txt">
    <div class="sec-head"><h2 class="h-sec" id="order-title">{title}</h2><p class="lead">Перезвоним и подтвердим даты. Бронь действует после предоплаты 50%, остальное — при заселении.</p><p class="disc-line"><a href="{VK}" target="_blank" rel="noopener">Скидка {DISCOUNT}% при бронировании через группу ВКонтакте</a></p></div>
    <div class="order__contacts"><a class="big tnum nb" href="tel:{PHONE_RAW}">{PHONE}</a>{extra}</div>
    <div class="order__photo{" order__photo--tall" if photo == "en/facade" else ""}">{img(photo, alt, sizes="(max-width: 760px) 100vw, 560px")}</div>
  </div>
  {form(house, "o")}
</div></section>'''

def faq_block(items, open_first=True):
    return "".join(f'<details{" open" if open_first and i == 0 else ""}><summary>{esc(q)}</summary><div class="faq__a"><p>{esc(a)}</p></div></details>' for i, (q, a) in enumerate(items))

RULES = [("Залог (зависит от количества и возраста гостей)", "5 000 – 20 000 ₽"), ("Предоплата", "50%, после звонка администратора"), ("Шум, крики и музыка на улице", "нельзя"), ("Громкие разговоры на улице", "нельзя с 22:00 до 8:00"),
         ("Шуметь в доме", "можно при закрытых окнах и дверях, есть кондиционеры"), ("Курение в доме", "нельзя"),
         ("Фейерверки, салюты, хлопушки", "нельзя"), ("Конфетти и блёстки", "нельзя"), ("Дети", "можно"),
         ("Питомцы", "можно, +1 000 ₽"), ("Баня", "топим без ограничений по времени"), ("Отчётные документы", "оформляем")]
GUESTS_RULE = ("Гости сверх 10 (в доме на Свиязева сверх 8)", "+500 ₽ за человека")
SERVICES = "Приготовим из ваших продуктов шашлык (300 ₽ за кг) или плов (2 500 ₽ за казан 10 литров)."
def house_rules(k):
    h = H[k]
    return [("Заезд / выезд", f'с {h["checkin"]} / до {h["checkout"]}'), ("Ночуют / отдыхают", f'{h["sleep"]} / до {h["max"]} гостей'),
            (f'Гости сверх {h["base"]}', f'+{h["extra"]} ₽ за человека')]
def rules_list(extra=(GUESTS_RULE,)):
    return '<ul class="rules">' + "".join(f'<li><span>{a}</span><span class="tnum">{b}</span></li>' for a, b in list(extra) + RULES) + "</ul>"

PREPAY_TXT = "Бронь подтверждается предоплатой 50% от стоимости, остальное доплачиваете при заселении. Вносите её только после того, как администратор подтвердил даты. Оплата по Системе быстрых платежей: сумму вводите сами, в комментарии укажите имя и дату заезда. При отмене предоплату возвращаем, если на ваши даты находится другой гость."

def legal_html(slug):
    """Текст документа: дата редакции и разделы, реквизиты оператора подставлены"""
    html = DOCS[slug]["html"]
    for k, v in (("{OPERATOR}", OPERATOR), ("{MAIL}", MAIL), ("{PHONE_RAW}", PHONE_RAW), ("{PHONE}", PHONE)):
        html = html.replace(k, v)
    return f'<p class="doc-date">Редакция от {LEGAL_DATE}</p>\n' + html.strip()

def legal_page(slug):
    d = DOCS[slug]
    cr, cr_ld = crumbs([("Главная", "index.html"), (d["title"], None)])
    body = f'''{header()}
<main>
<section class="phead on-light"><div class="wrap">
  {cr}
  <h1>{d["title"]}</h1>
</div></section>
<section class="band band--tight on-light"><div class="wrap"><div class="prose">
{legal_html(slug)}
</div></div></section>
</main>
{footer()}'''
    return dict(fn=slug + ".html", title=f'{d["title"]} — {BRAND}', desc=d["desc"], body=body, lds=[cr_ld], og="ru/hero-2")

def notfound_html():
    """Текст страницы 404 (тот же в MODX — «Содержимое» страницы ошибки): куда пойти дальше"""
    houses = "".join(f'<li><a class="link" href="{h["file"]}">{h["name"]}</a> — {esc(h["street"])}</li>' for h in H.values())
    return f'''<p>Такой страницы нет. Возможно, ссылка устарела: сайт обновился, и адреса страниц поменялись.</p>
<p>Наши Дома:</p>
<ul>{houses}</ul>
<p class="nf-actions"><a class="btn" href="index.html">На главную</a> <a class="btn btn--ghost" href="kontakty.html">Контакты</a></p>
<p>Забронировать или спросить — по телефону <a class="link" href="tel:{PHONE_RAW}">{PHONE}</a>.</p>'''

def notfound_page():
    cr, _ = crumbs([("Главная", "index.html"), ("Страница не найдена", None)])   # как в MODX: шаблон текстовой страницы с крошками
    body = f'''{header()}
<main>
<section class="phead on-light"><div class="wrap">
  {cr}
  <h1>Страница не найдена</h1>
</div></section>
<section class="band band--tight on-light"><div class="wrap"><div class="prose">
{notfound_html()}
</div></div></section>
</main>
{footer()}'''
    return dict(fn="404.html", title=f"Страница не найдена — {BRAND}", desc="Такой страницы на сайте нет. Дома с баней и бассейном в Перми — на главной.", body=body, lds=[], og="ru/hero-2", noindex=True)

def crumbs(items):
    """items: [(название, ссылка или None)] — хлебные крошки и их разметка."""
    out = " <span aria-hidden=\"true\">/</span> ".join(f'<a href="{u}">{esc(t)}</a>' if u else f'<span aria-current="page">{esc(t)}</span>' for t, u in items)
    ld = {"@context": "https://schema.org", "@type": "BreadcrumbList", "itemListElement": [
        {"@type": "ListItem", "position": i + 1, "name": t, **({"item": f"{SITE}/" + ("" if u == "index.html" else u)} if u else {})} for i, (t, u) in enumerate(items)]}
    return mark("crumbs", f'<nav class="crumbs" aria-label="Навигация">{out}</nav>'), ld

# ---------- JSON-LD ----------
def ld_house(k):
    h = H[k]
    return {"@context": "https://schema.org", "@type": "LodgingBusiness", "name": f'{h["name"]} — {BRAND}',
        "url": f'{SITE}/{h["file"]}', "telephone": PHONE, "image": [f'{SITE}/img/{k}/{p}.jpg' for p in h["card_photos"]],
        "address": {"@type": "PostalAddress", "streetAddress": h["addr"].replace("ул. ", "улица "), "addressLocality": "Пермь", "addressRegion": "Пермский край", "addressCountry": "RU"},
        "priceRange": f'{minp(h)}–{max(h["prices"])} RUB', "checkinTime": h["checkin"], "checkoutTime": h["checkout"],
        "numberOfRooms": h["rooms"], "petsAllowed": True, "smokingAllowed": False,
        "amenityFeature": [{"@type": "LocationFeatureSpecification", "name": n, "value": True} for _, n, _ in h["comfort"]],
        "makesOffer": {"@type": "Offer", "priceCurrency": "RUB", "price": minp(h), "description": "Аренда Дома на сутки"},
        "sameAs": [card_url(u) for u in HOUSE_REVIEW_LINKS.get(k, {}).values() if u],
        "parentOrganization": ORG,
        "potentialAction": {"@type": "ReserveAction", "target": f"{SITE}/index.html#order", "name": "Забронировать"}}
def ld_faq(items):
    return {"@context": "https://schema.org", "@type": "FAQPage", "mainEntity": [{"@type": "Question", "name": q, "acceptedAnswer": {"@type": "Answer", "text": a}} for q, a in items]}
ORG = {"@type": "Organization", "name": BRAND, "url": SITE + "/", "logo": SITE + "/img/logo-512.png"}
def card_url(u):
    """Ссылка «оставить отзыв» → адрес карточки организации на площадке (для sameAs): без /tab/…, ?… и #…"""
    return re.sub(r"(/tab/.*|[?#].*)$", "", u)
def ld_site():
    """WebSite — по нему Google показывает в выдаче название сайта, а не домен"""
    return {"@context": "https://schema.org", "@type": "WebSite", "name": BRAND, "alternateName": "Ефремовские бани", "url": SITE + "/", "inLanguage": "ru"}
def ld_org():
    return {"@context": "https://schema.org", **ORG, "image": f"{SITE}/img/ru/hero-2.jpg", "telephone": PHONE, "email": MAIL,
            "sameAs": [VK], "department": [{"@type": "LodgingBusiness", "name": v["name"], "url": f'{SITE}/{v["file"]}'} for v in H.values()]}

# ---------- главная ----------
HOME_FAQ = [
 ("Сколько стоит снять Дом с баней и бассейном в Перми?", "От 15 000 ₽ за сутки в доме на Свиязева и от 18 000 ₽ в Английском и Русском доме с понедельника по четверг. В пятницу 20 000–22 000 ₽, в субботу 25 000–27 000 ₽."),
 ("Как забронировать Дом?", "Оставьте заявку на сайте или позвоните по номеру +7 (342) 277-66-22. Администратор проверит даты и назовёт сумму предоплаты. Бронь подтверждается предоплатой 50%, остальное доплачиваете при заселении."),
 ("Можно ли снять Дом на день рождения или корпоратив?", "Да. В Английском и Русском доме ночуют 12 гостей, а отдыхают до 20; в доме на Свиязева ночуют 8, отдыхают до 15. На улице нельзя шуметь, кричать и слушать музыку. С 22:00 до 8:00 на улице нельзя громко разговаривать. В доме шуметь можно при закрытых окнах и дверях — установлены кондиционеры."),
 ("Бассейны работают зимой?", "Да, во всех трёх домах круглый год. В Английском и Русском доме бассейн 3 × 2,5 м, глубина 1,5 м, вода 28–30°. В доме на Свиязева бассейн с функцией джакузи 2,5 × 2 м, глубина 1 м."),
 ("Можно заказать шашлык или плов?", "Да, приготовим из ваших продуктов: шашлык 300 ₽ за кг, плов 2 500 ₽ за казан 10 литров. Скажите об этом администратору при бронировании."),
 ("Есть ли скидки?", "Да, 5% при бронировании через нашу группу ВКонтакте. Ещё дешевле приезжать с понедельника по четверг: сутки от 15 000 ₽."),
 ("Можно с собакой или кошкой?", "Да, во всех трёх домах. Доплата за питомца 1 000 ₽."),
 ("Как внести предоплату?", "Предоплата — 50% от стоимости, вносите её только после того, как администратор подтвердил даты: кнопка «Внести предоплату» есть на странице каждого Дома, оплата через Систему быстрых платежей. В комментарии укажите имя и дату заезда. При отмене предоплату возвращаем, если на ваши даты находится другой гость."),
 ("Что такое залог?", "Сумма от 5 000 до 20 000 ₽ на случай повреждений в доме. Точную сумму администратор назовёт при подтверждении брони. Зависит от количества и возраста гостей."),
]

def tour2_btn(h):
    """Кнопка второго 3D-тура Дома (поле tour2 в houses.py), с подписью tour2txt"""
    if not h.get("tour2"):
        return ""
    return f'<a class="btn btn--ghost" href="{h["tour2"]}" target="_blank" rel="noopener" title="{esc(h.get("tour2txt", ""))}">Второй 3D-тур</a>'

def house_card(k):
    h = H[k]; ph = h["card_photos"]
    caps = dict(h["gallery"])
    total = len(set(ph) | set(caps))
    hint = f'<span class="hcard__zoom"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5M11 8.5v5M8.5 11h5"/></svg>Смотреть все {total} фото</span>'
    media = "".join(f'<a href="img/{k}/{p}.jpg" data-lb="card-{k}" data-cap="{esc(h["name"] + ": " + caps.get(p, ""))}">{img(k + "/" + p, h["name"] + ": " + caps.get(p, "фото"))}{hint if i == 0 else ""}</a>' for i, p in enumerate(ph))
    # остальные фото Дома — в той же группе, чтобы из карточки листалась вся галерея Дома
    media += "".join(f'<a href="img/{k}/{p}.jpg" data-lb="card-{k}" data-cap="{esc(h["name"] + ": " + c)}" hidden></a>' for p, c in h["gallery"] if p not in ph)
    feats = h["features"]
    return f'''<article class="hcard h-{k}">
  <div class="hcard__media">{mark("media", media)}</div>
  <div class="hcard__body">
    <p class="hcard__kick">{h["kicker"]}</p>
    <h3><a href="{h["file"]}">{h["name"]}</a></h3>
    <ul>{mark("features", "".join(f"<li>{f}</li>" for f in feats))}</ul>
    <p class="hcard__price"><b class="tnum">от {money(minp(h))}</b><span>за сутки</span><em class="disc">−{DISCOUNT}% через ВКонтакте</em></p>
    <div class="hcard__cta"><button class="btn btn--sm" type="button" data-order data-house="{k}">Оставить заявку</button><a class="btn btn--sm btn--tour" href="{h["tour"]}" target="_blank" rel="noopener">{icon("cube")}3D-тур</a></div>
    <p class="hcard__links"><a class="btn btn--sm" href="{h["prepay"]}">{icon("card")}Внести предоплату</a><a href="{h["file"]}">Подробнее о доме</a></p>
  </div>
</article>'''

def tour_tile(k):
    h = H[k]
    n = re.search(r"(\d+) точ", h["tourtxt"])
    return f'''<a class="tour h-{k}" href="{h["tour"]}" target="_blank" rel="noopener">
  {img(k + "/" + h["card_photos"][0], h["name"] + ", 3D-тур")}
  <span class="tour__badge">{icon("cube")}3D</span>
  <span class="tour__body"><b>{h["name"]}</b><small>{n.group(1) + " точек обзора" if n else "3D-тур"}</small><span class="btn btn--sm btn--tour">Открыть 3D-тур</span></span>
</a>'''

def review_card(r):
    return mark("item:review", review_card_(r))

def review_card_(r):
    h = H.get(r.get("house", ""))
    stars = max(1, min(5, int(r.get("rating", 5))))
    meta = " · ".join(x for x in [h["name"] if h else "", ru_date(r["date"]) if r.get("date") else ""] if x)
    src = esc(r.get("source", ""))
    src_html = (f'<a class="rev__src" href="{esc(r["url"])}" target="_blank" rel="noopener nofollow">{src}</a>' if r.get("url") else f'<span class="rev__src">{src}</span>') if src else ""
    text = "".join(f"<p>{esc(p.strip())}</p>" for p in r["text"].split("\n") if p.strip())
    return f'''<article class="rev">
  <p class="rev__stars" aria-label="Оценка {stars} из 5">{"★" * stars}<span aria-hidden="true">{"★" * (5 - stars)}</span></p>
  <div class="rev__text">{text}</div>
  <button class="rev__more" type="button" hidden>Читать полностью</button>
  <footer class="rev__foot"><b>{esc(r["name"])}</b>{f"<span>{meta}</span>" if meta else ""}{src_html}</footer>
</article>'''

# Плашки площадок отзывов: простой знак и название (без фирменных картинок)
PLATFORM_BADGE = {"2ГИС": ("#137A18", "2Г"), "Яндекс Карты": ("#C8290B", "Я"), "Авито": ("#006FB3", "A")}   # темнее фирменных: белые буквы читаются (контраст ≥ 4,5:1)

def review_qr(k, name, url):
    """Колонка площадки: значок с названием, под ним QR. Вся колонка — ссылка (на телефоне код не сканируют, а нажимают)"""
    color, sign = PLATFORM_BADGE[name]
    return (f'<a class="hrev__qr" href="{esc(url)}" target="_blank" rel="noopener nofollow">'
            f'<span class="plat"><span class="plat__mark" style="background:{color}" aria-hidden="true">{esc(sign)}</span><span class="plat__name">{esc(name)}<span class="sr">: оставить отзыв о Доме «{esc(H[k]["name"])}»</span></span></span>'
            f'<span class="hrev__qrimg"><img src="img/qr/rev-{k}-{PLATFORM_KEY[name]}.svg" alt="" width="140" height="140" loading="lazy"></span></a>')

def house_review_panel(k):
    """По Дому: площадки отзывов, QR-код «оставить отзыв» и скриншоты отзывов (или заглушки, пока их нет)."""
    h = H[k]
    links = HOUSE_REVIEW_LINKS.get(k, {})
    shots = REVIEW_SHOTS.get(k, [])
    shots_html = "".join(f'<a class="rshot" href="img/reviews/{esc(s)}" data-lb="rev-{k}"><img src="img/reviews/{esc(s)}" alt="Отзыв гостя: {esc(h["name"])}" loading="lazy"></a>' for s in shots) \
        or ""   # скриншотов ещё нет — ленты нет совсем (заглушки «Здесь будет скриншот…» видели гости и поисковики)
    # QR «оставить отзыв» — по коду на каждую площадку со ссылкой (tools/make_qr.py); на телефоне код — просто ссылка
    qrs = "".join(review_qr(k, n, links[n]) for n in REVIEW_PLATFORMS if links.get(n))
    qr = (f'<div class="hrev__qrs"><p class="hrev__qrlab">Оставить отзыв: наведите камеру телефона на код или нажмите на площадку</p><div class="hrev__qrrow">{qrs}</div></div>') if qrs else ""
    return (f'<div class="hrev h-{k}"><div class="hrev__head"><h3>{h["name"]}</h3></div>'
            f'<div class="hrev__body">{rail(shots_html, "Скриншоты отзывов: " + h["name"], "rail--dark rail--shots") if shots_html else ""}{qr}</div></div>')

def reviews_block(house=None):
    """Отзывы гостей: карусель и по каждому Дому — площадки, QR-код и скриншоты отзывов."""
    items = [r for r in REVIEWS if not house or r.get("house") == house]
    links = "".join(f'<a class="btn btn--ghost btn--sm" href="{esc(u)}" target="_blank" rel="noopener">{esc(n)}</a>' for n, u in REVIEW_LINKS.items() if u)
    cta = f'<div class="rev-cta"><p>Отдыхали у нас? Расскажите, как всё прошло:</p>{links}</div>' if links else ""
    panels = mark("houseReviewPanels", "".join(house_review_panel(k) for k in ([house] if house else H)))
    if not items:
        return f'''<section class="band band--dark" id="reviews" aria-labelledby="rev-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="rev-title">Отзывы гостей{"" if not house else " об этом Доме"}</h2><p class="lead">Отзывы о каждом Доме — в 2ГИС, Яндекс Картах и на Авито. Оставить свой — по QR-коду или ссылке площадки ниже.</p></div>
  <div class="hrevs">{panels}</div>
  {cta}
</div></section>'''
    avg = sum(int(r.get("rating", 5)) for r in items) / len(items)
    summary = f'<p class="rev-sum"><b class="tnum">{avg:.1f}'.replace(".", ",") + f'</b> из 5 · {len(items)} {plural_ru(len(items), "отзыв", "отзыва", "отзывов")}</p>'
    title = "Отзывы гостей" + ("" if not house else " об этом доме")
    return f'''<section class="band band--dark" id="reviews" aria-labelledby="rev-title"><div class="wrap">
  <div class="sec-row"><div class="sec-head"><h2 class="h-sec" id="rev-title">{title}</h2><p class="lead">Отзывы публикуем слово в слово и пишем, где гость их оставил.</p></div>{summary}</div>
  {rail("".join(review_card(r) for r in items), "Отзывы гостей", "rail--dark rail--rev")}
  <div class="hrevs">{panels}</div>
  {cta}
</div></section>'''

def plural_ru(n, a, b, c):
    m10, m100 = n % 10, n % 100
    return a if m10 == 1 and m100 != 11 else b if 2 <= m10 <= 4 and not 10 <= m100 < 20 else c

def rail(items_html, label, cls=""):
    return f'''<div class="rail {cls}" data-rail><div class="strip" aria-label="{label}">{items_html}</div>
  <div class="rail__nav"><button class="rail__btn" type="button" data-step="-1" aria-label="Листать назад"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg></button><button class="rail__btn" type="button" data-step="1" aria-label="Листать вперёд"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></button></div></div>'''

def home():
    strip = [("ru/hero", "Русский Дом вечером"), ("en/pool", "Бассейн, Английский Дом"), ("sv/pool", "Всесезонный бассейн, Свиязева"), ("ru/sauna", "Баня, Русский Дом"),
             ("en/billiard-2", "Бильярд, Английский Дом"), ("ru/kitchen", "Кухня-гостиная, Русский Дом"), ("sv/billiard", "Бильярд, Свиязева"), ("en/fireplace", "Камин, Английский Дом"),
             ("ru/pool", "Бассейн, Русский Дом"), ("sv/gazebo", "Беседка с мангалом, Свиязева"), ("en/dining", "Стол, Английский Дом"), ("ru/bedroom", "Спальня, Русский Дом")]
    strip_html = "".join(f'<a href="img/{p}.jpg" data-lb="strip" data-cap="{esc(c)}">{img(p, "")}<span>{c}</span></a>' for p, c in strip)
    table_rows = [("Дом и участок", lambda h: f'{h["area"]} м², {h["rooms"]} комнат{"ы" if h["rooms"] < 5 else ""}, {h["plot"]}'),
                  ("Ночуют / отдыхают", lambda h: f'{h["sleep"]} / до {h["max"]} гостей'),
                  ("Заезд / выезд", lambda h: f'с {h["checkin"]} / до {h["checkout"]}')]
    th = "".join(f'<th scope="col" class="h-{k}">{v["name"]}</th>' for k, v in H.items())
    rows = "".join(f'<tr><th scope="row">{lab}</th>' + "".join(f"<td>{fn(v)}</td>" for v in H.values()) + "</tr>" for lab, fn in table_rows)
    rows += '<tr><th scope="row">В цене гостей</th>' + "".join(f'<td>до {v["base"]}, далее +{v["extra"]} ₽ за человека</td>' for v in H.values()) + "</tr>"
    rows += '<tr><th scope="row">Для отдыха</th>' + "".join(f"<td>{v['table_extra']}</td>" for v in H.values()) + "</tr>"
    # звёздочка — праздничные дни считаются по правилу (сноска под таблицей)
    for i, d in enumerate(["Пн – Чт*", "Пятница*", "Суббота", "Воскресенье*"]):
        rows += f'<tr class="money{" money-first" if i == 0 else ""}"><th scope="row">{d}</th>' + "".join(f'<td class="tnum">{money(v["prices"][i])}</td>' for v in H.values()) + "</tr>"
    rows += '<tr><th scope="row"><span class="sr">Ссылки</span></th>' + "".join(f'<td><a href="{v["file"]}">Подробнее о доме</a><br><a class="btn btn--sm" href="{v["prepay"]}">Внести предоплату</a></td>' for v in H.values()) + "</tr>"
    posts = "".join(post_card(p) for p in POSTS[:3])
    body = f'''{header("home")}
<main>
<section class="hero" aria-labelledby="hero-title">
  {mark("heroImg", img("ru/hero-2", "Русский Дом: фасад и беседка во дворе", "hero__img", lazy=False, sizes="100vw"))}
  <div class="wrap"><div class="hero__in">
    <p class="hero__kicker">{BRAND}</p>
    <h1 id="hero-title">Снять Дом с баней и бассейном в Перми</h1>
    <p class="hero__sub">Три Дома в трёх стилях: Английский, Русский и Дом на Свиязева. Баня, бассейн, бильярд и мангал, а на праздник можно позвать до 20 гостей.</p>
    <div class="hero__cta"><button class="btn" type="button" data-order>Оставить заявку</button><a class="btn btn--ghost" href="#houses">Выбрать Дом</a></div>
    <div class="facts-row"><a class="btn btn--sm disc-btn" href="{VK}" target="_blank" rel="noopener">−{DISCOUNT}% при бронировании через ВКонтакте</a><span>сутки от 15 000 ₽</span><span>до 20 гостей</span><span>в черте города</span><span>можно с питомцами</span></div>
  </div></div>
</section>

<section class="band on-light" id="houses" aria-labelledby="houses-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="houses-title">Три Дома, три характера</h2><p class="lead">В каждом доме баня, бассейн, бильярд, кухня и спальни. Выбирайте по стилю, числу гостей и дню недели.</p></div>
  <div class="houses">{mark("houseCards", "".join(mark(f"item:houseCard:{k}", house_card(k)) for k in H))}</div>
</div></section>

<section class="band band--dark tours" id="tours" aria-labelledby="tours-title"><div class="wrap">
  <div class="sec-row"><div class="sec-head"><h2 class="h-sec" id="tours-title">Пройдитесь по домам в 3D</h2><p class="lead">Откройте 3D-тур и осмотрите комнаты, баню, бассейн и бильярдную до брони, как будто вы уже внутри.</p></div></div>
  <div class="tours__grid">{mark("tourTiles", "".join(mark(f"item:tourTile:{k}", tour_tile(k)) for k in H))}</div>
</div></section>

<section class="band band--soft" id="comfort" aria-labelledby="comfort-title"><div class="wrap comfort">
  <div class="collage">
    <figure>{img("en/kitchen", "Кухня в Английском доме")}<figcaption>Кухня, Английский Дом</figcaption></figure>
    <figure>{img("ru/pool-2", "Бассейн в Русском доме")}<figcaption>Бассейн, Русский Дом</figcaption></figure>
    <figure>{img("sv/sauna", "Баня в доме на Свиязева")}<figcaption>Баня, Свиязева</figcaption></figure>
  </div>
  <div>
    <div class="sec-head"><h2 class="h-sec" id="comfort-title">Дизайнерские Дома, где всё уже готово</h2><p class="lead">Мы позаботились о комфорте гостей. Привозите продукты и хорошую компанию, остальное в доме есть.</p></div>
    <ul class="amen">
      <li>{icon("kitchen")}<div><b>Кухня с посудой</b><span>Холодильник, плита, микроволновка, посуда на всех</span></div></li>
      <li>{icon("steam")}<div><b>Баня и бассейн</b><span>Баню топим без ограничений, бассейны круглый год. На Свиязева бассейн с джакузи</span></div></li>
      <li>{icon("cue")}<div><b>Бильярд</b><span>В каждом доме</span></div></li>
      <li>{icon("fire")}<div><b>Мангал и беседка</b><span>Мангалы и шампуры уже на месте</span></div></li>
      <li>{icon("bed")}<div><b>Спальни и бельё</b><span>Чистое постельное бельё на всех, кто ночует</span></div></li>
      <li>{icon("snow")}<div><b>Кондиционеры</b><span>На каждом этаже в Английском и Русском доме</span></div></li>
      <li>{icon("tv")}<div><b>Телевизор и Wi-Fi</b><span>Wi-Fi в Английском и Русском доме</span></div></li>
      <li>{icon("car")}<div><b>Свой участок</b><span>Закрытый двор и парковка</span></div></li>
    </ul>
    <p class="included"><b>Входит в стоимость:</b> постельное бельё, посуда, мангал и шампуры, чай, кофе, сахар, специи, питьевая вода.</p>
    <p class="included included--svc"><b>Повар:</b> {SERVICES[0].lower() + SERVICES[1:]}</p>
  </div>
</div></section>

<section class="band band--dark" aria-labelledby="occ-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="occ-title">Для праздника и для отдыха</h2><p class="lead">Дома стоят в городе: гости доезжают на такси, а вечер не нужно сворачивать ради дороги домой.</p></div>
  <div class="occ">
    <article>{img("mood/occ-birthday", "Праздничный стол с тортом и свечами")}<h3>День рождения</h3><p>Большой стол, музыка, баня и бассейн. Шуметь и слушать музыку — в доме при закрытых окнах и дверях, хоть до утра: есть кондиционеры.</p><p class="fit">Русский или Английский Дом, до 20 гостей</p><button type="button" data-order data-occasion="birthday">Заявка на день рождения</button></article>
    <article>{img("mood/occ-friends", "Друзья отдыхают в бане")}<h3>Баня с друзьями</h3><p>Попариться, поплавать, сыграть в бильярд. С понедельника по четверг сутки дешевле.</p><p class="fit">Свиязева: бассейн-джакузи, от 15 000 ₽</p><button type="button" data-order data-occasion="friends">Заявка для компании</button></article>
    <article>{img("mood/occ-corporate", "Коллеги празднуют за общим столом")}<h3>Корпоратив</h3><p>Стол на всю команду, бильярд и мангал. Отчётные документы для бухгалтерии оформляем.</p><p class="fit">Английский Дом: 200 м², 6 комнат</p><button type="button" data-order data-occasion="corporate">Заявка на корпоратив</button></article>
    <article>{img("mood/occ-family", "Ужин во дворе Дома на закате")}<h3>Семейные выходные</h3><p>Можно с детьми и с питомцами. Отдельные спальни для тех, кто ляжет пораньше.</p><p class="fit">Любой из трёх домов</p><button type="button" data-order data-occasion="family">Заявка для семьи</button></article>
  </div>
</div></section>

<section class="promo" aria-labelledby="promo-title"><div class="wrap promo__in">
  <div><h2 id="promo-title">Забронируйте баню прямо сейчас — скидка {DISCOUNT}%</h2><p>Скидка {DISCOUNT}% при бронировании через нашу <a href="{VK}" target="_blank" rel="noopener">группу ВКонтакте</a>. А с понедельника по четверг сутки ещё и дешевле: от 15 000 ₽.</p></div>
  <a class="btn" href="{VK}" target="_blank" rel="noopener">Получить скидку</a>
</div></section>

<section class="band on-light" id="prices" aria-labelledby="prices-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="prices-title">Сравните Дома и цены</h2><p class="lead">Цена за сутки зависит от дня недели и рассчитана на компанию до 10 человек, на Свиязева до 8. За каждого следующего гостя 500 ₽, за питомца 1 000 ₽.</p></div>
  {mark("priceTable", f'<div class="table-wrap"><table class="cmp"><thead><tr><th scope="col"><span class="sr">Параметр</span></th>{th}</tr></thead><tbody>{rows}</tbody></table></div>')}
  <p class="note">* Праздничные дни: предпраздничный рабочий день считается как пятница, праздник перед выходным как суббота, праздник перед рабочим днём как воскресенье. Предоплату вносите после того, как администратор подтвердил даты.</p>
</div></section>

<section class="band band--soft" aria-labelledby="strip-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="strip-title">Посмотрите Дома изнутри</h2><p class="lead">Листайте ленту мышью или пальцем, нажмите на фото, чтобы открыть крупно. Для каждого Дома есть 3D-тур на его странице.</p></div>
  {mark("strip", rail(strip_html, "Фото домов"))}
</div></section>

<section class="band on-light" id="rules" aria-labelledby="rules-title"><div class="wrap two">
  <div><div class="sec-head"><h2 class="h-sec" id="rules-title">Правила домов</h2></div>{mark("rules", rules_list())}</div>
  <div class="faq"><div class="sec-head"><h2 class="h-sec">Частые вопросы</h2></div>{mark("faq", faq_block(HOME_FAQ))}{max_link('<p class="faq__ask">Не нашли ответ? <a class="link" href="{url}" target="_blank" rel="noopener">Спросите бота в MAX</a> — ответит сразу: цены, заезд, баня, правила.</p>')}</div>
</div></section>

<section class="band band--soft" aria-labelledby="seo-title"><div class="wrap seo">
  <h2 class="h-sec" id="seo-title">Аренда Дома с баней и бассейном в Перми посуточно</h2>
  <div class="seo__text">{mark("seo", f'''
    <p>«{BRAND}» сдают посуточно три Дома в Перми: <strong>Английский Дом</strong> и <strong>Русский Дом</strong> на улице Космонавта Беляева и <strong>Дом на Свиязева</strong>. Если вы ищете, где снять Дом на сутки с баней и бассейном, не уезжая за город, начните с них: в каждом доме своя баня, бассейн, бильярд, кухня и спальни.</p>
    <h3>Баня с бассейном для компании</h3>
    <p>В Английском и Русском доме ночуют 12 человек, а на вечер можно позвать до 20 гостей. Бассейны работают круглый год, а в доме на Свиязева бассейн с функцией джакузи. Это удобный вариант, когда нужна баня с бассейном в Перми для дня рождения, девичника, встречи друзей или корпоратива. Если вы искали сауну с бассейном, здесь то же самое, только вместо нескольких часов в зале у вас целый Дом на сутки.</p>
    <h3>Сколько стоит снять коттедж посуточно</h3>
    <p>Сутки стоят от 15 000 ₽ в будни и от 25 000 ₽ в субботу. В цену входят постельное бельё, посуда, мангал и шампуры, чай и кофе. Отдельно оплачиваются питомец и гости сверх 10 человек (на Свиязева сверх 8), по 500 ₽ за каждого. Залог от 5 000 до 20 000 ₽ администратор называет при подтверждении брони.</p>
    <h3>Как забронировать</h3>
    <p>Оставьте заявку или позвоните по номеру <span class="nb">{PHONE}</span>. Администратор проверит свободные даты, перезвонит и пришлёт ссылку на предоплату 50%.</p>
''')}  </div>
</div></section>

{mark("reviews", reviews_block())}

<section class="band on-light" aria-labelledby="blog-title"><div class="wrap">
  <div class="sec-row"><div class="sec-head"><h2 class="h-sec" id="blog-title">Блог</h2><p class="lead">Как отметить праздник, сэкономить на аренде и провести вечер в бане.</p></div><a class="btn btn--ghost on-light" href="blog.html">Все статьи</a></div>
  <div class="posts posts--3">{mark("posts3", posts)}</div>
</div></section>

{mark("orderBand", order_band())}

<section class="band band--dark" id="contacts" aria-labelledby="addr-title"><div class="wrap">
  <div class="sec-row"><div class="sec-head"><h2 class="h-sec" id="addr-title">Где мы</h2><p class="lead">Все три Дома в новом квартале Перми, у каждого свой участок и парковка.</p></div><a class="btn btn--ghost" href="kontakty.html">Контакты и схема проезда</a></div>
  <div class="addr">{mark("addrCards", "".join(mark(f"item:addrCard:{k}", f'<a class="addr__card" href="{v["file"]}">{img(k + "/" + v["card_photos"][0], v["name"] + ", фасад")}<h3>{v["name"]}</h3><p>{v["addr"]}</p><span>Подробнее о доме</span></a>') for k, v in H.items()))}</div>
</div></section>
</main>
{footer()}'''
    return dict(fn="index.html", title=f"Снять Дом с баней и бассейном в Перми посуточно — {BRAND}",
                desc="Дома с баней и бассейном в Перми посуточно — вместо сауны целый Дом на сутки: Английский, Русский и Дом на Свиязева. Бильярд, мангал, до 20 гостей. От 15 000 ₽.",
                body=body, lds=[ld_site(), ld_org(), ld_faq(HOME_FAQ)], og="ru/hero-2", og_alt="Русский Дом: фасад и беседка во дворе")

# ---------- страница Дома ----------
def house_page(k):
    h = H[k]
    sizes = {0: "big", 1: "t", 4: "w", 7: "w"}
    n = len(h["gallery"]); cells = n + 3 + 1 + 1 + 1; rem = cells % 4
    for j in range((4 - rem) % 4): sizes.setdefault(n - 1 - j, "w")
    gal = "".join(f'<a class="{sizes.get(i, "")}" href="img/{k}/{p}.jpg" data-lb="g-{k}" data-cap="{esc(c)}">{img(k + "/" + p, h["name"] + ": " + c, sizes="(max-width: 760px) 100vw, 640px" if sizes.get(i, "") in ("big", "w") else "(max-width: 760px) 50vw, 320px")}<span>{c}</span></a>' for i, (p, c) in enumerate(h["gallery"]))
    amen = "".join(f'<li>{icon(i)}<div><b>{b}</b><span>{d}</span></div></li>' for i, b, d in h["comfort"])
    hot = max(range(4), key=lambda i: h["prices"][i])
    prices = "".join(f'<div{" class=" + chr(34) + "hot" + chr(34) if i == hot else ""}><span>{DAYS[i]}</span><b class="tnum">{money(v)}</b></div>' for i, v in enumerate(h["prices"]))
    facts = "".join(f'<div><b class="tnum">{a}</b><span>{b}</span></div>' for a, b in h["facts"])
    seo = "".join(f"<p>{t}</p>" for t in h["seo"])
    others = "".join(house_card(o) for o in H if o != k)
    cr, cr_ld = crumbs([("Главная", "index.html"), (h["name"], None)])
    body = f'''{header(k)}
<main class="h-{k}">
<section class="dhero" aria-labelledby="d-title">
  {mark("houseHero", img(k + "/hero", h["name"] + ", " + dict(h["gallery"]).get("hero", "фото"), "dhero__img", lazy=False, sizes="100vw"))}
  <div class="wrap"><div class="dhero__in">
    {cr}
    <p class="dhero__kicker">{h["kicker"]}</p>
    <h1 id="d-title">{h["h1"]}</h1>
    <p class="intro">{mark("v:intro", h["intro"])}</p>
    <div class="dfacts">{mark("facts", facts)}</div>
    <p class="dprice">от {money(minp(h))} <small>за сутки, {h["addr"]}</small></p>
    <div class="hero__cta"><button class="btn" type="button" data-order data-house="{k}">Оставить заявку</button><a class="btn btn--ghost btn--ico" href="{h["tour"]}" target="_blank" rel="noopener">{icon("cube")}Открыть 3D-тур</a><a class="btn btn--ghost btn--ico" href="{h["prepay"]}">{icon("card")}Внести предоплату</a></div>
  </div></div>
</section>

<section class="band on-light" aria-labelledby="g-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="g-title">Фото Дома</h2><p class="lead">Нажмите на фото, чтобы открыть крупно и пролистать.</p></div>
  <div class="gal">{mark("gallery", gal)}</div>
  <div class="tourband"><div><h3>3D-тур по дому</h3><p>{mark("v:tour_text", h["tourtxt"])}</p></div><div class="tourband__btns"><a class="btn" href="{h["tour"]}" target="_blank" rel="noopener">Открыть 3D-тур</a>{mark("tour2", tour2_btn(h))}</div></div>
</div></section>

<section class="band band--soft" aria-labelledby="c-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="c-title">Мы позаботились о комфорте</h2><p class="lead">Всё для большого стола и долгого вечера уже в доме.</p></div>
  <ul class="amen amen--4">{mark("comfort", amen)}</ul>
  <p class="included"><b>Входит в стоимость:</b> постельное бельё, посуда, мангал и шампуры, чай, кофе, сахар, специи, питьевая вода.</p>
</div></section>

<section class="band on-light" id="price" aria-labelledby="p-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="p-title">Цена за сутки</h2><p class="lead">Заезд с {h["checkin"]}, выезд до {h["checkout"]} следующего дня. Цена для компании до {h["base"]} человек, за каждого следующего гостя +{h["extra"]} ₽.</p></div>
  <div class="price-days">{mark("prices", prices)}</div>
  <p class="note">Праздники считаются как выходные. Залог 5 000 – 20 000 ₽, питомец +1 000 ₽. <b>Скидка {DISCOUNT}% при бронировании через <a href="{VK}" target="_blank" rel="noopener">группу ВКонтакте</a>.</b></p>
  <div class="prepay" id="prepay">{icon("card")}<div><h3>Предоплата за {h["name"]}</h3><p>{PREPAY_TXT}</p></div><a class="btn" href="{h["prepay"]}">Внести предоплату</a></div>
</div></section>

<section class="band band--soft" aria-labelledby="r-title"><div class="wrap two">
  <div><div class="sec-head"><h2 class="h-sec" id="r-title">Правила Дома</h2></div>{mark("houseRules", rules_list(house_rules(k)))}</div>
  <div class="hinfo">{mark("hinfo", f'''
    <div><h3>Посмотреть Дом</h3><p>Ежедневно {h["view"]}, между заездами гостей. Позвоните заранее: <a class="nb tnum" href="tel:{PHONE_RAW}">{PHONE}</a></p></div>
    {f'<div><h3>Бассейн</h3><p>Работает круглый год: {h["pool"]}.</p></div>' if h["pool"] else f'<div><h3>Бассейн-джакузи</h3><p>Бассейн всесезонный, с функцией джакузи{": " + h["jacuzzi"] if h.get("jacuzzi") else ""}. Рядом баня.</p></div>'}
    <div><h3>Повар</h3><p>{SERVICES} Скажите об этом при бронировании.</p></div>''')}
  </div>
</div></section>

<section class="band on-light" aria-labelledby="s-title"><div class="wrap two">
  <div class="seo__text"><h2 class="h-sec" id="s-title" style="color:var(--bark)">{h["name"]} в Перми посуточно</h2>{mark("houseSeo", seo)}</div>
  <div class="faq"><div class="sec-head"><h2 class="h-sec">Вопросы о доме</h2></div>{mark("houseFaq", faq_block(h["faq"]))}{max_link('<p class="faq__ask">Не нашли ответ? <a class="link" href="{url}" target="_blank" rel="noopener">Спросите бота в MAX</a> — ответит сразу: цены, заезд, баня, правила.</p>')}</div>
</div></section>

{mark("houseReviews", reviews_block(k))}

{mark("houseOrder", order_band(k, k + "/" + h["card_photos"][1], "Заявка: " + h["name"], h["name"]))}

<section class="band on-light" aria-labelledby="o-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="o-title">Другие Дома</h2></div>
  <div class="houses houses--2">{mark("otherHouses", others)}</div>
</div></section>
</main>
{footer(k)}'''
    return dict(fn=h["file"], title=h["title"], desc=h["desc"], body=body, lds=[ld_house(k), ld_faq(h["faq"]), cr_ld], og=k + "/hero")

# ---------- контакты ----------
def contacts_page():
    cr, cr_ld = crumbs([("Главная", "index.html"), ("Контакты", None)])
    def house_block(k):
        h = H[k]; q = quote(f"Пермь, {h['street']}")
        return f'''<article class="cplace h-{k}">
  <a class="cplace__photo" href="{h["file"]}" tabindex="-1" aria-hidden="true">{img(k + "/facade", h["name"] + ", фасад")}</a>
  <div class="cplace__body">
    <h3><a href="{h["file"]}">{h["name"]}</a></h3>
    <p class="cplace__addr">{icon("pin")}Пермь, {h["addr"]}</p>
    <p>Заезд с {h["checkin"]}, выезд до {h["checkout"]}. Ночуют {h["sleep"]}, отдыхают до {h["max"]} гостей. Посмотреть Дом можно ежедневно {h["view"]}.</p>
    <p class="cplace__links"><a class="link" href="https://yandex.ru/maps/?rtext=~{q}&amp;rtt=auto" target="_blank" rel="noopener">Маршрут в Яндекс Картах</a><a class="link" href="https://2gis.ru/perm/search/{q}" target="_blank" rel="noopener">Найти в 2ГИС</a></p>
    <div class="cplace__cta"><button class="btn btn--sm" type="button" data-order data-house="{k}">Оставить заявку</button><a class="btn btn--sm" href="{h["prepay"]}">Внести предоплату</a></div>
  </div>
</article>'''
    body = f'''{header("contacts")}
<main>
<section class="phead on-light"><div class="wrap">
  {cr}
  <h1>Контакты</h1>
  <p class="lead">Бронирование всех трёх домов по одному номеру. Позвоните или оставьте заявку, администратор проверит даты и перезвонит.</p>
</div></section>

<section class="band band--tight on-light" aria-label="Как связаться"><div class="wrap">
  <div class="cgrid">
    <a class="ccard ccard--main" href="tel:{PHONE_RAW}">{icon("phone")}<span>Бронирование</span><b class="tnum">{PHONE}</b></a>
    {max_link('<a class="ccard" href="{url}" target="_blank" rel="noopener">' + icon("chat") + '<span>MAX</span><b>Написать в чат</b></a>')}
    <a class="ccard" href="{VK}" target="_blank" rel="noopener">{icon("chat")}<span>ВКонтакте</span><b>vk.com/russianbath59</b></a>
    <a class="ccard" href="mailto:{MAIL}">{icon("mail")}<span>Почта</span><b>{MAIL}</b></a>
  </div>
  <div class="hero__cta"><button class="btn" type="button" data-order>Оставить заявку</button></div>
</div></section>

<section class="band band--soft" aria-labelledby="where-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="where-title">Адреса домов</h2><p class="lead">Все три Дома в черте Перми, в новом жилом квартале. У каждого Дома свой участок и одно парковочное место, гостям удобно приезжать на такси.</p></div>
  <div class="cplaces">{mark("contactPlaces", "".join(mark(f"item:contactPlace:{k}", house_block(k)) for k in H))}</div>
</div></section>

<section class="band on-light" aria-labelledby="pay-title"><div class="wrap two">
  <div><div class="sec-head"><h2 class="h-sec" id="pay-title">Предоплата</h2><p class="lead">{PREPAY_TXT}</p></div></div>
  <div class="paylist">{mark("payItems", "".join(mark(f"item:payItem:{k}", f'<a class="paylist__item h-{k}" href="{v["prepay"]}"><span><b>{v["name"]}</b><small>{v["addr"]}</small></span><span class="btn btn--sm">Внести предоплату</span></a>') for k, v in H.items()))}</div>
</div></section>

<section class="band band--soft" aria-labelledby="req-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="req-title">Реквизиты</h2></div>
  <ul class="rules rules--req">
    <li><span>Исполнитель</span><span>ИП Ефремова Ольга Анатольевна</span></li>
    <li><span>ИНН</span><span class="tnum">590301252919</span></li>
    <li><span>ОГРНИП</span><span class="tnum">325595800149589</span></li>
    <li><span>Почта</span><span><a href="mailto:{MAIL}">{MAIL}</a></span></li>
    <li><span>Отчётные документы</span><span>оформляем, скажите о них при бронировании</span></li>
  </ul>
</div></section>
</main>
{footer()}'''
    ld = {"@context": "https://schema.org", "@type": "ContactPage", "name": "Контакты", "url": f"{SITE}/kontakty.html",
          "mainEntity": {**ORG, "telephone": PHONE, "email": MAIL, "contactPoint": [{"@type": "ContactPoint", "telephone": PHONE, "contactType": "reservations", "areaServed": "RU", "availableLanguage": "ru"}]}}
    return dict(fn="kontakty.html", title=f"Контакты и адреса домов — {BRAND}",
                desc="Телефон бронирования +7 (342) 277-66-22, ВКонтакте. Адреса домов с баней и бассейном в Перми на картах, предоплата и реквизиты.",
                body=body, lds=[ld, ld_org(), cr_ld], og="en/facade")

# ---------- блог ----------
def post_card(p, big=False):
    return mark(f"item:postCard:{p['slug']}", f'''<article class="pcard{" pcard--big" if big else ""}" data-cat="{p["cat"]}">
  <a class="pcard__img" href="{post_file(p)}" tabindex="-1" aria-hidden="true">{img(p["cover"], p["cover_alt"])}</a>
  <div class="pcard__body">
    <p class="pmeta"><span>{CATS[p["cat"]]}</span><span>{read_min(p)} мин чтения</span></p>
    <h3><a href="{post_file(p)}">{p["h1"]}</a></h3>
    <p>{p["lead"] if big else p["desc"]}</p>
  </div>
</article>''')

def blog_index():
    cr, cr_ld = crumbs([("Главная", "index.html"), ("Блог", None)])
    used = [c for c in CATS if any(p["cat"] == c for p in POSTS)]
    chips = '<button type="button" data-c="all" aria-pressed="true">Все статьи</button>' + "".join(f'<button type="button" data-c="{c}" aria-pressed="false">{CATS[c]}</button>' for c in used)
    cards = "".join(post_card(p, i == 0) for i, p in enumerate(POSTS))
    body = f'''{header("blog")}
<main>
<section class="phead on-light"><div class="wrap">
  {cr}
  <h1>Блог</h1>
  <p class="lead">Как отметить праздник, сэкономить на аренде и провести вечер в бане с пользой. Пишем о том, что знаем по своим домам.</p>
  <div class="filter" role="group" aria-label="Темы" data-cat-filter="#posts">{mark("blogChips", chips)}</div>
</div></section>
<section class="band band--tight on-light" aria-label="Статьи"><div class="wrap">
  <div class="posts" id="posts">{mark("blogCards", cards)}</div>
</div></section>
{order_band()}
</main>
{footer()}'''
    ld = {"@context": "https://schema.org", "@type": "Blog", "name": f"Блог — {BRAND}", "url": f"{SITE}/blog.html", "inLanguage": "ru", "publisher": ORG,
          "blogPost": [{"@type": "BlogPosting", "headline": p["h1"], "url": f"{SITE}/{post_file(p)}", "datePublished": p["date"]} for p in POSTS]}
    return dict(fn="blog.html", title=f"Блог о отдыхе в домах с баней в Перми — {BRAND}".replace("о отдыхе", "об отдыхе"),
                desc="Статьи о том, как снять Дом с баней и бассейном в Перми: праздники, корпоративы, цены, баня зимой. Советы от Доходных домов Ефремовых.",
                body=body, lds=[ld, cr_ld], og=POSTS[0]["cover"])

def post_page(p):
    url = f"{SITE}/{post_file(p)}"
    cr, cr_ld = crumbs([("Главная", "index.html"), ("Блог", "blog.html"), (p["h1"], None)])
    toc = "".join(f'<li><a href="#{sid}">{t}</a></li>' for sid, t, _ in p["sections"])
    mid = len(p["sections"]) // 2
    secs_l = [f'<section id="{sid}"><h2>{t}</h2>{h}</section>' for sid, t, h in p["sections"]]
    inline_cta = '<aside class="callout"><p><b>Хотите узнать, свободны ли даты?</b> Оставьте заявку — администратор перезвонит и назовёт точную сумму.</p><button class="btn btn--sm" type="button" data-order>Оставить заявку</button></aside>'
    secs = "".join(secs_l[:mid]) + inline_cta + "".join(secs_l[mid:])
    others = [o for o in POSTS if o is not p][:3]
    share_u = quote(url, safe="")
    body = f'''{header("blog")}
<main>
<article class="post">
  <header class="phead phead--post on-light"><div class="wrap">
    {cr}
    {mark("postMeta", f'<p class="pmeta"><a href="blog.html">{CATS[p["cat"]]}</a><time datetime="{p["date"]}">{ru_date(p["date"])}</time><span>{read_min(p)} мин чтения</span></p>')}
    <h1>{mark("v:post_title", p["h1"])}</h1>
    <p class="post__lead">{mark("v:post_lead", p["lead"])}</p>
  </div></header>
  <div class="wrap">{mark("postCover", f'<figure class="post__cover">{img(p["cover"], p["cover_alt"], lazy=False, sizes="(max-width: 760px) 100vw, 900px")}<figcaption>{p["cover_alt"]}</figcaption></figure>')}</div>
  <div class="wrap post__grid">
    <aside class="post__aside">
      <nav class="toc" aria-label="Содержание"><p>Содержание</p><ol>{mark("toc", toc)}</ol></nav>
      <div class="post__cta"><p>Три Дома с баней и бассейном в Перми, сутки от 15 000 ₽.</p><button class="btn btn--sm" type="button" data-order>Подобрать Дом</button><a class="link tnum" href="tel:{PHONE_RAW}">{PHONE}</a></div>
    </aside>
    <div class="prose">
      {mark("postBody", secs)}
      <footer class="share"><span>Поделиться:</span><a class="link" href="https://vk.com/share.php?url={mark("v:share_url", share_u)}" target="_blank" rel="noopener">ВКонтакте</a><button class="link" type="button" data-copy>Скопировать ссылку</button></footer>
    </div>
  </div>
</article>

<section class="band band--soft" aria-labelledby="ph-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="ph-title">Дома из статьи</h2></div>
  {mark("postHouses", f'<div class="houses{" houses--2" if len(p["houses"]) == 2 else (" houses--1" if len(p["houses"]) == 1 else "")}">{"".join(house_card(k) for k in p["houses"])}</div>')}
</div></section>

<section class="band on-light" aria-labelledby="more-title"><div class="wrap">
  <div class="sec-row"><div class="sec-head"><h2 class="h-sec" id="more-title">Читайте также</h2></div><a class="btn btn--ghost on-light" href="blog.html">Все статьи</a></div>
  <div class="posts posts--3">{mark("postOthers", "".join(post_card(o) for o in others))}</div>
</div></section>
</main>
{footer()}'''
    ld = {"@context": "https://schema.org", "@type": "BlogPosting", "headline": p["h1"], "description": p["desc"], "image": f'{SITE}/img/{p["cover"]}.jpg',
          "datePublished": p["date"], "dateModified": p.get("updated", p["date"]), "inLanguage": "ru", "articleSection": CATS[p["cat"]],
          "author": ORG, "publisher": ORG, "mainEntityOfPage": url}
    extra = f'<meta property="article:published_time" content="{p["date"]}"><meta property="article:section" content="{CATS[p["cat"]]}">'
    return dict(fn=post_file(p), title=f'{p["title"]} — {BRAND}', desc=p["desc"], body=body, lds=[ld, cr_ld], og=p["cover"], ogtype="article", extra=extra, lastmod=p.get("updated", p["date"]))

# ---------- скрытые страницы предоплаты (адреса как на старом сайте: /Предоплата1/ …) ----------
def prepay_page(k):
    h = H[k]
    cr, cr_ld = crumbs([("Главная", "index.html"), (h["name"], h["file"]), ("Предоплата", None)])
    body = f'''{header()}
<main class="h-{k}">
<section class="phead on-light"><div class="wrap">
  {cr}
  <h1>Предоплата: {h["name"]}</h1>
  <p class="lead">{h["addr"]}. Бронь подтверждается предоплатой 50% от стоимости, остальное доплачиваете при заселении.</p>
</div></section>
<section class="band band--tight on-light" aria-label="Как внести предоплату"><div class="wrap paypage">
  <div class="paypage__main">
    <div class="paypage__warn">{icon("card")}<p><b>Вносите предоплату только после того, как администратор записал вас и согласовал сумму.</b> Если ещё не договорились, позвоните: <a class="nb tnum" href="tel:{PHONE_RAW}">{PHONE}</a></p></div>
    <ol class="paysteps">
      <li>Отметьте согласие с условиями и нажмите «Внести предоплату через СБП» — откроется страница Системы быстрых платежей.</li>
      <li>Выберите свой банк и подтвердите переход в приложение.</li>
      <li>Введите сумму, которую назвал администратор. В комментарии укажите имя и дату заезда.</li>
    </ol>
    <div class="payterms"><h2>Условия предоплаты</h2><p>Предоплата — 50% от стоимости брони. В случае отказа от брони предоплата возвращается, только если эту дату снимут другие гости за ту же сумму.</p>
      <label class="check"><input type="checkbox" data-pay-agree><span>Я прочитал условия предоплаты и согласен с ними</span></label></div>
    <div class="hero__cta"><a class="btn btn--lg is-off" href="{h["sbp"]}" target="_blank" rel="noopener" data-pay-btn aria-disabled="true">Внести предоплату через СБП</a><button class="btn btn--ghost on-light" type="button" data-order data-house="{k}">Оставить заявку</button></div>
  </div>
  <figure class="paypage__qr is-locked" data-pay-qr><div class="paypage__qrimg"><img src="img/qr/{k}.svg" alt="QR-код для оплаты предоплаты через СБП" width="280" height="280"><p class="paypage__lock">Код появится, когда отметите согласие с условиями предоплаты</p></div><figcaption>Оплата с компьютера: наведите камеру телефона на код</figcaption></figure>
</div></section>
</main>
{footer(k)}'''
    n = h["prepay"].strip("/")
    return dict(fn=f"{n}/index.html", title=f'Предоплата — {h["name"]} — {BRAND}', desc=f'Внести предоплату за {h["name"]} через Систему быстрых платежей.',
                body=body, lds=[cr_ld], og=k + "/facade", noindex=True)

def relink(doc, up):
    """Страница во вложенной папке: относительные ссылки на файлы сайта получают префикс ../"""
    doc = re.sub(r'(\b(?:href|src)=")(?!https?:|#|tel:|mailto:|viber:|data:|/|\.\./)([^"]+")', lambda m: m.group(1) + up + m.group(2), doc)
    doc = re.sub(r'(\bsrcset=")([^"]+)"', lambda m: m.group(1) + re.sub(r'(^|,\s*)(?!https?:|/|\.\./)', lambda n: n.group(1) + up, m.group(2)) + '"', doc)
    return doc.replace('"prepay": "', '"prepay": "' + up)

# ---------- сборка ----------
# шрифты — со своего сервера (tools/fetch_fonts.py): IP посетителей не уходит в Google
FONTS = ('<link rel="preload" href="fonts/golos-text-cyrillic-1.woff2" as="font" type="font/woff2" crossorigin>'
         '<link rel="preload" href="fonts/prata-cyrillic-5.woff2" as="font" type="font/woff2" crossorigin>'
         '<link rel="stylesheet" href="fonts/fonts.css">')
# значки — файлами: data:-значок Яндекс для выдачи не берёт; favicon.ico в корне спрашивают все (tools/make_favicon.py)
FAV = ('<link rel="icon" href="favicon.ico" sizes="48x48"><link rel="icon" href="img/fox.svg" type="image/svg+xml">'
       '<link rel="icon" href="img/favicon-120.png" type="image/png" sizes="120x120"><link rel="apple-touch-icon" href="img/apple-touch-icon.png">')
# скидка только через ВКонтакте (решение владельца 22.10.2026) — в расчёте формы сайта её нет
HJS = {"_discount": 0} | {k: dict(name=v["name"], prices=dict(zip(["wd", "fri", "sat", "sun"], v["prices"])), max=v["max"], checkin=v["checkin"], checkout=v["checkout"], prepay=v["prepay"], base=v["base"], extra=v["extra"]) for k, v in H.items()}
RSS_LINK = f'<link rel="alternate" type="application/rss+xml" title="Блог — {BRAND}" href="rss.xml">'

def min_css(s):
    """Сжатие CSS без смены смысла: комментарии, переносы и пробелы у { } ; , > — вон; строки в кавычках не трогаем.
    Пробелы у «:», «+», «-», «(» остаются: «a :hover», calc(100% - 8px) и условия @media от них зависят."""
    parts = re.split(r'("(?:\\.|[^"\\])*"|\'(?:\\.|[^\'\\])*\')', s)
    out = []
    for i, x in enumerate(parts):
        if i % 2:
            out.append(x)
            continue
        x = re.sub(r"/\*.*?\*/", "", x, flags=re.S)
        x = re.sub(r"\s+", " ", x)
        x = re.sub(r"\s*([{};,>])\s*", r"\1", x)
        out.append(x.replace(";}", "}"))
    return "".join(out).strip() + "\n"

def main():
    global css, js, data_js
    css, js = rd("styles.css"), rd("app.js")
    sys.path.insert(0, os.path.join(ROOT, "tools")); import make_webp, make_favicon; make_webp.run(quiet=True); make_favicon.run(quiet=True)   # WebP-копии новых фото — до разметки: img() смотрит, какие копии есть
    data_js = "window.HOUSES = " + json.dumps(HJS, ensure_ascii=False) + ";"
    for d in (SITE_DIR + "/css", SITE_DIR + "/js", ART):
        os.makedirs(d, exist_ok=True)
    open(f"{SITE_DIR}/css/styles.css", "w", encoding="utf8", newline="\n").write(min_css(css))   # сжатый: меньше байт до первого экрана
    open(f"{SITE_DIR}/js/app.js", "w", encoding="utf8", newline="\n").write(js)
    open(f"{SITE_DIR}/js/cookies.js", "w", encoding="utf8", newline="\n").write(rd("cookies.js"))
    open(f"{SITE_DIR}/js/tinymce-bnprotect.js", "w", encoding="utf8", newline="\n").write(rd("tinymce-bnprotect.js"))   # для редактора в админке MODX
    open(f"{SITE_DIR}/img/fox.svg", "w", encoding="utf8", newline="\n").write(rd("fox.svg").replace("<path ", '<path fill="#D8650F" '))
    pages = [home()] + [house_page(k) for k in H] + [contacts_page(), blog_index()] + [post_page(p) for p in POSTS] + [prepay_page(k) for k in H] + [legal_page(s) for s in DOCS] + [notfound_page()]
    def og_image(pg):
        u = f'{SITE}/img/{pg["og"]}.jpg'
        wh = jpeg_size(f'{SITE_DIR}/img/{pg["og"]}.jpg')
        alt = esc(pg.get("og_alt", pg["title"]))
        size = f'<meta property="og:image:width" content="{wh[0]}"><meta property="og:image:height" content="{wh[1]}">' if wh else ""
        return (f'<meta property="og:image" content="{u}"><meta property="og:image:secure_url" content="{u}"><meta property="og:image:type" content="image/jpeg">{size}<meta property="og:image:alt" content="{alt}">'
                f'<meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="{u}"><link rel="image_src" href="{u}">')
    for pg in pages:
        fn, title, desc = pg["fn"], pg["title"], pg["desc"]
        url = f"{SITE}/" + (fn[:-len("index.html")] if fn.endswith("index.html") else fn)
        body = rd("fox.symbol.html") + "\n" + pg["body"]
        ld = "".join(f'<script type="application/ld+json">{json.dumps(x, ensure_ascii=False)}</script>' for x in pg["lds"])
        head = (f'<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
                f'<title>{esc(title)}</title><meta name="description" content="{esc(desc)}"><link rel="canonical" href="{url}">'
                f'<meta property="og:type" content="{pg.get("ogtype", "website")}"><meta property="og:locale" content="ru_RU"><meta property="og:site_name" content="{BRAND}"><meta property="og:title" content="{esc(title)}"><meta property="og:description" content="{esc(desc)}"><meta property="og:url" content="{url}">{og_image(pg)}{pg.get("extra", "")}{'<meta name="robots" content="noindex, nofollow">' if PREVIEW or pg.get("noindex") else ""}'
                f'<meta name="theme-color" content="#22140E">{FAV}{RSS_LINK}{FONTS}{ld}')
        doc = f'<!doctype html>\n<html lang="ru">\n<head>{head}<link rel="stylesheet" href="css/styles.css"></head>\n<body>\n{body}\n<script>{data_js}</script>\n<script src="js/app.js"></script>\n<script src="js/cookies.js" defer data-bn-cookies data-policy="cookie.html" data-version="{COOKIE_VERSION}" data-ym=""></script>\n</body>\n</html>\n'
        art = f'<!doctype html>\n<html lang="ru">\n<head>{head}<style>\n{css}\n</style></head>\n<body>\n{body}\n<script>{data_js}\n{js}</script>\n</body>\n</html>\n'
        depth = fn.count("/")
        if depth:
            doc, art = relink(doc, "../" * depth), relink(art, "../" * depth)
            for d in (SITE_DIR, ART): os.makedirs(os.path.join(d, os.path.dirname(fn)), exist_ok=True)
        open(f"{SITE_DIR}/{fn}", "w", encoding="utf8", newline="\n").write(doc)
        open(f"{ART}/{fn}", "w", encoding="utf8", newline="\n").write(art)

    # robots, sitemap, rss, llms.txt
    open(f"{SITE_DIR}/robots.txt", "w", encoding="utf8", newline="\n").write("User-agent: *\nDisallow: /\n" if PREVIEW else f"User-agent: *\nAllow: /\n\nSitemap: {SITE}/sitemap.xml\n")
    def sm(p):
        lm = f"<lastmod>{p['lastmod']}</lastmod>" if p.get("lastmod") else ""
        return f"<url><loc>{SITE}/{'' if p['fn'] == 'index.html' else p['fn']}</loc>{lm}<changefreq>monthly</changefreq></url>"
    urls = "".join(sm(p) for p in pages if not p.get("noindex"))
    open(f"{SITE_DIR}/sitemap.xml", "w", encoding="utf8", newline="\n").write(f'<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">{urls}</urlset>\n')
    def rfc822(iso):
        import datetime
        return datetime.datetime.strptime(iso, "%Y-%m-%d").strftime("%a, %d %b %Y 09:00:00 +0500")
    items = "".join(f"<item><title>{esc(p['h1'])}</title><link>{SITE}/{post_file(p)}</link><guid>{SITE}/{post_file(p)}</guid><pubDate>{rfc822(p['date'])}</pubDate><category>{CATS[p['cat']]}</category><description>{esc(p['desc'])}</description></item>" for p in POSTS)
    open(f"{SITE_DIR}/rss.xml", "w", encoding="utf8", newline="\n").write(f'<?xml version="1.0" encoding="UTF-8"?>\n<rss version="2.0"><channel><title>Блог — {BRAND}</title><link>{SITE}/blog.html</link><description>Статьи об отдыхе в домах с баней и бассейном в Перми</description><language>ru</language>{items}</channel></rss>\n')
    llms = [f"# {BRAND}", "", "> Аренда трёх домов с баней, бассейном и бильярдом в Перми посуточно. Бронирование: " + PHONE + ".", ""]
    for k, v in H.items():
        p = v["prices"]
        llms.append(f'- [{v["name"]}]({SITE}/{v["file"]}): {v["addr"]}, {v["area"]} м², ночуют {v["sleep"]}, отдыхают до {v["max"]}; заезд {v["checkin"]}, выезд {v["checkout"]}; сутки: Пн–Чт {p[0]} ₽, Пт {p[1]} ₽, Сб {p[2]} ₽, Вс {p[3]} ₽.')
    llms += ["", "Правила: залог 5 000–20 000 ₽, питомцы +1 000 ₽, курить в доме нельзя, тишина на участке с 22:00 до 9:00, после 22:00 музыка только в доме при закрытых окнах. Предоплата после подтверждения дат администратором.",
             "", f"- [Контакты]({SITE}/kontakty.html): телефон, ВКонтакте, адреса на карте, предоплата", "", "## Блог", ""]
    llms += [f'- [{p["h1"]}]({SITE}/{post_file(p)}): {p["desc"]}' for p in POSTS]
    open(f"{SITE_DIR}/llms.txt", "w", encoding="utf8", newline="\n").write("\n".join(llms) + "\n")
    print("ok:", ", ".join(p["fn"] for p in pages))

if __name__ == "__main__":
    main()
