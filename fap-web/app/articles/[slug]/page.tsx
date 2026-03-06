import type { Metadata } from "next";
import { notFound } from "next/navigation";

import {
  formatPublishedAt,
  getArticle,
  getArticleHtml,
  getSeo,
  serializeJsonLd,
} from "@/lib/articles";

export const dynamic = "force-dynamic";

type ArticlePageProps = {
  params: Promise<{
    slug: string;
  }>;
};

export async function generateMetadata(
  { params }: ArticlePageProps,
): Promise<Metadata> {
  const { slug } = await params;
  const seo = await getSeo(slug);

  if (!seo) {
    return {};
  }

  const meta = seo.meta;

  return {
    title: meta.title ?? undefined,
    description: meta.description ?? undefined,
    alternates: meta.canonical
      ? {
          canonical: meta.canonical,
        }
      : undefined,
    openGraph: {
      title: meta.og?.title ?? meta.title ?? undefined,
      description: meta.og?.description ?? meta.description ?? undefined,
      url: meta.canonical ?? undefined,
      images: meta.og?.image ? [{ url: meta.og.image }] : undefined,
      type: "article",
    },
    twitter: {
      card: "summary_large_image",
      title: meta.twitter?.title ?? meta.title ?? undefined,
      description:
        meta.twitter?.description ?? meta.description ?? undefined,
      images: meta.twitter?.image ? [meta.twitter.image] : undefined,
    },
  };
}

export default async function ArticlePage({ params }: ArticlePageProps) {
  const { slug } = await params;
  const [articleResponse, seo] = await Promise.all([getArticle(slug), getSeo(slug)]);

  if (!articleResponse) {
    notFound();
  }

  const article = articleResponse.article;
  const articleHtml = await getArticleHtml(article);

  return (
    <main className="page-shell">
      <section className="article-page">
        <header className="article-header">
          <p className="eyebrow">CMS Article</p>
          <h1 className="page-title">{article.title}</h1>
          {article.published_at ? (
            <p className="article-meta">
              {formatPublishedAt(article.published_at)}
            </p>
          ) : null}
        </header>

        <article
          className="article-body"
          dangerouslySetInnerHTML={{ __html: articleHtml }}
        />

        {seo?.jsonld ? (
          <script
            type="application/ld+json"
            dangerouslySetInnerHTML={{
              __html: serializeJsonLd(seo.jsonld),
            }}
          />
        ) : null}
      </section>
    </main>
  );
}
