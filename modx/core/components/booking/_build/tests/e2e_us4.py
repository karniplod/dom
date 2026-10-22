"""E2E US4 (quickstart 10, 11, 15): администратор меняет цены, задаёт праздник с особой ценой и закрывает даты в админке MODX;
форма гостя сразу видит изменения. Запуск: python _build/tests/e2e_us4.py [адрес стенда] [папка скриншотов]"""
import datetime
from _e2e import BASE, SHOTS, LAUNCH, check, fixture, console_errors, login, finish, sync_playwright

fixture("cleanup")
today = datetime.date.today()
# ближайший четверг не раньше чем через 3 недели — праздник; ближайшая пятница — для цены
thu = today + datetime.timedelta(days=21 + (3 - today.weekday()) % 7)
fri = thu + datetime.timedelta(days=8)
blk = today + datetime.timedelta(days=40)

with sync_playwright() as pw:
    br = pw.chromium.launch(**LAUNCH)
    page = br.new_page(viewport={"width": 1440, "height": 900})
    errs = console_errors(page)
    page.on("dialog", lambda d: d.accept())
    login(page)
    page.goto(BASE + "/manager/?a=index&namespace=booking", wait_until="networkidle")

    def quote(house, d_from, d_to):
        r = page.request.get(BASE + "/assets/components/booking/api.php",
                             params={"action": "booking_quote", "house": house, "date_from": d_from, "date_to": d_to, "guests": 4})
        return r.json()["data"]

    # цены: пятница Свиязева 20 000 → 21 000 и обратно
    page.click('[data-tab="prices"]')
    f = page.locator("form[data-prices]").filter(has=page.locator('input[name=house][value="sv"]'))
    f.locator("input[name=price_friday]").fill("21000")
    f.locator("button[type=submit]").click()
    page.wait_for_selector(".bk-msg.is-ok")
    q = quote("sv", fri.isoformat(), (fri + datetime.timedelta(days=1)).isoformat())
    check(q["subtotal"] == 21000, f"расчёт гостя видит новую цену пятницы ({q['subtotal']})")
    page.wait_for_timeout(5500)  # сообщение об успехе гаснет через 5 с
    f.locator("input[name=price_friday]").fill("20000")
    f.locator("button[type=submit]").click()
    page.wait_for_selector(".bk-msg.is-ok")
    check(quote("sv", fri.isoformat(), (fri + datetime.timedelta(days=1)).isoformat())["subtotal"] == 20000, "цена возвращена")

    # праздник: четверг как суббота, у Русского Дома особая цена 35 000
    page.click('[data-tab="holidays"]')
    hf = page.locator('form[data-holiday=""]')
    hf.locator("input[name=date]").fill(thu.isoformat())
    hf.locator("select[name=holiday_type]").select_option("holiday_before_dayoff")
    hf.locator("input[name=comment]").fill("ТЕСТ праздник")
    hf.locator("input[name=price_ru]").fill("35000")
    hf.locator("button[type=submit]").click()
    page.wait_for_selector(".bk-msg.is-ok")
    nxt = (thu + datetime.timedelta(days=1)).isoformat()
    check(quote("ru", thu.isoformat(), nxt)["subtotal"] == 35000, "Русский Дом в праздник — особая цена 35 000")
    check(quote("en", thu.isoformat(), nxt)["subtotal"] == 27000, "Английский Дом в праздник — по правилу, как суббота")
    page.screenshot(path=str(SHOTS / "us4-holidays.png"), full_page=False)

    # закрытые даты Свиязева: в форме гостя они серые
    page.click('[data-tab="blocks"]')
    bf = page.locator("form[data-block]")
    bf.locator("select[name=house]").select_option("sv")
    bf.locator("input[name=date_from]").fill(blk.isoformat())
    bf.locator("input[name=date_to]").fill((blk + datetime.timedelta(days=2)).isoformat())
    bf.locator("input[name=reason]").fill("ТЕСТ ремонт")
    bf.locator("button[type=submit]").click()
    page.wait_for_selector(".bk-msg.is-ok")
    check(page.locator(".bk-table").inner_text().count("ТЕСТ ремонт") == 1, "закрытие в списке")

    g = br.new_page(viewport={"width": 1440, "height": 900})
    gerrs = console_errors(g)
    g.goto(BASE + "/booking", wait_until="networkidle")
    form = g.locator('main form[data-bk="guest"]')
    form.locator("select[name=house]").select_option("sv")
    g.wait_for_timeout(1000)
    for _ in range(3):
        if form.locator(f'[data-day="{blk.isoformat()}"]').count():
            break
        form.locator('[data-cal-nav="1"]').click()
        g.wait_for_timeout(900)
    d1 = form.locator(f'[data-day="{blk.isoformat()}"]')
    d2 = form.locator(f'[data-day="{(blk + datetime.timedelta(days=1)).isoformat()}"]')
    check("is-busy" in d1.get_attribute("class") and d1.is_disabled(), "первая закрытая ночь серая и не выбирается")
    check("is-busy" in d2.get_attribute("class"), "вторая закрытая ночь серая")
    g.screenshot(path=str(SHOTS / "us4-blocked.png"))
    check(not gerrs, "консоль формы без ошибок")
    check(not errs, "консоль админки без ошибок" + ("" if not errs else ": " + "; ".join(errs[:2])))
    br.close()

fixture("cleanup")
finish()
