/* reports.js — ответы на три вопроса системы:
   1) ГДЕ     — карточки локаций и таймлайн лота;
   2) КТО/КОГДА — журнал аудита (делегирован в audit.js);
   3) ПОЧЕМУ  — расхождение норматив↔факт по номерам + причины. */
'use strict';

(function (LT) {

  /* ---------- Вопрос 1: ГДЕ ---------- */

  /* Сводка «где что лежит» по всем локациям */
  function whereSummary() {
    const lots = LT.Store.getLots().filter(l => l.status !== 'written_off');
    return LT.LOCATIONS.map(loc => {
      const inLoc = lots.filter(l => l.location === loc.id);
      const byType = {};
      inLoc.forEach(l => { byType[l.type] = (byType[l.type] || 0) + 1; });
      return { location: loc, total: inLoc.length, byType };
    });
  }

  /* Фактическое наличие в номере: считаются только чистые вещи в самом номере
     (ok) — они закрывают норматив; грязное/в прачечной — не закрывает. */
  function roomCounts(roomId) {
    const lots = LT.Store.getLots().filter(l => l.room === roomId && l.status !== 'written_off');
    const counts = {};
    for (const t of Object.keys(LT.ITEM_TYPES)) counts[t] = { ok:0, dirty:0, laundry:0, damaged:0, missing:0 };
    lots.forEach(l => { if (counts[l.type]) counts[l.type][l.status] = (counts[l.type][l.status] || 0) + 1; });
    return counts;
  }

  /* ---------- Вопрос 3: ПОЧЕМУ расхождение ---------- */

  /* Расхождение = норматив - (ok в номере). Для каждой позиции-минуса
     ищем лоты того же номера в статусе missing/damaged без причины — это
     «объяснимые» и «необъяснимые» кандидаты. */
  function discrepancies() {
    const out = [];
    for (const [roomId, norm] of Object.entries(LT.NORMS)) {
      const fact = roomCounts(roomId);
      for (const [type, need] of Object.entries(norm)) {
        const have = fact[type].ok;
        const diff = have - need;
        if (diff >= 0) continue; // расхождения нет
        // кандидаты — лоты номера, выбывшие из оборота
        const candidates = LT.Store.getLots().filter(l =>
          l.room === roomId && l.type === type &&
          ['missing','damaged','laundry','dirty'].includes(l.status));
        const unexplained = candidates.filter(l => {
          const last = LT.Audit.historyFor(l.id).slice(-1)[0];
          return !last || !last.reason;
        });
        out.push({
          roomId, type, need, have, diff,
          candidateIds: candidates.map(c => c.id),
          unexplainedIds: unexplained.map(u => u.id),
          lastHolders: unexplained.map(l => LT.Audit.lastHolder(l.id)).filter(Boolean),
        });
      }
    }
    return out;
  }

  /* Агрегация причин по журналу — дашборд менеджера */
  function reasonBreakdown(days = 30) {
    const rows = LT.Audit.filter({ sinceDays: days });
    const map = {};
    rows.forEach(r => {
      if (!LT.REASON_REQUIRED_FOR.has(r.now)) return;
      const key = r.reason || '__none__';
      map[key] = (map[key] || 0) + 1;
    });
    return Object.entries(map).map(([k, v]) => ({
      reasonKey: k,
      reasonLabel: k === '__none__' ? '⚠ Причина не указана' : LT.REASONS[k],
      count: v,
    })).sort((a, b) => b.count - a.count);
  }

  LT.Reports = { whereSummary, roomCounts, discrepancies, reasonBreakdown };
})(window.LT = window.LT || {});
