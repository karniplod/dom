"""E2E переноса прежних заявок (quickstart 16): «Перенос заявок» в админке — предпросмотр, перенос, повтор без дублей.
Запуск: python _build/tests/e2e_migrate.py [адрес стенда] [папка скриншотов]
Прежние заявки стенда (bania_leads) читаются только на чтение; перенесённые брони тест удаляет за собой."""
import json, subprocess
from _e2e import BASE, SHOTS, LAUNCH, PHP, check, console_errors, login, finish, sync_playwright


def sql(q):
    php = ("require 'C:/cloud/modx-bania/www/config.core.php'; require MODX_CORE_PATH.'vendor/autoload.php';"
           "$m=new MODX\\Revolution\\modX(); $m->initialize('mgr'); $p=$m->getOption('table_prefix');"
           f"$st=$m->query(str_replace('{{p}}',$p,{json.dumps(q)})); echo json_encode($st?$st->fetchAll(PDO::FETCH_ASSOC):[]);")
    out = subprocess.run(PHP + ["-r", php], capture_output=True, text=True, encoding="utf-8").stdout
    return json.loads(out.strip().splitlines()[-1] if out.strip() else "[]")


def drop_migrated():
    sql("DELETE n FROM {p}booking_nights n JOIN {p}booking_bookings b ON b.id = n.booking_id WHERE b.source = 'migrated'")
    sql("DELETE FROM {p}booking_bookings WHERE source = 'migrated'")


leads = sql("SELECT id, status FROM {p}bania_leads ORDER BY id")
check(len(leads) > 0, f"на стенде есть прежние заявки: {len(leads)}")
drop_migrated()
with sync_playwright() as pw:
    br = pw.chromium.launch(**LAUNCH)
    page = br.new_page(viewport={"width": 1440, "height": 900})
    errs = console_errors(page)
    page.on("dialog", lambda d: d.accept())
    login(page)
    page.goto(BASE + "/manager/?a=index&namespace=booking", wait_until="networkidle")
    page.click('[data-tab="migrate"]')
    out = page.locator("[data-mout]")
    page.click('[data-m="preview"]')
    out.locator("table").wait_for()
    check(out.inner_text().count("будет перенесена") == len(leads), "предпросмотр: все прежние заявки к переносу")
    page.click('[data-m="run"]')
    page.wait_for_function("document.querySelector('[data-mout]').textContent.includes('Перенесено')")
    t = out.inner_text()
    check(f"Перенесено: {len(leads)}" in t or "конфликтом" in t, "перенос: " + t.split("\n")[0])
    page.screenshot(path=str(SHOTS / "migrate-run.png"))
    page.click('[data-m="run"]')
    page.wait_for_timeout(1500)
    t2 = out.inner_text()
    check("Перенесено: 0" in t2 and t2.count("уже перенесена") == len(leads), "повторный перенос ничего не дублирует")
    check(not errs, "консоль без ошибок")
    br.close()

rows = sql("SELECT legacy_lead_id, status, admin_note FROM {p}booking_bookings WHERE source = 'migrated' ORDER BY legacy_lead_id")
MAP = {"new": "new", "contacted": "new", "confirmed": "confirmed", "done": "completed", "declined": "cancelled"}
for lead, row in zip(leads, rows):
    exp = MAP.get(lead["status"], "new")
    ok = row["status"] == exp or (exp == "confirmed" and row["status"] == "new" and "Не подтверждена при переносе" in (row["admin_note"] or ""))
    check(ok and int(row["legacy_lead_id"]) == int(lead["id"]), f"заявка №{lead['id']}: {lead['status']} → {row['status']}")
contacted = [r for l, r in zip(leads, rows) if l["status"] == "contacted"]
check(all("связались" in (r["admin_note"] or "") for r in contacted), "«связались» — с заметкой")
check(len(sql("SELECT id FROM {p}bania_leads")) == len(leads), "прежние заявки не тронуты (только чтение)")
drop_migrated()
finish()
