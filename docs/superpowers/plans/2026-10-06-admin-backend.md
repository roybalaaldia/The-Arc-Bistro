# The ARC Bistro Admin Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A logged-in admin (PHP, on Hostinger) that edits one `content.json`, which the unchanged-layout public page fetches and renders.

**Architecture:** Static `index.html` keeps its built-in default text and, on load, `main.js` fetches `content.json` and swaps in saved values (plus new full-menu and promo blocks). A PHP admin under `/admin/` validates input per section, role-checks every request, writes `content.json` atomically with a backup copy, and processes uploaded images. No database; no framework.

**Tech Stack:** HTML/CSS/vanilla JS (public page and admin UI), PHP 8.1+ with GD, mbstring, openssl (admin), PHPMailer (vendored, SMTP for reset email), Node 20+ `node:test` (JS logic tests), plain-PHP self-check script (PHP tests).

**Spec:** `docs/superpowers/specs/2026-10-06-admin-backend-design.md`

## Global Constraints

- Hosting: Hostinger shared hosting, PHP + HTTPS. No database, no Node on the server, no Composer required (vendor PHPMailer by copying files).
- The public page layout must not change: with default `content.json` the page must look the same as today; with no live promos and no menu categories the new blocks do not appear.
- Admin can change text, numbers, links and images only; never HTML or CSS.
- Roles: `owner`, `staff` (menu and promos only), `developer` (everything + System, hidden from Owner views). Enforced server-side per endpoint.
- Passwords: `password_hash`, minimum length 10. Session idle timeout 30 minutes. Login lockout: 5 failed attempts locks that username + IP for 10 minutes. Reset link: single-use, valid 30 minutes, only a hash stored, generic response always.
- Every state-changing request requires a CSRF token.
- Images: JPG/PNG/WebP, max 5 MB, real-type check, re-encode with GD, max 1600 px long edge, random filename, stored in `uploads/`.
- Limits: dish name 40, tag 30, dish description 140, category name 30, menu item name 50, item description 100, promo title 50, promo details 200. Max 8 featured dishes, 8 categories, 20 items per category, 6 promos. Item status: `available` | `hidden` | `soldout`.
- All content is inserted into the page as text (`textContent`), never as HTML.
- Currency is `₱` with thousands separators. Dates are ISO `YYYY-MM-DD`. Time zone for "today": `Asia/Manila`.
- Image paths accepted anywhere in content: `^(assets|uploads)/[A-Za-z0-9._%/-]+\.(jpg|jpeg|png|webp)$` (case-insensitive), no `..`. Spaces are stored as `%20`.
- Social/link URLs: `https://` only.

## Review Focus

Inputs and conditions the spec implies but does not spell out, most likely first. Each has a test in the task that owns the code.

1. `content.json` missing, empty or invalid JSON: public page must still show the built-in default text (Task 2).
2. Dish name or description containing `<script>` or `&`: must display as literal text, never execute (Task 2).
3. A Staff user posting directly to the `hours`, `contact`, `price` or `social` section: must get 403 and change nothing (Task 5).
4. A file renamed `.jpg` that is really text or PHP: must be rejected and nothing written to `uploads/` (Task 6).
5. Two people saving at the same time: the second save must be refused with "someone else changed this, reload" instead of silently overwriting (Task 5).
6. Promo on its last day (`end` equals today) is still shown; promo with `end` before `start` is rejected (Tasks 1 and 3).
7. All seven days set to Closed, or hours with closing time not after opening time (Tasks 1 and 3).
8. Login with an unknown username must take the same path and message as a wrong password (Task 4).

## File Structure

```
public_html/                         (= project root today)
  index.html                         MODIFY: data-bind attributes, new promos + fullmenu markup, script tags
  style.css                          MODIFY: fullmenu + promos styles
  main.js                            MODIFY: wrap in start(), fetch content.json first
  content-logic.js                   CREATE: pure functions (hours grouping, promo window, formatting)
  render.js                          CREATE: DOM binding of content.json into the page
  content.json                       CREATE: default content (matches today's page)
  tests/content-logic.test.js        CREATE: node:test unit tests for content-logic.js
  uploads/.gitkeep                   CREATE
  uploads/.htaccess                  CREATE: no PHP execution
  .htaccess                          CREATE: HTTPS redirect, security headers
  tools/                             TEMPORARY one-time scripts (deleted after use)
  admin/
    .htaccess                        CREATE: no-store, CSP, deny lib/ and tests/
    index.php                        CREATE: login page + forgot link
    setup.php                        CREATE: one-time first accounts
    forgot.php, reset.php            CREATE: email reset flow
    logout.php                       CREATE
    app.php                          CREATE: dashboard shell
    api.php                          CREATE: JSON dispatcher (auth + csrf + role + handler)
    lib/
      config.php                     CREATE: paths (overridable for tests)
      page.php                       CREATE: HTML helpers for login/setup/reset pages
      reset.php                      CREATE: reset tokens (create, look up, consume)
      util.php                       CREATE: json, logging, clean strings
      store.php                      CREATE: read/write content, backups, restore
      validate.php                   CREATE: per-section validation + cleaning
      auth.php                       CREATE: users, sessions, csrf, lockout, roles, reset tokens
      images.php                     CREATE: upload processing
      mail.php                       CREATE: PHPMailer wrapper
      handlers.php                   CREATE: API action handlers
      vendor/PHPMailer/              CREATE: vendored (Exception.php, PHPMailer.php, SMTP.php)
    assets/
      admin.css, app.js              CREATE: shell, helpers, tab router
      tab-menu.js, tab-promos.js, tab-hours.js, tab-history.js, tab-accounts.js, tab-system.js   CREATE
    data/                            PROTECTED (.htaccess "Require all denied")
      .htaccess                      CREATE
      (users.json, resets.json, attempts.json, smtp.json, setup.lock, backups/, log/ created at runtime)
    tests/check.php                  CREATE: plain-PHP self-check script
```

Run commands: from the project root, PHP tests `php admin/tests/check.php`; JS tests `node --test tests/`; local site `php -S localhost:8080` (serves public page and admin together).

---

### Task 0: Local tools

**Files:** none (environment only)

- [ ] **Step 1: Check what is installed**

Run: `node -v && php -v && php -m | findstr /i "gd mbstring openssl json"`
Expected: Node 20+ prints a version. If `php` is not found, continue to Step 2.

- [ ] **Step 2: Install PHP 8.1+ (only if missing)**

Install from https://windows.php.net/download (thread-safe x64 zip), unzip to `C:\php`, copy `php.ini-development` to `php.ini`, then in `php.ini` uncomment these lines: `extension=gd`, `extension=mbstring`, `extension=openssl`, `extension=fileinfo`, `extension_dir = "ext"`. Add `C:\php` to PATH and open a new terminal.

- [ ] **Step 3: Verify**

Run: `php -v && php -m | findstr /i "gd mbstring openssl fileinfo"`
Expected: PHP 8.1+ and all four extensions listed.

---

### Task 1: Content logic (pure functions) with tests

**Files:**
- Create: `content-logic.js`
- Test: `tests/content-logic.test.js`

**Interfaces:**
- Produces (`ContentLogic`, available as `window.ContentLogic` in the browser and `require('./content-logic.js')` in Node):
  - `formatTime(hhmm: string): string`, `"21:00"` -> `"9:00 PM"`
  - `groupHours(hours: Array<{day, status, from?, to?}>): Array<{label: string, value: string}>`
  - `isPromoLive(promo: {active, start?, end?}, todayISO: string): boolean`
  - `todayISO(now?: Date): string` (Asia/Manila date)
  - `priceSymbols(level: number): string`
  - `peso(amount: number): string`
  - `phoneTel(phone: string): string`
  - `formatDateRange(start?: string, end?: string): string`
  - `frameFor(index: number): 'arch' | 'pill' | 'leaf'`

- [ ] **Step 1: Write the failing tests**

Create `tests/content-logic.test.js`:

```js
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
```

- [ ] **Step 2: Run to verify failure**

Run: `node --test tests/`
Expected: FAIL, `Cannot find module '../content-logic.js'`

- [ ] **Step 3: Implement**

Create `content-logic.js`:

```js
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
```

- [ ] **Step 4: Run to verify pass**

Run: `node --test tests/`
Expected: all tests PASS

- [ ] **Step 5: Checkpoint**

Git is not set up in this project. If it is later, commit `content-logic.js` and `tests/content-logic.test.js` with message `feat: content logic with tests`.

---

### Task 2: Default content and public page rendering

**Files:**
- Create: `content.json`, `render.js`
- Modify: `index.html` (binding attributes, new blocks, script tags), `main.js` (wrap in `start()`), `style.css` (new blocks)

**Interfaces:**
- Consumes: `ContentLogic` from Task 1.
- Produces: `window.renderContent(content: object): void`, which fills the page from a content object. Content shape is the spec's section 4 (`contact.address` is multi-line using `\n`; `contact.addressShort` is one line).

- [ ] **Step 1: Create `content.json`**

```json
{
  "version": 1,
  "updatedAt": "2026-10-06T00:00:00+08:00",
  "contact": {
    "phone": "+63 995 109 1503",
    "email": "thearcbistro@gmail.com",
    "address": "208 Osmeña Street, City Subdivision\nSan Pablo City, Laguna, Philippines",
    "addressShort": "208 Osmeña Street, San Pablo City"
  },
  "social": [
    { "label": "Facebook", "url": "https://www.facebook.com/profile.php?id=61555963439558" }
  ],
  "hours": [
    { "day": "mon", "status": "call" },
    { "day": "tue", "status": "open", "from": "10:00", "to": "21:00" },
    { "day": "wed", "status": "open", "from": "10:00", "to": "21:00" },
    { "day": "thu", "status": "open", "from": "10:00", "to": "21:00" },
    { "day": "fri", "status": "open", "from": "10:00", "to": "21:00" },
    { "day": "sat", "status": "open", "from": "10:00", "to": "21:00" },
    { "day": "sun", "status": "open", "from": "10:00", "to": "21:00" }
  ],
  "price": { "level": 2, "label": "Inexpensive to moderate" },
  "featured": [
    { "id": "d1", "name": "Fettuccine Alfredo", "tag": "Italian · Pasta", "description": "A creamy, comforting plate and one of our most-ordered dishes.", "image": "assets/Fettuccine.jpg", "alt": "Fettuccine Alfredo", "hidden": false },
    { "id": "d2", "name": "Shrimp Bisque", "tag": "Soup", "description": "A rich, velvety shellfish soup, warm and full of flavour, lovely with a slice of bread.", "image": "assets/Shrimp%20Bisque.jpg", "alt": "Shrimp Bisque", "hidden": false },
    { "id": "d3", "name": "Pizza", "tag": "Italian · Pizza", "description": "Made for sharing across the table with friends and family.", "image": "assets/pizza.jpg", "alt": "Pizza", "hidden": false },
    { "id": "d4", "name": "Coffee", "tag": "Coffee bar", "description": "Your favourite cup, plus new drinks to discover on the wider menu.", "image": "assets/coffee.png", "alt": "Coffee", "hidden": false }
  ],
  "menu": [],
  "promos": []
}
```

- [ ] **Step 2: Add binding attributes to `index.html` with a one-time script**

Create `tools/bind_html.py` (delete it after running):

```python
import re, sys
p = 'index.html'
s = open(p, encoding='utf-8').read()

def sub(old, new, count, regex=False):
    global s
    n = len(re.findall(old, s)) if regex else s.count(old)
    if n != count:
        sys.exit(f'EXPECTED {count} of {old!r}, found {n}')
    s = re.sub(old, new, s) if regex else s.replace(old, new)

# email
sub('href="mailto:thearcbistro@gmail.com"', 'href="mailto:thearcbistro@gmail.com" data-href="mailto:{contact.email}"', 4)
sub('>thearcbistro@gmail.com</a>', ' data-bind="contact.email">thearcbistro@gmail.com</a>', 3)
sub('<b>thearcbistro@gmail.com</b>', '<b data-bind="contact.email">thearcbistro@gmail.com</b>', 1)
# phone
sub('href="tel:+639951091503"', 'href="tel:+639951091503" data-href="tel:{contact.phoneTel}"', 6)
sub('>+63 995 109 1503</a>', ' data-bind="contact.phone">+63 995 109 1503</a>', 4)
sub('<b>+63 995 109 1503</b>', '<b data-bind="contact.phone">+63 995 109 1503</b>', 1)
# short address (header bar, drawer)
sub('<span>208 Osmeña Street, San Pablo City</span>', '<span data-bind="contact.addressShort">208 Osmeña Street, San Pablo City</span>', 2)
# multi-line address (visit block, footer)
sub('<p>208 Osmeña Street, City Subdivision<br>San Pablo City, Laguna, Philippines</p>', '<p data-lines="contact.address">208 Osmeña Street, City Subdivision<br>San Pablo City, Laguna, Philippines</p>', 1)
sub('<p>208 Osmeña Street<br>City Subdivision<br>San Pablo City, Laguna<br>Philippines</p>', '<p data-lines="contact.address">208 Osmeña Street<br>City Subdivision<br>San Pablo City, Laguna<br>Philippines</p>', 1)
# social
sub('href="https://www.facebook.com/profile.php?id=61555963439558"', 'href="https://www.facebook.com/profile.php?id=61555963439558" data-social="Facebook"', 4)
sub(r'(<h3>Contact</h3>\s*<ul)>', r'\1 data-social-list>', 1, regex=True)
# hours
sub('<div class="hours__list">', '<div class="hours__list" data-hours="card">', 1)
sub('<p>Tuesday – Sunday<br>10:00 AM – 9:00 PM</p>\n        <p style="margin-top:6px">Monday: please call ahead</p>',
    '<div data-hours="block"><p>Tuesday – Sunday<br>10:00 AM – 9:00 PM</p><p>Monday<br>Call ahead</p></div>', 1)
sub(r'<ul>(\s*<li>Tue – Sun)', r'<ul data-hours="footer">\1', 1, regex=True)
# price
sub('<div class="fact"><b>₱₱</b>', '<div class="fact"><b data-bind="price.symbols">₱₱</b>', 1)
sub('<div class="price"><b>₱₱</b><span>Inexpensive to moderate</span></div>',
    '<div class="price"><b data-bind="price.symbols">₱₱</b><span data-bind="price.label">Inexpensive to moderate</span></div>', 1)
# featured dishes container
sub('<div class="menu__cols">', '<div class="menu__cols" data-featured>', 1)
# full menu block (hidden until content exists), placed before the cuisines list
sub(r'(\s*)(<ul class="cuisines")', r'\1<div class="fullmenu" id="fullMenu" hidden></div>\1\2', 1, regex=True)
# promos section (removed by render.js when nothing is live), placed before the feature band
sub('<!-- ============ FEATURE BAND ============ -->',
    '<!-- ============ PROMOS (rendered from content.json; removed when none are live) ============ -->\n'
    '<section class="promos" id="promos" hidden>\n  <div class="wrap">\n    <h2>Right now at The ARC</h2>\n    <div class="promos__grid" id="promoGrid"></div>\n  </div>\n</section>\n\n'
    '<!-- ============ FEATURE BAND ============ -->', 1)
# scripts
sub('<script src="main.js"></script>', '<script src="content-logic.js"></script>\n<script src="render.js"></script>\n<script src="main.js"></script>', 1)
open(p, 'w', encoding='utf-8').write(s)
print('ok')
```

Run: `python tools/bind_html.py`
Expected: `ok`. If it prints `EXPECTED n of ...`, the page was edited since this plan was written: open `index.html`, find that snippet, and adjust the matching line in the script (do not skip it). Then delete `tools/bind_html.py`.

- [ ] **Step 3: Create `render.js`**

```js
(function () {
  const L = window.ContentLogic;
  const $$ = (s, c) => Array.prototype.slice.call((c || document).querySelectorAll(s));
  const get = (o, p) => p.split('.').reduce((a, k) => (a == null ? a : a[k]), o);
  const SAFE_IMG = /^(assets|uploads)\/[A-Za-z0-9._%\/-]+\.(jpe?g|png|webp)$/i;
  const safeImg = s => typeof s === 'string' && SAFE_IMG.test(s) && s.indexOf('..') === -1;
  const safeUrl = s => typeof s === 'string' && /^https:\/\//i.test(s);
  const FB_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13.5 22v-8h2.7l.4-3.2h-3.1V8.8c0-.9.3-1.5 1.6-1.5h1.7V4.4c-.3 0-1.3-.1-2.4-.1-2.4 0-4 1.5-4 4.1v2.4H7.7V14h2.7v8z"/></svg>';

  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  function bindText(v) {
    $$('[data-bind]').forEach(n => {
      const val = get(v, n.getAttribute('data-bind'));
      if (val != null && val !== '') n.textContent = val;
    });
    $$('[data-href]').forEach(n => {
      n.setAttribute('href', n.getAttribute('data-href').replace(/\{([\w.]+)\}/g, (_, p) => get(v, p) || ''));
    });
    $$('[data-lines]').forEach(n => {
      const val = get(v, n.getAttribute('data-lines'));
      if (!val) return;
      n.textContent = '';
      String(val).split('\n').forEach((line, i) => {
        if (i) n.appendChild(document.createElement('br'));
        n.appendChild(document.createTextNode(line));
      });
    });
  }

  function bindSocial(social) {
    const byLabel = {};
    social.forEach(s => { if (safeUrl(s.url)) byLabel[s.label] = s.url; });
    $$('[data-social]').forEach(a => {
      const url = byLabel[a.getAttribute('data-social')];
      const box = a.closest('li') || a;
      if (url) { a.setAttribute('href', url); box.hidden = false; } else { box.hidden = true; }
    });
    $$('[data-social-list]').forEach(ul => {
      $$('li.dyn', ul).forEach(li => li.remove());
      social.filter(s => s.label !== 'Facebook' && safeUrl(s.url)).forEach(s => {
        const li = el('li', 'dyn');
        const a = el('a', 'social link-u', s.label);
        a.href = s.url; a.target = '_blank'; a.rel = 'noopener';
        li.appendChild(a); ul.appendChild(li);
      });
    });
  }

  function bindHours(hours) {
    const groups = L.groupHours(hours);
    $$('[data-hours]').forEach(box => {
      const kind = box.getAttribute('data-hours');
      box.textContent = '';
      groups.forEach(g => {
        if (kind === 'card') {
          const row = el('div', 'hours__row');
          row.appendChild(el('span', null, g.label)); row.appendChild(el('span', null, g.value));
          box.appendChild(row);
        } else if (kind === 'block') {
          const p = el('p');
          p.appendChild(document.createTextNode(g.label)); p.appendChild(document.createElement('br')); p.appendChild(document.createTextNode(g.value));
          box.appendChild(p);
        } else {
          box.appendChild(el('li', null, g.label + ' · ' + g.value));
        }
      });
    });
  }

  function imageFigure(src, alt, frame, label) {
    const fig = el('figure', 'media ' + frame);
    const inner = el('div', 'media__inner');
    if (safeImg(src)) {
      const img = document.createElement('img');
      img.src = src; img.alt = alt || ''; img.loading = 'lazy';
      inner.appendChild(img);
    } else {
      inner.className += ' ph'; inner.setAttribute('data-label', label || '');
    }
    fig.appendChild(inner);
    return fig;
  }

  function renderFeatured(list) {
    const box = document.querySelector('[data-featured]');
    if (!box) return;
    box.textContent = '';
    list.filter(d => !d.hidden).forEach((d, i) => {
      const row = el('div', 'mrow');
      const text = el('div', 'mcell');
      text.setAttribute('data-reveal', '');
      if (d.tag) text.appendChild(el('span', 'pillbtn', d.tag));
      text.appendChild(el('h3', null, d.name));
      if (d.description) text.appendChild(el('p', null, d.description));
      const photo = el('div', 'mcell is-photo');
      photo.setAttribute('data-reveal', '');
      photo.appendChild(imageFigure(d.image, d.alt || d.name, L.frameFor(i), d.name));
      (i % 2 ? [photo, text] : [text, photo]).forEach(c => row.appendChild(c));
      box.appendChild(row);
    });
  }

  function renderFullMenu(menu) {
    const box = document.getElementById('fullMenu');
    if (!box) return;
    box.textContent = '';
    let shown = 0;
    menu.forEach(cat => {
      const items = (cat.items || []).filter(i => i.status !== 'hidden');
      if (!items.length) return;
      shown++;
      const wrap = el('div', 'fm__cat');
      wrap.appendChild(el('h3', 'fm__name', cat.name));
      const ul = el('ul', 'fm__list');
      items.forEach(i => {
        const li = el('li', 'fm__item' + (i.status === 'soldout' ? ' is-soldout' : ''));
        const head = el('div', 'fm__head');
        head.appendChild(el('span', 'fm__title', i.name));
        head.appendChild(el('span', 'fm__dots'));
        if (i.status === 'soldout') head.appendChild(el('span', 'fm__price', 'Sold out'));
        else if (i.price != null && i.price !== '') head.appendChild(el('span', 'fm__price', L.peso(i.price)));
        li.appendChild(head);
        if (i.description) li.appendChild(el('p', 'fm__desc', i.description));
        ul.appendChild(li);
      });
      wrap.appendChild(ul);
      box.appendChild(wrap);
    });
    box.hidden = shown === 0;
  }

  function renderPromos(promos) {
    const section = document.getElementById('promos');
    const grid = document.getElementById('promoGrid');
    if (!section || !grid) return;
    const today = L.todayISO();
    const live = promos.filter(p => L.isPromoLive(p, today));
    if (!live.length) { section.remove(); return; }
    grid.textContent = '';
    section.hidden = false;
    live.forEach(p => {
      const card = el('article', 'promo');
      if (safeImg(p.image)) {
        const img = document.createElement('img');
        img.src = p.image; img.alt = p.alt || p.title; img.loading = 'lazy';
        card.appendChild(img);
      }
      card.appendChild(el('h3', null, p.title));
      const range = L.formatDateRange(p.start, p.end);
      if (range) card.appendChild(el('span', 'promo__dates', range));
      if (p.details) card.appendChild(el('p', null, p.details));
      grid.appendChild(card);
    });
  }

  function safely(fn) { try { fn(); } catch (e) { /* keep built-in text for this part */ } }

  window.renderContent = function (c) {
    if (!c || typeof c !== 'object') return;
    const contact = c.contact || {};
    const view = {
      contact: Object.assign({}, contact, { phoneTel: contact.phone ? L.phoneTel(contact.phone) : '' }),
      price: c.price ? { symbols: L.priceSymbols(c.price.level), label: c.price.label } : {}
    };
    safely(() => bindText(view));
    safely(() => Array.isArray(c.social) && bindSocial(c.social));
    safely(() => Array.isArray(c.hours) && c.hours.length && bindHours(c.hours));
    safely(() => Array.isArray(c.featured) && c.featured.length && renderFeatured(c.featured));
    safely(() => Array.isArray(c.menu) && renderFullMenu(c.menu));
    safely(() => Array.isArray(c.promos) && renderPromos(c.promos));
  };
})();
```

