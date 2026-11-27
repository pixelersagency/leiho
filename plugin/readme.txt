=== Leiho ===
Contributors: pixelersagency
Tags: headless, acf, jetengine, visual editor, nextjs
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visual editing and content API for headless WordPress. Editors see the real front end inside wp-admin, click, edit fields live, save and publish.

== Description ==

Leiho loads your headless front end (Next.js, Astro, Remix, Vite, Nuxt, SvelteKit or plain HTML) inside wp-admin. Editors click an element, edit the field behind it (ACF, JetEngine or Leiho Collections), watch the page update as they type, then save and publish.

* Works with any front end through a small, dependency-free bridge script. React apps get a one-line component.
* Uses the ACF fields you already have: text, WYSIWYG, images, galleries, links, choices, relationships, repeaters, groups and flexible content.
* Edits JetEngine meta boxes too, every field type included (maps, colors, icons, repeaters), saved in JetEngine's own formats.
* Page composition: add, reorder, duplicate and remove flexible content blocks.
* Undo and redo, local drafts, conflict protection and a full change history.
* Roles: choose what editors, authors and other roles see and do, with a simplified mode that hides everything technical.
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

= 1.0.2 =
* Forms: your front end's forms post to Leiho's REST API. Choose the required fields, the e-mail to your team and an optional confirmation to the visitor. Spam protection built in, Cloudflare Turnstile optional.
* Messages received are kept in WordPress, with a simple inbox to read, answer and delete them.
* E-mail sending: SMTP for all WordPress e-mail, a log with counts and a chart, a test e-mail, and failure alerts in wp-admin, Slack or Telegram.
* Roles: choose who sees form messages, who deletes them, who sets up forms and who reads the e-mail log.
* Scheduled posts go live on the site in the same cron run.

= 1.0.1 =
* Reorder related items: drag a chip in a relationship field (mouse or finger), or press Alt + arrow keys. The order is saved as is.
* ACF Post Object fields with multiple values kept only the first post when saved. They are now edited like a relationship.

= 1.0.0 =
* First stable release.
* JetEngine fields: every field type of JetEngine meta boxes is editable in the visual editor and in Collections, saved in JetEngine's own formats.
* Roles: choose what each WordPress role sees and does in Leiho (screens, content types, putting the site online, AI tools), with a simplified mode that hides everything technical.
* ACF checkboxes, radio buttons, button groups and multiple selects are editable.
* The Publish button follows the role's permission (filter leiho_deploy_capability now defaults to edit_posts).
* Authors see their own entries in Collections, with the right count.
* ACF / SCF tabs, messages and accordions no longer show as fields that can't be edited.
* Collections: the status and language menus no longer run over the save state in a narrow top bar.

= 1.0.0-rc.10 =
* Right-to-left languages: the interface is mirrored, and text fields follow the direction of their content.
* The character counter no longer covers the text of panel fields.

= 1.0.0-rc.9 =
* "Add a gallery" next to "Add media" in the content editor, and "Delete the gallery" in the gallery window.

= 1.0.0-rc.8 =
* Login page: autofilled fields stay dark, and the "Remember me" box is aligned.

= 1.0.0-rc.7 =
* Photo galleries without layout settings: add several photos at once, drag to reorder, remove. In the post body (a Photos button, saved as a WordPress Gallery block) and in gallery fields, with a live preview.
* Draft preview, even on a static site: an entry that isn't online yet is shown in the page of a published entry of the same type.
* The post body previews as the site will show it (blocks, galleries, embeds).
* Saving the settings no longer drops internal content types.
* The visual editor asks the site again when the first connection message is missed.

= 1.0.0-rc.6 =
* The visual editor keeps the front end's fallback images when an image field is empty; the image is only removed when you clear the field.

= 1.0.0-rc.5 =
* New Security tab: the "WordPress as a back office" options move there, with new hardening (file editor off, login errors that don't reveal usernames, failed attempts limited, comments off, author pages hidden).
* Login address: move the login page to an address of your choice; wp-login.php and wp-admin answer "not found" to visitors. A window helps you bookmark the new address, and the administrator receives it by e-mail.
* Leiho login page: same look as leiho.dev, a title per screen, no WordPress extras, and it wins over another login branding.
* Content written with the block editor can be edited in Leiho and saved back as blocks (paragraphs, headings, lists, quotes, images, separators), for every content type. Other blocks stay locked in the text and come back untouched.
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
