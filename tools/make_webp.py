"""WebP-копии фото сайта для телефонов и компьютеров: site/img/**/<имя>.jpg → <имя>-<ширина>.webp (480, 800, 1200, 1600).
Шире оригинала не делаем; пересоздаём только если JPEG новее копии. Запускается из build.py сам; вручную: py tools/make_webp.py"""
import os, sys
try:
    from PIL import Image
except ImportError:   # без Pillow (например, сборка на GitHub) копии берутся готовые из site/img
    Image = None

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
IMG = os.path.join(ROOT, "site", "img")
WIDTHS = (480, 800, 1200, 1600)
QUALITY = 72   # 78 → 72: копии на 14% легче, на глаз без разницы (Google PageSpeed: «Улучшите загрузку изображений»)


def widths_for(w):
    """Ширины копий для фото шириной w: стандартные меньше w и сам оригинал, если он уже 1600"""
    out = [x for x in WIDTHS if x < w]
    if w <= WIDTHS[-1] and (not out or w - out[-1] > 120):
        out.append(w)
    return out


def run(quiet=False):
    if Image is None:
        if not quiet:
            print("webp: Pillow не установлен — новые копии не делаю (py -m pip install pillow)")
        return 0
    made = 0
    for dirpath, _, files in os.walk(IMG):
        if os.path.basename(dirpath) in ("qr", "reviews"):   # QR — векторные; скриншоты отзывов — как есть
            continue
        for f in files:
            if not f.lower().endswith(".jpg"):
                continue
            src = os.path.join(dirpath, f)
            stem = src[:-4]
            t = os.path.getmtime(src)
            im = None
            with Image.open(src) as probe:
                ws = widths_for(probe.width)
            for w in ws:
                dst = f"{stem}-{w}.webp"
                if os.path.exists(dst) and os.path.getmtime(dst) >= t:
                    continue
                if im is None:
                    im = Image.open(src).convert("RGB")
                h = round(im.height * w / im.width)
                im.resize((w, h), Image.LANCZOS).save(dst, "WEBP", quality=QUALITY, method=6)
                made += 1
    if not quiet or made:
        print("webp: новых копий", made)
    return made


if __name__ == "__main__":
    run()