- [ ] **Step 4: Wrap `main.js` so content renders before animations start**

Create `tools/wrap_main.py` (delete after running):

```python
p = 'main.js'
s = open(p, encoding='utf-8').read()
head = "  const $ = (s,c=document)=>c.querySelector(s), $$=(s,c=document)=>[...c.querySelectorAll(s)];\n"
assert head in s and s.rstrip().endswith('})();') and 'function start()' not in s
s = s.replace(head, head + "\n  function start(){\n", 1)
tail = "})();"
i = s.rstrip().rfind(tail)
s = s[:i] + "  }\n\n  fetch('content.json',{cache:'no-cache'})\n    .then(r=>r.ok?r.json():Promise.reject())\n    .then(c=>{ if(window.renderContent) window.renderContent(c); })\n    .catch(()=>{})\n    .then(start);\n})();\n"
open(p, 'w', encoding='utf-8').write(s)
print('ok')
```

Run: `python tools/wrap_main.py && node --check main.js`
Expected: `ok`, then no output from `node --check`. Delete `tools/` afterwards.

- [ ] **Step 5: Add styles for the new blocks to the end of `style.css` (before the `prefers-reduced-motion` block)**

```css
/* ---------- full menu (price list under the featured dishes) ---------- */
.fullmenu{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:clamp(32px,5vw,72px);max-width:900px;margin:clamp(48px,6vw,80px) auto 0}
.fullmenu[hidden]{display:none}
.fm__name{font-size:var(--t-h3);padding-bottom:12px;border-bottom:1px solid var(--line-dark)}
.fm__list{list-style:none;margin:0;padding:0}
.fm__item{padding-block:14px}
.fm__head{display:flex;align-items:baseline;gap:10px}
.fm__title{font-family:var(--f-display);font-size:1.12rem}
.fm__dots{flex:1;border-bottom:1px dotted var(--line-dark);transform:translateY(-4px)}
.fm__price{font-family:var(--f-accent);font-size:1.1rem;white-space:nowrap}
.fm__desc{color:var(--ink-soft);font-size:.9rem;margin-top:2px}
.fm__item.is-soldout .fm__title,.fm__item.is-soldout .fm__desc{opacity:.5}
.fm__item.is-soldout .fm__price{font-size:.8rem;letter-spacing:.14em;text-transform:uppercase;color:var(--ink-soft)}

/* ---------- promos ---------- */
.promos{background:var(--sand);padding-block:var(--section)}
.promos h2{font-size:var(--t-h2);text-align:center}
.promos__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:clamp(20px,3vw,36px);margin-top:clamp(36px,5vw,56px)}
.promo{background:var(--paper);padding:0 0 28px;display:flex;flex-direction:column;gap:10px}
.promo img{width:100%;aspect-ratio:4/3;object-fit:cover}
.promo h3{font-size:var(--t-h3);padding-inline:24px;margin-top:14px}
.promo__dates{padding-inline:24px;font-size:.72rem;letter-spacing:.18em;text-transform:uppercase;color:var(--ink-soft)}
.promo p{padding-inline:24px;color:var(--ink-soft);font-size:.96rem}
[data-hours="block"] p + p{margin-top:6px}
.mcell .media.pill{aspect-ratio:4/5}
@media (max-width:640px){.fullmenu{grid-template-columns:minmax(0,1fr)}}
```

- [ ] **Step 6: Verify default content looks identical, and failures fall back**

Run the site: `python -m http.server 8080` (leave running; open http://localhost:8080).
Expected: the page looks the same as before the change (hours card, footer, menu rows, no promo section, no price list).
Then verify the fallback: rename `content.json` to `content.json.off`, reload.
Expected: page still shows full text from the HTML. Rename it back. Then put `{not json` into `content.json`, reload.
Expected: same fallback, no errors that stop the page animating. Restore the file from Step 1.

- [ ] **Step 7: Verify binding, escaping and promos**

Edit `content.json` temporarily: change the phone to `+63 900 000 0000`; set hours `mon` to `{ "day": "mon", "status": "closed" }`; set the first featured name to `<script>alert(1)</script> & Co.`; add `{ "id": "p1", "title": "Weekend coffee deal", "details": "Buy one, take one.", "image": "", "alt": "", "start": "", "end": "", "active": true }` to `promos`; add one menu category `{ "id": "c1", "name": "Pasta", "items": [ { "id": "i1", "name": "Carbonara", "description": "", "price": 1200, "status": "available" }, { "id": "i2", "name": "Alfredo", "description": "", "price": null, "status": "soldout" } ] }`.
Expected after reload:
- every phone number on the page (header bar, hours card, reserve block, footer, drawer) shows the new number and the `tel:` links use `+639000000000`
- hours show "Monday: Closed"
- the first dish title shows the literal text `<script>alert(1)</script> & Co.` with no alert
- a "Right now at The ARC" section appears before the olive band
- a price list appears under the dishes with `₱1,200` and "Sold out"
Then restore `content.json` from Step 1 and reload.
Expected: promos section and price list are gone, page matches the original.

- [ ] **Step 8: Run all tests**

Run: `node --test tests/`
Expected: PASS

- [ ] **Step 9: Checkpoint**

No git yet. If it is set up later, commit `content.json`, `render.js`, `index.html`, `main.js`, `style.css` with message `feat: render public page from content.json`.

---

### Task 3: PHP foundations: config, util, validation, store (with tests)

**Files:**
- Create: `admin/lib/config.php`, `admin/lib/util.php`, `admin/lib/validate.php`, `admin/lib/store.php`
- Create: `admin/tests/harness.php`, `admin/tests/check.php`, `admin/tests/test_store.php`
- Create: `admin/data/.htaccess`

**Interfaces:**
- Produces:
  - `arc_cfg(string $key): string` for keys `root|content|uploads|data`; tests override with `$GLOBALS['ARC_CFG']`
  - `json_read(string $file, $default = [])`, `json_write_atomic(string $file, $data): void`, `log_line(string $kind, string $msg): void`
  - constants `SECTIONS`, `DAYS`, `DAY_NAMES`, `IMG_RE`
  - `validate_section(string $section, $data): array{0: mixed, 1: string[]}` returns `[cleanData, errors]`
  - `validate_content(array $content): string[]` returns all errors
  - `store_read(): array`, `store_write(array $content, string $who): int` (returns new revision), `store_update(callable $mutate, ?int $baseRevision, string $who): int`, `store_versions(): array`, `store_restore(string $name, string $who): int`
  - `class ConflictException extends RuntimeException`
  - Content gains an integer `revision` (treated as 0 when absent, +1 per save)

- [ ] **Step 1: Create the test harness**

`admin/tests/harness.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/validate.php';

$GLOBALS['t_fails'] = 0;
$GLOBALS['t_count'] = 0;

function t(string $name, callable $fn): void
{
    $GLOBALS['t_count']++;
    try { $fn(); echo "ok   $name\n"; }
    catch (Throwable $e) { $GLOBALS['t_fails']++; echo "FAIL $name\n     " . $e->getMessage() . "\n"; }
}
function eq($actual, $expected, string $msg = ''): void
{
    if ($actual !== $expected) {
        throw new Exception(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}
function ok($cond, string $msg = 'assertion failed'): void { if (!$cond) throw new Exception($msg); }
function throws(callable $fn, string $class = Throwable::class): Throwable
{
    try { $fn(); }
    catch (Throwable $e) {
        if ($e instanceof $class) return $e;
        throw new Exception('wrong exception ' . get_class($e) . ': ' . $e->getMessage());
    }
    throw new Exception("expected $class to be thrown");
}
/** Fresh temp site dir with a copy of the real default content.json; points arc_cfg() at it. */
function fresh_env(): string
{
    $d = sys_get_temp_dir() . '/arc-' . bin2hex(random_bytes(4));
    mkdir($d . '/uploads', 0775, true);
    mkdir($d . '/data', 0775, true);
    copy(dirname(__DIR__, 2) . '/content.json', $d . '/content.json');
    $GLOBALS['ARC_CFG'] = ['root' => $d, 'content' => $d . '/content.json', 'uploads' => $d . '/uploads', 'data' => $d . '/data'];
    return $d;
}
function done(): void
{
    echo "\n{$GLOBALS['t_count']} tests, {$GLOBALS['t_fails']} failed\n";
    exit($GLOBALS['t_fails'] ? 1 : 0);
}

const GOOD_CONTACT = ['phone' => '+63 995 109 1503', 'email' => 'a@b.co', 'address' => "1 St\nCity", 'addressShort' => '1 St, City'];
function week(string $status = 'open'): array
{
    return array_map(
        fn($d) => $status === 'open' ? ['day' => $d, 'status' => 'open', 'from' => '10:00', 'to' => '21:00'] : ['day' => $d, 'status' => $status],
        DAYS
    );
}
```

`admin/tests/check.php`:

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
foreach (glob(__DIR__ . '/test_*.php') as $file) require $file;
done();
```

- [ ] **Step 2: Write the failing tests**

`admin/tests/test_store.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/util.php';
require_once __DIR__ . '/../lib/validate.php';
require_once __DIR__ . '/../lib/store.php';

t('contact: valid input passes and is returned clean', function () {
    [$c, $e] = validate_section('contact', GOOD_CONTACT);
    eq($e, []); eq($c['phone'], '+63 995 109 1503');
});
t('contact: bad phone and bad email are rejected', function () {
    [, $e] = validate_section('contact', ['phone' => 'call me', 'email' => 'nope'] + GOOD_CONTACT);
    ok(count($e) === 2, 'expected 2 errors, got ' . count($e));
});
t('hours: closing time must be after opening time', function () {
    $w = week(); $w[1]['to'] = '09:00';
    [, $e] = validate_section('hours', $w);
    ok(count($e) === 1 && str_contains($e[0], 'closing time'));
});
t('hours: all days closed is valid', function () {
    [$c, $e] = validate_section('hours', week('closed'));
    eq($e, []); eq(count($c), 7);
});
t('hours: a missing day is rejected', function () {
    $w = week(); array_pop($w);
    [, $e] = validate_section('hours', $w);
    ok(count($e) === 1);
});
t('featured: too-long name and bad image path are rejected', function () {
    [, $e] = validate_section('featured', [['name' => str_repeat('x', 41), 'image' => '../secret.jpg']]);
    ok(count($e) === 2, 'got ' . json_encode($e));
});
t('featured: encoded space in image path is accepted', function () {
    [$c, $e] = validate_section('featured', [['name' => 'Bisque', 'image' => 'assets/Shrimp%20Bisque.jpg']]);
    eq($e, []); eq($c[0]['image'], 'assets/Shrimp%20Bisque.jpg'); ok(strlen($c[0]['id']) >= 3);
});
t('featured: more than 8 dishes rejected', function () {
    [, $e] = validate_section('featured', array_fill(0, 9, ['name' => 'A']));
    ok(count($e) >= 1);
});
t('promos: end before start rejected, same-day window accepted', function () {
    [, $e] = validate_section('promos', [['title' => 'X', 'start' => '2026-10-10', 'end' => '2026-10-09', 'active' => true]]);
    ok(count($e) === 1);
    [, $e] = validate_section('promos', [['title' => 'X', 'start' => '2026-10-10', 'end' => '2026-10-10', 'active' => true]]);
    eq($e, []);
});
t('promos: impossible date rejected', function () {
    [, $e] = validate_section('promos', [['title' => 'X', 'start' => '2026-02-31']]);
    ok(count($e) === 1);
});
t('menu: price must be numeric; empty price becomes null', function () {
    [, $e] = validate_section('menu', [['name' => 'Pasta', 'items' => [['name' => 'A', 'price' => 'abc']]]]);
    ok(count($e) === 1);
    [$c, $e] = validate_section('menu', [['name' => 'Pasta', 'items' => [['name' => 'A', 'price' => '']]]]);
    eq($e, []); eq($c[0]['items'][0]['price'], null);
});
t('social: only https links', function () {
    [, $e] = validate_section('social', [['label' => 'Site', 'url' => 'javascript:alert(1)']]);
    ok(count($e) === 1);
    [, $e] = validate_section('social', [['label' => 'Instagram', 'url' => 'https://instagram.com/thearc']]);
    eq($e, []);
});
t('price: level 1..3 only', function () {
    [, $e] = validate_section('price', ['level' => 4, 'label' => 'x']);
    ok(count($e) === 1);
});
t('unknown section rejected', function () {
    [, $e] = validate_section('users', []);
    ok(count($e) === 1);
});
t('store: update makes a backup, bumps revision, keeps valid JSON', function () {
    fresh_env();
    $rev = store_update(function ($c) { $c['price']['label'] = 'Changed'; return $c; }, 0, 'tester');
    eq($rev, 1);
    eq(store_read()['price']['label'], 'Changed');
    eq(count(store_versions()), 1);
});
t('store: stale revision is refused and nothing changes', function () {
    fresh_env();
    store_update(fn($c) => $c, 0, 'a');
    throws(fn() => store_update(function ($c) { $c['price']['label'] = 'Lost'; return $c; }, 0, 'b'), ConflictException::class);
    ok(store_read()['price']['label'] !== 'Lost');
});
t('store: restore rejects path-like names', function () {
    fresh_env();
    throws(fn() => store_restore('../../etc/passwd', 'x'), InvalidArgumentException::class);
});
t('store: restore brings an older version back', function () {
    fresh_env();
    store_update(function ($c) { $c['price']['label'] = 'Second'; return $c; }, 0, 'a');
    $name = store_versions()[0]['name'];
    store_restore($name, 'a');
    eq(store_read()['price']['label'], 'Inexpensive to moderate');
});
t('store: invalid JSON in the content file raises instead of returning junk', function () {
    $d = fresh_env();
    file_put_contents($d . '/content.json', '{nope');
    throws(fn() => store_read(), RuntimeException::class);
});
```

- [ ] **Step 3: Run to verify failure**

Run: `php admin/tests/check.php`
Expected: fatal error, `Failed opening required '.../lib/config.php'`.

- [ ] **Step 4: Implement `config.php` and `util.php`**

`admin/lib/config.php`:

```php
<?php
declare(strict_types=1);

function arc_cfg(string $key): string
{
    static $defaults = null;
    if ($defaults === null) {
        $root = dirname(__DIR__, 2);
        $defaults = [
            'root' => $root,
            'content' => $root . '/content.json',
            'uploads' => $root . '/uploads',
            'data' => dirname(__DIR__) . '/data',
        ];
    }
    return $GLOBALS['ARC_CFG'][$key] ?? $defaults[$key];
}
```

`admin/lib/util.php`:

```php
<?php
declare(strict_types=1);

function json_read(string $file, $default = [])
{
    if (!is_file($file)) return $default;
    $d = json_decode((string)file_get_contents($file), true);
    return is_array($d) ? $d : $default;
}

function json_write_atomic(string $file, $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Could not write file');
    if (!rename($tmp, $file)) { @unlink($tmp); throw new RuntimeException('Could not replace file'); }
}

function log_line(string $kind, string $msg): void
{
    $dir = arc_cfg('data') . '/log';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $msg = str_replace(["\r", "\n"], ' ', $msg);
    file_put_contents($dir . '/' . $kind . '.log', date('c') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}
```

- [ ] **Step 5: Implement `validate.php`**

```php
<?php
declare(strict_types=1);

const SECTIONS = ['contact', 'social', 'hours', 'price', 'featured', 'menu', 'promos'];
const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const DAY_NAMES = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
const IMG_RE = '#^(assets|uploads)/[A-Za-z0-9._%/-]+\.(jpe?g|png|webp)$#i';

function gen_id(): string { return 'x' . bin2hex(random_bytes(4)); }
function keep_id($v): string { return (is_string($v) && preg_match('/^[a-z0-9]{3,12}$/', $v)) ? $v : gen_id(); }

function v_str($v, int $max, string $label, array &$errors, bool $required = false): string
{
    $s = is_string($v) ? $v : '';
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', str_replace("\r\n", "\n", $s)) ?? '';
    $s = trim($s);
    if ($required && $s === '') $errors[] = "$label is required";
    elseif (mb_strlen($s) > $max) $errors[] = "$label is too long (max $max characters)";
    return $s;
}
function v_image($v, string $label, array &$errors): string
{
    if ($v === null || $v === '') return '';
    if (!is_string($v) || !preg_match(IMG_RE, $v) || str_contains($v, '..')) { $errors[] = "$label has an invalid photo"; return ''; }
    return $v;
}
function v_date($v, string $label, array &$errors): string
{
    if ($v === null || $v === '') return '';
    if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        $errors[] = "$label is not a valid date"; return '';
    }
    return $v;
}
function v_url($v, string $label, array &$errors): string
{
    $s = is_string($v) ? trim($v) : '';
    if (!preg_match('#^https://#i', $s) || !filter_var($s, FILTER_VALIDATE_URL) || mb_strlen($s) > 300) {
        $errors[] = "$label must be a full link starting with https://"; return '';
    }
    return $s;
}

function v_contact($d, array &$e): array
{
    $d = is_array($d) ? $d : [];
    $phone = v_str($d['phone'] ?? '', 20, 'Phone number', $e, true);
    if ($phone !== '' && !preg_match('/^\+?[0-9 ()-]{7,20}$/', $phone)) $e[] = 'Phone number can only have digits, spaces, + ( ) and -';
    $email = v_str($d['email'] ?? '', 120, 'Email', $e, true);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $e[] = 'Email is not a valid address';
    $address = v_str($d['address'] ?? '', 160, 'Address', $e, true);
    if (substr_count($address, "\n") > 2) $e[] = 'Address can have at most 3 lines';
    $short = v_str($d['addressShort'] ?? '', 80, 'Short address', $e, true);
    return ['phone' => $phone, 'email' => $email, 'address' => $address, 'addressShort' => $short];
}

function v_social($d, array &$e): array
{
    if (!is_array($d)) { $e[] = 'Social links must be a list'; return []; }
    if (count($d) > 6) $e[] = 'At most 6 social links';
    $out = [];
    foreach (array_values($d) as $i => $row) {
        $n = $i + 1;
        $row = is_array($row) ? $row : [];
        $out[] = ['label' => v_str($row['label'] ?? '', 20, "Link $n name", $e, true), 'url' => v_url($row['url'] ?? '', "Link $n", $e)];
    }
    return $out;
}

function v_hours($d, array &$e): array
{
    $by = [];
    foreach (is_array($d) ? $d : [] as $row) {
        if (is_array($row) && isset($row['day']) && in_array($row['day'], DAYS, true)) $by[$row['day']] = $row;
    }
    if (count($by) !== 7) { $e[] = 'Hours must include all seven days'; return []; }
    $out = [];
    foreach (DAYS as $day) {
        $name = DAY_NAMES[$day];
        $r = $by[$day];
        $st = $r['status'] ?? '';
        if (!in_array($st, ['open', 'closed', 'call'], true)) { $e[] = "$name: choose open, closed or call ahead"; continue; }
        if ($st !== 'open') { $out[] = ['day' => $day, 'status' => $st]; continue; }
        $re = '/^([01]\d|2[0-3]):[0-5]\d$/';
        $f = (string)($r['from'] ?? '');
        $t = (string)($r['to'] ?? '');
        if (!preg_match($re, $f) || !preg_match($re, $t)) { $e[] = "$name: enter opening and closing times"; continue; }
        if ($t <= $f) { $e[] = "$name: closing time must be after opening time"; continue; }
        $out[] = ['day' => $day, 'status' => 'open', 'from' => $f, 'to' => $t];
    }
    return $out;
}

function v_price($d, array &$e): array
{
    $d = is_array($d) ? $d : [];
    $lvl = filter_var($d['level'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3]]);
    if ($lvl === false) { $e[] = 'Price level must be 1, 2 or 3'; $lvl = 2; }
    return ['level' => $lvl, 'label' => v_str($d['label'] ?? '', 40, 'Price label', $e, true)];
}

