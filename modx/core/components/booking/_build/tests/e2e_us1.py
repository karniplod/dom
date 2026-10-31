"""E2E US1 (quickstart 1–3, 9, 18): гость выбирает Дом и даты, видит расчёт, отправляет заявку через SendIt.
Запуск: python _build/tests/e2e_us1.py [адрес стенда] [папка скриншотов]
Почта на стенде не настроена — это и есть сценарий 18: заявка сохраняется, гость видит номер."""
import urllib.parse, sys, datetime, pathlib, random, math
from playwright.sync_api import sync_playwright

sys.stdout.reconfigure(encoding="utf-8")
BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8091"
SHOTS = pathlib.Path(sys.argv[2] if len(sys.argv) > 2 else ".")
SHOTS.mkdir(parents=True, exist_ok=True)
fails = []


def check(cond, what):
    print(("  ✓ " if cond else "  ✗ ") + what)
    if not cond:
        fails.append(what)


def humanize(page, n=80):
    """Случайные кривые движения мыши с неровными паузами — как у человека (иначе SendIt примет за бота)."""
    w = page.viewport_size["width"]
    x, y = w / 3, 300
    for _ in range(n):
        a = random.uniform(0, 2 * math.pi)
        x = max(5, min(w - 5, x + math.cos(a) * random.uniform(5, 60)))
        y = max(5, min(850, y + math.sin(a) * random.uniform(5, 60)))
        page.mouse.move(x, y)
        page.wait_for_timeout(random.randint(15, 140))


def pick_range(page, form, nights, start_offset):
    """Выбрать заезд не раньше чем через start_offset дней и выезд через nights ночей (свободные даты)."""
    page.wait_for_function("f => !f.querySelector('.bk-cal').textContent.includes('Загружаем')", arg=form.element_handle())
    want = (datetime.date.today() + datetime.timedelta(days=start_offset))
    for _ in range(4):
        days = form.locator("[data-day]:not([disabled])").evaluate_all("els => els.map(e => e.dataset.day)")
        for d in days:
            if d >= want.isoformat():
                out = (datetime.date.fromisoformat(d) + datetime.timedelta(days=nights)).isoformat()
                form.locator(f'[data-day="{d}"]').click()
                page.wait_for_timeout(random.randint(300, 900))
                if form.locator(f'[data-day="{out}"]').count() == 0:
                    form.locator('[data-cal-nav="1"]').click()
                    page.wait_for_timeout(600)
                btn = form.locator(f'[data-day="{out}"]')
                if btn.count() and btn.is_enabled():
                    btn.click()
                    return d, out
                form.locator(f'[data-day="{d}"]').click()  # сброс
        form.locator('[data-cal-nav="1"]').click()
        page.wait_for_timeout(600)
    raise RuntimeError("нет свободных дат")


# тестовый браузер на своём стенде: снимаем признаки автоматизации, по которым SendIt отсекает ботов
HUMAN = """Object.defineProperty(navigator, 'webdriver', {get: () => false});
if (navigator.userAgentData) Object.defineProperty(navigator, 'userAgentData', {get: () => ({brands: [{brand: 'Google Chrome', version: '141'}], mobile: false, platform: 'Windows'})});
Object.defineProperty(window, 'outerWidth', {get: () => window.innerWidth});
Object.defineProperty(window, 'outerHeight', {get: () => window.innerHeight + 80});"""

import os, subprocess
PHP = os.environ.get("BOOKING_PHP", "C:/cloud/modx-test/php/php.exe -c C:/cloud/modx-test/php/php.ini").split()
FIX = str(pathlib.Path(__file__).with_name("fixture.php"))
cleanup = lambda: subprocess.run(PHP + [FIX, "cleanup"], capture_output=True)   # заявки «ТЕСТ …» прошлых прогонов: иначе повтор той же заявки отклоняется
cleanup()

