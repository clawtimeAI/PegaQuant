"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

type NavItem = {
  href: string;
  label: string;
};

const NAV_ITEMS: NavItem[] = [
  { href: "/", label: "首页" },
  { href: "/market", label: "行情" },
  { href: "/trade", label: "交易" },
  { href: "/trend", label: "趋势" },
  { href: "/api", label: "API" },
  { href: "/about", label: "关于" },
];

export function SidebarNav() {
  const pathname = usePathname();

  return (
    <TopNavInner pathname={pathname} />
  );
}

export function TopNav() {
  const pathname = usePathname();
  return <TopNavInner pathname={pathname} />;
}

function TopNavInner({ pathname }: { pathname: string }) {
  return (
    <nav className="flex items-center gap-1 text-sm text-white">
      {NAV_ITEMS.map((item) => {
        const active = pathname === item.href;
        return (
          <Link
            key={item.href}
            href={item.href}
            aria-current={active ? "page" : undefined}
            className={`rounded-lg px-3 py-2 ${
              active ? "bg-white/10" : "hover:bg-white/5"
            }`}
          >
            {item.label}
          </Link>
        );
      })}
    </nav>
  );
}
