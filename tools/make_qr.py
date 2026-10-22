"""QR-коды: py tools/make_qr.py
- СБП для страниц предоплаты: ссылки из src/houses.py (поле sbp) → site/img/qr/<дом>.svg;
- «оставить отзыв» по Дому: первая заполненная площадка из src/reviews.py (HOUSE_REVIEW_LINKS),
  а пока ссылок нет — страница Дома на сайте → site/img/qr/rev-<дом>.svg.
Нужен пакет qrcode (py -m pip install qrcode)."""
import os, sys
import qrcode, qrcode.image.svg
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "src"))
from houses import H, SITE
from reviews import HOUSE_REVIEW_LINKS, REVIEW_PLATFORMS
out = os.path.join(ROOT, "site", "img", "qr")
os.makedirs(out, exist_ok=True)
for k, h in H.items():
    img = qrcode.make(h["sbp"], image_factory=qrcode.image.svg.SvgPathFillImage, box_size=10, border=2)
    img.save(os.path.join(out, k + ".svg"))
    print(k, h["sbp"])
for k, h in H.items():
    links = HOUSE_REVIEW_LINKS.get(k, {})
    url = next((links[p] for p in REVIEW_PLATFORMS if links.get(p)), "") or f'{SITE.rstrip("/")}/{h["file"].removesuffix(".html")}#reviews'
    img = qrcode.make(url, image_factory=qrcode.image.svg.SvgPathFillImage, box_size=10, border=2)
    img.save(os.path.join(out, "rev-" + k + ".svg"))
    print("отзыв", k, url)
