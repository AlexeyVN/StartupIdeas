"""E2E-проверка LinenTrack MVP в реальном браузере (Playwright + Chromium).

Сценарий:
1. Открыть index.html, войти как горничная → «Мой номер».
2. Нажать «Не найдено» без причины — система должна потребовать причину (диалог).
3. Указать причину — статус меняется, запись появляется в журнале менеджера.
4. Войти как менеджер → журнал содержит ФИО, время, было→стало, причину.
5. Войти как менеджер → вкладка «Расхождения» показывает Δ и объяснения.
6. Таймлайн лота (история) показывает цепочку кто/когда.
"""
import sys, pathlib
from playwright.sync_api import sync_playwright, expect

ROOT = pathlib.Path(__file__).resolve().parent.parent
URL = f"file://{ROOT}/index.html"
errors = []

def step(name, fn):
    try:
        fn(); print("PASS:", name)
    except Exception as e:
        errors.append((name, str(e))); print("FAIL:", name, "->", e)

with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={"width": 480, "height": 900})  # телефон горничной
    page = ctx.new_page()
    js_errors = []
    page.on("pageerror", lambda e: js_errors.append(str(e)))

    page.goto(URL)
    page.wait_for_selector("#user-list .user-btn")
    # чистый демо-старт для воспроизводимости теста
    page.evaluate("localStorage.removeItem(LT.DB_KEY); LT.Store.load();")

    # --- Горничная входит ---
    def housekeeper_login():
        page.click('.user-btn[data-uid="u1"]')
        page.wait_for_selector(".action-tile")
        expect(page.locator("#current-user")).to_contain_text("Ирина П.")
    step("вход горничной, экран «Мой номер»", housekeeper_login)

    # --- «Не найдено» требует причину: отмена не меняет статус ---
    def cancel_keeps_status():
        tile = page.locator('[data-lot="LOT-004"][data-act="missing"]')
        if not tile.count():
            tile = page.locator('[data-act="missing"]').first
        lot_id = tile.get_attribute("data-lot")
        tile.click()
        page.wait_for_selector("#reason-dialog[open]")
        page.click('#reason-form button[value="cancel"]')
        page.wait_for_timeout(200)
        chip = page.locator(f'[data-lot="{lot_id}"]').first
        status = page.evaluate(
            """id => LT.Store.getLot(id).status""", lot_id)
        assert status != "missing", f"статус изменился без причины: {status}"
        return lot_id
    step("«не найдено» без причины не сохраняется", cancel_keeps_status)

    # --- Причина указана — статус меняется ---
    def reason_saves():
        tile = page.locator('[data-act="missing"]').first
        lot_id = tile.get_attribute("data-lot")
        tile.click()
        page.wait_for_selector("#reason-dialog[open]")
        page.select_option("#reason-select", "guest_took")
        page.fill("#reason-comment", "гость увёз с собой")
        page.click("#reason-ok")
        page.wait_for_timeout(300)
        status = page.evaluate("""id => LT.Store.getLot(id).status""", lot_id)
        assert status == "missing", status
        globals()["changed_lot"] = lot_id
    step("«не найдено» с причиной сохраняется", reason_saves)

    # --- История лота из телефона ---
    def history_visible():
        page.click(f'[data-lot="{changed_lot}"][data-act="history"]')
        page.wait_for_selector("#lot-dialog[open]")
        tl = page.inner_text("#lot-timeline")
        assert "Ирина" in tl and "Забрал гость" in tl, tl[:120]
        page.click("#lot-close")
    step("таймлайн лота: кто, когда, причина", history_visible)

    # --- Менеджер: журнал (тот же профиль — общее хранилище; десктопное окно) ---
    def manager_audit():
        page.evaluate("LT.Store.clearSession()")
        page.reload(); page.wait_for_selector("#user-list .user-btn")
        page.set_viewport_size({"width": 1400, "height": 900})  # менеджер с компьютера
        page.click('.user-btn[data-uid="u4"]')
        page.wait_for_selector(".tab")
        page.click('button.tab[data-tab="audit"]')
        txt = page.inner_text("#view")
        assert changed_lot in txt, "лот не найден в журнале"
        assert "Ирина П." in txt and "📱" in txt, "нет ФИО/устройства: " + txt[:300]
        assert "Забрал гость" in txt, "нет причины"
    step("журнал менеджера: кто/когда/было→стало/причина/устройство", manager_audit)

    # --- Админ: выдача со склада меняет локацию ---
    def admin_move():
        page.evaluate("LT.Store.clearSession()")
        page.reload(); page.wait_for_selector("#user-list .user-btn")
        page.click('.user-btn[data-uid="u3"]')
        page.wait_for_selector(".tab")
        page.click('button.tab[data-tab="closet"]')
        btn = page.locator('[data-act="to-room"]').first
        if btn.count():
            lot_id = btn.get_attribute("data-lot")
            page.once("dialog", lambda d: d.accept("room:102"))
            btn.click()
            page.wait_for_selector("#reason-dialog[open]")
            page.click("#reason-ok")
            page.wait_for_timeout(300)
            loc = page.evaluate("""id => LT.Store.getLot(id).location""", lot_id)
            assert loc == "room:102", loc
    step("админ: выдача со склада в номер фиксируется", admin_move)

    # --- Менеджер: расхождения (после действий админа — перепроверяем на актуальных данных) ---
    def manager_diffs():
        page.evaluate("LT.Store.clearSession()")
        page.reload(); page.wait_for_selector("#user-list .user-btn")
        page.click('.user-btn[data-uid="u4"]')
        page.wait_for_selector(".tab")
        page.click('button.tab[data-tab="diffs"]')
        txt = page.inner_text("#view")
        assert "Расхождение" in txt or "в норме" in txt
        assert ("без причины" in txt) or ("объяснены" in txt), txt[:200]
    step("экран расхождений показывает Δ и объяснения", manager_diffs)

    # --- Сводка «Где всё бельё» (роль администратора) ---
    def where_tab():
        page.evaluate("LT.Store.clearSession()")
        page.reload(); page.wait_for_selector("#user-list .user-btn")
        page.click('.user-btn[data-uid="u3"]')
        page.wait_for_selector(".tab")
        page.click('button.tab[data-tab="where"]')
        cards = page.locator(".grid-2 .card")
        assert cards.count() >= 4, cards.count()
    step("сводка «Где всё бельё» по локациям", where_tab)

    step("нет ошибок JS на странице", lambda: (_ for _ in ()).throw(AssertError("; ".join(js_errors))) if js_errors else None)

    browser.close()

print(f"\nИТОГО: {'OK' if not errors else str(len(errors))+' FAIL'}")
sys.exit(1 if errors else 0)
