import { redirect } from "next/navigation";
import { Suspense } from "react";
import { ManagementHome } from "../components/management-home";

type ManagementPageProps = { searchParams: Promise<{ id?: string | string[] }> };

async function ManagementEntry({ searchParams }: ManagementPageProps) {
  const { id } = await searchParams;
  if (typeof id === "string" && /^[1-9]\d*$/.test(id) && Number.isSafeInteger(Number(id))) {
    redirect(`/management/requests?id=${id}`);
  }
  return <ManagementHome />;
}

export default function ManagementPage(props: ManagementPageProps) {
  return <Suspense fallback={<main className="requests-gate" role="status">Wczytywanie panelu…</main>}><ManagementEntry {...props} /></Suspense>;
}
