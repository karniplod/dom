"""3D-туры для сайта: py tools/prep_vtour.py
Берёт скачанные туры krpano из vtour/ (исходники владельца, папки и распакованные .rar) и кладёт в site/vtour/<имя>/:
- только то, что нужно в браузере: tour.html, tour.js, tour.xml, panos/, plugins/, skin/ (без tour_testingserver.exe,
  версии для macOS, Flash tour.swf, резервных копий);
- в подписях «Сауна» → «Баня» (на сайте пишем «баня»);
- tour.html запускает только HTML5-версию (Flash давно не работает).
Сопоставление папок — TOURS ниже. RAR распаковывается WinRAR (UnRAR.exe), если архив ещё не распакован."""
import os, re, shutil, subprocess, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, "vtour")
OUT = os.path.join(ROOT, "site", "vtour")
UNRAR = r"C:\Program Files\WinRAR\UnRAR.exe"

# имя на сайте → (папка или архив в vtour/, имя папки тура внутри)
TOURS = {
    "belaeva111": ("vtour_angliiskiy_dom", "vtour_belaeva111"),          # Английский Дом
    "belaeva": ("vtour_russkiy_dom", "vtour_belaeva"),                   # Русский Дом
    "sauna59": ("vtour_sviayzeva_dom/vtour_sauna59.rar", "vtour_sauna59"),  # Дом на Свиязева
    "sviazeva": ("vtour_sauna/vtour_sauna.rar", "vtour_sviazeva"),       # Дом на Свиязева, второй тур
}
# заголовок вкладки тура
TITLES = {"belaeva111": "Английский Дом — 3D-тур", "belaeva": "Русский Дом — 3D-тур",
          "sauna59": "Дом на Свиязева — 3D-тур", "sviazeva": "Дом на Свиязева — второй 3D-тур"}
SKIP_FILES = re.compile(r"(^tour_testingserver|\.exe$|\.swf$|backup|^tour1\.xml$|\.rar$)", re.I)


def source_dir(src, inner):
    path = os.path.join(SRC, src)
    if path.lower().endswith(".rar"):
        unpacked = os.path.join(os.path.dirname(path), "_unpacked")
        if not os.path.isdir(os.path.join(unpacked, inner)):
            os.makedirs(unpacked, exist_ok=True)
            subprocess.run([UNRAR, "x", "-o+", "-idq", path, unpacked + os.sep], check=True)
        return os.path.join(unpacked, inner)
    return os.path.join(path, inner)


def main():
    for name, (src, inner) in TOURS.items():
        sd = source_dir(src, inner)
        if not os.path.isfile(os.path.join(sd, "tour.html")):
            sys.exit(f"нет тура: {sd}")
        dst = os.path.join(OUT, name)
        if os.path.isdir(dst):
            shutil.rmtree(dst)
        n = 0
        for base, dirs, files in os.walk(sd):
            dirs[:] = [d for d in dirs if not SKIP_FILES.search(d)]
            rel = os.path.relpath(base, sd)
            for f in files:
                if SKIP_FILES.search(f):
                    continue
                os.makedirs(os.path.join(dst, rel), exist_ok=True)
                shutil.copy2(os.path.join(base, f), os.path.join(dst, rel, f))
                n += 1
        xml = os.path.join(dst, "tour.xml")
        s = open(xml, encoding="utf-8").read()
        s = s.replace("Сауна", "Баня").replace("сауна", "баня")
        open(xml, "w", encoding="utf-8", newline="\n").write(s)
        html = os.path.join(dst, "tour.html")
        h = open(html, encoding="utf-8").read()
        h = h.replace('swf:"tour.swf", ', "").replace('html5:"auto"', 'html5:"only"')
        h = re.sub(r"<title>.*?</title>", f"<title>{TITLES[name]} — Доходные Дома Ефремовых</title>", h, count=1, flags=re.S)
        # значок сайта: ../../img/fox.svg — и на статичном сайте (vtour/…), и в MODX (assets/vtour/…)
        if 'rel="icon"' not in h:
            h = h.replace("</title>", '</title>\n\t<link rel="icon" href="../../img/fox.svg" type="image/svg+xml">', 1)
        open(html, "w", encoding="utf-8", newline="\n").write(h)
        size = sum(os.path.getsize(os.path.join(b, f)) for b, _, fs in os.walk(dst) for f in fs) / 1048576
        print(f"{name}: {n} файлов, {size:.1f} МБ")


if __name__ == "__main__":
    main()
