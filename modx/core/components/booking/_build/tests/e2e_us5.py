"""E2E US5 (quickstart 13, 14): приложение /booking/app — гостевая часть, вкладка администратора только по токену,
подтверждение с телефона с теми же отказами, офлайн без «старой» занятости, Service Worker и манифест.
Запуск: python _build/tests/e2e_us5.py [адрес стенда] [папка скриншотов]"""
import datetime
from _e2e import BASE, SHOTS, LAUNCH, HUMAN, check, fixture, console_errors, humanize, finish, sync_playwright

fixture("cleanup")
d0 = datetime.date.today() + datetime.timedelta(days=12)
iso = lambda n: (d0 + datetime.timedelta(days=n)).isoformat()
a = fixture("request", "en", iso(0), iso(2), 6, "ТЕСТ Приложение Первый")["data"]["id"]
b = fixture("request", "en", iso(1), iso(3), 4, "ТЕСТ Приложение Второй")["data"]["id"]
token = fixture("token", "ТЕСТ телефон e2e")["data"]["token"]

with sync_playwright() as pw:
    br = pw.chromium.launch(**LAUNCH)
    ctx = br.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    ctx.add_init_script(HUMAN)
    page = ctx.new_page()
    errs = console_errors(page)
    dialogs = []
    page.on("dialog", lambda d: (dialogs.append(d.message), d.accept()))
    page.goto(BASE + "/booking/app", wait_until="networkidle")
    check(page.locator('link[rel="manifest"]').count() == 1, "манифест подключён")
    check(page.locator('form[data-bk="guest"]').is_visible(), "вкладка «Забронировать» — форма гостя")
    page.wait_for_function("navigator.serviceWorker && navigator.serviceWorker.ready.then(() => true)")
    page.reload(wait_until="networkidle")
    check(page.evaluate("!!navigator.serviceWorker.controller"), "Service Worker управляет приложением")
    check(page.evaluate("window.siConfig && !!window.siConfig.protectionMap.booking_request"), "SendIt подключён (форма отправится)")

    # без токена администраторской части нет
    page.click('[data-app-tab="admin"]')
    login = page.locator("[data-app-login]")
    check(login.is_visible() and page.locator("[data-app-admin]").is_hidden(), "без токена — только вход, сетки нет")
    login.locator("input[name=token]").fill("неверный-токен-неверный-токен-123")
    login.locator("button[type=submit]").click()
    page.wait_for_timeout(1200)
    check("не подошёл" in login.inner_text(), "неверный токен — отказ")
    login.locator("input[name=token]").fill("abcdefghijklmnopqrstuvwxyz0123456789")
    login.locator("button[type=submit]").click()
    page.wait_for_timeout(1500)
    check("не подошёл" in login.inner_text() and page.locator("[data-app-admin]").is_hidden(), "чужой токен правильного вида — сервер отказывает (401)")
    login.locator("input[name=token]").fill(token)
    login.locator("button[type=submit]").click()
    page.wait_for_selector(".bk-g", timeout=15000)
    check(True, "с токеном — сетка Домов и дат")
    page.screenshot(path=str(SHOTS / "us5-admin-390.png"), full_page=True)

    # подтверждение с телефона, затем пересекающаяся — отказ с номером
    page.locator('[data-tab="list"]').click()
    page.wait_for_selector(f'tr[data-open="{a}"]')
    page.locator(f'tr[data-open="{a}"]').click()
    dlg = page.locator(".bk-dialog")
    dlg.locator("form[data-act=confirm] button[type=submit]").click()
    page.wait_for_timeout(1500)
    check("Подтверждена" in dlg.inner_text(), "подтверждение из приложения работает")
    dlg.locator("[data-close]").click()
    page.locator(f'tr[data-open="{b}"]').click()
    dlg.locator("form[data-act=confirm] button[type=submit]").click()
    page.wait_for_timeout(1500)
    check(any(f"№{a}" in m for m in dialogs), "пересечение — тот же отказ с номером брони")
    dlg.locator("[data-close]").click()
    log = fixture("request", "en", iso(0), iso(1), 2, "ТЕСТ проверка")  # занятые даты и для сайта
    check(log["status"] == 409, "даты, подтверждённые с телефона, заняты и для формы сайта")

    # офлайн: занятость не показывается как актуальная
    page.click('[data-app-tab="book"]')
    ctx.set_offline(True)
    page.evaluate("window.dispatchEvent(new Event('offline'))")
    page.locator('form[data-bk="guest"] select[name=house]').select_option("ru")
    page.wait_for_timeout(1500)
    check(page.locator("[data-app-offline]").is_visible(), "баннер «нет связи»")
    cal = page.locator('form[data-bk="guest"] .bk-cal').inner_text()
    check("Нет связи" in cal and page.locator('form[data-bk="guest"] [data-day]:not([disabled])').count() == 0, "календарь офлайн не даёт выбрать даты")
    page.screenshot(path=str(SHOTS / "us5-offline-390.png"), full_page=True)
    ctx.set_offline(False)

    # выход: токен с устройства удаляется
    page.click('[data-app-tab="admin"]')
    page.locator("[data-app-logout]").click()
    page.wait_for_timeout(500)
    check(page.evaluate("localStorage.getItem('bk-admin-token')") is None, "выход стирает токен с устройства")
    check(not page.evaluate("document.scrollingElement.scrollWidth > window.innerWidth"), "нет горизонтальной прокрутки")
    # ожидаемые ответы: 401 на неверный токен, 409 на пересечение, сетевые ошибки офлайн
    errs = [e for e in errs if not any(x in e for x in ("401 (Unauthorized)", "409 (Conflict)", "ERR_INTERNET_DISCONNECTED", "Failed to fetch"))]
    check(not errs, "консоль без ошибок" + ("" if not errs else ": " + "; ".join(errs[:3])))
    br.close()

fixture("cleanup")
finish()
