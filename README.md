# centralcomms.nz v2

The website for **Central Communications Ltd** — a Hamilton-based telecommunications and IT services company operating throughout the Waikato and beyond.

This is a full rebuild of the original site, now running as a statically generated site with a lightweight PHP CMS layer for live content management.

---

## What the site does

- Markets CCL's services: VoIP, CCTV, data cabling, managed wireless, UFB, websites & hosting
- Displays a live **Service Notifications** feed — current outages, advisories, and resolved issues
- Hosts a **Project Updates** portfolio — case studies and work highlights, editable by staff via an in-browser CMS
- Provides a **contact form** (home page and support page) that routes to the support team

---

## Built with

| Tool | Role |
|---|---|
| [Astro 5](https://astro.build) | Static site generator — all pages compile to plain HTML |
| [Tailwind CSS v4](https://tailwindcss.com) | Utility-first styling via the Vite plugin |
| [TipTap 3](https://tiptap.dev) | Rich-text editor used in the staff CMS (bundled by Vite) |
| [PHP 8+](https://www.php.net) | Thin API layer for notifications and updates CMS |
| [Vite](https://vitejs.dev) | Bundler (via Astro) — handles JS/CSS tree-shaking and chunking |
| `rsync` + `make` | One-command deploy pipeline |

---

## Key features

### Notifications system
Live service status page driven by `notifications.json`. Staff can log in and create/edit/delete notifications with status levels (info, warning, outage, resolved). Active issues always show in full; resolved/historical items load progressively via infinite scroll. See [`docs/README.md`](docs/README.md) for full details.

### Updates CMS
A portfolio of project case studies managed via an in-browser TipTap rich-text editor. Staff can create, edit, and delete posts with images, YouTube embeds, and tags — all saved to `updates.json` on the server. Running `make deploy` syncs the JSON back locally before rebuilding, so git always reflects the current content state.

### Static + PHP hybrid
All public-facing pages are pre-rendered static HTML (fast, SEO-friendly, no database). The PHP layer only handles write operations (form submissions, CMS saves, image uploads) — it never serves HTML to end users.

### Security model
Staff APIs use bcrypt password hashes, server-side PHP sessions, CSRF tokens, login throttling, same-origin POST checks, and locked JSON writes. Update body HTML is sanitized before storage and validated before build, uploaded images are MIME/dimension-checked, and private credentials/secrets live in gitignored server-only config files.

---

## Docs

Full development, deployment, and CMS documentation lives in [`docs/README.md`](docs/README.md).

Screenshots of the Notifications system and Updates CMS staff interface: [`docs/Screenshots/README.md`](docs/Screenshots/README.md)

Changelog: [`docs/CHANGELOG.md`](docs/CHANGELOG.md)
