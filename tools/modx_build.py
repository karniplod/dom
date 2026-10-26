"""
Собирает пакет для переноса сайта на MODX 3: папку modx/ и архив dist/bania-modx.zip для загрузки на хостинг.

Откуда берёт:
  build.py (с MARK = True) — та же разметка, что у статичного сайта, с метками <!--bn:имя-->…<!--/bn:имя-->
                             вокруг данных и повторов; метки превращаются в теги MODX: сниппеты, чанки, поля (TV)
  src/houses.py, blog.py, reviews.py — страницы, поля и настройки для установщика (resources.json, манифест)
  tools/modx/              — то, что пишется руками: установщик, библиотека Bania.php, страница «Заявки», сниппеты, INSTALL.md
Что делает:
  modx/                    — готовый пакет (руками не править — пересобирается)
  dist/bania-modx.zip      — пакет + папка site/assets/ с файлами сайта (стили, скрипт, картинки, QR-коды)

Запуск: python tools/modx_build.py   (поменяли сайт — запустите снова и загрузите новый архив; установщик обновит только изменённое)
"""
import datetime
import hashlib
import io
import json
import os
import re
import shutil
import sys
import zipfile
from urllib.parse import quote

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, "tools", "modx")
OUT = os.path.join(ROOT, "modx")
DIST = os.path.join(ROOT, "dist")
PKG = "bania-modx"
sys.path.insert(0, ROOT)
sys.path.insert(0, os.path.join(ROOT, "src"))
import build as B          # noqa: E402  функции разметки статичного сайта
B.MARK = True
H, POSTS, CATS, REVIEWS = B.H, B.POSTS, B.CATS, B.REVIEWS


def fail(msg):
    sys.exit("modx_build: " + msg)


def write(rel, text):
    path = os.path.join(OUT, rel)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    io.open(path, "w", encoding="utf-8", newline="\n").write(text.rstrip("\n") + "\n")


# ---------------------------------------------------------------- метки
MARK_RE = re.compile(r"<!--bn:([^>]+?)-->(.*?)<!--/bn:\1-->", re.S)


def unmark(html, name, repl):
    """Заменить все вхождения метки name: repl — строка или функция от содержимого"""
    pat = re.compile(r"<!--bn:%s-->(.*?)<!--/bn:%s-->" % (re.escape(name), re.escape(name)), re.S)
    if not pat.search(html):
        fail("нет метки «%s» — build.py изменился, поправьте tools/modx_build.py" % name)
    return pat.sub((lambda m: repl(m.group(1))) if callable(repl) else (lambda m: repl), html)


def item(html, kind, key):
    m = re.search(r"<!--bn:item:%s:%s-->(.*?)<!--/bn:item:%s:%s-->" % (kind, re.escape(key), kind, re.escape(key)), html, re.S)
    if not m:
        fail("нет образца item:%s:%s" % (kind, key))
    return m.group(1)


MENU_RE = re.compile(r"<!--bn:menu:(\w+)-->.*?<!--/bn:menu:\1-->", re.S)


def menu_snippets(html):
    """Меню шапки, выдвижного меню и подвала — сниппет bnMenu: новая страница с «Показывать в меню» появится сама;
    метка appLink — ссылка на приложение бронирования (bnAppLink, только если установлен компонент booking)"""
    html = html.replace("<!--bn:appLink--><!--/bn:appLink-->", "[[bnAppLink]]")
    return MENU_RE.sub(lambda m: "[[bnMenu? &place=`%s`]]" % m.group(1), html)


MAX_RE = re.compile(r"<!--bn:max-->(.*?)<!--/bn:max-->", re.S)


def max_cond(html):
    """Ссылка на MAX — только если в «Конфигурации» заполнено bn_max_url"""
    return MAX_RE.sub(lambda m: "[[++bn_max_url:notempty=`%s`]]" % m.group(1), html)


def strip_marks(html):
    """Убрать оставшиеся метки (содержимое остаётся). Метки значений v:* уже должны быть заменены."""
    html = menu_snippets(max_cond(html))
    left = sorted(set(n for n in re.findall(r"<!--bn:([^>]+?)-->", html) if n.startswith("v:")))
    if left:
        fail("не заменены метки значений: " + ", ".join(left))
    prev = None
    while prev != html:
        prev = html
        html = MARK_RE.sub(lambda m: m.group(2), html)
    return html


# ---------------------------------------------------------------- превращения разметки
SAMPLE = "sv"   # образец дома: значения этого дома меняются на теги MODX
BRAND = B.BRAND


def assets(s):
    """Файлы сайта в MODX лежат в assets/: img/… → /assets/img/…"""
    s = re.sub(r'((?:src|href)=")(?:\.\./)?img/', r"\1[[++base_url]]assets/img/", s)
    return re.sub(r'(srcset=")([^"]+)"', lambda m: m.group(1) + re.sub(r'(^|,\s*)(?:\.\./)?img/', r"\1[[++base_url]]assets/img/", m.group(2)) + '"', s)


def links(s):
    s = s.replace('href="index.html#order"', 'href="[[bnBookingUrl]]"')   # «Бронирование»: /booking или форма на главной
    s = s.replace('href="index.html#prices"', 'href="[[~[[++site_start]]]]#prices"').replace('href="index.html"', 'href="[[~[[++site_start]]]]"')
    s = s.replace('href="blog.html"', 'href="[[~[[++bn_blog_id]]]]"').replace('href="kontakty.html"', 'href="[[~[[++bn_contacts_id]]]]"')
    for doc in ("politika", "soglasie", "cookie"):   # документы 152-ФЗ
        s = s.replace('href="%s.html"' % doc, 'href="[[~[[++bn_%s_id]]]]"' % doc)
    for k, h in H.items():
        s = s.replace('href="%s"' % h["file"], 'href="[[~[[++bn_house_%s_id]]]]"' % k)
    return s


def settings(s):
    s = max_cond(s)
    pairs = [
        ("tel:" + B.PHONE_RAW, "tel:[[++bn_phone_raw]]"), (B.PHONE, "[[++bn_phone]]"),
        ("<b>vk.com/russianbath59</b>", "<b>[[++bn_vk_url:replace=`https://==`]]</b>"), (B.VK, "[[++bn_vk_url]]"),
        ("mailto:" + B.MAIL, "mailto:[[++bn_email]]"), (B.MAIL, "[[++bn_email]]"),
        ("ИП Ефремова Ольга Анатольевна", "[[++bn_legal_name]]"), ("590301252919", "[[++bn_inn]]"), ("325595800149589", "[[++bn_ogrnip]]"),
        ("Скидка %d%%" % B.DISCOUNT, "Скидка [[++bn_discount]]%"), ("скидка %d%%" % B.DISCOUNT, "скидка [[++bn_discount]]%"),
        ("−%d%%" % B.DISCOUNT, "−[[++bn_discount]]%"),
        ("предоплатой 50%", "предоплатой [[++bn_prepay_percent]]%"), ("предоплаты 50%", "предоплаты [[++bn_prepay_percent]]%"), ("это 50% от", "это [[++bn_prepay_percent]]% от"),
        ("5 000 – 20 000 ₽", "[[++bn_deposit]]"), (B.SERVICES, "[[++bn_services]]"),
        ("сутки от 15 000 ₽", "сутки от [[bnMinPrice]]"), ("сутки от 15 000 ₽", "сутки от [[bnMinPrice]]"),
        (BRAND, "[[++site_name]]"),
    ]
    for a, b in pairs:
        s = s.replace(a, b)
    return s


