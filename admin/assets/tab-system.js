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
