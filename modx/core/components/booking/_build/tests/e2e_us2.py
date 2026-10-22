"""E2E US2 (quickstart 4–7): администратор в админке MODX подтверждает, отменяет, ведёт залог; бронь по телефону.
Запуск: python _build/tests/e2e_us2.py [адрес стенда] [папка скриншотов]
Тестовые заявки создаёт и убирает _build/tests/fixture.php (нужен php стенда: переменная BOOKING_PHP)."""
import json, os, pathlib, subprocess, sys, datetime
from playwright.sync_api import sync_playwright

sys.stdout.reconfigure(encoding="utf-8")
BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8091"
SHOTS = pathlib.Path(sys.argv[2] if len(sys.argv) > 2 else ".")
SHOTS.mkdir(parents=True, exist_ok=True)
PHP = os.environ.get("BOOKING_PHP", "C:/cloud/modx-test/php/php.exe -c C:/cloud/modx-test/php/php.ini").split()
FIX = str(pathlib.Path(__file__).with_name("fixture.php"))
fails = []


def check(cond, what):
    print(("  ✓ " if cond else "  ✗ ") + what)
    if not cond:
        fails.append(what)


def fixture(*args):
    out = subprocess.run(PHP + [FIX] + [str(a) for a in args], capture_output=True, text=True, encoding="utf-8").stdout
    return json.loads(out.strip().splitlines()[-1])


fixture("cleanup")
d0 = datetime.date.today() + datetime.timedelta(days=10)
iso = lambda n: (d0 + datetime.timedelta(days=n)).isoformat()
a = fixture("request", "ru", iso(0), iso(2), 8, "ТЕСТ Админ Первый")["data"]["id"]
b = fixture("request", "ru", iso(1), iso(3), 5, "ТЕСТ Админ Второй")["data"]["id"]