function v_featured($d, array &$e): array
{
    if (!is_array($d)) { $e[] = 'Dishes must be a list'; return []; }
    if (count($d) > 8) $e[] = 'At most 8 featured dishes';
    $out = [];
    foreach (array_values($d) as $i => $r) {
        $n = 'Dish ' . ($i + 1);
        $r = is_array($r) ? $r : [];
        $out[] = [
            'id' => keep_id($r['id'] ?? null),
            'name' => v_str($r['name'] ?? '', 40, "$n name", $e, true),
            'tag' => v_str($r['tag'] ?? '', 30, "$n label", $e),
            'description' => v_str($r['description'] ?? '', 140, "$n description", $e),
            'image' => v_image($r['image'] ?? '', $n, $e),
            'alt' => v_str($r['alt'] ?? '', 80, "$n photo description", $e),
            'hidden' => ($r['hidden'] ?? false) === true,
        ];
    }
    return $out;
}

function v_menu($d, array &$e): array
{
    if (!is_array($d)) { $e[] = 'Menu must be a list'; return []; }
    if (count($d) > 8) $e[] = 'At most 8 menu categories';
    $out = [];
    foreach (array_values($d) as $i => $c) {
        $cn = 'Category ' . ($i + 1);
        $c = is_array($c) ? $c : [];
        $items = is_array($c['items'] ?? null) ? $c['items'] : [];
        if (count($items) > 20) $e[] = "$cn can have at most 20 items";
        $cleanItems = [];
        foreach (array_values($items) as $j => $it) {
            $in = "$cn item " . ($j + 1);
            $it = is_array($it) ? $it : [];
            $price = $it['price'] ?? null;
            if ($price === '' || $price === null) $price = null;
            elseif (is_numeric($price) && (float)$price >= 0 && (float)$price <= 1000000) $price = $price + 0;
            else { $e[] = "$in: price must be a number"; $price = null; }
            $st = $it['status'] ?? 'available';
            if (!in_array($st, ['available', 'hidden', 'soldout'], true)) { $e[] = "$in: unknown status"; $st = 'available'; }
            $cleanItems[] = [
                'id' => keep_id($it['id'] ?? null),
                'name' => v_str($it['name'] ?? '', 50, "$in name", $e, true),
                'description' => v_str($it['description'] ?? '', 100, "$in description", $e),
                'price' => $price,
                'status' => $st,
            ];
        }
        $out[] = ['id' => keep_id($c['id'] ?? null), 'name' => v_str($c['name'] ?? '', 30, "$cn name", $e, true), 'items' => $cleanItems];
    }
    return $out;
}

function v_promos($d, array &$e): array
{
    if (!is_array($d)) { $e[] = 'Promos must be a list'; return []; }
    if (count($d) > 6) $e[] = 'At most 6 promos';
    $out = [];
    foreach (array_values($d) as $i => $r) {
        $n = 'Promo ' . ($i + 1);
        $r = is_array($r) ? $r : [];
        $start = v_date($r['start'] ?? '', "$n start date", $e);
        $end = v_date($r['end'] ?? '', "$n end date", $e);
        if ($start !== '' && $end !== '' && $end < $start) $e[] = "$n: the end date is before the start date";
        $out[] = [
            'id' => keep_id($r['id'] ?? null),
            'title' => v_str($r['title'] ?? '', 50, "$n title", $e, true),
            'details' => v_str($r['details'] ?? '', 200, "$n details", $e),
            'image' => v_image($r['image'] ?? '', $n, $e),
            'alt' => v_str($r['alt'] ?? '', 80, "$n photo description", $e),
            'start' => $start,
            'end' => $end,
            'active' => ($r['active'] ?? false) === true,
        ];
    }
    return $out;
}

/** @return array{0: mixed, 1: string[]} [cleanData, errors] */
function validate_section(string $section, $data): array
{
    if (!in_array($section, SECTIONS, true)) return [null, ['Unknown section']];
    $e = [];
    $fn = 'v_' . $section;
    $clean = $fn($data, $e);
    return [$clean, $e];
}

function validate_content(array $c): array
{
    $all = [];
    foreach (SECTIONS as $s) {
        [, $e] = validate_section($s, $c[$s] ?? []);
        foreach ($e as $msg) $all[] = "$s: $msg";
    }
    return $all;
}
```

- [ ] **Step 6: Implement `store.php`**

```php
<?php
declare(strict_types=1);

class ConflictException extends RuntimeException {}

function store_read(): array
{
    $d = json_read(arc_cfg('content'), null);
    if (!is_array($d)) throw new RuntimeException('content.json is missing or not valid JSON');
    return $d;
}

function store_write(array $content, string $who): int
{
    $file = arc_cfg('content');
    $bdir = arc_cfg('data') . '/backups';
    if (!is_dir($bdir)) mkdir($bdir, 0775, true);
    $prev = is_file($file) ? json_read($file, []) : [];
    if (is_file($file)) copy($file, $bdir . '/content-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json');
    $content['version'] = 1;
    $content['revision'] = (int)($prev['revision'] ?? 0) + 1;
    $content['updatedAt'] = date('c');
    json_write_atomic($file, $content);
    $old = glob($bdir . '/content-*.json') ?: [];
    rsort($old);
    foreach (array_slice($old, 30) as $f) @unlink($f);
    log_line('activity', "$who saved revision {$content['revision']}");
    return $content['revision'];
}

