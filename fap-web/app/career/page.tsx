import type { Metadata } from "next";
import Link from "next/link";
import Breadcrumb from "@/components/breadcrumb/Breadcrumb";
import { getCareers } from "@/lib/content";

export const dynamic = "force-dynamic";

export const metadata: Metadata = {
  title: "Career",
  description:
    "Explore practical career guides with overview, skills, salary range, and outlook.",
  alternates: {
    canonical: "/career",
  },
  openGraph: {
    title: "Career",
    description:
      "Explore practical career guides with overview, skills, salary range, and outlook.",
    url: "/career",
  },
};

export default function CareerPage() {
  const careers = getCareers();

  return (
    <div className="shell page-shell">
      <Breadcrumb
        items={[
          { label: "Home", href: "/" },
          { label: "Career" },
        ]}
      />

      <section className="page-hero">
        <p className="eyebrow">Career guides</p>
        <h1 className="page-title">Career pages now share one platform shell.</h1>
        <p className="lead">
          The listing and detail views use the same header, footer, breadcrumb,
          and metadata shape as the rest of the content routes.
        </p>
      </section>

      <section className="content-stack">
        {careers.map((career) => (
          <article key={career.id} className="content-card">
            <h2>{career.name}</h2>
            <p>{career.summary}</p>
            <div className="content-meta">
              <Link href={`/career/${career.slug}`} className="card-link">
                Open guide
              </Link>
            </div>
          </article>
        ))}
      </section>
    </div>
  );
}

