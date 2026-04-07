# Changelog

All notable changes to centralcomms.nz v2.

---

## [Unreleased]

---

## 2026-04-07 (security review)

### Security hardening — notifications, contact form, updates API

- `notifications.astro`: replaced all inline `onclick="fn(id)"` attributes with `data-action`/`data-id`/`data-id2` data attributes and a single event delegation listener on `#notifications-list`; removed `window.*` global function assignments
- `contact.php`: strip `\r\n` from the validated email address before inserting it into the `Reply-To` header (email header injection hardening)
- `updates-api.php` (`upload_image`): replaced sequential `file_exists` loop with a `bin2hex(random_bytes(3))` random suffix, eliminating the TOCTOU race condition between the existence check and `move_uploaded_file`
- `updates-api.php` (`delete_image`): added `realpath()` guard — resolves both the target path and `$imagesDir` and confirms the file is inside the images directory before unlinking (path-traversal defence-in-depth)
- `updates/[slug].astro`: removed dead `<script define:vars>` block (variables injected by Astro are inaccessible from ES module scripts); simplified `SLUG` derivation to always use `window.location.pathname`

---

## 2026-04-07

### c87571e — Various fixes and improvements
- Contact form email address changed from `info@centralcomms.nz` to `support@smtp.centralcomms.nz`
- Notifications status banner: removed `reveal` animation class so the loading character displays immediately on page load (previously the banner was invisible until the user scrolled)
- Support page `.content-h2` headings darkened to `#27448f` for readability against the light background
- Nav: added "Call Now" `tel:0800848038` button before "Get in Touch" in both desktop nav and mobile menu; phone number extracted to a frontmatter constant to avoid duplication
- Contact forms (home page + support page): added client-side validation — name must be ≥ 2 characters, email must match a valid format; inline field-level error messages shown; PHP `contact.php` also enforces name length server-side

### fd752a0 — Add updates CMS with TipTap editor and PHP API
- Migrated all 26 project updates from Astro content collection markdown files to `public/assets/data/updates.json`
- Removed Astro content collection; `[slug].astro` and `index.astro` now read directly from the JSON at build time
- Created `public/assets/php/updates-api.php` — full CRUD API with separate bcrypt auth, image upload/delete, slug generation
- Created `public/assets/php/updates-auth-config.php.example`
- `updates/index.astro`: staff login modal, "New Update" full-screen overlay with TipTap editor, featured image upload, content image insert, YouTube embed button, category tags
- `updates/[slug].astro`: fixed staff bar at bottom of page (edit/delete/logout), full edit overlay pre-loaded from API, live DOM update after save
- Makefile: added `sync-updates` target — pulls `updates.json` and post images from server before build; `deploy` now runs `sync-data sync-updates build push`
- Post-push: server sets `updates.json` and `notifications.json` to 666, `posts/` directory to 777

### 3e1e1ab — Infinite scroll for resolved (older) notifications
- Active/warning/outage notifications always fully visible
- Resolved (historical) notifications load 5 at a time as user scrolls to the bottom
- IntersectionObserver with 200px `rootMargin` for look-ahead preloading
- Spinner sentinel element shows/hides based on remaining resolved items

### ec11414 — Import historical notifications from pages 2-4 of old site
- Fetched notifications.php pages 2–4 from old centralcomms.nz site
- Imported 8 historical notifications (2022–2023) into `notifications.json`
- Total notifications: 11

### 792e2cb — Show all notifications without pagination
- Removed initial 5-item pagination limit; all notifications rendered on load

### f96626e — Replace pagination with infinite scroll on notifications page
- Initial infinite scroll implementation (later refined in 3e1e1ab)

### 146c14b — Fix notification CSS not applying to JS-rendered elements
- Astro scopes `<style>` blocks with `data-astro-cid-*` attribute selectors
- JavaScript-created elements never receive these attributes, so styles were invisible
- Fixed by changing `<style>` to `<style is:global>` in `notifications.astro`

### 4d08076 — Retheme notification CSS for light-mode section background
- Notifications section background changed to `#f4f8fd` (light blue-white)
- All notification card colours updated for light-mode contrast

### ee5cee4 — Improve notifications UI: styling, sections, resolve button
- Notification cards redesigned with status colour coding
- Staff can mark notifications as resolved inline
- Comment system added to notifications

### 4498bf2 — Remove PHP dev proxy — developing on dev web server going forward
- Removed the local PHP dev proxy script
- Development now done directly against the dev server at `centralcomms.netent.co.nz`

### f0e8745 — Handle missing remote notifications.json on first deploy
- `sync-data` in Makefile now gracefully skips if no remote file exists yet

### 9730caa — Set notifications.json to 777 after deploy
- `push` target in Makefile runs `chmod 777` on `notifications.json` post-rsync so PHP can write to it

### 50ad4fa — Add PHP dev server proxy for local development
- Temporary proxy to forward API calls to PHP during local dev (later removed)

### e2d6a1b — Fix PHP API compatibility and output corruption
- Fixed PHP output buffering issues causing JSON responses to be corrupted by stray whitespace/notices
- Added `ob_start()` / `ob_clean()` guards

### baa7bed — Fix notifications JS: DOMContentLoaded never fires for Astro modules
- Astro `<script>` blocks run as ES modules after the DOM is already parsed
- `DOMContentLoaded` listener never fires; removed it — code now runs at module evaluation time

### 7d7f103 — Add Makefile with sync-data, build, push, deploy targets
- `sync-data`: pulls live `notifications.json` from server before build
- `build`: runs `npm run build`
- `push`: rsyncs `dist/` to server, excluding server-only auth config files
- `deploy`: full pipeline — `sync-data build push`

### 12ec07f — Add dynamic notifications system with staff auth
- `notifications-api.php` with bcrypt login, session management, CRUD for notifications and comments
- `notifications.astro` with live fetch, staff login modal, create/edit/delete forms
- `notifications.json` as the data store

### 0d3b0cf — Initial commit
- Full Astro 5 site with Tailwind CSS v4
- Pages: home, about, VoIP, CCTV, networking, wireless, UFB, websites & hosting, support, notifications, updates, 404, privacy policy, terms, fair use
- Custom fonts: DM Sans, Syne, Space Mono, CentralComms (brand font)
- `Nav.astro`: responsive nav with dropdowns, scroll-aware transparent→white transition
- `Footer.astro`: contact details, service links, social links
- `Layout.astro`: shared head with OG tags, canonical URL, sitemap integration
- Reveal scroll animations via IntersectionObserver
- `@astrojs/sitemap` integration generating `sitemap-index.xml`
