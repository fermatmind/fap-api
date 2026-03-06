import { cache } from "react";

export type ArticleReference = {
  slug: string;
  title: string;
  excerpt?: string | null;
};

export type CareerReference = {
  slug: string;
  name: string;
  summary?: string | null;
};

export type PersonalityListItem = {
  type: string;
  summary: string;
};

export type PersonalityDetail = PersonalityListItem & {
  description: string;
  strengths: string;
  weaknesses: string;
  career_advice: string;
  related_careers?: CareerReference[] | null;
  related_articles?: ArticleReference[] | null;
  relatedCareers?: CareerReference[] | null;
  relatedArticles?: ArticleReference[] | null;
};

export type PersonalityListResponse = {
  items?: PersonalityListItem[];
};

export type PersonalityDetailResponse = {
  personality: PersonalityDetail;
};

function getApiBase() {
  const apiBase = process.env.NEXT_PUBLIC_API_BASE?.replace(/\/$/, "");

  if (!apiBase) {
    throw new Error("NEXT_PUBLIC_API_BASE is not configured");
  }

  return apiBase;
}

export const getPersonalities = cache(
  async (): Promise<PersonalityListResponse> => {
    const response = await fetch(`${getApiBase()}/api/v0.5/personality`, {
      cache: "no-store",
    });

    if (!response.ok) {
      throw new Error("failed to fetch personalities");
    }

    return (await response.json()) as PersonalityListResponse;
  },
);

export const getPersonality = cache(
  async (type: string): Promise<PersonalityDetailResponse | null> => {
    const response = await fetch(
      `${getApiBase()}/api/v0.5/personality/${type}`,
      { cache: "no-store" },
    );

    if (response.status === 404) {
      return null;
    }

    if (!response.ok) {
      throw new Error("personality not found");
    }

    return (await response.json()) as PersonalityDetailResponse;
  },
);

export function getRelatedCareers(personality: PersonalityDetail) {
  return personality.related_careers ?? personality.relatedCareers ?? [];
}

export function getRelatedArticles(personality: PersonalityDetail) {
  return personality.related_articles ?? personality.relatedArticles ?? [];
}

export function serializeJsonLd(payload: unknown) {
  return JSON.stringify(payload).replace(/</g, "\\u003c");
}
