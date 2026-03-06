import type { Metadata } from "next";
import Link from "next/link";

import {
  formatPublishedAt,
  getArticles,
  normalizePage,
} from "@/lib/articles";

export const dynamic = "force-dynamic";

export const metadata: Metadata = {
  title: "Articles | FermatMind",
  description:
    "Explore psychology, personality, and career insights from FermatMind.",
  alternates: {
    canonical: "/articles",
  },
};

type ArticlesPageProps = {
  searchParams?: Promise<{
    page?: string | string[];
  }>;
};

export default async function ArticlesPage({ searchParams }: ArticlesPageProps) {
  const resolvedSearchParams = await searchParams;
  const page = normalizePage(resolvedSearchParams?.page);
  const data = await getArticles(page);
  const items = data.items ?? [];
  const currentPage = Math.max(1, data.pagination?.current_page ?? page);
  const lastPage = Math.max(currentPage, data.pagination?.last_page ?? currentPage);

  return (
    <main className="page-shell">
      <section className="articles-page">
        <p className="eyebrow">FermatMind CMS</p>
        <h1 className="page-title">Articles</h1>
        <p className="page-subtitle">
          Explore psychology, personality, and career insights from FermatMind.
        </p>

        <div className="article-list">
          {items.map((article) => (
            <article key={article.id} className="article-card">
              <Link className="article-link" href={`/articles/${article.slug}`}>
                {article.title}
              </Link>

              {article.excerpt ? (
                <p className="article-excerpt">{article.excerpt}</p>
              ) : null}

              {article.published_at ? (
                <div className="article-date">
                  {formatPublishedAt(article.published_at)}
                </div>
              ) : null}
            </article>
          ))}
        </div>

        <div className="pagination">
          {currentPage > 1 ? (
            <Link
              className="pagination-link"
              href={`/articles?page=${currentPage - 1}`}
            >
              Previous
            </Link>
          ) : null}

          <div className="pagination-current">
            Page {currentPage} of {lastPage}
          </div>

          {currentPage < lastPage ? (
            <Link
              className="pagination-link"
              href={`/articles?page=${currentPage + 1}`}
            >
              Next
            </Link>
          ) : null}
        </div>
      </section>
    </main>
  );
}