with sync_playwright() as pw:
    br = pw.chromium.launch(channel="chrome", args=["--disable-blink-features=AutomationControlled"])
    for width in (1440, 390):
        print(f"\n— ширина {width}")
        ctx = br.new_context(viewport={"width": width, "height": 900}, locale="ru-RU")
        ctx.add_init_script(HUMAN)
        page = ctx.new_page()
        errors = []
        page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)
        page.on("pageerror", lambda e: errors.append(str(e)))
        page.goto(BASE + "/booking", wait_until="networkidle")
        page.evaluate("document.addEventListener('si:send:after', e => { window.__si = e.detail && e.detail.result && e.detail.result.message; })")
        form = page.locator('main form[data-bk="guest"]')
        check(form.count() == 1, "форма booking.form на странице /booking")
        form.locator("select[name=house]").select_option("ru")
        page.wait_for_timeout(800)
        busy_before = form.locator(".bk-cal__day.is-busy").count()
        d_in, d_out = pick_range(page, form, 2, 20 if width == 1440 else 40)
        check(form.locator("input[name=date_from]").input_value() == d_in, f"заезд {d_in} в скрытом поле")
        check(form.locator("input[name=date_to]").input_value() == d_out, f"выезд {d_out} в скрытом поле")
        page.wait_for_timeout(random.randint(400, 1100))
        form.locator("input[name=guests]").fill("14")
        form.locator("input[name=guests]").dispatch_event("change")
        page.wait_for_selector(".bk-quote__t", timeout=8000)
        quote = form.locator("[data-bk-quote]").inner_text()
        check("Гости сверх 10" in quote and "Скидка" not in quote, "расчёт: доплата за 4 гостей, скидки на сайте нет")
        vk = form.locator(".bk-vk a")
        check(vk.count() == 1 and "vk.com" in vk.get_attribute("href"), "кнопка «Мы во ВКонтакте» ведёт в группу")
        humanize(page, 30)
        form.locator("input[name=name]").type("ТЕСТ Браузер", delay=110)
        form.locator("input[name=phone]").type("90012345" + ("67" if width == 1440 else "68"), delay=90)
        check(form.locator("input[name=phone]").input_value().startswith("+7 (900) 123-45-6"), "маска телефона")
        form.locator("input[name=agree]").check()
        humanize(page, 150)  # и заодно больше 3 с заполнения (minFillTime SendIt)
        form.locator("button[type=submit]").click()
        done = page.locator("main [data-bk-done]")
        try:
            done.wait_for(state="visible", timeout=30000)
        except Exception:
            print("  ответ SendIt:", page.evaluate("window.__si"))
            raise
        text = done.inner_text()
        check("Заявка №" in text and "отправлена" in text, "гость видит номер заявки: " + text.split("\n")[0])
        check("Ссылка на заявку" in text, "и ссылку на неё")
        # окно «сначала позвоните, потом предоплата»: само и по клику мимо не закрывается
        dlg = page.locator("dialog.bk-pp")
        dlg.wait_for(state="visible", timeout=5000)
        dt = dlg.inner_text()
        check("Сначала позвоните администратору" in dt and "50%" in dt, "окно: сначала звонок, потом предоплата 50%")
        check(dlg.locator("a[href^='tel:']").count() >= 1, "в окне кнопка «Позвонить администратору»")
        pay = dlg.locator("[data-pp-pay]")
        check(pay.count() == 1 and "Предоплата" in urllib.parse.unquote(pay.get_attribute("href") or ""), "кнопка «Внести предоплату по СБП» ведёт на страницу предоплаты Дома")
        page.screenshot(path=str(SHOTS / f"us1-prepay-{width}.png"))
        page.mouse.click(5, 5)
        page.wait_for_timeout(300)
        check(dlg.is_visible(), "клик мимо окна его не закрывает")
        dlg.locator(".bk-pp__x").click()
        page.wait_for_timeout(300)
        check(page.locator("dialog.bk-pp").count() == 0, "крестик закрывает окно")
        check("Сначала позвоните" in done.inner_text(), "шаги остаются в блоке «Заявка отправлена»")
        page.screenshot(path=str(SHOTS / f"us1-done-{width}.png"), full_page=True)

        # вторая заявка на те же даты принимается (новая заявка даты не занимает)
        done.locator("[data-bk-again]").click()
        form.locator("select[name=house]").select_option("ru")
        page.wait_for_timeout(1000)
        check(form.locator(f'[data-day="{d_in}"]').is_enabled(), "даты первой заявки остались свободными в календаре")
        form.locator(f'[data-day="{d_in}"]').click()
        page.wait_for_timeout(random.randint(400, 900))
        form.locator(f'[data-day="{d_out}"]').click()
        form.locator("input[name=guests]").fill("6")
        form.locator("input[name=phone]").fill("")
        form.locator("input[name=phone]").type("90012345" + ("77" if width == 1440 else "78"), delay=60)
        humanize(page, 150)
        form.locator("button[type=submit]").click()
        done.wait_for(state="visible", timeout=30000)
        check("Заявка №" in done.inner_text(), "вторая заявка на те же даты (другой гость) принята")
        page.locator("dialog.bk-pp [data-pp-close]").last.click()

        page.goto(BASE + "/booking", wait_until="networkidle")
        page.evaluate("window.scrollTo(0, document.body.scrollHeight)")
        page.wait_for_timeout(400)
        hscroll = page.evaluate("document.scrollingElement.scrollWidth > window.innerWidth")
        check(not hscroll, "нет горизонтальной прокрутки")
        page.evaluate("window.scrollTo(0, 0)")
        page.locator('main form[data-bk="guest"] select[name=house]').select_option("en")
        page.wait_for_timeout(900)
        page.screenshot(path=str(SHOTS / f"us1-form-{width}.png"), full_page=True)
        errs = [e for e in errors if "favicon" not in e]
        check(not errs, "консоль без ошибок" + ("" if not errs else ": " + "; ".join(errs[:3])))
        ctx.close()
    br.close()

cleanup()
print("\nИтог:", "OK" if not fails else f"провалено {len(fails)}")
sys.exit(1 if fails else 0)
