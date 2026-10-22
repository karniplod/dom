"""E2E US6 (quickstart 12): баня отдельно — включается у Дома в админке; гость выбирает «Только баня», дату и часы;
после подтверждения сеанса 15–18 время с 18:00 занято (уборка 30 минут), с 18:30 — свободно.
Запуск: python _build/tests/e2e_us6.py [адрес стенда] [папка скриншотов]"""
import datetime, random
from _e2e import BASE, SHOTS, LAUNCH, HUMAN, check, fixture, console_errors, humanize, finish, sync_playwright

fixture("cleanup")
fixture("addon", "sv", 1500)
day = datetime.date.today() + datetime.timedelta(days=33)
try:
    with sync_playwright() as pw:
        br = pw.chromium.launch(**LAUNCH)
        for width in (1440, 390):
            print(f"\n— ширина {width}")
            ctx = br.new_context(viewport={"width": width, "height": 900})
            ctx.add_init_script(HUMAN)
            page = ctx.new_page()
            errs = console_errors(page)
            page.goto(BASE + "/booking", wait_until="networkidle")
            form = page.locator('main form[data-bk="guest"]')
            form.locator("select[name=house]").select_option("en")
            page.wait_for_timeout(600)
            check(form.locator("[data-bk-kind]").is_hidden(), "у Английского Дома выбора «Только баня» нет")
            form.locator("select[name=house]").select_option("sv")
            page.wait_for_timeout(800)
            check(form.locator("[data-bk-kind]").is_visible(), "у Свиязева — выбор «Дом на сутки / Только баня»")
            form.locator('label:has(input[name=addon][value="1"])').click()
            page.wait_for_timeout(800)
            d = (day + datetime.timedelta(days=0 if width == 1440 else 1)).isoformat()
            for _ in range(3):
                if form.locator(f'[data-day="{d}"]').count():
                    break
                form.locator('[data-cal-nav="1"]').click()
                page.wait_for_timeout(900)
            form.locator(f'[data-day="{d}"]').click()
            page.wait_for_timeout(random.randint(500, 900))
            form.locator("select[name=time_from]").select_option("15:00")
            form.locator("select[name=time_to]").select_option("18:00")
            form.locator("select[name=time_to]").dispatch_event("change")
            page.wait_for_selector(".bk-quote__t")
            q = form.locator("[data-bk-quote]").inner_text().replace("\u00a0", " ")
            check("3 часа" in q and "4 500 ₽" in q, "расчёт: баня 3 часа × 1 500 ₽")
            form.locator("input[name=guests]").fill("5")
            humanize(page, 30)
            form.locator("input[name=name]").type("ТЕСТ Баня", delay=100)
            form.locator("input[name=phone]").type("9005556677", delay=90)
            form.locator("input[name=agree]").check()
            humanize(page, 150)
            form.locator("button[type=submit]").click()
            done = page.locator("main [data-bk-done]")
            done.wait_for(state="visible", timeout=30000)
            text = done.inner_text()
            check("Заявка №" in text and "15:00–18:00" in text, "заявка на баню отправлена: " + text.split("\n")[0])
            page.screenshot(path=str(SHOTS / f"us6-bath-{width}.png"), full_page=True)
            bid = int(text.split("№")[1].split()[0])
            if width == 1440:
                c = fixture("confirm", bid)
                check(c["status"] == 200, "администратор подтвердил сеанс")
                done.locator("[data-bk-again]").click()
                form.locator('label:has(input[name=addon][value="1"])').click()
                page.wait_for_timeout(600)
                for _ in range(3):
                    if form.locator(f'[data-day="{d}"]').count():
                        break
                    form.locator('[data-cal-nav="1"]').click()
                    page.wait_for_timeout(900)
                form.locator(f'[data-day="{d}"]').click()
                page.wait_for_timeout(1500)
                slots = form.locator("[data-bk-slots]").inner_text()
                check("15:00–18:00" in slots and "30 мин" in slots, "форма показывает занятое время и перерыв: " + slots)
            check(not errs, "консоль без ошибок" + ("" if not errs else ": " + "; ".join(errs[:3])))
            ctx.close()
        br.close()

    # пересечения сеансов — через тот же процессор, что и хук формы
    import subprocess, json
    from _e2e import PHP, FIX
    d = day.isoformat()
    def bath(tf, tt):
        php = ("require getenv('BOOKING_CONFIG_CORE') ?: 'C:/cloud/modx-bania/www/config.core.php'; require MODX_CORE_PATH.'vendor/autoload.php';"
               "$_SESSION=[]; $m=new MODX\\Revolution\\modX(); $m->initialize('web'); $b=$m->services->get('booking');"
               "$b->setActor(['role'=>'guest','interface'=>'site']);"
               f"$r=$b->runAction('booking_create_request',['house'=>'sv','addon'=>1,'date_from'=>'{d}','time_from'=>'{tf}','time_to'=>'{tt}','guests'=>2,'name'=>'ТЕСТ Баня 2','phone'=>'+79000000003']);"
               "echo json_encode(['status'=>$r['status']]);")
        out = subprocess.run(PHP + ["-r", php], capture_output=True, text=True, encoding="utf-8").stdout
        return json.loads(out.strip().splitlines()[-1])["status"]
    check(bath("18:00", "20:00") == 409, "с 18:00 — занято (30 минут на уборку)")
    check(bath("18:30", "20:30") == 200, "с 18:30 — можно")
finally:
    fixture("addon", "sv", "off")
    fixture("cleanup")
finish()
