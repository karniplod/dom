"""Шрифты сайта — со своего сервера: py tools/fetch_fonts.py
Скачивает Golos Text (400, 500, 600) и Prata из Google Fonts в site/fonts/ и пишет site/fonts/fonts.css.
Зачем: при подключении с fonts.googleapis.com IP посетителя уходит на серверы Google за рубежом (трансграничная
передача по 152-ФЗ); со своего сервера — нет. Запускать один раз (или при смене шрифтов)."""
import os, re, urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "site", "fonts")
URL = "https://fonts.googleapis.com/css2?family=Golos+Text:wght@400;500;600&family=Prata&display=swap"
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36"
KEEP = {"cyrillic", "cyrillic-ext", "latin", "latin-ext"}


def get(url):
    return urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent": UA}), timeout=30).read()


def main():
    os.makedirs(OUT, exist_ok=True)
    css = get(URL).decode("utf-8")
    blocks = re.findall(r"/\* ([\w-]+) \*/\s*(@font-face \{.*?\})", css, re.S)
    out, saved = [], {}
    for subset, block in blocks:
        if subset not in KEEP:
            continue
        fam = re.search(r"font-family: '([^']+)'", block).group(1)
        weight = re.search(r"font-weight: (\d+)", block).group(1)
        src = re.search(r"url\((https://[^)]+\.woff2)\)", block).group(1)
        if src not in saved:
            name = f"{fam.lower().replace(' ', '-')}-{subset}-{len(saved)}.woff2"
            with open(os.path.join(OUT, name), "wb") as f:
                f.write(get(src))
            saved[src] = name
        out.append(f"/* {fam} {weight} {subset} */\n" + block.replace(src, saved[src]))
    with open(os.path.join(OUT, "fonts.css"), "w", encoding="utf-8", newline="\n") as f:
        f.write("/* Шрифты сайта со своего сервера (tools/fetch_fonts.py) */\n" + "\n".join(out) + "\n")
    print(f"файлов шрифтов: {len(saved)}, правил @font-face: {len(out)}")


if __name__ == "__main__":
    main()
