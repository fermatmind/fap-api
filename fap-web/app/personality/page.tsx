import type { Metadata } from "next";
import Link from "next/link";

import { getPersonalities } from "@/lib/personality";

export const dynamic = "force-dynamic";

export const metadata: Metadata = {
  title: "Personality Types | FermatMind",
  description:
    "Explore personality types and discover strengths, weaknesses, and career paths.",
  alternates: {
    canonical: "/personality",
  },
  openGraph: {
    title: "Personality Types | FermatMind",
    description:
      "Explore personality types and discover strengths, weaknesses, and career paths.",
    url: "/personality",
    type: "website",
  },
};

export default async function PersonalityPage() {
  const data = await getPersonalities();
  const items = data.items ?? [];

  return (
    <main className="page-shell">
      <section className="personality-list-page">
        <p className="eyebrow">FermatMind Personality</p>
        <h1 className="page-title">Personality Types</h1>
        <p className="page-subtitle">
          Explore personality types and discover strengths, weaknesses, and
          career paths.
        </p>

        <div className="personality-grid">
          {items.map((personality) => (
            <Link
              key={personality.type}
              href={`/personality/${personality.type}`}
              className="personality-card"
            >
              <h2>{personality.type.toUpperCase()}</h2>
              <p>{personality.summary}</p>
            </Link>
          ))}
        </div>
      </section>
    </main>
  );
}
