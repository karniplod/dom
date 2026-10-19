"""Сборка сайта: python build.py  (Windows: py build.py)

src/   — исходники: данные домов (houses.py), стили, скрипт, логотип
site/  — готовые страницы, robots.txt, sitemap.xml, llms.txt (не править руками)
build/ — служебная сборка для превью в Claude (в git не попадает)
"""
import os, sys, json, re, html
ROOT = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(ROOT, "src"))
from houses import H, SITE, PHONE, PHONE_RAW

SRC, SITE_DIR, ART = (os.path.join(ROOT, d) for d in ("src", "site", os.path.join("build", "artifact")))
rd = lambda n: open(os.path.join(SRC, n), encoding="utf8").read()
esc = html.escape
def money(n): return f"{n:,}".replace(",", " ") + " ₽"
def minp(h): return min(h["prices"])

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
}
def icon(n): return f'<svg viewBox="0 0 24 24" aria-hidden="true">{ICONS[n]}</svg>'
def img(path, alt, cls="", lazy=True, w=None, h=None):
    return f'<img src="img/{path}.jpg" alt="{esc(alt)}"{f" class={chr(34)}{cls}{chr(34)}" if cls else ""}{" loading=" + chr(34) + "lazy" + chr(34) if lazy else ""} decoding="async">'

DAYS = ["Понедельник – четверг", "Пятница", "Суббота", "Воскресенье"]

# ---------- общие куски ----------
def header(active=None):
    nav = "".join(f'<a href="{v["file"]}"{" aria-current=" + chr(34) + "page" + chr(34) if k == active else ""}>{v["name"]}</a>' for k, v in H.items())
    nav += '<a href="index.html#prices">Цены</a>'
    return f'''<header class="top"><div class="wrap top__in">
  <a class="brand" href="index.html" aria-label="Доходные дома Ефремовых, на главную">{FOX}<span><b>Доходные дома Ефремовых</b><small>Дома с сауной и бассейном в Перми</small></span></a>
  <nav class="nav" aria-label="Дома">{nav}</nav>
  <a class="top__phone tnum" href="tel:{PHONE_RAW}">{PHONE}</a>
  <a class="btn btn--sm" href="#order">Оставить заявку</a>
</div></header>'''

def footer():
    links = "".join(f'<a href="{v["file"]}">{v["name"]}, {v["addr"]}</a>' for v in H.values())
    return f'''<footer class="foot" id="privacy"><div class="wrap foot__in">
  {FOX}
  <div>
    <p><a href="index.html">Доходные дома Ефремовых</a>, аренда домов с сауной и бассейном в Перми посуточно</p>
    <p>ИП Ефремова Ольга Анатольевна, ИНН 590301252919, ОГРНИП 325595800149589</p>
    <p>Бронирование: <span class="nb tnum">{PHONE}</span>, efremova7676@mail.ru</p>
    <p><a href="#privacy">Политика обработки персональных данных</a></p>
  </div>
  <nav aria-label="Дома">{links}</nav>
</div></footer>
<div class="mbar"><a class="btn btn--ghost" href="tel:{PHONE_RAW}">Позвонить</a><a class="btn" href="#order">Заявка</a></div>'''

