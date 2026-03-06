export type ArticleListItem = {
  id: number | string;
  slug: string;
  title: string;
  excerpt?: string | null;
  published_at?: string | null;
};

export type ArticlesPagination = {
  current_page: number;
  last_page: number;
};

export type ArticlesResponse = {
  items?: ArticleListItem[];
  pagination?: Partial<ArticlesPagination> | null;
};

function getApiBase() {
  const apiBase = process.env.NEXT_PUBLIC_API_BASE?.replace(/\/$/, "");

  if (!apiBase) {
    throw new Error("NEXT_PUBLIC_API_BASE is not configured");
  }

  return apiBase;
}

export async function getArticles(page = 1): Promise<ArticlesResponse> {
  const response = await fetch(
    `${getApiBase()}/api/v0.5/articles?page=${page}`,
    { cache: "no-store" },
  );

  if (!response.ok) {
    throw new Error("failed to fetch articles");
  }

  return (await response.json()) as ArticlesResponse;
}

export function normalizePage(value?: string | string[]) {
  const raw = Array.isArray(value) ? value[0] : value;
  const parsed = Number.parseInt(raw ?? "1", 10);

  if (!Number.isFinite(parsed) || parsed < 1) {
    return 1;
  }

  return parsed;
}

export function formatPublishedAt(value?: string | null) {
  if (!value) {
    return null;
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat("en", {
    dateStyle: "long",
    timeStyle: "short",
  }).format(date);
}