def common(s):
    return settings(links(assets(s)))


def house_values(k):
    """Значения дома k → ключ данных дома в MODX (Bania::house). Длинные строки — раньше, чтобы не задеть части."""
    h = H[k]
    vals = [
        (h["prepay"], "prepay_url"), (h["tour"], "tour"), (h["file"], "url"),
        ("img/%s/%s.jpg" % (k, h["card_photos"][1]), "photo2"), ("img/%s/%s.jpg" % (k, h["card_photos"][0]), "photo"), ("img/%s/hero.jpg" % k, "hero"),
        (quote("Пермь, " + h["street"]), "street_q"), (h["intro"], "intro"), (h["tourtxt"], "tour_text"),
        (h["name"].replace("Дом", "дом"), "name_lc"), (h["name"], "name"), (h["addr"], "addr"), (h["kicker"], "kicker"),
        (B.money(B.minp(h)), "min_price"), (re.search(r"(\d+) точ", h["tourtxt"]).group(1) + " точек обзора", "tour_points"), (h["view"], "view"),
    ]
    ctx = [  # числа — только в своём окружении
        ("Заезд с %s" % h["checkin"], "Заезд с {checkin}"), ("заезд с %s" % h["checkin"], "заезд с {checkin}"),
        ("выезд до %s" % h["checkout"], "выезд до {checkout}"), ("Ночуют %d," % h["sleep"], "Ночуют {sleep},"),
        ("до %d гостей" % h["max"], "до {max} гостей"), ("до %d человек" % h["base"], "до {base} человек"), ("гостя +%d ₽" % h["extra"], "гостя +{extra} ₽"),
        ('h-%s"' % k, 'h-{code}"'), ('h-%s ' % k, 'h-{code} '), ('data-house="%s"' % k, 'data-house="{code}"'), ('value="%s"' % k, 'value="{code}"'),
        ("img/qr/%s.svg" % k, "img/qr/{code}.svg"), ('data-lb="card-%s"' % k, 'data-lb="card-{code}"'), ('data-lb="g-%s"' % k, 'data-lb="g-{code}"'),
    ]
    return h, vals, ctx


def templatize(s, k, P):
    """Значения дома k → P(ключ): плейсхолдер [[+ключ]] в чанке или вызов сниппета на странице"""
    h, vals, ctx = house_values(k)
    # фото образца-дома в <picture>: WebP-копии — файлы этого дома, в общий шаблон не годятся; остаётся <img> (его src — из поля дома)
    # размеры <source> — в data-bn-sizes: <picture> с WebP фото этого дома соберёт Bania::picturize (плагин bnPicture)
    s = re.sub(r'<picture><source[^>]*img/%s/[^>]*\bsizes="([^"]*)"[^>]*><img' % re.escape(k), r'<img data-bn-sizes="\1"', s)
    s = re.sub(r'(<img data-bn-sizes="[^"]*"[^>]*>)</picture>', r"\1", s)
    s = re.sub(r'<picture><source[^>]*img/%s/[^>]*>(<img[^>]*>)</picture>' % re.escape(k), r"\1", s)
    for a, key in vals:
        s = s.replace(a, P(key))
    for a, b in ctx:
        s = s.replace(a, re.sub(r"\{(\w+)\}", lambda m: P(m.group(1)), b))
    for bad in (h["name"], h["addr"], "img/%s/" % k, h["file"]):
        if bad in s:
            fail("в шаблоне осталось значение дома %s: «%s»" % (k, bad))
    return s


PH = lambda key: "[[+%s]]" % key                                   # чанк-образец: плейсхолдеры от bnHouses
HP = lambda key: "[[bnHouse? &part=`%s`]]" % key                   # страница дома
PP = lambda key: "[[bnHouse? &id=`[[*parent]]` &part=`%s`]]" % key  # страница предоплаты: данные родителя-дома


def page_parts(body):
    """header + main + footer статичной страницы → (header, main, footer)"""
    m = re.match(r"(.*?)\n<main([^>]*)>\n?(.*)\n</main>\n(.*)$", body, re.S)
    if not m:
        fail("не разобрал страницу на шапку, main и подвал")
    return m.group(1), m.group(2), m.group(3), m.group(4)


def tpl(main_attrs, main, head_props=""):
    return ("<!doctype html>\n<html lang=\"ru\">\n<head>\n[[$bn.head%s]]\n</head>\n<body>\n[[$bn.fox]]\n[[$bn.header]]\n<main%s>\n%s\n</main>\n[[$bn.footer]]\n[[$bn.scripts]]\n</body>\n</html>\n"
            % (head_props, main_attrs, main.strip("\n")))


CHUNKS = {}      # имя → (категория, текст)
TEMPLATES = {}   # имя → (описание, текст)

# ---------------------------------------------------------------- общие куски
CHUNKS["bn.fox"] = ("Общие", io.open(os.path.join(ROOT, "src", "fox.symbol.html"), encoding="utf-8").read().strip())

hdr = B.header()
hdr = unmark(hdr, "navHouses", "[[bnHouses? &tpl=`bn.tpl.navHouse`]]")
CHUNKS["bn.header"] = ("Общие", common(strip_marks(hdr)))

frm = B.form("", "PFXQ")
frm = unmark(frm, "formOptions", "[[bnHouses? &tpl=`bn.tpl.formOption`]]")
frm = frm.replace('<form class="form order-form" id="PFXQ-form" novalidate>',
                  '<form class="form order-form" id="PFXQ-form" action="[[~[[++bn_lead_id]]]]" method="post" data-house="[[+house]]" novalidate>\n'
                  '  <input type="hidden" name="bn_token" value="[[!bnToken]]">')
if 'name="bn_token"' not in frm:
    fail("форма изменилась: не нашёл <form …>")
# прежняя форма сайта — запасная, если компонент бронирования не установлен; основная — booking.form (решение владельца 22.10.2026)
CHUNKS["bn.form.legacy"] = ("Общие", common(strip_marks(frm.replace("PFXQ", "[[+p]]"))))
CHUNKS["bn.form"] = ("Общие", "[[!bnOrderForm? &p=`[[+p]]` &house=`[[+house]]`]]")

modal = B.order_modal("")
modal = modal.replace(B.form(""), "[[$bn.form? &p=`f` &house=`[[*bn_code]]`]]")
CHUNKS["bn.orderModal"] = ("Общие", common(strip_marks(modal)))

drw = unmark(B.drawer(), "drawerHouses", "[[bnHouses? &tpl=`bn.tpl.drawerHouse`]]")
CHUNKS["bn.drawer"] = ("Общие", common(strip_marks(drw)))

ftr = B.footer("")
ftr = ftr.replace(B.order_modal(""), "[[$bn.orderModal]]").replace(B.drawer(), "[[$bn.drawer]]")
ftr = unmark(ftr, "footHouses", "[[bnHouses? &tpl=`bn.tpl.footHouse`]]")
if "[[$bn.orderModal]]" not in ftr or "[[$bn.drawer]]" not in ftr:
    fail("подвал изменился: не нашёл окно заявки или меню")
