import type { MetadataRoute } from "next";
import {
  getArticles,
  getCareers,
  getPersonalityTypes,
} from "@/lib/content";
import { absoluteUrl } from "@/lib/site";

export default function sitemap(): MetadataRoute.Sitemap {
  const lastModified = new Date("2026-03-06T09:00:00+08:00");
  const staticRoutes = [
    "/",
    "/tests",
    "/articles",
    "/career",
    "/personality",
  ];

  return [
    ...staticRoutes.map((route) => ({
      url: absoluteUrl(route),
      lastModified,
    })),
    ...getArticles().map((article) => ({
      url: absoluteUrl(`/articles/${article.slug}`),
      lastModified,
    })),
    ...getCareers().map((career) => ({
      url: absoluteUrl(`/career/${career.slug}`),
      lastModified,
    })),
    ...getPersonalityTypes().map((type) => ({
      url: absoluteUrl(`/personality/${type.toLowerCase()}`),
      lastModified,
    })),
  ];
}
