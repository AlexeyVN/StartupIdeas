/* app.js — вход, роли, вкладки и рендер всех экранов LinenTrack MVP. */
'use strict';

(function (LT) {
  const $ = sel => document.querySelector(sel);
  const esc = s => String(s ?? '').replace(/[&<>"']/g,
    c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  let currentUser = null;   // вошедший пользователь
  let activeTab = null;     // id текущей вкладки роли

  /* ---------- Вкладки по ролям ---------- */
  const TABS = {
    housekeeper: [
      { id:'room',   label:'🛏 Мой номер' },
      { id:'where',  label:'📍 Где всё бельё' },
    ],
    admin: [
      { id:'closet', label:'📦 Склад: приёмка/выдача' },
      { id:'rooms',  label:'🛏 Номера' },
      { id:'diffs',  label:'⚖ Расхождения' },
      { id:'where',  label:'📍 Где всё бельё' },
    ],
    manager: [
      { id:'dash',   label:'📊 Дашборд' },
      { id:'audit',  label:'🕵 Журнал изменений' },
      { id:'diffs',  label:'⚖ Расхождения' },
      { id:'lots',   label:'🧺 Все лоты' },
      { id:'where',  label:'📍 Где всё бельё' },
    ],
  };

  /* ---------- Экран входа ---------- */
  function renderLogin() {
    $('#app-shell').hidden = true;
    $('#login-screen').hidden = false;
    document.body.dataset.role = '';
    const list = $('#user-list');
    list.innerHTML = LT.USERS.map(u => `
      <button class="user-btn" data-uid="${u.id}">
        <b>${esc(u.name)}</b>
        <span class="role-tag">${esc(LT.ROLE_LABELS[u.role])} · ${esc(u.deviceHint)}</span>
      </button>`).join('');
    list.querySelectorAll('.user-btn').forEach(btn =>
      btn.onclick = () => login(btn.dataset.uid));
  }

  function login(uid) {
    currentUser = LT.USERS.find(u => u.id === uid);
    LT.Store.saveSession(currentUser.id);
    $('#login-screen').hidden = true;
    $('#app-shell').hidden = false;
    document.body.dataset.role = currentUser.role;
    activeTab = TABS[currentUser.role][0].id;
    renderAll();
  }

  function logout() {
    currentUser = null;
    LT.Store.clearSession();
    renderLogin();
  }

  /* ---------- Шапка / статус ---------- */
  function renderChrome() {
    $('#current-user').textContent =
      `${currentUser.name} (${LT.ROLE_LABELS[currentUser.role]})`;
    const online = LT.Store.isOnline();
    const badge = $('#offline-badge');
    badge.textContent = online ? 'онлайн' : 'офлайн';
    badge.className = 'badge ' + (online ? 'online' : 'offline');
    const qn = LT.Store.getQueueSize();
    $('#queue-info').textContent = qn
      ? `⏳ Ожидают отправки: ${qn} — данные сохранятся локально и уйдут на сервер при связи`
      : '✓ Все изменения синхронизированы';
    const nav = $('#role-nav');
    nav.innerHTML = TABS[currentUser.role].map(t =>
      `<button class="tab ${t.id===activeTab?'active':''}" data-tab="${t.id}">${t.label}</button>`).join('');
    nav.querySelectorAll('.tab').forEach(b =>
      b.onclick = () => { activeTab = b.dataset.tab; renderAll(); });
  }

  /* ---------- Общий рендер ---------- */
  function renderAll() {
    if (!currentUser) return;
    renderChrome();
    const view = $('#view');
    const fn = {
      room: renderHousekeeperRoom, closet: renderCloset, rooms: renderRooms,
      diffs: renderDiffs, where: renderWhere, dash: renderDashboard,
      audit: renderAudit, lots: renderLots,
    }[activeTab];
    view.innerHTML = fn ? fn() : '<div class="card">Раздел в разработке</div>';
    bindViewEvents(view);
  }
  LT.renderAll = renderAll;

  /* ---------- Горничная: «Мой номер» ---------- */
  const HK_ROOM = { u1: 'room:101', u2: 'room:201' }; // закрепление номеров демо
  function renderHousekeeperRoom() {
    const roomId = HK_ROOM[currentUser.id] || 'room:101';
    const norm = LT.NORMS[roomId] || {};
    const fact = LT.Reports.roomCounts(roomId);
    const lots = LT.Store.getLots().filter(l => l.room === roomId && l.status !== 'written_off');

    const normTable = Object.entries(norm).map(([t, need]) => {
      const have = fact[t]?.ok || 0;
      const cls = have >= need ? 'diff-ok' : 'diff-bad';
      return `<tr><td>${esc(LT.ITEM_TYPES[t])}</td><td>${need}</td>
              <td class="${cls}">${have}</td></tr>`;
    }).join('');

    const tiles = lots.map(l => `
      <div class="card">
        <b>${esc(LT.ITEM_TYPES[l.type])}</b> · ${esc(l.id)}
        <span class="chip ${l.status}">${esc(LT.STATUSES[l.status])}</span>
        <div class="muted">Где сейчас: ${esc(LT.Audit.locLabel(l.location))}</div>
        <div style="margin-top:.4rem">
          <button class="action-tile" data-lot="${l.id}" data-act="dirty">🧺 Сдать в грязное (в прачечную)</button>
          <button class="action-tile" data-lot="${l.id}" data-act="damaged">⚠️ Повреждено — отметить</button>
          <button class="action-tile" data-lot="${l.id}" data-act="missing">❓ Не найдено при проверке</button>
          <button class="action-tile" data-lot="${l.id}" data-act="history">🕒 История: кто и когда менял</button>
        </div>
      </div>`).join('');

    return `
      <div class="card">
        <h2>${esc(LT.Audit.locLabel(roomId))} — норматив и факт</h2>
        <table><tr><th>Позиция</th><th>Норматив</th><th>Есть (чистые)</th></tr>${normTable}</table>
      </div>
      ${tiles}`;
  }

  /* ---------- Админ: склад ---------- */
  function renderCloset() {
    const inCloset = LT.Store.getLots().filter(l => l.location === 'linen_closet' && l.status==='ok');
    const fromLaundry = LT.Store.getLots().filter(l => l.location === 'laundry_vendor');
    const row = l => `<tr>
        <td>${esc(l.id)}</td><td>${esc(LT.ITEM_TYPES[l.type])}</td>
        <td><span class="chip ${l.status}">${esc(LT.STATUSES[l.status])}</span></td>
        <td>${esc(LT.Audit.locLabel(l.location))}</td>
        <td><button class="ghost small" data-lot="${l.id}" data-act="to-room">→ в номер</button></td>
      </tr>`;
    return `
      <div class="card"><h2>Приёмка из прачечной (${fromLaundry.length})</h2>
        <table><tr><th>Лот</th><th class="hide-sm">Тип</th><th>Состояние</th><th class="hide-sm">Где</th><th></th></tr>
        ${fromLaundry.map(row).join('') || '<tr><td colspan=5 class="muted">Пусто</td></tr>'}</table></div>
      <div class="card"><h2>Чистый склад (${inCloset.length})</h2>
        <table><tr><th>Лот</th><th class="hide-sm">Тип</th><th>Состояние</th><th class="hide-sm">Где</th><th></th></tr>
        ${inCloset.map(row).join('') || '<tr><td colspan=5 class="muted">Пусто</td></tr>'}</table></div>`;
  }

  /* ---------- Админ: номера ---------- */
  function renderRooms() {
    return Object.keys(LT.NORMS).map(roomId => {
      const fact = LT.Reports.roomCounts(roomId);
      const rows = Object.entries(LT.NORMS[roomId]).map(([t, need]) => {
        const f = fact[t];
        const ok = f.ok >= need;
        return `<tr><td>${esc(LT.ITEM_TYPES[t])}</td><td>${need}</td>
          <td class="${ok?'diff-ok':'diff-bad'}">${f.ok}</td>
          <td class="muted">грязн. ${f.dirty} · стир. ${f.laundry} · поврежд. ${f.damaged} · нет ${f.missing}</td></tr>`;
      }).join('');
      return `<div class="card"><h2>${esc(LT.Audit.locLabel(roomId))}</h2>
        <table><tr><th>Позиция</th><th>Норм.</th><th>Факт</th><th class="hide-sm">Откуда не хватает</th></tr>${rows}</table></div>`;
    }).join('');
  }

  /* ---------- Расхождения (админ и менеджер) ---------- */
  function renderDiffs() {
    const ds = LT.Reports.discrepancies();
    if (!ds.length) return '<div class="card">✅ Расхождений нет: все номера в норме.</div>';
    const rows = ds.map(d => `
      <tr>
        <td>${esc(LT.Audit.locLabel(d.roomId))}</td>
        <td>${esc(LT.ITEM_TYPES[d.type])}</td>
        <td>${d.need}</td><td>${d.have}</td>
        <td class="diff-bad">${d.diff}</td>
        <td>${d.unexplainedIds.length
            ? `⚠ без причины: ${d.unexplainedIds.map(esc).join(', ')}`
            : 'все позиции объяснены причинами из журнала'}</td>
        <td>${d.lastHolders.map(h =>
            `<button class="ghost small" data-lot="${h.entityId}" data-act="history">🕒 ${esc(h.userName)}</button>`
          ).join(' ') || '—'}</td>
      </tr>`).join('');
    return `<div class="card">
      <h2>⚖ Расхождение норматив ↔ факт</h2>
      <p class="muted">Каждая позиция «минуса» должна иметь причину из журнала.
      Без причины расхождение считается необъяснённым — так система отвечает на вопрос «почему».</p>
      <table><tr><th>Номер</th><th>Позиция</th><th>Норм.</th><th>Факт</th><th>Δ</th>
      <th>Объяснение</th><th class="hide-sm">Кто последний</th></tr>${rows}</table></div>`;
  }

  /* ---------- Где всё бельё (сводка локаций) ---------- */
  function renderWhere() {
    const cards = LT.Reports.whereSummary().map(w => `
      <div class="card">
        <h3>📍 ${esc(w.location.label)}</h3>
        <div><b>${w.total}</b> ед.</div>
        <div class="muted">${Object.entries(w.byType).map(([t,c]) =>
          `${esc(LT.ITEM_TYPES[t])}: ${c}`).join(' · ') || '—'}</div>
      </div>`).join('');
    return `<div class="grid-2">${cards}</div>`;
  }

  /* ---------- Менеджер: дашборд ---------- */
  function renderDashboard() {
    const br = LT.Reports.reasonBreakdown(30);
    const total = br.reduce((s, r) => s + r.count, 0) || 1;
    const bars = br.map(r => `
      <tr><td>${esc(r.reasonLabel)}</td>
      <td>${r.count}</td>
      <td><div style="background:var(--accent);height:10px;border-radius:5px;width:${Math.round(r.count/total*100)}%"></div></td></tr>`).join('');
    const unexplained = LT.Audit.filter({ onlyWithoutReason: true });
    const ds = LT.Reports.discrepancies();
    return `
      <div class="grid-2">
        <div class="card"><h2>Причины списаний/потерь за 30 дней</h2>
          <table>${bars || '<tr><td class="muted">Нет событий</td></tr>'}</table></div>
        <div class="card"><h2>Здоровье учёта</h2>
          <p>Расхождений по номерам: <b class="${ds.length?'diff-bad':'diff-ok'}">${ds.length}</b></p>
          <p>Записей без причины: <b class="${unexplained.length?'diff-bad':'diff-ok'}">${unexplained.length}</b></p>
          <p class="muted">Идея: горничная не может сохранить «не найдено» без причины —
          со временем поле «без причины» должно быть пустым.</p></div>
      </div>`;
  }

  /* ---------- Менеджер: журнал ---------- */
  function renderAudit() {
    const rows = LT.Audit.filter({ sinceDays: 30 }).map(a => `
      <tr>
        <td>${esc(a.timeLabel)}</td>
        <td>${esc(a.userName)} ${esc(a.deviceLabel)}</td>
        <td>${esc(a.entityId)}</td>
        <td>${esc(a.fieldLabel)}</td>
        <td>${esc(a.wasLabel)} → ${esc(a.nowLabel)}</td>
        <td>${esc(a.reasonLabel)}</td>
        <td>${a.comment ? esc(a.comment) : ''}</td>
      </tr>`).join('');
    return `<div class="card">
      <h2>🕵 Кто и когда изменил количество или состояние</h2>
      <p class="muted">Append-only журнал: записи создаются при каждом изменении
      состояния или места лота. Удаление/правка недоступны ни одной роли.</p>
      <table><tr><th>Когда</th><th>Кто · устройство</th><th>Лот</th>
      <th class="hide-sm">Что</th><th>Было → стало</th><th>Причина</th><th class="hide-sm">Коммент.</th></tr>
      ${rows || '<tr><td colspan=7 class="muted">Пусто</td></tr>'}</table></div>`;
  }

  /* ---------- Менеджер: все лоты ---------- */
  function renderLots() {
    const rows = LT.Store.getLots().map(l => `
      <tr>
        <td><button class="ghost small" data-lot="${l.id}" data-act="history">${esc(l.id)}</button></td>
        <td>${esc(LT.ITEM_TYPES[l.type])}</td>
        <td><span class="chip ${l.status}">${esc(LT.STATUSES[l.status])}</span></td>
        <td>${esc(LT.Audit.locLabel(l.location))}</td>
        <td class="muted hide-sm">${esc(new Date(l.updatedAt).toLocaleString('ru-RU'))}</td>
      </tr>`).join('');
    return `<div class="card"><h2>🧺 Все лоты</h2>
      <table><tr><th>Лот / QR</th><th>Тип</th><th>Состояние</th><th>Где</th><th class="hide-sm">Обновлён</th></tr>
      ${rows}</table></div>`;
  }

  /* ---------- Диалог причины ---------- */
  let pendingAction = null;
  function askReason(contextText, cb) {
    pendingAction = cb;
    $('#reason-context').textContent = contextText;
    const sel = $('#reason-select');
    sel.innerHTML = Object.entries(LT.REASONS)
      .map(([k,v]) => `<option value="${k}">${esc(v)}</option>`).join('');
    $('#reason-comment').value = '';
    $('#reason-dialog').showModal();
  }

  /* ---------- Диалог истории лота ---------- */
  function showLotHistory(lotId) {
    const lot = LT.Store.getLot(lotId);
    if (!lot) return;
    $('#lot-title').textContent = `${lot.id} · ${LT.ITEM_TYPES[lot.type]} · сейчас: ${LT.Audit.locLabel(lot.location)}`;
    const h = LT.Audit.historyFor(lotId);
    $('#lot-timeline').innerHTML = h.length
      ? '<ul class="timeline">' + h.map(e => `
          <li><b>${esc(e.timeLabel)}</b> — ${esc(e.userName)} (${esc(e.role)}, ${esc(e.deviceLabel)})
            <div>${esc(e.fieldLabel)}: ${esc(e.wasLabel)} → <b>${esc(e.nowLabel)}</b></div>
            <div class="when">Причина: ${esc(e.reasonLabel || '⚠ не указана')}</div></li>`).join('') + '</ul>'
      : '<p class="muted">Изменений не зафиксировано.</p>';
    $('#lot-dialog').showModal();
  }

  /* Обработчики диалогов вешаются один раз при старте (элементы статичны в index.html) */
  function wireDialogs() {
    $('#reason-form').addEventListener('submit', e => {
      if (e.submitter && e.submitter.value === 'ok') {
        const reason = $('#reason-select').value;
        const comment = $('#reason-comment').value.trim();
        const cb = pendingAction;
        pendingAction = null;
        if (cb) cb(reason, comment);
      } else {
        pendingAction = null;
      }
    });
    $('#lot-close').onclick = () => $('#lot-dialog').close();
  }

  /* ---------- Обработчики кнопок вьюхи ---------- */
  function bindViewEvents(view) {
    view.querySelectorAll('[data-lot]').forEach(btn => {
      btn.onclick = () => {
        const lotId = btn.dataset.lot, act = btn.dataset.act;
        const lot = LT.Store.getLot(lotId);
        if (act === 'history') return showLotHistory(lotId);

        if (act === 'dirty') {
          askReason(`${lotId}: сдать в грязное → отправить в прачечную`, (reason) => {
            apply({ status:'laundry', location:'laundry_vendor' }, reason);
          });
        } else if (act === 'damaged') {
          askReason(`${lotId}: отметить повреждение (статус «Повреждено»)`, (reason, comment) => {
            apply({ status:'damaged', location:'dirty_room' }, reason, comment);
          });
        } else if (act === 'missing') {
          askReason(`${lotId}: не найдено при проверке — система требует причину`, (reason, comment) => {
            apply({ status:'missing' }, reason, comment);
          });
        } else if (act === 'to-room') {
          const target = prompt('В какой номер? (например, room:101)', 'room:101');
          if (target && LT.LOCATIONS.some(l => l.id === target)) {
            askReason(`${lotId}: выдача со склада в ${LT.Audit.locLabel(target)}`, (reason) => {
              apply({ status:'ok', location: target }, reason || 'room_move');
            });
          }
        }
        function apply(patch, reason, comment) {
          try {
            LT.Store.updateLot(lotId, patch, currentUser, reason, comment);
            renderAll();
          } catch (err) { alert(err.message); }
        }
      };
    });
  }

  /* ---------- Инициализация ---------- */
  function init() {
    LT.Store.load();
    wireDialogs();
    $('#btn-reset-demo').onclick = () => { LT.Store.resetDemo(); renderAll(); };
    $('#btn-logout').onclick = logout;
    const s = LT.Store.getSession();
    if (s) { currentUser = s; $('#login-screen').hidden = true; $('#app-shell').hidden = false;
             document.body.dataset.role = s.role; activeTab = TABS[s.role][0].id; renderAll(); }
    else renderLogin();
  }
  document.addEventListener('DOMContentLoaded', init);
})(window.LT = window.LT || {});