function store_update(callable $mutate, ?int $baseRevision, string $who): int
{
    $dir = arc_cfg('data');
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $h = fopen($dir . '/content.lock', 'c');
    flock($h, LOCK_EX);
    try {
        $cur = store_read();
        if ($baseRevision !== null && (int)($cur['revision'] ?? 0) !== $baseRevision) {
            throw new ConflictException('Someone else changed this while you were editing. Reload the page and try again.');
        }
        return store_write($mutate($cur), $who);
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

function store_versions(): array
{
    $files = glob(arc_cfg('data') . '/backups/content-*.json') ?: [];
    rsort($files);
    return array_map(fn($f) => ['name' => basename($f), 'time' => date('c', (int)filemtime($f)), 'size' => (int)filesize($f)], $files);
}

function store_restore(string $name, string $who): int
{
    if (!preg_match('/^content-\d{8}-\d{6}-[0-9a-f]{4}\.json$/', $name)) throw new InvalidArgumentException('Unknown version');
    $d = json_read(arc_cfg('data') . '/backups/' . $name, null);
    if (!is_array($d)) throw new InvalidArgumentException('That version was not found');
    $errs = validate_content($d);
    if ($errs) throw new InvalidArgumentException('That version is not valid: ' . implode('; ', $errs));
    return store_update(fn($cur) => $d, null, "$who (restored $name)");
}
```

- [ ] **Step 7: Protect the data folder**

`admin/data/.htaccess`:

```
Require all denied
```

- [ ] **Step 8: Run to verify pass**

Run: `php admin/tests/check.php`
Expected: every line starts with `ok`, final line `N tests, 0 failed`.

- [ ] **Step 9: Checkpoint**

No git yet; if set up later commit `admin/lib/*`, `admin/tests/*`, `admin/data/.htaccess` with message `feat: content validation and versioned store`.

---

### Task 4: Authentication, lockout, CSRF, roles (with tests)

**Files:**
- Create: `admin/lib/auth.php`, `admin/tests/test_auth.php`

**Interfaces:**
- Consumes: `arc_cfg`, `json_read`, `json_write_atomic`, `log_line`, `SECTIONS` (Task 3).
- Produces:
  - constants `ROLES`, `STAFF_SECTIONS`, `SESSION_IDLE=1800`, `MAX_ATTEMPTS=5`, `LOCK_SECONDS=600`, `PASSWORD_MIN=10`
  - `users_all(): array`, `users_save(array): void`, `user_by(string $field, string $value): ?array`
  - `user_create(string $username, string $email, string $password, string $role): array`, throws `InvalidArgumentException` with a user-readable message
  - `auth_login(string $username, string $password, string $ip, ?int $now = null): array{ok:bool,error:?string,user:?array}`
  - `auth_session_start(): void`, `auth_begin(array $user): void`, `auth_current(?int $now = null): ?array`, `auth_end(): void`
  - `csrf_token(): string`, `csrf_valid(string $t): bool`
  - `can_edit_section(string $role, string $section): bool`, `can(string $role, string $cap): bool` for caps `content|accounts|history|system`, `users_visible_to(string $role): array`

- [ ] **Step 1: Write the failing tests**

`admin/tests/test_auth.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/util.php';
require_once __DIR__ . '/../lib/validate.php';
require_once __DIR__ . '/../lib/auth.php';

t('user_create: validates username, email, password length, role', function () {
    fresh_env();
    throws(fn() => user_create('a', 'a@b.co', 'longenough1', 'owner'), InvalidArgumentException::class);
    throws(fn() => user_create('okname', 'bad', 'longenough1', 'owner'), InvalidArgumentException::class);
    throws(fn() => user_create('okname', 'a@b.co', 'short', 'owner'), InvalidArgumentException::class);
    throws(fn() => user_create('okname', 'a@b.co', 'longenough1', 'admin'), InvalidArgumentException::class);
    $u = user_create('okname', 'a@b.co', 'longenough1', 'owner');
    ok(password_verify('longenough1', $u['hash']) && $u['hash'] !== 'longenough1');
});
t('user_create: duplicate username or email refused', function () {
    fresh_env();
    user_create('okname', 'a@b.co', 'longenough1', 'owner');
    throws(fn() => user_create('OKNAME', 'c@d.co', 'longenough1', 'staff'), InvalidArgumentException::class);
    throws(fn() => user_create('other', 'a@b.co', 'longenough1', 'staff'), InvalidArgumentException::class);
});
t('login: success returns the user', function () {
    fresh_env(); user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $r = auth_login('OkName', 'longenough1', '1.1.1.1', 1000);
    ok($r['ok'] && $r['user']['username'] === 'okname');
});
t('login: unknown user and wrong password give the identical message', function () {
    fresh_env(); user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $a = auth_login('okname', 'wrongwrongwrong', '1.1.1.1', 1000);
    $b = auth_login('nobody', 'wrongwrongwrong', '1.1.1.1', 1000);
    ok(!$a['ok'] && !$b['ok']); eq($a['error'], $b['error']);
});
t('login: 5 failures lock the account for 10 minutes, then it opens again', function () {
    fresh_env(); user_create('okname', 'a@b.co', 'longenough1', 'owner');
    for ($i = 0; $i < 5; $i++) auth_login('okname', 'bad', '1.1.1.1', 1000);
    $locked = auth_login('okname', 'longenough1', '1.1.1.1', 1001);
    ok(!$locked['ok'] && str_contains($locked['error'], 'Too many'));
    ok(auth_login('okname', 'longenough1', '9.9.9.9', 1001)['ok'], 'lock is per username + IP');
    ok(auth_login('okname', 'longenough1', '1.1.1.1', 1000 + 601)['ok']);
});
t('locking an unknown username behaves the same as a real one', function () {
    fresh_env();
    for ($i = 0; $i < 5; $i++) auth_login('ghost', 'bad', '1.1.1.1', 1000);
    ok(str_contains(auth_login('ghost', 'bad', '1.1.1.1', 1001)['error'], 'Too many'));
});
t('session: idle for more than 30 minutes ends the session', function () {
    fresh_env(); $u = user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $_SESSION = ['uid' => $u['id'], 'seen' => 1000, 'csrf' => 'x'];
    ok(auth_current(1000 + 1799) !== null);
    $_SESSION['seen'] = 1000;
    eq(auth_current(1000 + 1801), null);
});
t('session: a deleted user loses access immediately', function () {
    fresh_env(); $u = user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $_SESSION = ['uid' => $u['id'], 'seen' => time(), 'csrf' => 'x'];
    users_save([]);
    eq(auth_current(), null);
});
t('csrf: token must match and an empty token is never valid', function () {
    $_SESSION = ['csrf' => 'abc123'];
    ok(csrf_valid('abc123')); ok(!csrf_valid('abc124')); ok(!csrf_valid(''));
    $_SESSION = [];
    ok(!csrf_valid(''));
});
t('permissions: staff only menu and promos', function () {
    foreach (['featured', 'menu', 'promos'] as $s) ok(can_edit_section('staff', $s), $s);
    foreach (['contact', 'social', 'hours', 'price'] as $s) ok(!can_edit_section('staff', $s), $s);
    foreach (SECTIONS as $s) { ok(can_edit_section('owner', $s)); ok(can_edit_section('developer', $s)); }
    ok(!can_edit_section('owner', 'users'));
});
t('permissions: capabilities per role', function () {
    ok(can('staff', 'content') && !can('staff', 'accounts') && !can('staff', 'history') && !can('staff', 'system'));
    ok(can('owner', 'accounts') && can('owner', 'history') && !can('owner', 'system'));
    ok(can('developer', 'system'));
});
t('developer accounts are hidden from the owner', function () {
    fresh_env();
    user_create('dev', 'd@b.co', 'longenough1', 'developer');
    user_create('boss', 'o@b.co', 'longenough1', 'owner');
    user_create('helper', 's@b.co', 'longenough1', 'staff');
    eq(count(users_visible_to('owner')), 2);
    eq(count(users_visible_to('developer')), 3);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php admin/tests/check.php`
Expected: fatal error, `Failed opening required '.../lib/auth.php'`.

- [ ] **Step 3: Implement `auth.php`**

```php
<?php
declare(strict_types=1);

const ROLES = ['owner', 'staff', 'developer'];
const STAFF_SECTIONS = ['featured', 'menu', 'promos'];
const SESSION_IDLE = 1800;
const MAX_ATTEMPTS = 5;
const LOCK_SECONDS = 600;
const PASSWORD_MIN = 10;

function users_all(): array { return json_read(arc_cfg('data') . '/users.json', []); }
function users_save(array $u): void { json_write_atomic(arc_cfg('data') . '/users.json', array_values($u)); }

function user_by(string $field, string $value): ?array
{
    foreach (users_all() as $u) {
        if (strcasecmp((string)($u[$field] ?? ''), $value) === 0) return $u;
    }
    return null;
}

function user_create(string $username, string $email, string $password, string $role): array
{
    $username = strtolower(trim($username));
    $email = trim($email);
    if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username)) throw new InvalidArgumentException('Username must be 3 to 30 letters, numbers, dot, dash or underscore');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address');
    if (strlen($password) < PASSWORD_MIN) throw new InvalidArgumentException('Password must be at least ' . PASSWORD_MIN . ' characters');
    if (!in_array($role, ROLES, true)) throw new InvalidArgumentException('Unknown role');
    if (user_by('username', $username)) throw new InvalidArgumentException('That username is already taken');
    if (user_by('email', $email)) throw new InvalidArgumentException('That email is already used by another account');
    $u = [
        'id' => 'u' . bin2hex(random_bytes(4)), 'username' => $username, 'email' => $email, 'role' => $role,
        'hash' => password_hash($password, PASSWORD_DEFAULT), 'createdAt' => date('c'),
    ];
    $all = users_all();
    $all[] = $u;
    users_save($all);
    return $u;
}

function dummy_hash(): string
{
    static $h = null;
    return $h ??= password_hash('not-a-real-password', PASSWORD_DEFAULT);
}

function attempts_key(string $ip, string $username): string { return hash('sha256', $ip . '|' . strtolower(trim($username))); }

function lock_remaining(string $key, int $now): int
{
    $a = json_read(arc_cfg('data') . '/attempts.json', []);
    return max(0, (int)($a[$key]['until'] ?? 0) - $now);
}

function attempt_fail(string $key, int $now): void
{
    $f = arc_cfg('data') . '/attempts.json';
    $a = json_read($f, []);
    $row = $a[$key] ?? ['count' => 0, 'until' => 0];
    if ((int)$row['until'] > 0 && (int)$row['until'] <= $now) $row = ['count' => 0, 'until' => 0];
    $row['count']++;
    if ($row['count'] >= MAX_ATTEMPTS) { $row['until'] = $now + LOCK_SECONDS; $row['count'] = 0; }
    $a[$key] = $row;
    json_write_atomic($f, $a);
}

function attempt_clear(string $key): void
{
    $f = arc_cfg('data') . '/attempts.json';
    $a = json_read($f, []);
    unset($a[$key]);
    json_write_atomic($f, $a);
}

function auth_login(string $username, string $password, string $ip, ?int $now = null): array
{
    $now ??= time();
    $key = attempts_key($ip, $username);
    $wait = lock_remaining($key, $now);
    if ($wait > 0) {
        return ['ok' => false, 'error' => 'Too many attempts. Try again in ' . max(1, (int)ceil($wait / 60)) . ' minute(s).', 'user' => null];
    }
    $u = user_by('username', strtolower(trim($username)));
    $good = password_verify($password, $u['hash'] ?? dummy_hash()) && $u !== null;
    if (!$good) {
        attempt_fail($key, $now);
        log_line('activity', 'failed login ' . substr(hash('sha256', strtolower($username)), 0, 8));
        return ['ok' => false, 'error' => 'Wrong username or password.', 'user' => null];
    }
    attempt_clear($key);
    return ['ok' => true, 'error' => null, 'user' => $u];
}

function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('arcadmin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function auth_begin(array $user): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION = ['uid' => $user['id'], 'seen' => time(), 'csrf' => bin2hex(random_bytes(16))];
}

function auth_current(?int $now = null): ?array
{
    $now ??= time();
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) return null;
    if ($now - (int)($_SESSION['seen'] ?? 0) > SESSION_IDLE) { $_SESSION = []; return null; }
    $u = user_by('id', (string)$uid);
    if (!$u) { $_SESSION = []; return null; }
    $_SESSION['seen'] = $now;
    return $u;
}

function auth_end(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

function csrf_token(): string { return (string)($_SESSION['csrf'] ?? ''); }
function csrf_valid(string $t): bool { $c = csrf_token(); return $c !== '' && hash_equals($c, $t); }

function can_edit_section(string $role, string $section): bool
{
    if ($role === 'staff') return in_array($section, STAFF_SECTIONS, true);
    return in_array($role, ['owner', 'developer'], true) && in_array($section, SECTIONS, true);
}

function can(string $role, string $cap): bool
{
    return match ($cap) {
        'content' => in_array($role, ROLES, true),
        'accounts', 'history' => in_array($role, ['owner', 'developer'], true),
        'system' => $role === 'developer',
        default => false,
    };
}

function users_visible_to(string $role): array
{
    $all = users_all();
    return $role === 'developer' ? $all : array_values(array_filter($all, fn($u) => $u['role'] !== 'developer'));
}
```

- [ ] **Step 4: Run to verify pass**

Run: `php admin/tests/check.php`
Expected: `N tests, 0 failed`.

- [ ] **Step 5: Checkpoint**

Commit (if git exists) `admin/lib/auth.php`, `admin/tests/test_auth.php`: `feat: authentication, lockout, csrf, roles`.

---

### Task 5: API handlers and dispatcher (with tests)

**Files:**
- Create: `admin/lib/bootstrap.php`, `admin/lib/handlers.php`, `admin/api.php`, `admin/tests/test_handlers.php`

**Interfaces:**
- Consumes: Tasks 3 and 4.
- Produces:
  - `api_ok(array $body = [], int $status = 200): array{0:int,1:array}` and `api_err(string $msg, int $status, array $extra = []): array{0:int,1:array}`
  - `handle_content_get(array $user, array $in): array` returns `[200, ['ok'=>true,'content'=>...]]`; staff only get `revision, updatedAt, featured, menu, promos`
  - `handle_content_save(array $user, array $in): array` with `$in = ['section'=>string,'data'=>mixed,'baseRevision'=>int]`; returns 200 `{ok,revision,data}`, 400 unknown section or missing revision, 403 not allowed, 409 conflict, 422 `{errors:[...]}`
  - HTTP: `api.php?action=<name>`; POST bodies are JSON with header `X-CSRF-Token`; every response is JSON.

- [ ] **Step 1: Write the failing tests**

`admin/tests/test_handlers.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

const U_STAFF = ['id' => 'u1', 'username' => 'staff1', 'role' => 'staff'];
const U_OWNER = ['id' => 'u2', 'username' => 'boss', 'role' => 'owner'];

t('staff cannot save hours, and content stays byte-identical', function () {
    fresh_env();
    $before = file_get_contents(arc_cfg('content'));
    [$st, $body] = handle_content_save(U_STAFF, ['section' => 'hours', 'baseRevision' => 0, 'data' => []]);
    eq($st, 403); eq($body['ok'], false);
    eq(file_get_contents(arc_cfg('content')), $before);
});
t('staff cannot save contact, price or social either', function () {
    fresh_env();
    foreach (['contact', 'price', 'social'] as $s) {
        [$st] = handle_content_save(U_STAFF, ['section' => $s, 'baseRevision' => 0, 'data' => []]);
        eq($st, 403, $s);
    }
});
t('staff can save featured dishes', function () {
    fresh_env();
    $c = store_read();
    $c['featured'][0]['name'] = 'Fettuccine Carbonara';
    [$st, $body] = handle_content_save(U_STAFF, ['section' => 'featured', 'baseRevision' => 0, 'data' => $c['featured']]);
    eq($st, 200, json_encode($body));
    eq(store_read()['featured'][0]['name'], 'Fettuccine Carbonara');
    eq($body['revision'], 1);
});
t('owner can save contact and the response carries cleaned data', function () {
    fresh_env();
    [$st, $body] = handle_content_save(U_OWNER, ['section' => 'contact', 'baseRevision' => 0, 'data' => GOOD_CONTACT]);
    eq($st, 200); eq($body['data']['email'], 'a@b.co');
});
t('invalid data returns 422 with messages and changes nothing', function () {
    fresh_env();
    $before = file_get_contents(arc_cfg('content'));
    [$st, $body] = handle_content_save(U_OWNER, ['section' => 'contact', 'baseRevision' => 0, 'data' => ['phone' => 'x'] + GOOD_CONTACT]);
    eq($st, 422); ok(count($body['errors']) >= 1);
    eq(file_get_contents(arc_cfg('content')), $before);
});
t('second save with a stale revision gets 409 and does not overwrite', function () {
    fresh_env();
    handle_content_save(U_OWNER, ['section' => 'price', 'baseRevision' => 0, 'data' => ['level' => 2, 'label' => 'First']]);
    [$st] = handle_content_save(U_OWNER, ['section' => 'price', 'baseRevision' => 0, 'data' => ['level' => 3, 'label' => 'Second']]);
    eq($st, 409);
    eq(store_read()['price']['label'], 'First');
});
t('missing baseRevision and unknown section are rejected with 400', function () {
    fresh_env();
    [$st] = handle_content_save(U_OWNER, ['section' => 'price', 'data' => ['level' => 2, 'label' => 'x']]);
    eq($st, 400);
    [$st] = handle_content_save(U_OWNER, ['section' => 'users', 'baseRevision' => 0, 'data' => []]);
    eq($st, 400);
});
t('content.get hides owner-only sections from staff', function () {
    fresh_env();
    [, $body] = handle_content_get(U_STAFF, []);
    ok(isset($body['content']['featured']) && !isset($body['content']['contact']) && !isset($body['content']['hours']));
    [, $body] = handle_content_get(U_OWNER, []);
    ok(isset($body['content']['contact']));
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php admin/tests/check.php`
Expected: fatal error, `Failed opening required '.../lib/bootstrap.php'`.

- [ ] **Step 3: Implement `bootstrap.php`**

`admin/lib/bootstrap.php`:

```php
<?php
declare(strict_types=1);

foreach (['config', 'util', 'validate', 'store', 'auth', 'handlers'] as $lib) {
    require_once __DIR__ . '/' . $lib . '.php';
}
```

- [ ] **Step 4: Implement `handlers.php`**

`admin/lib/handlers.php`:

```php
<?php
declare(strict_types=1);

function api_ok(array $body = [], int $status = 200): array { return [$status, ['ok' => true] + $body]; }
function api_err(string $msg, int $status, array $extra = []): array { return [$status, ['ok' => false, 'error' => $msg] + $extra]; }

function handle_content_get(array $user, array $in): array
{
    $c = store_read();
    if ($user['role'] === 'staff') {
        $c = array_intersect_key($c, array_flip(['revision', 'updatedAt', 'featured', 'menu', 'promos']));
    }
    return api_ok(['content' => $c]);
}

function handle_content_save(array $user, array $in): array
{
    $section = (string)($in['section'] ?? '');
    if (!in_array($section, SECTIONS, true)) return api_err('Unknown section', 400);
    if (!isset($in['baseRevision'])) return api_err('Missing revision. Reload the page and try again.', 400);
    if (!can_edit_section($user['role'], $section)) {
        log_line('activity', "{$user['username']} was blocked from saving $section");
        return api_err('You do not have permission to change this.', 403);
    }
    [$clean, $errors] = validate_section($section, $in['data'] ?? null);
    if ($errors) return api_err('Please fix the problems below.', 422, ['errors' => $errors]);
    try {
        $rev = store_update(
            function (array $cur) use ($section, $clean) { $cur[$section] = $clean; return $cur; },
            (int)$in['baseRevision'],
            $user['username']
        );
    } catch (ConflictException $e) {
        return api_err($e->getMessage(), 409);
    }
    return api_ok(['revision' => $rev, 'data' => $clean]);
}
```

- [ ] **Step 5: Implement the HTTP dispatcher**

`admin/api.php`:

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

auth_session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $user = auth_current();
    if (!$user) respond(401, ['ok' => false, 'error' => 'Please log in again.']);

    $routes = [
        'content.get'  => ['GET',  'handle_content_get'],
        'content.save' => ['POST', 'handle_content_save'],
    ];
    $action = (string)($_GET['action'] ?? '');
    if (!isset($routes[$action])) respond(404, ['ok' => false, 'error' => 'Unknown action']);
    [$method, $fn] = $routes[$action];
    if ($_SERVER['REQUEST_METHOD'] !== $method) respond(405, ['ok' => false, 'error' => 'Wrong request method']);
    if ($method === 'POST' && !csrf_valid((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        respond(403, ['ok' => false, 'error' => 'Your session expired. Reload the page.']);
    }
    if ($action === 'upload') {
        $f = $_FILES['photo'] ?? [];
        $tmp = (string)($f['tmp_name'] ?? '');
        $in = ['tmp' => ($tmp !== '' && is_uploaded_file($tmp)) ? $tmp : '', 'err' => (int)($f['error'] ?? UPLOAD_ERR_NO_FILE)];
    } else {
        $in = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_GET;
    }
    [$status, $body] = $fn($user, $in);
    respond($status, $body);
} catch (Throwable $e) {
    log_line('error', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    respond(500, ['ok' => false, 'error' => 'Something went wrong. Nothing was saved. Please try again.']);
}
```

Later tasks add entries to `$routes` (Tasks 6, 9 and 10). The `upload` branch is already here so Task 6 only adds the route and the handler.

- [ ] **Step 6: Run to verify pass**

Run: `php admin/tests/check.php`
Expected: `N tests, 0 failed`.

- [ ] **Step 7: Checkpoint**

Commit (if git exists): `feat: content API with role checks and conflict detection`.

---

### Task 6: Image upload (with tests)

**Files:**
- Create: `admin/lib/images.php`, `admin/tests/test_images.php`, `uploads/.htaccess`, `uploads/.gitkeep`
- Modify: `admin/lib/bootstrap.php` (add `'images'`), `admin/lib/handlers.php` (add `handle_upload`), `admin/api.php` (add the `upload` route)

**Interfaces:**
- Produces:
  - `images_process(string $tmpPath): string` returns a site-relative path like `uploads/3f9a….jpg`; throws `InvalidArgumentException` with a user-readable message
  - `handle_upload(array $user, array $in): array` with `$in = ['tmp'=>string,'err'=>int]`; 200 `{ok,path}`, 400 or 422 on failure
  - HTTP: `POST api.php?action=upload`, multipart field `photo`, header `X-CSRF-Token`

Decision recorded here: replaced or removed photos are **not** deleted automatically, because restoring an older version needs them. The System tab (Task 10) lists unused photos with a delete button. This replaces the spec's line "deletes replaced or removed files"; update the spec section 8 accordingly when this task is done.

- [ ] **Step 1: Write the failing tests**

`admin/tests/test_images.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

function make_jpeg(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 200, 120, 60));
    $f = sys_get_temp_dir() . '/arc-img-' . bin2hex(random_bytes(3)) . '.jpg';
    imagejpeg($im, $f, 90);
    return $f;
}

t('gd is available', function () { ok(function_exists('imagecreatetruecolor'), 'enable the gd extension in php.ini'); });

t('a large jpeg is shrunk to 1600px and given a random name in uploads/', function () {
    fresh_env();
    $path = images_process(make_jpeg(2000, 1000));
    ok(preg_match('#^uploads/[0-9a-f]{16}\.jpg$#', $path) === 1, $path);
    [$w, $h] = getimagesize(arc_cfg('root') . '/' . $path);
    eq($w, 1600); eq($h, 800);
});
t('a small image keeps its size', function () {
    fresh_env();
    $path = images_process(make_jpeg(400, 300));
    [$w] = getimagesize(arc_cfg('root') . '/' . $path);
    eq($w, 400);
});
t('a PHP or text file renamed .jpg is rejected and nothing is written', function () {
    fresh_env();
    $f = sys_get_temp_dir() . '/evil-' . bin2hex(random_bytes(3)) . '.jpg';
    file_put_contents($f, '<?php echo "pwned";');
    throws(fn() => images_process($f), InvalidArgumentException::class);
    eq(count(glob(arc_cfg('uploads') . '/*') ?: []), 0);
});
t('a gif is rejected (only jpg, png, webp)', function () {
    fresh_env();
    $f = sys_get_temp_dir() . '/a-' . bin2hex(random_bytes(3)) . '.gif';
    imagegif(imagecreatetruecolor(10, 10), $f);
    throws(fn() => images_process($f), InvalidArgumentException::class);
});
t('a file over 5 MB is rejected before decoding', function () {
    fresh_env();
    $f = sys_get_temp_dir() . '/big-' . bin2hex(random_bytes(3)) . '.jpg';
    file_put_contents($f, str_repeat('A', 5 * 1024 * 1024 + 10));
    $e = throws(fn() => images_process($f), InvalidArgumentException::class);
    ok(str_contains($e->getMessage(), '5 MB'));
});
t('a png keeps being a png', function () {
    fresh_env();
    $f = sys_get_temp_dir() . '/p-' . bin2hex(random_bytes(3)) . '.png';
    imagepng(imagecreatetruecolor(50, 50), $f);
    ok(str_ends_with(images_process($f), '.png'));
});
t('handle_upload reports a failed upload nicely', function () {
    fresh_env();
    [$st, $b] = handle_upload(['role' => 'staff', 'username' => 's'], ['tmp' => '', 'err' => UPLOAD_ERR_NO_FILE]);
    eq($st, 400); eq($b['ok'], false);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php admin/tests/check.php`
Expected: FAIL lines for the new image tests (`Call to undefined function images_process()`).

- [ ] **Step 3: Implement `images.php`**

`admin/lib/images.php`:

```php
<?php
declare(strict_types=1);

const IMG_MAX_BYTES = 5 * 1024 * 1024;
const IMG_MAX_EDGE = 1600;
const IMG_MAX_PIXELS = 40000000;

function images_process(string $tmpPath): string
{
    if ($tmpPath === '' || !is_file($tmpPath)) throw new InvalidArgumentException('No photo was received');
    if (filesize($tmpPath) > IMG_MAX_BYTES) throw new InvalidArgumentException('That photo is larger than 5 MB. Please choose a smaller one.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    $info = $ext === null ? false : @getimagesize($tmpPath);
    if ($info === false) throw new InvalidArgumentException('Please upload a JPG, PNG or WebP photo.');
    if ($info[0] * $info[1] > IMG_MAX_PIXELS) throw new InvalidArgumentException('That photo has too many pixels. Please resize it first.');
    if ($ext === 'webp' && !function_exists('imagewebp')) throw new InvalidArgumentException('WebP photos are not supported on this server. Please use JPG or PNG.');

    $src = @imagecreatefromstring((string)file_get_contents($tmpPath));
    if ($src === false) throw new InvalidArgumentException('That photo could not be read.');

    if ($ext === 'jpg' && function_exists('exif_read_data')) {
        $orient = (int)(@exif_read_data($tmpPath)['Orientation'] ?? 1);
        $deg = [3 => 180, 6 => -90, 8 => 90][$orient] ?? 0;
        if ($deg) $src = imagerotate($src, $deg, 0) ?: $src;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, IMG_MAX_EDGE / max($w, $h));
    $dst = $scale < 1 ? imagescale($src, max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale))) : $src;
    if ($ext === 'png') { imagealphablending($dst, false); imagesavealpha($dst, true); }

    $dir = arc_cfg('uploads');
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    $ok = match ($ext) {
        'jpg' => imagejpeg($dst, "$dir/$name", 82),
        'png' => imagepng($dst, "$dir/$name", 6),
        'webp' => imagewebp($dst, "$dir/$name", 82),
    };
    if (!$ok) throw new InvalidArgumentException('The photo could not be saved.');
    return 'uploads/' . $name;
}
```

- [ ] **Step 4: Wire it up**

In `admin/lib/bootstrap.php` change the list to `['config', 'util', 'validate', 'store', 'auth', 'images', 'handlers']`.

Append to `admin/lib/handlers.php`:

```php
function handle_upload(array $user, array $in): array
{
    if ((int)($in['err'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return api_err('The upload did not finish. Please try again.', 400);
    try {
        $path = images_process((string)($in['tmp'] ?? ''));
    } catch (InvalidArgumentException $e) {
        return api_err($e->getMessage(), 422);
    }
    log_line('activity', "{$user['username']} uploaded $path");
    return api_ok(['path' => $path]);
}
```

In `admin/api.php` add the route to the table:

```php
        'upload'       => ['POST', 'handle_upload'],
```

`uploads/.htaccess` (stops any script from running even if one got in):

```
Options -ExecCGI -Indexes
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps
<FilesMatch "\.(php|phtml|php[0-9]|phps|pl|py|cgi|sh)$">
  Require all denied
</FilesMatch>
```

Create an empty `uploads/.gitkeep`.

- [ ] **Step 5: Run to verify pass**

Run: `php admin/tests/check.php`
Expected: `N tests, 0 failed`.

- [ ] **Step 6: Checkpoint**

Commit (if git exists): `feat: safe image upload`.

---

### Task 7: Admin shell: login, logout, first-time setup, dashboard frame

**Files:**
- Create: `admin/lib/page.php`, `admin/index.php`, `admin/setup.php`, `admin/logout.php`, `admin/app.php`, `admin/assets/admin.css`, `admin/assets/app.js`

**Interfaces:**
- Consumes: Tasks 3 to 5 (`bootstrap.php`, `auth_*`, `csrf_*`, `can`, `api.php`).
- Produces:
  - PHP: `e(mixed): string` (HTML-escape), `page_head(string $title, string $bodyAttrs = ''): void`, `page_foot(string $scripts = ''): void`
  - Browser `window.ARC` with: `h(tag, props, ...kids)`, `api(action, {method, json, form})`, `toast(msg, kind)`, `saveSection(section, data)`, `field(label, control, hint)`, `textInput(obj, key, {max, multiline, placeholder})`, `selectInput(obj, key, [[value, label]])`, `toggleField(label, obj, key)`, `photoField(obj, key, altKey)`, `btn(label, onClick, props)`, `card(title, sub, ...children)`, `errorBox()`, `move(arr, i, dir)`, `reload()`, `tabs` (registry: `ARC.tabs[key] = {render(root)}`), `state` (`{content, revision}`), `dirty` flag
  - Pages: `/admin/` (login), `/admin/setup.php` (one time), `/admin/app.php` (dashboard), `/admin/logout.php` (POST)

- [ ] **Step 1: Create `admin/lib/page.php`**

```php
<?php
declare(strict_types=1);

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function page_head(string $title, string $bodyAttrs = ''): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . e($title) . ' · The ARC Bistro</title>'
        . '<link rel="stylesheet" href="assets/admin.css"></head><body ' . $bodyAttrs . '>';
}

function page_foot(string $scripts = ''): void { echo $scripts . '</body></html>'; }
```

- [ ] **Step 2: Create the login page `admin/index.php`**

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/page.php';

auth_session_start();
if (!is_file(arc_cfg('data') . '/setup.lock')) { header('Location: setup.php'); exit; }
if (auth_current()) { header('Location: app.php'); exit; }
if (csrf_token() === '') $_SESSION['csrf'] = bin2hex(random_bytes(16));

$error = '';
$notice = isset($_GET['expired']) ? 'You were signed out. Please log in again.' : (isset($_GET['reset']) ? 'Your password was changed. Please log in.' : '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'Please try again.';
    } else {
        $r = auth_login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? '0');
        if ($r['ok']) { auth_begin($r['user']); header('Location: app.php'); exit; }
        $error = $r['error'];
    }
}
page_head('Log in', 'class="auth"');
?>
<main class="auth__box">
  <h1>The ARC Bistro</h1>
  <p class="muted">Website admin</p>
  <?php if ($notice): ?><p class="notice"><?= e($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label class="field"><span class="field__label">Username</span><input name="username" type="text" autocomplete="username" required autofocus></label>
    <label class="field"><span class="field__label">Password</span><input name="password" type="password" autocomplete="current-password" required></label>
    <button class="btn primary" type="submit">Log in</button>
  </form>
  <p><a href="forgot.php">Forgot your password?</a></p>
</main>
<?php page_foot();
```

- [ ] **Step 3: Create the first-time setup page `admin/setup.php`**

The page requires a setup key that your developer places on the server (`admin/data/setup.key`), so a stranger who finds the URL first cannot take over the admin.

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/page.php';

auth_session_start();
$lock = arc_cfg('data') . '/setup.lock';
if (is_file($lock)) { header('Location: index.php'); exit; }
if (csrf_token() === '') $_SESSION['csrf'] = bin2hex(random_bytes(16));

$keyFile = arc_cfg('data') . '/setup.key';
$expected = is_file($keyFile) ? trim((string)file_get_contents($keyFile)) : '';
$error = '';
if ($expected !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = $_POST;
    try {
        if (!csrf_valid((string)($p['csrf'] ?? ''))) throw new InvalidArgumentException('Please try again.');
        if (!hash_equals($expected, trim((string)($p['key'] ?? '')))) throw new InvalidArgumentException('The setup key is not correct.');
        if (($p['dev_password'] ?? '') !== ($p['dev_password2'] ?? '') || ($p['own_password'] ?? '') !== ($p['own_password2'] ?? '')) {
            throw new InvalidArgumentException('The two passwords must match.');
        }
        user_create((string)$p['dev_username'], (string)$p['dev_email'], (string)$p['dev_password'], 'developer');
        user_create((string)$p['own_username'], (string)$p['own_email'], (string)$p['own_password'], 'owner');
        file_put_contents($lock, date('c'));
        @unlink($keyFile);
        header('Location: index.php'); exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}
page_head('Set up', 'class="auth"');
?>
<main class="auth__box auth__box--wide">
  <h1>First-time setup</h1>
  <?php if ($expected === ''): ?>
    <p class="error">Setup is not unlocked. Ask your developer to create the file <code>admin/data/setup.key</code> containing a secret word, then reload this page.</p>
  <?php else: ?>
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label class="field"><span class="field__label">Setup key</span><input name="key" type="password" required></label>
      <h2>Developer account</h2>
      <label class="field"><span class="field__label">Username</span><input name="dev_username" required></label>
      <label class="field"><span class="field__label">Email</span><input name="dev_email" type="email" required></label>
      <label class="field"><span class="field__label">Password (10+ characters)</span><input name="dev_password" type="password" minlength="10" required></label>
      <label class="field"><span class="field__label">Repeat password</span><input name="dev_password2" type="password" minlength="10" required></label>
      <h2>Owner account</h2>
      <label class="field"><span class="field__label">Username</span><input name="own_username" required></label>
      <label class="field"><span class="field__label">Email</span><input name="own_email" type="email" required></label>
      <label class="field"><span class="field__label">Password (10+ characters)</span><input name="own_password" type="password" minlength="10" required></label>
      <label class="field"><span class="field__label">Repeat password</span><input name="own_password2" type="password" minlength="10" required></label>
      <button class="btn primary" type="submit">Create accounts</button>
    </form>
  <?php endif; ?>
</main>
<?php page_foot();
```

- [ ] **Step 4: Create `admin/logout.php`**

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
auth_session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid((string)($_POST['csrf'] ?? ''))) auth_end();
header('Location: index.php');
```

- [ ] **Step 5: Create the dashboard shell `admin/app.php`**

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/page.php';

auth_session_start();
$user = auth_current();
if (!$user) { header('Location: index.php'); exit; }

$tabs = [['menu', 'Menu'], ['promos', 'Promos']];
if ($user['role'] !== 'staff') $tabs[] = ['hours', 'Hours & Contact'];
if (can($user['role'], 'history')) $tabs[] = ['history', 'History'];
if (can($user['role'], 'accounts')) $tabs[] = ['accounts', 'Accounts'];
if (can($user['role'], 'system')) $tabs[] = ['system', 'System'];

page_head('Dashboard', 'data-csrf="' . e(csrf_token()) . '" data-role="' . e($user['role']) . '" data-user="' . e($user['username']) . '" data-tabs="' . e(json_encode($tabs)) . '"');
?>
<header class="top">
  <strong>The ARC Bistro admin</strong>
  <span class="top__spacer"></span>
  <a class="btn" href="../" target="_blank" rel="noopener">View site</a>
  <span class="muted"><?= e($user['username']) ?> (<?= e($user['role']) ?>)</span>
  <form method="post" action="logout.php">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <button class="btn" type="submit">Log out</button>
  </form>
</header>
<div class="layout">
  <nav id="nav" aria-label="Sections"></nav>
  <main id="main"><p class="muted">Loading…</p></main>
</div>
<div id="toast" role="status" aria-live="polite"></div>
<?php page_foot(
    '<script src="assets/app.js"></script>'
    . '<script src="assets/tab-menu.js"></script><script src="assets/tab-promos.js"></script><script src="assets/tab-hours.js"></script>'
    . '<script src="assets/tab-history.js"></script><script src="assets/tab-accounts.js"></script><script src="assets/tab-system.js"></script>'
);
```

(The `tab-*.js` files are created in Tasks 8 and 10. Until then, create each as an empty file so the page loads without 404s; Task 8 and 10 overwrite them.)

- [ ] **Step 6: Create `admin/assets/app.js` (shared helpers, tab router)**

```js
(function () {
  const ARC = (window.ARC = { tabs: {}, state: { content: null, revision: 0 }, dirty: false, current: '' });
  const body = document.body;

  function h(tag, props, ...kids) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(props || {})) {
      if (k === 'class') el.className = v;
      else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else if (v === true) el.setAttribute(k, '');
      else if (v !== false && v != null) el.setAttribute(k, v);
    }
    kids.flat().forEach(c => { if (c != null && c !== false) el.append(c.nodeType ? c : document.createTextNode(String(c))); });
    return el;
  }

  async function api(action, opts) {
    const o = opts || {};
    const init = { method: o.method || 'GET', headers: { 'X-CSRF-Token': body.dataset.csrf }, credentials: 'same-origin' };
    if (o.json !== undefined) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(o.json); }
    if (o.form) init.body = o.form;
    const res = await fetch('api.php?action=' + action, init);
    if (res.status === 401) { location.href = 'index.php?expired=1'; throw new Error('expired'); }
    const data = await res.json().catch(() => ({ ok: false, error: 'The server sent an unexpected answer.' }));
    data.status = res.status;
    return data;
  }

  let toastTimer;
  function toast(msg, kind) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show ' + (kind || 'ok');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.className = 'toast'; }, 5000);
  }

  function btn(label, onClick, props) {
    return h('button', Object.assign({ type: 'button', class: 'btn', onclick: onClick }, props || {}), label);
  }

  function card(title, sub, ...children) {
    return h('section', { class: 'card' }, h('h2', {}, title), sub ? h('p', { class: 'card__sub' }, sub) : null, ...children);
  }

  function field(label, control, hint) {
    return h('label', { class: 'field' }, h('span', { class: 'field__label' }, label), control, hint ? h('span', { class: 'field__hint' }, hint) : null);
  }

  function textInput(obj, key, o) {
    const opt = o || {};
    const val = obj[key] == null ? '' : String(obj[key]);
    const el = opt.multiline
      ? h('textarea', { rows: opt.rows || 3, maxlength: opt.max }, val)
      : h('input', { type: opt.type || 'text', value: val, maxlength: opt.max, placeholder: opt.placeholder, inputmode: opt.inputmode });
    const counter = opt.max ? h('span', { class: 'counter' }, val.length + '/' + opt.max) : null;
    el.addEventListener('input', () => {
      obj[key] = el.value;
      ARC.dirty = true;
      if (counter) counter.textContent = el.value.length + '/' + opt.max;
    });
    return h('div', { class: 'input' }, el, counter);
  }

  function selectInput(obj, key, options) {
    const sel = h('select', {}, options.map(([v, l]) => h('option', { value: v, selected: String(obj[key]) === String(v) }, l)));
    sel.addEventListener('change', () => { obj[key] = sel.value; ARC.dirty = true; });
    return sel;
  }

  function toggleField(label, obj, key, onChange) {
    const cb = h('input', { type: 'checkbox', checked: obj[key] === true });
    cb.addEventListener('change', () => { obj[key] = cb.checked; ARC.dirty = true; if (onChange) onChange(); });
    return h('label', { class: 'toggle' }, cb, h('span', {}, label));
  }

  function errorBox() {
    const ul = h('ul', {});
    const el = h('div', { class: 'errors', role: 'alert', hidden: true }, h('strong', {}, 'Please fix these:'), ul);
    return {
      el,
      show(list) {
        ul.replaceChildren(...(list || []).map(m => h('li', {}, m)));
        el.hidden = !(list && list.length);
      }
    };
  }

  function move(arr, i, dir) {
    const j = i + dir;
    if (j < 0 || j >= arr.length) return;
    [arr[i], arr[j]] = [arr[j], arr[i]];
    ARC.dirty = true;
  }

  function photoField(obj, key, altKey) {
    const prev = h('div', { class: 'photo__preview' });
    const status = h('span', { class: 'field__hint' });
    const input = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', class: 'visually-hidden' });
    const draw = () => prev.replaceChildren(obj[key] ? h('img', { src: '../' + obj[key], alt: '' }) : h('span', { class: 'photo__empty' }, 'No photo yet'));
    input.addEventListener('change', async () => {
      const f = input.files[0];
      if (!f) return;
      if (f.size > 5 * 1024 * 1024) { status.textContent = 'That photo is larger than 5 MB.'; input.value = ''; return; }
      status.textContent = 'Uploading…';
      const fd = new FormData();
      fd.append('photo', f);
      const r = await api('upload', { method: 'POST', form: fd });
      if (r.ok) { obj[key] = r.path; ARC.dirty = true; status.textContent = ''; draw(); } else status.textContent = r.error;
      input.value = '';
    });
    draw();
    return h('div', { class: 'photo' }, prev, h('div', { class: 'photo__side' },
      h('label', { class: 'btn' }, obj[key] ? 'Change photo' : 'Choose photo', input),
      obj[key] ? btn('Remove photo', () => { obj[key] = ''; ARC.dirty = true; draw(); }) : null,
      status,
      altKey ? field('Describe the photo (for screen readers)', textInput(obj, altKey, { max: 80 })) : null,
      h('span', { class: 'field__hint' }, 'JPG, PNG or WebP, up to 5 MB. Portrait photos work best.')
    ));
  }

  async function saveSection(section, data) {
    const r = await api('content.save', { method: 'POST', json: { section, data, baseRevision: ARC.state.revision } });
    if (r.ok) {
      ARC.state.revision = r.revision;
      ARC.state.content[section] = r.data;
      ARC.dirty = false;
      toast('Saved. Your changes are live on the website.');
    } else if (r.status === 409) {
      toast(r.error, 'error');
    } else if (r.status !== 422) {
      toast(r.error || 'Could not save.', 'error');
    }
    return r;
  }

  async function loadContent() {
    const r = await api('content.get');
    if (!r.ok) { toast(r.error || 'Could not load the content.', 'error'); return false; }
    ARC.state.content = r.content;
    ARC.state.revision = r.content.revision || 0;
    return true;
  }

  function show(key) {
    if (ARC.dirty && !confirm('You have unsaved changes. Leave without saving?')) return;
    ARC.dirty = false;
    ARC.current = key;
    document.querySelectorAll('#nav button').forEach(b => b.setAttribute('aria-current', String(b.dataset.key === key)));
    const main = document.getElementById('main');
    main.replaceChildren();
    ARC.tabs[key].render(main);
    window.scrollTo(0, 0);
  }

  async function reload() {
    ARC.dirty = false;
    if (await loadContent()) show(ARC.current);
  }

  async function start() {
    const tabs = JSON.parse(body.dataset.tabs);
    const nav = document.getElementById('nav');
    tabs.forEach(([key, label]) => nav.append(h('button', { type: 'button', 'data-key': key, onclick: () => show(key) }, label)));
    window.addEventListener('beforeunload', e => { if (ARC.dirty) { e.preventDefault(); e.returnValue = ''; } });
    if (await loadContent()) show(tabs[0][0]);
  }

  Object.assign(ARC, { h, api, toast, btn, card, field, textInput, selectInput, toggleField, errorBox, move, photoField, saveSection, loadContent, reload, start });
  document.addEventListener('DOMContentLoaded', () => { if (document.getElementById('nav')) start(); });
})();
```

- [ ] **Step 7: Create `admin/assets/admin.css`**

```css
:root{--forest:#2F3A2E;--olive:#46472B;--paper:#FAF7F0;--sand:#EEE7D9;--ink:#1D221C;--muted:#5d645b;--line:#d9d2c2;--brass:#a8844f;--bad:#a12b2b;--good:#2b6a3a}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
h1,h2{font-family:Georgia,"Times New Roman",serif;font-weight:400;margin:0 0 .4em}
h2{font-size:1.35rem}
a{color:var(--forest)}
.muted{color:var(--muted)}
.visually-hidden{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.4em;padding:.6em 1.1em;border:1px solid var(--forest);background:transparent;color:var(--forest);border-radius:6px;font:inherit;cursor:pointer;min-height:44px;text-decoration:none}
.btn:hover{background:var(--forest);color:var(--paper)}
.btn:disabled{opacity:.45;cursor:default;background:transparent;color:var(--forest)}
.btn.primary{background:var(--forest);color:var(--paper)}
.btn.primary:hover{background:var(--olive)}
.btn.danger{border-color:var(--bad);color:var(--bad)}
.btn.danger:hover{background:var(--bad);color:#fff}
:focus-visible{outline:3px solid var(--brass);outline-offset:2px}
.top{display:flex;align-items:center;gap:14px;padding:10px 20px;background:var(--forest);color:var(--paper);flex-wrap:wrap}
.top .btn{border-color:var(--paper);color:var(--paper)}
.top .btn:hover{background:var(--paper);color:var(--forest)}
.top__spacer{flex:1}
.top form{margin:0}
.layout{display:grid;grid-template-columns:200px minmax(0,1fr);gap:24px;max-width:1100px;margin:0 auto;padding:24px 20px 80px}
#nav{display:flex;flex-direction:column;gap:6px;align-self:start;position:sticky;top:16px}
#nav button{text-align:left;padding:.7em 1em;border:1px solid transparent;border-radius:6px;background:transparent;font:inherit;cursor:pointer;min-height:44px}
#nav button:hover{background:var(--sand)}
#nav button[aria-current="true"]{background:var(--forest);color:var(--paper)}
.card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:22px;margin-bottom:22px}
.card__sub{margin:0 0 16px;color:var(--muted)}
.stack{display:grid;gap:14px;margin-bottom:14px}
.item{border:1px solid var(--line);border-radius:8px;padding:16px;background:var(--paper)}
.item__head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;flex-wrap:wrap}
.item__actions{display:flex;gap:6px;flex-wrap:wrap}
.item__actions .btn{padding:.35em .8em;min-height:36px}
.field{display:grid;gap:4px;margin-bottom:12px}
.field__label{font-weight:600;font-size:.92rem}
.field__hint,.counter{color:var(--muted);font-size:.82rem}
.input{position:relative}
.input .counter{position:absolute;right:8px;bottom:6px;background:#fffd;padding:0 4px}
input[type=text],input[type=email],input[type=password],input[type=date],input[type=time],input:not([type]),textarea,select{width:100%;padding:.6em .75em;border:1px solid var(--line);border-radius:6px;font:inherit;background:#fff;color:var(--ink);min-height:44px}
textarea{resize:vertical}
.toggle{display:flex;align-items:center;gap:10px;margin:6px 0 12px;min-height:44px}
.toggle input{width:22px;height:22px}
.photo{display:grid;grid-template-columns:140px minmax(0,1fr);gap:16px;margin-bottom:12px}
.photo__preview{aspect-ratio:3/4;background:var(--sand);border-radius:8px;overflow:hidden;display:grid;place-items:center}
.photo__preview img{width:100%;height:100%;object-fit:cover}
.photo__empty{color:var(--muted);font-size:.85rem;padding:8px;text-align:center}
.photo__side{display:flex;flex-direction:column;gap:8px;align-items:flex-start}
.errors{border:1px solid var(--bad);background:#fbeeee;color:var(--bad);border-radius:8px;padding:10px 14px;margin:12px 0}
.errors ul{margin:.3em 0 0;padding-left:1.2em}
.error{color:var(--bad)}
.notice{color:var(--good)}
.row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.table{width:100%;border-collapse:collapse}
.table th,.table td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:middle}
.chip{display:inline-block;padding:.1em .6em;border-radius:99px;font-size:.78rem;border:1px solid var(--line)}
.chip.live{background:#e5f3e8;border-color:#b4d8bb;color:var(--good)}
.toast{position:fixed;left:50%;bottom:24px;transform:translate(-50%,20px);opacity:0;pointer-events:none;background:var(--forest);color:#fff;padding:12px 18px;border-radius:8px;transition:.3s;max-width:90vw;z-index:50}
.toast.show{opacity:1;transform:translate(-50%,0)}
.toast.error{background:var(--bad)}
.auth{display:grid;place-items:center;min-height:100vh;padding:20px}
.auth__box{width:min(400px,100%);background:#fff;border:1px solid var(--line);border-radius:12px;padding:28px}
.auth__box--wide{width:min(520px,100%)}
pre.log{background:#1d221c;color:#e8e4d8;padding:14px;border-radius:8px;overflow:auto;max-height:360px;font-size:.8rem}
@media (max-width:760px){.layout{grid-template-columns:minmax(0,1fr)}#nav{flex-direction:row;overflow-x:auto;position:static}.photo{grid-template-columns:100px minmax(0,1fr)}}
```

- [ ] **Step 8: Create placeholder tab files so the page loads**

Create empty files: `admin/assets/tab-menu.js`, `tab-promos.js`, `tab-hours.js`, `tab-history.js`, `tab-accounts.js`, `tab-system.js`. Until a tab is written, selecting it throws in the console. That is expected at this point.

- [ ] **Step 9: Verify setup, login, lockout, logout**

Run from the project root: `php -S localhost:8080`. Open http://localhost:8080/admin/ .
Expected, in order:
1. It redirects to `setup.php`, showing "Setup is not unlocked".
2. Create `admin/data/setup.key` containing `letmein123`, reload: the form appears. Submit with the wrong key: "The setup key is not correct." Submit with valid developer and owner details (passwords 10+ characters, matching): you land on the login page. `setup.key` is deleted and `admin/data/setup.lock` exists. Opening `setup.php` again redirects to the login.
3. Log in with the owner account: the dashboard shell shows the sidebar `Menu, Promos, Hours & Contact, History, Accounts` and the top bar with your name.
4. Log out, then enter a wrong password 5 times: the 6th attempt says "Too many attempts. Try again in 10 minute(s)." even with the right password.
5. Delete `admin/data/attempts.json`, log in, wait is not needed; click **Log out**: you return to the login page and pressing Back then Refresh keeps you on the login page.

(The built-in PHP server ignores `.htaccess`, so access protection for `admin/data/` is checked on Hostinger in Task 11.)

- [ ] **Step 10: Checkpoint**

Commit (if git exists): `feat: admin login, setup, dashboard shell`. Do not commit `admin/data/users.json`, `admin/data/setup.lock` or logs.

---

### Task 8: Content editing tabs (Menu, Promos, Hours & Contact)

**Files:**
- Create (overwrite the empty placeholders): `admin/assets/tab-menu.js`, `admin/assets/tab-promos.js`, `admin/assets/tab-hours.js`

**Interfaces:**
- Consumes: `window.ARC` helpers from Task 7; `content.save` sections from Task 5; `upload` from Task 6.
- Produces: `ARC.tabs.menu`, `ARC.tabs.promos`, `ARC.tabs.hours`, each `{ render(root: HTMLElement): void }`.

Each card edits a private deep copy (`structuredClone`) of its section and only publishes on that card's Save button. After a successful save the card redraws from the server's cleaned copy. On a 422 the server's messages show in the red box.

- [ ] **Step 1: Write `admin/assets/tab-menu.js`**

```js
(function () {
  const { h, btn, card, field, textInput, selectInput, toggleField, errorBox, move, photoField, saveSection } = ARC;

  function featuredCard(draft) {
    const list = h('div', { class: 'stack' });
    const errs = errorBox();
    const addBtn = btn('+ Add a dish', () => {
      draft.push({ name: '', tag: '', description: '', image: '', alt: '', hidden: false });
      ARC.dirty = true; draw();
    });
    function dish(d, i) {
      return h('div', { class: 'item' },
        h('div', { class: 'item__head' },
          h('strong', {}, d.name || 'New dish'),
          h('div', { class: 'item__actions' },
            btn('Move up', () => { move(draft, i, -1); draw(); }, { disabled: i === 0 }),
            btn('Move down', () => { move(draft, i, 1); draw(); }, { disabled: i === draft.length - 1 }),
            btn('Delete', () => { if (confirm('Delete this dish?')) { draft.splice(i, 1); ARC.dirty = true; draw(); } }, { class: 'btn danger' }))),
        photoField(d, 'image', 'alt'),
        field('Name', textInput(d, 'name', { max: 40 })),
        field('Small label above the name', textInput(d, 'tag', { max: 30, placeholder: 'e.g. Italian · Pasta' })),
        field('Description', textInput(d, 'description', { max: 140, multiline: true })),
        toggleField('Hide this dish on the website', d, 'hidden'));
    }
    function draw() {
      list.replaceChildren(...draft.map(dish));
      addBtn.disabled = draft.length >= 8;
    }
    const save = btn('Save dishes', async e => {
      e.target.disabled = true;
      const r = await saveSection('featured', draft);
      errs.show(r.errors);
      if (r.ok) { draft.splice(0, draft.length, ...structuredClone(r.data)); draw(); }
      e.target.disabled = false;
    }, { class: 'btn primary' });
    draw();
    return card('Featured dishes', 'The big photo list in the Menu section. Up to 8 dishes.', list, addBtn, errs.el, h('p', {}, save));
  }

  function menuCard(draft) {
    const list = h('div', { class: 'stack' });
    const errs = errorBox();
    const addCat = btn('+ Add a category', () => { draft.push({ name: '', items: [] }); ARC.dirty = true; draw(); });
    function item(cat, it, j) {
      return h('div', { class: 'item' },
        h('div', { class: 'item__head' }, h('strong', {}, it.name || 'New item'),
          h('div', { class: 'item__actions' },
            btn('Up', () => { move(cat.items, j, -1); draw(); }, { disabled: j === 0 }),
            btn('Down', () => { move(cat.items, j, 1); draw(); }, { disabled: j === cat.items.length - 1 }),
            btn('Delete', () => { cat.items.splice(j, 1); ARC.dirty = true; draw(); }, { class: 'btn danger' }))),
        h('div', { class: 'row' },
          field('Name', textInput(it, 'name', { max: 50 })),
          field('Price (₱)', textInput(it, 'price', { inputmode: 'decimal', placeholder: 'e.g. 285' })),
          field('Status', selectInput(it, 'status', [['available', 'Available'], ['soldout', 'Sold out'], ['hidden', 'Hidden']]))),
        field('Description (optional)', textInput(it, 'description', { max: 100 })));
    }
    function category(cat, i) {
      return h('div', { class: 'item' },
        h('div', { class: 'item__head' }, h('strong', {}, cat.name || 'New category'),
          h('div', { class: 'item__actions' },
            btn('Move up', () => { move(draft, i, -1); draw(); }, { disabled: i === 0 }),
            btn('Move down', () => { move(draft, i, 1); draw(); }, { disabled: i === draft.length - 1 }),
            btn('Delete category', () => { if (confirm('Delete this category and all its items?')) { draft.splice(i, 1); ARC.dirty = true; draw(); } }, { class: 'btn danger' }))),
        field('Category name', textInput(cat, 'name', { max: 30, placeholder: 'e.g. Pasta' })),
        h('div', { class: 'stack' }, cat.items.map((it, j) => item(cat, it, j))),
        btn('+ Add an item', () => { cat.items.push({ name: '', description: '', price: '', status: 'available' }); ARC.dirty = true; draw(); }, { disabled: cat.items.length >= 20 }));
    }
    function draw() {
      list.replaceChildren(...draft.map(category));
      addCat.disabled = draft.length >= 8;
    }
    const save = btn('Save menu', async e => {
      e.target.disabled = true;
      const r = await saveSection('menu', draft);
      errs.show(r.errors);
      if (r.ok) { draft.splice(0, draft.length, ...structuredClone(r.data)); draw(); }
      e.target.disabled = false;
    }, { class: 'btn primary' });
    draw();
    return card('Full menu with prices', 'Shows as a price list under the featured dishes. It stays hidden until you add a category with at least one item.', list, addCat, errs.el, h('p', {}, save));
  }

  ARC.tabs.menu = {
    render(root) {
      const c = ARC.state.content;
      root.append(featuredCard(structuredClone(c.featured || [])), menuCard(structuredClone(c.menu || [])));
    }
  };
})();
```

- [ ] **Step 2: Write `admin/assets/tab-promos.js`**

```js
(function () {
  const { h, btn, card, field, textInput, toggleField, errorBox, move, photoField, saveSection } = ARC;
  const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });

  function status(p) {
    if (!p.active) return h('span', { class: 'chip' }, 'Switched off');
    const t = today();
    if (p.start && p.start > t) return h('span', { class: 'chip' }, 'Scheduled');
    if (p.end && p.end < t) return h('span', { class: 'chip' }, 'Ended');
    return h('span', { class: 'chip live' }, 'Live now');
  }

  ARC.tabs.promos = {
    render(root) {
      const draft = structuredClone(ARC.state.content.promos || []);
      const list = h('div', { class: 'stack' });
      const errs = errorBox();
      const add = btn('+ Add a promo', () => {
        draft.push({ title: '', details: '', image: '', alt: '', start: '', end: '', active: true });
        ARC.dirty = true; draw();
      });
      function promo(p, i) {
        return h('div', { class: 'item' },
          h('div', { class: 'item__head' }, h('strong', {}, p.title || 'New promo'), status(p),
            h('div', { class: 'item__actions' },
              btn('Move up', () => { move(draft, i, -1); draw(); }, { disabled: i === 0 }),
              btn('Move down', () => { move(draft, i, 1); draw(); }, { disabled: i === draft.length - 1 }),
              btn('Delete', () => { if (confirm('Delete this promo?')) { draft.splice(i, 1); ARC.dirty = true; draw(); } }, { class: 'btn danger' }))),
          field('Title', textInput(p, 'title', { max: 50 })),
          field('Details', textInput(p, 'details', { max: 200, multiline: true })),
          photoField(p, 'image', 'alt'),
          h('div', { class: 'row' },
            field('Starts (optional)', textInput(p, 'start', { type: 'date' })),
            field('Ends (optional)', textInput(p, 'end', { type: 'date' }))),
          toggleField('Show this promo on the website', p, 'active', draw));
      }
      function draw() {
        list.replaceChildren(...draft.map(promo));
        add.disabled = draft.length >= 6;
      }
      const save = btn('Save promos', async e => {
        e.target.disabled = true;
        const r = await saveSection('promos', draft);
        errs.show(r.errors);
        if (r.ok) { draft.splice(0, draft.length, ...structuredClone(r.data)); draw(); }
        e.target.disabled = false;
      }, { class: 'btn primary' });
      draw();
      root.append(card('Promos', 'A promo appears on the website only while it is switched on and today is between its dates. When no promo is live, the whole promo section disappears.', list, add, errs.el, h('p', {}, save)));
    }
  };
})();
```

- [ ] **Step 3: Write `admin/assets/tab-hours.js`**

```js
(function () {
  const { h, btn, card, field, textInput, selectInput, errorBox, saveSection } = ARC;
  const DAYS = [['mon', 'Monday'], ['tue', 'Tuesday'], ['wed', 'Wednesday'], ['thu', 'Thursday'], ['fri', 'Friday'], ['sat', 'Saturday'], ['sun', 'Sunday']];

  function saveButton(label, section, getDraft, errs, after) {
    return btn(label, async e => {
      e.target.disabled = true;
      const r = await saveSection(section, getDraft());
      errs.show(r.errors);
      if (r.ok && after) after(r.data);
      e.target.disabled = false;
    }, { class: 'btn primary' });
  }

  function hoursCard(c) {
    const by = {};
    (c.hours || []).forEach(d => { by[d.day] = Object.assign({ from: '10:00', to: '21:00' }, d); });
    const rows = DAYS.map(([key, name]) => {
      const d = by[key] || { day: key, status: 'closed', from: '10:00', to: '21:00' };
      by[key] = d;
      const times = h('div', { class: 'row' }, field('Opens', textInput(d, 'from', { type: 'time' })), field('Closes', textInput(d, 'to', { type: 'time' })));
      const sel = selectInput(d, 'status', [['open', 'Open'], ['closed', 'Closed'], ['call', 'Call ahead']]);
      const sync = () => { times.hidden = d.status !== 'open'; };
      sel.addEventListener('change', sync);
      sync();
      return h('div', { class: 'item' }, h('div', { class: 'row' }, field(name, sel)), times);
    });
    const errs = errorBox();
    const draftOut = () => DAYS.map(([key]) => {
      const d = by[key];
      return d.status === 'open' ? { day: key, status: 'open', from: d.from, to: d.to } : { day: key, status: d.status };
    });
    return card('Opening hours', 'Days with the same hours are grouped on the website, for example "Tuesday – Sunday".', h('div', { class: 'stack' }, rows), errs.el, h('p', {}, saveButton('Save hours', 'hours', draftOut, errs)));
  }

  function priceCard(c) {
    const draft = Object.assign({ level: 2, label: '' }, structuredClone(c.price || {}));
    const errs = errorBox();
    return card('Price range', 'Shown in the About facts and the "Good to know" box.',
      field('Price level', selectInput(draft, 'level', [[1, '₱ (budget)'], [2, '₱₱ (moderate)'], [3, '₱₱₱ (premium)']])),
      field('Short description', textInput(draft, 'label', { max: 40 })),
      errs.el, h('p', {}, saveButton('Save price range', 'price', () => Object.assign({}, draft, { level: Number(draft.level) }), errs)));
  }

  function contactCard(c) {
    const draft = Object.assign({ phone: '', email: '', address: '', addressShort: '' }, structuredClone(c.contact || {}));
    const errs = errorBox();
    return card('Contact details', 'The phone number and email update everywhere on the website, including the call and email links.',
      field('Phone number', textInput(draft, 'phone', { max: 20, placeholder: '+63 995 109 1503' })),
      field('Email', textInput(draft, 'email', { max: 120, type: 'email' })),
      field('Address (up to 3 lines, shown in the footer and the Visit section)', textInput(draft, 'address', { max: 160, multiline: true })),
      field('Short address (one line, shown in the top bar)', textInput(draft, 'addressShort', { max: 80 })),
      errs.el, h('p', {}, saveButton('Save contact details', 'contact', () => draft, errs)));
  }

  function socialCard(c) {
    const draft = structuredClone(c.social || []);
    const list = h('div', { class: 'stack' });
    const errs = errorBox();
    const add = btn('+ Add a link', () => { draft.push({ label: '', url: '' }); ARC.dirty = true; draw(); });
    function draw() {
      list.replaceChildren(...draft.map((s, i) => h('div', { class: 'item' },
        h('div', { class: 'row' },
          field('Name', textInput(s, 'label', { max: 20, placeholder: 'Facebook' })),
          field('Link (must start with https://)', textInput(s, 'url', { max: 300, placeholder: 'https://' }))),
        btn('Remove', () => { draft.splice(i, 1); ARC.dirty = true; draw(); }, { class: 'btn danger' }))));
      add.disabled = draft.length >= 6;
    }
    draw();
    return card('Social media links', 'Facebook links fill the Facebook buttons on the page. Other links appear in the footer. Remove a link to hide it.',
      list, add, errs.el, h('p', {}, saveButton('Save links', 'social', () => draft, errs, d => { draft.splice(0, draft.length, ...structuredClone(d)); draw(); })));
  }

  ARC.tabs.hours = {
    render(root) {
      const c = ARC.state.content;
      root.append(hoursCard(c), priceCard(c), contactCard(c), socialCard(c));
    }
  };
})();
```

- [ ] **Step 4: Verify the three tabs end to end**

Run `php -S localhost:8080`, log in as the owner. In one browser tab keep the public site open at http://localhost:8080/ .
Expected:
1. **Menu tab:** change the first dish name, add a dish with a photo upload (use any JPG), press Save dishes. The toast says "Saved. Your changes are live". Reload the public page: the new dish name and the new dish appear, alternating text/photo sides.
2. Add a category "Pasta" with an item "Carbonara", price `285`, Save menu: the public page shows the price list with `₱285`. Set the item to Sold out and save: it shows "Sold out". Delete the category and save: the price list disappears.
3. **Promos tab:** add a promo titled "Weekend coffee deal", active, no dates. Save: the public page shows the promo section. Set Ends to yesterday and save: the section disappears and the chip says "Ended".
4. **Hours & Contact:** change the phone, save: every phone number and `tel:` link on the public page updates. Set Monday to Closed, save: public hours show "Monday: Closed".
5. **Error handling:** clear the dish name and save: a red box says "Dish 1 name is required" and nothing changes on the public page. Type a 200-character description: the counter stops at the limit.
6. **Conflict:** open the admin in two browser windows, save a price change in window A, then save a different price change in window B without reloading. Window B shows "Someone else changed this…".
7. Choose a `.txt` file renamed to `.jpg` in a photo field: the message says "Please upload a JPG, PNG or WebP photo."
8. Log in as a Staff account (create it in Task 10, then repeat this check): only Menu and Promos appear.

- [ ] **Step 5: Checkpoint**

Commit (if git exists): `feat: menu, promos, hours and contact editors`.

---

### Task 9: Email password reset (with tests)

**Files:**
- Create: `admin/lib/mail.php`, `admin/lib/reset.php`, `admin/forgot.php`, `admin/reset.php`, `admin/tests/test_reset.php`, `admin/lib/vendor/PHPMailer/{Exception.php,PHPMailer.php,SMTP.php}`
- Modify: `admin/lib/bootstrap.php` (add `'mail'`, `'reset'`)

**Interfaces:**
- Consumes: `user_by`, `users_all`, `users_save`, `PASSWORD_MIN`, `json_read`, `json_write_atomic`, `log_line` (Tasks 3 and 4).
- Produces:
  - `mail_send(string $to, string $subject, string $text): bool`; tests replace it by setting `$GLOBALS['ARC_MAILER'] = fn($to, $subject, $body): bool`
  - `reset_request(string $email, string $ip, ?int $now = null): void` (never reveals whether the email exists)
  - `reset_lookup(string $token, ?int $now = null): ?array`, `reset_consume(string $token, string $newPassword, ?int $now = null): array{0: bool, 1: ?string}`
  - Config file `admin/data/smtp.json`: `{"host","port","user","pass","from","baseUrl"}` (`baseUrl` like `https://thearcbistro.com`; reset links always use it, never the request's Host header)
  - Constants `RESET_TTL=1800`, `RESET_MAX_PER_EMAIL_HOUR=3`, `RESET_MAX_PER_IP_HOUR=10`

- [ ] **Step 1: Vendor PHPMailer**

Download the latest PHPMailer 6.x release from https://github.com/PHPMailer/PHPMailer/releases . From the zip copy `src/Exception.php`, `src/PHPMailer.php`, `src/SMTP.php` into `admin/lib/vendor/PHPMailer/`. (No Composer needed.)

- [ ] **Step 2: Write the failing tests**

`admin/tests/test_reset.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

function capture_mail(): array
{
    $box = ['sent' => []];
    $GLOBALS['ARC_MAILER'] = function ($to, $subject, $body) use (&$box) { $box['sent'][] = compact('to', 'subject', 'body'); return true; };
    return $box;
}
function token_from(string $body): string
{
    preg_match('/token=([0-9a-f]{48})/', $body, $m);
    return $m[1] ?? '';
}

t('reset: a known email gets one mail with a token, and only a hash is stored', function () {
    fresh_env(); user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $sent = [];
    $GLOBALS['ARC_MAILER'] = function ($to, $s, $b) use (&$sent) { $sent[] = [$to, $b]; return true; };
    reset_request('a@b.co', '1.1.1.1', 1000);
    eq(count($sent), 1); eq($sent[0][0], 'a@b.co');
    $token = token_from($sent[0][1]);
    eq(strlen($token), 48);
    ok(!str_contains((string)file_get_contents(arc_cfg('data') . '/resets.json'), $token), 'raw token must not be stored');
});
t('reset: an unknown email sends nothing and raises nothing', function () {
    fresh_env();
    $sent = [];
    $GLOBALS['ARC_MAILER'] = function ($to, $s, $b) use (&$sent) { $sent[] = $to; return true; };
    reset_request('nobody@b.co', '1.1.1.1', 1000);
    reset_request('', '1.1.1.1', 1000);
    eq($sent, []);
});
t('reset: a token works once, then never again', function () {
    fresh_env(); user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $sent = [];
    $GLOBALS['ARC_MAILER'] = function ($to, $s, $b) use (&$sent) { $sent[] = $b; return true; };
    reset_request('a@b.co', '1.1.1.1', 1000);
    $t = token_from($sent[0]);
    [$ok1] = reset_consume($t, 'brandnewpass1', 1100);
    [$ok2] = reset_consume($t, 'anotherpass22', 1200);
    ok($ok1); ok(!$ok2);
    ok(auth_login('okname', 'brandnewpass1', '1.1.1.1', 1300)['ok']);
    ok(!auth_login('okname', 'longenough1', '1.1.1.1', 1300)['ok']);
});
t('reset: a token expires after 30 minutes', function () {
    fresh_env(); user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $sent = [];
    $GLOBALS['ARC_MAILER'] = function ($to, $s, $b) use (&$sent) { $sent[] = $b; return true; };
    reset_request('a@b.co', '1.1.1.1', 1000);
    $t = token_from($sent[0]);
    ok(reset_lookup($t, 1000 + 1799) !== null);
    ok(reset_lookup($t, 1000 + 1801) === null);
    [$ok] = reset_consume($t, 'brandnewpass1', 1000 + 1801);
    ok(!$ok);
});
t('reset: a weak password is refused and the token stays usable', function () {
    fresh_env(); user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $sent = [];
    $GLOBALS['ARC_MAILER'] = function ($to, $s, $b) use (&$sent) { $sent[] = $b; return true; };
    reset_request('a@b.co', '1.1.1.1', 1000);
    $t = token_from($sent[0]);
    [$ok] = reset_consume($t, 'short', 1100);
    ok(!$ok);
    [$ok] = reset_consume($t, 'brandnewpass1', 1200);
    ok($ok);
});
t('reset: the 4th request within an hour for one email sends nothing', function () {
    fresh_env(); user_create('okname', 'a@b.co', 'longenough1', 'owner');
    $n = 0;
    $GLOBALS['ARC_MAILER'] = function () use (&$n) { $n++; return true; };
    foreach ([1000, 1100, 1200, 1300] as $now) reset_request('a@b.co', '1.1.1.1', $now);
    eq($n, 3);
    reset_request('a@b.co', '1.1.1.1', 1000 + 3700);
    eq($n, 4, 'allowed again after an hour');
});
t('reset: garbage tokens are rejected', function () {
    fresh_env();
    ok(reset_lookup('nope') === null);
    ok(reset_lookup(str_repeat('z', 48)) === null);
    ok(reset_lookup('') === null);
});
```

- [ ] **Step 3: Run to verify failure**

Run: `php admin/tests/check.php`
Expected: FAIL lines for the reset tests (`Call to undefined function reset_request()`).

- [ ] **Step 4: Implement `mail.php`**

```php
<?php
declare(strict_types=1);

function mail_send(string $to, string $subject, string $text): bool
{
    if (isset($GLOBALS['ARC_MAILER']) && is_callable($GLOBALS['ARC_MAILER'])) return (bool)($GLOBALS['ARC_MAILER'])($to, $subject, $text);

    $cfg = json_read(arc_cfg('data') . '/smtp.json', []);
    if (!$cfg || empty($cfg['host']) || empty($cfg['from'])) {
        // Not configured yet (for example on a local test machine): keep the message in the protected log instead.
        log_line('error', 'SMTP not configured; mail to ' . $to . ' was not sent');
        log_line('mail', "TO: $to | SUBJECT: $subject | $text");
        return false;
    }
    $dir = __DIR__ . '/vendor/PHPMailer';
    require_once $dir . '/Exception.php';
    require_once $dir . '/PHPMailer.php';
    require_once $dir . '/SMTP.php';
    try {
        $m = new PHPMailer\PHPMailer\PHPMailer(true);
        $m->isSMTP();
        $m->Host = (string)$cfg['host'];
        $m->SMTPAuth = true;
        $m->Username = (string)($cfg['user'] ?? '');
        $m->Password = (string)($cfg['pass'] ?? '');
        $port = (int)($cfg['port'] ?? 465);
        $m->Port = $port;
        $m->SMTPSecure = $port === 587 ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $m->CharSet = 'UTF-8';
        $m->setFrom((string)$cfg['from'], 'The ARC Bistro');
        $m->addAddress($to);
        $m->Subject = $subject;
        $m->Body = $text;
        $m->send();
        return true;
    } catch (Throwable $e) {
        log_line('error', 'mail failed: ' . $e->getMessage());
        return false;
    }
}
```

- [ ] **Step 5: Implement `reset.php`**

```php
<?php
declare(strict_types=1);

const RESET_TTL = 1800;
const RESET_MAX_PER_EMAIL_HOUR = 3;
const RESET_MAX_PER_IP_HOUR = 10;

function reset_file(): string { return arc_cfg('data') . '/resets.json'; }

function reset_request(string $email, string $ip, ?int $now = null): void
{
    $now ??= time();
    $email = trim($email);
    $all = array_values(array_filter(json_read(reset_file(), []), fn($x) => (int)($x['at'] ?? 0) > $now - 86400));
    $ek = hash('sha256', strtolower($email));
    $ik = hash('sha256', $ip);
    $hour = $now - 3600;
    $byEmail = count(array_filter($all, fn($x) => $x['ek'] === $ek && (int)$x['at'] > $hour));
    $byIp = count(array_filter($all, fn($x) => $x['ik'] === $ik && (int)$x['at'] > $hour));
    $user = $email !== '' ? user_by('email', $email) : null;

    // Every request is recorded (known email or not) so rate limits cannot be used to probe accounts.
    $entry = ['ek' => $ek, 'ik' => $ik, 'at' => $now, 'uid' => null, 'hash' => null, 'expires' => 0, 'used' => true];
    $token = null;
    if ($user && $byEmail < RESET_MAX_PER_EMAIL_HOUR && $byIp < RESET_MAX_PER_IP_HOUR) {
        $token = bin2hex(random_bytes(24));
        $entry = ['ek' => $ek, 'ik' => $ik, 'at' => $now, 'uid' => $user['id'], 'hash' => hash('sha256', $token), 'expires' => $now + RESET_TTL, 'used' => false];
    }
    $all[] = $entry;
    json_write_atomic(reset_file(), $all);
    if ($token !== null) reset_mail($user, $token);
}

function reset_mail(array $user, string $token): void
{
    $cfg = json_read(arc_cfg('data') . '/smtp.json', []);
    $base = rtrim((string)($cfg['baseUrl'] ?? ''), '/');
    if ($base === '' && !isset($GLOBALS['ARC_MAILER'])) {
        log_line('error', 'reset mail skipped: baseUrl is not set in smtp.json');
        return;
    }
    $link = $base . '/admin/reset.php?token=' . $token;
    $text = "Hello {$user['username']},\n\n"
        . "Someone asked to reset the password for your The ARC Bistro admin account.\n"
        . "Open this link within 30 minutes to choose a new password:\n\n$link\n\n"
        . "If you did not ask for this, ignore this email. Your password stays the same.\n";
    mail_send($user['email'], 'Reset your The ARC Bistro admin password', $text);
}

function reset_lookup(string $token, ?int $now = null): ?array
{
    $now ??= time();
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) return null;
    $h = hash('sha256', $token);
    foreach (json_read(reset_file(), []) as $x) {
        if (!empty($x['hash']) && hash_equals((string)$x['hash'], $h) && empty($x['used']) && (int)$x['expires'] > $now) return $x;
    }
    return null;
}

/** @return array{0: bool, 1: ?string} [ok, error message] */
function reset_consume(string $token, string $newPassword, ?int $now = null): array
{
    $now ??= time();
    if (strlen($newPassword) < PASSWORD_MIN) return [false, 'Password must be at least ' . PASSWORD_MIN . ' characters'];
    $x = reset_lookup($token, $now);
    $gone = [false, 'This link is not valid any more. Please ask for a new one.'];
    if (!$x) return $gone;
    $users = users_all();
    $found = false;
    foreach ($users as &$u) {
        if ($u['id'] === $x['uid']) { $u['hash'] = password_hash($newPassword, PASSWORD_DEFAULT); $found = true; }
    }
    unset($u);
    if (!$found) return $gone;
    users_save($users);
    $all = json_read(reset_file(), []);
    foreach ($all as &$r) { if (($r['uid'] ?? null) === $x['uid']) $r['used'] = true; }
    unset($r);
    json_write_atomic(reset_file(), $all);
    log_line('activity', 'password reset for ' . $x['uid']);
    return [true, null];
}
```

In `admin/lib/bootstrap.php` set the list to `['config', 'util', 'validate', 'store', 'auth', 'images', 'mail', 'reset', 'handlers']`.

- [ ] **Step 6: Run to verify pass**

Run: `php admin/tests/check.php`
Expected: `N tests, 0 failed`.

- [ ] **Step 7: Create `admin/forgot.php`**

The page answers first and sends the email afterwards, so the response time does not reveal whether an address has an account.

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/page.php';

auth_session_start();
if (csrf_token() === '') $_SESSION['csrf'] = bin2hex(random_bytes(16));
$done = false;
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid((string)($_POST['csrf'] ?? ''))) {
    $done = true;
    $email = (string)($_POST['email'] ?? '');
}
page_head('Forgot password', 'class="auth"');
?>
<main class="auth__box">
  <h1>Reset your password</h1>
  <?php if ($done): ?>
    <p class="notice">If that email belongs to an account, we have sent a link. It works for 30 minutes.</p>
    <p><a href="index.php">Back to log in</a></p>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label class="field"><span class="field__label">Your account email</span><input name="email" type="email" required autofocus></label>
      <button class="btn primary" type="submit">Send reset link</button>
    </form>
    <p><a href="index.php">Back to log in</a></p>
  <?php endif; ?>
</main>
<?php
page_foot();
if ($done) {
    session_write_close();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    else { @ob_flush(); flush(); }
    reset_request($email, $_SERVER['REMOTE_ADDR'] ?? '0');
}
```

- [ ] **Step 8: Create `admin/reset.php`**

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/page.php';

auth_session_start();
if (csrf_token() === '') $_SESSION['csrf'] = bin2hex(random_bytes(16));
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$valid = reset_lookup($token) !== null;
$error = '';
if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $error = 'Please try again.';
    } elseif (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
        $error = 'The two passwords must match.';
    } else {
        [$ok, $msg] = reset_consume($token, (string)$_POST['password']);
        if ($ok) { header('Location: index.php?reset=1'); exit; }
        $error = (string)$msg;
        $valid = reset_lookup($token) !== null;
    }
}
page_head('Choose a new password', 'class="auth"');
?>
<main class="auth__box">
  <h1>Choose a new password</h1>
  <?php if (!$valid): ?>
    <p class="error">This link is not valid any more. <a href="forgot.php">Ask for a new one</a>.</p>
  <?php else: ?>
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label class="field"><span class="field__label">New password (10+ characters)</span><input name="password" type="password" minlength="10" required autocomplete="new-password" autofocus></label>
      <label class="field"><span class="field__label">Repeat password</span><input name="password2" type="password" minlength="10" required autocomplete="new-password"></label>
      <button class="btn primary" type="submit">Save new password</button>
    </form>
  <?php endif; ?>