def form(house=""):
    opts = "".join(f'<option value="{k}"{" selected" if k == house else ""}>{v["name"]} — до {v["max"]} гостей</option>' for k, v in H.items())
    return f'''<form class="form" id="order-form" novalidate>
  <div class="field full"><label for="f-house">Дом</label><select id="f-house" name="house"><option value="">Выберите дом</option>{opts}</select></div>
  <div class="field"><label for="f-in">Заезд</label><input id="f-in" name="checkin" type="date"></div>
  <div class="field"><label for="f-out">Выезд</label><input id="f-out" name="checkout" type="date"></div>
  <div class="estimate" id="estimate" aria-live="polite"></div>
  <div class="field"><label for="f-guests">Сколько гостей</label><input id="f-guests" name="guests" type="number" min="1" max="20" inputmode="numeric" placeholder="до 20"></div>
  <div class="field"><label for="f-contact">Как с вами связаться</label><select id="f-contact" name="contact"><option>Позвонить</option><option>Telegram</option><option>MAX</option><option>WhatsApp</option></select></div>
  <fieldset class="field full"><legend>Повод</legend><div class="chips">
    <label><input type="radio" name="occasion" value="birthday"><span>День рождения</span></label>
    <label><input type="radio" name="occasion" value="corporate"><span>Корпоратив</span></label>
    <label><input type="radio" name="occasion" value="friends"><span>Отдых с друзьями</span></label>
    <label><input type="radio" name="occasion" value="family"><span>Семья</span></label>
    <label><input type="radio" name="occasion" value="other"><span>Другое</span></label>
  </div></fieldset>
  <div class="field"><label for="f-name">Имя</label><input id="f-name" name="name" type="text" autocomplete="name"></div>
  <div class="field"><label for="f-phone">Телефон</label><input id="f-phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" placeholder="+7 (___) ___-__-__"></div>
  <div class="field full"><label for="f-comment">Комментарий</label><textarea id="f-comment" name="comment" placeholder="Например: приедем с собакой, нужен детский стульчик"></textarea></div>
  <div class="hp" aria-hidden="true"><label for="f-website">Сайт</label><input id="f-website" name="website" tabindex="-1" autocomplete="off"></div>
  <label class="check full"><input type="checkbox" id="f-agree" name="agree"><span>Согласен на обработку персональных данных по <a class="link" href="#privacy">политике конфиденциальности</a></span></label>
  <label class="check full"><input type="checkbox" id="f-sms" name="sms"><span>Можно прислать SMS о брони и после отдыха</span></label>
  <p class="err full" id="f-err" role="alert" hidden></p>
  <div class="full"><button class="btn" type="submit">Отправить заявку</button></div>
</form>'''

def order_section(house="", photo="mood/dinner", title="Оставьте заявку", alt="Ужин большой компанией"):
    addr = f'<p>{H[house]["addr"]}</p>' if house else '<p>Viber: <span class="nb tnum">+7 (919) 707-85-81</span></p><p><a class="link" href="https://vk.com/russianbath59" target="_blank" rel="noopener">Мы во ВКонтакте</a></p>'
    return f'''<section class="band band--soft on-light" id="order" aria-labelledby="order-title"><div class="wrap order">
  <div>
    <div class="sec-head"><h2 class="h-sec" id="order-title">{title}</h2><p class="lead">Перезвоним, подтвердим даты и назовём сумму предоплаты. Бронь действует после предоплаты.</p></div>
    <div class="order__contacts"><a class="big tnum nb" href="tel:{PHONE_RAW}">{PHONE}</a>{addr}</div>
    <div class="order__photo">{img(photo, alt)}</div>
  </div>
  {form(house)}
</div></section>'''

def faq_block(items, open_first=True):
    return "".join(f'<details{" open" if open_first and i == 0 else ""}><summary>{esc(q)}</summary><p>{esc(a)}</p></details>' for i, (q, a) in enumerate(items))

RULES = [("Залог", "5 000 – 20 000 ₽"), ("Предоплата", "после звонка администратора"), ("Тишина на участке", "после 22:00"),
         ("В доме при закрытых окнах", "можно праздновать"), ("Курение в доме", "нельзя"), ("Дети", "можно"),
         ("Питомцы", "можно, +1 000 ₽"), ("Отчётные документы", "оформляем")]
def rules_list(extra=()):
    return '<ul class="rules">' + "".join(f'<li><span>{a}</span><span class="tnum">{b}</span></li>' for a, b in list(extra) + RULES) + "</ul>"

# ---------- JSON-LD ----------
def ld_house(k):
    h = H[k]
    return {"@context": "https://schema.org", "@type": "LodgingBusiness", "name": f'{h["name"]} — Доходные дома Ефремовых',
        "url": f'{SITE}/{h["file"]}', "telephone": PHONE, "image": [f'{SITE}/img/{k}/{p}.jpg' for p in h["card_photos"]],
        "address": {"@type": "PostalAddress", "streetAddress": h["addr"].replace("ул. ", "улица "), "addressLocality": "Пермь", "addressRegion": "Пермский край", "addressCountry": "RU"},
        "priceRange": f'{minp(h)}–{max(h["prices"])} RUB', "checkinTime": h["checkin"], "checkoutTime": h["checkout"],
        "numberOfRooms": h["rooms"], "petsAllowed": True, "smokingAllowed": False,
        "amenityFeature": [{"@type": "LocationFeatureSpecification", "name": n, "value": True} for _, n, _ in h["comfort"]],
        "makesOffer": {"@type": "Offer", "priceCurrency": "RUB", "price": minp(h), "description": "Аренда дома на сутки"}}
