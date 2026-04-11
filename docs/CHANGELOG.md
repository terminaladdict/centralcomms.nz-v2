# Changelog

All notable changes to centralcomms.nz v2.

---

## [Unreleased]

### Security review hardening

- `contact.php`: moved the private reCAPTCHA secret, recipient address, and sender address into server-only `contact-config.php`; added `contact-config.php.example`, `.gitignore`, and deploy exclusion.
- `contact.php`: added config validation, IP-based rate limiting, server-side field length caps, and stripped CR/LF from name and phone fields before composing mail headers/body.
- `public/assets/php/security.php`: added shared session, file-backed rate limiting, CSRF token, same-origin, and string length helper functions.
- `notifications-api.php`, `updates-api.php`: added `HttpOnly`, `SameSite=Lax`, HTTPS-aware `Secure` session cookies, same-origin `Origin`/`Referer` checks, per-session CSRF tokens, and file-backed login throttling for staff POST requests.
- `notifications-api.php`, `updates-api.php`: login failures now write to the PHP error log for server-side alerting/monitoring.
- `notifications-api.php`, `updates-api.php`: added per-data-file lock files around read-modify-write operations to prevent lost updates under concurrent CMS requests.
- `updates-api.php`: added server-side HTML sanitization for saved rich-text update bodies; allows only CMS-required tags/attributes, safe links/images, and YouTube embed iframes.
- `updates-api.php`: tightened upload handling to 5 MB max, `getimagesize()` validation, 6000×6000 dimension cap, and per-user upload rate limiting.
- `notifications-api.php`, `updates-api.php`: added server-side length caps for CMS text fields.
- `notifications.astro`, `updates/index.astro`, `updates/[slug].astro`: staff write requests now send the `X-CSRF-Token` header returned by the APIs.
- `notifications-api.php`: logout now clears only the notifications staff session key instead of destroying the entire PHP session, matching the independent auth model used by the updates CMS.
- `updates/index.astro`: removed the hardcoded default author in the New Update form; blank authors now fall back to the authenticated staff username on the server.
- `updates/index.astro`: scoped dark-overlay button styling for the New Update window so Cancel, Upload Image, Insert Image, Remove, and Embed YouTube remain readable.
- `.htaccess`: denied direct HTTP access to server-only PHP config files while still allowing local PHP includes.
- `.htaccess`: `X-Content-Type-Options: nosniff` now applies to all responses, including uploaded images; CSP now includes `script-src-attr 'none'` to block inline event-handler attributes.
- `package.json`, `scripts/scrub-server-config.mjs`, `scripts/validate-updates-html.mjs`: `npm run build` now validates stored update HTML before Astro builds, then removes server-only config files from `dist/assets/php/` after Astro copies `public/`, preventing ignored local secrets from entering build artifacts.
- `Makefile`: changed deploy connection values to overridable defaults (`?=`), added `contact-config.php` to the rsync exclusions, and creates writable JSON lock files during push.
- `docs/README.md`: updated deployment paths from `/var/www/dev` to `/var/www/html`, documented the new contact config, JSON locking, HTML sanitization, session-cookie settings, same-origin POST checks, and Makefile overrides.

---

## 2026-04-08 (PageSpeed / performance / security hardening)

### W3C HTML validation fixes

- `Layout.astro`: fixed JSON-LD `<script type="application/ld+json">` — was rendering `{JSON.stringify(...)}` as literal text; fixed with `set:html` directive. Corrected telephone to E.164 format (`+648008480038`). Added `priceRange` and `image` fields to `LocalBusiness` schema.
- `Nav.astro`, `Footer.astro`: removed redundant `role="list"` from `<ul>` elements.
- `index.astro`, `support.astro`, `notifications.astro`, `updates/index.astro`, `updates/[slug].astro`: moved all `<script>` blocks to inside `</Layout>` — scripts placed after `</Layout>` were rendering after `</html>` causing "stray start tag script" errors.
- `updates/index.astro`, `updates/[slug].astro`: hidden preview `<img>` had `src=""` (invalid); replaced with a 1×1 transparent GIF data URI.
- `updates/[slug].astro`: changed content wrapper from `<article>` → `<div>` (article/section without internal heading triggers W3C warning; the post title is in the hero above the wrapper).

### PageSpeed Insights — 90 → 98/100 desktop, 91/100 mobile

