# Zadania aplikacji urlopowej

Aktualizacja: 6 listopada 2026 r. Etap **1 — audyt, uruchomienie i dokumentacja — zakończony**.
Następny: etap 2, po kolejnym poleceniu użytkownika i wyjaśnieniu zależnych pytań.

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

Etap 1 odebrano na podstawie lokalnych kontroli i pełnego zielonego
[CI #6](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42247971165).
Dokładne komendy, środowiska i granice testów są w `docs/TEST_PLAN.md`, decyzje
techniczne oraz rozwiązane problemy w `docs/DECISIONS.md`.

Po poleceniu rozpoczęcia etapu 2 sprawdzić aktualny kod i najpierw wyjaśnić pytania
z `docs/OPEN_QUESTIONS.md`, które wpływają na model danych. Q1–Q9 nadal są otwarte;
nie wolno traktować propozycji jako zaakceptowanych odpowiedzi.
Nie rozpoczynać etapu 2 automatycznie. Nie ma jeszcze funkcji urlopowych ani wdrożenia produkcyjnego.
