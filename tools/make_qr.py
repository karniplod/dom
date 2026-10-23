"""QR-коды: py tools/make_qr.py
- СБП для страниц предоплаты: ссылки из src/houses.py (поле sbp) → site/img/qr/<дом>.svg;
- «оставить отзыв» по Дому: первая заполненная площадка из src/reviews.py (HOUSE_REVIEW_LINKS),
  — по коду на каждую заполненную площадку → site/img/qr/rev-<дом>-<2gis|yandex|avito>.svg.
Нужен пакет qrcode (py -m pip install qrcode)."""
import os, sys
import qrcode, qrcode.image.svg
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "src"))
from houses import H
from reviews import HOUSE_REVIEW_LINKS, REVIEW_PLATFORMS, PLATFORM_KEY
out = os.path.join(ROOT, "site", "img", "qr")
os.makedirs(out, exist_ok=True)
for k, h in H.items():
    img = qrcode.make(h["sbp"], image_factory=qrcode.image.svg.SvgPathFillImage, box_size=10, border=2)
    img.save(os.path.join(out, k + ".svg"))
    print(k, h["sbp"])
for k, h in H.items():
    old = os.path.join(out, "rev-" + k + ".svg")   # прежний единый код Дома
    if os.path.exists(old):
        os.remove(old)
    links = HOUSE_REVIEW_LINKS.get(k, {})
    for p in REVIEW_PLATFORMS:
        url, f = links.get(p, ""), os.path.join(out, "rev-%s-%s.svg" % (k, PLATFORM_KEY[p]))
        if not url:  # ссылки на площадку нет — кода нет
            if os.path.exists(f):
                os.remove(f)
            continue
        img = qrcode.make(url, image_factory=qrcode.image.svg.SvgPathFillImage, box_size=10, border=2)
        img.save(f)
        print("отзыв", k, p, url)
