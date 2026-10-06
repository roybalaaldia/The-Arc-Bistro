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
