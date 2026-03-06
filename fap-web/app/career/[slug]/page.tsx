import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import Breadcrumb from "@/components/breadcrumb/Breadcrumb";
import { getCareer, getPersonality, getArticles } from "@/lib/content";

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

  const relatedArticles = getArticles().filter((article) =>
    article.relatedCareerSlugs.includes(career.slug),
  );
  const suggestedPersonalities = ["INTJ", "ENTJ", "INFJ"]
    .map((type) => getPersonality(type))
    .filter(Boolean);

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
        <aside className="related-panel">
          <h2>Related articles</h2>
          <ul className="link-list">
            {relatedArticles.map((article) => (
              <li key={article.id}>
                <Link href={`/articles/${article.slug}`}>
                  <strong>{article.title}</strong>
                  <span>{article.excerpt}</span>
                </Link>
              </li>
            ))}
          </ul>
        </aside>

        <aside className="related-panel">
          <h2>Relevant personalities</h2>
          <ul className="link-list">
            {suggestedPersonalities.map((personality) => (
              <li key={personality!.type}>
                <Link href={`/personality/${personality!.slug}`}>
                  <strong>{personality!.type}</strong>
                  <span>{personality!.summary}</span>
                </Link>
              </li>
            ))}
          </ul>
        </aside>
      </section>
    </div>
  );
}

