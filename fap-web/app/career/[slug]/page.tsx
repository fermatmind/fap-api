import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { getCareer, serializeJsonLd } from "@/lib/career";

export const dynamic = "force-dynamic";

type CareerDetailPageProps = {
  params: Promise<{
    slug: string;
  }>;
};

export async function generateMetadata(
  { params }: CareerDetailPageProps,
): Promise<Metadata> {
  const { slug } = await params;
  const data = await getCareer(slug);

  if (!data) {
    return {};
  }

  const career = data.career;

  return {
    title: `${career.name} Career Guide`,
    description: career.summary,
    alternates: {
      canonical: `/career/${career.slug}`,
    },
    openGraph: {
      title: `${career.name} Career Guide`,
      description: career.summary,
      url: `/career/${career.slug}`,
      type: "article",
    },
  };
}

export default async function CareerDetailPage({
  params,
}: CareerDetailPageProps) {
  const { slug } = await params;
  const data = await getCareer(slug);

  if (!data) {
    notFound();
  }

  const career = data.career;
  const jsonLd = {
    "@context": "https://schema.org",
    "@type": "Occupation",
    name: career.name,
    description: career.summary,
  };

  return (
    <main className="page-shell">
      <section className="career-detail-page">
        <header className="career-hero">
          <div>
            <p className="eyebrow">Career Guide</p>
            <h1 className="page-title">{career.name}</h1>
          </div>

          <p className="page-subtitle">{career.summary}</p>

          <div className="career-meta">
            <span>{career.salary_range}</span>
            <span>{career.slug}</span>
          </div>
        </header>

        <section className="career-sections">
          <div className="career-section">
            <h2>Overview</h2>
            <p>{career.description}</p>
          </div>

          <div className="career-section">
            <h2>Skills</h2>
            <p>{career.skills}</p>
          </div>

          <div className="career-section">
            <h2>Salary</h2>
            <p>{career.salary_range}</p>
          </div>

          <div className="career-section">
            <h2>Future Outlook</h2>
            <p>{career.future_outlook}</p>
          </div>
        </section>

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
