/* audit.js — неизменяемый (append-only) журнал изменений.

Правило продукта: на каждый вопрос «кто и когда изменил количество или
состояние» отвечает этот модуль. Записи создаёт storage.updateLot; здесь —
чтение, фильтрация и человекочитаемое представление. Подделать/удалить запись
из UI нельзя — API удаления отсутствует намеренно. */
'use strict';

(function (LT) {
  const userById = id => LT.USERS.find(u => u.id === id);
  const locLabel = id => LT.LOCATIONS.find(l => l.id === id)?.label || id;
  const fmtTime  = ts => new Date(ts).toLocaleString('ru-RU');

  /* Полная цепочка изменений одного лота (для таймлайна в карточке «Где») */
  function historyFor(lotId) {
    return LT.Store.getAudit()
      .filter(a => a.entityId === lotId)
      .sort((a, b) => a.at - b.at)
      .map(a => ({
        ...a,
        userName: userById(a.userId)?.name || a.userId,
        role: LT.ROLE_LABELS[userById(a.userId)?.role] || '',
        deviceLabel: { phone:'📱 телефон', tablet:'📲 планшет', computer:'💻 компьютер' }[a.device] || a.device,
        fieldLabel: a.field === 'status' ? 'состояние' : a.field === 'location' ? 'место' : a.field,
        wasLabel: a.field === 'location' ? locLabel(a.was) : (LT.STATUSES[a.was] || a.was),
        nowLabel: a.field === 'location' ? locLabel(a.now) : (LT.STATUSES[a.now] || a.now),
        reasonLabel: a.reason ? LT.REASONS[a.reason] : null,
        timeLabel: fmtTime(a.at),
      }));
  }

  /* Изменения за период/по пользователю — экран аудита менеджера */
  function filter({ userId = null, sinceDays = null, onlyWithoutReason = false } = {}) {
    let rows = LT.Store.getAudit();
    if (userId) rows = rows.filter(r => r.userId === userId);
    if (sinceDays) rows = rows.filter(r => r.at >= Date.now() - sinceDays * 864e5);
    if (onlyWithoutReason) rows = rows.filter(r =>
      LT.REASON_REQUIRED_FOR.has(r.now) && !r.reason);
    return rows.map(a => ({
      ...a,
      userName: userById(a.userId)?.name || a.userId,
      deviceLabel: { phone:'📱', tablet:'📲', computer:'💻' }[a.device] || '',
      fieldLabel: a.field === 'status' ? 'состояние' : 'место',
      wasLabel: a.field === 'location' ? locLabel(a.was) : (LT.STATUSES[a.was] || a.was),
      nowLabel: a.field === 'location' ? locLabel(a.now) : (LT.STATUSES[a.now] || a.now),
      reasonLabel: a.reason ? LT.REASONS[a.reason] : '⚠ не указана',
      timeLabel: fmtTime(a.at),
    }));
  }

  /* Кто последним держал лот в руках (усилие ответа «почему расхождение») */
  function lastHolder(lotId) {
    const h = historyFor(lotId);
    return h.length ? h[h.length - 1] : null;
  }

  LT.Audit = { historyFor, filter, lastHolder, userById, locLabel, fmtTime };
})(window.LT = window.LT || {});
