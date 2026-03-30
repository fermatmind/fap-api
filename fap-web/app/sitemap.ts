import type { MetadataRoute } from 'next';

export default function sitemap(): MetadataRoute.Sitemap {
  const siteUrl = (process.env.NEXT_PUBLIC_SITE_URL || 'https://fermatmind.com').replace(/\/$/, '');
  const now = new Date();
  const paths = [
    '/',
    '/en',
    '/zh',
    '/en/articles',
    '/zh/articles',
    '/en/personality',
    '/zh/personality',
    '/en/topics',
    '/zh/topics',
    '/en/tests',
    '/zh/tests',
    '/en/career',
    '/zh/career',
    '/en/career/guides',
    '/zh/career/guides',
    '/en/career/jobs',
    '/zh/career/jobs',
    '/en/career/recommendations',
    '/zh/career/recommendations',
  ];

  return paths.map((path) => ({
    url: `${siteUrl}${path === '/' ? '' : path}`,
    lastModified: now,
  }));
}
