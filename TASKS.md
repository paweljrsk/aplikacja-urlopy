# Zadania aplikacji urlopowej

Aktualizacja: 6 listopada 2026 r. Bieżący etap: **1 — audyt, uruchomienie i dokumentacja**.

Statusy: `[ ]` do zrobienia, `[-]` w trakcie, `[x]` wykonane i zweryfikowane.
Dokumentacja opisuje także przyszłe funkcje; ich opis nie jest potwierdzeniem wykonania.

## Etap 1

- [-] Audyt i uruchomienie repozytorium.
- [ ] Sprawdzić konfigurację Compose, Dockerfile, Makefile i narzędzia jakości.
- [ ] Zweryfikować zależności i usunąć potwierdzone przeszkody uruchomienia w zakresie etapu.
- [x] Utrwalić uzgodnione wymagania i macierz uprawnień w `docs/REQUIREMENTS.md`.
- [x] Utrwalić 12 etapów i pełne prompty w `docs/DEVELOPMENT_PLAN.md`.
- [x] Zapisać nierozstrzygnięte Q1–Q9 w `docs/OPEN_QUESTIONS.md`.
- [x] Utworzyć rejestr uzgodnionych decyzji, plan 23 scenariuszy testowych i szkielet operacyjny.
- [x] Utworzyć główny `AGENTS.md`, zachowując `frontend/AGENTS.md`.
- [ ] Uzupełnić README o zweryfikowane polecenia start/stop/restart, konfigurację i kontrole.
- [ ] Uruchomić backend i frontend, sprawdzić odpowiedzi HTTP i stan usług.
- [ ] Uruchomić dostępne kontrole jakości, zapisać faktyczne wyniki i ograniczenia.
- [ ] Sprawdzić powtarzalność startu oraz restart bez usuwania danych.
- [ ] Zapisać raport odbioru etapu 1 i punkt wznowienia.

Weryfikacja wykonanych zadań dokumentacyjnych: kompletność przeniesionych sekcji
porównano z planem 1.0; sprawdzono obecność 12 promptów, pytań Q1–Q9 i scenariuszy
T01–T23 oraz zachowanie instrukcji frontendu. To nie jest weryfikacja działania aplikacji.

## Kolejne etapy

- [ ] Etap 2 — model danych, ograniczenia i migracje Doctrine.
- [ ] Etap 3 — logowanie lokalne i kontrola dostępu w API.
- [ ] Etap 4 — administracja użytkownikami, zespołami i słownikami.
- [ ] Etap 5 — kalendarz roboczy, limity, salda początkowe i przejście roku.
- [ ] Etap 6 — wnioski, rezerwacje, decyzje i wycofanie.
- [ ] Etap 7 — edycja zaakceptowanych wniosków, anulowanie i korekty.
- [ ] Etap 8 — formularz i widoki pracownika w stylistyce Notus.
- [ ] Etap 9 — główny kalendarz zespołu i lista nieobecności.
- [ ] Etap 10 — widoki przełożonego/administratora, salda i historia.
- [ ] Etap 11 — weryfikacja pełnych procesów, bezpieczeństwa i wydajności.
- [ ] Etap 12 — przygotowanie produkcji, backup/restore i pilotaż.

## Punkt wznowienia

Dokończyć audyt i weryfikację uruchomienia etapu 1. Wyniki zapisać w
`docs/TEST_PLAN.md`, a podjęte decyzje i ograniczenia w `docs/DECISIONS.md`.
Przed zmianą statusów sprawdzić aktualny stan kodu i dowody wykonania.
Nie rozpoczynać etapu 2 bez kolejnego polecenia użytkownika.
