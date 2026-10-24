"""Таблички в Дома с QR-кодом бота MAX: py tools/make_bot_cards.py https://max.ru/…
Ссылка — та, что в кабинете MAX («Основная информация») и в «Конфигурации» → «Контакты» → MAX.
Результат: dist/print/bot-cards.html — по табличке на Дом, формат A5; открыть в браузере → «Печать» (или «Сохранить как PDF»).
Нужен пакет qrcode (py -m pip install qrcode)."""
import html, io, os, re, sys
import qrcode, qrcode.image.svg
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "src"))
from houses import H, PHONE

if len(sys.argv) < 2 or not re.match(r"^https://\S+$", sys.argv[1]):
    sys.exit("Укажите ссылку на бота: py tools/make_bot_cards.py https://max.ru/…")
url = sys.argv[1]

buf = io.BytesIO()
qrcode.make(url, image_factory=qrcode.image.svg.SvgPathFillImage, box_size=10, border=1).save(buf)
qr = re.sub(r"^<\?xml[^>]*>\s*", "", buf.getvalue().decode("utf-8"))
qr = re.sub(r'\s(width|height)="[^"]*"', "", qr, count=2)   # размер задаёт CSS

e = html.escape
cards = "".join(f"""
<section class="card">
  <p class="brand">Доходные Дома Ефремовых</p>
  <p class="house">{e(h["name"])}</p>
  <h1>Есть вопрос?<br>Спросите бота в MAX</h1>
  <p class="lead">Цены, заезд и выезд, баня, правила, как добраться — ответит сразу, в любое время. Там же — бронирование.</p>
  <div class="qr">{qr}</div>
  <p class="how">Наведите камеру телефона на код</p>
  <p class="phone">Администратор: {e(PHONE)}</p>
</section>""" for h in H.values())

page = f"""<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><title>Таблички: бот MAX</title>
<link href="https://fonts.googleapis.com/css2?family=Golos+Text:wght@400;600&family=Prata&display=swap" rel="stylesheet">
<style>
@page {{ size: A5; margin: 0; }}
* {{ box-sizing: border-box; }}
body {{ margin: 0; background: #ddd; font: 400 15px/1.45 "Golos Text", "Segoe UI", sans-serif; color: #22140E; }}
.card {{ width: 148mm; height: 210mm; margin: 10mm auto; padding: 14mm 14mm 12mm; background: #F4EBDF;
  display: flex; flex-direction: column; align-items: center; text-align: center; break-after: page; border-top: 6mm solid #B04E09; }}
.brand {{ margin: 0; font: 400 15px/1.2 "Prata", Georgia, serif; letter-spacing: .02em; color: #66503F; }}
.house {{ margin: 3mm 0 0; font-weight: 600; color: #A94C08; text-transform: uppercase; letter-spacing: .08em; font-size: 12px; }}
h1 {{ margin: 8mm 0 0; font: 400 30px/1.15 "Prata", Georgia, serif; }}
.lead {{ margin: 5mm 0 0; max-width: 112mm; color: #66503F; }}
.qr {{ width: 72mm; height: 72mm; margin: 8mm 0 0; padding: 4mm; background: #fff; border-radius: 4mm; }}
.qr svg {{ width: 100%; height: 100%; display: block; }}
.how {{ margin: 4mm 0 0; font-weight: 600; }}
.phone {{ margin: auto 0 0; color: #66503F; }}
@media print {{ body {{ background: none; }} .card {{ margin: 0; }} }}
</style></head><body>{cards}
</body></html>"""

out = os.path.join(ROOT, "dist", "print")
os.makedirs(out, exist_ok=True)
path = os.path.join(out, "bot-cards.html")
with open(path, "w", encoding="utf-8", newline="\n") as f:
    f.write(page)
print("Готово:", path, "—", url)