def ld_faq(items):
    return {"@context": "https://schema.org", "@type": "FAQPage", "mainEntity": [{"@type": "Question", "name": q, "acceptedAnswer": {"@type": "Answer", "text": a}} for q, a in items]}
def ld_org():
    return {"@context": "https://schema.org", "@type": "Organization", "name": "Доходные дома Ефремовых", "url": SITE + "/", "telephone": PHONE, "logo": SITE + "/img/fox.svg",
            "sameAs": ["https://vk.com/russianbath59"], "department": [{"@type": "LodgingBusiness", "name": v["name"], "url": f'{SITE}/{v["file"]}'} for v in H.values()]}

# ---------- главная ----------
HOME_FAQ = [
 ("Сколько стоит снять дом с сауной и бассейном в Перми?", "От 15 000 ₽ за сутки в доме на Свиязева и от 17 000 ₽ в Английском и Русском доме с понедельника по четверг. В пятницу 20 000–22 000 ₽, в субботу 25 000–27 000 ₽."),
 ("Как забронировать дом?", "Оставьте заявку на сайте или позвоните по номеру +7 (342) 277-66-22. Администратор проверит даты и назовёт сумму предоплаты. Бронь действует после предоплаты."),
 ("Можно ли снять дом на день рождения или корпоратив?", "Да. В Английском и Русском доме ночуют 10 гостей, а отдыхают до 20; в доме на Свиязева ночуют 8, отдыхают до 15. В доме при закрытых окнах можно праздновать, на участке тишина после 22:00."),
 ("Где сауна с бассейном, который работает зимой?", "В доме на Свиязева бассейн всесезонный, рядом джакузи и сауна."),
 ("Можно с собакой или кошкой?", "Да, во всех трёх домах. Доплата за питомца 1 000 ₽."),
 ("Как внести предоплату?", "Только после того, как администратор подтвердил даты: по ссылке через Систему быстрых платежей, в комментарии укажите имя и дату заезда. При отмене предоплату возвращаем, если на ваши даты находится другой гость."),
 ("Что такое залог?", "Сумма от 5 000 до 20 000 ₽ на случай повреждений в доме. Точную сумму администратор назовёт при подтверждении брони."),
]

def house_card(k):
    h = H[k]; ph = h["card_photos"]
    caps = dict(h["gallery"])
    media = "".join(f'<a href="img/{k}/{p}.jpg" data-lb="card-{k}" data-cap="{esc(h["name"] + ": " + caps.get(p, ""))}">{img(k + "/" + p, h["name"] + ": " + caps.get(p, "фото"))}</a>' for p in ph)
    feats = {"en": ["Сауна и бассейн", "Американский бильярд и камин", "4 спальни, кухня с духовкой", "Ночуют 10, отдыхают до 20"],
             "ru": ["Сауна и бассейн", "Бильярдная, массажное кресло", "Терраса и беседка с мангалом", "Выезд до 14:00, до 20 гостей"],
             "sv": ["Бассейн круглый год и джакузи", "Сауна и бильярд", "Беседка с мангалом", "Ночуют 8, отдыхают до 15"]}[k]
    return f'''<article class="hcard h-{k}">
  <div class="hcard__media">{media}</div>
  <div class="hcard__body">
    <p class="hcard__kick">{h["kicker"]}</p>
    <h3><a href="{h["file"]}">{h["name"]}</a></h3>
    <ul>{"".join(f"<li>{f}</li>" for f in feats)}</ul>
    <p class="hcard__price"><b class="tnum">от {money(minp(h))}</b><span>за сутки</span></p>
    <div class="hcard__cta"><a class="btn btn--sm" href="#order" data-house="{k}">Оставить заявку</a><a class="btn btn--sm btn--ghost on-light" href="{h["file"]}">Подробнее</a><a class="link tour" href="{h["tour"]}" target="_blank" rel="noopener">3D-тур</a></div>
  </div>
</article>'''

