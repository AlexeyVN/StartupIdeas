/* storage.js — слой хранения: localStorage + offline-очередь.

В MVP «сервер» — это сам браузер, поэтому запись всегда успешна.
Но мы имитируем поведение горничной в номере без сети: если navigator.onLine
=== false, изменения копятся в очереди и «отправляются» при появлении связи
(события online/offline). Очередь видна в статус-баре. */
'use strict';

(function (LT) {
  let db = null;
  const queue = []; // не отправленные действия (имитация)

  function load() {
    try {
      const raw = localStorage.getItem(LT.DB_KEY);
      db = raw ? JSON.parse(raw) : LT.seedDb();
    } catch (e) {
      db = LT.seedDb();
    }
    persist();
    return db;
  }

  function persist() {
    localStorage.setItem(LT.DB_KEY, JSON.stringify(db));
  }

  function resetDemo() {
    localStorage.removeItem(LT.DB_KEY);
    db = LT.seedDb();
    persist();
  }

  /* --- CRUD лотов --- */
  const getLots   = () => db.lots;
  const getLot    = id => db.lots.find(l => l.id === id);
  const getMovements = lotId => db.movements.filter(m => m.lotId === lotId)
                              .sort((a,b) => a.at - b.at);
  const getAudit  = () => [...db.audit].sort((a,b) => b.at - a.at);
  const getRecons = () => db.reconciliations;

  /* Изменение состояния/локации лота. reason обязателен, если новый статус
     входит в REASON_REQUIRED_FOR — иначе бросает ошибку и UI не сохранит. */
  function updateLot(lotId, patch, user, reason, comment) {
    const lot = getLot(lotId);
    if (!lot) throw new Error('Лот не найден: ' + lotId);

    if (patch.status && LT.REASON_REQUIRED_FOR.has(patch.status) && !reason) {
      throw new Error('Нужно указать причину: изменение на «' + LT.STATUSES[patch.status] + '»');
    }

    const entries = [];
    for (const field of ['status','location']) {
      if (patch[field] !== undefined && patch[field] !== lot[field]) {
        entries.push({
          id: LT.nextId('au'), at: Date.now(), userId: user.id, device: detectDevice(),
          entity:'lot', entityId: lot.id, field, was: lot[field], now: patch[field],
          reason: reason || null, comment: comment || '',
        });
      }
    }
    if (!entries.length) return { lot, changed: false };

    Object.assign(lot, patch, { updatedAt: Date.now() });
    db.audit.push(...entries);

    if (patch.location && patch.location !== entries[0]?.was) {
      db.movements.push({
        id: LT.nextId('mv'), lotId: lot.id,
        from: entries.find(e=>e.field==='location')?.was ?? lot.location,
        to: patch.location, byUserId: user.id, at: Date.now(), reason: reason || 'room_move',
      });
    }

    enqueueIfOffline({ type:'updateLot', lotId, patch, user:user.id, reason, comment });
    persist();
    return { lot, changed: true };
  }

  function addReconciliation(rec) {
    db.reconciliations.push(rec);
    persist();
  }

  /* --- offline очередь (имитация) --- */
  function enqueueIfOffline(action) {
    if (!navigator.onLine) queue.push(action);
  }
  function flushQueue() {
    // «Отправка» — в MVP просто очищаем очередь, данные уже в localStorage
    const n = queue.length;
    queue.length = 0;
    return n;
  }

  function detectDevice() {
    const w = window.innerWidth;
    if (w < 720) return 'phone';
    if (w <= 1024) return 'tablet';
    return 'computer';
  }

  /* --- сессия --- */
  function saveSession(userId) { localStorage.setItem(LT.SESSION_KEY, userId); }
  function getSession() {
    const id = localStorage.getItem(LT.SESSION_KEY);
    return LT.USERS.find(u => u.id === id) || null;
  }
  function clearSession() { localStorage.removeItem(LT.SESSION_KEY); }

  window.addEventListener('online',  () => { LT.Store.flushQueue(); LT.renderAll && LT.renderAll(); });
  window.addEventListener('offline', () => { LT.renderAll && LT.renderAll(); });

  LT.Store = {
    load, persist, resetDemo,
    getLots, getLot, getMovements, getAudit, getRecons,
    updateLot, addReconciliation,
    flushQueue, getQueueSize: () => queue.length, isOnline: () => navigator.onLine,
    saveSession, getSession, clearSession, detectDevice,
  };
})(window.LT = window.LT || {});
