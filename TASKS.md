# Zadania aplikacji urlopowej

Aktualizacja: 7 listopada 2026 r. Etap **1 — audyt, uruchomienie i dokumentacja — zakończony**.
**Etap 2 — model danych i migracje — zakończony**.
**Etap 3 — logowanie lokalne i kontrola dostępu — zakończony**.
**Etap 4 — administracja — zakończony.** Q5 rozstrzygnięto w B22–B24.
**Etap 5 — kalendarz roboczy, limity i saldo — zakończony.**
**Etap 6 — wnioski, rezerwacje i decyzje — zakończony.** Q2 o dzisiaj i Q10 zatwierdzone jako B30–B31.
**Etap 7 — zmiany, anulowania i korekty — zakończony.** Q2/Q4: B32–B33.
Użytkownik zatwierdził Q3, Q4, Q6, pozostałą część Q8 i Q9; zapisano B25–B29.
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

## Etap 5 — ukończony

- [x] Odczytać aktualny kod, wymagania, decyzje i prompt etapu 5; sprawdzić stan Git.
- [x] Uzyskać odpowiedzi na Q3, Q4, Q6, pozostałą część Q8 i Q9; zapisać decyzje B25–B29 przed zależną implementacją.
- [x] Zweryfikować urzędowe źródła świąt i wdrożyć lokalny kalendarz dni roboczych.
- [x] Wdrożyć limity, dane początkowe, saldo godzinowe i idempotentne rozliczenie roku.
- [x] Przygotować atomowe operacje rezerwacji, zwolnienia, zatwierdzenia i korekty.
- [x] Dodać chronione API i podstawowe ekrany limitów/danych początkowych oraz dni firmowych; sprawdzić Chrome.
- [x] Sprawdzić obliczenia, uprawnienia, współbieżność, migracje i zachowanie istniejących danych. CI #20: 268 testów / 6832 asercje, SUCCESS.
- [x] Uzupełnić dokumentację, wzór salda i rzeczywisty raport odbioru.

## Etap 6 — ukończony

- [x] Sprawdzić aktualne wymagania, prompt 6, model wniosków, zasady dostępu i stan PR #5.
- [x] Rozstrzygnąć Q2 o dzisiejszym dniu oraz Q10 o uzasadnieniu odrzucenia przed zależną implementacją.
- [x] Dodać publiczne API tworzenia, odczytu, edycji oczekującego wniosku, wycofania i decyzji.
- [x] Połączyć walidację kalendarza, rodzaju, zatrudnienia, godzin i salda z atomowym zapisem historii.
- [x] Zachować zapisane zasady przy edycji, kontrolę dostępu po transferze, idempotencję i ochronę nieaktualnych wersji.
- [x] Sprawdzić scenariusze ról, przełomu roku, współbieżnych wniosków i decyzji oraz zachowanie dotychczasowych danych. CI #22: 337 testów / 10 662 asercje, SUCCESS.
- [x] Uzupełnić kontrakt API, README, plan testów i rzeczywisty raport odbioru; opublikować PR #6 i zweryfikować pełny CI.

## Etap 7 — ukończony

- [x] Odczytać aktualny prompt 7, wymagania, decyzje, otwarte pytania, PR #6 i podstawowe usługi wniosków/rozliczeń.
- [x] Rozstrzygnąć Q2/Q4 i zapisać odpowiedź „Tak” jako B32–B33 przed zależną implementacją.
- [x] Dodać API propozycji zmiany/anulowania i decyzji oraz odczyt wersji obowiązującej i proponowanej.
- [x] Sprawdzić dodatkowe rezerwacje per rok/pula, ochronę obu terminów i maksymalną zajętość dnia bez podwójnego sumowania wersji.
- [x] Wdrożyć uzgodnione korekty historyczne z kontrolą uprawnień, podglądem wpływu, audytem i zachowaniem danych początkowych.
- [x] Sprawdzić zmiany rodzaju i roku, transfery, niedobór, zwroty, ponowienia i rzeczywistą współbieżność na MySQL.
- [x] Uzupełnić kontrakt API, dokumentację i faktyczne wyniki odbioru; lokalnie i w Docker CI #24: 391 testów / 15 091 asercji, rzeczywisty HTTP i pozostałe kontrole PASS. Opublikowano PR #7.

