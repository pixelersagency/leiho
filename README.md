# Okno

**Visual editing for headless WordPress.** Your editors see the real front end — Next.js, Astro, Remix, Vite, Nuxt, SvelteKit or plain HTML — inside wp-admin. They click a heading, change it, watch it update live, save, and publish. Define your content types in Okno, or keep your existing ACF fields: either way WordPress stays the single source of truth, with no page builder and no lock-in.

> *Okno* means *window* in Polish, Czech and Russian — a window onto the real site.

[Français](README.fr.md) · [Changelog](CHANGELOG.md) · [Bridge package](bridge/) · [Framework examples](examples/)

> **Status: 1.0 beta.** The interface is in English and French (it follows the WordPress site language).

---

## Why

Headless WordPress gives developers a modern front end, but it takes the "what you see" away from editors. They fill in forms blind, wait for a build, and discover the result on the live site. Okno puts the site back in front of them, without asking developers to give up their stack.

- **Works with any front end.** A ~10 KB, dependency-free bridge talks to wp-admin through `postMessage`. React apps get a one-line component.
- **Your data model, untouched.** Okno reads and writes the ACF fields you already have — repeaters, groups, flexible content, relationships, images.
- **Or no field plugin at all.** Okno Collections defines content types and fields in Okno itself, stored in typed tables. WordPress + Okno is enough.
- **Nothing public.** The bridge never calls the WordPress API and holds no secrets. Every message is origin-checked on both sides.
- **Publishing that fits your hosting.** Live content, a build hook (Vercel, Netlify, Cloudflare Pages), GitHub Actions with live build status, a publish commit, or Coolify.

## How it works

```
┌──────────────────────── wp-admin ────────────────────────┐
│  Okno editor                                             │
│  ┌──────────────┐  ┌───────────────────────────┐  ┌─────┐│
│  │ Pages        │  │  <iframe> your real site  │  │Field││
│  │ Structure    │  │  ┌─────────────────────┐  │  │panel││
│  │ History      │  │  │ okno-bridge.js      │◄─┼──┤     ││
│  └──────────────┘  │  └─────────────────────┘  │  └─────┘│
│                    └───────────────────────────┘         │
│             REST (okno/v1, nonce + capabilities)         │
└───────────────────────────┬──────────────────────────────┘
                            ▼
                     WordPress + ACF
```

1. The editor loads your front end in an iframe.
2. The bridge finds the elements you annotated with `data-wp-field` and reports them.
3. Clicking an element opens its field. Typing updates the page instantly, in the browser only.
4. **Save** writes to WordPress. **Publish** triggers your deployment (or nothing at all in live mode).

## Quick start

### 1. Install the plugin

