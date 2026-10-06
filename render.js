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
      let ok = true;
      const href = n.getAttribute('data-href').replace(/\{([\w.]+)\}/g, (_, p) => { const x = get(v, p); if (x == null || x === '') ok = false; return x; });
      if (ok) n.setAttribute('href', href);
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
