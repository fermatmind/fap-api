import type { Metadata } from "next";
import Link from "next/link";

import { formatPublishedAt, getArticles } from "@/lib/articles";

export const dynamic = "force-dynamic";

export const metadata: Metadata = {
  title: "Articles",
  description: "Latest CMS articles rendered with the Next.js App Router.",
};

export default async function ArticlesPage() {
  const data = await getArticles();

  return (
    <main className="page-shell">
      <section className="articles-page">
        <p className="eyebrow">CMS</p>
        <h1 className="page-title">Articles</h1>
        <p className="page-subtitle">
          Server-rendered article pages with SEO metadata, OpenGraph, Twitter
          cards, and JSON-LD structured data.
        </p>

        <ul className="article-list">
          {data.items.map((article) => (
            <li key={article.id}>
              <Link className="article-card" href={`/articles/${article.slug}`}>
                <h2>{article.title}</h2>
                {article.excerpt ? <p>{article.excerpt}</p> : null}
                {article.published_at ? (
                  <p>{formatPublishedAt(article.published_at)}</p>
                ) : null}
              </Link>
            </li>
          ))}
        </ul>
      </section>
    </main>
  );
}
