export const siteConfig = {
  name: "FermatMind",
  description:
    "Explore structured articles, career guides, and personality profiles on FermatMind.",
  url: process.env.NEXT_PUBLIC_SITE_URL || "http://localhost:3000",
};

export function absoluteUrl(path = "/") {
  return new URL(path, siteConfig.url).toString();
}

