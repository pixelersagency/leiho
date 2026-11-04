# React + Vite

A single-page app that fetches content in the browser and navigates with React Router.

```
src/main.tsx   Router + bridge
src/Page.tsx   Fetches a page from WordPress and annotates it
vite.config.ts frame-ancestors header for the dev server
```

```bash
npm install leiho react-router-dom
```

Set `VITE_WORDPRESS_URL=https://wp.example.com` in `.env`.

**Publishing mode.** Content is fetched on each visit: choose **Live content** in Leiho's settings.

**Re-renders and navigation.** Nothing to do. When React re-renders or the router changes the URL, the bridge re-scans the page, keeps unsaved edits applied, and tells the editor which page is open.

**CORS.** The app fetches the WordPress REST API from the browser, so WordPress must allow your front-end origin. Published content is public; drafts are not fetched here.

**Production header.** `vite.config.ts` only covers `vite dev` and `vite preview`. Set the same header on your host (for example `public/_headers` on Netlify or Cloudflare Pages).
