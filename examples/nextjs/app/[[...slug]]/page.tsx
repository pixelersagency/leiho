import { notFound } from 'next/navigation';
import { getPage, type Section } from '../../lib/wordpress';

// Read WordPress on every request: saved changes are live immediately.
export const revalidate = 0;

export default async function Page({ params }: { params: Promise<{ slug?: string[] }> }) {
  const { slug = [] } = await params;
  const page = await getPage(slug.join('/'));
  if (!page) notFound();

  const sections = page.acf.sections || [];

  return (
    <main data-wp-post={page.id}>
      {/* Titles may contain entities: let the editor inject them as HTML. */}
      <h1 data-wp-field="_title" data-leiho-apply="html" dangerouslySetInnerHTML={{ __html: page.title.rendered }} />

      {/* Always render annotated elements, even when empty. */}
      <p data-wp-field="intro">{page.acf.intro ?? ''}</p>

      {sections.map((section, i) => (
        <SectionBlock key={i} section={section} index={i} />
      ))}
    </main>
  );
}

function SectionBlock({ section, index }: { section: Section; index: number }) {
  const path = `sections.${index}`;

  switch (section.acf_fc_layout) {
    case 'hero':
      return (
        <section data-leiho-layout={path} data-leiho-section="Hero">
          <h2 data-wp-field={`${path}.title`}>{section.title}</h2>
          <img
            data-wp-field={`${path}.image`}
            src={section.image ? section.image.url : ''}
            alt={section.image ? section.image.alt : ''}
          />
        </section>
      );
    case 'text':
      return (
        <section data-leiho-layout={path} data-leiho-section="Text">
          <div data-wp-field={`${path}.body`} dangerouslySetInnerHTML={{ __html: section.body }} />
        </section>
      );
    default:
      return null;
  }
}
