import type { Metadata } from "next";
import { notFound } from "next/navigation";
import Breadcrumb from "@/components/breadcrumb/Breadcrumb";
import RelatedContent from "@/components/content/RelatedContent";
import { getTopic } from "@/lib/topics";

export const dynamic = "force-dynamic";

type TopicPageProps = {
  params: Promise<{
    slug: string;
  }>;
};

export async function generateMetadata({
  params,
}: TopicPageProps): Promise<Metadata> {
  const { slug } = await params;
  const data = await getTopic(slug);

  if (!data) {
    return {
      title: "Topic not found",
    };
  }

  const description =
    data.topic.seo_description ??
    data.topic.description ??
    `Explore articles, careers, and personality guides related to ${data.topic.name}.`;

  const title = data.topic.seo_title ?? `${data.topic.name} | FermatMind`;

  return {
    title: {
      absolute: title,
    },
    description,
    alternates: {
      canonical: `/topics/${data.topic.slug}`,
    },
    openGraph: {
      title,
      description,
      url: `/topics/${data.topic.slug}`,
    },
  };
}

export default async function TopicDetailPage({ params }: TopicPageProps) {
  const { slug } = await params;
  const data = await getTopic(slug);

  if (!data) {
    notFound();
  }

  return (
    <div className="shell page-shell">
      <Breadcrumb
        items={[
          { label: "Home", href: "/" },
          { label: "Topics", href: "/topics" },
          { label: data.topic.name },
        ]}
      />

      <section className="page-hero">
        <p className="eyebrow">Topic cluster</p>
        <h1 className="page-title">{data.topic.name}</h1>
        <p className="lead">
          {data.topic.description ??
            "This topic groups related articles, careers, and personality guides."}
        </p>
        <div className="content-meta">
          {data.personalities.length} personalities · {data.careers.length} careers
          {" · "}
          {data.articles.length} articles
        </div>
      </section>

      <section className="split-grid">
        <RelatedContent title="Related Personality" items={data.personalities} />
        <RelatedContent title="Related Careers" items={data.careers} />
        <RelatedContent title="Related Articles" items={data.articles} />
      </section>
    </div>
  );
}