CHUNKS["bn.footer"] = ("Общие", common(strip_marks(ftr)))

band = B.order_band().replace(B.form("", "o"), "[[$bn.form? &p=`o`]]")
CHUNKS["bn.orderBand"] = ("Общие", common(strip_marks(band)))
k0 = SAMPLE
bandh = B.order_band(k0, k0 + "/" + H[k0]["card_photos"][1], "Заявка: " + H[k0]["name"], H[k0]["name"]).replace(B.form(k0, "o"), "[[$bn.form? &p=`o` &house=`[[*bn_code]]`]]")
CHUNKS["bn.orderBandHouse"] = ("Общие", common(templatize(strip_marks(bandh), k0, HP)))

VER = hashlib.md5((B.rd("styles.css") + B.rd("app.js")).encode("utf-8")).hexdigest()[:8]
CHUNKS["bn.head"] = ("Общие", """<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>[[*longtitle:default=`[[*pagetitle]] — [[++site_name]]`:htmlent]]</title>
<meta name="description" content="[[*description:htmlent]]">
<link rel="canonical" href="[[~[[*id]]? &scheme=`full`]]">
<meta property="og:type" content="[[+ogtype:default=`website`]]"><meta property="og:locale" content="ru_RU"><meta property="og:site_name" content="[[++site_name:htmlent]]">
<meta property="og:title" content="[[*longtitle:default=`[[*pagetitle]]`:htmlent]]"><meta property="og:description" content="[[*description:htmlent]]"><meta property="og:url" content="[[~[[*id]]? &scheme=`full`]]">
[[bnOg? &tv=`[[+ogtv:default=`bn_hero`]]`]]
[[+noindex:notempty=`<meta name="robots" content="noindex, nofollow">`]]
[[*id:is=`[[++error_page]]`:then=`<meta name="robots" content="noindex, nofollow">`:else=``]]
[[++bn_yandex_verification:notempty=`<meta name="yandex-verification" content="[[++bn_yandex_verification]]">`]]
<meta name="theme-color" content="#22140E"><link rel="icon" href="[[++base_url]]favicon.ico" sizes="48x48"><link rel="icon" href="[[++base_url]]assets/img/fox.svg" type="image/svg+xml"><link rel="icon" href="[[++base_url]]assets/img/favicon-120.png" type="image/png" sizes="120x120"><link rel="apple-touch-icon" href="[[++base_url]]assets/img/apple-touch-icon.png">
<link rel="alternate" type="application/rss+xml" title="Блог — [[++site_name:htmlent]]" href="[[~[[++bn_rss_id]]]]">
""" + B.FONTS.replace('href="fonts/', 'href="[[++base_url]]assets/fonts/') + """
<link rel="stylesheet" href="[[++base_url]]assets/css/styles.css?v=""" + VER + """">
[[bnLd]]""")
# Яндекс Метрика — только после согласия на cookie (152-ФЗ): счётчик подключает cookies.js, номер — из «Конфигурации»
CHUNKS["bn.scripts"] = ("Общие", """[[bnHousesJs]]
<script src="[[++base_url]]assets/js/app.js?v=""" + VER + """"></script>
<script src="[[++base_url]]assets/js/cookies.js?v=""" + VER + """" defer data-bn-cookies data-policy="[[~[[++bn_cookie_id]]]]" data-version=\"""" + B.COOKIE_VERSION + """" data-ym="[[++bn_ym_id]]"></script>""")

# ---------------------------------------------------------------- образцы для чанков-списков (дом k0, статья, отзыв)
home = B.home()["body"]
contacts = B.contacts_page()["body"]
blogp = B.blog_index()["body"]
SAMPLE_REVIEW = dict(name="⟦name⟧", house=k0, date="2026-01-01", rating=5, source="⟦src⟧", text="⟦text⟧")
rev = B.review_card_(SAMPLE_REVIEW)
rev = rev.replace("★★★★★<span aria-hidden=\"true\"></span>", "[[+stars_on]]<span aria-hidden=\"true\">[[+stars_off]]</span>").replace('Оценка 5 из 5', 'Оценка [[+stars]] из 5')
rev = rev.replace("<p>⟦text⟧</p>", "[[+text]]").replace("<b>⟦name⟧</b>", "<b>[[+name]]</b>")
rev = re.sub(r"<span>%s · [^<]*</span>" % re.escape(H[k0]["name"]), "[[+meta]]", rev)
rev = rev.replace('<span class="rev__src">⟦src⟧</span>', "[[+source]]")
if "⟦" in rev or H[k0]["name"] in rev:
    fail("отзыв-образец разобран не до конца")
CHUNKS["bn.tpl.review"] = ("Шаблоны списков", rev)

for kind, src_html in (("navHouse", hdr), ("drawerHouse", B.drawer()), ("formOption", B.form("")), ("footHouse", B.footer("")),
                       ("houseCard", home), ("tourTile", home), ("addrCard", home), ("contactPlace", contacts), ("payItem", contacts)):
    frag = item(src_html if kind != "navHouse" else B.header(), kind, k0)
    if kind == "houseCard":
        frag = unmark(frag, "media", "[[+media]]")
        frag = unmark(frag, "features", "[[+features]]")
    if kind == "formOption":
        frag = frag.replace(' selected', '[[+selected]]')
    CHUNKS["bn.tpl." + kind] = ("Шаблоны списков", common(templatize(strip_marks(frag), k0, PH)))

p0 = POSTS[1]
pc = item(blogp, "postCard", p0["slug"])
pc = pc.replace('<article class="pcard"', '<article class="pcard[[+big]]"').replace('data-cat="%s"' % p0["cat"], 'data-cat="[[+cat]]"')
pc = pc.replace('href="%s"' % B.post_file(p0), 'href="[[+url]]"').replace('src="img/%s.jpg"' % p0["cover"], 'src="[[+cover]]"').replace('alt="%s"' % p0["cover_alt"], 'alt="[[+cover_alt]]"')
pc = pc.replace("<span>%s</span>" % CATS[p0["cat"]], "<span>[[+cat_name]]</span>").replace("<span>%d мин чтения</span>" % B.read_min(p0), "<span>[[+min]] мин чтения</span>")
pc = pc.replace(">%s</a></h3>" % p0["h1"], ">[[+title]]</a></h3>").replace("<p>%s</p>" % p0["desc"], "<p>[[+text]]</p>")
for bad in (p0["h1"], p0["desc"], B.post_file(p0)):
    if bad in pc:
        fail("карточка статьи разобрана не до конца")
CHUNKS["bn.tpl.postCard"] = ("Шаблоны списков", strip_marks(pc))