def home():
    strip = [("ru/hero", "Русский дом вечером"), ("en/pool", "Бассейн, Английский дом"), ("sv/pool", "Всесезонный бассейн, Свиязева"), ("ru/sauna", "Сауна, Русский дом"),
             ("en/billiard-2", "Бильярд, Английский дом"), ("ru/kitchen", "Кухня-гостиная, Русский дом"), ("sv/billiard", "Бильярд, Свиязева"), ("en/fireplace", "Камин, Английский дом"),
             ("ru/pool", "Бассейн, Русский дом"), ("sv/gazebo", "Беседка с мангалом, Свиязева"), ("en/dining", "Стол, Английский дом"), ("ru/bedroom", "Спальня, Русский дом")]
    strip_html = "".join(f'<a href="img/{p}.jpg" data-lb="strip" data-cap="{esc(c)}">{img(p, c)}<span>{c}</span></a>' for p, c in strip)
    table_rows = [("Дом и участок", lambda h: f'{h["area"]} м², {h["rooms"]} комнат{"ы" if h["rooms"] < 5 else ""}, {h["plot"]}'),
                  ("Ночуют / отдыхают", lambda h: f'{h["sleep"]} / до {h["max"]} гостей'),
                  ("Заезд / выезд", lambda h: f'с {h["checkin"]} / до {h["checkout"]}')]
    extra = {"en": "Сауна, бассейн, бильярд, камин", "ru": "Сауна, бассейн, бильярд, терраса", "sv": "Сауна, бассейн круглый год, джакузи, бильярд"}
    th = "".join(f'<th scope="col" class="h-{k}">{v["name"]}</th>' for k, v in H.items())
    rows = "".join(f'<tr><th scope="row">{lab}</th>' + "".join(f"<td>{fn(v)}</td>" for v in H.values()) + "</tr>" for lab, fn in table_rows)
    rows += '<tr><th scope="row">Для отдыха</th>' + "".join(f"<td>{extra[k]}</td>" for k in H) + "</tr>"
    for i, d in enumerate(["Пн – Чт", "Пятница", "Суббота", "Воскресенье"]):
        rows += f'<tr class="money{" money-first" if i == 0 else ""}"><th scope="row">{d}</th>' + "".join(f'<td class="tnum">{money(v["prices"][i])}</td>' for v in H.values()) + "</tr>"
    rows += '<tr><th scope="row"><span class="sr">Ссылки</span></th>' + "".join(f'<td><a href="{v["file"]}">Подробнее о доме</a></td>' for v in H.values()) + "</tr>"
    body = f'''{header()}
<main>
<section class="hero" aria-labelledby="hero-title">
  {img("ru/hero", "Русский дом вечером, огни на фасаде", "hero__img", lazy=False)}
  <div class="wrap"><div class="hero__in">
    <p class="hero__kicker">Доходные дома Ефремовых</p>
    <h1 id="hero-title">Снять дом с сауной и бассейном в Перми</h1>
    <p class="hero__sub">Три дома в трёх стилях: Английский, Русский и дом на Свиязева. Сауна, бассейн, бильярд и мангал, а на праздник можно позвать до 20 гостей.</p>
    <div class="hero__cta"><a class="btn" href="#order">Оставить заявку</a><a class="btn btn--ghost" href="#houses">Выбрать дом</a></div>
    <div class="facts-row"><span>сутки от 15 000 ₽</span><span>до 20 гостей</span><span>в черте города</span><span>можно с питомцами</span></div>
  </div></div>
</section>

<section class="band on-light" id="houses" aria-labelledby="houses-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="houses-title">Три дома, три характера</h2><p class="lead">В каждом доме сауна, бассейн, бильярд, кухня и спальни. Выбирайте по стилю, числу гостей и дню недели.</p></div>
  <div class="houses">{"".join(house_card(k) for k in H)}</div>
</div></section>

<section class="band band--soft" id="comfort" aria-labelledby="comfort-title"><div class="wrap comfort">
  <div class="collage">
    <figure>{img("en/kitchen", "Кухня в Английском доме")}<figcaption>Кухня, Английский дом</figcaption></figure>
    <figure>{img("ru/pool-2", "Бассейн в Русском доме")}<figcaption>Бассейн, Русский дом</figcaption></figure>
    <figure>{img("sv/sauna", "Сауна в доме на Свиязева")}<figcaption>Сауна, Свиязева</figcaption></figure>
  </div>
  <div>
    <div class="sec-head"><h2 class="h-sec" id="comfort-title">Дизайнерские дома, где всё уже готово</h2><p class="lead">Мы позаботились о комфорте гостей. Привозите продукты и хорошую компанию, остальное в доме есть.</p></div>
    <ul class="amen">
      <li>{icon("kitchen")}<div><b>Кухня с посудой</b><span>Холодильник, плита, микроволновка, посуда на всех</span></div></li>
      <li>{icon("steam")}<div><b>Сауна и бассейн</b><span>На Свиязева бассейн круглый год и джакузи</span></div></li>
      <li>{icon("cue")}<div><b>Американский бильярд</b><span>В каждом доме</span></div></li>
      <li>{icon("fire")}<div><b>Мангал и беседка</b><span>Мангалы и шампуры уже на месте</span></div></li>
      <li>{icon("bed")}<div><b>Спальни и бельё</b><span>Чистое постельное бельё на всех, кто ночует</span></div></li>
      <li>{icon("snow")}<div><b>Кондиционеры</b><span>На каждом этаже в Английском и Русском доме</span></div></li>
      <li>{icon("tv")}<div><b>Телевизор и Wi-Fi</b><span>Wi-Fi в Английском и Русском доме</span></div></li>
      <li>{icon("car")}<div><b>Свой участок</b><span>Закрытый двор и парковка</span></div></li>
    </ul>
    <p class="included"><b>Входит в стоимость:</b> постельное бельё, посуда, мангал и шампуры, чай, кофе, сахар, специи, питьевая вода.</p>
  </div>
</div></section>

<section class="band band--dark" aria-labelledby="occ-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="occ-title">Для праздника и для отдыха</h2><p class="lead">Дома стоят в городе: гости доезжают на такси, а вечер не нужно сворачивать ради дороги домой.</p></div>
  <div class="occ">
    <article>{img("mood/party", "Праздничный стол с бокалами")}<h3>День рождения</h3><p>Большой стол, музыка, сауна и бассейн. В доме при закрытых окнах празднуйте сколько хотите.</p><p class="fit">Русский или Английский дом, до 20 гостей</p><button type="button" data-occasion="birthday">Заявка на день рождения</button></article>
    <article>{img("mood/friends-sauna", "Друзья в сауне")}<h3>Сауна с друзьями</h3><p>Попариться, поплавать, сыграть в бильярд. С понедельника по четверг сутки дешевле.</p><p class="fit">Свиязева: бассейн круглый год, от 15 000 ₽</p><button type="button" data-occasion="friends">Заявка для компании</button></article>
    <article>{img("en/dining", "Длинный стол в Английском доме")}<h3>Корпоратив</h3><p>Стол на всю команду, бильярд и мангал. Отчётные документы для бухгалтерии оформляем.</p><p class="fit">Английский дом: 200 м², 6 комнат</p><button type="button" data-occasion="corporate">Заявка на корпоратив</button></article>
    <article>{img("ru/facade", "Двор Русского дома с беседкой")}<h3>Семейные выходные</h3><p>Можно с детьми и с питомцами. Отдельные спальни для тех, кто ляжет пораньше.</p><p class="fit">Любой из трёх домов</p><button type="button" data-occasion="family">Заявка для семьи</button></article>
  </div>
</div></section>

<section class="promo" aria-labelledby="promo-title"><div class="wrap promo__in">
  <div><h2 id="promo-title">Будни дешевле выходных на 10 000 ₽</h2><p>С понедельника по четверг сутки от 15 000 ₽, в субботу от 25 000 ₽. Хороший повод собраться среди недели.</p></div>
  <a class="btn" href="#order">Выбрать дату</a>
</div></section>

<section class="band on-light" id="prices" aria-labelledby="prices-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="prices-title">Сравните дома и цены</h2><p class="lead">Цена за сутки зависит от дня недели. Отдельно оплачивается только питомец, 1 000 ₽.</p></div>
  <div class="table-wrap"><table class="cmp"><thead><tr><th scope="col"><span class="sr">Параметр</span></th>{th}</tr></thead><tbody>{rows}</tbody></table></div>
  <p class="note">Предпраздничный рабочий день считается как пятница, праздник перед выходным как суббота, праздник перед рабочим днём как воскресенье.</p>
</div></section>

<section class="band band--soft" aria-labelledby="strip-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="strip-title">Посмотрите дома изнутри</h2><p class="lead">Нажмите на фото, чтобы открыть крупно. Для каждого дома есть 3D-тур на его странице.</p></div>
  <div class="strip">{strip_html}</div>
</div></section>

<section class="band on-light" id="rules" aria-labelledby="rules-title"><div class="wrap two">
  <div><div class="sec-head"><h2 class="h-sec" id="rules-title">Правила домов</h2></div>{rules_list()}</div>
  <div class="faq"><div class="sec-head"><h2 class="h-sec">Частые вопросы</h2></div>{faq_block(HOME_FAQ)}</div>
</div></section>

<section class="band band--soft" aria-labelledby="seo-title"><div class="wrap seo">
  <h2 class="h-sec" id="seo-title">Аренда дома с сауной и бассейном в Перми посуточно</h2>
  <div class="seo__text">
    <p>«Доходные дома Ефремовых» сдают посуточно три дома в Перми: <strong>Английский дом</strong> и <strong>Русский дом</strong> на улице Космонавта Беляева и <strong>дом на Свиязева</strong>. Если вы ищете, где снять дом на сутки с баней и бассейном, не уезжая за город, начните с них: в каждом доме своя сауна, бассейн, бильярд, кухня и спальни.</p>
    <h3>Сауна с бассейном для компании</h3>
    <p>В Английском и Русском доме ночуют 10 человек, а на вечер можно позвать до 20 гостей. В доме на Свиязева бассейн работает круглый год, рядом джакузи. Это удобный вариант, когда нужна сауна с бассейном в Перми для дня рождения, девичника, встречи друзей или корпоратива.</p>
    <h3>Сколько стоит снять коттедж посуточно</h3>
    <p>Сутки стоят от 15 000 ₽ в будни и от 25 000 ₽ в субботу. В цену входят постельное бельё, посуда, мангал и шампуры, чай и кофе. Отдельно оплачивается только питомец. Залог от 5 000 до 20 000 ₽ администратор называет при подтверждении брони.</p>
    <h3>Как забронировать</h3>
    <p>Оставьте заявку или позвоните по номеру <span class="nb">{PHONE}</span>. Администратор проверит свободные даты, перезвонит и пришлёт ссылку на предоплату.</p>
  </div>
</div></section>

<section class="band band--dark" id="reviews" aria-labelledby="rev-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="rev-title">Отзывы гостей</h2><p class="lead">Показываем отзывы с карт как есть, вместе с оценками.</p></div>
  <div class="reviews"><div class="review-slot"><h3>Яндекс Карты</h3><p>Место для официального виджета рейтинга и отзывов Яндекса.</p></div><div class="review-slot"><h3>2ГИС</h3><p>Место для виджета отзывов 2ГИС.</p></div></div>
</div></section>

{order_section()}

<section class="band band--dark" id="contacts" aria-labelledby="addr-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="addr-title">Где мы</h2><p class="lead">Все три дома в новом квартале Перми, у каждого свой участок и парковка.</p></div>
  <div class="addr">{"".join(f'<a class="addr__card" href="{v["file"]}">{img(k + "/facade", v["name"] + ", фасад")}<h3>{v["name"]}</h3><p>{v["addr"]}</p><span>Подробнее о доме</span></a>' for k, v in H.items())}</div>
</div></section>
</main>
{footer()}'''
    lds = [ld_org(), ld_faq(HOME_FAQ)]
    return ("index.html", "Снять дом с сауной и бассейном в Перми посуточно — Доходные дома Ефремовых",
            "Аренда домов с сауной, бассейном и бильярдом в Перми на сутки: Английский, Русский и дом на Свиязева. До 20 гостей, мангал, кухня. От 15 000 ₽ за сутки.",
            body, lds, "ru/hero", None)

