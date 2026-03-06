import Link from "next/link";
import type { RelatedContentItem } from "@/lib/content";

type RelatedContentProps = {
  title: string;
  items?: RelatedContentItem[];
};

export default function RelatedContent({
  title,
  items,
}: RelatedContentProps) {
  if (!items?.length) {
    return null;
  }

  return (
    <section className="related-panel related-content">
      <h2>{title}</h2>

      <ul className="link-list">
        {items.map((item) => (
          <li key={item.slug}>
            <Link href={item.url} className="related-content__link">
              {item.title}
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}

