import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import Breadcrumb from "@/components/breadcrumb/Breadcrumb";
import { getArticle, getCareer, getPersonality } from "@/lib/content";

export const dynamic = "force-dynamic";

type ArticlePageProps = {
  params: Promise<{
    slug: string;
  }>;
};

export async function generateMetadata({
  params,
}: ArticlePageProps): Promise<Metadata> {
  const { slug } = await params;
  const article = getArticle(slug);

  if (!article) {
    return {
      title: "Article not found",
    };
  }

  return {
    title: article.title,
    description: article.excerpt,
    alternates: {
      canonical: `/articles/${article.slug}`,
    },
    openGraph: {
      title: article.title,
      description: article.excerpt,
      type: "article",
      url: `/articles/${article.slug}`,
    },
  };
}

export default async function ArticleDetailPage({ params }: ArticlePageProps) {
  const { slug } = await params;
  const article = getArticle(slug);

  if (!article) {
    notFound();
  }

  const relatedCareers = article.relatedCareerSlugs
    .map((careerSlug) => getCareer(careerSlug))
    .filter(Boolean);

  const relatedPersonalities = article.relatedPersonalityTypes
    .map((type) => getPersonality(type))
    .filter(Boolean);

  return (
    <div className="shell page-shell">
      <Breadcrumb
        items={[
          { label: "Home", href: "/" },
          { label: "Articles", href: "/articles" },
          { label: article.title },
        ]}
      />

      <section className="page-hero">
        <p className="eyebrow">Article</p>
        <h1 className="page-title">{article.title}</h1>
        <p className="lead">{article.excerpt}</p>
        <div className="content-meta">{article.publishedAt}</div>
      </section>

      <section className="detail-grid">
        <article className="detail-panel">
          <h2>Overview</h2>
          {article.body.map((paragraph) => (
            <p key={paragraph} className="detail-panel__copy">
              {paragraph}
            </p>
          ))}
        </article>

        <div className="split-grid">
          <aside className="related-panel">
            <h2>Related careers</h2>
            <ul className="link-list">
              {relatedCareers.map((career) => (
                <li key={career!.id}>
                  <Link href={`/career/${career!.slug}`}>
                    <strong>{career!.name}</strong>
                    <span>{career!.summary}</span>
                  </Link>
                </li>
              ))}
            </ul>
          </aside>

          <aside className="related-panel">
            <h2>Related personalities</h2>
            <ul className="link-list">
              {relatedPersonalities.map((personality) => (
                <li key={personality!.type}>
                  <Link href={`/personality/${personality!.slug}`}>
                    <strong>{personality!.type}</strong>
                    <span>{personality!.summary}</span>
                  </Link>
                </li>
              ))}
            </ul>
          </aside>
        </div>
      </section>
    </div>
  );
}

