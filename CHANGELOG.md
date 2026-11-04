# Changelog

All notable changes to Okno are documented here. The project follows [Semantic Versioning](https://semver.org/). The plugin and the bridge package share one version number.

## [Unreleased]

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

[1.0.0-beta.6]: https://github.com/pixelersagency/okno/releases/tag/v1.0.0-beta.6
[1.0.0-beta.5]: https://github.com/pixelersagency/okno/releases/tag/v1.0.0-beta.5
[1.0.0-beta.4]: https://github.com/pixelersagency/okno/releases/tag/v1.0.0-beta.4
[1.0.0-beta.3]: https://github.com/pixelersagency/okno/releases/tag/v1.0.0-beta.3
[1.0.0-beta.2]: https://github.com/pixelersagency/okno/releases/tag/v1.0.0-beta.2
[1.0.0-beta.1]: https://github.com/pixelersagency/okno/releases/tag/v1.0.0-beta.1
