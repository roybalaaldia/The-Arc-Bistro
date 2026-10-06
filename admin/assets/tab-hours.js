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
