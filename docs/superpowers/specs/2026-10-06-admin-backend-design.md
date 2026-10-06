# The ARC Bistro: Admin Backend Design

Status: draft for owner review. Date: 2026-10-06.

## 1. Goal

Let non-technical staff change the website's content from a friendly, logged-in admin, with no HTML editing, and without changing the public page's layout.

Editable: featured dishes, full priced menu, promos, opening hours, price range, phone number, email, social media links.

Not editable: HTML structure, CSS, layout, section order, brand assets (logo).

## 2. Decisions already made

| Topic | Decision |
|---|---|
| Hosting | Hostinger (PHP + HTTPS available) |
| Approach | **B**: static `index.html` fetches `content.json` with JavaScript |
| Menu scope | Featured dishes (existing 4, now editable) **and** a new full menu with categories and prices |
| Promos | Promo section that appears only when at least one promo is active |
| Accounts | Three roles: **Owner**, **Staff** (menu and promos only), **Developer** |
| Password reset | Email-based, single-use link |
| Publish model | Save publishes immediately; every save is a restorable version |

## 3. Architecture

```
public_html/
  index.html        layout unchanged; current text kept as default content
  style.css
  main.js           + renders content.json into the page
  content.json      single source of truth (public, read-only to the web; written only by PHP)
  uploads/          processed images (PHP execution disabled via .htaccess)
  assets/           logos, existing photos
  admin/
    index.php       login + forgot/reset password
    app.php         dashboard shell (sidebar + tabs)
    api/*.php       save, upload, restore, accounts, system endpoints
    lib/            auth, csrf, validation, content store, image processing, mail
    assets/         admin CSS and JS
    data/           PROTECTED (.htaccess deny + outside web root where possible)
      users.json    accounts (hashed passwords, roles, emails)
      resets.json   hashed reset tokens with expiry
      backups/      last N versions of content.json
      log/          activity log, error log
```

Data flow: admin form -> `api/save.php` (checks session, role, CSRF token, validation) -> atomic write of `content.json` (write temp file, rename) -> previous copy goes to `data/backups/` -> public page fetches `content.json` on next load.

`main.js` fetch behavior: on failure or invalid JSON, do nothing (page shows built-in default text). Requests use `cache: "no-cache"` so edits show without a hard refresh.

## 4. Content model (`content.json`)

```json
{
  "version": 1,
  "updatedAt": "2026-10-06T10:00:00+08:00",
  "contact": {
    "phone": "+63 995 109 1503",
    "email": "thearcbistro@gmail.com",
    "address": "208 Osmeña Street, City Subdivision\nSan Pablo City, Laguna, Philippines",
    "addressShort": "208 Osmeña Street, San Pablo City"
  },
  "social": [ { "label": "Facebook", "url": "https://www.facebook.com/..." } ],
  "hours": [
    { "day": "mon", "status": "call" },
    { "day": "tue", "status": "open", "from": "10:00", "to": "21:00" }
  ],
  "price": { "level": 2, "label": "Inexpensive to moderate" },
  "featured": [
    { "id": "d1", "name": "Fettuccine Alfredo", "tag": "Italian · Pasta",
      "description": "…", "image": "uploads/…jpg", "alt": "…", "hidden": false }
  ],
  "menu": [
    { "id": "c1", "name": "Pasta",
      "items": [ { "id": "i1", "name": "…", "description": "", "price": 285, "status": "available" } ] }
  ],
  "promos": [
    { "id": "p1", "title": "…", "details": "…", "image": "", "alt": "",
      "start": "2026-10-10", "end": "2026-10-17", "active": true }
  ]
}
```

Limits (keep layout safe): dish name 40, tag 30, dish description 140, category name 30, menu item name 50, item description 100, promo title 50, promo details 200. Max 8 featured dishes, 8 categories, 20 items per category, 6 promos. Item `status`: `available` | `hidden` | `soldout`.

## 5. Public page rendering rules

