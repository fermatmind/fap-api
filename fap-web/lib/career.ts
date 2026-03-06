import { cache } from "react";

export type CareerListItem = {
  id: number | string;
  slug: string;
  name: string;
  summary: string;
};

export type CareerDetail = CareerListItem & {
  description: string;
  skills: string;
  salary_range: string;
  future_outlook: string;
};

export type CareerListResponse = {
  items?: CareerListItem[];
};

export type CareerDetailResponse = {
  career: CareerDetail;
};

function getApiBase() {
  const apiBase = process.env.NEXT_PUBLIC_API_BASE?.replace(/\/$/, "");

  if (!apiBase) {
    throw new Error("NEXT_PUBLIC_API_BASE is not configured");
  }

  return apiBase;
}

export const getCareers = cache(async (): Promise<CareerListResponse> => {
  const response = await fetch(`${getApiBase()}/api/v0.5/career`, {
    cache: "no-store",
  });

  if (!response.ok) {
    throw new Error("failed to fetch careers");
  }

  return (await response.json()) as CareerListResponse;
});

export const getCareer = cache(
  async (slug: string): Promise<CareerDetailResponse | null> => {
    const response = await fetch(`${getApiBase()}/api/v0.5/career/${slug}`, {
      cache: "no-store",
    });

    if (response.status === 404) {
      return null;
    }

    if (!response.ok) {
      throw new Error("career not found");
    }

    return (await response.json()) as CareerDetailResponse;
  },
);

export function serializeJsonLd(payload: unknown) {
  return JSON.stringify(payload).replace(/</g, "\\u003c");
}
