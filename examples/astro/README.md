# Astro

```
src/layouts/Base.astro     Loads the bridge (inline loader, editor only)
src/pages/[...slug].astro  Fetches pages from WordPress at build time
public/_headers            frame-ancestors header (Netlify, Cloudflare Pages)
public/leiho-bridge.js      Copy it from Leiho → Get started, or from bridge/dist/
```

Set `PUBLIC_WORDPRESS_URL=https://wp.example.com` in `.env`.

**Publishing mode.** This site is static: content changes need a rebuild. In Leiho's settings choose **Build hook** (Netlify, Cloudflare Pages, Vercel) or **GitHub Actions**. With Astro's server output (`output: 'server'`), choose **Live content** instead.

**The header.** `public/_headers` works on Netlify and Cloudflare Pages. On Vercel, use `vercel.json`; on your own server, set it in nginx or Apache.

**View transitions.** If you use `<ClientRouter />`, nothing to change: the bridge follows client-side navigation.

**Galleries.** Render a gallery field as one child per image in an annotated container, and style WordPress's Gallery block in post bodies. `leiho/gallery.css` does both (automatic grid of squares):

```astro
---
import { field } from 'leiho';
import 'leiho/gallery.css';
---
<ul class="leiho-gallery" {...field(page, 'photos', { gallery: true })}>
  {page.fields.photos.map((photo) => <li><img src={photo.sizes.large?.url ?? photo.url} alt={photo.alt} loading="lazy" /></li>)}
</ul>
<div class="article__body" {...field(post, '_content', { html: true })} set:html={post.content} />
```

**Drafts.** With a static build, a draft has no page yet: the editor shows it in the page of a published entry of the same type (bridge 6), so editors see it before publishing.