#### Apache caching and security headers (`.htaccess`)
- `mod_expires` + `Cache-Control: immutable` for images, CSS, JS, and fonts (1 year) — CSS/JS are safe to cache because Astro hashes filenames
- `no-cache, no-store` for HTML and JSON — prevents caching of regenerated pages and live data files
- Security headers added: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Strict-Transport-Security` (with `preload`), `Cross-Origin-Opener-Policy: same-origin`
- CSP: `default-src 'self'`; `script-src 'self' 'unsafe-inline'` (required for Astro's runtime module loader); `style-src` allows Google Fonts; `font-src` allows gstatic; `object-src 'none'`; `base-uri 'self'`; `frame-ancestors 'none'`
- `X-Frame-Options: DENY` aligned with `frame-ancestors 'none'` in CSP (previously SAMEORIGIN/none were contradictory)

#### Image optimisation
- All testimonial avatars, `central_comms_montage.jpg`, and `pspla-license-2026.png` converted to WebP
- Testimonials: generated both `{name}-160.webp` (1x, 160×160) and `{name}-320.webp` (2x, 320×320)
- Static images wrapped in `<picture>` with `<source type="image/webp">` and original JPG/PNG fallback
- Testimonial `<picture srcset="{name}-160.webp 1x, {name}-320.webp 2x">` — standard screens get the smaller file; retina screens get 2x
- Fixed pspla badge dimensions: `width="140" height="97"` (was `width="180"` with no height — CSS forces 140px; missing height was causing CLS)
- Added `width="160" height="160"` to `.testimonial-avatar-zoom` images (previously no explicit size on the hover-popup image)

#### LCP fix — hero `<h1>`
- Removed `reveal reveal-delay-1` classes from `<h1 class="hero-heading">` — the reveal animation started with `opacity: 0` which delayed the LCP element until the IntersectionObserver fired (~2,550ms). The `<h1>` now paints immediately on first render. Other hero elements (tagline, sub, CTAs) retain their staggered reveal.

#### Contrast fixes (WCAG AA)
- `btn-primary` background: `#0284c7` → `#0369a1` (white text contrast: 3.9:1 → 5.5:1, passes AA)
- `btn-primary:hover` background: `#0ea5e9` → `#0284c7` (hover is a lighter shade now)
- `Footer.astro` `.footer-coverage`, `.footer-copy`, `.footer-legal a`: `#2a4a6e` → `#7ea3c8` (contrast on `#071525`: 2.3:1 → 7.5:1, passes AA)
- `.footer-legal a:hover`: `#7ea3c8` → `#e8f1ff`

#### Duplicate link label fix
- Hero "Get in Touch" button renamed to "Contact Us" and given `aria-label="Contact us via the form below"` — was identical to the nav "Get in Touch" button pointing to a different URL, which caused an accessibility warning

#### Font loading
- Google Fonts `display=swap` retained (reverted from `display=optional` which prevented custom fonts from loading on first visit and all subsequent visits in many cases)

---

## 2026-04-07 (post-security-review UI/UX fixes)

### Remote support: RustDesk → HelpWire

- `support.astro`: full rewrite of the remote support section — replaced RustDesk box, screenshot, and pre-configured `.exe` download with a HelpWire explainer box
- Updated workflow steps: call us → we send the app → we take over (no self-serve download)
- Removed `public/assets/rustdesk-host=...exe`, `public/assets/images/RustDesk_image.jpg`, and `public/assets/images/rustdesk.svg`
- Fixed stale copy: removed "below" reference to a download button that no longer exists

### Light-mode readability fixes

All service pages (voip, cctv, networking--data, wireless, ultra-fast-broadband, websites-and-hosting) and content pages had colours designed for dark backgrounds that were unreadable on the light section backgrounds (`#f4f8fd`, `#ffffff`):

- `.feature-list li` colour: `#b8cce0` → `#1e3a5f` (dark navy, readable on white)
- `.cta-box .btn-outline` scoped override added — global `.btn-outline` uses `color:#0c1a2e` (dark, for light backgrounds) which was unreadable inside the dark `.cta-box`; fixed with a scoped `color:#e8f1ff` override and a `#38bdf8` hover

`about.astro`:
- `.section-heading`: `#e8f1ff` → `#1e3a5f`
- `.body-text`: `#7ea3c8` → `#334e68`
- `.team-name`: `#e8f1ff` → `#1e3a5f`
- `.team-role`: `#0ea5e9` → `#0369a1`
- `.team-bio`: `#7ea3c8` → `#334e68`
- `.info-row dt`: `#7ea3c8` → `#486581`
- `.info-row dd`: `#e8f1ff` → `#1e3a5f`

`updates/index.astro`:
- `.post-title`: `#e8f1ff` → `#1e3a5f`; hover `#0ea5e9` → `#0369a1`
- `.post-excerpt`: `#7ea3c8` → `#334e68`
- `.post-date`: `#7ea3c8` → `#486581`

### Other fixes

- `index.astro`: hero heading `.hero-heading` `font-size` clamp minimum reduced `2.8rem` → `2rem` so the title scales down correctly on narrow mobile screens
- `cctv.astro`: PSPLA licence image was 420 px wide on narrow phones, causing horizontal page scroll — added `width:100%` to the inline style and `min-width:0` to `.prose` (CSS grid child `min-width` defaults to `auto`, allowing content to overflow its track)
- `about.astro`: PSPLA licence image wrapped in `<a href="..." target="_blank" rel="noopener">` with `cursor:zoom-in` so visitors can open the full-size image
- `support.astro`: `24/7 emergency service` strong tag colour updated to `#27448f` for readability on the light section background

### Documentation

- `docs/Screenshots/README.md`: added full walkthrough of all 9 CMS screenshots (Notifications and Updates systems), explaining each screen and the underlying functionality
- `docs/README.md`: added navigation bar, Screenshots link in table of contents
- `README.md`: added Screenshots link in Docs section

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