# ---------- страница дома ----------
def house_page(k):
    h = H[k]
    sizes = {0: "big", 1: "t", 4: "w", 7: "w"}
    n = len(h["gallery"]); cells = n + 3 + 1 + 1 + 1; rem = cells % 4
    for j in range((4 - rem) % 4): sizes.setdefault(n - 1 - j, "w")
    gal = "".join(f'<a class="{sizes.get(i, "")}" href="img/{k}/{p}.jpg" data-lb="g-{k}" data-cap="{esc(c)}">{img(k + "/" + p, h["name"] + ": " + c)}<span>{c}</span></a>' for i, (p, c) in enumerate(h["gallery"]))
    amen = "".join(f'<li>{icon(i)}<div><b>{b}</b><span>{d}</span></div></li>' for i, b, d in h["comfort"])
    hot = max(range(4), key=lambda i: h["prices"][i])
    prices = "".join(f'<div{" class=" + chr(34) + "hot" + chr(34) if i == hot else ""}><span>{DAYS[i]}</span><b class="tnum">{money(v)}</b></div>' for i, v in enumerate(h["prices"]))
    facts = "".join(f'<div><b class="tnum">{a}</b><span>{b}</span></div>' for a, b in h["facts"])
    seo = "".join(f"<p>{t}</p>" for t in h["seo"])
    others = "".join(house_card(o) for o in H if o != k)
    body = f'''{header(k)}
<main class="h-{k}">
<section class="dhero" aria-labelledby="d-title">
  {img(k + "/hero", h["name"] + ", " + h["gallery"][0][1], "dhero__img", lazy=False)}
  <div class="wrap"><div class="dhero__in">
    <p class="crumbs"><a href="index.html">Все дома</a></p>
    <p class="dhero__kicker">{h["kicker"]}</p>
    <h1 id="d-title">{h["h1"]}</h1>
    <p class="intro">{h["intro"]}</p>
    <div class="dfacts">{facts}</div>
    <p class="dprice">от {money(minp(h))} <small>за сутки, {h["addr"]}</small></p>
    <div class="hero__cta"><a class="btn" href="#order">Оставить заявку</a><a class="btn btn--ghost" href="{h["tour"]}" target="_blank" rel="noopener">Открыть 3D-тур</a></div>
  </div></div>
</section>

<section class="band on-light" aria-labelledby="g-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="g-title">Фото дома</h2><p class="lead">Нажмите на фото, чтобы открыть крупно и пролистать.</p></div>
  <div class="gal">{gal}</div>
  <div class="tourband"><div><h3>3D-тур по дому</h3><p>{h["tourtxt"]}</p></div><a class="btn" href="{h["tour"]}" target="_blank" rel="noopener">Открыть 3D-тур</a></div>
</div></section>

<section class="band band--soft" aria-labelledby="c-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="c-title">Мы позаботились о комфорте</h2><p class="lead">Всё для большого стола и долгого вечера уже в доме.</p></div>
  <ul class="amen amen--4">{amen}</ul>
  <p class="included"><b>Входит в стоимость:</b> постельное бельё, посуда, мангал и шампуры, чай, кофе, сахар, специи, питьевая вода.</p>
</div></section>

<section class="band on-light" aria-labelledby="p-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="p-title">Цена за сутки</h2><p class="lead">Заезд с {h["checkin"]}, выезд до {h["checkout"]} следующего дня.</p></div>
  <div class="price-days">{prices}</div>
  <p class="note">Праздники считаются как выходные. Залог 5 000 – 20 000 ₽, питомец +1 000 ₽. Тишина на участке после 22:00, в доме при закрытых окнах можно праздновать.</p>
</div></section>

<section class="band band--soft" aria-labelledby="s-title"><div class="wrap two">
  <div class="seo__text"><h2 class="h-sec" id="s-title" style="color:var(--bark)">{h["name"]} в Перми посуточно</h2>{seo}</div>
  <div class="faq"><div class="sec-head"><h2 class="h-sec">Вопросы о доме</h2></div>{faq_block(h["faq"])}</div>
</div></section>

{order_section(k, k + "/" + h["card_photos"][1], "Заявка: " + h["name"], h["name"])}

<section class="band on-light" aria-labelledby="o-title"><div class="wrap">
  <div class="sec-head"><h2 class="h-sec" id="o-title">Другие дома</h2></div>
  <div class="houses" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">{others}</div>
</div></section>
</main>
{footer()}'''
    return (h["file"], h["title"], h["desc"], body, [ld_house(k), ld_faq(h["faq"])], k + "/hero", k)

