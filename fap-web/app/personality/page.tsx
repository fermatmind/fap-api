import type { Metadata } from "next";
import Link from "next/link";

const types = [
  "INTP",
  "INTJ",
  "ENTP",
  "ENTJ",
  "INFP",
  "INFJ",
  "ENFP",
  "ENFJ",
  "ISTP",
  "ISTJ",
  "ESTP",
  "ESTJ",
  "ISFP",
  "ISFJ",
  "ESFP",
  "ESFJ",
];

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

export default function PersonalityPage() {
  return (
    <main className="page-shell">
      <section className="personality-list-page">
        <p className="eyebrow">FermatMind Personality</p>
        <h1 className="page-title">Personality Types</h1>
        <p className="page-subtitle">
          Explore all 16 personality types and jump into detailed guides on
          strengths, weaknesses, relationships, and career fit.
        </p>

        <div className="personality-grid">
          {types.map((type) => (
            <Link
              key={type}
              href={`/personality/${type.toLowerCase()}`}
              className="personality-card"
            >
              <h2>{type}</h2>
              <p>
                Discover strengths, weaknesses, relationships, and career paths
                for the {type} personality type.
              </p>
            </Link>
          ))}
        </div>
      </section>
    </main>
  );
}
