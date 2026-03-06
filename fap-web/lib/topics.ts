const apiBase = (
  process.env.NEXT_PUBLIC_API_BASE ?? "http://127.0.0.1:18080"
).replace(/\/$/, "");

export type TopicListItem = {
  id: number;
  org_id: number;
  name: string;
  slug: string;
  description: string | null;
  seo_title: string | null;
  seo_description: string | null;
  articles_count: number;
  careers_count: number;
  personalities_count: number;
  url: string;
  created_at: string | null;
  updated_at: string | null;
};

export type TopicDetail = {
  id: number;
  org_id: number;
  name: string;
  slug: string;
  description: string | null;
  seo_title: string | null;
  seo_description: string | null;
  created_at: string | null;
  updated_at: string | null;
};

export type TopicRelatedItem = {
  title: string;
  slug: string;
  url: string;
};

export type TopicDetailResponse = {
  ok: boolean;
  topic: TopicDetail;
  articles: TopicRelatedItem[];
  careers: TopicRelatedItem[];
  personalities: TopicRelatedItem[];
};

type TopicListResponse = {
  ok: boolean;
  items?: TopicListItem[];
};

export async function getTopics(): Promise<TopicListItem[]> {
  const response = await fetch(`${apiBase}/api/v0.5/topics`, {
    cache: "no-store",
  });

  if (!response.ok) {
    throw new Error("failed to fetch topics");
  }

  const data = (await response.json()) as TopicListResponse;

  return data.items ?? [];
}

export async function getTopic(slug: string): Promise<TopicDetailResponse | null> {
  const response = await fetch(
    `${apiBase}/api/v0.5/topics/${encodeURIComponent(slug)}`,
    { cache: "no-store" },
  );

  if (response.status === 404) {
    return null;
  }

  if (!response.ok) {
    throw new Error("failed to fetch topic");
  }

  return (await response.json()) as TopicDetailResponse;
}