# ---------- сборка ----------
FONTS = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Golos+Text:wght@400;500;600&family=Prata&display=swap">'
FAV = 'data:image/svg+xml,' + rd("fox.svg").replace('<path ', '<path fill="%23D8650F" ').replace('"', "'").replace("#", "%23")
HJS = {k: dict(name=v["name"], prices=dict(zip(["wd", "fri", "sat", "sun"], [v["prices"][0], v["prices"][1], v["prices"][2], v["prices"][3]])), max=v["max"], checkin=v["checkin"], checkout=v["checkout"], prepay=v["prepay"]) for k, v in H.items()}
css, js = rd("styles.css"), rd("app.js")
data_js = "window.HOUSES = " + json.dumps(HJS, ensure_ascii=False) + ";"
for d in (SITE_DIR + "/css", SITE_DIR + "/js", ART):
    os.makedirs(d, exist_ok=True)
open(f"{SITE_DIR}/css/styles.css", "w", encoding="utf8").write(css)
open(f"{SITE_DIR}/js/app.js", "w", encoding="utf8").write(js)
open(f"{SITE_DIR}/img/fox.svg", "w", encoding="utf8").write(rd("fox.svg").replace("<path ", '<path fill="#D8650F" '))
pages = [home()] + [house_page(k) for k in H]
for fn, title, desc, body, lds, ogimg, house in pages:
    url = f"{SITE}/" + ("" if fn == "index.html" else fn)
    body = rd("fox.symbol.html") + "\n" + body
    ld = "".join(f'<script type="application/ld+json">{json.dumps(x, ensure_ascii=False)}</script>' for x in lds)
    head = (f'<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
            f'<title>{esc(title)}</title><meta name="description" content="{esc(desc)}"><link rel="canonical" href="{url}">'
            f'<meta property="og:type" content="website"><meta property="og:locale" content="ru_RU"><meta property="og:title" content="{esc(title)}"><meta property="og:description" content="{esc(desc)}"><meta property="og:url" content="{url}"><meta property="og:image" content="{SITE}/img/{ogimg}.jpg">'
            f'<meta name="theme-color" content="#22140E"><link rel="icon" href="{FAV}">{FONTS}{ld}')
    open(f"{SITE_DIR}/{fn}", "w", encoding="utf8").write(f'<!doctype html>\n<html lang="ru">\n<head>{head}<link rel="stylesheet" href="css/styles.css"></head>\n<body>\n{body}\n<script>{data_js}</script>\n<script src="js/app.js"></script>\n</body>\n</html>\n')
    if fn == "index.html":
        art = f'<title>Доходные дома Ефремовых</title>\n<meta name="claude:viewport-fit-sides" content="cover">\n{FONTS}\n<style>\n{css}\n</style>\n{body}\n<script>{data_js}\n{js}</script>\n'
    else:
        art = f'<!doctype html>\n<html lang="ru">\n<head>{head}<style>\n{css}\n</style></head>\n<body>\n{body}\n<script>{data_js}\n{js}</script>\n</body>\n</html>\n'
    open(f"{ART}/{fn}", "w", encoding="utf8").write(art)

