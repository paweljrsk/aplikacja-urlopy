# Zadania aplikacji urlopowej

Aktualizacja: 6 listopada 2026 r. Etap **1 — audyt, uruchomienie i dokumentacja — zakończony**.
Bieżący: **etap 2 — model danych i migracje — w trakcie**.
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

## Etap 2 — zakres bieżącej pracy

- [x] Sprawdzić aktualny kod, instrukcje, konfigurację Doctrine i zakres etapu.
- [x] Doprecyzować i zapisać Q1 oraz podstawowe ustalenia Q5 i Q8 w wymaganiach i decyzjach B18–B21.
- [-] Zaprojektować model Doctrine z historią przypisań, zasad wniosków i pochodzeniem pul godzin.
- [ ] Wdrożyć encje i ograniczenia dla użytkowników, zespołów, słowników, dni wolnych, limitów, danych początkowych, wniosków, zmian, decyzji i rozliczeń.
- [ ] Przygotować migracje Doctrine bez resetowania istniejących danych.
- [ ] Przygotować syntetyczne dane dla wszystkich ról, UoP/B2B i co najmniej dwóch zespołów, bez automatycznego ładowania na produkcji.
- [ ] Sprawdzić migracje na pustym MySQL i powtarzalność ich wykonania.
- [ ] Zweryfikować zgodność schematu, relacje, ograniczenia, zachowanie historii oraz zabezpieczenia współbieżnych zapisów.
- [ ] Uruchomić adekwatne kontrole jakości i zapisać faktyczne wyniki.
- [ ] Uzupełnić opis modelu, README, decyzje techniczne i raport odbioru etapu 2.

## Kolejne etapy

- [-] Etap 2 — model danych, ograniczenia i migracje Doctrine.
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

Etap 2 rozpoczęto na polecenie użytkownika. Zweryfikowano aktualny stan Git,
zakres promptu 2, wymagania, otwarte pytania i konfigurację Doctrine/Migrations.
Praca bazuje na zakończonym etapie 1; PR #1 pozostaje otwarty i nie został scalony.

Użytkownik zatwierdził cztery propozycje; zapisano je w REQUIREMENTS oraz decyzjach
B18–B21. Można kontynuować model, migracje, syntetyczne dane i testy zgodnie z promptem 2.
Status etapu pozostaje `[-]` do wdrożenia i rzeczywistej weryfikacji.

Q1 jest zamknięte. Q5 nadal wymaga ustalenia widoczności historii i obsługi otwartych
lub już złożonych przyszłych wniosków przy zmianie zespołu/formy zatrudnienia.
Q8 nadal wymaga procedury korekt sprzed granicy ewidencji. Pozostałe pytania są otwarte;
nie wolno rozstrzygać ich domyślnie w modelu ani implementować zależnych operacji.
Nie rozpoczynać etapu 3 bez kolejnego polecenia użytkownika.
