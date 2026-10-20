/* Интеграционный тест без браузера: мок window/localStorage + загрузка
   data.js, storage.js, audit.js, reports.js. Проверяет три вопроса системы. */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

/* --- мок браузерного окружения --- */
const storeMap = new Map();
const localStorageMock = {
  getItem: k => storeMap.has(k) ? storeMap.get(k) : null,
  setItem: (k, v) => storeMap.set(k, String(v)),
  removeItem: k => storeMap.delete(k),
};
const sandbox = { console, Date, JSON, Object, Array, Set, Map, String, Number, Math, Error, setTimeout };
sandbox.window = sandbox;
sandbox.navigator = { onLine: true };
sandbox.localStorage = localStorageMock;
sandbox.addEventListener = () => {};
sandbox.innerWidth = 1400;
vm.createContext(sandbox);

for (const f of ['data.js', 'storage.js', 'audit.js', 'reports.js']) {
  const code = fs.readFileSync(path.join(__dirname, '..', 'js', f), 'utf8');
  vm.runInContext(code, sandbox, { filename: f });
}

const LT = sandbox.LT;
LT.Store.load();

let passed = 0, failed = 0;
function check(name, cond) {
  if (cond) { passed++; console.log('PASS:', name); }
  else { failed++; console.log('FAIL:', name); }
}

/* === Вопрос 1: ГДЕ === */
const where = LT.Reports.whereSummary();
check('локации посчитаны', where.length === LT.LOCATIONS.length && where.every(w => w.total >= 0));
const r101 = where.find(w => w.location.id === 'room:101');
check('в номере 101 есть вещи', r101.total > 0);
const hist = LT.Audit.historyFor('LOT-001');
check('таймлайн LOT-001 непустой', hist.length >= 3);
check('таймлайн содержит кто/когда/устройство',
  hist.every(h => h.userName && h.timeLabel && h.deviceLabel));

/* === Вопрос 2: КТО и КОГДА (аудит append-only) === */
const admin = LT.USERS.find(u => u.role === 'admin');
const before = LT.Store.getAudit().length;
LT.Store.updateLot('LOT-003', { status: 'dirty' }, admin, 'not_returned', 'тест');
const after = LT.Store.getAudit().length;
check('изменение состояния добавило запись аудита', after === before + 1);
const last = LT.Store.getAudit()[0]; // сортировка по убыванию
check('запись содержит кто/когда/было→стало',
  last.userId === admin.id && !!last.at && last.was === 'ok' && last.now === 'dirty');

/* === Вопрос 3: ПОЧЕМУ — причина обязательна для missing/damaged/written_off === */
let threw = false;
try {
  LT.Store.updateLot('LOT-005', { status: 'missing' }, admin, null, '');
} catch (e) { threw = true; }
check('missing без причины НЕ сохраняется', threw);
check('лот не изменился после отказа', LT.Store.getLot('LOT-005').status !== 'missing');

LT.Store.updateLot('LOT-005', { status: 'missing' }, admin, 'guest_took', '');
check('missing с причиной сохраняется', LT.Store.getLot('LOT-005').status === 'missing');

/* расхождения норматив↔факт */
const ds = LT.Reports.discrepancies();
check('расхождения найдены', ds.length > 0);
check('у расхождений есть need/have/diff',
  ds.every(d => d.need > 0 && typeof d.have === 'number' && d.diff < 0));
const unexplained = LT.Audit.filter({ onlyWithoutReason: true });
check('демо-запись без причины видна менеджеру', unexplained.length >= 1);

/* агрегация причин */
const br = LT.Reports.reasonBreakdown(30);
check('агрегация причин непустая', br.length > 0 && br[0].count >= 1);

/* persistence: «перезагрузка браузера» сохраняет данные */
LT.Store.load();
check('данные переживают перезагрузку', LT.Store.getLot('LOT-005').status === 'missing');

console.log(`\nИТОГО: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
