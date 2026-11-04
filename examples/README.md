# Examples

Minimal integrations of Leiho, one per kind of front end. Each shows the three things a site needs:

1. **Frame permission**: a `Content-Security-Policy: frame-ancestors` header that lets wp-admin display the site.
2. **The bridge**: loaded only inside the editor, never for visitors.
3. **Annotations**: `data-wp-post` on a container, `data-wp-field` on each editable element.

| Folder | Stack | Rendering |
|---|---|---|
| [`nextjs/`](nextjs/) | Next.js App Router | Server components, live content or ISR |
| [`astro/`](astro/) | Astro | Static build, publish = rebuild |
| [`react-vite/`](react-vite/) | React + Vite + React Router | Client-side fetch, SPA navigation |
| [`html/`](html/) | Any server-rendered HTML (PHP, Rails, Django, Eleventy…) | Whatever you use |

They read content from the WordPress REST API, with ACF fields exposed. In ACF, open each field group and turn on **Show in REST API**. Values then appear under the `acf` key of `/wp-json/wp/v2/pages`.

Replace `https://wp.example.com` with your WordPress address everywhere.

## Page composition

To let editors add, move and remove blocks, use an ACF flexible content field (here `sections`) and mark each rendered block with its row path:

```html
<section data-leiho-layout="sections.0" data-leiho-section="Hero">
  <h2 data-wp-field="sections.0.title">…</h2>
</section>
```

The Next.js example shows this with a component per layout.
