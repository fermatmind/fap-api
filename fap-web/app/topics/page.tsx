import type { Metadata } from "next";
import Link from "next/link";
import Breadcrumb from "@/components/breadcrumb/Breadcrumb";
import { getTopics } from "@/lib/topics";

export const dynamic = "force-dynamic";

export const metadata: Metadata = {
  title: "Topics",
  description:
    "Browse topic clusters that connect articles, careers, and personality guides across FermatMind.",
  alternates: {
    canonical: "/topics",
  },
  openGraph: {
    title: "Topics",
    description:
      "Browse topic clusters that connect articles, careers, and personality guides across FermatMind.",
    url: "/topics",
  },
};

export default async function TopicsPage() {
  const topics = await getTopics();

  return (
    <div className="shell page-shell">
      <Breadcrumb
        items={[
          { label: "Home", href: "/" },
          { label: "Topics" },
        ]}
      />

      <section className="page-hero">
        <p className="eyebrow">Topic engine</p>
        <h1 className="page-title">SEO topic clusters for the content platform.</h1>
        <p className="lead">
          Each topic connects editorial articles, career guides, and personality
          content into one navigable hub for stronger internal linking.
        </p>
      </section>

      <section className="content-stack">
        {topics.map((topic) => (
          <article key={topic.id} className="content-card">
            <h2>{topic.name}</h2>
            <p>{topic.description ?? "No topic description yet."}</p>
            <div className="content-meta">
              {topic.articles_count} articles · {topic.careers_count} careers ·{" "}
              {topic.personalities_count} personalities
            </div>
            <div className="content-meta">
              <Link href={`/topics/${topic.slug}`} className="card-link">
                Open topic
              </Link>
            </div>
          </article>
        ))}
      </section>
    </div>
  );
}
