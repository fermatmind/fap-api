import type { Metadata } from "next";
import { notFound } from "next/navigation";
import Breadcrumb from "@/components/breadcrumb/Breadcrumb";
import RelatedContent from "@/components/content/RelatedContent";
import {
  getCareer,
  getRelatedArticlesForCareer,
  getRelatedPersonalitiesForCareer,
} from "@/lib/content";

export const dynamic = "force-dynamic";

type CareerPageProps = {
  params: Promise<{
    slug: string;
  }>;
};

export async function generateMetadata({
  params,
}: CareerPageProps): Promise<Metadata> {
  const { slug } = await params;
  const career = getCareer(slug);

  if (!career) {
    return {
      title: "Career not found",
    };
  }

  return {
    title: `${career.name} guide`,
    description: career.summary,
    alternates: {
      canonical: `/career/${career.slug}`,
    },
    openGraph: {
      title: `${career.name} guide`,
      description: career.summary,
      url: `/career/${career.slug}`,
    },
  };
}

export default async function CareerDetailPage({ params }: CareerPageProps) {
  const { slug } = await params;
  const career = getCareer(slug);

  if (!career) {
    notFound();
  }

  const relatedArticles = getRelatedArticlesForCareer(career);
  const relatedPersonalities = getRelatedPersonalitiesForCareer(career);

  return (
    <div className="shell page-shell">
      <Breadcrumb
        items={[
          { label: "Home", href: "/" },
          { label: "Career", href: "/career" },
          { label: career.name },
        ]}
      />

      <section className="page-hero">
        <p className="eyebrow">Career detail</p>
        <h1 className="page-title">{career.name}</h1>
        <p className="lead">{career.summary}</p>
      </section>

      <section className="split-grid">
        <article className="detail-panel">
          <h2>Overview</h2>
          <p className="detail-panel__copy">{career.overview}</p>
        </article>

        <article className="detail-panel">
          <h2>Skills</h2>
          <p className="detail-panel__copy">{career.skills}</p>
        </article>

        <article className="detail-panel">
          <h2>Salary range</h2>
          <p className="detail-panel__copy">{career.salaryRange}</p>
        </article>

        <article className="detail-panel">
          <h2>Future outlook</h2>
          <p className="detail-panel__copy">{career.futureOutlook}</p>
        </article>
      </section>

      <section className="split-grid">
        <RelatedContent title="Related Articles" items={relatedArticles} />
        <RelatedContent
          title="Related Personality Types"
          items={relatedPersonalities}
        />
      </section>
    </div>
  );
}
