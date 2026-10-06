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
