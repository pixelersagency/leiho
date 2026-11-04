# Changelog

All notable changes to Okno are documented here. The project follows [Semantic Versioning](https://semver.org/). The plugin and the bridge package share one version number.

## [Unreleased]

### Added

- **Content API.** `GET /okno/v1/export` serves every published item of the Okno content types in one cached response with an `ETag` (an unchanged build gets a `304`); `/okno/v1/content` filters by type, path or slug; `/okno/v1/content/{id}` serves one item. Fields are keyed by name and shaped for a front end: images and files as `{ id, url, alt, width, height, sizes }`, relations as `{ id, type, slug, title, path }`, WYSIWYG as rendered HTML, flexible content rows with `_layout`. The cache is cleared on every content, field, term or settings change.
- **TypeScript types.** `GET /okno/v1/types` generates declarations of that JSON from the ACF field groups (flexible content as a discriminated union on `_layout`, fields of conditional groups optional, select choices as literal unions).
- **Content client** in the npm package: `@pixelersagency/okno-bridge/content` loads the export with retries, keeps a snapshot in `.okno/content.json` and falls back to it when WordPress doesn't answer. CLI: `npx okno types`, `npx okno pull`.
- **Restricted access** to the content API with a token (`X-Okno-Token` or `Authorization: Bearer`).
- **Automatic publishing** after each change, once edits stop for a configurable delay (changes made in wp-admin included).
- **WordPress as a back office**: noindex for the CMS (header, `robots.txt`, no sitemap), redirection of public WordPress pages to the front end, XML-RPC off, `/wp/v2/users` closed to visitors. On by default for new installs; sites updating keep their behavior until the options are ticked.
- `Okno_Content_Adapter_Interface` for adapters that feed the content API, and the `okno_content_item`, `okno_content_post_types`, `okno_content_html` filters and `okno_content_changed` action.

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

[1.0.0-beta.5]: https://github.com/pixelersagency/okno/releases/tag/v1.0.0-beta.5
[1.0.0-beta.4]: https://github.com/pixelersagency/okno/releases/tag/v1.0.0-beta.4
[1.0.0-beta.3]: https://github.com/pixelersagency/okno/releases/tag/v1.0.0-beta.3
[1.0.0-beta.2]: https://github.com/pixelersagency/okno/releases/tag/v1.0.0-beta.2
[1.0.0-beta.1]: https://github.com/pixelersagency/okno/releases/tag/v1.0.0-beta.1