with sync_playwright() as pw:
    br = pw.chromium.launch(channel="chrome")
    page = br.new_page(viewport={"width": 1440, "height": 900})
    errors, dialogs = [], []
    page.on("console", lambda m: errors.append(m.text) if m.type == "error" and "favicon" not in (m.location or {}).get("url", "") else None)
    page.on("pageerror", lambda e: errors.append(str(e)))

    def on_dialog(d):
        dialogs.append(d.message)
        d.accept()
    page.on("dialog", on_dialog)

    page.goto(BASE + "/manager/")
    page.fill("#modx-login-username", "admin")
    page.fill("#modx-login-password", "Bania-Test-2026")
    page.click("#modx-login-btn")
    page.wait_for_load_state("networkidle")
    page.goto(BASE + "/manager/?a=index&namespace=booking", wait_until="networkidle")
    page.wait_for_selector(".bk-g")
    check(page.locator(f'.bk-g [data-open="{a}"]').count() > 0, "новая заявка видна в сетке (кружок новых)")
    page.screenshot(path=str(SHOTS / "us2-grid-before.png"))

    # подтверждение A с неверным залогом, затем с верным
    page.locator(f'.bk-g [data-open="{a}"]').first.click()
    dlg = page.locator(".bk-dialog")
    dlg.locator("form[data-act=confirm]").wait_for()
    check(dlg.locator(".bk-overlaps").count() == 1 and f"№{b}" in dlg.locator(".bk-overlaps").inner_text(), "в карточке видна пересекающаяся заявка")
    dlg.locator("form[data-act=confirm] input[name=deposit_amount]").fill("3000")
    dlg.locator("form[data-act=confirm] button[type=submit]").click()
    page.wait_for_timeout(800)
    invalid = dlg.locator("form[data-act=confirm] input[name=deposit_amount]").evaluate("i => !i.checkValidity()")
    check(invalid and "Новая" in dlg.inner_text(), "залог 3 000 — форма не отправляется, заявка не подтверждена (сервер тоже отказывает — PHP-тесты)")
    dlg.locator("form[data-act=confirm] input[name=deposit_amount]").fill("10000")
    dlg.locator("form[data-act=confirm] button[type=submit]").click()
    page.wait_for_timeout(1500)
    check("Подтверждена" in dlg.inner_text() and "10 000" in dlg.inner_text().replace(" ", " "), "A подтверждена, залог 10 000 — ожидается")
    page.screenshot(path=str(SHOTS / "us2-card.png"))
    dlg.locator("[data-close]").click()

    # сетка: ночи A зелёные
    page.click('[data-tab="grid"]')
    page.wait_for_selector(".bk-g")
    check(page.locator("td.is-ok").count() == 2, "в сетке 2 подтверждённые ночи")

    # подтверждение B — 409
    page.click('[data-tab="list"]')
    page.wait_for_selector(f'tr[data-open="{b}"]')
    page.locator(f'tr[data-open="{b}"]').click()
    dlg.locator("form[data-act=confirm]").wait_for()
    dialogs.clear()
    dlg.locator("form[data-act=confirm] button[type=submit]").click()
    page.wait_for_timeout(1200)
    check(any(f"№{a}" in m for m in dialogs), "подтверждение пересекающейся заявки — отказ с номером брони A")

    # отмена A освобождает ночи, теперь B подтверждается
    page.locator(".bk-dialog__x").click()
    page.locator(f'tr[data-open="{a}"]').click()
    dlg.locator("form[data-act=cancel]").wait_for()
    dlg.locator("form[data-act=cancel] input[name=reason]").fill("ТЕСТ гость передумал")
    dlg.locator("form[data-act=cancel] button[type=submit]").click()
    page.wait_for_timeout(1500)
    check("Отменена" in dlg.inner_text(), "A отменена")
    check("Ожидается" in dlg.inner_text(), "статус залога после отмены сохранился")
    dlg.locator("[data-close]").click()
    page.locator(f'tr[data-open="{b}"]').click()
    dlg.locator("form[data-act=confirm]").wait_for()
    dlg.locator("form[data-act=confirm] button[type=submit]").click()
    page.wait_for_timeout(1500)
    check("Подтверждена" in dlg.inner_text(), "после отмены A подтверждается B")
    dlg.locator("[data-close]").click()

    # бронь по телефону
    page.click('[data-tab="list"]')
    page.locator("[data-manual]").click()
    f = dlg.locator("[data-manual-form]")
    f.locator("select[name=house]").select_option("sv")
    f.locator("input[name=date_from]").fill(iso(5))
    f.locator("input[name=date_to]").fill(iso(6))
    f.locator("input[name=guests]").fill("6")
    f.locator("input[name=guest_name]").fill("ТЕСТ Телефонный")
    f.locator("input[name=guest_phone]").fill("8 912 000 11 22")
    f.locator("button[type=submit]").click()
    page.wait_for_timeout(1800)
    check("Подтверждена" in dlg.inner_text() and "+79120001122" in dlg.inner_text(), "бронь по телефону создана подтверждённой")
    dlg.locator("[data-close]").click()

    # прокрутка длинных вкладок
    page.click('[data-tab="prices"]')
    page.wait_for_timeout(1200)
    scroll = page.evaluate("(() => { const c = document.querySelector('.bk-cmp'); c.scrollTop = 99999; return c.scrollTop > 0; })()")
    check(scroll, "страница админки прокручивается (вкладка «Цены»)")
    for tab in ["houses", "holidays", "blocks", "tokens", "migrate"]:
        page.click(f'[data-tab="{tab}"]')
        page.wait_for_timeout(900)
        check(page.locator(".bk-warn").count() == 0 and page.locator(".bk-msg.is-bad").count() == 0, f"вкладка «{tab}» без ошибок")
    # 409 — ожидаемый ответ API на пересечение, который тест вызывает сам; браузер пишет любой 4xx в консоль
    errors = [e for e in errors if "409 (Conflict)" not in e]
    check(not errors, "консоль без ошибок" + ("" if not errors else ": " + "; ".join(errors[:3])))
    br.close()

fixture("cleanup")
print("\nИтог:", "OK" if not fails else f"провалено {len(fails)}")
sys.exit(1 if fails else 0)