# ---------------------------------------------------------------- главная
_, mattrs, main, _ = page_parts(home)
main = unmark(main, "heroImg", '[[bnImg? &src=`[[*bn_hero]]` &alt=`[[*bn_hero_alt]]` &cls=`hero__img` &lazy=`0` &sizes=`100vw`]]')   # WebP и высокий приоритет, как на статичном
main = unmark(main, "houseCards", "[[bnHouses? &tpl=`bn.tpl.houseCard`]]")
main = unmark(main, "tourTiles", "[[bnHouses? &tpl=`bn.tpl.tourTile`]]")
main = unmark(main, "priceTable", "[[bnPriceTable]]")
main = unmark(main, "strip", "[[bnStrip]]")
main = unmark(main, "rules", "[[bnRules]]")
main = unmark(main, "faq", "[[bnFaq]]")
seo_html = re.search(r"<!--bn:seo-->(.*?)<!--/bn:seo-->", main, re.S).group(1)
main = unmark(main, "seo", "\n[[*content]]\n  ")
main = unmark(main, "reviews", "[[bnReviews]]")
main = unmark(main, "posts3", "[[bnPosts? &limit=`3`]]")
main = unmark(main, "orderBand", "[[$bn.orderBand]]")
main = unmark(main, "addrCards", "[[bnHouses? &tpl=`bn.tpl.addrCard`]]")
main = common(strip_marks(main))
# каждый блок — свой чанк «Главная», в шаблоне — их список
blocks, order = [], []
for part in re.split(r"\n\n+", main.strip("\n")):
    part = part.strip("\n")
    if not part:
        continue
    m = re.match(r'<section class="([\w -]+)"(?: id="([\w-]+)")?(?: aria-labelledby="([\w-]+)")?', part)
    if not m:
        order.append(part)            # вызов чанка или сниппета
        continue
    name = m.group(2) or (m.group(3) or m.group(1).split()[0]).replace("-title", "")
    cname = "bn.home." + name
    if cname in CHUNKS:
        fail("два блока главной с именем " + name)
    CHUNKS[cname] = ("Главная", part)
    order.append("[[$%s]]" % cname)
TEMPLATES["bn-home"] = ("Главная: дома, комфорт, праздники, цены, фото, правила, отзывы, блог, заявка, адреса", tpl("", "\n\n".join(order)))

# ---------------------------------------------------------------- страница дома
hb = B.house_page(k0)["body"]
_, mattrs, hm, _ = page_parts(hb)
for name, repl in (("crumbs", "[[bnCrumbs]]"), ("houseHero", HP("hero_img")), ("v:intro", "[[*bn_intro]]"), ("facts", HP("facts")), ("gallery", HP("gallery")),
                   ("v:tour_text", "[[*bn_tour_text]]"), ("tour2", HP("tour2")), ("comfort", HP("comfort")), ("prices", HP("prices")), ("houseRules", HP("rules")), ("hinfo", HP("hinfo")),
                   ("houseSeo", "[[*bn_seo]]"), ("houseFaq", HP("faq")), ("houseReviews", HP("reviews")), ("houseOrder", "[[$bn.orderBandHouse]]"), ("otherHouses", HP("others"))):
    hm = unmark(hm, name, repl)
hm = hm.replace("<h1 id=\"d-title\">%s</h1>" % H[k0]["h1"], "<h1 id=\"d-title\">[[*pagetitle]]</h1>")
TEMPLATES["bn-house"] = ("Дом: фото, удобства, цены, предоплата, правила, вопросы, отзывы. Данные — во вкладке «Дополнительные поля»",
                         tpl(' class="h-[[*bn_code]]"', common(templatize(strip_marks(hm), k0, HP))))

# ---------------------------------------------------------------- контакты, блог, статья, предоплата
_, _, cm, _ = page_parts(contacts)
cm = unmark(cm, "crumbs", "[[bnCrumbs]]")
cm = unmark(cm, "contactPlaces", "[[bnHouses? &tpl=`bn.tpl.contactPlace`]]")
cm = unmark(cm, "payItems", "[[bnHouses? &tpl=`bn.tpl.payItem`]]")
CHUNKS["bn.page.contacts"] = ("Страницы", common(strip_marks(cm)))
TEMPLATES["bn-contacts"] = ("Контакты: телефоны, адреса домов, предоплата, реквизиты (тексты — чанк bn.page.contacts)", tpl("", "[[$bn.page.contacts]]"))

_, _, bm, _ = page_parts(blogp)
bm = unmark(bm, "crumbs", "[[bnCrumbs]]")
bm = unmark(bm, "blogChips", "[[bnBlogChips]]")
bm = unmark(bm, "blogCards", "[[bnPosts? &big=`1`]]")
bm = bm.replace(B.order_band(), "[[$bn.orderBand]]")
bm = bm.replace("<h1>Блог</h1>", "<h1>[[*pagetitle]]</h1>")
TEMPLATES["bn-blog"] = ("Блог: рубрики и карточки статей (статьи — дочерние страницы)", tpl("", common(strip_marks(bm))))

pp = B.post_page(POSTS[0])["body"]
_, _, pm, _ = page_parts(pp)
for name, repl in (("crumbs", "[[bnCrumbs]]"), ("postMeta", "[[bnPost? &part=`meta`]]"), ("v:post_title", "[[*pagetitle]]"), ("v:post_lead", "[[*introtext]]"),
                   ("postCover", "[[bnPost? &part=`cover`]]"), ("toc", "[[bnPost? &part=`toc`]]"), ("postBody", "[[bnPost? &part=`body`]]"),
                   ("v:share_url", "[[bnPost? &part=`share_url`]]"),
                   ("postHouses", "[[bnPost? &part=`houses`]]"), ("postOthers", "[[bnPosts? &limit=`3` &exclude=`[[*id]]`]]")):
    pm = unmark(pm, name, repl)
pm = common(strip_marks(pm)).replace('href="[[~[[++bn_blog_id]]]]"', 'href="[[~[[*parent]]]]"')
TEMPLATES["bn-post"] = ("Статья блога: текст — в поле «Содержимое» (разделы с заголовками h2), обложка и рубрика — в полях", tpl("", pm, "? &ogtype=`article` &ogtv=`bn_cover`"))

prb = B.prepay_page(k0)["body"]
_, mattrs, prm, _ = page_parts(prb)
prm = unmark(prm, "crumbs", "[[bnCrumbs]]")
prm = prm.replace(H[k0]["sbp"].replace("&", "&amp;"), PP("sbp")).replace(H[k0]["sbp"], PP("sbp"))
TEMPLATES["bn-prepay"] = ("Предоплата за дом: страница — дочерняя у дома, ссылка СБП и QR — из полей дома. Не в меню и не в поиске",
                          tpl(' class="h-[[bnHouse? &id=`[[*parent]]` &part=`code`]]"', common(templatize(strip_marks(prm), k0, PP)), "? &noindex=`1`"))

TEMPLATES["bn-text"] = ("Текстовая страница (например, политика обработки персональных данных): заголовок и текст из «Содержимого»", tpl("", """<section class="phead on-light"><div class="wrap">
  [[bnCrumbs]]
  <h1>[[*pagetitle]]</h1>
</div></section>
<section class="band band--tight on-light"><div class="wrap"><div class="prose">
[[*content]]
</div></div></section>"""))
TEMPLATES["bn-section"] = ("Служебный раздел (например, «Отзывы»): на сайте не открывается — перенаправляет на главную", "[[bnGoHome]]")
TEMPLATES["bn-review"] = ("Отзыв гостя: заголовок — имя, «Содержимое» — текст отзыва, остальное — в полях", "[[bnGoHome]]")

# проверка: не осталось ли данных статичного сайта
for name, (_, text) in list(CHUNKS.items()) + [(n, (None, t)) for n, (_, t) in TEMPLATES.items()]:
    for bad in ('src="img/', 'href="img/', ".html\"", "<!--bn:"):
        if bad in text and not (bad == '.html"' and "ld+json" in text):
            fail("в %s осталось «%s»" % (name, bad))

