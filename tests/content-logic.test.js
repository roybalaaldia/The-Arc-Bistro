const test = require('node:test');
const assert = require('node:assert');
const L = require('../content-logic.js');

const DEFAULT_HOURS = [
  { day: 'mon', status: 'call' },
  ...['tue', 'wed', 'thu', 'fri', 'sat', 'sun'].map(day => ({ day, status: 'open', from: '10:00', to: '21:00' }))
];

test('formatTime', () => {
  assert.strictEqual(L.formatTime('21:00'), '9:00 PM');
  assert.strictEqual(L.formatTime('10:00'), '10:00 AM');
  assert.strictEqual(L.formatTime('00:30'), '12:30 AM');
  assert.strictEqual(L.formatTime('12:00'), '12:00 PM');
});

test('groupHours groups consecutive identical days and lists open first', () => {
  assert.deepStrictEqual(L.groupHours(DEFAULT_HOURS), [
    { label: 'Tuesday – Sunday', value: '10:00 AM – 9:00 PM' },
    { label: 'Monday', value: 'Call ahead' }
  ]);
});

test('groupHours handles gaps and two-day runs', () => {
  const hours = [
    { day: 'mon', status: 'open', from: '09:00', to: '17:00' },
    { day: 'tue', status: 'open', from: '09:00', to: '17:00' },
    { day: 'wed', status: 'closed' },
    { day: 'thu', status: 'open', from: '09:00', to: '17:00' },
    { day: 'fri', status: 'closed' },
    { day: 'sat', status: 'closed' },
    { day: 'sun', status: 'closed' }
  ];
  assert.deepStrictEqual(L.groupHours(hours), [
    { label: 'Monday & Tuesday, Thursday', value: '9:00 AM – 5:00 PM' },
    { label: 'Wednesday, Friday – Sunday', value: 'Closed' }
  ]);
});

test('groupHours with every day closed returns one Closed group', () => {
  const all = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'].map(day => ({ day, status: 'closed' }));
  assert.deepStrictEqual(L.groupHours(all), [{ label: 'Monday – Sunday', value: 'Closed' }]);
});

test('isPromoLive respects active flag and inclusive date window', () => {
  const p = { active: true, start: '2026-10-10', end: '2026-10-17' };
  assert.strictEqual(L.isPromoLive(p, '2026-10-09'), false);
  assert.strictEqual(L.isPromoLive(p, '2026-10-10'), true);
  assert.strictEqual(L.isPromoLive(p, '2026-10-17'), true);
  assert.strictEqual(L.isPromoLive(p, '2026-10-18'), false);
  assert.strictEqual(L.isPromoLive({ ...p, active: false }, '2026-10-12'), false);
  assert.strictEqual(L.isPromoLive({ active: true }, '2026-10-12'), true);
});

test('todayISO uses Manila time', () => {
  assert.strictEqual(L.todayISO(new Date('2026-10-09T17:00:00Z')), '2026-10-10');
});

test('priceSymbols clamps 1..3', () => {
  assert.strictEqual(L.priceSymbols(2), '₱₱');
  assert.strictEqual(L.priceSymbols(0), '₱');
  assert.strictEqual(L.priceSymbols(9), '₱₱₱');
});

test('peso formats with separators', () => {
  assert.strictEqual(L.peso(285), '₱285');
  assert.strictEqual(L.peso(1200), '₱1,200');
  assert.strictEqual(L.peso(99.5), '₱99.5');
});

test('phoneTel strips spacing', () => {
  assert.strictEqual(L.phoneTel('+63 995 109 1503'), '+639951091503');
});

test('formatDateRange', () => {
  assert.strictEqual(L.formatDateRange('2026-10-10', '2026-10-17'), 'Oct 10 – Oct 17');
  assert.strictEqual(L.formatDateRange('', '2026-10-17'), 'Until Oct 17');
  assert.strictEqual(L.formatDateRange('2026-10-10', ''), 'From Oct 10');
  assert.strictEqual(L.formatDateRange('', ''), '');
});

test('frameFor cycles arch, pill, leaf', () => {
  assert.deepStrictEqual([0, 1, 2, 3].map(L.frameFor), ['arch', 'pill', 'leaf', 'arch']);
});
