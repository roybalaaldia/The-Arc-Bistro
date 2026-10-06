# Deployment and QA checklist (Hostinger)

## Before you deploy

- PHP 8.1 or newer with `gd`, `mbstring`, `fileinfo`, `openssl` and `exif` enabled, and `display_errors` off.
- Do NOT upload: `.git`, `.tools`, `.superpowers`, `docs/`, `tests/`, `tools/`, `admin/tests/`, `node_modules/`, any `admin/data/*` except `.htaccess`, any `uploads/*` except `.htaccess`.
- Tests run locally only: `php admin/tests/check.php` and `node --test`.
- Known limitations:
  - Existing sessions are not invalidated when a password is changed or reset.
  - The System tab reads only the activity and error logs.
  - Static copy that mentions days or the street name is not editable from the admin.
  - Real SMTP delivery and the `uploads/.htaccess` directives (`Options`, `RemoveHandler`) were never exercised against Hostinger/LiteSpeed. Verify them in step 4. If `uploads/.htaccess` causes a 500, remove the `Options` and `RemoveHandler` lines and keep the `FilesMatch ... Require all denied` block.
- If the admin looks unstyled or buttons do nothing, check the browser console for a Content-Security-Policy violation first. The admin uses no inline scripts or styles, so it should not trigger.

## Step 1: Deploy

1. In hPanel, confirm PHP 8.1 or newer, and in "PHP Configuration" that `gd`, `mbstring`, `fileinfo`, `openssl`, `exif` are enabled. Set `display_errors` to Off.
2. Upload the project to `public_html` (skip the items listed above).
3. Make sure `admin/data/` and `uploads/` are writable by PHP (permissions 755 or 775, owned by the account).
4. Create `admin/data/setup.key` on the server with a secret word, open `https://yourdomain/admin/`, create the developer and owner accounts. The key file deletes itself.
5. Log in as developer, open System, fill in Email settings (Hostinger mailbox) and the website address, press Save.
6. Click "Forgot your password?" on the login page with the owner's email: the email arrives and the link works.

## Step 2: Production checks (each must pass)

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

## Step 3: Final manual checklist

- Login lockout works (5 wrong passwords), and the unknown-username message equals the wrong-password message.
- Reset email arrives; link works once; link older than 30 minutes is refused.
- Staff cannot reach Hours & Contact through the UI or by calling `api.php?action=content.save` for `hours`.
- Uploading a text file renamed `.jpg` is refused.
- With default content, the public page looks exactly like before; with the promo list empty and no menu categories, no extra section appears.
- Blocking `content.json` (rename it for a minute) leaves the public page readable.
- Admin is usable at phone width (sidebar becomes a row, buttons are tappable, photos upload from the phone camera).
- Two people saving at once: the second sees "Someone else changed this".
