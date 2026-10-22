"""E2E US3 (quickstart 8): гость открывает свою заявку по ссылке или по номеру и телефону, просит отмену.
Запуск: python _build/tests/e2e_us3.py [адрес стенда] [папка скриншотов]"""
import datetime
from _e2e import BASE, SHOTS, LAUNCH, check, fixture, console_errors, finish, sync_playwright

fixture("cleanup")
d0 = datetime.date.today() + datetime.timedelta(days=15)
r = fixture("request", "en", d0.isoformat(), (d0 + datetime.timedelta(days=2)).isoformat(), 7, "ТЕСТ Моя заявка")["data"]
other = fixture("request", "sv", d0.isoformat(), (d0 + datetime.timedelta(days=1)).isoformat(), 3, "ТЕСТ Чужая")["data"]

with sync_playwright() as pw:
    br = pw.chromium.launch(**LAUNCH)
    for width in (1440, 390):
        print(f"\n— ширина {width}")
        page = br.new_page(viewport={"width": width, "height": 900})
        errs = console_errors(page)
        page.on("dialog", lambda d: d.accept())
        page.goto(r["link"].replace("http://localhost:8091", BASE), wait_until="networkidle")
        res = page.locator("[data-bk-result]")
        res.locator(".bk-card").wait_for()
        t = res.inner_text().replace(" ", " ")
        check(f"Заявка №{r['id']}" in t and "Новая" in t and "Английский дом" in t, "по ссылке видна своя заявка")
        if width == 1440:
            res.locator("textarea[name=reason]").fill("ТЕСТ заболели")
            res.locator("[data-bk-cancel] button").click()
            res.locator(".bk-note-orange").wait_for(timeout=20000)  # на стенде нет почты: отправка письма ждёт таймаута
            t = res.inner_text()
            check("попросили отменить" in t, "после запроса — отметка «попросили отменить»")
            check("Новая" in t, "статус прежний — отменяет только администратор")
            check(res.locator("[data-bk-cancel]").count() == 0, "кнопка отмены больше не показывается")
        page.screenshot(path=str(SHOTS / f"us3-request-{width}.png"), full_page=True)

        # по номеру и телефону; чужой телефон — отказ
        page.goto(BASE + "/booking/zayavka", wait_until="networkidle")
        f = page.locator("[data-bk-find]")
        f.locator("input[name=id]").fill(str(other["id"]))
        f.locator("input[name=phone]").type("9001112200")
        f.locator("button[type=submit]").click()
        page.wait_for_timeout(1200)
        check("не найдена" in res.inner_text(), "с чужим телефоном заявка не показывается")
        f.locator("input[name=phone]").fill("")
        f.locator("input[name=phone]").type("9001112233")
        f.locator("button[type=submit]").click()
        page.wait_for_timeout(1200)
        check(f"Заявка №{other['id']}" in res.inner_text(), "по номеру и телефону — показывается")
        check(not page.evaluate("document.scrollingElement.scrollWidth > window.innerWidth"), "нет горизонтальной прокрутки")
        # 403 на чужой телефон — ожидаемый ответ API, браузер пишет его в консоль
        errs = [e for e in errs if "403 (Forbidden)" not in e]
        # SendIt подключает свои скрипты относительным путём — на вложенных страницах 404 (не наш код, см. README)
        errs = [e for e in errs if "404 (Not Found)" not in e] if page.evaluate("[...document.scripts].some(s => s.src.includes('/booking/assets/components/sendit'))") else errs
        check(not errs, "консоль без ошибок" + ("" if not errs else ": " + "; ".join(errs[:3])))
        page.close()
    br.close()

fixture("cleanup")
finish()
