# Zadania aplikacji urlopowej

Aktualizacja: 6 listopada 2026 r. Etap **1 — audyt, uruchomienie i dokumentacja — zakończony**.
**Etap 2 — model danych i migracje — zakończony**.
Bieżący: **etap 3 — logowanie lokalne i kontrola dostępu — w trakcie**.
Odpowiedzi potrzebne do rozpoczęcia modelu (Q1, podstawy Q5 i Q8) zatwierdzono i zapisano.

Statusy: `[ ]` do zrobienia, `[-]` w trakcie, `[x]` wykonane i zweryfikowane.
Dokumentacja opisuje także przyszłe funkcje; ich opis nie jest potwierdzeniem wykonania.

## Etap 1

- [x] Audyt i uruchomienie repozytorium.
- [x] Sprawdzić konfigurację Compose, Dockerfile, Makefile i narzędzia jakości.
- [x] Zweryfikować zależności i usunąć potwierdzone przeszkody uruchomienia w zakresie etapu.
- [x] Utrwalić uzgodnione wymagania i macierz uprawnień w `docs/REQUIREMENTS.md`.
- [x] Utrwalić 12 etapów i pełne prompty w `docs/DEVELOPMENT_PLAN.md`.
- [x] Zapisać nierozstrzygnięte Q1–Q9 w `docs/OPEN_QUESTIONS.md`.
- [x] Utworzyć rejestr uzgodnionych decyzji, plan 23 scenariuszy testowych i szkielet operacyjny.
- [x] Utworzyć główny `AGENTS.md`, zachowując `frontend/AGENTS.md`.
- [x] Uzupełnić README o zweryfikowane polecenia start/stop/restart, konfigurację i kontrole.
- [x] Uruchomić backend i frontend, sprawdzić odpowiedzi HTTP i stan usług.
- [x] Uruchomić dostępne kontrole jakości, zapisać faktyczne wyniki i ograniczenia.
- [x] Sprawdzić powtarzalność startu oraz restart bez usuwania danych.
- [x] Zapisać raport odbioru etapu 1 i punkt wznowienia.

Weryfikacja wykonanych zadań dokumentacyjnych: kompletność przeniesionych sekcji
porównano z planem 1.0; sprawdzono obecność 12 promptów, pytań Q1–Q9 i scenariuszy
T01–T23 oraz zachowanie instrukcji frontendu. To nie jest weryfikacja działania aplikacji.

## Etap 2 — ukończony

- [x] Sprawdzić aktualny kod, instrukcje, konfigurację Doctrine i zakres etapu.
- [x] Doprecyzować i zapisać Q1 oraz podstawowe ustalenia Q5 i Q8 w wymaganiach i decyzjach B18–B21.
- [x] Zaprojektować model Doctrine z historią przypisań, zasad wniosków i pochodzeniem pul godzin.
- [x] Wdrożyć encje i ograniczenia dla użytkowników, zespołów, słowników, dni wolnych, limitów, danych początkowych, wniosków, zmian, decyzji i rozliczeń.
- [x] Przygotować migracje Doctrine bez resetowania istniejących danych.
- [x] Przygotować syntetyczne dane dla wszystkich ról, UoP/B2B i co najmniej dwóch zespołów, bez automatycznego ładowania na produkcji.
- [x] Sprawdzić migracje na pustym MySQL i powtarzalność ich wykonania.
- [x] Zweryfikować zgodność schematu, relacje, ograniczenia, zachowanie historii oraz zabezpieczenia współbieżnych zapisów.
- [x] Uruchomić adekwatne kontrole jakości i zapisać faktyczne wyniki.
- [x] Uzupełnić opis modelu, README, decyzje techniczne i raport odbioru etapu 2.

## Etap 3 — bieżąca praca

- [x] Odczytać wymagania, prompt 3, aktualny kod i stan PR #2.
- [-] Wdrożyć logowanie, sesje, CSRF, limity prób i unieważnianie dostępu.
- [ ] Wdrożyć hasła tymczasowe, zmianę hasła, reset administratora i bootstrap.
- [ ] Wdrożyć aktualną kontrolę ról, zespołów i profili w API.
- [ ] Przygotować podstawowe ekrany logowania, zmiany hasła i chronionej aplikacji.
- [ ] Przetestować API i UI, migracje oraz zachowanie istniejących danych.
- [ ] Uzupełnić dokumentację i rzeczywisty raport odbioru.

## Kolejne etapy

- [x] Etap 2 — model danych, ograniczenia i migracje Doctrine.
- [-] Etap 3 — logowanie lokalne i kontrola dostępu w API.
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

Etap 1 odebrano na podstawie lokalnych kontroli i pełnego zielonego
[CI #6](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42247971165).
Dokładne komendy, środowiska i granice testów są w `docs/TEST_PLAN.md`, decyzje
techniczne oraz rozwiązane problemy w `docs/DECISIONS.md`.

Etap 2 odebrano na commicie `2917abae9a06ee92b3185062f667f2460cdd801c`:
[CI #11](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42292576627) **SUCCESS**,
81 testów / 397 asercji na PHP 8.3.36 i MySQL 8.0.32. Zaliczone migracje, zgodność
schematu, ograniczenia, historia, konflikty zapisów, dwukrotne demo, lint, build,
zachowanie danych po stop/start i start workerów. Szczegóły są w TEST_PLAN.

[PR #2](https://github.com/paweljrsk/aplikacja-urlopy/pull/2) na gałęzi
`codex/etap-2-model-danych` bazuje na [PR #1](https://github.com/paweljrsk/aplikacja-urlopy/pull/1).
Oba pozostają niescalone. Zapisano odpowiedzi B18–B21, model w DATA_MODEL oraz
polecenia odbioru w README. Po pobraniu gałęzi: `make init`, `make verify`,
`make db-validate`, `make test`; `make demo` jest opcjonalne na pustej bazie dev.

Punkt dalszej pracy: **etap 3 — logowanie lokalne i kontrola dostępu w API**.
Przed rozpoczęciem przeczytać prompt 3, wymagania i aktualne otwarte pytania.
Model nie udostępnia jeszcze interfejsów biznesowych ani pełnych usług rozliczeń.

Q1 jest zamknięte. Q5 nadal wymaga ustalenia widoczności historii i obsługi otwartych
lub już złożonych przyszłych wniosków przy zmianie zespołu/formy zatrudnienia.
Q8 nadal wymaga procedury korekt sprzed granicy ewidencji. Pozostałe pytania są otwarte;
nie wolno rozstrzygać ich domyślnie w modelu ani implementować zależnych operacji.
Etap 3 rozpoczęto na polecenie użytkownika. Nie rozpoczynać etapu 4 bez kolejnego polecenia.
