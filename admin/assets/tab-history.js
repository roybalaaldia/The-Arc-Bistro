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
