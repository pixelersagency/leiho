import { LeihoBridge } from 'leiho/react';

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body>
        {children}
        {/* Renders nothing. Downloads the bridge only inside Leiho's editor. */}
        <LeihoBridge wpOrigin={process.env.NEXT_PUBLIC_WORDPRESS_URL!} />
      </body>
    </html>
  );
}
