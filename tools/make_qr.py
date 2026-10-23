"""QR-коды: py tools/make_qr.py
- СБП для страниц предоплаты: ссылки из src/houses.py (поле sbp) → site/img/qr/<дом>.svg;
- «оставить отзыв» по Дому: первая заполненная площадка из src/reviews.py (HOUSE_REVIEW_LINKS),
  → site/img/qr/rev-<дом>.svg; пока ссылки нет, кода нет (блок отзывов его не показывает).
Нужен пакет qrcode (py -m pip install qrcode)."""
import os, sys
import qrcode, qrcode.image.svg
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "src"))
from houses import H
from reviews import HOUSE_REVIEW_LINKS, REVIEW_PLATFORMS
out = os.path.join(ROOT, "site", "img", "qr")
os.makedirs(out, exist_ok=True)
for k, h in H.items():
    img = qrcode.make(h["sbp"], image_factory=qrcode.image.svg.SvgPathFillImage, box_size=10, border=2)
    img.save(os.path.join(out, k + ".svg"))
    print(k, h["sbp"])
for k, h in H.items():
    links = HOUSE_REVIEW_LINKS.get(k, {})
    url = next((links[p] for p in REVIEW_PLATFORMS if links.get(p)), "")
    f = os.path.join(out, "rev-" + k + ".svg")
    if not url:  # ссылки на площадку нет — кода нет (вести на свою же страницу бессмысленно)
        if os.path.exists(f):
            os.remove(f)
        print("отзыв", k, "— нет ссылки, QR не делаем")
        continue
    img = qrcode.make(url, image_factory=qrcode.image.svg.SvgPathFillImage, box_size=10, border=2)
    img.save(f)
    print("отзыв", k, url)