- **Phone / email / address / social:** every occurrence (header bar, hours card, drawer, reserve block, footer, `tel:` and `mailto:` links) is bound to the content value via `data-bind` attributes. Empty social links are not rendered.
- **Hours:** consecutive days with identical hours are grouped ("Tuesday – Sunday  10:00 AM – 9:00 PM"); closed or "call ahead" days shown as such. Used in the hours card, reserve block and footer.
- **Price range:** `₱` repeated `level` times plus the label, in the About facts and "Good to know" card.
- **Featured dishes:** rows render in the existing alternating text/photo pattern, with the existing arch, pill and leaf frames cycling in order.
- **Full menu:** new price-list block under the featured dishes in the Menu section. Hidden until at least one category with a visible item exists. Sold-out items show a muted "Sold out" note; hidden items do not render. Exact visual treatment is finalised during build, using existing type, colours and rules.
- **Promos:** new section between the Menu section and the olive feature band. Shows promos where `active` is true and today is within `start`..`end` (inclusive). If none qualify, the section is removed from the DOM and the page matches today's layout exactly.
- All rendered text is inserted as text (never as HTML) to prevent injection.

## 6. Admin screens

Sidebar: **Menu**, **Promos**, **Hours & Contact**, **Accounts** (Owner, Developer), **System** (Developer). Top bar: signed-in name, View site, Log out. Responsive down to phone width.

- **Menu:** featured dish cards (photo, name, label, description, hide toggle, up/down arrows, delete, Add dish). Full menu: categories containing items (name, description, price, status), with add, delete and reorder.
- **Promos:** form per promo (title, details, optional image, start and end dates, Active switch).
- **Hours & Contact:** 7-day table (Open / Closed / Call ahead + time pickers), price level dropdown and label, phone, email, address, social links list.
- **Accounts:** list, add, remove, reset-link, change role (Owner manages Staff accounts and their own password; Owner cannot see or edit Developer accounts; only a Developer can add or remove another Owner).
- **System (Developer):** activity log, error log, SMTP settings, upload limits, restore any version.
- **History:** Owner and Developer can list saved versions and restore one. Restoring itself creates a new version.
- **UX safeguards:** live character counters, inline validation, unsaved-changes warning, clear success and error messages.

## 7. Authentication and permissions

- Password hashing with `password_hash` (bcrypt/argon2). Minimum length 10.
- PHP sessions: `HttpOnly`, `Secure`, `SameSite=Lax`, session id regenerated on login, 30-minute idle timeout.
- CSRF token required on every state-changing request.
- Login lockout: 5 failed attempts locks that username and IP for 10 minutes.
- Roles enforced server-side per endpoint: Staff may call only menu and promo endpoints; Owner adds hours, contact, accounts, history; Developer adds system endpoints and is hidden from Owner views.
- First setup: one-time `setup` page creates the first Developer and Owner accounts, then disables itself (writes a lock file).
- Forgot password: asks for email, always replies with a generic message, sends a single-use link valid 30 minutes (only a hash stored), rate-limited per email and IP.
- Mail via Hostinger SMTP mailbox, configured in System.
- HTTPS enforced; admin pages sent with `no-store` cache headers.
- Activity log records user, action, time (never passwords or tokens).

## 8. Images

Accepted: JPG, PNG, WebP, up to 5 MB. Server verifies real type (not just extension), re-encodes with GD (strips metadata), resizes to a maximum of 1600 px on the long edge, writes random filenames to `uploads/`, and deletes replaced or removed files. Alt text field per image. Admin shows a hint that portrait photos work best (frames crop to their shape).

## 9. Error handling

- Invalid input: field-level messages; nothing is saved.
- Write failure: previous `content.json` stays untouched (atomic rename); user sees an error and the error is logged.
- Corrupt or missing `content.json`: public page falls back to built-in text; admin offers restore of the latest backup.
- Image failure: clear message; no partial record saved.

## 10. Testing

- A small PHP self-check script (`admin/tests/check.php`) covering: role enforcement per endpoint, validation limits, hours grouping, promo date window, atomic save and backup creation.
- Manual checklist before launch: login lockout, reset email round trip, staff blocked from Hours & Contact, image upload rejects a renamed non-image, public page matches today's layout with default content and with an empty promo list, phone-width admin usability.
- Page-side check: with `content.json` blocked or invalid, `index.html` renders unchanged.

## 11. Out of scope (YAGNI for now)

Draft/preview before publish, scheduled publishing beyond promo dates, multi-language content, drag-and-drop reorder (arrows instead), two-factor login, online ordering or booking.

## 12. Open items

1. Final visual treatment of the full menu block (decided at build time with the owner).
2. Currency formatting is ₱ with thousands separators; confirm no other currency is needed.
3. Hostinger plan must allow PHP `mail()` or SMTP and GD image support (expected on all plans; developer to confirm).
4. Developer to confirm where `data/` can live outside the web root, otherwise rely on `.htaccess` deny.
