import type { Metadata } from "next";
import Link from "next/link";
import type { ReactNode } from "react";

import "./globals.css";

export const metadata: Metadata = {
  title: "FermatMind",
  description:
    "Explore articles, careers, and personality guides from FermatMind.",
};

type RootLayoutProps = {
  children: ReactNode;
};

export default function RootLayout({ children }: RootLayoutProps) {
  return (
    <html lang="en">
      <body>
        <header className="site-header">
          <div className="site-shell">
            <Link className="site-brand" href="/personality">
              FermatMind
            </Link>

            <nav className="site-nav" aria-label="Primary">
              <Link href="/articles">Articles</Link>
              <Link href="/career">Career</Link>
              <Link href="/personality">Personality</Link>
            </nav>
          </div>
        </header>

        {children}
      </body>
    </html>
  );
}
