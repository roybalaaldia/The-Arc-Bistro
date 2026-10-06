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
        h('td', {}, u.self || (role === 'owner' && u.role !== 'staff') ? null : h('div', { class: 'item__actions' },
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
