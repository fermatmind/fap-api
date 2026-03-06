import type { Metadata } from "next";
import { notFound } from "next/navigation";
import Breadcrumb from "@/components/breadcrumb/Breadcrumb";
import RelatedContent from "@/components/content/RelatedContent";
import {
  getPersonality,
  getRelatedArticlesForPersonality,
  getRelatedCareersForPersonality,
} from "@/lib/content";

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

  const relatedCareers = getRelatedCareersForPersonality(personality);
  const relatedArticles = getRelatedArticlesForPersonality(personality);

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
        <RelatedContent title="Related Careers" items={relatedCareers} />
        <RelatedContent title="Related Articles" items={relatedArticles} />
      </section>
    </div>
  );
}
