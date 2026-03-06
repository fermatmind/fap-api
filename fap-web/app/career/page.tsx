import type { Metadata } from "next";
import Link from "next/link";

import { getCareers } from "@/lib/career";

export const dynamic = "force-dynamic";

export const metadata: Metadata = {
  title: "Career Guide | FermatMind",
  description:
    "Explore career paths and discover jobs that match your personality.",
  alternates: {
    canonical: "/career",
  },
  openGraph: {
    title: "Career Guide | FermatMind",
    description:
      "Explore career paths and discover jobs that match your personality.",
    url: "/career",
    type: "website",
  },
};

export default async function CareerPage() {
  const data = await getCareers();
  const items = data.items ?? [];

  return (
    <main className="page-shell">
      <section className="career-list-page">
        <p className="eyebrow">FermatMind Career</p>
        <h1 className="page-title">Career Guides</h1>
        <p className="page-subtitle">
          Explore career paths and discover jobs that match your personality.
        </p>

        <div className="career-list">
          {items.map((career) => (
            <article key={career.id} className="career-card">
              <Link className="career-link" href={`/career/${career.slug}`}>
                {career.name}
              </Link>

              <p className="career-summary">{career.summary}</p>
            </article>
          ))}
        </div>
      </section>
    </main>
  );
}
