import type { Metadata } from "next";
import Breadcrumb from "@/components/breadcrumb/Breadcrumb";

export const dynamic = "force-dynamic";

export const metadata: Metadata = {
  title: "Tests",
  description: "Browse assessment entry points available on FermatMind.",
  alternates: {
    canonical: "/tests",
  },
};

export default function TestsPage() {
  return (
    <div className="shell page-shell">
      <Breadcrumb
        items={[
          { label: "Home", href: "/" },
          { label: "Tests" },
        ]}
      />

      <section className="page-hero">
        <p className="eyebrow">Assessments</p>
        <h1 className="page-title">Testing entry points live here.</h1>
        <p className="lead">
          This placeholder route keeps the global navigation complete while the
          product-side assessment flows continue shipping separately.
        </p>
      </section>
    </div>
  );
}

