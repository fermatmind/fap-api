import type { Metadata } from "next";
import Link from "next/link";
import Breadcrumb from "@/components/breadcrumb/Breadcrumb";
import { getArticles } from "@/lib/content";

export const dynamic = "force-dynamic";

export const metadata: Metadata = {
  title: "Articles",
  description:
    "Read practical articles on career strategy, deep work, and communication from FermatMind.",
  alternates: {
    canonical: "/articles",
  },
  openGraph: {
    title: "Articles",
    description:
      "Read practical articles on career strategy, deep work, and communication from FermatMind.",
    url: "/articles",
  },
};

export default function ArticlesPage() {
  const articles = getArticles();

  return (
    <div className="shell page-shell">
      <Breadcrumb
        items={[
          { label: "Home", href: "/" },
          { label: "Articles" },
        ]}
      />

      <section className="page-hero">
        <p className="eyebrow">Editorial</p>
        <h1 className="page-title">Articles with a clearer path through the site.</h1>
        <p className="lead">
          The article index now shares the same navigation, breadcrumb, footer,
          and metadata structure as the rest of the content platform.
        </p>
      </section>

      <section className="content-stack">
        {articles.map((article) => (
          <article key={article.id} className="content-card">
            <h2>{article.title}</h2>
            <p>{article.excerpt}</p>
            <div className="content-meta">
              {article.publishedAt} ·{" "}
              <Link href={`/articles/${article.slug}`} className="card-link">
                Read article
              </Link>
            </div>
          </article>
        ))}
      </section>
    </div>
  );
}

