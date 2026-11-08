"use client";

import { useEffect, useState } from "react";
import { redirectForAuthError } from "@/lib/auth-api";
import { getRequestDuration, type DurationInput, type RequestDuration } from "@/lib/request-duration-api";

export function RequestDurationSummary({ mode, startDate, endDate, hours }: DurationInput) {
  const valid = /^\d{4}-\d{2}-\d{2}$/.test(startDate) && /^\d{4}-\d{2}-\d{2}$/.test(endDate)
    && startDate >= "2025-01-01" && endDate <= "2100-12-31" && endDate >= startDate
    && (mode === "full_days" || startDate === endDate && Number.isInteger(hours) && hours! >= 1 && hours! <= 7);
  const fingerprint = JSON.stringify({ mode, startDate, endDate, ...(mode === "hourly" ? { hours } : {}) });
  // A changed selection gets a fresh state immediately, before effects run.
  // Late responses belong to the unmounted selection and cannot replace its successor.
  return valid ? <DurationResult key={fingerprint} fingerprint={fingerprint} />
    : <div className="requests-duration" role="status" aria-label="Obliczony wymiar urlopu"><span>Wymiar urlopu</span><p>Wybierz poprawny zakres dat, aby obliczyć liczbę dni.</p></div>;
}

function DurationResult({ fingerprint }: { fingerprint: string }) {
  const [duration, setDuration] = useState<RequestDuration | null>(null);
  const [error, setError] = useState(false);
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    let active = true;
    const timer = window.setTimeout(() => {
      void getRequestDuration(JSON.parse(fingerprint) as DurationInput).then(value => {
        if (active) setDuration(value);
      }).catch(failure => {
        if (active && !redirectForAuthError(failure)) setError(true);
      });
    }, 200);
    return () => { active = false; window.clearTimeout(timer); };
  }, [fingerprint, attempt]);
  const input = JSON.parse(fingerprint) as DurationInput;
  function retry() { setDuration(null); setError(false); setAttempt(value => value + 1); }
  return <div className="requests-duration" role="status" aria-label="Obliczony wymiar urlopu" aria-live="polite" aria-atomic="true">
    <span>Wymiar urlopu</span>
    {error ? <><p>Nie udało się obliczyć wymiaru urlopu.</p><button type="button" className="text-button" onClick={retry}>Ponów obliczenie dni</button></>
      : duration ? <><strong>{input.mode === "hourly" && duration.totalHours > 0 ? `${duration.totalHours} h` : `${duration.workingDays} ${duration.workingDays === 1 ? "dzień" : "dni"}`}{input.mode === "full_days" && <small> ({duration.totalHours} h)</small>}</strong>
        <p>{duration.workingDays === 0 ? "W tym terminie nie ma dni roboczych." : input.mode === "hourly" ? "Nieobecność przez część dnia roboczego." : "Bez weekendów, świąt i firmowych dni wolnych."}</p></>
        : <p>Obliczamy liczbę dni…</p>}
  </div>;
}
