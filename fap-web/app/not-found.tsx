import Link from "next/link";

export default function NotFoundPage() {
  return (
    <main className="page-shell">
      <section className="empty-state">
        <p className="eyebrow">404</p>
        <h1>Article not found</h1>
        <p>
          The requested article slug did not resolve to a published CMS entry.
        </p>
        <Link className="text-link" href="/articles">
          Browse all articles
        </Link>
      </section>
    </main>
  );
}
