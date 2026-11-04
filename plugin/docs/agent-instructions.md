# Okno — instructions for the agent building or integrating the site

## Your role

You are building, or adapting, the front end of a site whose content lives in WordPress
(ACF fields and native fields). The front end can use any technology:
React, Next.js, Remix, Vite, Astro, Nuxt, SvelteKit, or generated HTML.

Okno gives the client visual editing of this front end from wp-admin: they see their real
site in an iframe, click a text or an image, change the value, see the result instantly,
then save. For this to work, the front end must follow the contract below **from the very
first component**.

Division of responsibilities:

- **WordPress** owns the content: pages, ACF fields, media, menus.
- **The front end** owns layout, styling and behavior, and displays the content it reads
  from WordPress (REST API, WPGraphQL, or export at build time).
- **Okno** connects the two: it reads the annotations placed by the front end, and writes
  the client’s changes to the existing WordPress fields. It stores no content of its own.

## Non-negotiable rules

1. Every value a visitor can see has **exactly one owner**: a WordPress field.
   No hardcoded text in components, except interface text (accessibility labels,
   decorative icons).
2. Every element that displays a field carries `data-wp-field` with the **exact path** of
   the field, inside a `data-wp-post` container that carries the content ID.
3. **Never make the rendering of an annotated element conditional on its value being present.**
   `{title && <h2>…</h2>}` is forbidden: an element missing from the HTML cannot be filled
   by the preview, and the client can never enter that field again. Render the element
   empty, and hide it with CSS if needed (`:empty`).
4. Field names are **stable**. Renaming an ACF field disconnects the content already
   entered. New content = new field.
5. Layout is never controlled from a field: no classes, sizes, colors or structural HTML
   stored in ACF.
6. The site must work **without Okno**: the bridge only loads inside the editor, and the
   annotations are plain `data-*` attributes that visitors ignore.

## Install the bridge

The bridge is only active inside the editor iframe. **Never** load it for visitors: it is
of no use to them.

HTML, Astro, Nuxt, SvelteKit, any server-rendered site — in the `<head>` of the layout:

```html
<script>
  if (window.self !== window.top) {
    var s = document.createElement('script');
    s.src = '/okno-bridge.js';
    s.setAttribute('data-wp-origin', 'https://ADRESSE-DU-WORDPRESS');
    document.head.appendChild(s);
  }
</script>
```

Copy `okno-bridge.js` (shipped in the `@pixelersagency/okno-bridge` package, `dist/` folder)
to the site’s public folder.

React, Next.js, Remix, Vite — once, in the root layout:

```jsx
import { OknoBridge } from '@pixelersagency/okno-bridge/react';

export default function RootLayout({ children }) {
  return (
    <>
      <OknoBridge wpOrigin="https://ADRESSE-DU-WORDPRESS" />
      {children}
    </>
  );
}
```

Then allow wp-admin to display the site in an iframe, with an HTTP header sent by the front end:

```
Content-Security-Policy: frame-ancestors 'self' https://ADRESSE-DU-WORDPRESS
```

Do not send `X-Frame-Options: DENY` or `SAMEORIGIN`: it would block the editor.

## Annotate the content

```jsx
<section data-wp-post={page.id} data-okno-section="Hero">
  <h1 data-wp-field="hero_title">{page.acf.hero_title}</h1>
  <div data-wp-field="hero_intro" data-okno-apply="html"
       dangerouslySetInnerHTML={{ __html: page.acf.hero_intro }} />
  <img data-wp-field="hero_image" src={page.acf.hero_image.url} alt={page.acf.hero_image.alt} />
</section>
```

| Attribute | Purpose |
|---|---|
| `data-wp-post="12"` | Container: ID of the WordPress content that the fields inside it belong to |
| `data-wp-field="path"` | Element that displays this field |
| `data-okno-apply="html"` | The value contains markup (an accent `<span>` in a heading, a WYSIWYG field) |
| `data-okno-section="Label"` | Name of the section in the editor’s structure tree |
| `data-okno-layout="sections.2"` | The section is row 2 of the `sections` flexible content: the client can add, move, duplicate and delete it |
| `data-okno-refresh="save"` | No instant preview: the editor reloads the page after saving (complex rendering) |
| `data-okno-managed="menu"` | Content managed elsewhere (menu, post list, WooCommerce product) |
| `data-okno-managed-label="…"` | Text shown to the client for this content managed elsewhere |
| `data-okno-edit-url="…"` | wp-admin screen where it is edited (must be on the wp-admin address) |

### Paths

- Simple field: `hero_title`
- Row of a repeater or a flexible content: `cards.0.title`, `sections.2.intro`
- Sub-field of a group: `contact.phone`
- Native fields: `_title` (content title), `_thumbnail` (featured image)

