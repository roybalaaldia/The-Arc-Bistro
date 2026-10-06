(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.ContentLogic = factory();
})(typeof self !== 'undefined' ? self : this, function () {
  const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
  const NAMES = { mon: 'Monday', tue: 'Tuesday', wed: 'Wednesday', thu: 'Thursday', fri: 'Friday', sat: 'Saturday', sun: 'Sunday' };
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  function formatTime(hhmm) {
    const [h, m] = hhmm.split(':').map(Number);
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return h12 + ':' + String(m).padStart(2, '0') + ' ' + (h >= 12 ? 'PM' : 'AM');
  }

  function hoursValue(d) {
    if (d.status === 'open') return formatTime(d.from) + ' – ' + formatTime(d.to);
    return d.status === 'closed' ? 'Closed' : 'Call ahead';
  }

  function runLabel(run) {
    if (run.length === 1) return NAMES[run[0]];
    if (run.length === 2) return NAMES[run[0]] + ' & ' + NAMES[run[1]];
    return NAMES[run[0]] + ' – ' + NAMES[run[run.length - 1]];
  }

  function groupHours(hours) {
    const byDay = {};
    hours.forEach(d => { byDay[d.day] = d; });
    const groups = [];
    DAYS.forEach(day => {
      const d = byDay[day];
      if (!d) return;
      const value = hoursValue(d);
      let g = groups.find(x => x.value === value && x.status === d.status);
      if (!g) { g = { status: d.status, value, days: [] }; groups.push(g); }
      g.days.push(day);
    });
    groups.forEach(g => {
      const runs = [];
      let run = [];
      g.days.forEach(day => {
        if (run.length && DAYS.indexOf(day) !== DAYS.indexOf(run[run.length - 1]) + 1) { runs.push(run); run = []; }
        run.push(day);
      });
      if (run.length) runs.push(run);
      g.label = runs.map(runLabel).join(', ');
    });
    const rank = g => (g.status === 'open' ? 0 : 1);
    return groups
      .map((g, i) => ({ g, i }))
      .sort((a, b) => rank(a.g) - rank(b.g) || a.i - b.i)
      .map(x => ({ label: x.g.label, value: x.g.value }));
  }

  function isPromoLive(p, today) {
    return !!p.active && (!p.start || p.start <= today) && (!p.end || today <= p.end);
  }

  function todayISO(now) {
    return (now || new Date()).toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
  }

  function priceSymbols(level) {
    const n = Math.min(3, Math.max(1, level | 0));
    return '₱'.repeat(n);
  }

  function peso(amount) {
    return '₱' + Number(amount).toLocaleString('en-US', { maximumFractionDigits: 2 });
  }

  function phoneTel(phone) {
    return String(phone).replace(/[^\d+]/g, '');
  }

  function shortDate(iso) {
    const [, m, d] = iso.split('-').map(Number);
    return MONTHS[m - 1] + ' ' + d;
  }

  function formatDateRange(start, end) {
    if (start && end) return shortDate(start) + ' – ' + shortDate(end);
    if (end) return 'Until ' + shortDate(end);
    if (start) return 'From ' + shortDate(start);
    return '';
  }

  function frameFor(i) {
    return ['arch', 'pill', 'leaf'][i % 3];
  }

  return { formatTime, groupHours, isPromoLive, todayISO, priceSymbols, peso, phoneTel, formatDateRange, frameFor };
});
