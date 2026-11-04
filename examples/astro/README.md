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
