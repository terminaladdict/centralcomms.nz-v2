# centralcomms.nz v2 — Developer Documentation

Complete reference for developing, building, deploying, and managing the Central Communications website.

**Navigation:** [Main README](../README.md) · [Screenshots](Screenshots/README.md) · [Changelog](CHANGELOG.md)

---

## Table of contents

1. [Architecture overview](#architecture-overview)
2. [Repository layout](#repository-layout)
3. [Prerequisites](#prerequisites)
4. [Local development](#local-development)
5. [Building](#building)
6. [Deployment](#deployment)
7. [Makefile targets](#makefile-targets)
8. [Notifications system](#notifications-system)
9. [Updates CMS](#updates-cms)
10. [PHP APIs](#php-apis)
11. [Auth configuration](#auth-configuration)
12. [Contact form](#contact-form)
13. [Adding pages](#adding-pages)
14. [Fonts and assets](#fonts-and-assets)
15. [CSS conventions](#css-conventions)
16. [Environment details](#environment-details)
17. [Screenshots](Screenshots/README.md)
18. [Performance and PageSpeed](#webp-images)

---

## Architecture overview

This is a **static site with a thin PHP CMS layer**.

```
Browser                     Server (centralcomms.netent.co.nz /var/www/html)
  │                                │
  │  GET /index.html               │
  ├──────────────────────────────► │  Pre-built static HTML (Astro output)
  │ ◄────────────────────────────  │
  │                                │
  │  POST /assets/php/updates-api.php?action=login
  ├──────────────────────────────► │  PHP — writes to updates.json
  │ ◄────────────────────────────  │
  │                                │
  │  GET /assets/images/posts/*    │
  ├──────────────────────────────► │  Static image files
  │ ◄────────────────────────────  │
```

**Key principle:** PHP never generates HTML for end users. It only handles:
- Staff authentication (bcrypt + PHP sessions)
- Writing JSON data files (`notifications.json`, `updates.json`)
- Uploading/deleting image files

All public-facing HTML is pre-rendered by Astro at build time and served as static files. This means fast load times and no database dependency.

**Content workflow:**
1. Staff edit content via the in-browser CMS
2. Changes are saved to JSON on the server immediately
3. Running `make deploy` pulls the latest JSON → rebuilds → pushes new static HTML
4. The rebuilt pages embed the latest content as static HTML

---

## Repository layout

```
centralcomms.nz-v2/
├── src/
│   ├── components/
│   │   ├── Nav.astro          # Responsive navigation with dropdowns
│   │   └── Footer.astro       # Footer with contact info and links
│   ├── layouts/
│   │   └── Layout.astro       # Shared HTML shell — head, OG tags, fonts
│   ├── pages/
│   │   ├── index.astro        # Home page
│   │   ├── about.astro
│   │   ├── support.astro      # Support page — HelpWire remote support explainer + contact form
│   │   ├── notifications.astro # Live service notifications
│   │   ├── voip.astro
│   │   ├── cctv.astro
│   │   ├── wireless.astro
│   │   ├── networking--data.astro
│   │   ├── ultra-fast-broadband.astro
│   │   ├── websites-and-hosting.astro
│   │   ├── 404.astro
│   │   ├── privacypolicy.astro
│   │   ├── terms.astro
│   │   ├── fair-use.astro
│   │   └── updates/
│   │       ├── index.astro    # Updates portfolio grid
│   │       └── [slug].astro   # Individual update page (dynamic route)
│   ├── content/
│   │   └── config.ts          # Astro content config (collections removed; kept as stub)
│   └── styles/
│       └── global.css         # Global CSS — buttons, typography, reveal animations
├── public/
│   ├── assets/
│   │   ├── data/
│   │   │   ├── notifications.json   # Live service notifications
│   │   │   └── updates.json         # Project update posts (CMS source of truth)
│   │   ├── images/
│   │   │   └── posts/               # Images for update posts
│   │   └── php/
│   │       ├── contact.php                        # Contact form handler
│   │       ├── contact-config.php                 # Contact secrets/addresses (server only, gitignored)
│   │       ├── contact-config.php.example
│   │       ├── notifications-api.php              # Notifications CRUD API
│   │       ├── notifications-auth-config.php      # Credentials (server only, gitignored)
│   │       ├── notifications-auth-config.php.example
│   │       ├── security.php                       # Shared PHP security helpers
│   │       ├── updates-api.php                    # Updates CRUD + image upload API
│   │       ├── updates-auth-config.php            # Credentials (server only, gitignored)
│   │       └── updates-auth-config.php.example
│   └── (other static assets: fonts, images, favicon, .htaccess)
├── docs/
│   ├── README.md       # This file
│   └── CHANGELOG.md    # History of changes
├── Makefile
├── astro.config.mjs
├── package.json
└── README.md
```

---

## Prerequisites

| Requirement | Version | Notes |
|---|---|---|
| Node.js | 18+ | LTS recommended |
| npm | 9+ | Comes with Node |
| rsync | Any | For deployment |
| SSH access | — | Must be able to `ssh paul@centralcomms.netent.co.nz` without a password prompt (use SSH keys) |
| PHP 8+ | Server only | Not needed locally |

Install Node dependencies:

```bash
npm install
```

---

## Local development

Development is done against the live dev server rather than running PHP locally. The Astro dev server handles the frontend, and the PHP APIs are called against the dev URL.

```bash
npm run dev
```

This starts Astro's dev server (usually at `http://localhost:4321`). The `--host` flag is set in `package.json` so it also binds to your local network IP.

> **Note:** PHP-dependent features (notifications login, CMS editing) only work in full when tested against the deployed dev server at `https://dev.centralcomms.nz` (or the raw IP `centralcomms.netent.co.nz`), since PHP is not running locally.

---

## Building

```bash
npm run build
```

Astro compiles all `.astro` pages to static HTML in `dist/`. The build reads `updates.json` and `notifications.json` from `public/assets/data/` — these must be up to date before building.

Output goes to `dist/` and is ready to serve as-is from any web server. The `astro.config.mjs` sets `build.format: 'file'` so pages are generated as `page.html` files (not `page/index.html`). After Astro finishes, `scripts/scrub-server-config.mjs` removes server-only config files from `dist/assets/php/` so local ignored secrets are never packaged into a build artifact.

To preview the built site locally:

```bash
npm run preview
```

---

## Deployment

The full deploy pipeline is one command:

```bash
make deploy
```

This runs three steps in sequence:

### 1. `make sync-data`
Pulls the live `notifications.json` from the server back to `public/assets/data/notifications.json`. This ensures any notifications created via the web UI are preserved in the local repo before rebuilding.

### 2. `make sync-updates`
Pulls the live `updates.json` and any new/updated images in `assets/images/posts/` from the server. This ensures posts created or edited via the CMS are included in the next build.

### 3. `make build`
Runs `npm run build` to generate the static site.

### 4. `make push`
Rsyncs `dist/` to `/var/www/html/` on the server with `--delete` (removes files no longer in the build). After rsync, sets permissions:
- `notifications.json` and `updates.json` → `chmod 666` (web server must be able to write these)
- `notifications.json.lock` and `updates.json.lock` → created if missing and set to `chmod 666` (used to serialize JSON writes)
- `assets/images/posts/` → `chmod 777` (web server must be able to write uploaded images)

Three files are **excluded** from rsync (they live only on the server):
- `assets/php/contact-config.php`
- `assets/php/notifications-auth-config.php`
- `assets/php/updates-auth-config.php`

These are gitignored and never committed. The `.example` versions are committed as templates.

The Makefile defaults can be overridden from the shell when needed:

```bash
make deploy REMOTE_USER=deploy REMOTE_HOST=example.com REMOTE_PATH=/srv/www/site
```

---

## Makefile targets

```makefile
make sync-data      # Pull notifications.json from server
make sync-updates   # Pull updates.json + post images from server
make build          # Run npm run build
make push           # rsync dist/ to server + fix permissions
make deploy         # All of the above in sequence
```

---

## Notifications system

### Overview
The service notifications page (`/notifications.html`) shows the current status of Central Communications services. It fetches data from `notifications-api.php` on page load and renders a status banner and notification cards client-side.

### Data file
`public/assets/data/notifications.json` — structure:

```json
{
  "notifications": [
    {
      "id": "uuid",
      "title": "UFB outage — Hamilton CBD",
      "body": "Customers in the Hamilton CBD area may be experiencing...",
      "status": "outage",
      "author": "paul",
      "created_at": "2026-04-07T10:00:00+12:00",
      "updated_at": "2026-04-07T10:00:00+12:00",
      "comments": []
    }
  ]
}
```

**Status values:**
- `info` — general advisory, no service impact
- `warning` — potential impact, watch this space
- `outage` — confirmed service disruption
- `resolved` — issue resolved (shown in the "older notifications" infinite scroll section)

### Status banner
The banner at the top of the page shows the overall service health:
- Green / "All Systems Operational" — no active `outage`, `warning`, or `info` notifications
- Yellow / "Advisory" — at least one `warning` or `info` active
- Red / "Service Disruption" — at least one `outage` active

While the API call is in flight, an animated SVG character with binoculars is shown as a loading indicator.

### Infinite scroll
Active notifications (status ≠ `resolved`) are always fully visible. Resolved/historical notifications load 5 at a time as the user scrolls to the bottom, using an `IntersectionObserver` with a 200px `rootMargin` look-ahead.

### Staff features
Staff must log in via the "Staff Login" link to access write operations. Login is session-based (PHP sessions).

Once logged in, staff can:
- Create a new notification (title, body, status)
- Edit any existing notification
- Add/edit/delete comments on a notification
- Mark a notification as resolved

Staff action buttons are rendered inside dynamically-built card HTML. Because Astro `<script>` blocks run as ES modules (not in global scope), inline `onclick="fn()"` attributes cannot call module-scoped functions. All card buttons use `data-action`/`data-id`/`data-id2` attributes and a single delegated `click` listener on `#notifications-list`.

### API endpoint
`/assets/php/notifications-api.php`

| Action | Method | Auth required | Description |
|---|---|---|---|
| `me` | GET | No | Check session state |
| `login` | POST | No | Authenticate with username + password |
| `logout` | POST | Yes | Destroy session |
| `list` | GET | No | Return all notifications sorted newest first |
| `create` | POST | Yes | Create a notification |
| `update?id=X` | POST | Yes | Update a notification |
| `delete?id=X` | POST | Yes | Delete a notification |
| `comment_add?id=X` | POST | Yes | Add a comment |
| `comment_update?id=X&comment_id=Y` | POST | Yes | Edit a comment |
| `comment_delete?id=X&comment_id=Y` | POST | Yes | Delete a comment |

All write actions expect a JSON body (`Content-Type: application/json`).

---

## Updates CMS

### Overview
The project updates section (`/updates.html`, `/updates/[slug].html`) displays a portfolio of case studies. Content is managed via an in-browser rich-text editor powered by TipTap.

### Data file
`public/assets/data/updates.json` — structure:

```json
{
  "updates": [
    {
      "slug": "ellicott-road-cctv",
      "title": "Ellicott Road CCTV Installation",
      "date": "2021-06-24",
      "author": "Paul Willard",
      "image": "home_cctv.jpg",
      "categories": ["CCTV"],
      "excerpt": "Complete 6-camera CCTV system with open-source NVR and web-integrated live views.",
      "content_html": "<p>We set up a complete CCTV system...</p>"
    }
  ]
}
```

- `slug` — URL-safe identifier, used in the page URL (`/updates/ellicott-road-cctv.html`)
- `image` — filename only (e.g. `home_cctv.jpg`), resolved to `/assets/images/posts/{image}`
- `content_html` — full rich-text content as HTML, produced by TipTap and sanitized by `updates-api.php` before it is saved
- `categories` — array of tag strings (e.g. `["CCTV", "Infrastructure"]`)

Updates are sorted newest-first by `date` (YYYY-MM-DD).

### Build-time rendering
At build time, Astro imports `updates.json` directly and generates a static HTML page for every slug in the array. Adding a new post via the CMS and then running `make deploy` will generate and publish the new page.

### Editor features
The TipTap editor (loaded only for logged-in staff) supports:
- Headings (H2, H3), bold, italic
- Bullet and ordered lists
- Blockquotes
- Horizontal rules
- Image insertion (uploaded to server via `upload_image` API action)
- YouTube video embeds (enter a YouTube URL or video ID)

### Staff workflow: create a new post
1. Go to `/updates.html`
2. Click **Staff Login** and log in
3. Click **+ New Update**
4. Fill in title, excerpt, date, author, categories, and featured image
5. Write content in the TipTap editor — use the toolbar for formatting, the "Insert Image" button to upload and embed images, and "Embed YouTube" for video
6. Click **Save** — the post is written to `updates.json` on the server
7. Run `make deploy` to rebuild and publish the new page

### Staff workflow: edit an existing post
1. Navigate to the post's page (e.g. `/updates/ellicott-road-cctv.html`)
2. The **Staff Bar** appears at the bottom of the page if you are logged in (it checks the session on page load)
3. Click **Edit Post** — the editor overlay opens pre-populated with current content fetched from the API
4. Make changes and click **Save Changes** — `updates.json` is updated immediately; the visible page DOM is also updated in-place
5. Run `make deploy` to rebuild and make the changes permanent in the static HTML

### Staff workflow: delete a post
1. Navigate to the post page
2. Click **Delete Post** in the staff bar
3. Confirm the dialog
4. The post is removed from `updates.json`
5. Run `make deploy` to remove the page from the site

### Image management
- **Featured image:** Shown as the hero image on the post page and as the card thumbnail on the index. Set via the "Upload Image" button in the meta column of the editor overlay. Click "Remove" to clear it.
- **Content images:** Insert inline images anywhere in the body using the "Insert Image" button below the editor. These are uploaded to `/assets/images/posts/` on the server.
- Images are stored on the server in `/var/www/html/assets/images/posts/`. Running `make sync-updates` pulls them back to `public/assets/images/posts/` so they are included in the next build.

### API endpoint
`/assets/php/updates-api.php`

| Action | Method | Auth required | Description |
|---|---|---|---|
| `me` | GET | No | Check session state |
| `login` | POST | No | Authenticate |
| `logout` | POST | Yes | Destroy session |
| `list` | GET | No | Return all updates |
| `get?slug=X` | GET | No | Return single update |
| `create` | POST | Yes | Create a new update |
| `update?slug=X` | POST | Yes | Update an existing post |
| `delete?slug=X` | POST | Yes | Delete a post |
| `upload_image` | POST (multipart) | Yes | Upload an image file |
| `delete_image` | POST | Yes | Delete an image file by filename |

---

## PHP APIs

### Session isolation
Notifications and updates use independent session keys so staff can be logged in/out of each system separately:
- Notifications: `$_SESSION['staff_user']`
- Updates: `$_SESSION['updates_staff_user']`

Both share the same PHP session (same cookie), but auth state is tracked independently.

### Security
- Passwords are stored as bcrypt hashes (`password_hash(..., PASSWORD_DEFAULT)`)
- Constant-time comparison prevents username enumeration (a dummy hash is always checked even for unknown usernames)
- `session_regenerate_id(true)` is called on login to prevent session fixation
- Staff session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` when served over HTTPS
- Staff login attempts are rate-limited by IP address and username, with failures written to the PHP error log
- Staff POST requests must be same-origin using `Origin` or `Referer`; requests without either header are rejected
- Staff write actions require a per-session CSRF token in the `X-CSRF-Token` header. The token is returned by `me` and `login`.
- All write actions require authentication
- Notification title/body/comment fields and update title/author/excerpt/content/category fields have server-side length caps
- Image uploads validate MIME type via `mime_content_type()` and `getimagesize()`, enforce a 5 MB size limit, cap dimensions at 6000×6000, and rate-limit each staff user to 30 uploads per hour; filenames use a `bin2hex(random_bytes(3))` random suffix to prevent collisions without a TOCTOU race
- `delete_image` resolves the target path via `realpath()` and confirms it is inside the images directory before unlinking (path traversal defence)
- Featured-image filenames accepted from JSON requests are reduced to safe image basenames only (`jpg`, `jpeg`, `png`, `gif`, `webp`)
- Update body HTML is sanitized server-side before it is stored. Only the editor tags required for posts are allowed; event-handler attributes, `javascript:` URLs, non-YouTube iframes, and unsafe image URLs are stripped.
- `npm run build` validates `updates.json` before Astro builds and fails if stored HTML contains unsafe tags, event handlers, JavaScript URLs, bad iframe embeds, or unsafe image URLs
- Output buffering (`ob_start()` / `ob_clean()`) prevents PHP notices/warnings from corrupting JSON responses

### JSON data safety
Both APIs use a per-file `.lock` file around read-modify-write operations. This prevents concurrent CMS requests from overwriting each other with stale data. Writes still use a write-to-temp-then-rename pattern so an interrupted write does not leave a partial JSON file:

```php
flock($lock, LOCK_EX);
$data = read_data_unlocked($file);
// mutate $data
write_data_unlocked($file, $data);
flock($lock, LOCK_UN);
```

---

## Auth configuration

### Notifications
1. On the server, copy the example file:
   ```bash
   cp /var/www/html/assets/php/notifications-auth-config.php.example \
      /var/www/html/assets/php/notifications-auth-config.php
   ```
2. Generate a password hash:
   ```bash
   php -r "echo password_hash('your-password', PASSWORD_DEFAULT) . PHP_EOL;"
   ```
3. Edit `notifications-auth-config.php` and replace the placeholder hash with the generated one:
   ```php
   $STAFF_USERS = [
       'paul' => '$2y$12$...',
   ];
   ```

### Updates
Same process, but the file is `updates-auth-config.php` and the variable is `$UPDATES_STAFF_USERS`:

```bash
cp /var/www/html/assets/php/updates-auth-config.php.example \
   /var/www/html/assets/php/updates-auth-config.php
```

```php
$UPDATES_STAFF_USERS = [
    'paul' => '$2y$12$...',
];
```

> Both auth config files are gitignored. They live only on the server and are never committed to the repository.

---

## Contact form

The contact form appears on both the home page and the support page. Both submit to `/assets/php/contact.php` via `fetch()`.

### Validation
Client-side (runs before the request):
- Name must be ≥ 2 characters
- Email must match a valid format

Server-side (PHP backstop):
- All three required fields (name, email, message) must be non-empty
- Name must be ≥ 2 characters (`mb_strlen`)
- Email validated with `FILTER_VALIDATE_EMAIL`
- Name, email, phone, and message have server-side length caps
- Submissions are rate-limited by IP address
- Message must not contain URLs (basic spam filter)

### Configuration
The contact form uses a server-only config file for private values:

```bash
cp /var/www/html/assets/php/contact-config.php.example \
   /var/www/html/assets/php/contact-config.php
```

Then set:

```php
$CONTACT_RECAPTCHA_SECRET = '...';
$CONTACT_RECIPIENT = 'support@smtp.centralcomms.nz';
$CONTACT_FROM = 'noreply@centralcomms.nz';
```

`contact-config.php` is gitignored and excluded from deploy. The browser-side reCAPTCHA site key is intentionally public and remains in the static pages.

The `From` header comes from `$CONTACT_FROM`. The `Reply-To` is set to the sender's validated email address so replies go directly to the enquirer. CR/LF characters are stripped from name, phone, and email before the message is composed to prevent email header injection.

---

## Adding pages

1. Create a new file in `src/pages/`, e.g. `src/pages/new-service.astro`
2. Import the shared layout:
   ```astro
   ---
   import Layout from '../layouts/Layout.astro';
   ---
   <Layout title="New Service — Central Communications Ltd" description="...">
     <!-- page content -->
   </Layout>
   ```
3. The page will be built to `dist/new-service.html` and will appear in the sitemap automatically (via `@astrojs/sitemap`)
4. Add a link to `Nav.astro` or `Footer.astro` as needed

### Layout props

| Prop | Required | Default | Description |
|---|---|---|---|
| `title` | Yes | — | Page `<title>` and OG title |
| `description` | No | Default CCL description | Meta description and OG description |
| `image` | No | Screenshot of site | OG image (absolute path from public root) |
| `noindex` | No | `false` | Set to `true` to add `<meta name="robots" content="noindex">` |

---

## Fonts and assets

### Typefaces
| Font | Usage | Source |
|---|---|---|
| DM Sans | Body copy, UI | Google Fonts (self-hosted via CSS `@font-face`) |
| Syne | Headings, buttons, nav labels | Google Fonts |
| Space Mono | Monospace labels, tags, section labels | Google Fonts |
| CentralComms | Brand logo text | Custom font in `public/assets/fonts/centralcomms/` |

### Static assets
All files in `public/` are copied to `dist/` unchanged during build:
- `public/assets/images/` — site images, post images, icons
- `public/assets/images/testimonials/` — avatar images in JPG/PNG and WebP (1x and 2x)
- `public/assets/fonts/` — self-hosted web fonts
- `public/assets/php/` — PHP scripts (copied as-is; Astro does not process PHP)
- `public/assets/data/` — JSON data files
- `public/.htaccess` — Apache rewrite rules, caching, and security headers

### WebP images
Performance-critical images are provided in WebP format alongside the original JPG/PNG as fallbacks. All `<img>` tags for these images are wrapped in `<picture>` elements:

```html
<picture>
  <source srcset="/assets/images/foo.webp" type="image/webp" />
  <img src="/assets/images/foo.jpg" alt="…" width="520" height="420" loading="lazy" />
</picture>
```

For testimonial avatars, 1x and 2x srcset descriptors are used:

```html
<source srcset="avatar-160.webp 1x, avatar-320.webp 2x" type="image/webp" />
```

When adding new testimonial avatars, generate both sizes with ImageMagick:

```bash
# 1x (160×160)
convert input.jpg -resize 160x160^ -gravity Center -extent 160x160 -quality 82 name-160.webp
# 2x (320×320)
convert input.jpg -resize 320x320^ -gravity Center -extent 320x320 -quality 82 name-320.webp
```

### Apache `.htaccess`
`public/.htaccess` controls URL rewriting, caching, and security headers. Key rules:

**Direct access blocks:**
- `contact-config.php`, `notifications-auth-config.php`, and `updates-auth-config.php` are denied over HTTP. PHP can still include them locally.

**Caching:**
- Images, CSS, JS, fonts: `Cache-Control: public, max-age=31536000, immutable` (1 year). CSS/JS are safe to cache forever because Astro appends a content hash to filenames on each build.
- HTML and JSON: `no-cache, no-store` — these are regenerated on each deploy or updated by the CMS.

**Security headers:**
| Header | Value | Purpose |
|---|---|---|
| `X-Frame-Options` | `DENY` | Prevents this site being embedded in an iframe |
| `X-Content-Type-Options` | `nosniff` | Prevents MIME-type sniffing |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Limits referrer data sent to third parties |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains; preload` | HTTPS-only, eligible for browser preload list |
| `Cross-Origin-Opener-Policy` | `same-origin` | Isolates browsing context |
| `Content-Security-Policy` | See below | Restricts resource origins |

**Content Security Policy:**
```
default-src 'self'
script-src 'self' 'unsafe-inline'        ← unsafe-inline required by Astro's runtime module loader
script-src-attr 'none'                   ← blocks inline event handler attributes
style-src 'self' 'unsafe-inline' https://fonts.googleapis.com
font-src 'self' https://fonts.gstatic.com
img-src 'self' data:                     ← data: needed for transparent pixel placeholder
connect-src 'self'                       ← AJAX calls to PHP APIs (same-origin)
form-action 'self'
object-src 'none'                        ← blocks Flash/plugin content
base-uri 'self'                          ← prevents <base href> injection
frame-ancestors 'none'                   ← aligned with X-Frame-Options: DENY
```

> **Note on `unsafe-inline` in script-src:** Astro's static output includes an inline module bootstrapper script that cannot be hashed at build time. Removing `unsafe-inline` will break the site. A nonce-based approach would require server-side rendering (SSR mode), which is not used here.

---

## CSS conventions

Global styles live in `src/styles/global.css` and apply to all pages. Page-specific styles are written in `<style>` blocks inside each `.astro` file.

### Key global classes

| Class | Purpose |
|---|---|
| `.btn` | Base button style |
| `.btn-primary` | Blue filled button |
| `.btn-ember` | Amber/orange filled button (CTAs) |
| `.btn-outline` | Blue outlined button |
| `.btn-outline-light` | White outlined button (for dark backgrounds) |
| `.container` | Max-width 1200px, auto-margin, horizontal padding |
| `.section` | Standard vertical padding for content sections |
| `.section-alt` | Section with the light blue-white background (`#f4f8fd`) |
| `.reveal` | Hidden by default (`opacity:0`, `translateY(24px)`); made visible by IntersectionObserver adding `.visible` |
| `.reveal-delay-1` to `.reveal-delay-5` | Staggered transition delays for reveal animations |
| `.card` | Standard card with dark background and blue border |
| `.section-tag` | Small uppercase monospace label |
| `.prose` | Rich text content area (headings, paragraphs, images from TipTap output) |

### Colour palette (key values)

**Dark-background palette** (hero, cards, CMS overlays):

| Token | Value | Usage |
|---|---|---|
| Dark navy | `#0c1a2e` | Page backgrounds, dark sections |
| Mid navy | `#071525` | Card backgrounds, hero gradients |
| Primary button | `#0369a1` | `.btn-primary` background (5.5:1 contrast with white, WCAG AA) |
| Primary button hover | `#0284c7` | `.btn-primary:hover` (lighter on hover) |
| Sky blue | `#0ea5e9` | Accents, icon colour |
| Body text | `#e8f1ff` | Text on dark backgrounds |
| Muted text | `#7ea3c8` | Secondary text on dark backgrounds, footer copy |

**Light-background palette** (`.section`, `.section-alt`, `.card` — backgrounds `#f4f8fd` / `#ffffff`):

| Token | Value | Usage |
|---|---|---|
| Section light bg | `#f4f8fd` | `.section-alt` background |
| Heading | `#1e3a5f` | `h2`, `h3`, list items, bold values |
| Body copy | `#334e68` | Paragraph text, bios, excerpts |
| Label / secondary | `#486581` | `<dt>` labels, dates, muted meta |
| Link / role | `#0369a1` | Links, job titles, hover on post titles |

> **Important:** The global `.btn-outline` class uses `color: #0c1a2e` (dark text) — it is designed for use on light backgrounds. If you place a `.btn-outline` inside a dark container (e.g. `.cta-box`), you must add a scoped override:
> ```css
> .cta-box .btn-outline { color: #e8f1ff; border-color: rgba(232,241,255,0.35); }
> .cta-box .btn-outline:hover { color: #38bdf8; border-color: #38bdf8; }
> ```

---

## Environment details

| Item | Value |
|---|---|
| Production URL | `https://www.centralcomms.nz` |
| Production server host | `centralcomms.netent.co.nz` |
| Production server path | `/var/www/html` |
| Deploy user | `paul` by default; override with `make deploy REMOTE_USER=...` |
| Site canonical origin | Defined in `astro.config.mjs` as `https://www.centralcomms.nz` |
| Node version | 18+ |
| PHP version | 8+ (on server) |

### `.gitignore` additions
The following are excluded from version control (server-only):
```
public/assets/php/notifications-auth-config.php
public/assets/php/updates-auth-config.php
public/assets/php/contact-config.php
```