# ---------------------------------------------------------------- поля (TV)
HOUSE_TVS = [
    ("bn_code", "Код дома", "text", "Латиницей, как в стилях: en — Английский, ru — Русский, sv — Свиязева. От кода зависят цвета и папка фото assets/img/КОД/", ""),
    ("bn_kicker", "Подзаголовок", "text", "Строка над названием: «Баня, бассейн и камин на Беляева»", ""),
    ("bn_intro", "Вступление", "textarea", "Абзац под названием на первом экране страницы дома", ""),
    ("bn_hero", "Фото первого экрана", "image", "Большое фото вверху страницы дома", ""),
    ("bn_hero_alt", "Что на фото первого экрана", "text", "Описание фото для поисковиков и незрячих: «Кухня-гостиная с большим столом»", ""),
    ("bn_addr", "Адрес", "text", "Как показывать: «ул. Космонавта Беляева, 111»", ""),
    ("bn_street", "Адрес для карт", "text", "Полностью, для ссылок на Яндекс Карты и 2ГИС: «улица Космонавта Беляева, 111»", ""),
    ("bn_area", "Площадь, м²", "number", "Только число", ""),
    ("bn_floors", "Подпись к площади", "text", "Например «Дом, 2 этажа»", "Дом, 2 этажа"),
    ("bn_rooms", "Комнат", "number", "Только число", ""),
    ("bn_plot", "Участок", "text", "Например «6 соток»", ""),
    ("bn_sleep", "Ночуют", "number", "Сколько гостей ночует (спальных мест). Если установлено «Бронирование», на сайте берётся оттуда: «Пакеты → Бронирование → Дома и бани» — меняйте там", ""),
    ("bn_max", "Отдыхают до", "number", "Сколько гостей можно позвать на праздник. Если установлено «Бронирование», на сайте берётся оттуда: «Пакеты → Бронирование → Дома и бани» — меняйте там", ""),
    ("bn_base", "Гостей в цене", "number", "На сколько человек рассчитана цена за сутки. Если установлено «Бронирование», на сайте берётся оттуда: «Пакеты → Бронирование → Дома и бани» — меняйте там", ""),
    ("bn_extra", "Доплата за гостя сверх, ₽", "number", "За каждого гостя сверх «Гостей в цене», за всё время отдыха. Если установлено «Бронирование», на сайте берётся оттуда: «Пакеты → Бронирование → Дома и бани» — меняйте там", "500"),
    ("bn_checkin", "Заезд с", "text", "Например «15:00». Если установлено «Бронирование», на сайте берётся оттуда: «Пакеты → Бронирование → Дома и бани» — меняйте там", ""),
    ("bn_checkout", "Выезд до", "text", "Например «12:00». Если установлено «Бронирование», на сайте берётся оттуда: «Пакеты → Бронирование → Дома и бани» — меняйте там", ""),
    ("bn_view", "Посмотреть Дом", "text", "Когда можно приехать посмотреть: «с 12:00 до 15:00»", ""),
    ("bn_pool", "Бассейн", "text", "Размеры и температура: «3 × 2,5 м, глубина 1,5 м, вода 28–30°». Пусто — «бассейн-джакузи»", ""),
    ("bn_jacuzzi", "Бассейн-джакузи", "text", "Если поле «Бассейн» пустое — блок «Бассейн-джакузи»: размеры, например «2,5 × 2 м, глубина 1 м»", ""),
    ("bn_price_wd", "Цена Пн–Чт, ₽", "number", "Сутки с заездом с понедельника по четверг. Если установлено «Бронирование», на сайте берётся оттуда: «Пакеты → Бронирование → Цены» — меняйте там", ""),
    ("bn_price_fri", "Цена Пт, ₽", "number", "Сутки с заездом в пятницу (и в предпраздничный рабочий день). Если установлено «Бронирование», на сайте берётся оттуда: «Пакеты → Бронирование → Цены» — меняйте там", ""),
    ("bn_price_sat", "Цена Сб, ₽", "number", "Сутки с заездом в субботу (и в праздник перед выходным). Если установлено «Бронирование», на сайте берётся оттуда: «Пакеты → Бронирование → Цены» — меняйте там", ""),
    ("bn_price_sun", "Цена Вс, ₽", "number", "Сутки с заездом в воскресенье (и в праздник перед рабочим днём). Если установлено «Бронирование», на сайте берётся оттуда: «Пакеты → Бронирование → Цены» — меняйте там", ""),
    ("bn_tour", "3D-тур", "text", "Ссылка на 3D-тур: vtour/<папка>/tour.html (туры в assets/vtour/) или полный адрес", ""),
    ("bn_tour2", "Второй 3D-тур", "text", "Необязательно: ещё один тур Дома — vtour/<папка>/tour.html", ""),
    ("bn_tour2_text", "Что во втором 3D-туре", "text", "Подсказка к кнопке «Второй 3D-тур»", ""),
    ("bn_tour_text", "Что в 3D-туре", "textarea", "«14 точек обзора: прихожая, кухня-гостиная…» — число точек выводится на плитке тура", ""),
    ("bn_sbp", "Ссылка на оплату СБП", "text", "Ссылка qr.nspk.ru/… для страницы предоплаты. QR-код — файл assets/img/qr/КОД.svg", ""),
    ("bn_gallery", "Галерея", "textarea", "По фото в строке: «файл | подпись». Кнопка «Загрузить фото» под полем — сразу пачкой: уменьшит, сделает WebP и допишет строки. Первые три фото — в карточке дома", ""),
    ("bn_features", "Пункты в карточке", "textarea", "По пункту в строке, лучше 4", ""),
    ("bn_table_extra", "Для отдыха (в таблице цен)", "text", "Коротко: «Баня, бассейн, бильярд, камин»", ""),
    ("bn_comfort", "Удобства", "textarea", "По удобству в строке: «значок | заголовок | пояснение». Значки: kitchen, steam, cue, fire, bed, snow, tv, car, chair, floor", ""),
    ("bn_seo", "Текст о доме", "richtext", "Абзацы под заголовком «… в Перми посуточно»", ""),
]
SHARED_TVS = [("bn_faq", "Частые вопросы", "textarea", "По вопросу в строке: «Вопрос | Ответ». Выводятся в блоке вопросов и в разметке для поисковиков", "")]
HOME_TVS = [
    ("bn_hero_alt", None, None, None, None),   # то же поле, что у дома
    ("bn_rules", "Правила домов", "textarea", "По правилу в строке: «Название | значение». Строка «Гости сверх …» на странице дома заменяется его условиями", ""),
    ("bn_strip", "Лента фото «Посмотрите дома изнутри»", "textarea", "По фото в строке: «assets/img/ru/sauna.jpg | подпись». Кнопка «Загрузить фото» под полем — пачкой, в папку выбранного Дома", ""),
]
HOUSE_SQL = "SELECT pagetitle AS name, id FROM [[+PREFIX]]site_content WHERE template = (SELECT id FROM [[+PREFIX]]site_templates WHERE templatename = 'bn-house') AND deleted = 0"
HOUSE_LIST = "@" + HOUSE_SQL
POST_TVS = [
    ("bn_post_cat", "Рубрика", "listbox", "Рубрики и их адреса — системная настройка bn_blog_cats", "prazdnik", "||".join("%s==%s" % (v, k) for k, v in CATS.items())),
    ("bn_cover", "Обложка", "image", "Фото вверху статьи и в карточке", "", ""),
    ("bn_cover_alt", "Подпись к обложке", "text", "Что на фото", "", ""),
    ("bn_post_houses", "Дома из статьи", "checkbox", "Карточки этих домов — под статьёй", "", HOUSE_LIST),
]
REVIEW_TVS = [
    ("bn_rev_house", "Дом", "listbox", "О каком доме отзыв (пусто — без привязки к дому)", "0", "@SELECT 'Без привязки к дому' AS name, 0 AS id UNION ALL " + HOUSE_SQL),
    ("bn_rev_rating", "Оценка", "listbox", "Сколько звёзд поставил гость", "5", "5||4||3||2||1"),
    ("bn_rev_source", "Где оставлен", "listbox", "Источник отзыва — показывается под отзывом", "Яндекс Карты", "Яндекс Карты||2ГИС||Авито||ВКонтакте||Лично"),
    ("bn_rev_url", "Ссылка на отзыв", "text", "Необязательно: ссылка на отзыв на картах", "", ""),
    ("bn_rev_date", "Дата отзыва", "date", "", "", ""),
]
TVS = []
for name, cap, typ, desc, default in HOUSE_TVS:
    TVS.append({"name": name, "caption": cap, "type": typ, "description": desc, "default": default, "elements": "", "templates": ["bn-house"] + (["bn-home"] if name in ("bn_hero", "bn_hero_alt") else []), "category": "Дом"})