# robots, sitemap, llms.txt
open(f"{SITE_DIR}/robots.txt", "w", encoding="utf8").write(f"User-agent: *\nAllow: /\n\nSitemap: {SITE}/sitemap.xml\n")
urls = "".join(f"<url><loc>{SITE}/{'' if p[0] == 'index.html' else p[0]}</loc><changefreq>monthly</changefreq></url>" for p in pages)
open(f"{SITE_DIR}/sitemap.xml", "w", encoding="utf8").write(f'<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">{urls}</urlset>\n')
llms = ["# Доходные дома Ефремовых", "", "> Аренда трёх домов с сауной, бассейном и бильярдом в Перми посуточно. Бронирование: " + PHONE + ".", ""]
for k, v in H.items():
    p = v["prices"]
    llms.append(f'- [{v["name"]}]({SITE}/{v["file"]}): {v["addr"]}, {v["area"]} м², ночуют {v["sleep"]}, отдыхают до {v["max"]}; заезд {v["checkin"]}, выезд {v["checkout"]}; сутки: Пн–Чт {p[0]} ₽, Пт {p[1]} ₽, Сб {p[2]} ₽, Вс {p[3]} ₽.')
llms += ["", "Правила: залог 5 000–20 000 ₽, питомцы +1 000 ₽, курить в доме нельзя, тишина на участке после 22:00. Предоплата после подтверждения дат администратором."]
open(f"{SITE_DIR}/llms.txt", "w", encoding="utf8").write("\n".join(llms) + "\n")
print("ok:", ", ".join(p[0] for p in pages))
