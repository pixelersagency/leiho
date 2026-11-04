# Contributing to Leiho

Thanks for helping. Bug reports, framework recipes and pull requests are all welcome.

## Before you start

- **Bugs**: open an issue with your WordPress, ACF and PHP versions, your front-end framework, and what the browser console shows in both wp-admin and the preview iframe.
- **Features**: open an issue first so we can agree on the approach before you write code.
- **Security issues**: see [SECURITY.md](SECURITY.md), never a public issue.

## Project layout

| Folder | Content |
|---|---|
| `plugin/` | WordPress plugin. PHP 7.4+, no Composer dependency, no build step. |
| `plugin/assets/admin/` | Editor and admin screens: plain ES5 JavaScript and CSS, no build step. |
| `bridge/` | Front-end bridge. `src/` is the source, `dist/` and `plugin/assets/bridge/` are built by `npm run build`. |
| `examples/` | Minimal integrations per framework. |

## Working locally

```bash
# Plugin tests: they stub WordPress, so no install is needed
php plugin/tests/test-acf-adapter.php
php plugin/tests/test-collections.php
php plugin/tests/test-acf-import.php
php plugin/tests/test-activity.php
php plugin/tests/test-frame-verdict.php
php plugin/tests/test-updater.php
php plugin/tests/test-ai.php
php plugin/tests/smoke-load.php

# After changing bridge/src
cd bridge && npm run build

# Installable zip in dist/
./scripts/build-zip.sh
```

To try the editor, symlink `plugin/` into `wp-content/plugins/leiho` on a local WordPress with ACF, and run one of the `examples/` next to it.

## Translations

User-facing strings are written in English and wrapped in WordPress i18n functions (`__()`, `esc_html_e()`… in PHP, `wp.i18n.__()` in JavaScript, text domain `leiho`). The French translation lives in `plugin/languages/leiho-fr_FR.po`.

After adding or changing a string, run:

```bash
python3 scripts/i18n.py
```

It extracts every string into `leiho.pot`, updates the French `.po` (new strings appear with an empty translation), and rebuilds the `.mo` and the JSON files used by the scripts. Fill in the French in the `.po` (by hand or with Poedit), then run it again. CI fails if a string has no French translation. Requires GNU gettext (`brew install gettext`, `apt install gettext`).

To add a language, copy `leiho-fr_FR.po` to `leiho-<locale>.po`, translate it, and add the locale to `scripts/i18n.py`.

## Conventions

- WordPress coding standards for PHP (tabs, spaces inside parentheses, Yoda conditions).
- Every REST route checks a nonce and a capability. Every value written is sanitized for its field type.
- The bridge must stay dependency-free, inert outside the editor iframe, and check `event.origin` on every message.
- Code comments are written in French; commit messages, issues and pull requests may be in English or French.
- Commit the rebuilt bridge together with the change to `bridge/src`: CI fails otherwise.

## Releasing (maintainers)

1. Bump the version in `plugin/leiho.php` (header and `LEIHO_VERSION`), `plugin/readme.txt` (Stable tag) and `bridge/package.json`.
2. Add a section to `CHANGELOG.md`.
3. Tag and push: `git tag v1.2.3 && git push --tags`. The release workflow builds the zip, publishes the GitHub release, and publishes the bridge to npm through Trusted Publishing (no token needed).
