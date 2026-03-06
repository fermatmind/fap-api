import type { Metadata } from "next";
import { notFound } from "next/navigation";
import Breadcrumb from "@/components/breadcrumb/Breadcrumb";
import RelatedContent from "@/components/content/RelatedContent";
import {
  getArticle,
  getRelatedArticlesForArticle,
  getRelatedCareersForArticle,
  getRelatedPersonalitiesForArticle,
} from "@/lib/content";

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

  const relatedArticles = getRelatedArticlesForArticle(article);
  const relatedCareers = getRelatedCareersForArticle(article);
  const relatedPersonalities = getRelatedPersonalitiesForArticle(article);

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
          <RelatedContent title="Related Articles" items={relatedArticles} />
          <RelatedContent title="Related Careers" items={relatedCareers} />
          <RelatedContent
            title="Related Personality Types"
            items={relatedPersonalities}
          />
        </div>
      </section>
    </div>
  );
}
