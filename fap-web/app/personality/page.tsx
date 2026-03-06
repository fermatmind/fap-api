import type { Metadata } from "next";
import Link from "next/link";
import Breadcrumb from "@/components/breadcrumb/Breadcrumb";
import { getPersonalities } from "@/lib/content";

export const dynamic = "force-dynamic";

export const metadata: Metadata = {
  title: "Personality",
  description:
    "Browse the 16 personality types with a consistent route structure and linked detail guides.",
  alternates: {
    canonical: "/personality",
  },
  openGraph: {
    title: "Personality",
    description:
      "Browse the 16 personality types with a consistent route structure and linked detail guides.",
    url: "/personality",
  },
};

export default function PersonalityPage() {
  const personalities = getPersonalities();

  return (
    <div className="shell page-shell">
      <Breadcrumb
        items={[
          { label: "Home", href: "/" },
          { label: "Personality" },
        ]}
      />

      <section className="page-hero">
        <p className="eyebrow">Personality library</p>
        <h1 className="page-title">A navigable index for all 16 personality types.</h1>
        <p className="lead">
          Each profile now lives inside the same global shell, so readers can
          move cleanly between personality, career, and article content.
        </p>
      </section>

      <section className="card-grid">
        {personalities.map((personality) => (
          <article key={personality.type} className="content-card">
            <h2>{personality.type}</h2>
            <p>{personality.summary}</p>
            <div className="content-meta">
              <Link
                href={`/personality/${personality.slug}`}
                className="card-link"
              >
                Open profile
              </Link>
            </div>
          </article>
        ))}
      </section>
    </div>
  );
}

