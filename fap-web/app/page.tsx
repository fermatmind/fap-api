import Link from "next/link";

export default function HomePage() {
  return (
    <div className="shell page-shell">
      <section className="page-hero">
        <p className="eyebrow">Content platform</p>
        <h1 className="page-title">Structured guides for thoughtful decisions.</h1>
        <p className="lead">
          Browse personality profiles, career guides, and editorial content with a
          consistent navigation system and SEO-ready page structure.
        </p>
      </section>

      <section className="cta-strip">
        <article className="content-card">
          <h2>Personality</h2>
          <p>
            Explore the 16 types and move into a detailed guide for strengths,
            weaknesses, and career fit.
          </p>
          <div className="content-meta">
            <Link href="/personality" className="card-link">
              Open personality index
            </Link>
          </div>
        </article>

        <article className="content-card">
          <h2>Career</h2>
          <p>
            Review compact career pages with overview, key skills, salary range,
            and future outlook.
          </p>
          <div className="content-meta">
            <Link href="/career" className="card-link">
              Open career guides
            </Link>
          </div>
        </article>

        <article className="content-card">
          <h2>Articles</h2>
          <p>
            Read editorial content that connects personality patterns with work,
            learning, and team communication.
          </p>
          <div className="content-meta">
            <Link href="/articles" className="card-link">
              Open articles
            </Link>
          </div>
        </article>
      </section>
    </div>
  );
}

