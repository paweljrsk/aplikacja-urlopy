import type { Metadata } from "next";
import "./globals.css";
import "./notus.css";

export const metadata: Metadata = {
  title: "Urlopy zespołu",
  description: "Wewnętrzna aplikacja urlopowa zespołu.",
  robots: { index: false, follow: false },
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="pl">
      <body>{children}</body>
    </html>
  );
}
