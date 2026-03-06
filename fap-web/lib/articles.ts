import { cache } from "react";

import { marked } from "marked";

export type Article = {
  id: number | string;
  slug: string;
  title: string;
  excerpt?: string | null;
  published_at?: string | null;
  content_html?: string | null;
  content_md?: string | null;
};

export type ArticleResponse = {
  article: Article;
};

export type ArticlesResponse = {
  items: Article[];
};

export type ArticleSeoResponse = {
  meta: {
    title?: string | null;
    description?: string | null;
    canonical?: string | null;
    og?: {
      title?: string | null;
      description?: string | null;
      image?: string | null;
    } | null;
    twitter?: {
      title?: string | null;
      description?: string | null;
      image?: string | null;
    } | null;
  };
  jsonld?: Record<string, unknown> | Array<Record<string, unknown>> | null;
};

function getApiBase() {
  const apiBase = process.env.NEXT_PUBLIC_API_BASE?.replace(/\/$/, "");

  if (!apiBase) {
    throw new Error("NEXT_PUBLIC_API_BASE is not configured");
  }

  return apiBase;
}

export const getArticles = cache(async (): Promise<ArticlesResponse> => {
  const response = await fetch(`${getApiBase()}/api/v0.5/articles`, {
    cache: "no-store",
  });

  if (!response.ok) {
    throw new Error("Failed to fetch articles");
  }

  return (await response.json()) as ArticlesResponse;
});

export const getArticle = cache(
  async (slug: string): Promise<ArticleResponse | null> => {
    const response = await fetch(`${getApiBase()}/api/v0.5/articles/${slug}`, {
      cache: "no-store",
    });

    if (response.status === 404) {
      return null;
    }

    if (!response.ok) {
      throw new Error("Failed to fetch article");
    }

    return (await response.json()) as ArticleResponse;
  },
);

export const getSeo = cache(
  async (slug: string): Promise<ArticleSeoResponse | null> => {
    const response = await fetch(
      `${getApiBase()}/api/v0.5/articles/${slug}/seo`,
      {
        cache: "no-store",
      },
    );

    if (!response.ok) {
      return null;
    }

    return (await response.json()) as ArticleSeoResponse;
  },
);

export async function getArticleHtml(article: Article) {
  if (article.content_html) {
    return article.content_html;
  }

  if (article.content_md) {
    return await marked.parse(article.content_md);
  }

  return "";
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

export function serializeJsonLd(payload: unknown) {
  return JSON.stringify(payload).replace(/</g, "\\u003c");
}
