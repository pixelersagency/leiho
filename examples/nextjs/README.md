# Next.js (App Router)

```
app/layout.tsx           Loads the bridge (React component, editor only)
app/[[...slug]]/page.tsx Fetches a page from WordPress and annotates it
lib/wordpress.ts         REST helper
next.config.mjs          frame-ancestors header
```

```bash
npm install leiho
```

In `.env.local`:

```bash
WORDPRESS_URL=https://wp.example.com
NEXT_PUBLIC_WORDPRESS_URL=https://wp.example.com
```

**Publishing mode.** With `revalidate = 0` (as here) the page reads WordPress on every request: choose **Live content** in Leiho's settings, and Save is enough. For a static export, remove it and pick **Build hook** or **GitHub Actions** instead.

**Previewing drafts.** The editor opens drafts too. Anonymous REST requests only return published content, so for drafts you need an authenticated fetch (an application password stored server side). The example keeps to published pages.