</main>
<?php page_foot();
```

- [ ] **Step 9: Verify locally**

Run `php -S localhost:8080`. With no `smtp.json`, open `/admin/forgot.php`, enter the owner's email, submit.
Expected: the generic "If that email belongs to an account…" message for both a real and a made-up email. Open `admin/data/log/mail.log`: it holds the reset link for the real email only. Open that link (`http://localhost:8080/admin/reset.php?token=...`; the link has no host because `baseUrl` is not set locally, so type the host yourself), set a new password, and log in with it. Open the same link again: "This link is not valid any more".

- [ ] **Step 10: Checkpoint**

Commit (if git exists): `feat: email password reset`. Do not commit `admin/data/smtp.json`.

---

### Task 10: Accounts, history and system (with tests)

**Files:**
- Modify: `admin/lib/handlers.php`, `admin/lib/images.php`, `admin/api.php`
- Create: `admin/tests/test_accounts.php`, and overwrite the placeholders `admin/assets/tab-history.js`, `admin/assets/tab-accounts.js`, `admin/assets/tab-system.js`

**Interfaces:**
- Consumes: Tasks 3, 4, 5, 9 (`users_visible_to`, `user_create`, `reset_request`, `store_versions`, `store_restore`, `can`).
- Produces (all `handle_*(array $user, array $in): array{0:int,1:array}`; every one checks its own capability and returns 403 otherwise):
  - `handle_accounts_list`, `handle_accounts_create` (`username,email,password,role`), `handle_accounts_delete` (`id`), `handle_accounts_send_reset` (`id`), `handle_account_password` (`current,new`, any logged-in role)
  - `handle_history_list`, `handle_history_restore` (`name`)
  - `handle_system_logs` (`kind: activity|error`), `handle_system_smtp_get`, `handle_system_smtp_save` (`host,port,user,pass,from,baseUrl`), `handle_system_images_unused`, `handle_system_images_delete` (`name`)
  - `images_referenced(): array<string,true>`, `images_unused(): array<array{name:string,size:int}>`
  - HTTP actions: `accounts.list`, `accounts.create`, `accounts.delete`, `accounts.send_reset`, `account.password`, `history.list`, `history.restore`, `system.logs`, `system.smtp_get`, `system.smtp_save`, `system.images_unused`, `system.images_delete`