[Download `okno.zip`](https://github.com/pixelersagency/okno/releases/latest/download/okno.zip) (latest version, also listed on the [releases page](https://github.com/pixelersagency/okno/releases)) and upload it in **Plugins → Add New → Upload Plugin**. Later versions show up in **Plugins** like any other update. Requires WordPress 6.0+, PHP 7.4+ and [ACF](https://www.advancedcustomfields.com/) (free or Pro). Without ACF, only the title and featured image are editable.

### 2. Point it at your site

**Okno → Settings**: your front-end URL, the post types editors may open, and the URL pattern for each one (`/{slug}` by default; the front page maps to `/`).

### 3. Allow wp-admin to frame your site

Send this header from your front end (**Okno → Get started** shows it with your domain filled in, and checks it for you):

```
Content-Security-Policy: frame-ancestors 'self' https://your-wordpress.example
```

### 4. Add the bridge and annotate your markup

**React (Next.js, Remix, Vite…)**

```bash
npm install @pixelersagency/okno-bridge
```

```jsx
// Root layout: app/layout.tsx, root.tsx, App.tsx…
import { OknoBridge } from '@pixelersagency/okno-bridge/react';

<OknoBridge wpOrigin="https://your-wordpress.example" />
```

**Any other site (Astro, Nuxt, SvelteKit, HTML)**: copy `okno-bridge.js` (downloadable from **Okno → Get started**) to your public folder and add to `<head>`:

```html
<script>
  // Loads the bridge only inside the editor: visitors get ~150 bytes, not 10 KB.
  if (window.self !== window.top) {
    var s = document.createElement('script');
    s.src = '/okno-bridge.js';
    s.setAttribute('data-wp-origin', 'https://your-wordpress.example');
    document.head.appendChild(s);
  }
</script>
```

Then annotate what should be editable:

```html
<main data-wp-post="12">
  <h1 data-wp-field="_title">About us</h1>
  <p data-wp-field="intro">We build things.</p>
  <img data-wp-field="hero_image" src="/hero.jpg" alt="" />
</main>
```

Back in wp-admin, **Okno → Get started → Test the connection** confirms that the bridge answers. You're done. Step-by-step setups for each framework are in [`examples/`](examples/).

> **Using a coding agent?** **Okno → Get started** gives you ready-to-paste prompts and the full integration contract ([`plugin/docs/agent-instructions.md`](plugin/docs/agent-instructions.md)) for Claude Code, Cursor, Codex and the like.

## Annotations

| Attribute | Purpose |
|---|---|
| `data-wp-post="12"` | Container: the WordPress post ID for the fields inside |
| `data-wp-field="hero_title"` | Editable element: ACF field name, or `_title` / `_thumbnail` |
| `data-wp-field="cards.0.title"` | Dotted paths reach into repeaters, flexible content and groups |
| `data-okno-apply="html"` | The value contains markup (an accent `<span>` in a heading): inject filtered HTML instead of text |
| `data-okno-refresh="save"` | No live preview for this field; reload the page after saving (complex WYSIWYG rendering) |
| `data-okno-section="Hero"` | Name a section in the Structure tree |
| `data-okno-layout="sections.2"` | Bind a section to a flexible content row, so editors can add, move, duplicate and remove it |
| `data-okno-managed="menu"` | Content managed elsewhere (menus, post lists, WooCommerce): clicking explains where to edit it |
| `data-okno-edit-url="…"` | The wp-admin screen for that managed content |

**One rule:** always render an annotated element, even when its value is empty. `{title && <h2 data-wp-field="title">…}` breaks live editing, because an element missing from the page can't be filled in. Okno flags such fields as "not on this page".

Client-side navigation (React Router, Next.js links, `history.pushState`) and re-renders are handled: the bridge re-scans the page and keeps unsaved edits applied.

## Supported fields

- **Content**: text, textarea, WYSIWYG, number, range, URL, email, oEmbed, page link, link, select, true/false, date, date-time, time.
- **Media**: image (with crop-ratio warnings), file, gallery.
- **Relations**: post object, relationship, taxonomy.
- **Containers**: repeater, group, clone, flexible content, nested freely.
- **Native**: title and featured image.

Other field types show up read-only, with a link to the classic WordPress editor.

## What editors get

- Live preview while typing, with character counters where fields have a limit.
- Undo / redo (Ctrl+Z, Ctrl+Shift+Z) and Ctrl+S to save.
- Local drafts: unsaved changes survive a closed tab, and are offered back on return.
- Page composition: add, reorder, duplicate and remove flexible content blocks.
- Create, duplicate and trash pages without leaving the editor.
- Conflict protection: if someone else changed the page meanwhile, Okno refuses to overwrite it and offers to reload.
- Change history: every saved change, with its before and after values and its author.
- Deep links: "Edit with Okno" in the admin bar opens the current page.

## Publishing modes

| Mode | For | What Publish does |
|---|---|---|
| **Live content** | Sites that fetch WordPress at request time (SSR, ISR) | Nothing to publish: saving is enough, the button is hidden |
| **Build hook** | Vercel, Netlify, Cloudflare Pages, any rebuild URL | POSTs to the hook |
| **GitHub Actions** | Static builds run in Actions | Dispatches a workflow and follows the run live |
| **GitHub commit** | Hosts that deploy on every push | Pushes an empty commit to the branch |
| **Coolify** | Self-hosted Coolify | Calls the deployment webhook |

Tokens are encrypted in the database with a key derived from your WordPress salts. GitHub settings are checked when saved: repository, branch, workflow and token expiry.

Publishing can also be automatic: tick **Publish on its own after each change** and Okno deploys once the edits stop (60 s by default), changes made in wp-admin included.

## Content API

Okno also serves your content to the front end, so WordPress is the only source of truth and the front-end repository holds code, not content.

```ts
// astro.config / build script — Node 18+
import { loadOknoContent, indexOknoContent } from '@pixelersagency/okno-bridge/content';
import type { OknoExport, Page } from './src/okno'; // npx okno types

const { data } = await loadOknoContent( { url: 'https://cms.example.com' } );
const content = indexOknoContent( data as OknoExport );
const home = content.byPath( '/' ) as Page;
home.fields.hero_title; // typed
```

| Endpoint | Returns |
|---|---|
| `GET /wp-json/okno/v1/export` | Every published item of the Okno content types, in one response. Cached until something changes, with an `ETag` (an unchanged build gets a `304`). |
| `GET /wp-json/okno/v1/content?type=&path=&slug=` | A filtered, paginated list. |
| `GET /wp-json/okno/v1/content/{id}` | One item (drafts for logged-in editors). |
| `GET /wp-json/okno/v1/types` | TypeScript declarations of exactly that JSON, generated from your ACF field groups. |

Each item carries `id`, `type`, `slug`, `path` (the front-end path from your URL settings), `title`, dates, `excerpt`, rendered `content`, `featured_image`, `terms` and `fields`. Fields are keyed by name and already shaped for a front end: an image is `{ id, url, alt, width, height, sizes }`, a relation is `{ id, type, slug, title, path }`, a WYSIWYG is rendered HTML, a flexible content row has a `_layout`.

- **One request per build** instead of one per page: no more "Error establishing a database connection" on shared hosting.
- **Snapshot fallback**: every successful load is written to `.okno/content.json`. If WordPress doesn't answer, the build carries on with the last known content and says so (`fallback: 'error'` to fail instead).
- **Types**: `npx okno types --url https://cms.example.com` writes `src/okno.d.ts`. Regenerate it when you change fields.
- **Access**: public by default (published content only, which is public anyway). Switch to *Restricted* in the settings and set `OKNO_TOKEN` in the build environment.

## Collections

Okno Collections is a content model built into Okno: "Strapi or Webflow collections inside WordPress", without ACF. A **collection** has many entries (Services, Articles); a **singleton** has exactly one (Home, Contact, Site settings).

- **Entries are WordPress posts** (one post type per collection): roles, drafts, scheduling, trash, revisions, the media library, SEO and multilingual plugins keep working.
- **Field values live in one table per collection** (`wp_okno_<collection>`), one row per entry, not in postmeta: typed, indexed columns for scalar fields, JSON for lists, blocks and groups. MySQL 5.7+, MariaDB and the SQLite drop-in.
- **Field types**: text, rich text, number, boolean, date, date-time, select (single or multiple), slug, email, URL, color, image, file, gallery, link, reference (to another collection, one or many), list, blocks and group, nested freely.
- **Schema as code**: export the schema to `okno.schema.json`, keep it in your front-end repository, import it on another site.
- **Safe migrations**: renaming a field is free (fields have a stable `key`), a type change copies values into a new column, and removed or retyped columns are archived, never dropped. `dry_run` shows the plan first.
- **Revisions** include field values: restoring a revision restores the fields too.
- **Same editor, same API**: entries are edited in the visual editor with the same dotted paths as ACF (`sections.2.items.1.title`), and served by `/okno/v1/export`, `/content` and `/types` like any other content. ACF keeps working alongside.

```json
{
  "okno": "collections",
  "version": 1,
  "collections": [
    { "id": "service", "label": "Services", "singular_label": "Service", "path": "/services/{slug}", "fields": [
      { "name": "price", "type": "number", "min": 0 },
      { "name": "faq", "type": "list", "fields": [
        { "name": "question", "type": "text", "required": true },
        { "name": "answer", "type": "richtext" }
      ] },
      { "name": "related", "type": "reference", "to": ["service"], "multiple": true, "max": 3 }
    ] }
  ]
}
```

| Endpoint (administrators) | |
|---|---|
| `GET /wp-json/okno/v1/collections` | Schema and table state (archived columns included). |
| `POST /wp-json/okno/v1/collections/import?dry_run=1` | Plan the migration to an `okno.schema.json`; without `dry_run`, apply it. |
| `GET /wp-json/okno/v1/collections/export` | The current `okno.schema.json`. |
| `POST`, `PUT`, `DELETE /wp-json/okno/v1/collections/{id}` | Create, replace or remove one collection (data is kept). |

From PHP: `Okno_Collections::import( $json )`, `Okno_Collections::save_entry( $post_id, array( 'price' => 120 ) )`, `Okno_Collections::entry( 'home' )`. The schema builder in wp-admin comes next; the format is documented in [plugin/docs/collections-spec.md](plugin/docs/collections-spec.md).

### WordPress as a back office

On a new install, Okno also hardens WordPress for headless use (Settings → *WordPress as a back office*): no indexing of the CMS (noindex header, `robots.txt`, no sitemap), public WordPress pages redirect to the same page on the front end, XML-RPC off, `/wp/v2/users` closed to visitors. wp-admin, login, the REST API and draft previews keep working. Sites updating from an earlier version keep their behavior until you tick the options.

## Security

- Editing routes check a nonce and `edit_post` on the content. The content API only serves published items of the Okno content types; it can require a token (compared in constant time, sent in `X-Okno-Token`). Publishing requires `publish_pages` (filter `okno_deploy_capability`), settings require `manage_options`.
- The bridge is inert outside the editor iframe and checks the origin of every message, in both directions. It makes no network request.
- Values are sanitized per field type on write. `required` and `maxlength` are enforced server side. Attachments and related posts are checked for read access.
- Use a fine-grained GitHub token limited to the deployment repository (`Actions: write`, or `Contents: write` for the commit mode).

Found a vulnerability? See [SECURITY.md](SECURITY.md).

## Extending

- **Other field plugins**: implement `Okno_Adapter_Interface` and register it with the `okno_adapters` filter (Meta Box, JetEngine, Pods…).
- **Other deployment targets**: implement `Okno_Deploy_Driver_Interface` and plug it in with `okno_deploy_driver`.
- **WYSIWYG preview**: `okno_wysiwyg_render` replaces `wpautop()` with your front end's actual rendering.
- **Content API**: `okno_content_item` adds or removes data on each item (SEO, author…), `okno_content_post_types` changes the served types, `okno_content_html` the rendered HTML. An adapter implementing `Okno_Content_Adapter_Interface` feeds the content API and the types.
- **Automatic publishing**: the `okno_content_changed` action fires when served content changes.

## Repository layout

```
plugin/     WordPress plugin (PHP, GPL-2.0-or-later)
bridge/     @pixelersagency/okno-bridge — the front-end bridge (vanilla JS, zero dependencies)
examples/   Minimal integrations: Next.js, Astro, React + Vite, plain HTML
scripts/    Release packaging
```

## Development

```bash
# Plugin tests (no WordPress needed)
php plugin/tests/test-acf-adapter.php
php plugin/tests/test-content.php
php plugin/tests/test-collections.php
php plugin/tests/test-activity.php
php plugin/tests/test-frame-verdict.php
php plugin/tests/test-updater.php
php plugin/tests/smoke-load.php

# Content client tests
cd bridge && npm test

# Rebuild the bridge (also copies it into the plugin)
cd bridge && npm run build

# Build the installable zip into dist/
./scripts/build-zip.sh
```

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[GPL-2.0-or-later](LICENSE). Made by [Pixelers](https://pixelers.fr).
