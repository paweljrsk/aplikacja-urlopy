"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { ApiError, getApi, redirectForAuthError } from "@/lib/auth-api";
import { balanceError, hoursLabel, wholeNumber, type Balance, type BalancePerson, type PeopleResponse } from "@/lib/balance-api";
import { AccountingField, AccountingNotice } from "./accounting-shared";
import "./team-balances.css";

type Row = { person: BalancePerson; balance: Balance | null };

export function TeamBalancesPanel({ initialYear, onSelect }: { initialYear: string; onSelect: (person: BalancePerson, year: string) => void }) {
  const [year, setYear] = useState(initialYear);
  const [loadedYear, setLoadedYear] = useState(initialYear);
  const [rows, setRows] = useState<Row[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [query, setQuery] = useState("");
  const sequence = useRef(0);
  const selectedYear = useRef(initialYear);

  const load = useCallback(async (value: string) => {
    let numericYear: number;
    try { numericYear = wholeNumber(value, 2025, 2100); } catch (failure) { setError(balanceError(failure)); return; }
    const current = ++sequence.current;
    selectedYear.current = value;
    setRows([]); setLoading(true); setError("");
    try {
      // Obtain the authorized people again; an old selector is not an access grant.
      const { people } = await getApi<PeopleResponse>("/api/balances/people");
      const result: Row[] = [];
      for (let offset = 0; offset < people.length; offset += 4) {
        if (current !== sequence.current) return;
        const batch = await Promise.all(people.slice(offset, offset + 4).map(async person => {
          try {
            const { balance } = await getApi<{ balance: Balance }>(`/api/balances/users/${person.id}/${numericYear}`);
            return { person, balance };
          } catch (failure) {
            if (failure instanceof ApiError && (failure.status === 401 || failure.status === 403)) throw failure;
            return { person, balance: null };
          }
        }));
        result.push(...batch);
      }
      if (current !== sequence.current) return;
      setRows(result.sort((a, b) => `${a.person.lastName} ${a.person.firstName}`.localeCompare(`${b.person.lastName} ${b.person.firstName}`, "pl")));
      setLoadedYear(String(numericYear));
    } catch (failure) {
      if (current === sequence.current && !redirectForAuthError(failure)) setError(failure instanceof ApiError && failure.status === 403 ? "Zakres dostępu do sald zmienił się. Odśwież zestawienie." : balanceError(failure));
    } finally { if (current === sequence.current) setLoading(false); }
  }, []);

  useEffect(() => {
    let active = true;
    void Promise.resolve().then(() => { if (active) void load(selectedYear.current); });
    const invalidate = () => { ++sequence.current; };
    const refresh = () => { void load(selectedYear.current); };
    const hide = () => { ++sequence.current; setRows([]); setLoading(true); };
    const visibility = () => { if (document.visibilityState === "visible") refresh(); else hide(); };
    window.addEventListener("focus", refresh);
    document.addEventListener("visibilitychange", visibility);
    return () => { active = false; invalidate(); window.removeEventListener("focus", refresh); document.removeEventListener("visibilitychange", visibility); };
  }, [load]);

  const visible = rows.filter(row => `${row.person.firstName} ${row.person.lastName} ${row.person.team?.name ?? ""}`.toLocaleLowerCase("pl").includes(query.toLocaleLowerCase("pl")));
  return <section className="team-balances-panel" aria-labelledby="team-balances-title">
    <div className="admin-section-heading"><div><h2 id="team-balances-title">Salda zespołu · {loadedYear}</h2><p className="field-hint">Zestawienie osób dostępnych w Twoim zakresie uprawnień. Szczegóły pokazują pule i historię zmian.</p></div></div>
    <form className="team-balance-filters" onSubmit={event => { event.preventDefault(); void load(year); }}>
      <AccountingField id="team-balance-year" label="Rok zestawienia" type="number" min={2025} max={2100} value={year} onChange={setYear} />
      <AccountingField id="team-balance-search" label="Szukaj osoby lub zespołu" value={query} onChange={setQuery} required={false} />
      <button type="submit" className="button button-secondary" disabled={loading}>Odśwież zestawienie</button>
    </form>
    {error && <AccountingNotice>{error}</AccountingNotice>}
    {loading ? <p role="status">Wczytywanie sald zespołu…</p> : !error && <>
      {rows.some(row => row.balance === null) && <AccountingNotice kind="info">Nie udało się odczytać części sald. Odśwież zestawienie, aby spróbować ponownie.</AccountingNotice>}
      {!visible.length ? <p className="field-hint" role="status">Brak osób spełniających wybrane kryteria.</p> : <div className="accounting-table-scroll" tabIndex={0} role="region" aria-label="Salda zespołu, tabela przewijana poziomo"><table className="accounting-table team-balances-table">
        <caption className="sr-only">Salda uprawnionych osób w roku {loadedYear}. Wszystkie wartości są w godzinach.</caption>
        <thead><tr><th scope="col">Pracownik</th><th scope="col">Dostępne</th><th scope="col">Limit</th><th scope="col">Wykorzystane</th><th scope="col">Zaplanowane</th><th scope="col">Rezerwacje</th><th scope="col"><span className="sr-only">Szczegóły</span></th></tr></thead>
        <tbody>{visible.map(({ person, balance }) => <tr key={person.id}><th scope="row">{person.firstName} {person.lastName}<small>{person.team?.name ?? "Brak zespołu"}{!person.active && " · Konto nieaktywne"}</small></th>{balance ? <>
          <td className={balance.deficitHours > 0 ? "team-balance-deficit" : "team-balance-available"}><strong>{balance.availableHours} h</strong><small>{hoursLabel(balance.availableHours)}</small></td>
          <td>{balance.annualHours === null ? "Nie ustawiono" : `${balance.annualHours} h`}</td>
          <td>{balance.usedHours + balance.openingUsedHours} h<small>Łącznie z danymi początkowymi</small></td>
          <td>{balance.approvedFutureHours} h</td><td>{balance.reservedHours + balance.changeReservedHours} h<small>Wnioski i dodatki na zmiany</small></td>
        </> : <td colSpan={5}>Nie udało się odczytać salda.</td>}<td><button type="button" className="text-button" aria-label={`Saldo: ${person.firstName} ${person.lastName}`} onClick={() => onSelect(person, loadedYear)}>Szczegóły</button></td></tr>)}</tbody>
      </table></div>}
      <p className="field-hint">Oczekujące wnioski już rezerwują godziny. Akceptacja nie odejmuje ich ponownie. Nie sumujemy pul wspólnych dla różnych lat.</p>
    </>}
  </section>;
}