- [ ] **Step 1: Write the failing tests**

`admin/tests/test_accounts.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

function people(): array
{
    fresh_env();
    user_create('dev', 'd@b.co', 'longenough1', 'developer');
    user_create('boss', 'o@b.co', 'longenough1', 'owner');
    user_create('helper', 's@b.co', 'longenough1', 'staff');
    return ['dev' => user_by('username', 'dev'), 'owner' => user_by('username', 'boss'), 'staff' => user_by('username', 'helper')];
}

t('accounts.list: owner does not see the developer; developer sees everyone; staff refused', function () {
    $p = people();
    [$st, $b] = handle_accounts_list($p['owner'], []);
    eq($st, 200); eq(count($b['users']), 2);
    [, $b] = handle_accounts_list($p['dev'], []);
    eq(count($b['users']), 3);
    [$st] = handle_accounts_list($p['staff'], []);
    eq($st, 403);
});
t('accounts.create: owner may add staff only; developer may add owners', function () {
    $p = people();
    [$st] = handle_accounts_create($p['owner'], ['username' => 'new1', 'email' => 'n1@b.co', 'password' => 'longenough1', 'role' => 'staff']);
    eq($st, 200);
    [$st] = handle_accounts_create($p['owner'], ['username' => 'new2', 'email' => 'n2@b.co', 'password' => 'longenough1', 'role' => 'owner']);
    eq($st, 403);
    [$st] = handle_accounts_create($p['owner'], ['username' => 'new3', 'email' => 'n3@b.co', 'password' => 'longenough1', 'role' => 'developer']);
    eq($st, 403);
    [$st] = handle_accounts_create($p['dev'], ['username' => 'new4', 'email' => 'n4@b.co', 'password' => 'longenough1', 'role' => 'owner']);
    eq($st, 200);
    [$st] = handle_accounts_create($p['staff'], ['username' => 'new5', 'email' => 'n5@b.co', 'password' => 'longenough1', 'role' => 'staff']);
    eq($st, 403);
});
t('accounts.create: duplicate username gives 422 with a readable message', function () {
    $p = people();
    [$st, $b] = handle_accounts_create($p['owner'], ['username' => 'helper', 'email' => 'x@b.co', 'password' => 'longenough1', 'role' => 'staff']);
    eq($st, 422); ok(str_contains($b['error'], 'taken'));
});
t('accounts.delete: owner can remove staff, not the developer, not themselves', function () {
    $p = people();
    [$st] = handle_accounts_delete($p['owner'], ['id' => $p['dev']['id']]);
    eq($st, 404, 'developer is invisible to the owner');
    [$st] = handle_accounts_delete($p['owner'], ['id' => $p['owner']['id']]);
    eq($st, 422);
    [$st] = handle_accounts_delete($p['owner'], ['id' => $p['staff']['id']]);
    eq($st, 200);
    ok(user_by('username', 'helper') === null);
});
t('accounts.delete: staff refused', function () {
    $p = people();
    [$st] = handle_accounts_delete($p['staff'], ['id' => $p['owner']['id']]);
    eq($st, 403);
    ok(user_by('username', 'boss') !== null);
});
t('accounts.send_reset: owner can send to staff; staff cannot', function () {
    $p = people();
    $n = 0;
    $GLOBALS['ARC_MAILER'] = function () use (&$n) { $n++; return true; };
    [$st] = handle_accounts_send_reset($p['owner'], ['id' => $p['staff']['id']]);
    eq($st, 200); eq($n, 1);
    [$st] = handle_accounts_send_reset($p['staff'], ['id' => $p['owner']['id']]);
    eq($st, 403); eq($n, 1);
});
t('account.password: needs the current password and a strong new one', function () {
    $p = people();
    [$st] = handle_account_password($p['staff'], ['current' => 'wrong', 'new' => 'brandnewpass1']);
    eq($st, 422);
    [$st] = handle_account_password($p['staff'], ['current' => 'longenough1', 'new' => 'short']);
    eq($st, 422);
    [$st] = handle_account_password($p['staff'], ['current' => 'longenough1', 'new' => 'brandnewpass1']);
    eq($st, 200);
    ok(auth_login('helper', 'brandnewpass1', '1.1.1.1', 5000)['ok']);
});
t('history: staff refused; owner can list and restore; bad names get 422', function () {
    $p = people();
    store_update(function ($c) { $c['price']['label'] = 'Changed'; return $c; }, 0, 'x');
    [$st] = handle_history_list($p['staff'], []);
    eq($st, 403);
    [$st, $b] = handle_history_list($p['owner'], []);
    eq($st, 200); eq(count($b['versions']), 1);
    [$st] = handle_history_restore($p['staff'], ['name' => $b['versions'][0]['name']]);
    eq($st, 403);
    [$st] = handle_history_restore($p['owner'], ['name' => '../../x']);
    eq($st, 422);
    [$st] = handle_history_restore($p['owner'], ['name' => $b['versions'][0]['name']]);
    eq($st, 200);
    eq(store_read()['price']['label'], 'Inexpensive to moderate');
});
t('system: owner and staff refused; developer reads the last 200 log lines', function () {
    $p = people();
    for ($i = 0; $i < 250; $i++) log_line('activity', "line $i");
    [$st] = handle_system_logs($p['owner'], []);
    eq($st, 403);
    [$st, $b] = handle_system_logs($p['dev'], ['kind' => 'activity']);
    eq($st, 200); eq(count($b['lines']), 200); ok(str_ends_with($b['lines'][199], 'line 249'));
});
t('smtp: blank password keeps the old one; http or path baseUrl rejected', function () {
    $p = people();
    $ok = ['host' => 'smtp.example.com', 'port' => 465, 'user' => 'no-reply@x.com', 'pass' => 'secret', 'from' => 'no-reply@x.com', 'baseUrl' => 'https://x.com'];
    [$st] = handle_system_smtp_save($p['dev'], $ok);
    eq($st, 200);
    [$st] = handle_system_smtp_save($p['dev'], ['pass' => ''] + $ok);
    eq($st, 200);
    eq(json_read(arc_cfg('data') . '/smtp.json')['pass'], 'secret');
    [, $b] = handle_system_smtp_get($p['dev'], []);
    eq($b['passSet'], true); ok(!isset($b['pass']));
    [$st] = handle_system_smtp_save($p['dev'], ['baseUrl' => 'http://x.com'] + $ok);
    eq($st, 422);
    [$st] = handle_system_smtp_save($p['dev'], ['baseUrl' => 'https://x.com/admin'] + $ok);
    eq($st, 422);
    [$st] = handle_system_smtp_save($p['owner'], $ok);
    eq($st, 403);
});
t('images: referenced files are never listed as unused or deletable; path tricks refused', function () {
    $p = people();
    $used = 'aaaaaaaaaaaaaaaa.jpg';
    $spare = 'bbbbbbbbbbbbbbbb.jpg';
    file_put_contents(arc_cfg('uploads') . "/$used", 'x');
    file_put_contents(arc_cfg('uploads') . "/$spare", 'x');
    store_update(function ($c) use ($used) { $c['featured'][0]['image'] = "uploads/$used"; return $c; }, 0, 'x');
    [, $b] = handle_system_images_unused($p['dev'], []);
    eq(array_column($b['images'], 'name'), [$spare]);
    [$st] = handle_system_images_delete($p['dev'], ['name' => $used]);
    eq($st, 422); ok(is_file(arc_cfg('uploads') . "/$used"));
    [$st] = handle_system_images_delete($p['dev'], ['name' => '../content.json']);
    eq($st, 422);
    [$st] = handle_system_images_delete($p['dev'], ['name' => $spare]);
    eq($st, 200); ok(!is_file(arc_cfg('uploads') . "/$spare"));
    [$st] = handle_system_images_delete($p['owner'], ['name' => $spare]);
    eq($st, 403);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php admin/tests/check.php`