## Kolejne etapy

- [x] Etap 2 — model danych, ograniczenia i migracje Doctrine.
- [x] Etap 3 — logowanie lokalne i kontrola dostępu w API.
- [x] Etap 4 — administracja użytkownikami, zespołami i słownikami.
- [x] Etap 5 — kalendarz roboczy, limity, salda początkowe i przejście roku.
- [x] Etap 6 — wnioski, rezerwacje, decyzje i wycofanie.
- [x] Etap 7 — edycja zaakceptowanych wniosków, anulowanie i korekty.
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

Historycznie przed etapem 4 zamknięte były Q1 i Q5 (B22–B24). Aktualny stan pytań
znajduje się w OPEN_QUESTIONS; decyzje etapu 5 rozszerzyły te ustalenia o B25–B29.

### Historyczny odbiór etapu 4 — 7 listopada 2026 r.

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

### Historyczny odbiór etapu 5 — 7 listopada 2026 r.

Etap 5 odebrano na commicie `c526ab8e2eda42ab029ba24a1f49ec6d5ff58701`:
[CI #20](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42357495900)
**SUCCESS**, PHP 8.3.36 + MySQL 8.0.32, **268 testów / 6832 asercje**.
Przeszły migracje z zachowaniem danych etapu 4, rozliczenia i uprawnienia,
PHPStan/lint/build, Chrome, rzeczywisty HTTP przez Nginx/PHP-FPM,
restart bez utraty danych i workery. Zakres kontroli opisuje TEST_PLAN.

Kod jest w [PR #5](https://github.com/paweljrsk/aplikacja-urlopy/pull/5), gałąź
`codex/etap-5-kalendarz-limity-saldo`, oparta na PR #4. PR-y pozostają niescalone.
Nowe ekrany: `/balances` i `/calendar-settings`. Aktualizacja lokalna:
`make stop`, pobranie gałęzi, `make init`, `make verify`, `make db-validate`.
Dotychczasowe konto administratora i dane pozostają zachowane.

### Historyczny odbiór etapu 6 — 7 listopada 2026 r.

Etap 6 odebrano na commicie `58f30102b85567952f5967663ad8f04028d79384`:
[CI #22](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42360588801)
**SUCCESS**, PHP 8.3.36 + MySQL 8.0.32, **337 testów / 10 662 asercje**.
Zaliczone API, rozliczenia, kontrola dostępu do poszczególnych wersji, rzeczywista
współbieżność, migracje i schemat, PHPStan/lint/build, regresja Chrome, HTTP przez
Nginx/PHP-FPM, restart z zachowaniem danych oraz workery. Szczegóły w TEST_PLAN.

Kod jest w [PR #6](https://github.com/paweljrsk/aplikacja-urlopy/pull/6), gałąź
`codex/etap-6-wnioski-decyzje`, oparta na końcowym commicie etapu 5
`12e66f3fb76a25ea72ab4bd9d6b815f7091c905e` (CI #21 również SUCCESS).
Etap 6 udostępnia wnioski przez API opisane w REQUESTS_API; formularz i stylistyka
Notus pozostają w etapie 8. Bez nowych zależności ani migracji. Aktualizacja:
`make stop`, pobranie gałęzi, `make init`, `make verify`, `make db-validate`.

Etap 7 użytkownik zlecił 7 listopada 2026 r.; bieżący punkt wznowienia znajduje się poniżej.
Nie scalać PR bez osobnego polecenia.

Usługi etapu 6 korzystają z poniższych zabezpieczeń; należy zachować je w etapie 7:
korzystać z `RequestAccessPolicy`, blokować
agregat użytkownika przed zapisem i zachowywać pełny `RequestAssignmentContext`.
Samo `RequestDay.assignment` nie określa uprawnień. Przygotowane operacje rozliczeń
przyjmują zwalidowane, zapisane wersje; publiczny proces musi sprawdzać daty,
kalendarz, dostępność rodzaju i zatrudnienie przed ich utworzeniem.

Wnioski z przeglądu przed etapem 6: edycja oczekującej wersji ma zachować kontekst
dotychczasowego zakresu, także pierwotnie wolnych dni; nowe daty poza nim wymagają
przypisania z daty nieobecności. Nie rozciągać starego otwartego przypisania poza
utrwalony zakres. Poprzednią oczekującą wersję zastępować nową w jednej transakcji,
rozliczając tylko końcowy stan. Uprawnienia do historii sprawdzać osobno dla każdej
wersji; dostęp do obecnego wniosku nie pozwala ujawnić całej historii innego zespołu.

### Historyczny punkt rozpoczęcia etapu 7, 7 listopada 2026 r.

Gałąź `codex/etap-7-zmiany-anulowania-korekty` bazuje na końcowym commicie etapu 6
`6c0be6b3a292ae427bc286d97ee5eeee394d810f` (CI #23 SUCCESS).
Przeczytano wymagania i prompt 7 oraz sprawdzono model wersji, aktualny workflow,
API odczytu, politykę dostępu i rozliczanie wniosków. Analiza nie stanowi odbioru etapu 7.

Zależną implementację wznowić po odpowiedziach na dwie propozycje Q2/Q4 zapisane
w OPEN_QUESTIONS. Najpierw utrwalić odpowiedzi w REQUIREMENTS i DECISIONS.
Potwierdzenia z etapu 6 dotyczyły dzisiejszego dnia i powodu odrzucenia;
nie rozstrzygają korekt przeszłości ani dodatkowych uprawnień do niedoboru.

Istniejący model zawiera wersję obowiązującą i oczekującą, ale publiczny workflow
etapu 6 obsługuje tylko INITIAL. Polityka decyzji sprawdza również wersję zastępowaną.
Rozliczenia wymagają osobnego sprawdzenia różnicy zapotrzebowania per rok i pula:
obecne liczenie dodatku per dzień nie wystarcza jako dowód poprawnego rozliczania
przeniesionego terminu. Zachować ochronę starych i nowych dni przez maksimum wymiaru.
Nie utożsamiać dostępności całego salda z prawem przełożonego starego zespołu do korekty.

Środowisko wykonawcze przy rozpoczęciu tego etapu było niedostępne. Analizę i zapis
dokumentacji wykonano przez GitHub; nie uruchomiono nowych testów i nie zmieniono
kodu aplikacji. Dalsza weryfikacja wymaga działającego środowiska lub właściwego CI
na nowym kodzie. Etap 8 pozostaje nierozpoczęty.

### Historyczna aktualizacja — odpowiedź przed implementacją etapu 7

Użytkownik potwierdził obie propozycje. B32–B33 i REQUIREMENTS 1.5 są obowiązujące.
Środowisko wykonawcze odzyskało dostęp; gałąź pobrano lokalnie bez zmian kodu etapu 6.
Wznowić implementację i testy etapu 7 na tej gałęzi. Wcześniejsza blokada pytań i
niedostępność środowiska opisują stan historyczny, nie aktualne przeszkody.

### Bieżący punkt wznowienia — po ukończeniu etapu 7

Etap 7 odebrano na kodzie `c08755da94e587c10e2ba9b7b5c5dd528e8f5bf9`:
[CI #24 — SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42401371870),
PHP 8.3.36 + MySQL 8.0.32, **391 testów / 15 091 asercji**. Zaliczone migracje
i schemat, aktualizacja wcześniejszych danych, PHPStan/lint/build, regresja Chrome,
rzeczywisty HTTP zmian/anulowania/korekt przez Nginx/PHP-FPM, restart z zachowaniem
danych i workery. Lokalne kontrole również PASS; szczegóły i ręczny odbiór w TEST_PLAN.

Kod i dokumentacja są w [PR #7](https://github.com/paweljrsk/aplikacja-urlopy/pull/7),
gałąź `codex/etap-7-zmiany-anulowania-korekty`, oparta na PR #6. Bez nowych bibliotek
ani migracji. Zachować B32–B33, rozliczenie różnic per rok i dozwolona pula, ochronę
obu terminów, granicę ewidencji i prywatność historii. Kontrakt: REQUESTS_API.

Etap 8 — formularz i widoki pracownika w stylistyce Notus — uruchomić dopiero na
kolejne polecenie użytkownika. PR-y pozostają niescalone; nie scalać automatycznie.
