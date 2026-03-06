import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import {
  getPersonality,
  getRelatedArticles,
  getRelatedCareers,
  serializeJsonLd,
} from "@/lib/personality";

export const dynamic = "force-dynamic";

type PersonalityDetailPageProps = {
  params: Promise<{
    type: string;
  }>;
};

export async function generateMetadata(
  { params }: PersonalityDetailPageProps,
): Promise<Metadata> {
  const { type } = await params;
  const normalizedType = type.toUpperCase();
  const data = await getPersonality(type);
  const summary =
    data?.personality.summary ??
    `Discover strengths, weaknesses, and career paths for the ${normalizedType} personality type.`;

  return {
    title: `${normalizedType} Personality Guide`,
    description: summary,
    alternates: {
      canonical: `/personality/${type.toLowerCase()}`,
    },
    openGraph: {
      title: `${normalizedType} Personality Guide`,
      description: summary,
      url: `/personality/${type.toLowerCase()}`,
      type: "article",
    },
  };
}

export default async function PersonalityDetailPage({
  params,
}: PersonalityDetailPageProps) {
  const { type } = await params;
  const data = await getPersonality(type);

  if (!data) {
    notFound();
  }

  const personality = data.personality;
  const normalizedType = personality.type.toUpperCase();
  const relatedCareers = getRelatedCareers(personality);
  const relatedArticles = getRelatedArticles(personality);
  const jsonLd = {
    "@context": "https://schema.org",
    "@type": "Person",
    name: `${normalizedType} personality`,
    description: personality.summary,
  };

  return (
    <main className="page-shell">
      <section className="personality-detail-page">
        <header className="personality-hero">
          <div>
            <p className="eyebrow">Personality Guide</p>
            <h1 className="page-title">{normalizedType} Personality Guide</h1>
          </div>

          <p className="page-subtitle">{personality.summary}</p>

          <span className="personality-chip">Type: {normalizedType}</span>
        </header>

        <section className="detail-grid">
          <div className="detail-block">
            <h2>Overview</h2>
            <p>{personality.description}</p>
          </div>

          <div className="detail-block">
            <h2>Strengths</h2>
            <p>{personality.strengths}</p>
          </div>

          <div className="detail-block">
            <h2>Weaknesses</h2>
            <p>{personality.weaknesses}</p>
          </div>

          <div className="detail-block">
            <h2>Career match</h2>
            <p>{personality.career_advice}</p>
          </div>

          <div className="detail-block">
            <h2>Relationships</h2>
            <p>{personality.relationships ?? "Relationship guidance is coming soon."}</p>
          </div>
        </section>

        {relatedCareers.length > 0 ? (
          <section className="related-section">
            <h2>Related Careers</h2>
            <div className="related-grid">
              {relatedCareers.map((career) => (
                <Link
                  key={career.slug}
                  href={`/career/${career.slug}`}
                  className="related-card"
                >
                  <h3>{career.name}</h3>
                  {career.summary ? <p>{career.summary}</p> : null}
                </Link>
              ))}
            </div>
          </section>
        ) : null}

        {relatedArticles.length > 0 ? (
          <section className="related-section">
            <h2>Related Articles</h2>
            <div className="related-grid">
              {relatedArticles.map((article) => (
                <Link
                  key={article.slug}
                  href={`/articles/${article.slug}`}
                  className="related-card"
                >
                  <h3>{article.title}</h3>
                  {article.excerpt ? <p>{article.excerpt}</p> : null}
                </Link>
              ))}
            </div>
          </section>
        ) : null}

        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{
            __html: serializeJsonLd(jsonLd),
          }}
        />
      </section>
    </main>
  );
}
