import Link from "next/link";

const navItems = [
  { href: "/tests", label: "Tests" },
  { href: "/personality", label: "Personality" },
  { href: "/career", label: "Career" },
  { href: "/articles", label: "Articles" },
];

export default function Header() {
  return (
    <header className="site-header">
      <div className="shell site-header__inner">
        <Link href="/" className="brand-mark">
          FermatMind
        </Link>

        <nav aria-label="Primary" className="main-nav">
          {navItems.map((item) => (
            <Link key={item.href} href={item.href} className="main-nav__link">
              {item.label}
            </Link>
          ))}
        </nav>
      </div>
    </header>
  );
}

