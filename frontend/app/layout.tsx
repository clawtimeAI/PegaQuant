import type { Metadata } from "next";
import Link from "next/link";
import { TopNav } from "./_components/SidebarNav";
import "./globals.css";

export const metadata: Metadata = {
  title: "PegaQuant",
  description: "USDT 合约量化交易",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html
      lang="zh-CN"
      className="h-full bg-[#0b0e11] antialiased"
    >
      <body className="min-h-full bg-[#0b0e11] text-[#eaecef]">
        <div className="min-h-screen">
          <header className="sticky top-0 z-20 border-b border-white/5 bg-[#0b0e11]/90 backdrop-blur">
            <div className="flex h-14 items-center justify-between px-6">
              <div className="flex items-center gap-3">
                <div className="h-6 w-6 rounded bg-amber-400" />
                <Link href="/" className="font-semibold tracking-tight text-white">
                  PegaQuant
                </Link>
              </div>
              <div className="hidden md:block">
                <TopNav />
              </div>
              <div className="text-sm text-white">本地开发</div>
            </div>
            <div className="border-t border-white/5 px-6 py-2 md:hidden">
              <TopNav />
            </div>
          </header>

          <main className="bg-[#0b0e11] text-white">
            <div className="w-full px-6 py-6">{children}</div>
          </main>
        </div>
      </body>
    </html>
  );
}
