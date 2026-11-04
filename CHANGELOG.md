# Changelog

All notable changes to Leiho (called Okno until 1.0.0-beta.11) are documented here. The project follows [Semantic Versioning](https://semver.org/). The plugin and the bridge package share one version number.

## [1.0.0-beta.13] — 2026-11-04

### Added

- **AI screen** (navigation, administrators): the AI provider's status with its setup link and privacy note, the brand context (moved from Settings, with export and import), the **context of every content type** Leiho edits — ACF types and pages included, not only Leiho collections — the recent AI actions from the change log, and the abilities offered to external agents. It has its own form: saving it changes nothing else.
- **`field()`, `block()` and `entry()`** in the `leiho` package (also `leiho/content` and `leiho/attrs`): they return the annotation attributes as an object to spread on an element — `<h1 {...field(page, 'hero_title')}>` writes `data-wp-post` and `data-wp-field`; options `html`, `refresh`, `style`; `block(page, 'sections', i, 'Label')` for flexible content rows. Pure functions, usable in server components and at build time. The onboarding, the agent prompts and the integration guide use them.
- **Get started is now an onboarding.** Pick your framework (Next.js, Astro, Nuxt, SvelteKit, React + Vite, or anything else), then either follow six steps with code written for it — allow the editor to display the site, install the bridge, read the content, annotate, test the connection — or copy one prompt for your coding agent (Claude Code, Cursor, Codex…), with the framework's snippets and the full integration guide, for an existing or a new site. Progress is tracked as checks pass.
- **AI agents over MCP.** The AI screen explains how to connect Claude Desktop, Claude Code or Cursor through the WordPress MCP Adapter, with this site's endpoint and username filled in, and example prompts.
- **New abilities for agents**: `leiho/search-entries`, `leiho/create-draft` (always a draft, validated like the editor) and `leiho/suggest-alt-text` (when the provider reads images; saves nothing).
- **Site administration by an agent** (administrators, filter `leiho_ai_admin_capability`): `leiho/get-diagnostics` (versions, settings, secrets as set / empty / unreadable only, frame headers, GitHub preflight, recent deployments, content API and AI status, and a list of problems with their fix), `leiho/update-settings` (same validation as the Settings screen, partial updates, `dry_run`, logged), `leiho/set-secret` (write-only) and `leiho/test-connection`. No ability publishes or deploys.
- **Live preview of the site's home page** on Leiho's home, rendered at desktop width and scaled to the card; clicking it opens the home page in the visual editor. Shown once the connection test has succeeded, so the site is known to allow being displayed in wp-admin.

### Fixed

- Content client: a snapshot that can't be written (read-only server, serverless rendering) no longer makes `loadLeihoContent` fail; the content is served and a warning is logged.
- Tags field: typing a tag that is already chosen and pressing Enter no longer removes it (removing is done with its ×); the typed text is cleared.
- Entry list: the sort menu (« Dernière modification ») is no longer cut; filter menus take the width of their label and wrap in a narrow column. In French, the sort is now « Modifiés récemment ».
- Field builder: a long collection name in the header wraps on two lines (full name on hover) instead of being cut.
- « Go back to ACF » says that WordPress's own fields (title, content, excerpt, slug, categories, tags) changed after the import are not undone.

## [1.0.0-beta.12] — 2026-11-04

### Changed

- **Okno is now Leiho** (Basque for *window*). Another headless CMS already used the name Okno. Plugin `leiho/leiho.php`, repository `pixelersagency/leiho`, npm package `leiho` (was `@pixelersagency/okno-bridge`), CLI `npx leiho`, REST namespace `leiho/v1`, attributes `data-leiho-*`, hooks `leiho_*`, classes `Leiho_*`, text domain `leiho`, new logo.

### Added

- **Migration from Okno.** Activated after Okno is deactivated, Leiho takes over Okno's options (values and autoload kept), tables, post and user meta, and re-encrypts its tokens; it runs once, before the headless defaults, so an Okno site is never treated as a fresh install. While Okno is active, Leiho waits and asks to deactivate it. A notice confirms the migration; deleting Okno afterwards leaves Leiho's data alone.
- **Compatibility with Okno until 2.0**: `okno/v1` REST routes, `X-Okno-Token`, `okno_*` filters and actions (deprecated notices under `WP_DEBUG`), public `Okno_*` classes, `wp okno`, `admin.php?page=okno…`, `okno.schema.json` files; in the editor, the bridge of Okno (`ns: 'okno'`); in the bridge, `data-okno-*` attributes, `initOknoBridge` and `OknoBridge`; in the content client, `loadOknoContent`, `OKNO_URL`, `OKNO_TOKEN` and the `.okno/` snapshot.

### Fixed

- On databases that cannot rename a table (the WordPress SQLite driver, used by Playground, accepts `ALTER TABLE … RENAME TO` without doing it), Okno's tables are kept under their name and found by Leiho, instead of being reported as renamed.

## [1.0.0-beta.11] — 2026-11-04

### Added

- **AI in Okno, through WordPress's AI client** (WordPress 7.0+): Okno uses `wp_ai_client_prompt()` and whatever provider the site configured in **Settings → Connectors**. It stores no API key and chooses no provider. Without WordPress 7.0, when `wp_supports_ai` is false, or when no configured provider can generate text, the AI tools are hidden; administrators get **Set up an AI provider** (Settings → Connectors) on the Collections AI button and in the settings.
- **Brand context** (Settings → *Brand context (AI)*, administrators): brand name, activity and offer, audience, tone of voice, words to prefer, words to avoid, **forbidden claims** (one per line, matched without case or accents at the start of a word — “certifié” also flags “certifiée”, “4 h” also flags “4h” and “4 heures”; `/pattern/i` for a regular expression), mandatory facts, language and up to three sample texts. **Export (JSON)** / **Import…**. The settings say which provider state applies (ready, not configured, unavailable) and what is sent to the provider.
- **Per-collection AI context**: purpose and readers, in the collection's *General* settings (saved on its own, apart from the schema). Sent with the brand context for entries of that type.
- **AI menu on text fields** — text, textarea and rich text, ACF and Okno collections, native title, excerpt and content — in the Collections form and the visual editor's panel, nested cells of lists and blocks included: **Generate…** (from a short instruction), **Rewrite**, **Shorten**, **Expand**, **Fix spelling and grammar**, **Change the tone…**, **Translate…**, and **Suggest a search title / description** on SEO-like fields (`seo_title`, `meta_description`… detected by name or label) and the excerpt, with the recommended length (60 / 155 characters, or the field's own limit). The prompt carries the brand context, the collection context, the entry's other fields as they are in the editor (unsaved changes included), the field's label, instructions, format (plain text, single line or not, or HTML for rich text) and maximum length.
- **Preview before anything changes**: the proposal opens with a word-level diff, a character count against the field's limit, the formatted result for rich text, and a warning that highlights forbidden claims and flags a text that's too long (accepting cuts it at the last word that fits). **Accept** / **Regenerate** / **Cancel**. Accepting writes into the field like typing: undo works, the preview updates, nothing is saved until you save.
- **Alternative text with AI** on image fields (saved on the image) and in Media's details panel, when the provider can read images; hidden otherwise.
- **New entry with AI** (sparkle button of a collection's entries): a brief, then a structured JSON answer matching the type's fields (text, rich text, choices, yes / no, numbers, lists, groups, blocks with `_layout`), checked against the schema (lengths, choices, layouts, row limits) and previewed field by field with forbidden claims highlighted, then **Create the draft** through the editor's save path.
- **Abilities API** (category `okno`), for external AI agents such as the MCP adapter: `okno/get-brand-context`, `okno/list-collections` (content types and their fields), `okno/get-entry`, `okno/update-entry-fields` (drafts and pending entries only, validated like the editor, revision check, never changes the status). Registered on WordPress 6.9+, with or without an AI provider.
- REST: `GET okno/v1/ai/status`, `POST ai/field`, `POST ai/alt`, `POST ai/entry`, `POST ai/entry/create`, `GET|POST ai/brand`, `GET|POST ai/collections/{type}` — nonce, capability (`okno_ai_capability`, default `edit_posts`) and rights on the content, bounded inputs, per-user rate limit (`okno_ai_rate_limit`, 20 requests a minute), generation timeout (`okno_ai_timeout`, 60 s). Provider errors (timeout, refused key, rate limit, token limit) are turned into readable messages.
- **Change log**: each AI action is a line of its own (who, which field, which action — never the prompt or the text), shown in the entry's history and on the home page. They don't count as content changes.

### Changed

- The « changes to publish » dot on the **Publish** button is dark instead of orange, so it no longer clashes with the lime button.
- Repeater rows (lists in lists) no longer stack a grey background per level: rows are outlined, nested rows get a left rule, and fields keep the same contrast at every depth.
- `Okno_Activity::lookup()` and `Okno_Rest::post_item()` / `can_create_type()` are public; `Okno_Field_Adapter::describe_type()` describes a content type's fields without an entry.

## [1.0.0-beta.10] — 2026-11-04

### Fixed

- Escape in a menu or a calendar opened inside an Okno dialog closes only the menu, not the dialog.
- Settings → Publishing: the radio button of each publishing mode card no longer covers the card's title.

### Changed

- **Dates and times are picked with Okno's own picker** instead of the browser's (date, date and time, time fields of ACF and Okno collections, and the publish date). The field shows the value in the site's language (« 11 août 2026, 21:56 »), with a calendar icon and a clear button when the field isn't required. The calendar follows the app's theme in light and dark: weeks start on the day set in Settings → General, today is marked, the selected day is lime, days of the neighbouring months are dimmed, days outside a field's minimum / maximum are disabled; the month title opens a month grid, then a year list; **Today** / **Now** and **Clear** actions. Date and time fields get an hour and a minutes menu (24 h or 12 h after the site's time format, the field's step for minutes) and **Done**. Keyboard: arrows move by day / week, Page Up / Page Down by month (with Shift: by year), Home / End to the start / end of the week, Enter picks, Escape closes and returns to the field. `dialog` / `grid` semantics, selected day and month changes announced. The native input stays in the page, hidden, with the same value formats (`YYYY-MM-DD`, `YYYY-MM-DDTHH:MM`, `HH:MM`): nothing changes in what is saved.
- `window.OknoUI.date( input )` enhances a date, date-time or time input; `OknoUI.autoSelects()` now also upgrades the date inputs it finds, including those added later. The site's language, first day of the week and clock are passed to the scripts as `window.OknoUIConfig`.
- **Add a field**: each field type now shows an icon.

## [1.0.0-beta.9] — 2026-11-04

### Added

- **Articles are fully editable in Collections.** The entry form shows WordPress's own fields for the types that support them: **Content** (WordPress's rich text editor with paragraph / headings, bold, italic, links, lists, quote, alignment and **Add media**, saved in the classic editor's format), **Excerpt** (with a character count), **URL slug** (with a preview of the full address), **categories, tags and every other public taxonomy** (chips with search; typing a new tag offers « Create “…” », the term is created on save — a new category needs the right to manage categories), **Author** (users who can write that type; needs `edit_others_posts`), **Publish date** (a future date schedules a published entry), and **Parent** / **Order** for hierarchical types. Pages get their content too. Everything goes through Okno's save path: one `wp_update_post()`, server-side sanitization (`wp_kses_post()` without `unfiltered_html`), per-field capability checks, conflict detection and the change log. These fields stay out of the visual editor's panel.
- **Content written with the block editor is protected**: the form shows a read-only preview and **Open in the WordPress editor**; the server refuses to overwrite it (`blocks_protected`). Other fields of the entry (excerpt, taxonomies, slug…) stay editable.
- REST: `POST /okno/v1/preferences` (`theme`: `light` | `dark`). Native save paths `_content`, `_excerpt`, `_slug`, `_date`, `_author`, `_parent`, `_menu_order`, `_tax_<taxonomy>` on `POST /okno/v1/save/{id}`.
- **Import from ACF into Okno Collections**, one content type at a time (Okno → Collections, **Import into Okno** on an ACF type, administrators). A preview lists the ACF field groups attached to the type, what each field becomes in Okno and what doesn't carry over (fields without an equivalent such as `page_link` or `taxonomy`, conditional logic, default values, media restrictions, location rules other than the content type, ACF Pro repeater options), with the number of entries, fields and stored values. The import creates the collection, then copies the entries in batches with a progress bar, through the editor's validation; refused values are listed with a link to the entry without stopping the import, and every entry is checked afterwards: the JSON Okno serves is compared field by field with what ACF served. An interrupted import resumes where it stopped.
- After the import the type is **served by Okno**: `/okno/v1/export`, `/content` and `/types` give the same JSON as before, and the visual editor's dotted paths keep working. Nothing is deleted from ACF (postmeta, field groups and post types stay); the ACF field groups no longer apply to the imported type, on Okno's side and on the classic edit screen.
- **Go back to ACF** in the settings of an imported collection: ACF serves its original values again and the Okno table of the collection is deleted. Values edited in Okno after the import are not written back to ACF (the dialog says so).
- Content types declared in ACF's **Post Types** screen stay registered (same labels, slug, archive, supports…) when ACF is deactivated after their import; types registered by the theme or a plugin keep their registration, Okno only owns their fields.
- REST: `GET /okno/v1/collections/import-acf`, `GET …/import-acf/preview`, `POST …/import-acf/run`, `GET …/import-acf/status`, `POST …/import-acf/rollback`. WP-CLI: `wp okno acf-import list|preview|run|rollback`. Filters `okno_acf_import_batch` and `okno_collections_save_revision`; actions `okno_acf_import_started`, `okno_acf_imported`, `okno_acf_import_rolled_back`.
- Clone fields: a seamless clone is imported as the fields it clones, a clone shown as a group becomes a group.
- Collections schema: `external` (a content type registered outside Okno, which only owns its fields) and `source: "acf"` (field and block names keep their ACF API name, camelCase and hyphens included).

### Changed

- **The app is at a comfortable scale on large screens**: 14 px body text (13 px secondary, 13 px labels, 16–20 px section titles), 36 px fields, selects and buttons, 38 px list rows and navigation links, a 48 px top bar, larger icons and spacing, and a 720 px form column, through new size tokens (`--okno-text-*`, `--okno-control*`, `--okno-row`, `--okno-bar`, `--okno-pane-*`, `--okno-form-width`) shared by every view and the visual editor's chrome. Panes are a little narrower on 1440 px screens so the entry keeps its room; the entries filters no longer cut « Tous les statuts ».
- **Menus instead of native selects** everywhere in the app (status filters, sorting, the entry's status, choice fields of forms, settings): a button and a list with a check mark on the current item, a coloured dot per status (published, scheduled, draft, pending review, private, trash), arrows / Home / End / Enter / Escape and type-ahead, `listbox` / `option` semantics, closing on outside click, placed inside the window. The native `<select>` stays in the page, hidden, for its value and for PHP forms.
- **Multiple choices are chips** with search (ACF relationship and taxonomy fields, multiple choices of Okno collections, WordPress taxonomies).
- **Checkboxes, radios and switches drawn by Okno** in light and dark: rounded square with a lime check, circle with a lime dot, radio cards (collection nature) with a clear selected state (border, tint, dot), switches for true / false fields, proper disabled states.
- **WordPress's media library, link dialog and rich text editor match Okno** inside the app: Okno surfaces and text in light and dark, lime primary button instead of WordPress blue, accent selection ring and check in the grid, themed tabs, drop zone and attachment details; the editor's toolbar, tabs and text area follow the theme.
- A whole number stored in a decimal `number` column is served as `5`, not `5.0`, like ACF and like numbers inside lists.
- Rich text of a collection imported from ACF is rendered like ACF renders it (paragraphs, typography, shortcodes, embeds) even once ACF is deactivated.
- An Okno collection imported from ACF can't be removed with `DELETE /okno/v1/collections/{id}`: go back to ACF instead.

### Fixed

- **No more light flash when switching views in dark mode** (and no dark flash in light mode). The theme is saved per user on the server (user meta, cookie as a fallback) and printed by PHP on `<html>`, the app shell and the visual editor before the first paint; a choice made before this version in the browser is picked up and saved once. Dark stays the default.
- **No more blue frame on click.** Buttons, links, navigation items and form controls only show a focus ring with the keyboard (`:focus-visible`), in Okno's accent ink; WordPress's `:focus` box-shadows (`#2271b1`) no longer leak into the app.
- **The media library no longer looks greyed out** when opened from the visual editor or Collections (« Replace » on a featured image, image / gallery / file fields): its backdrop was stacked above the window. Same fix for the link dialog and TinyMCE's windows and menus (paragraph / heading list).
- The **Visual editor** button of an entry keeps an accessible name when its label is hidden on narrow screens.

## [1.0.0-beta.8] — 2026-11-04

### Changed

- **Content and Collections are one view: Collections.** Types on the left, whatever their engine (WordPress pages and posts, ACF or theme post types, Okno collections and single pages), grouped and with their entry counts, plus **New collection** for administrators; the entries of the selected type in the middle (search, status filter, sort by last modified, date or title, create, duplicate, publish / back to draft, trash and restore); the selected entry edited in place on the right with the editor's form (same widgets, undo / redo, local drafts, conflict protection, history), its status, **Save**, and **Visual editor** / **View on the site** for entries that have a page. Leaving an entry with unsaved changes asks first. Deep links: `view=collections&type=<post_type>&id=<post_id>`. The **Fields** tab of an Okno collection opens the schema builder (administrators); ACF types say their fields are managed in ACF. Editors without `manage_options` get the view too, without the builder.
- The former `view=content` and `view=form` addresses redirect to Collections with their filters or their entry, and every link that pointed to them (change log, the editor's page list, **Edit with Okno** on the edit screen, home shortcuts) now opens the entry in Collections.
- REST: `GET /okno/v1/posts` also sorts by `orderby=date`.

### Fixed

- **Check again** (Dashboard → Updates) now clears Okno's cached release, so a version published in the last hours shows up right away instead of after the 6-hour cache.
- The home screen of the Okno app is centred instead of sticking to the left on wide screens.

## [1.0.0-beta.7] — 2026-11-04

### Added

- **Okno opens full screen and is the back office.** One wp-admin page (`admin.php?page=okno`) hides the WordPress menu, admin bar, footer and other plugins' notices (this page only; the admin bar isn't even rendered) and shows Okno's own navigation: Home, Visual editor, Content, Collections, Media, Publishing, Settings, plus **Back to WordPress** for administrators. The navigation collapses, works with the keyboard (arrows, Escape) and becomes a drawer below 782 px. The top bar carries the site name, a **Publish** button that shows when changes aren't published yet, the light / dark switch shared with the editor, and a user menu (profile, log out).
- **Content view**: every editable entry (pages, posts, ACF post types, collections) with search, filters by type and status (trash included), create, duplicate, publish / back to draft, trash and restore, and opening in the visual editor or in a form.
- **Entry form**: the editor's field panel without the preview, for content that has no page on the site (legal pages, testimonials, settings singletons). Same widgets, undo / redo, local drafts, conflict protection and change history as the visual editor. Collections without a URL open there from everywhere (Content, the editor's page list, the edit screen, the change log).
- **Collections builder** on top of the `okno/v1/collections` API: list of collections and single pages, create / edit (id, labels, kind, URL pattern, features), field editor with drag and drop and keyboard reordering, every one of the 19 field types and their options (choices, min / max, reference targets, nested sub-fields of lists and groups, block types of blocks fields), keys generated from the name and fixed once applied, review of the migration plan (`dry_run`) before applying, validation errors attached to the field concerned, archived columns with purge (typed confirmation), removal of a collection, import and export of `okno.schema.json`.
- **Media view**: the media library as a grid, with search, type filter, upload by button or drag and drop, and a details panel (alternative text, title, file URL, delete).
- **Status from the editor**: a status menu in the editor toolbar (draft, pending review, published, private) so content created in Okno can go live without wp-admin. Users who can't publish submit for review.
- Editors land in Okno after logging in (`login_redirect`, for users without `manage_options`, unless another destination was asked for). Setting **Editors → After logging in**, on by default.
- REST: `GET /okno/v1/posts` takes `type`, `status` and `orderby=modified` and returns `modified`; `POST /okno/v1/posts/{id}/status`, `POST /okno/v1/posts/{id}/restore`, `GET /okno/v1/deploy/state` (pending changes, active and last deployment).

### Changed

- Okno has a single menu entry. The former pages (`okno-start`, `okno-settings`, `okno-deploys`, `okno-editor`) still answer and redirect to their view, with their parameters.
- Changes are marked as pending even without automatic publishing (as long as a publishing mode is set), so the **Publish** button can show them; **Publish** clears the mark as before.
- Duplicating a collection entry copies its field values.
- The editor offers the featured image only for content types that support it (a collection without the *Featured image* feature no longer shows one), or when the content already has one.

## [1.0.0-beta.6] — 2026-11-04

### Added

- **Content API.** `GET /okno/v1/export` serves every published item of the Okno content types in one cached response with an `ETag` (an unchanged build gets a `304`); `/okno/v1/content` filters by type, path or slug; `/okno/v1/content/{id}` serves one item. Fields are keyed by name and shaped for a front end: images and files as `{ id, url, alt, width, height, sizes }`, relations as `{ id, type, slug, title, path }`, WYSIWYG as rendered HTML, flexible content rows with `_layout`. The cache is cleared on every content, field, term or settings change.
- **TypeScript types.** `GET /okno/v1/types` generates declarations of that JSON from the ACF field groups (flexible content as a discriminated union on `_layout`, fields of conditional groups optional, select choices as literal unions).
- **Content client** in the npm package: `@pixelersagency/okno-bridge/content` loads the export with retries, keeps a snapshot in `.okno/content.json` and falls back to it when WordPress doesn't answer. CLI: `npx okno types`, `npx okno pull`.
- **Restricted access** to the content API with a token (`X-Okno-Token` or `Authorization: Bearer`).
- **Automatic publishing** after each change, once edits stop for a configurable delay (changes made in wp-admin included). No server cron to set up: the Okno editor publishes when the delay runs out and right away when the tab is closed or hidden (`navigator.sendBeacon`), other wp-admin screens do the same after **Update** in the classic or block editor, the wp-admin Heartbeat publishes once the delay has passed while any admin tab is open, and WP-Cron stays as the safety net. A pending marker and an atomic lock make sure one batch of changes gives one deployment, whichever trigger fires first; **Publish** takes pending changes along. `POST /okno/v1/deploy/flush` publishes pending changes now (idempotent, accepts the REST nonce as `_wpnonce` for beacons). When the build service doesn't answer, pending changes are kept and retried up to five times instead of being dropped.
- **WordPress as a back office**: noindex for the CMS (header, `robots.txt`, no sitemap), redirection of public WordPress pages to the front end, XML-RPC off, `/wp/v2/users` closed to visitors. On by default for new installs; sites updating keep their behavior until the options are ticked.
- **Content cache** that uses what the site already has: with a persistent object cache (Redis, Memcached, LiteSpeed, W3 Total Cache…), the export, items, path lookups and types are cached in the object cache and invalidated in O(1) by switching generation; without one, the export keeps its non-autoloaded option. On by default, with a toggle, a status line and a *Clear the content cache* button in the settings.
- **HTTP caching** on every content endpoint: `ETag`, `Cache-Control: public, max-age=0, must-revalidate` (`private` in restricted mode, for logged-in users and for drafts), `304` on `If-None-Match`.
- **Page cache purge** when served content changes, once per request: LiteSpeed (by tag), WP Rocket, W3 Total Cache, WP Super Cache, SiteGround, WP Fastest Cache, Cloudflare, Breeze, Nginx Helper, plus the `okno_purge_caches` action and `okno_purge_urls` filter for anything else.
- `Okno_Content_Adapter_Interface` for adapters that feed the content API, and the `okno_content_item`, `okno_content_post_types`, `okno_content_html` filters and `okno_content_changed` action.
- **Okno Collections**: content types and fields defined in Okno, without ACF. Collections (many entries) and singletons (one entry, created by Okno) are registered as post types, so roles, drafts, trash, revisions, media and SEO plugins keep working; field values live in one table per collection (`wp_okno_<id>`) with typed, indexed columns for scalar fields and JSON for lists, blocks, groups and multiple values. 19 field types: text, rich text, number, boolean, date, date-time, select, slug, email, URL, color, image, file, gallery, link, reference, list, blocks, group.
- **Schema as code**: `okno.schema.json` export and import, validated with precise error paths (`fields.3.key`).
- **Safe migrations**: a stable `key` per field makes renames free; type changes copy and convert values into a new column; removed and retyped columns are archived, never dropped (purge is explicit); `dry_run` returns the plan. Works on MySQL 5.7+, MariaDB and the SQLite drop-in.
- **Field values in revisions**: each revision of an entry stores its fields, restoring it restores them, and a field-only change creates a revision.
- Administrator REST routes `/okno/v1/collections` (list, create, replace, delete, export, import, purge) and a PHP API (`Okno_Collections::import()`, `save_entry()`, `entry()`…).
- Collections are served by `/okno/v1/export`, `/content` and `/types` and edited in the visual editor with the same dotted paths as ACF fields. ACF keeps working alongside. Saving an entry or migrating the schema clears the content cache, purges page caches and queues automatic publishing, like any other content change.
- `okno_settings` filter on the effective settings, `okno_collection_post_type_args`, `okno_collections_capability` filters and `okno_collections_saved` action.

### Changed

- The field engine (describe, read, patch dotted paths, sanitize, shape for the front end) is now shared by the ACF and Collections adapters (`Okno_Field_Adapter`).

### Fixed

- A multiple select is read as a list of choices, and edited with a multi-select in the editor, instead of the string "Array".

## [1.0.0-beta.5] — 2026-09-25

### Fixed

- Update checks no longer send the site address to GitHub (the User-Agent only carries Okno's version).
- When GitHub doesn't answer (rate limit on shared hosting, outage), Okno keeps offering the last known release instead of hiding a pending update.
- WordPress compatibility is read from the release's `readme.txt` ("Tested up to", now 7.1) instead of a hardcoded value, so updates are no longer flagged as untested.
- Quotes in release notes render as quotes in "View details" instead of showing a literal `>`.

## [1.0.0-beta.4] — 2026-09-25

### Added

- Updates from GitHub releases. New versions show up in **Plugins** like any other plugin: one-click update, automatic updates, and a "View details" window with the release notes. Checked twice a day, cached, no token needed.
- `Update URI` header: WordPress no longer checks wordpress.org for Okno, so a different plugin with the same name can never be offered as an update.

### Fixed

- The plugin description in **Plugins** is translated again on French sites.

> Sites on 1.0.0-beta.3 or earlier need to install this version by hand once; later versions arrive on their own.

## [1.0.0-beta.3] — 2026-09-25

### Changed

- The interface is now in English, with a complete French translation shipped in `plugin/languages/`. Okno follows the language of the WordPress site (or of the user's profile).
- Every string goes through WordPress i18n (`__()` in PHP, `wp.i18n` in the editor), so Okno can be translated into any language. See CONTRIBUTING.md.
- The coding-agent guide (`docs/agent-instructions.md`) is in English; French sites get `agent-instructions.fr.md`.
- Dates and numbers in the editor follow the site locale.

### Bridge

- The bridge no longer sends French default labels: managed regions send their provider and auto-detected sections send their kind (`auto: 'header' | 'footer' | 'section'`), and the editor writes the label in the editor's language. Sites on an older bridge keep their French labels.

## [1.0.0-beta.2] — 2026-09-25

### Changed

- Nouvelle identité visuelle aux couleurs de Pixelers : accent vert citron `#C5FF3E` en remplissage (texte noir posé dessus) et nouveau token `--okno-accent-ink` (`#3D6B00` en clair) pour le texte, les icônes, les liens et le focus. Logo, éditeur, pages d'administration et contour de survol du bridge mis à jour.

## [1.0.0-beta.1] — 2026-09-25

First public release.

### Plugin

- Visual editor in wp-admin: live preview in an iframe, field panel, Pages / Structure / History tabs, light and dark themes.
- ACF support for content, media, relation and container fields, nested freely, with dotted paths (`cards.0.title`) for reading and writing.
- Flexible content composition: add, move, duplicate and remove blocks from the Structure tree.
- Create, duplicate and trash content from the editor; server-side search and pagination.
- Undo / redo, keyboard shortcuts, local drafts, deep links and an "Edit with Okno" admin bar entry.
- Conflict protection: saving over a newer version is refused (HTTP 409) and the editor offers to reload.
- Server-side validation of `required` and `maxlength`, with readable error messages next to each field.
- Change history with before / after values and author.
- Crop-ratio warnings for images and character counters for limited fields.
- New admin screens: Home, Get started (header check, bridge install, connection test, coding-agent prompts), Settings and Deployments.
- Publishing modes: live content (no publish step), build hook, GitHub Actions with live run status, GitHub commit, Coolify. GitHub settings are checked on save (repository, branch, workflow, token expiry).
- Secrets encrypted at rest. Clean uninstall of every option, transient, table and internal meta.

### Bridge

- Works with any front end; React entry point `@pixelersagency/okno-bridge/react` loads the bridge only inside the editor.
- Survives re-renders and client-side navigation: event delegation, `MutationObserver` re-scan, `pushState` / `popstate` tracking, idempotent initialisation (safe under React Strict Mode).
- Text updates write text nodes only, so React keeps ownership of the DOM.
- Managed regions (`data-okno-managed`) explain where to edit content Okno doesn't own.
- Explicit sections (`data-okno-section`, `data-okno-layout`) take precedence over auto-detected ones.

[1.0.0-beta.13]: https://github.com/pixelersagency/leiho/releases/tag/v1.0.0-beta.13
[1.0.0-beta.12]: https://github.com/pixelersagency/leiho/releases/tag/v1.0.0-beta.12
[1.0.0-beta.11]: https://github.com/pixelersagency/leiho/releases/tag/v1.0.0-beta.11
[1.0.0-beta.10]: https://github.com/pixelersagency/leiho/releases/tag/v1.0.0-beta.10
[1.0.0-beta.9]: https://github.com/pixelersagency/leiho/releases/tag/v1.0.0-beta.9
[1.0.0-beta.8]: https://github.com/pixelersagency/leiho/releases/tag/v1.0.0-beta.8
[1.0.0-beta.7]: https://github.com/pixelersagency/leiho/releases/tag/v1.0.0-beta.7
[1.0.0-beta.6]: https://github.com/pixelersagency/leiho/releases/tag/v1.0.0-beta.6
[1.0.0-beta.5]: https://github.com/pixelersagency/leiho/releases/tag/v1.0.0-beta.5
[1.0.0-beta.4]: https://github.com/pixelersagency/leiho/releases/tag/v1.0.0-beta.4
[1.0.0-beta.3]: https://github.com/pixelersagency/leiho/releases/tag/v1.0.0-beta.3
[1.0.0-beta.2]: https://github.com/pixelersagency/leiho/releases/tag/v1.0.0-beta.2
[1.0.0-beta.1]: https://github.com/pixelersagency/leiho/releases/tag/v1.0.0-beta.1
