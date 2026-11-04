=== Okno ===
Contributors: pixelersagency
Tags: headless, acf, visual editor, nextjs, astro
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0-beta.10
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visual editing and content API for headless WordPress. Editors see the real front end inside wp-admin, click, edit fields live, save and publish.

== Description ==

Okno loads your headless front end (Next.js, Astro, Remix, Vite, Nuxt, SvelteKit or plain HTML) inside wp-admin. Editors click an element, edit the field behind it (ACF or Okno Collections), watch the page update as they type, then save and publish.

* Works with any front end through a small, dependency-free bridge script. React apps get a one-line component.
* Uses the ACF fields you already have: text, WYSIWYG, images, galleries, links, relationships, repeaters, groups and flexible content.
* Page composition: add, reorder, duplicate and remove flexible content blocks.
* Undo and redo, local drafts, conflict protection and a full change history.
* Publishing modes: live content, build hook (Vercel, Netlify, Cloudflare Pages), GitHub Actions with live status, publish commit, Coolify.
* No public endpoint and no secret on the front end.

Documentation, framework examples and the bridge package: https://github.com/pixelersagency/okno

== Installation ==

1. Upload the plugin zip in Plugins → Add New → Upload Plugin, then activate it.
2. Install Advanced Custom Fields (free or Pro).
3. Open Okno → Get started and follow the steps: front-end address, frame-ancestors header, bridge script, connection test.

== Frequently Asked Questions ==

= Does my site need to be built with React? =

No. The bridge is plain JavaScript and works with any HTML page. React apps can use the optional component.

= Does Okno store content somewhere else? =

No. Content is saved where it lives: your existing ACF fields and post data, or the Okno Collections tables for content types defined in Okno.

= What happens to my data if I delete the plugin? =

Okno removes its own settings, tokens, history table and internal post meta, and the Okno Collections tables and schema (define OKNO_KEEP_DATA in wp-config.php to keep them). Your posts and ACF fields are left untouched.

== Credits ==

Parts of the Okno app's layout and styles (three-pane Collections view, list rows, field settings, dialogs) are derived from Stacki (https://github.com/flowtricks/stacki), Copyright (c) 2026 Timothy Ricks, MIT License. The full license text ships in licenses/stacki-MIT.txt.

== Changelog ==

= 1.0.0-beta.10 =
* Dates and times are picked with Okno's own calendar. Publishing mode cards no longer overlap their radio button. Field types show an icon in "Add a field".

= 1.0.0-beta.9 =
* Import from ACF into Okno Collections, one content type at a time, with a preview, a resumable import checked entry by entry, and a way back to ACF. Entries show WordPress's own fields (content, excerpt, slug, taxonomies, author, date, parent and order); the app is larger and uses Okno menus, chips, checkboxes and switches; no theme flash between views.

= 1.0.0-beta.8 =
* "Check again" shows a newly published Okno version right away. The home screen is centred.
* Content and Collections are merged into one Collections view: content types on the left, entries in the middle, the selected entry edited in place on the right (Fields tab for the schema of Okno collections). Old Content and form links redirect there.

= 1.0.0-beta.7 =
* Okno opens full screen and becomes the back office: its own navigation, Content, Media, Collections builder and entry form, in a dense desktop-style interface (dark by default).

= 1.0.0-beta.6 =
* Okno Collections: define content types and fields in Okno, without ACF, with schema export/import and safe migrations.
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
