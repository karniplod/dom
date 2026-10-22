"""Общее для e2e-тестов booking: адрес стенда, проверки, тестовые данные через fixture.php, «человеческий» ввод."""
import json, math, os, pathlib, random, subprocess, sys
from playwright.sync_api import sync_playwright  # noqa: F401 — реэкспорт для тестов

sys.stdout.reconfigure(encoding="utf-8")
BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8091"
SHOTS = pathlib.Path(sys.argv[2] if len(sys.argv) > 2 else ".")
SHOTS.mkdir(parents=True, exist_ok=True)
PHP = os.environ.get("BOOKING_PHP", "C:/cloud/modx-test/php/php.exe -c C:/cloud/modx-test/php/php.ini").split()
FIX = str(pathlib.Path(__file__).with_name("fixture.php"))
LAUNCH = {"channel": "chrome", "args": ["--disable-blink-features=AutomationControlled"]}
# тестовый браузер на своём стенде: снимаем признаки автоматизации, по которым SendIt отсекает ботов
HUMAN = """Object.defineProperty(navigator, 'webdriver', {get: () => false});
if (navigator.userAgentData) Object.defineProperty(navigator, 'userAgentData', {get: () => ({brands: [{brand: 'Google Chrome', version: '141'}], mobile: false, platform: 'Windows'})});
Object.defineProperty(window, 'outerWidth', {get: () => window.innerWidth});
Object.defineProperty(window, 'outerHeight', {get: () => window.innerHeight + 80});"""
fails = []


def check(cond, what):
    print(("  ✓ " if cond else "  ✗ ") + what)
    if not cond:
        fails.append(what)


def fixture(*args):
    out = subprocess.run(PHP + [FIX] + [str(a) for a in args], capture_output=True, text=True, encoding="utf-8").stdout
    return json.loads(out.strip().splitlines()[-1])


def humanize(page, n=80):
    """Случайные кривые движения мыши с неровными паузами."""
    w = page.viewport_size["width"]
    x, y = w / 3, 300
    for _ in range(n):
        a = random.uniform(0, 2 * math.pi)
        x = max(5, min(w - 5, x + math.cos(a) * random.uniform(5, 60)))
        y = max(5, min(850, y + math.sin(a) * random.uniform(5, 60)))
        page.mouse.move(x, y)
        page.wait_for_timeout(random.randint(15, 140))


def console_errors(page):
    errs = []
    page.on("console", lambda m: errs.append(m.text) if m.type == "error" and "favicon" not in (m.location or {}).get("url", "") else None)
    page.on("pageerror", lambda e: errs.append(str(e)))
    return errs


def login(page):
    page.goto(BASE + "/manager/")
    page.fill("#modx-login-username", "admin")
    page.fill("#modx-login-password", "Bania-Test-2026")
    page.click("#modx-login-btn")
    page.wait_for_load_state("networkidle")


def finish():
    print("\nИтог:", "OK" if not fails else f"провалено {len(fails)}")
    sys.exit(1 if fails else 0)
