# leiho

The front-end half of [Leiho](https://github.com/pixelersagency/leiho), visual editing for headless WordPress. Add it to your site, annotate your markup, and editors can click and edit your pages live from wp-admin.

- **Any front end**: React, Next.js, Remix, Vite, Astro, Nuxt, SvelteKit or plain HTML.
- **Inert for visitors**: it only runs inside Leiho's editor iframe, and the loaders below don't even download it otherwise.
- **Zero dependencies**, about 10 KB.
- **No network requests, no secrets**: values arrive from wp-admin by `postMessage`, and every message's origin is checked on both sides.
- **React-safe**: survives re-renders, remounts, Strict Mode and client-side navigation.

## Install

### React

```bash
npm install leiho
```

Once, in the root layout (Next.js `app/layout.tsx`, Remix `root.tsx`, Vite `App.tsx`):

```jsx
import { LeihoBridge } from 'leiho/react';

<LeihoBridge wpOrigin="https://your-wordpress.example" />
```

The component renders nothing and imports the bridge dynamically, only inside the editor. It's a client component (`'use client'`), so it can sit in a Next.js server layout.

### Any bundler (ESM)

```js
if (window.self !== window.top) {
  const { initLeihoBridge } = await import('leiho');
  initLeihoBridge({ wpOrigin: 'https://your-wordpress.example' });
}
```

### Script tag

Copy `dist/leiho-bridge.js` to your public folder (the plugin also offers it for download in **Leiho → Démarrer**), then in `<head>`:

```html
<script>
  if (window.self !== window.top) {
    var s = document.createElement('script');
    s.src = '/leiho-bridge.js';
    s.setAttribute('data-wp-origin', 'https://your-wordpress.example');
    document.head.appendChild(s);
  }
</script>
```

The standalone file starts itself from its `data-wp-origin` attribute.

### Options

| Option | Default | |
|---|---|---|
| `wpOrigin` | required | Origin of wp-admin, e.g. `https://wp.example.com`. Messages from any other origin are ignored. |
| `cloakTimeout` | `4000` (`0` in the React component) | Hides the page until the editor sends its values, to avoid a flash of stale content. `0` disables it. |

## Annotate

In a JavaScript framework, let this package write the attributes: `field()`, `block()` and `entry()` return an object to spread on the element (JSX, Astro, Svelte; `v-bind` in Vue).

```jsx
import { field, block } from 'leiho';

<h1 {...field(page, 'hero_title')}>{page.fields.hero_title}</h1>
<section {...block(page, 'sections', i, 'Hero')}>…</section>
```

`field(page, 'intro', { html: true })` adds `data-leiho-apply="html"`; `{ refresh: true }` adds `data-leiho-refresh="save"`. They are pure functions: use them in server components and at build time. In plain HTML, write the attributes below by hand.

```html
<main data-wp-post="12">
  <h1 data-wp-field="_title">About us</h1>
  <p data-wp-field="intro">We build things.</p>
  <img data-wp-field="hero_image" src="/hero.jpg" alt="" />
  <h3 data-wp-field="cards.0.title">First card</h3>
</main>
```

| Attribute | Purpose |
|---|---|
| `data-wp-post="{id}"` | Container: WordPress post ID of the fields inside |
| `data-wp-field="{path}"` | Editable element: ACF field name, `_title` or `_thumbnail`; dotted paths (`cards.0.title`, `contact.address`) reach into repeaters, flexible content and groups |
| `data-leiho-apply="html"` | The value contains markup: inject filtered HTML instead of text |
| `data-leiho-refresh="save"` | No live preview; the editor reloads the page after saving |
| `data-leiho-section="Label"` | Names a section in the editor's Structure tree |
| `data-leiho-layout="sections.2"` | Binds a section to a flexible content row, so editors can add, move, duplicate and remove it |
| `data-leiho-managed="menu"` | Content managed elsewhere: clicking it explains where to edit it |
| `data-leiho-managed-label="…"` | Label for that region (default: "Géré dans {provider}") |
| `data-leiho-edit-url="…"` | wp-admin screen where it's edited (ignored unless it's on the wp-admin origin) |

**Always render annotated elements, even when their value is empty.** An element that isn't in the page can't be filled in live.

Header, footer and `<section>` elements are detected as sections automatically; explicit `data-leiho-section` / `data-leiho-layout` annotations take precedence.

## Allow framing

Your pages must let wp-admin display them:

```
Content-Security-Policy: frame-ancestors 'self' https://your-wordpress.example
```

and must not send `X-Frame-Options: DENY` or `SAMEORIGIN`.

## Protocol

Messages are `{ ns: 'leiho', type, … }`, sent with an explicit `targetOrigin` and checked against `event.origin`.

| Direction | Type | Meaning |
|---|---|---|
| bridge → editor | `ready` | Fields, sections and image slot ratios on the page, plus its URL. Sent again after client-side navigation. |
| bridge → editor | `fieldFocus` | An editable element was clicked |
| bridge → editor | `sectionFocus` | A section was clicked |
| bridge → editor | `managedFocus` | A managed region was clicked |
| bridge → editor | `navigate` | A link was clicked |
| bridge → editor | `cloakTimeout` | The editor didn't answer in time |
| editor → bridge | `init` | Saved values to display |
| editor → bridge | `fieldUpdate` | Live value of a field being edited |
| editor → bridge | `focusField` / `focusSection` | Scroll to and highlight an element |

## Build

`npm run build` writes `dist/leiho-bridge.js` (standalone, self-starting) from `src/index.js` and copies it into the plugin. Don't edit `dist/` by hand.

## License

GPL-2.0-or-later
