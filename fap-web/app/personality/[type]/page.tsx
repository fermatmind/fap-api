import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import Breadcrumb from "@/components/breadcrumb/Breadcrumb";
import { getArticle, getCareer, getPersonality } from "@/lib/content";

export const dynamic = "force-dynamic";

type PersonalityPageProps = {
  params: Promise<{
    type: string;
  }>;
};

export async function generateMetadata({
  params,
}: PersonalityPageProps): Promise<Metadata> {
  const { type } = await params;
  const personality = getPersonality(type);

  if (!personality) {
    return {
      title: "Personality not found",
    };
  }

  const description = `Discover strengths, weaknesses, and career paths for the ${personality.type} personality type.`;

  return {
    title: `${personality.type} Personality Guide`,
    description,
    alternates: {
      canonical: `/personality/${personality.slug}`,
    },
    openGraph: {
      title: `${personality.type} Personality Guide`,
      description,
      type: "profile",
      url: `/personality/${personality.slug}`,
    },
  };
}

export default async function PersonalityDetailPage({
  params,
}: PersonalityPageProps) {
  const { type } = await params;
  const personality = getPersonality(type);

  if (!personality) {
    notFound();
  }

  const relatedCareers = personality.relatedCareerSlugs
    .map((slug) => getCareer(slug))
    .filter(Boolean);
  const relatedArticles = personality.relatedArticleSlugs
    .map((slug) => getArticle(slug))
    .filter(Boolean);

  return (
    <div className="shell page-shell">
      <Breadcrumb
        items={[
          { label: "Home", href: "/" },
          { label: "Personality", href: "/personality" },
          { label: personality.type },
        ]}
      />

      <section className="page-hero">
        <p className="eyebrow">Personality detail</p>
        <h1 className="page-title">{personality.type} Personality Guide</h1>
        <p className="lead">{personality.summary}</p>
      </section>

      <section className="split-grid">
        <article className="detail-panel">
          <h2>Overview</h2>
          <p className="detail-panel__copy">{personality.overview}</p>
        </article>

        <article className="detail-panel">
          <h2>Strengths</h2>
          <p className="detail-panel__copy">{personality.strengths}</p>
        </article>

        <article className="detail-panel">
          <h2>Weaknesses</h2>
          <p className="detail-panel__copy">{personality.weaknesses}</p>
        </article>

        <article className="detail-panel">
          <h2>Career match</h2>
          <p className="detail-panel__copy">{personality.careerMatch}</p>
        </article>

        <article className="detail-panel">
          <h2>Relationships</h2>
          <p className="detail-panel__copy">{personality.relationships}</p>
        </article>
      </section>

      <section className="split-grid">
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
          <h2>Related articles</h2>
          <ul className="link-list">
            {relatedArticles.map((article) => (
              <li key={article!.id}>
                <Link href={`/articles/${article!.slug}`}>
                  <strong>{article!.title}</strong>
                  <span>{article!.excerpt}</span>
                </Link>
              </li>
            ))}
          </ul>
        </aside>
      </section>
    </div>
  );
}

