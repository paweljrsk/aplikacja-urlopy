# Zadania aplikacji urlopowej

Aktualizacja: 7 listopada 2026 r. Etap **1 — audyt, uruchomienie i dokumentacja — zakończony**.
**Etap 2 — model danych i migracje — zakończony**.
**Etap 3 — logowanie lokalne i kontrola dostępu — zakończony**.
**Etap 4 — administracja — zakończony.** Q5 rozstrzygnięto w B22–B24.
Następny: etap 5, po kolejnym poleceniu użytkownika i odpowiedziach na pytania tego etapu.
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

## Etap 3 — ukończony

- [x] Odczytać wymagania, prompt 3, aktualny kod i stan PR #2.
- [x] Wdrożyć logowanie, sesje, CSRF, limity prób i unieważnianie dostępu.
- [x] Wdrożyć hasła tymczasowe, zmianę hasła, reset administratora, bootstrap i recovery; sprawdzić CLI na MySQL.
- [x] Wdrożyć aktualną kontrolę ról, zespołów i profili w API.
- [x] Przygotować i sprawdzić w Chrome podstawowe ekrany logowania, zmiany hasła i chronionej aplikacji.
- [x] Przetestować API i UI, migracje, rzeczywiste Nginx/PHP-FPM oraz zachowanie istniejących danych.
- [x] Uzupełnić dokumentację i rzeczywisty raport odbioru.

## Etap 4 — ukończony

- [x] Odczytać aktualny kod i uzyskać odpowiedzi na Q5; zapisać B22–B24.
- [x] Wdrożyć API administracji, wersjonowanie słowników, datowane przypisania i audyt.
- [x] Wdrożyć ochronę ostatniego administratora, konflikty zapisów i zachowanie historii.
- [x] Przygotować funkcjonalne ekrany administracji; sprawdzić interakcje Chrome i zrzuty desktop/mobile.
- [x] Sprawdzić politykę widoczności i decyzji po przeniesieniu na podstawie dat nieobecności.
- [x] Wykonać testy API i UI, migracje, zachowanie danych, Nginx/PHP-FPM, restart i workery. CI #16: 198 testów / 4599 asercji, SUCCESS.
- [x] Uzupełnić README, kontrakt administracji, decyzje techniczne i raport odbioru.

## Kolejne etapy

- [x] Etap 2 — model danych, ograniczenia i migracje Doctrine.
- [x] Etap 3 — logowanie lokalne i kontrola dostępu w API.
- [x] Etap 4 — administracja użytkownikami, zespołami i słownikami.
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

Etap 3 odebrano na commicie `2f039b7c1a02bf9b888f2a01cc1fa5e24054d0f2`:
[CI #14](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42305357024) **SUCCESS**,
156 testów / 3061 asercji, rzeczywiste CLI administratora, Chrome ze sztucznym API,
logowanie przez Nginx/PHP-FPM, migracja danych etapu 2, lint/build, restart i workery.
[PR #3](https://github.com/paweljrsk/aplikacja-urlopy/pull/3) na gałęzi
`codex/etap-3-logowanie-uprawnienia` bazuje na PR #2. PR-y pozostają niescalone.
Szczegóły oraz granice testów są w TEST_PLAN; API i obsługa administratora w AUTHENTICATION.

Historyczny punkt po etapie 3: **etap 4 — administracja użytkownikami, zespołami i słownikami**.
Przed rozpoczęciem przeczytać prompt 4, wymagania i aktualne otwarte pytania.
Logowanie i bieżące profile są dostępne; wnioski, kalendarz i pełne rozliczenia
pozostają zakresem kolejnych etapów. Aktualizacja: `make stop`, pobranie gałęzi,
`make init`, `make verify`, `make db-validate`; pierwszego administratora inicjalizuje
interaktywne `app:admin:bootstrap`, bez stałego hasła demonstracyjnego.

Q1 jest zamknięte. Q5 zamknięto przed etapem 4: obowiązują B22–B24.
Q8 nadal wymaga procedury korekt sprzed granicy ewidencji. Pozostałe pytania są otwarte;
nie wolno rozstrzygać ich domyślnie w modelu ani implementować zależnych operacji.
Etap 4 rozpoczęto na polecenie użytkownika; nie rozpoczynać etapu 5 bez kolejnego polecenia.

### Bieżący punkt wznowienia — po etapie 4, 7 listopada 2026 r.

Etap 4 odebrano na commicie `4b1f4f42fd02c7550d17974f1dee6de465f4008d`:
[CI #16](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42349188537)
**SUCCESS**, PHP 8.3.36 + MySQL 8.0.32, **198 testów / 4599 asercji**.
Przeszły migracje istniejących danych, zgodność schematu, PHPStan/lint/build,
Chrome (logowanie i administracja), rzeczywisty HTTP przez Nginx/PHP-FPM,
zachowanie danych po restarcie i workery. Zrzuty desktop/mobile przejrzano.

Kod jest w [PR #4](https://github.com/paweljrsk/aplikacja-urlopy/pull/4), gałąź
`codex/etap-4-administracja`, oparta na PR #3. PR-y pozostają niescalone.
Użytkownik zatwierdził publikację gałęzi i CI; Q5 jest zamknięte (B22–B24).

Aktualizacja lokalna: `make stop`, pobranie gałęzi, `make init`, `make verify`,
`make db-validate`. Logowanie dotychczasowym kontem administratora i `/admin`;
na pustej bazie jawnie dodaj słowniki startowe, zespół i konta. Nie uruchamiaj
ponownie bootstrap ani demo, jeśli masz już własne konta. Wygląd nadal roboczy,
dopasowanie do Notus w etapie 8.

Następny: **etap 5 — kalendarz roboczy, limity i saldo**. Najpierw rozstrzygnąć
Q3, Q4, Q6, pozostałą część Q8 i Q9 zgodnie z promptem 5. Nie zaczynać tego etapu
ani nie scalać PR bez kolejnego polecenia. Usługi wniosków w etapach 6–7 mają
korzystać z `RequestAccessPolicy` i blokować agregat użytkownika przed zapisem
przypisań dni; samo zapisane `RequestDay.assignment` nie określa uprawnień.
