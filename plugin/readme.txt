=== Leiho ===
Contributors: pixelersagency
Tags: headless, acf, visual editor, nextjs, astro
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0-rc.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visual editing and content API for headless WordPress. Editors see the real front end inside wp-admin, click, edit fields live, save and publish.

== Description ==

Leiho loads your headless front end (Next.js, Astro, Remix, Vite, Nuxt, SvelteKit or plain HTML) inside wp-admin. Editors click an element, edit the field behind it (ACF or Leiho Collections), watch the page update as they type, then save and publish.

* Works with any front end through a small, dependency-free bridge script. React apps get a one-line component.
* Uses the ACF fields you already have: text, WYSIWYG, images, galleries, links, relationships, repeaters, groups and flexible content.
* Page composition: add, reorder, duplicate and remove flexible content blocks.
* Undo and redo, local drafts, conflict protection and a full change history.
* Publishing modes: live content, build hook (Vercel, Netlify, Cloudflare Pages), GitHub Actions with live status, publish commit, Coolify.
* No public endpoint and no secret on the front end.

Documentation, framework examples and the bridge package: https://github.com/pixelersagency/leiho

== Installation ==

1. Upload the plugin zip in Plugins → Add New → Upload Plugin, then activate it.
2. Install Advanced Custom Fields (free or Pro).
3. Open Leiho → Get started and follow the steps: front-end address, frame-ancestors header, bridge script, connection test.

== Frequently Asked Questions ==

= Does my site need to be built with React? =

No. The bridge is plain JavaScript and works with any HTML page. React apps can use the optional component.

= Does Leiho store content somewhere else? =

No. Content is saved where it lives: your existing ACF fields and post data, or the Leiho Collections tables for content types defined in Leiho.

= What happens to my data if I delete the plugin? =

Leiho removes its own settings, tokens, history table and internal post meta, and the Leiho Collections tables and schema (define LEIHO_KEEP_DATA in wp-config.php to keep them). Your posts and ACF fields are left untouched.

== Credits ==

Parts of the Leiho app's layout and styles (three-pane Collections view, list rows, field settings, dialogs) are derived from Stacki (https://github.com/flowtricks/stacki), Copyright (c) 2026 Timothy Ricks, MIT License. The full license text ships in licenses/stacki-MIT.txt.

The login page uses the Geist font (https://github.com/vercel/geist-font), Copyright 2024 The Geist Project Authors, SIL Open Font License 1.1. The license ships in licenses/geist-OFL.txt.

== Changelog ==

= 1.0.0-rc.5 =
* New Security tab: the "WordPress as a back office" options move there, with new hardening (file editor off, login errors that don't reveal usernames, failed attempts limited, comments off, author pages hidden).
* Login address: move the login page to an address of your choice; wp-login.php and wp-admin answer "not found" to visitors. A window helps you bookmark the new address, and the administrator receives it by e-mail.
* Leiho login page: same look as leiho.dev, a title per screen, no WordPress extras, and it wins over another login branding.
* Content written with the block editor can be edited in Leiho and saved back as blocks (paragraphs, headings, lists, quotes, images, separators), for every content type.
* Option to hide the content types edited in Leiho from the WordPress menu.
* Leiho favicon while the site has no icon of its own.
* Leiho Zero: an empty theme for a back-office WordPress, installed and activated in one click, with a way back.
* Texts name the fields plugin in use (ACF, ACF PRO, Secure Custom Fields), and the Get started prompt uses Leiho Collections when no ACF is installed.

= 1.0.0-rc.4 =
* The visual editor opens collections whose pages get their address from a field or from their own address (typical after an ACF import) instead of sending them to the form view.

= 1.0.0-rc.3 =
* The "Field holding the URL" setting reads the field from the collection when a content type is served by Leiho Collections, so new and edited entries get the right path.

= 1.0.0-rc.2 =
* Text fields keep what you typed (a "<" or leading and trailing spaces are no longer altered). Safer ACF import: a content type no longer switches to Leiho when values failed or differ (it waits in a "needs review" state), field groups attached to a single entry are detected, and values over an ACF limit are found before the import. Publishing with Coolify always rebuilds the site, and the headless robots.txt keeps media crawlable.

= 1.0.0-rc.1 =
* Languages for Leiho Collections: one entry for every language, translatable or shared fields, a language switch in the editor, translation with AI, and translation abilities for agents. Search and AI: schema.org data, descriptions and FAQ from your fields, llms.txt and Markdown for every entry. A security review of the whole plugin: protected content hidden from the content API, signed updates, safer agents, editor and bridge.

= 1.0.0-beta.13 =
* Get started becomes a framework-aware onboarding (step by step, or one prompt for your coding agent). New AI screen with an MCP connection guide, new abilities for agents (search, drafts, alt text, diagnostics and settings for administrators), and a live preview of the site's home page on Leiho's home. Fixes in tags, list filters and the "Go back to ACF" dialog.

= 1.0.0-beta.12 =
* Okno is now Leiho. Existing Okno sites are migrated automatically; front ends written for Okno keep working until 2.0. See "Coming from Okno" in the README.

= 1.0.0-beta.11 =
* AI in Leiho through WordPress's AI client: brand context, AI menu on text fields with a reviewed preview, alt text, new entry with AI, abilities for external agents. Calmer Publish dot and nested list rows.

= 1.0.0-beta.10 =
* Dates and times are picked with Leiho's own calendar. Publishing mode cards no longer overlap their radio button. Field types show an icon in "Add a field".

= 1.0.0-beta.9 =
* Import from ACF into Leiho Collections, one content type at a time, with a preview, a resumable import checked entry by entry, and a way back to ACF. Entries show WordPress's own fields (content, excerpt, slug, taxonomies, author, date, parent and order); the app is larger and uses Leiho menus, chips, checkboxes and switches; no theme flash between views.

= 1.0.0-beta.8 =
* "Check again" shows a newly published Leiho version right away. The home screen is centred.
* Content and Collections are merged into one Collections view: content types on the left, entries in the middle, the selected entry edited in place on the right (Fields tab for the schema of Leiho collections). Old Content and form links redirect there.

= 1.0.0-beta.7 =
* Leiho opens full screen and becomes the back office: its own navigation, Content, Media, Collections builder and entry form, in a dense desktop-style interface (dark by default).

= 1.0.0-beta.6 =
* Leiho Collections: define content types and fields in Leiho, without ACF, with schema export/import and safe migrations.
* Content API for headless front ends (export, items, TypeScript types), cached in the site's object cache, with ETag/304 and page cache purge.
* Automatic publishing after each change, without a server cron.
* WordPress as a back office: noindex, redirection of public pages to the front end, XML-RPC off.

= 1.0.0-beta.5 =
* Update checks are more robust and no longer send the site address to GitHub.

= 1.0.0-beta.4 =
* Updates from GitHub: new versions show up in Plugins like any other plugin, with one-click and automatic updates.

= 1.0.0-beta.3 =
* The interface is now in English, with a French translation included. It follows the WordPress site language.

= 1.0.0-beta.2 =
* New visual identity in Pixelers colours.

= 1.0.0-beta.1 =
* First public release. See CHANGELOG.md on GitHub for details.