The path of a list item uses the **render index**: in a `map`, build it from the loop
index, not from an identifier.

```jsx
{page.acf.cards.map((card, i) => (
  <article key={i}>
    <h3 data-wp-field={`cards.${i}.title`}>{card.title}</h3>
  </article>
))}
```

### One element = one value

The bridge writes the value into the annotated element. Put `data-wp-field` on the element
that **directly** contains the value, not on a parent that also contains layout. If a
heading needs a highlighted word, do not split the heading into two fields: store the
markup in the field and annotate with `data-okno-apply="html"`.

### Images

Every content image comes from the WordPress media library, through an ACF image field or
the featured image. Every image slot has an **aspect ratio fixed by the layout**:

```css
.hero__media { aspect-ratio: 3 / 2; overflow: hidden; }
.hero__media img { width: 100%; height: 100%; object-fit: cover; }
```

Replacing an image with another of a different aspect ratio must never change the size of
the component. The editor warns the client when the chosen image will be heavily cropped.

### Page composition

A page the client must be able to compose (add an FAQ, move a block) is an ACF
**flexible content** field. Each layout of the flexible content maps to one front-end
component. Annotate each rendered block with `data-okno-layout="field_name.index"` and
`data-okno-section="Readable label"`.

### Content managed elsewhere

WordPress menus, post lists, products, computed data: never copy them into a field.
Render them normally and wrap the smallest useful block in a managed region:

```html
<nav data-okno-managed="menu" data-okno-edit-url="https://ADRESSE-DU-WORDPRESS/wp-admin/nav-menus.php">…</nav>
```

## Model the content in ACF

- One field group per page template, attached with a location rule.
- Descriptive `snake_case` field names (`hero_title`, not `field_1`).
- Give a **character limit** to short texts that break the layout when they overflow
  (headings, buttons): Okno enforces it in the editor.
- Mark as **required** whatever cannot be empty: Okno enforces it on save.
- A link with a label = a `link` field (URL, text, new tab), not two text fields.

## Load the content

Read the content from Okno's content API, not from the WordPress REST API and not from
JSON files in the repository: WordPress is the only source of truth.

```js
import { loadOknoContent, indexOknoContent } from '@pixelersagency/okno-bridge/content';

const { data } = await loadOknoContent( { url: process.env.OKNO_URL } );
const content = indexOknoContent( data );
const page = content.byPath( Astro.url.pathname );
```

- One request per build (`/wp-json/okno/v1/export`). Load it once and share it between
  pages (module-level cache), never once per page.
- Fields are keyed by ACF field name, already shaped: `fields.hero.image.url`,
  `fields.sections[0]._layout`. Never call ACF or `/wp/v2` for them.
- Generate the types with `npx okno types --url $OKNO_URL` and type your components with
  them. Regenerate after changing a field group.
- Commit `src/okno.d.ts`, ignore `.okno/` (the snapshot is a build cache).
- Restricted API: the token goes in the build environment as `OKNO_TOKEN`, never in the code.

## Publishing

- Site that reads WordPress **on every visit** (on-demand server rendering, SPA that
  queries the API): choose the “Live content” mode in Okno’s settings. Saving is enough.
- Site **generated at build time** (static Astro, Next export): set up deployment in the
  settings (build hook, GitHub Actions, Coolify, commit). The client clicks “Publish”.

## Checks before delivery

- [ ] The bridge only loads inside the iframe (check the Network tab in normal browsing).
- [ ] The `frame-ancestors` header allows the wp-admin address.
- [ ] Okno → Get started → “Test the connection” is green.
- [ ] Every visible text, image and link belongs to an annotated field, or to a managed region.
- [ ] No annotated element is rendered conditionally on its value.
- [ ] In the editor, no field of the page shows “not on this page” without a reason.
- [ ] Lists use the loop index in their paths.
- [ ] Replace each image with a square image, then a panoramic one: the layout does not move.
- [ ] On a page with flexible content, add / move / delete a block from the structure
      tree, then save: the preview reflects the new structure.
- [ ] Clicking an internal link in the preview switches the editor to the right page.
- [ ] The public site works with the Okno plugin deactivated.
- [ ] The build makes a single content request (`/okno/v1/export`) and survives WordPress
      being down (snapshot in `.okno/`).

## Never do this

- Load the bridge for visitors.
- Hardcode content text in a component.
- Render an annotated element only if its value exists.
- Rename an ACF field that already holds content.
- Split a value into several fields to work around formatting.
- Store layout (classes, sizes, colors, structural HTML) in a field.
- Copy into ACF content that is already managed elsewhere (menu, product, post).
- Let an image’s natural size decide the size of a component.
