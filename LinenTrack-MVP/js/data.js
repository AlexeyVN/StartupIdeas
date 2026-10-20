/* data.js — справочники, модель данных и демо-сид LinenTrack MVP */
'use strict';

const DB_KEY = 'linentrack_db_v1';
const SESSION_KEY = 'linentrack_session_v1';

/* Справочник состояний лота */
const STATUSES = {
  ok:         'В наличии (чистое)',
  dirty:      'Грязное (в номере/на приёмке)',
  laundry:    'В прачечной',
  damaged:    'Повреждено',
  written_off:'Списано',
  missing:    'Не найдено',
};

/* Справочник обязательных причин изменения (вопрос «почему») */
const REASONS = {
  guest_took:     'Забрал гость',
  worn_out:       'Износ / повреждено — списание',
  laundry_missing:'Не довезли из прачечной',
  intake_error:   'Ошибка приёмки',
  not_returned:   'Не сдано за смену',
  room_move:      'Перемещение между локациями',
  found:          'Нашлось при проверке',
};

/* Причины, требующие обязательного пояснения (иначе изменение не сохраняется) */
const REASON_REQUIRED_FOR = new Set(['damaged','written_off','missing']);

const USERS = [
  { id:'u1', name:'Ирина П.',  role:'housekeeper', deviceHint:'телефон' },
  { id:'u2', name:'Ольга С.',  role:'housekeeper', deviceHint:'планшет' },
  { id:'u3', name:'Админ',     role:'admin',       deviceHint:'компьютер' },
  { id:'u4', name:'Менеджер',  role:'manager',     deviceHint:'компьютер' },
];

const ROLE_LABELS = { housekeeper:'Горничная', admin:'Администратор', manager:'Менеджер' };

/* Локации: rooms + склад + прачечная */
const LOCATIONS = [
  { id:'room:101', label:'Номер 101' },
  { id:'room:102', label:'Номер 102' },
  { id:'room:201', label:'Номер 201' },
  { id:'linen_closet', label:'Чистый склад белья' },
  { id:'dirty_room', label:'Комната грязного белья' },
  { id:'laundry_vendor', label:'Прачечная (подрядчик)' },
];

/* Норматив белья на номер (для расчёта расхождений) */
const NORMS = {
  'room:101': { towel_bath:2, towel_face:2, sheet:2, pillowcase:4 },
  'room:102': { towel_bath:2, towel_face:2, sheet:2, pillowcase:4 },
  'room:201': { towel_bath:3, towel_face:3, sheet:3, pillowcase:6 },
};

const ITEM_TYPES = {
  towel_bath:'Полотенце банное', towel_face:'Полотенце лицевое',
  sheet:'Простыня', pillowcase:'Наволочка',
};

let _seq = 1;
const nextId = p => `${p}-${Date.now().toString(36)}-${(_seq++).toString(36)}`;

/* ---------- Демо-данные ---------- */
function seedDb() {
  const now = Date.now();
  const H = 3600e3, D = 24 * H;
  const lots = [];
  let n = 1;
  function mk(type, status, loc, room) {
    return { id:`LOT-${String(n++).padStart(3,'0')}`, qr:`QR${100+n}`, type, status, location:loc, room, updatedAt: now - Math.floor(Math.random()*5)*H };
  }
  // Номер 101: норматив 2/2/2/4. Факт меньше — есть расхождение без причины (демо вопроса «почему»).
  lots.push(mk('towel_bath','ok','room:101','room:101'));
  lots.push(mk('towel_bath','dirty','room:101','room:101'));
  lots.push(mk('towel_face','ok','room:101','room:101'));
  lots.push(mk('towel_face','laundry','laundry_vendor','room:101'));
  lots.push(mk('sheet','ok','room:101','room:101'));
  lots.push(mk('sheet','laundry','laundry_vendor','room:101'));
  for (let i=0;i<4;i++) lots.push(mk('pillowcase','ok','room:101','room:101'));

  // Номер 102: чистовое соответствие
  lots.push(mk('towel_bath','ok','room:102','room:102'));
  lots.push(mk('towel_bath','ok','room:102','room:102'));
  lots.push(mk('towel_face','ok','room:102','room:102'));
  lots.push(mk('towel_face','ok','room:102','room:102'));
  lots.push(mk('sheet','ok','room:102','room:102'));
  lots.push(mk('sheet','ok','room:102','room:102'));
  for (let i=0;i<4;i++) lots.push(mk('pillowcase','ok','room:102','room:102'));

  // Номер 201: одно полотенце помечено missing без причины
  lots.push(mk('towel_bath','ok','room:201','room:201'));
  lots.push(mk('towel_bath','missing','room:201','room:201'));
  lots.push(mk('towel_bath','laundry','laundry_vendor','room:201'));
  lots.push(mk('towel_face','ok','room:201','room:201'));
  lots.push(mk('towel_face','ok','room:201','room:201'));
  lots.push(mk('towel_face','damaged','dirty_room','room:201'));
  lots.push(mk('sheet','ok','room:201','room:201'));
  lots.push(mk('sheet','ok','room:201','room:201'));
  lots.push(mk('sheet','laundry','laundry_vendor','room:201'));
  for (let i=0;i<6;i++) lots.push(mk('pillowcase','ok','room:201','room:201'));

  // Движения и аудит для одного лота — чтобы таймлайн был непустым
  const movements = [
    { id:nextId('mv'), lotId:'LOT-001', from:'linen_closet', to:'room:101', byUserId:'u3', at: now-2*D, reason:'room_move' },
    { id:nextId('mv'), lotId:'LOT-001', from:'room:101', to:'dirty_room', byUserId:'u1', at: now-1*D-3*H, reason:'not_returned' },
    { id:nextId('mv'), lotId:'LOT-001', from:'dirty_room', to:'laundry_vendor', byUserId:'u3', at: now-1*D, reason:'room_move' },
    { id:nextId('mv'), lotId:'LOT-001', from:'laundry_vendor', to:'room:101', byUserId:'u1', at: now-2*H, reason:'intake_error' },
  ];
  const audit = [
    { id:nextId('au'), at: now-2*D, userId:'u3', device:'computer', entity:'lot', entityId:'LOT-001', field:'location', was:'linen_closet', now:'room:101', reason:'room_move' },
    { id:nextId('au'), at: now-1*D-3*H, userId:'u1', device:'phone', entity:'lot', entityId:'LOT-001', field:'status', was:'ok', now:'dirty', reason:'not_returned' },
    { id:nextId('au'), at: now-1*D, userId:'u3', device:'computer', entity:'lot', entityId:'LOT-001', field:'location', was:'dirty_room', now:'laundry_vendor', reason:'room_move' },
    { id:nextId('au'), at: now-2*H, userId:'u1', device:'phone', entity:'lot', entityId:'LOT-001', field:'location', was:'laundry_vendor', now:'room:101', reason:'intake_error' },
    { id:nextId('au'), at: now-5*H, userId:'u2', device:'tablet', entity:'lot', entityId:'LOT-019', field:'status', was:'ok', now:'missing', reason:null /* расхождение без причины — видно менеджеру */ },
  ];

  return { lots, movements, audit, reconciliations: [] };
}

/* Доступ ко всему "база" объекту через глобальный namespace */
window.LT = window.LT || {};
Object.assign(window.LT, {
  DB_KEY, SESSION_KEY, STATUSES, REASONS, REASON_REQUIRED_FOR,
  USERS, ROLE_LABELS, LOCATIONS, NORMS, ITEM_TYPES, nextId, seedDb,
});
