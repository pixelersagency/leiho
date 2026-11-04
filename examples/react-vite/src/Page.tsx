import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';

const WP = import.meta.env.VITE_WORDPRESS_URL;

type WpPage = {
  id: number;
  title: { rendered: string };
  acf: { intro?: string; cards?: { title: string; text: string }[] | false };
};

export default function Page({ slug: fixed }: { slug?: string }) {
  const params = useParams();
  const slug = fixed ?? params.slug ?? 'home';
  const [page, setPage] = useState<WpPage | null>(null);

  useEffect(() => {
    let cancelled = false;
    fetch(`${WP}/wp-json/wp/v2/pages?slug=${encodeURIComponent(slug)}&acf_format=standard`)
      .then((res) => res.json())
      .then((pages: WpPage[]) => {
        if (!cancelled) setPage(pages[0] ?? null);
      });
    return () => {
      cancelled = true;
    };
  }, [slug]);

  if (!page) return <p>Loading…</p>;

  const cards = page.acf.cards || [];

  return (
    <main data-wp-post={page.id}>
      <nav>
        <Link to="/">Home</Link> · <Link to="/about">About</Link>
      </nav>

      <h1 data-wp-field="_title" data-leiho-apply="html" dangerouslySetInnerHTML={{ __html: page.title.rendered }} />

      {/* Always render annotated elements, even when empty. */}
      <p data-wp-field="intro">{page.acf.intro ?? ''}</p>

      {/* Repeater rows: dotted paths reach each cell. */}
      <ul>
        {cards.map((card, i) => (
          <li key={i}>
            <h3 data-wp-field={`cards.${i}.title`}>{card.title}</h3>
            <p data-wp-field={`cards.${i}.text`}>{card.text}</p>
          </li>
        ))}
      </ul>
    </main>
  );
}
