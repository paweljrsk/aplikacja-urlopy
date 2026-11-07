"use client";

import { hoursLabel, type Balance } from "@/lib/balance-api";

type Summary = Omit<Balance, "userId" | "year" | "version" | "history">;
export type BalancePreview = { userId: number; year: number; version: string; before: Summary; after: Summary };

export function BalanceImpactPreview({ preview }: { preview: BalancePreview }) {
  const rows: { label: string; before: number | null; after: number | null }[] = [
    { label: "Dostępne godziny", before: preview.before.availableHours, after: preview.after.availableHours },
    { label: "Limit roczny", before: preview.before.annualHours, after: preview.after.annualHours },
    { label: "Dostępna zaległość", before: preview.before.carriedHours, after: preview.after.carriedHours },
    { label: "Wykorzystanie przed startem", before: preview.before.openingUsedHours, after: preview.after.openingUsedHours },
    { label: "Wykorzystane w ewidencji", before: preview.before.usedHours, after: preview.after.usedHours },
    { label: "Zatwierdzone przyszłe urlopy", before: preview.before.approvedFutureHours, after: preview.after.approvedFutureHours },
    { label: "Oczekujące wnioski", before: preview.before.reservedHours, after: preview.after.reservedHours },
    { label: "Dodatkowa rezerwacja zmian", before: preview.before.changeReservedHours, after: preview.after.changeReservedHours },
  ];
  const pools = new Map<number, { before: number | null; after: number | null }>();
  for (const pool of preview.before.pools) pools.set(pool.originYear, { before: pool.availableHours, after: null });
  for (const pool of preview.after.pools) pools.set(pool.originYear, { before: pools.get(pool.originYear)?.before ?? null, after: pool.availableHours });
  return <section className="balance-impact-preview" aria-labelledby="balance-preview-title">
    <h3 id="balance-preview-title">Podgląd wpływu na saldo · {preview.year}</h3>
    <p className="field-hint">Zmiana zostanie zapisana dopiero po Twoim potwierdzeniu.</p>
    <div className="accounting-table-scroll"><table className="accounting-table"><thead><tr><th scope="col">Pozycja</th><th scope="col">Przed zmianą</th><th scope="col">Po zmianie</th></tr></thead><tbody>{rows.map(row => <tr key={row.label}><th scope="row">{row.label}</th><td>{row.before === null ? "Nie ustawiono" : `${row.before} h`}</td><td>{row.after === null ? "Nie ustawiono" : `${row.after} h`}</td></tr>)}</tbody></table></div>
    <p><strong>Po zmianie dostępne: {hoursLabel(preview.after.availableHours)}.</strong></p>
    {preview.after.deficitHours > 0 && <div className="message message-error" role="status">Po zmianie wystąpi niedobór: {hoursLabel(preview.after.deficitHours)}. Inne zatwierdzone urlopy pozostaną ważne; nowe obciążenia zwiększające niedobór będą zablokowane.</div>}
    {pools.size > 0 && <details className="balance-preview-pools"><summary>Wpływ na pule według roku pochodzenia</summary><dl className="history-values">{[...pools].sort(([a], [b]) => a - b).map(([originYear, values]) => <div key={originYear}><dt>Pula {originYear}</dt><dd>{values.before ?? 0} h → {values.after ?? 0} h</dd></div>)}</dl></details>}
  </section>;
}