Expected: FAIL lines for the new tests (`Call to undefined function handle_accounts_list()`).

- [ ] **Step 3: Add the image helpers to `admin/lib/images.php`**

```php
/** Names (not paths) of uploaded photos that the current content or any saved version still uses. */
function images_referenced(): array
{
    $files = [arc_cfg('content')];
    foreach (glob(arc_cfg('data') . '/backups/content-*.json') ?: [] as $f) $files[] = $f;
    $names = [];
    foreach ($files as $f) {
        if (is_file($f) && preg_match_all('#uploads/([0-9a-f]{16}\.(?:jpg|png|webp))#', (string)file_get_contents($f), $m)) {
            foreach ($m[1] as $n) $names[$n] = true;
        }
    }
    return $names;
}

function images_unused(): array
{
    $used = images_referenced();
    $out = [];
    foreach (glob(arc_cfg('uploads') . '/*') ?: [] as $f) {
        $n = basename($f);
        if (preg_match('/^[0-9a-f]{16}\.(jpg|png|webp)$/', $n) && !isset($used[$n])) $out[] = ['name' => $n, 'size' => (int)filesize($f)];
    }
    return $out;
}
```

- [ ] **Step 4: Add the handlers to `admin/lib/handlers.php`**

```php
function deny(): array { return api_err('You do not have permission to do this.', 403); }

function acct_public(array $u, string $selfId): array
{
    return ['id' => $u['id'], 'username' => $u['username'], 'email' => $u['email'], 'role' => $u['role'], 'self' => $u['id'] === $selfId];
}

function visible_target(array $user, string $id): ?array
{
    $t = user_by('id', $id);
    return ($t && in_array($t['id'], array_column(users_visible_to($user['role']), 'id'), true)) ? $t : null;
}

function handle_accounts_list(array $user, array $in): array
{
    if (!can($user['role'], 'accounts')) return deny();
    return api_ok(['users' => array_map(fn($u) => acct_public($u, $user['id']), users_visible_to($user['role']))]);
}

function handle_accounts_create(array $user, array $in): array
{
    if (!can($user['role'], 'accounts')) return deny();
    $role = (string)($in['role'] ?? 'staff');
    if ($user['role'] === 'owner' && $role !== 'staff') return api_err('Owners can only add staff accounts.', 403);
    try {
        $u = user_create((string)($in['username'] ?? ''), (string)($in['email'] ?? ''), (string)($in['password'] ?? ''), $role);
    } catch (InvalidArgumentException $e) {
        return api_err($e->getMessage(), 422);
    }
    log_line('activity', "{$user['username']} created {$u['role']} account {$u['username']}");
    return api_ok(['id' => $u['id']]);
}

function handle_accounts_delete(array $user, array $in): array
{
    if (!can($user['role'], 'accounts')) return deny();
    $t = visible_target($user, (string)($in['id'] ?? ''));
    if (!$t) return api_err('Account not found.', 404);
    if ($t['id'] === $user['id']) return api_err('You cannot delete your own account.', 422);
    if ($user['role'] === 'owner' && $t['role'] !== 'staff') return api_err('Owners can only remove staff accounts.', 403);
    users_save(array_filter(users_all(), fn($u) => $u['id'] !== $t['id']));
    log_line('activity', "{$user['username']} deleted account {$t['username']}");
    return api_ok();
}

function handle_accounts_send_reset(array $user, array $in): array
{
    if (!can($user['role'], 'accounts')) return deny();
    $t = visible_target($user, (string)($in['id'] ?? ''));
    if (!$t) return api_err('Account not found.', 404);
    reset_request($t['email'], 'admin:' . $user['id']);
    return api_ok(['message' => 'If the email is set up correctly, a reset link is on its way to ' . $t['email'] . '.']);
}

function handle_account_password(array $user, array $in): array
{
    $cur = user_by('id', $user['id']);
    if (!$cur || !password_verify((string)($in['current'] ?? ''), $cur['hash'])) return api_err('Your current password is not correct.', 422);
    $new = (string)($in['new'] ?? '');
    if (strlen($new) < PASSWORD_MIN) return api_err('The new password must be at least ' . PASSWORD_MIN . ' characters.', 422);
    $users = users_all();
    foreach ($users as &$u) { if ($u['id'] === $user['id']) $u['hash'] = password_hash($new, PASSWORD_DEFAULT); }
    unset($u);
    users_save($users);
    log_line('activity', "{$user['username']} changed their password");
    return api_ok();
}

function handle_history_list(array $user, array $in): array
{
    if (!can($user['role'], 'history')) return deny();
    return api_ok(['versions' => store_versions(), 'revision' => (int)(store_read()['revision'] ?? 0)]);
}

function handle_history_restore(array $user, array $in): array
{
    if (!can($user['role'], 'history')) return deny();
    try {
        $rev = store_restore((string)($in['name'] ?? ''), $user['username']);
    } catch (InvalidArgumentException $e) {
        return api_err($e->getMessage(), 422);
    }
    return api_ok(['revision' => $rev]);
}

function handle_system_logs(array $user, array $in): array
{
    if (!can($user['role'], 'system')) return deny();
    $kind = ($in['kind'] ?? 'activity') === 'error' ? 'error' : 'activity';
    $f = arc_cfg('data') . "/log/$kind.log";
    $lines = is_file($f) ? array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -200) : [];
    return api_ok(['lines' => $lines]);
}

function handle_system_smtp_get(array $user, array $in): array
{
    if (!can($user['role'], 'system')) return deny();
    $c = json_read(arc_cfg('data') . '/smtp.json', []);
    return api_ok([
        'host' => (string)($c['host'] ?? ''), 'port' => (int)($c['port'] ?? 465), 'user' => (string)($c['user'] ?? ''),
        'from' => (string)($c['from'] ?? ''), 'baseUrl' => (string)($c['baseUrl'] ?? ''), 'passSet' => !empty($c['pass']),
    ]);
}

function handle_system_smtp_save(array $user, array $in): array
{
    if (!can($user['role'], 'system')) return deny();
    $old = json_read(arc_cfg('data') . '/smtp.json', []);
    $host = trim((string)($in['host'] ?? ''));
    $port = filter_var($in['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $from = trim((string)($in['from'] ?? ''));
    $base = rtrim(trim((string)($in['baseUrl'] ?? '')), '/');
    $errs = [];
    if (!preg_match('/^[A-Za-z0-9.-]{3,100}$/', $host)) $errs[] = 'Mail server name looks wrong.';
    if ($port === false) $errs[] = 'Port must be a number such as 465 or 587.';
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) $errs[] = 'The "from" address must be a valid email.';
    if (!preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?$#', $base)) $errs[] = 'Website address must look like https://yourdomain.com (https, no path).';
    if ($errs) return api_err('Please fix the problems below.', 422, ['errors' => $errs]);
    $pass = (string)($in['pass'] ?? '');
    json_write_atomic(arc_cfg('data') . '/smtp.json', [
        'host' => $host, 'port' => $port, 'user' => trim((string)($in['user'] ?? '')),
        'pass' => $pass !== '' ? $pass : (string)($old['pass'] ?? ''), 'from' => $from, 'baseUrl' => $base,
    ]);
    log_line('activity', "{$user['username']} changed the email settings");
    return api_ok();
}

function handle_system_images_unused(array $user, array $in): array
{
    if (!can($user['role'], 'system')) return deny();
    return api_ok(['images' => images_unused()]);
}

function handle_system_images_delete(array $user, array $in): array
{
    if (!can($user['role'], 'system')) return deny();
    $name = (string)($in['name'] ?? '');
    if (!preg_match('/^[0-9a-f]{16}\.(jpg|png|webp)$/', $name) || !in_array($name, array_column(images_unused(), 'name'), true)) {
        return api_err('That photo is still in use or does not exist.', 422);
    }
    unlink(arc_cfg('uploads') . '/' . $name);
    log_line('activity', "{$user['username']} deleted photo $name");
    return api_ok();
}
```