for name, cap, typ, desc, default in SHARED_TVS:
    TVS.append({"name": name, "caption": cap, "type": typ, "description": desc, "default": default, "elements": "", "templates": ["bn-house", "bn-home"], "category": "Общие поля"})
for name, cap, typ, desc, default in HOME_TVS:
    if cap:
        TVS.append({"name": name, "caption": cap, "type": typ, "description": desc, "default": default, "elements": "", "templates": ["bn-home"], "category": "Главная"})
for name, cap, typ, desc, default, el in POST_TVS:
    TVS.append({"name": name, "caption": cap, "type": typ, "description": desc, "default": default, "elements": el, "templates": ["bn-post"], "category": "Статья"})
for name, cap, typ, desc, default, el in REVIEW_TVS:
    TVS.append({"name": name, "caption": cap, "type": typ, "description": desc, "default": default, "elements": el, "templates": ["bn-review"], "category": "Отзыв"})

# ---------------------------------------------------------------- настройки
SETTINGS = [
    {"key": "bn_phone", "value": B.PHONE, "name": "Телефон бронирования", "cc": "Контакты", "help": "Как показывать: +7 (342) 277-66-22"},
    {"key": "bn_phone_raw", "value": B.PHONE_RAW, "name": "Телефон для ссылки", "cc": "Контакты", "help": "Только + и цифры: +73422776622"},
    {"key": "bn_max_url", "value": B.MAX, "name": "MAX: ссылка на чат", "cc": "Контакты", "help": "Полная ссылка https://max.ru/…; пусто — ссылки MAX на сайте нет"},
    {"key": "bn_vk_url", "value": B.VK, "name": "Группа ВКонтакте", "cc": "Контакты", "help": "Полная ссылка https://vk.com/…"},
    {"key": "bn_email", "value": B.MAIL, "name": "Почта на сайте", "cc": "Контакты", "help": "Показывается в подвале и на странице контактов"},
    {"key": "bn_lead_email", "value": B.MAIL, "name": "Почта для заявок", "cc": "Контакты", "help": "Сюда приходят заявки с сайта. Несколько адресов — через запятую"},
    {"key": "bn_legal_name", "value": "ИП Ефремова Ольга Анатольевна", "name": "Исполнитель", "cc": "Реквизиты", "help": "Как в подвале: «ИП …»"},
    {"key": "bn_inn", "value": "590301252919", "name": "ИНН", "cc": "Реквизиты", "help": "Только цифры"},
    {"key": "bn_ogrnip", "value": "325595800149589", "name": "ОГРНИП", "cc": "Реквизиты", "help": "Только цифры"},
    {"key": "bn_discount", "value": str(B.DISCOUNT), "name": "Скидка при бронировании через ВКонтакте, %", "cc": "Условия", "help": "Только число. 0 — скидки нет (плашки «−5%» исчезнут)"},
    {"key": "bn_prepay_percent", "value": "50", "name": "Предоплата, %", "cc": "Условия", "help": "Только число"},
    {"key": "bn_deposit", "value": "5 000 – 20 000 ₽", "name": "Залог", "cc": "Условия", "help": "Как показывать: «5 000 – 20 000 ₽»"},
    {"key": "bn_services", "value": B.SERVICES, "name": "Повар", "cc": "Условия", "help": "Предложение про шашлык и плов (на главной и на страницах домов)"},
    {"key": "bn_review_yandex", "value": "", "name": "Отзывы: ссылка на Яндекс Карты", "cc": "Отзывы", "help": "Куда отправлять гостей оставить отзыв. Пусто — кнопки нет"},
    {"key": "bn_review_2gis", "value": "", "name": "Отзывы: ссылка на 2ГИС", "cc": "Отзывы", "help": "Пусто — кнопки нет"},
    {"key": "bn_ym_id", "value": "", "name": "Номер счётчика Яндекс Метрики", "cc": "Аналитика", "help": "Только цифры. Пусто — Метрика не подключается. Цель «lead» — отправленная заявка"},
    {"key": "bn_yandex_verification", "value": "", "name": "Код подтверждения Яндекс Вебмастера", "cc": "Аналитика", "help": "Только код из метатега (content=\"…\")"},
    {"key": "bn_tg_token", "value": "", "name": "Telegram: токен бота", "cc": "", "help": "Заявки в Telegram: токен от @BotFather. Пусто — не отправлять"},
    {"key": "bn_tg_chat", "value": "", "name": "Telegram: чат", "cc": "", "help": "ID чата или канала, куда бот пишет заявки"},
    {"key": "bn_email_from", "value": "", "name": "Отправитель писем", "cc": "", "help": "Пусто — noreply@ваш-домен"},
    {"key": "bn_blog_cats", "value": "\n".join("%s | %s" % kv for kv in CATS.items()), "name": "Рубрики блога", "cc": "", "help": "По рубрике в строке: «адрес | название»"},
    {"key": "bn_og_default", "value": "assets/img/ru/hero-2.jpg", "name": "Картинка для превью ссылок", "cc": "", "help": "Если у страницы нет своей"},
]
from reviews import HOUSE_REVIEW_LINKS   # noqa: E402  ссылки на площадки отзывов по Домам (их читает Bania::reviewPanels)
for k, h in H.items():
    for plat, key in (("2ГИС", "2gis"), ("Яндекс Карты", "yandex"), ("Авито", "avito")):
        SETTINGS.append({"key": "bn_rev_%s_%s" % (k, key), "value": HOUSE_REVIEW_LINKS.get(k, {}).get(plat, ""), "name": "%s: %s" % (h["name"], plat),
                         "cc": "Отзывы", "help": "Ссылка на карточку Дома с отзывами. Пусто — логотип без ссылки"})
