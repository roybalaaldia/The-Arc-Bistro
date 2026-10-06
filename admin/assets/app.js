(function () {
  const ARC = (window.ARC = { tabs: {}, state: { content: null, revision: 0 }, current: '' });
  // Unsaved changes are tracked per card. `ARC.dirty = true` marks the card the user last interacted with
  // (every edit, add, delete, move and toggle happens inside a user event); `ARC.dirty = false` clears all.
  const dirtyCards = new Set();
  let activeCard = null;
  ['input', 'change', 'click'].forEach(t => document.addEventListener(t, e => { activeCard = (e.target.closest && e.target.closest('.card')) || activeCard; }, true));
  Object.defineProperty(ARC, 'dirty', {
    get: () => [...dirtyCards].some(c => c.isConnected),
    set: v => { if (!v) dirtyCards.clear(); else if (activeCard) dirtyCards.add(activeCard); }
  });
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
    const lab = h('label', { class: 'btn' }, '', input);
    const rm = btn('Remove photo', () => { obj[key] = ''; ARC.dirty = true; draw(); });
    const draw = () => {
      prev.replaceChildren(obj[key] ? h('img', { src: '../' + obj[key], alt: '' }) : h('span', { class: 'photo__empty' }, 'No photo yet'));
      lab.firstChild.textContent = obj[key] ? 'Change photo' : 'Choose photo';
      rm.disabled = !obj[key];
    };
    input.addEventListener('change', async () => {
      const f = input.files[0];
      if (!f) return;
      if (f.size > 5 * 1024 * 1024) { status.textContent = 'That photo is larger than 5 MB.'; input.value = ''; return; }
      status.textContent = 'Uploading…';
      const owner = input.closest('.card');
      const fd = new FormData();
      fd.append('photo', f);
      let r;
      try { r = await api('upload', { method: 'POST', form: fd }); } catch (e) { r = { ok: false, error: 'Upload failed. Check your connection and try again.' }; }
      if (r.ok) { obj[key] = r.path; if (owner) dirtyCards.add(owner); status.textContent = ''; draw(); } else status.textContent = r.error;
      input.value = '';
    });
    draw();
    return h('div', { class: 'photo' }, prev, h('div', { class: 'photo__side' },
      lab, rm,
      status,
      altKey ? field('Describe the photo (for screen readers)', textInput(obj, altKey, { max: 80 })) : null,
      h('span', { class: 'field__hint' }, 'JPG, PNG or WebP, up to 5 MB. Portrait photos work best.')
    ));
  }

  async function saveSection(section, data) {
    const owner = activeCard;
    let r;
    try { r = await api('content.save', { method: 'POST', json: { section, data, baseRevision: ARC.state.revision } }); }
    catch (e) { r = { ok: false, status: 0, error: 'Could not reach the server. Check your connection and try again.' }; }
    if (r.ok) {
      ARC.state.revision = r.revision;
      ARC.state.content[section] = r.data;
      dirtyCards.delete(owner);
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
    const done = ARC.tabs[key].render(main);
    window.scrollTo(0, 0);
    return done;
  }

  async function reload() {
    ARC.dirty = false;
    if (await loadContent()) { const back = ARC.afterRecovery; ARC.afterRecovery = null; show(back || ARC.current); }
  }

  async function start() {
    const tabs = JSON.parse(body.dataset.tabs);
    const nav = document.getElementById('nav');
    tabs.forEach(([key, label]) => nav.append(h('button', { type: 'button', 'data-key': key, onclick: () => show(key) }, label)));
    window.addEventListener('beforeunload', e => { if (ARC.dirty) { e.preventDefault(); e.returnValue = ''; } });
    if (await loadContent()) { show(tabs[0][0]); return; }
    // Content could not be read: keep History reachable so a version can be restored (spec section 9).
    const hasHistory = tabs.some(t => t[0] === 'history');
    ARC.current = hasHistory ? 'history' : tabs[0][0];
    ARC.afterRecovery = tabs[0][0]; // once a restore makes the content readable, land on the first normal tab
    const warn = card('The website content could not be read', hasHistory
      ? 'Restore a previous version below to fix it.'
      : 'Please ask the owner or the developer to restore a previous version.');
    if (hasHistory) { await show('history'); document.getElementById('main').prepend(warn); }
    else document.getElementById('main').append(warn);
  }

  Object.assign(ARC, { h, api, toast, btn, card, field, textInput, selectInput, toggleField, errorBox, move, photoField, saveSection, loadContent, reload, start });
  document.addEventListener('DOMContentLoaded', () => { if (document.getElementById('nav')) start(); });
})();