- [ ] **Step 5: Register the routes in `admin/api.php`**

Replace the `$routes` table with:

```php
    $routes = [
        'content.get'         => ['GET',  'handle_content_get'],
        'content.save'        => ['POST', 'handle_content_save'],
        'upload'              => ['POST', 'handle_upload'],
        'accounts.list'       => ['GET',  'handle_accounts_list'],
        'accounts.create'     => ['POST', 'handle_accounts_create'],
        'accounts.delete'     => ['POST', 'handle_accounts_delete'],
        'accounts.send_reset' => ['POST', 'handle_accounts_send_reset'],
        'account.password'    => ['POST', 'handle_account_password'],
        'history.list'        => ['GET',  'handle_history_list'],
        'history.restore'     => ['POST', 'handle_history_restore'],
        'system.logs'         => ['GET',  'handle_system_logs'],
        'system.smtp_get'     => ['GET',  'handle_system_smtp_get'],
        'system.smtp_save'    => ['POST', 'handle_system_smtp_save'],
        'system.images_unused' => ['GET',  'handle_system_images_unused'],
        'system.images_delete' => ['POST', 'handle_system_images_delete'],
    ];
```

- [ ] **Step 6: Run to verify pass**

Run: `php admin/tests/check.php`
Expected: `N tests, 0 failed`.

- [ ] **Step 7: Write `admin/assets/tab-history.js`**

```js
(function () {
  const { h, btn, card, toast, api } = ARC;
  ARC.tabs.history = {
    async render(root) {
      root.append(card('History', 'Every save is kept. Restoring a version puts that content back on the website and keeps the current one in the list, so you can undo a restore too.', h('p', { class: 'muted' }, 'Loading…')));
      const r = await api('history.list');
      root.replaceChildren();
      if (!r.ok) { root.append(card('History', '', h('p', { class: 'error' }, r.error))); return; }
      const rows = r.versions.map(v => h('tr', {},
        h('td', {}, new Date(v.time).toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' })),
        h('td', {}, Math.round(v.size / 1024) + ' KB'),
        h('td', {}, btn('Restore', async e => {
          if (!confirm('Put this version back on the website?')) return;
          e.target.disabled = true;
          const x = await api('history.restore', { method: 'POST', json: { name: v.name } });
          if (x.ok) { toast('Restored. The website now shows that version.'); ARC.reload(); } else { toast(x.error, 'error'); e.target.disabled = false; }
        }))));
      root.append(card('History', 'Every save is kept (the last 30). Restoring puts that content back on the website and keeps the current one in the list, so you can undo a restore too.',
        r.versions.length
          ? h('table', { class: 'table' }, h('thead', {}, h('tr', {}, h('th', {}, 'Saved'), h('th', {}, 'Size'), h('th', {}, ''))), h('tbody', {}, rows))
          : h('p', { class: 'muted' }, 'Nothing saved yet.')));
    }
  };
})();
```

- [ ] **Step 8: Write `admin/assets/tab-accounts.js`**

```js
(function () {
  const { h, btn, card, field, textInput, selectInput, errorBox, toast, api } = ARC;

  function passwordCard() {
    const d = { current: '', new: '' };
    const msg = h('p', { class: 'field__hint' });
    return card('Change my password', 'Use at least 10 characters.',
      field('Current password', textInput(d, 'current', { type: 'password' })),
      field('New password', textInput(d, 'new', { type: 'password' })),
      msg,
      h('p', {}, btn('Change password', async e => {
        e.target.disabled = true;
        const r = await api('account.password', { method: 'POST', json: d });
        msg.textContent = r.ok ? 'Password changed.' : r.error;
        if (r.ok) { toast('Password changed.'); ARC.reload(); } else e.target.disabled = false;
      }, { class: 'btn primary' })));
  }

  ARC.tabs.accounts = {
    async render(root) {
      const role = document.body.dataset.role;
      const r = await api('accounts.list');
      if (!r.ok) { root.append(card('Accounts', '', h('p', { class: 'error' }, r.error))); return; }
      const draft = { username: '', email: '', password: '', role: 'staff' };
      const roles = role === 'developer' ? [['staff', 'Staff (menu and promos only)'], ['owner', 'Owner'], ['developer', 'Developer']] : [['staff', 'Staff (menu and promos only)']];
      const errs = errorBox();
      const rows = r.users.map(u => h('tr', {},
        h('td', {}, u.username, u.self ? ' (you)' : ''), h('td', {}, u.email), h('td', {}, u.role),
        h('td', {}, u.self ? null : h('div', { class: 'item__actions' },
          btn('Send reset link', async e => { e.target.disabled = true; const x = await api('accounts.send_reset', { method: 'POST', json: { id: u.id } }); toast(x.ok ? x.message : x.error, x.ok ? 'ok' : 'error'); e.target.disabled = false; }),
          btn('Delete', async () => {
            if (!confirm('Delete the account "' + u.username + '"?')) return;
            const x = await api('accounts.delete', { method: 'POST', json: { id: u.id } });
            if (x.ok) { toast('Account deleted.'); ARC.reload(); } else toast(x.error, 'error');
          }, { class: 'btn danger' })))));
      root.append(
        card('Accounts', 'People who can log in to this admin.', h('table', { class: 'table' }, h('thead', {}, h('tr', {}, h('th', {}, 'Username'), h('th', {}, 'Email'), h('th', {}, 'Role'), h('th', {}, ''))), h('tbody', {}, rows))),
        card('Add an account', 'Give the new person their password in person. They can change it, or use "Forgot your password?" on the login page.',
          field('Username', textInput(draft, 'username', { max: 30 })),
          field('Email', textInput(draft, 'email', { type: 'email', max: 120 })),
          field('Temporary password (10+ characters)', textInput(draft, 'password', { type: 'password' })),
          field('Role', selectInput(draft, 'role', roles)),
          errs.el,
          h('p', {}, btn('Add account', async e => {
            e.target.disabled = true;
            const x = await api('accounts.create', { method: 'POST', json: draft });
            if (x.ok) { toast('Account added.'); ARC.reload(); } else { errs.show([x.error]); e.target.disabled = false; }
          }, { class: 'btn primary' }))),
        passwordCard());
    }
  };
})();
```

- [ ] **Step 9: Write `admin/assets/tab-system.js`**

```js
(function () {
  const { h, btn, card, field, textInput, errorBox, toast, api } = ARC;

  async function smtpCard() {
    const r = await api('system.smtp_get');
    const d = { host: r.host || '', port: String(r.port || 465), user: r.user || '', pass: '', from: r.from || '', baseUrl: r.baseUrl || '' };
    const errs = errorBox();
    return card('Email settings', 'Used to send password reset emails. Use a mailbox on your Hostinger domain (for example no-reply@yourdomain.com).',
      field('Mail server (SMTP host)', textInput(d, 'host', { max: 100, placeholder: 'smtp.hostinger.com' })),
      field('Port', textInput(d, 'port', { max: 5, placeholder: '465' })),
      field('Mailbox username', textInput(d, 'user', { max: 120 })),
      field(r.passSet ? 'Mailbox password (leave blank to keep the saved one)' : 'Mailbox password', textInput(d, 'pass', { type: 'password' })),
      field('Send from', textInput(d, 'from', { max: 120 })),
      field('Website address (used in reset links)', textInput(d, 'baseUrl', { max: 100, placeholder: 'https://yourdomain.com' })),
      errs.el,
      h('p', {}, btn('Save email settings', async e => {
        e.target.disabled = true;
        const x = await api('system.smtp_save', { method: 'POST', json: d });
        errs.show(x.errors || (x.ok ? [] : [x.error]));
        if (x.ok) { toast('Email settings saved.'); ARC.dirty = false; }
        e.target.disabled = false;
      }, { class: 'btn primary' })));
  }

  async function logsCard() {
    const pre = h('pre', { class: 'log' }, 'Loading…');
    let kind = 'activity';
    async function load() {
      const r = await api('system.logs&kind=' + kind);
      pre.textContent = r.ok ? (r.lines.join('\n') || '(empty)') : r.error;
    }
    const tabs = h('p', {}, btn('Activity', () => { kind = 'activity'; load(); }), ' ', btn('Errors', () => { kind = 'error'; load(); }));
    load();
    return card('Logs', 'The last 200 lines. Passwords and reset links are never logged here.', tabs, pre);
  }

  async function imagesCard() {
    const box = h('div', {});
    async function load() {
      const r = await api('system.images_unused');
      if (!r.ok) { box.replaceChildren(h('p', { class: 'error' }, r.error)); return; }
      box.replaceChildren(r.images.length
        ? h('table', { class: 'table' }, h('tbody', {}, r.images.map(i => h('tr', {},
            h('td', {}, h('img', { src: '../uploads/' + i.name, alt: '', width: 60 })),
            h('td', {}, Math.round(i.size / 1024) + ' KB'),
            h('td', {}, btn('Delete', async () => {
              if (!confirm('Delete this photo for good?')) return;
              const x = await api('system.images_delete', { method: 'POST', json: { name: i.name } });
              toast(x.ok ? 'Photo deleted.' : x.error, x.ok ? 'ok' : 'error'); load();
            }, { class: 'btn danger' }))))))
        : h('p', { class: 'muted' }, 'No unused photos.'));
    }
    load();
    return card('Unused photos', 'Photos that no saved version uses any more. Deleting is permanent.', box);
  }

  ARC.tabs.system = {
    async render(root) {
      root.append(await smtpCard(), await logsCard(), await imagesCard());
    }
  };
})();
```

Note: `api('system.logs&kind=' + kind)` works because `api()` builds `api.php?action=system.logs&kind=activity`; the handler reads `$in` from `$_GET` for GET routes.

- [ ] **Step 10: Manual verification**

Run `php -S localhost:8080`; log in as the developer.
Expected:
1. **Accounts:** add a Staff account; the table lists it. Log in as Staff in a private window: only Menu and Promos show. In that window open `http://localhost:8080/admin/api.php?action=accounts.list`: the answer is `{"ok":false,"error":"You do not have permission to do this."}`.
2. As Owner (private window): the developer account is not in the Accounts table; you can add Staff but the Role list has only Staff.
3. **History:** make a change in Promos, open History, press Restore on the oldest version: the website returns to that version.
4. **System (developer only):** save email settings; blank password keeps the saved one; the logs show recent activity and your failed login attempts from Task 7 without any passwords.
5. Upload a photo, change the dish photo to another one and save: the first photo is still used by a saved version and is not listed under Unused photos.

- [ ] **Step 11: Checkpoint**

Commit (if git exists): `feat: accounts, history and system tabs`.

---

### Task 11: Hardening, deployment and final checks

**Files:**
- Create: `.htaccess` (site root), `admin/.htaccess`
- Modify: `docs/superpowers/specs/2026-10-06-admin-backend-design.md` (record the decisions below)

**Interfaces:** none (configuration and procedure).

- [ ] **Step 1: Create the site-root `.htaccess`**

```
Options -Indexes

RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Keep tooling, tests and project docs private
RewriteRule ^(docs|tools|tests|node_modules)(/|$) - [F,L]
<FilesMatch "\.(md|py|log|lock|key)$">
  Require all denied
</FilesMatch>

<IfModule mod_headers.c>
  Header always set X-Content-Type-Options "nosniff"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
  Header always set X-Frame-Options "SAMEORIGIN"
  <FilesMatch "^content\.json$">
    Header set Cache-Control "no-cache"
  </FilesMatch>
</IfModule>
```

- [ ] **Step 2: Create `admin/.htaccess`**

```
Options -Indexes
RewriteEngine On
RewriteRule ^(lib|tests)(/|$) - [F,L]

<IfModule mod_headers.c>
  Header always set Cache-Control "no-store"
  Header always set Content-Security-Policy "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'"
</IfModule>
```

If the admin looks unstyled or buttons do nothing after deploying, the CSP is the first thing to check in the browser console. The admin uses no inline scripts or styles, so it should not trigger.

- [ ] **Step 3: Deploy to Hostinger (developer)**

1. In hPanel, confirm PHP 8.1 or newer, and in "PHP Configuration" that `gd`, `mbstring`, `fileinfo`, `openssl`, `exif` are enabled. Set `display_errors` to Off.
2. Upload the project to `public_html` (skip `docs/`, `tests/`, `tools/`, `admin/tests/`, `node_modules/`, any `admin/data/*.json`, any local `uploads/` test images).
3. Make sure `admin/data/` and `uploads/` are writable by PHP (permissions 755 or 775, owned by the account).
4. Create `admin/data/setup.key` on the server with a secret word, open `https://yourdomain/admin/`, create the developer and owner accounts. The key file deletes itself.
5. Log in as developer, open System, fill in Email settings (Hostinger mailbox) and the website address, press Save.
6. Click "Forgot your password?" on the login page with the owner's email: the email arrives and the link works.

- [ ] **Step 4: Production checks (each must pass)**

Run these from any machine, replacing the domain:

```
curl -I https://yourdomain/admin/data/users.json        -> 403
curl -I https://yourdomain/admin/data/backups/          -> 403
curl -I https://yourdomain/admin/lib/auth.php           -> 403
curl -I https://yourdomain/admin/tests/check.php        -> 403 or 404
curl -I https://yourdomain/docs/                        -> 403 or 404
curl -I http://yourdomain/                               -> 301 to https
curl -I https://yourdomain/content.json                 -> 200 and Cache-Control: no-cache
curl -I https://yourdomain/admin/setup.php               -> 302 to index.php (setup is locked)
```

Also upload a photo through the admin, copy its address (`https://yourdomain/uploads/<name>.jpg`), and confirm it loads. Then create a file `uploads/test.php` containing `<?php echo 1;` by FTP and open it in the browser: it must be refused (403); delete it afterwards.

- [ ] **Step 5: Final manual checklist (from the spec)**

- Login lockout works (5 wrong passwords), and the unknown-username message equals the wrong-password message.
- Reset email arrives; link works once; link older than 30 minutes is refused.
- Staff cannot reach Hours & Contact through the UI or by calling `api.php?action=content.save` for `hours`.
- Uploading a text file renamed `.jpg` is refused.
- With default content, the public page looks exactly like before; with the promo list empty and no menu categories, no extra section appears.
- Blocking `content.json` (rename it for a minute) leaves the public page readable.
- Admin is usable at phone width (sidebar becomes a row, buttons are tappable, photos upload from the phone camera).
- Two people saving at once: the second sees "Someone else changed this".

- [ ] **Step 6: Record the final decisions in the spec**

Edit `docs/superpowers/specs/2026-10-06-admin-backend-design.md`:
- Section 8, replace "and deletes replaced or removed files" with "and keeps replaced or removed photos until a Developer deletes them in System > Unused photos, because saved versions may still use them".
- Section 4, add after `"version": 1,` the line `"revision": 0,` and add one sentence under the JSON: "`revision` is an integer that increases by one on every save; the admin sends the revision it loaded and a mismatch is refused (409)."
- Section 7, add: "The first-time setup page requires a `setup.key` file placed on the server by the developer; it is deleted when setup completes."
- Section 12, add: "5. Copy elsewhere on the page that names days or places (for example 'Tuesday to Sunday' in the closing call-to-action band and 'Worth the trip to Osmeña Street') is static text and is not editable from the admin."

- [ ] **Step 7: Checkpoint**

Commit (if git exists): `chore: hardening and deployment notes`.

---

## Self-Review Notes

Spec coverage: goal and editable fields (Tasks 2, 3, 8); roles and permissions (4, 5, 10); login lockout, sessions, CSRF (4, 7); first setup (7); email reset (9); developer access and System tab (10); images (6, 8); public rendering of contact, hours, price, social, featured dishes, full menu, promos (1, 2); versions, restore, atomic writes (3, 10); admin screens, counters, unsaved warning, phone layout (7, 8); security headers and HTTPS (11); tests (1, 3 to 6, 9, 10, 11 manual list). Deviations are recorded in Task 6 (no automatic photo deletion) and Task 11 Step 6.

Known limitations to tell the owner: static copy that mentions days or street name does not change when hours change; the page needs JavaScript to show edited content (default text shows without it).