ID_SETTINGS = {"bn_politika_id": "politika", "bn_soglasie_id": "soglasie", "bn_cookie_id": "cookie", "bn_blog_id": "blog", "bn_contacts_id": "kontakty", "bn_lead_id": "api-zayavka", "bn_reviews_id": "otzyvy", "bn_rss_id": "rss"}
for k, h in H.items():
    ID_SETTINGS["bn_house_%s_id" % k] = h["file"][:-5]
IDS_OWN = list(ID_SETTINGS)   # свои настройки «ID страницы»; error_page — системная настройка MODX, её только заполняем
ID_SETTINGS["error_page"] = "404"
for key in IDS_OWN:
    SETTINGS.append({"key": key, "value": "", "name": "ID страницы", "cc": "", "help": "Заполняет установщик"})

# ---------------------------------------------------------------- страницы
def lines(rows):
    return "\n".join(" | ".join(str(c) for c in r) for r in rows)


RES = [{
    "alias": "index", "parent": "", "home": True, "template": "bn-home", "richtext": 1,
    "fields": {"pagetitle": "Главная", "longtitle": B.home()["title"], "description": B.home()["desc"], "menutitle": "Главная",
               "content": settings(strip_marks(seo_html)).strip()},
    "tvs": {"bn_hero": "assets/img/ru/hero-2.jpg", "bn_hero_alt": "Русский Дом: фасад и беседка во дворе",
            "bn_faq": settings(lines(B.HOME_FAQ)), "bn_rules": lines([B.GUESTS_RULE] + B.RULES),
            "bn_strip": lines([("assets/img/%s.jpg" % p, c) for p, c in re.findall(r'<a href="img/([\w/-]+)\.jpg" data-lb="strip" data-cap="([^"]*)"', B.home()["body"])])},
}]
for i, (k, h) in enumerate(H.items()):
    RES.append({
        "alias": h["file"][:-5], "parent": "", "template": "bn-house", "isfolder": 1, "richtext": 0,
        "fields": {"pagetitle": h["name"], "longtitle": h["title"], "description": h["desc"], "menutitle": h["name"]},
        "tvs": {"bn_code": k, "bn_kicker": h["kicker"], "bn_intro": h["intro"], "bn_hero": "assets/img/%s/hero.jpg" % k,
                "bn_hero_alt": dict(h["gallery"]).get("hero", "фото"), "bn_addr": h["addr"], "bn_street": h["street"], "bn_area": h["area"], "bn_floors": "Дом, 2 этажа",
                "bn_rooms": h["rooms"], "bn_plot": h["plot"], "bn_sleep": h["sleep"], "bn_max": h["max"], "bn_base": h["base"], "bn_extra": h["extra"],
                "bn_checkin": h["checkin"], "bn_checkout": h["checkout"], "bn_view": h["view"], "bn_pool": h["pool"],
                "bn_price_wd": h["prices"][0], "bn_price_fri": h["prices"][1], "bn_price_sat": h["prices"][2], "bn_price_sun": h["prices"][3],
                "bn_tour": h["tour"], "bn_tour_text": h["tourtxt"], "bn_tour2": h.get("tour2", ""), "bn_jacuzzi": h.get("jacuzzi", ""), "bn_tour2_text": h.get("tour2txt", ""), "bn_sbp": h["sbp"],
                "bn_gallery": lines([("%s.jpg" % p, c) for p, c in h["gallery"]]), "bn_features": "\n".join(h["features"]), "bn_table_extra": h["table_extra"],
                "bn_comfort": lines(h["comfort"]), "bn_seo": "".join("<p>%s</p>" % t for t in h["seo"]), "bn_faq": lines(h["faq"])},
    })
    num = h["prepay"].strip("/")   # «Предоплата1» — адрес как на старом сайте
    pg = B.prepay_page(k)
    RES.append({"alias": "predoplata-" + num[-1], "uri": num + "/", "parent": h["file"][:-5], "template": "bn-prepay", "hidemenu": 1, "searchable": 0,
                "fields": {"pagetitle": "Предоплата: " + h["name"], "longtitle": pg["title"], "description": pg["desc"]}, "tvs": {}})
for slug, d in B.DOCS.items():   # документы 152-ФЗ — текстовые страницы
    RES.append({"alias": slug, "parent": "", "template": "bn-text", "hidemenu": 1, "richtext": 0,
                "fields": {"pagetitle": d["title"], "longtitle": d["title"] + " — " + BRAND, "description": d["desc"],
                           "content": settings(links(B.legal_html(slug)))}, "tvs": {}})
RES.append({"alias": "404", "parent": "", "template": "bn-text", "hidemenu": 1, "searchable": 0, "richtext": 0,   # страница ошибки (error_page)
            "fields": {"pagetitle": "Страница не найдена", "longtitle": B.notfound_page()["title"], "description": B.notfound_page()["desc"],
                       "content": settings(links(B.notfound_html()))}, "tvs": {}})
cp = B.contacts_page()
RES.append({"alias": "kontakty", "parent": "", "template": "bn-contacts", "menuindex": 40, "fields": {"pagetitle": "Контакты", "longtitle": cp["title"], "description": settings(cp["desc"])}, "tvs": {}})
bi = B.blog_index()
RES.append({"alias": "blog", "parent": "", "template": "bn-blog", "isfolder": 1, "menuindex": 30, "fields": {"pagetitle": "Блог", "longtitle": bi["title"], "description": bi["desc"]}, "tvs": {}})
for p in POSTS:
    body = "".join('<section id="%s"><h2>%s</h2>%s</section>' % (sid, t, html) for sid, t, html in p["sections"])
    RES.append({"alias": p["slug"], "parent": "blog", "template": "bn-post", "richtext": 1, "publishedon": p["date"],
                "fields": {"pagetitle": p["h1"], "longtitle": p["title"] + " — " + BRAND, "description": p["desc"], "introtext": p["lead"], "content": links(body).strip()},
                "tvs": {"bn_post_cat": p["cat"], "bn_cover": "assets/img/%s.jpg" % p["cover"], "bn_cover_alt": p["cover_alt"],
                        "bn_post_houses": "@ids:" + ",".join(H[k]["file"][:-5] for k in p["houses"])}})
RES.append({"alias": "otzyvy", "parent": "", "template": "bn-section", "isfolder": 1, "hidemenu": 1, "searchable": 0,
            "fields": {"pagetitle": "Отзывы", "description": "Отзывы гостей. Добавить: правый клик по разделу → «Создать документ», заголовок — имя гостя, «Содержимое» — текст отзыва. Только настоящие отзывы"},
            "tvs": {}})
for r in REVIEWS:
    RES.append({"alias": "otzyv-%d" % (REVIEWS.index(r) + 1),
                "parent": "otzyvy", "template": "bn-review", "hidemenu": 1, "searchable": 0, "richtext": 0,
                "fields": {"pagetitle": r["name"], "content": r["text"]},
                "tvs": {"bn_rev_house": "@ids:" + H[r["house"]]["file"][:-5] if r.get("house") else "0", "bn_rev_rating": str(r.get("rating", 5)),
                        "bn_rev_source": r.get("source", ""), "bn_rev_url": r.get("url", ""), "bn_rev_date": r.get("date", "")}})
