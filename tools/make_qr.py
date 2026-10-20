"""QR-коды СБП для страниц предоплаты: py tools/make_qr.py
Ссылки берутся из src/houses.py (поле sbp). Файлы: site/img/qr/<дом>.svg. Нужен пакет qrcode (py -m pip install qrcode)."""
import os, sys
import qrcode, qrcode.image.svg
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "src"))
from houses import H
out = os.path.join(ROOT, "site", "img", "qr")
os.makedirs(out, exist_ok=True)
for k, h in H.items():
    img = qrcode.make(h["sbp"], image_factory=qrcode.image.svg.SvgPathFillImage, box_size=10, border=2)
    img.save(os.path.join(out, k + ".svg"))
    print(k, h["sbp"])
