import Link from "next/link";

export default function NotFound() {
  return (
    <div className="shell page-shell">
      <section className="page-hero">
        <p className="eyebrow">Not found</p>
        <h1 className="page-title">This page does not exist.</h1>
        <p className="lead">
          The route may be outdated, or the content might not have been published
          yet.
        </p>
      </section>

      <section className="content-card">
        <h2>Useful links</h2>
        <p>Return to one of the active content sections below.</p>
        <div className="content-meta">
          <Link href="/articles" className="card-link">
            Articles
          </Link>
          {" · "}
          <Link href="/career" className="card-link">
            Career
          </Link>
          {" · "}
          <Link href="/personality" className="card-link">
            Personality
          </Link>
        </div>
      </section>
    </div>
  );
}

