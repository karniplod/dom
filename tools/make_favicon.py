"""Значки сайта из src/icon-512.png (лиса на тёмном фоне, как у приложения):
site/favicon.ico (16, 32, 48) — его по умолчанию берут Яндекс и браузеры; site/img/favicon-120.png — Яндекс рекомендует 120×120;
site/img/apple-touch-icon.png (180×180) — iPhone. Без Pillow ничего не делает (значки остаются прежними)."""
import os
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def run(quiet=False):
    try:
        from PIL import Image
    except ImportError:
        if not quiet: print("нет Pillow — значки не пересобраны")
        return
    src = Image.open(os.path.join(ROOT, "src", "icon-512.png")).convert("RGBA")
    site = os.path.join(ROOT, "site")
    os.makedirs(os.path.join(site, "img"), exist_ok=True)
    src.save(os.path.join(site, "favicon.ico"), sizes=[(16, 16), (32, 32), (48, 48)])
    src.save(os.path.join(site, "img", "logo-512.png"), optimize=True)   # логотип для разметки: Google берёт PNG/JPG от 112×112, не SVG
    for name, n in (("favicon-120.png", 120), ("apple-touch-icon.png", 180)):
        src.resize((n, n), Image.LANCZOS).save(os.path.join(site, "img", name), optimize=True)
    if not quiet: print("значки: site/favicon.ico, site/img/favicon-120.png, site/img/apple-touch-icon.png")


if __name__ == "__main__":
    run()