RES += [
    {"alias": "api-zayavka", "parent": "", "template": "", "hidemenu": 1, "searchable": 0, "always": True, "content_type": "application/json", "cacheable": 0,
     "fields": {"pagetitle": "API: заявка с сайта", "content": "[[!bnLead]]", "description": "Сюда форма отправляет заявки. Не удаляйте и не меняйте"}, "tvs": {}},
    {"alias": "robots", "parent": "", "template": "", "hidemenu": 1, "searchable": 0, "always": True, "content_type": "text/plain",
     "fields": {"pagetitle": "robots.txt", "content": "User-agent: *\nAllow: /\nDisallow: /manager/\nDisallow: /%s/\nDisallow: /api-zayavka\n\nSitemap: [[++site_url]]sitemap.xml" % PKG}, "tvs": {}},
    {"alias": "sitemap", "parent": "", "template": "", "hidemenu": 1, "searchable": 0, "always": True, "content_type": "text/xml",
     "fields": {"pagetitle": "sitemap.xml", "content": "[[bnSitemap]]"}, "tvs": {}},
    {"alias": "rss", "parent": "", "template": "", "hidemenu": 1, "searchable": 0, "always": True, "content_type": "text/xml",
     "fields": {"pagetitle": "rss.xml", "content": "[[bnRss]]"}, "tvs": {}},
    {"alias": "llms", "parent": "", "template": "", "hidemenu": 1, "searchable": 0, "always": True, "content_type": "text/plain",
     "fields": {"pagetitle": "llms.txt", "content": "[[bnLlms]]"}, "tvs": {}},
]

# ---------------------------------------------------------------- пакет modx/
# Чистим только своё: modx/core/ и modx/assets/ — компонент booking, его не трогаем
OWN = ("chunks", "templates", "snippets", "component", "resources.json", "bn-install.php",
       "bn-install.inc.php", "INSTALL.md", ".htaccess", "translit-bania.php")
os.makedirs(OUT, exist_ok=True)
for name in OWN:
    p = os.path.join(OUT, name)
    if os.path.isdir(p):
        shutil.rmtree(p)
    elif os.path.exists(p):
        os.remove(p)
PIC = re.compile(r'<picture><source([^>]*)>(<img[^>]*\bsrc="([^"]*)"[^>]*>)</picture>')


def unpicture(text):
    """Фото, путь к которому подставляет MODX (поле, плейсхолдер), — без <picture>: WebP-копии в образце — файлы образца
    (обложка первой статьи, фото образца-дома), для других страниц они чужие. Размеры <source> остаются в data-bn-sizes:
    <picture> с WebP этого фото соберёт Bania::picturize (плагин bnPicture)"""
    # «[[++base_url]]» в начале пути — просто адрес сайта; поле или плейсхолдер — тег MODX дальше в пути
    def one(m):
        if "[[" not in m.group(3).replace("[[++base_url]]", "", 1):
            return m.group(0)
        sz = re.search(r'\bsizes="([^"]*)"', m.group(1))
        return m.group(2).replace("<img", '<img data-bn-sizes="%s"' % sz.group(1), 1) if sz else m.group(2)
    return PIC.sub(one, text)


chunk_list = []
for n, (cat, text) in CHUNKS.items():
    text = unpicture(text)
    write("chunks/%s.html" % n, text)
    chunk_list.append({"name": n, "category": cat, "file": "chunks/%s.html" % n})
templates = []
for n, (desc, text) in TEMPLATES.items():
    text = unpicture(text)
    write("templates/%s.html" % n, text)
    templates.append({"name": n, "file": "templates/%s.html" % n, "description": desc})


def doc_of(path):
    m = re.search(r"/\*\*\s*(\w+)\s+—\s+(.*?)\s*\*/", io.open(path, encoding="utf-8").read(), re.S)
    return m.group(2).strip() if m else ""


snippets = []
for f in sorted(os.listdir(os.path.join(SRC, "snippets"))):
    os.makedirs(os.path.join(OUT, "snippets"), exist_ok=True)
    shutil.copy(os.path.join(SRC, "snippets", f), os.path.join(OUT, "snippets", f))
    snippets.append({"name": f[:-4], "file": "snippets/" + f, "description": doc_of(os.path.join(SRC, "snippets", f))[:255]})
shutil.copytree(os.path.join(SRC, "component"), os.path.join(OUT, "component"))
write("resources.json", json.dumps(RES, ensure_ascii=False, indent=1))

manifest = {
    "top": "Доходные дома", "site_name": BRAND, "email": B.MAIL, "pkg": PKG,
    "version": datetime.datetime.now().strftime("%Y-%m-%d %H:%M"),
    "chunks": chunk_list, "templates": templates, "snippets": snippets, "plugins": [], "tvs": TVS, "settings": SETTINGS,
    "cc_groups": {"Контакты": "Телефоны, мессенджеры, почта на сайте и для заявок", "Реквизиты": "Исполнитель, ИНН, ОГРНИП — в подвале и на странице контактов",
                  "Условия": "Скидка, предоплата, залог, повар — на главной, в домах и в форме", "Отзывы": "Куда отправлять гостей оставить отзыв",
                  "Аналитика": "Яндекс Метрика и Вебмастер"},
    "id_settings": ID_SETTINGS,
}
tplsrc = io.open(os.path.join(SRC, "bn-install.inc.php.tpl"), encoding="utf-8").read()
write("bn-install.inc.php", tplsrc.replace("%%MANIFEST%%", json.dumps(manifest, ensure_ascii=False, indent=1)))
for f in ("bn-install.php", "INSTALL.md", "translit-bania.php"):
    shutil.copy(os.path.join(SRC, f), os.path.join(OUT, f))
shutil.copy(os.path.join(SRC, "htaccess.txt"), os.path.join(OUT, ".htaccess"))

# ---------------------------------------------------------------- архив: пакет + файлы сайта
os.makedirs(DIST, exist_ok=True)
zpath = os.path.join(DIST, PKG + ".zip")
n_site = 0
with zipfile.ZipFile(zpath, "w", zipfile.ZIP_DEFLATED, compresslevel=6) as z:
    for name in OWN:
        p = os.path.join(OUT, name)
        for base, _, files in (os.walk(p) if os.path.isdir(p) else [(OUT, None, [name])]):
            for f in files:
                full = os.path.join(base, f)
                z.write(full, PKG + "/" + os.path.relpath(full, OUT).replace("\\", "/"))
    for d in ("css", "js", "img", "vtour", "fonts"):
        for base, _, files in os.walk(os.path.join(ROOT, "site", d)):
            for f in files:
                full = os.path.join(base, f)
                z.write(full, PKG + "/site/assets/" + os.path.relpath(full, os.path.join(ROOT, "site")).replace("\\", "/"))
                n_site += 1
    if os.path.isfile(os.path.join(ROOT, "site", "favicon.ico")):   # значок — в корень сайта (/favicon.ico)
        z.write(os.path.join(ROOT, "site", "favicon.ico"), PKG + "/site/favicon.ico"); n_site += 1
print("Пакет MODX: modx/ — чанков %d, шаблонов %d, сниппетов %d, полей %d, страниц %d" % (len(chunk_list), len(templates), len(snippets), len(TVS), len(RES)))
print("Архив: %s — %.1f МБ, файлов сайта %d" % (os.path.relpath(zpath, ROOT), os.path.getsize(zpath) / 1048576, n_site))
