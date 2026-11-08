# Plan testów i odbioru

## Domyślny miesiąc B39

Lokalnie lint, build, składnia dwóch skryptów przeglądarkowych i kontrola renderu
przeszły. Render startowy zaznacza „Miesiąc”, a zakres obejmuje pierwszy i ostatni
dzień miesiąca. Zaktualizowane regresje Chrome sprawdzają ten wybór i zakres API
na komputerze i telefonie oraz przełączanie na krótsze okresy. Lokalny Chromium
zatrzymał się przy starcie na `socket() EPERM`. Ręcznie otwórz kalendarz po
zalogowaniu i sprawdź, czy „Miesiąc” jest zaznaczony bez dodatkowego kliknięcia.

## Górny pasek B38

Lint, build i kontrola produkcyjnego HTML kalendarza i logowania przeszły. Ręczny odbiór polega na sprawdzeniu górnego paska po zalogowaniu i przejścia do kalendarza po kliknięciu logo.

## Pełny miesiąc w kalendarzu B37 — odbiór zakończony (8 listopada 2026 r.)

Baza: `e7165f265bed6063b110a04a3152805eb0926a93`, ukończona zmiana UX B36.
Lokalnie pełne ESLint, TypeScript i produkcyjny build — PASS. Sprawdzenie pomocników
dat: miesiące 28/29/30/31 dni, rok 2100 nieprzestępny, grudzień/styczeń, tydzień
zaczynający się w poprzednim miesiącu i granice lat API — PASS.
Kod `f63de212562a9c0566427a2a43b272658f89916a` przeszedł pełny
[CI #45 — SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42519955628).
[PR #13](https://github.com/paweljrsk/aplikacja-urlopy/pull/13) bazuje na niescalonym
PR #12. Kontenery projektu, PHP 8.3, MySQL 8, Node 22 i Chrome; dane syntetyczne.

| Kontrola | Wynik |
|---|---|
| Backend, statyczne i build | PASS: 445 testów / 21 607 asercji; Symfony, PHPStan, ESLint, TypeScript i produkcyjny Next.js |
| Miesiąc w Chrome | PASS: 28/29/30/31 dni, grudzień/styczeń, luty 2028, wybór listopada z tygodnia zaczynającego się w październiku, strzałki i Dzisiaj, weekend/święto, dokładne zakresy API i daty DOM |
| Kontekst kalendarza | PASS: zachowane zespół, osoba, status i miesiąc po zamknięciu szczegółów; lista nadchodzących dwóch miesięcy pozostaje niezależna; ponowny Miesiąc/Dzisiaj nie pozostawia spinnera |
| Telefon i zrzuty | PASS: 30 dni miesiąca na telefonie, brak poziomego przewijania dokumentu. Przejrzano miesięczną tabelę desktop, listę dni mobile i pełny widok mobilny z przełącznikiem trzech okresów; godziny/statusy pozostają czytelne |
| Regresje | PASS: dotychczasowe UI, wszystkie cztery przewodniki, rzeczywiste HTTP i E2E pracownika, przełożonego oraz pełnego procesu; restart z zachowaniem danych i workery |
| Skan, pomiary i audity | Skan źródeł/historii PASS; wszystkie 14 pomiarów w proponowanych budżetach diagnostycznych. Composer/produkcyjny npm: 0 zgłoszeń; pełny npm nadal 8 wpisów moderate z developerskiego minimatch, bez high/critical. S11-SEC-01 pozostaje otwarte |

Testy Chrome i backend wykonano w CI; lokalny Chromium ma znane ograniczenie
`socket() EPERM`. Telefon jest emulowanym viewportem, a pomiary dotyczą stosu
developerskiego, nie produkcyjnego SLA. Raporty i zrzuty są w artefaktach tego CI.
Nie wdrażano na serwer firmowy ani nie wykonywano backup/restore. Bez nowych
bibliotek, migracji i zmian zasad rozliczeń/API.

### Ręczny odbiór B37

Ręcznie: wybierz „Miesiąc”, sprawdź pierwszy/ostatni dzień, przejdź przez grudzień
do stycznia i do lutego, następnie wybierz „Dzisiaj”. Ustaw filtr osoby/statusu,
otwórz dozwolone szczegóły i zamknij je: miesiąc oraz filtry mają pozostać.
Na telefonie dostępna jest lista dni całego miesiąca. Ponowne kliknięcie aktywnego
okresu lub „Dzisiaj” nie może pozostawić pustego ekranu ładowania.
Etap 12 i asystent AI pozostają poza zakresem.

## Moduł zarządzania i przewodniki — odbiór zakończony (8 listopada 2026 r.)

Dodatkowe polecenie po etapie 11, B36; etap 12 pozostaje poza zakresem.
Baza `4882ba6751bbbd2846bf3be8a01acf87d5187db8`, CI #40 SUCCESS.
Sprawdzono osobne adresy zarządzania, własne saldo bez edycji dla wszystkich ról,
kompatybilność dawnych adresów oraz zachowanie kontroli API. Nowy
`module-tour-ui-check.cjs` obejmuje role, tutoriale, klawiaturę, telefon, utratę
uprawnień/sesji i brak zapisów. Istniejące testy operacji skierowano na nowe adresy;
test resetu cudzego hasła jest w panelu administratora, nie w osobistym koncie.
Kod `23dba5d7067b5bbace72581e9a9d070d717b3714` przeszedł pełny
[CI #43 — SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42510298991).
[PR #12 — zmiana UX](https://github.com/paweljrsk/aplikacja-urlopy/pull/12) pozostaje
niescalony i bazuje na PR #11; numer PR nie oznacza rozpoczęcia etapu 12.
Środowisko: kontenery projektu, PHP 8.3.36, MySQL 8.0.32, Node 22.23.4, Chrome 156,
wyłącznie dane syntetyczne.

| Kontrola | Faktyczny wynik |
|---|---|
| Inicjalizacja, migracje i schemat | PASS; bez nowych migracji ani resetowania istniejących danych |
| Backend | PASS: **445 testów / 21 607 asercji**, w tym rozliczenia, role i współbieżność MySQL |
| Statyczne i build | PASS: Symfony, PHPStan, ESLint, TypeScript oraz produkcyjny Next.js, 16 tras |
| Nowy moduł i tutoriale w Chrome | PASS: własne saldo bez edycji dla wszystkich ról; odmowa dostępu pracownika bez pobierania danych zarządczych; menu zgodne z rolą; stare adresy; pełne ścieżki pracownika, kierownika, zastępcy i administratora; Dalej/Wstecz, zakończenie/Escape, pułapka i powrót fokusu, telefon, brak celu podświetlenia, opóźnione otwarcie po nawigacji, utrata roli/sesji, zero zapisów i storage |
| Dotychczasowe UI | PASS: logowanie, konto, administracja i reset hasła, salda, firmowe dni wolne, kalendarz, formularz i decyzje; syntetyczne odpowiedzi API |
| Rzeczywiste HTTP i E2E | PASS przez Chrome/Nginx/PHP-FPM: sesje/CSRF, zapis pracownika, decyzje kierownika/zastępcy/administratora, korekty, pełny obieg zmiany/anulowania, licznik dni, transfer i podział roku |
| Restart i workery | PASS: zachowanie danych po stop/start, stabilne procesy async i scheduler; bez testu restore |
| Skan i audity zależności | Ograniczony skan źródeł/historii — PASS. Composer i produkcyjny npm: 0 zgłoszeń. Pełny npm: nadal 8 wpisów moderate z developerskiego minimatch, bez high/critical; S11-SEC-01 pozostaje otwarte |
| Pomiary | 100 osób / 5 zespołów / 2000 wniosków, wszystkie 14 przypadków w proponowanych budżetach diagnostycznych; najwyższe p95 API 284,8 ms, widoków 1467,5 ms |
| Zrzuty | Przejrzano osobny moduł desktop/mobile, własne saldo administratora, panel administracji i tutoriale pracownika desktop/mobile oraz administratora desktop; czytelne podświetlenie i treść kart. Test sprawdza też rozmiar karty w rzeczywistym viewportcie |

Artefakty CI zawierają `synthetic-ui-screenshots` oraz `stage11-check-reports`
z bieżącymi raportami auditów i pomiarów. CI #41 i #42 nie stanowią odbioru:
nowy test wymagał oczekiwania na przywrócenie fokusu przez requestAnimationFrame
oraz wskazania linku „Moje wnioski” w głównej nawigacji. Zachowano wszystkie asercje;
CI #43 potwierdza ich wykonanie po poprawkach.

Lokalnie: pełny ESLint, TypeScript i produkcyjny build (16 tras) — PASS; składnia
skryptów Node, workflow YAML i diff — PASS. Brak lokalnego PHP/MySQL; lokalny
Chromium zatrzymuje się na systemowym `socket() EPERM`, więc testy backendu/Chrome
wykonano w powyższym CI. Początkowy build wymagał objęcia odczytu
legacy `searchParams` w `/management` przez Suspense zgodnie z konfiguracją Next;
po poprawce build zakończył się powodzeniem.

Ograniczenia: telefon to emulowany viewport Chrome; pomiary są krótką sekwencyjną
próbą na stosie developerskim, bez produkcyjnego SLA. Nie wdrażano na serwer
firmowy i nie wykonywano backup/restore. Q7 i S11-SEC-01 pozostają zapisane
w dokumentacji etapu 11. Bez nowych bibliotek, zmian rozliczeń i reguł API.
Etap 12 pozostaje nierozpoczęty.

### Ręczny odbiór B36

1. Po aktualizacji bieżącej gałęzi otwórz `http://localhost:8080/`. Jako administrator
   wejdź w „Moje saldo”: widzisz własne godziny i historię, bez wyboru osoby i edycji.
2. Wybierz „Zarządzanie”. Sprawdź osobną nawigację, kolejkę decyzji, salda i limity,
   administrację oraz firmowe dni wolne. Reset hasła innej osoby jest przy jej koncie
   w administracji; „Moje konto” zawiera tylko własne sprawy.
3. Jako kierownik lub zastępca sprawdź, że moduł pokazuje wyłącznie dozwolone funkcje.
   Zastępca nie edytuje limitów, kierownik nie edytuje własnego limitu.
4. Wybierz „Przewodnik” i odpowiednią ścieżkę. Sprawdź podświetlenie, przejścia,
   „Wstecz”, „Dalej”, zakończenie i Escape; po zamknięciu fokus wraca do przycisku.
   Przewodnik można uruchomić ponownie. Nie wysyła wniosku ani decyzji.
5. Powtórz na telefonie oraz używając Tab/Shift+Tab. Stare `/admin`,
   `/calendar-settings` i `/management?id=…` powinny kierować do nowych adresów.

## Etap 11 — odbiór zakończony (8 listopada 2026 r.)

Punkt wyjścia: końcowy etap 10 `4191f2ca26ebd205e36c4d5c7fcb606cb91bb1a4`,
[CI #38 SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42418164212).
Użytkownik zlecił licznik dni roboczych (B35), następnie pełny odbiór systemu.

- [Mapa wymagań i scenariuszy T01–T23](STAGE11_COVERAGE.md).
- [Zakres bezpieczeństwa, skanów i pozostałe ustalenia](STAGE11_SECURITY.md).
- [Protokół pomiaru większego zestawu danych](STAGE11_PERFORMANCE.md), zapisany przed pomiarem.

Kod `f8bc8b37a2e645d817524bedaffa9a8881ae8221` przeszedł pełny
[CI #39 — SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42487633929).
Środowisko: PHP 8.3.36, MySQL 8.0.32, Node 22.23.4, Chrome 156; rzeczywiste kontenery
projektu i dane syntetyczne. [PR #11](https://github.com/paweljrsk/aplikacja-urlopy/pull/11)
bazuje na końcowym etapie 10 i pozostaje niescalony.

| Kontrola | Faktyczny wynik |
|---|---|
| `make init`, migracje, walidacja schematu i `make verify` | PASS, również zgodność istniejących danych; bez nowych migracji etapu 11 |
| `make test` | PASS: **445 testów / 21 607 asercji**; kalendarz, saldo, role, transfery, idempotencja i rzeczywista współbieżność MySQL |
| `make lint`, produkcyjny build frontendu | PASS; Symfony, PHPStan, ESLint, TypeScript i Next.js |
| Syntetyczne testy Chrome | PASS; logowanie, administracja, salda, kalendarz, wnioski i zarządzanie. Nowe regresje: licznik weekendów/świąt/dni firmowych, zero, opóźnienia i błąd z ponowieniem, XSS w historii oraz niepełne potwierdzenia zapisu |
| Rzeczywisty HTTP przez Nginx/PHP-FPM | PASS; sesja, CSRF, administracja, limity, cały obieg wniosków, korekty, konflikty, izolacja i wylogowanie |
| Rzeczywiste E2E pracownika i przełożonego | PASS; pełny zapis/podgląd/odczyt, decyzje wszystkich ról, odmowa własnej decyzji zastępcy, korekta 6 h → 3 h i audyt |
| Nowe E2E całego procesu | PASS; automatyczne 2 dni / 16 h, zatwierdzenie, zmniejszenie do 5 h i zwrot po decyzji, anulowanie, historia, niepłatne 3 h + 5 h oraz odmowa dziewiątej godziny, podział grudzień/styczeń i korekta po transferze zespołu bez ujawnienia bieżących pul |
| Sekrety i niebezpieczne operacje HTML/JS | PASS: 270 śledzonych plików i 552 historyczne bloby, ograniczony skaner 5 wzorców sekretów; zakres w raporcie bezpieczeństwa |
| Audity zależności | Composer: 0 zgłoszeń i 0 porzuconych pakietów; npm produkcyjny: 0 zgłoszeń; pełny npm: 8 wpisów moderate z jednego zgłoszenia developerskiego minimatch. Brak high/critical; **S11-SEC-01 pozostaje otwarte** |
| Pomiary | PASS funkcjonalny, bez ostrzeżeń budżetowych: 100 osób / 5 zespołów / 2000 wniosków. Sześć odczytów API i cztery widoki desktop/mobile; najwyższe p95 API 445,8 ms, widoku 1402,3 ms |
| Restart i workery | PASS: trwałość po stop/start oraz działające procesy w tle; nie jest to test restore kopii |
| Odbiór wizualny | Przejrzano zrzuty formularza pełnych dni na komputerze i godzin na telefonie: licznik widoczny pod datami, brak przycięcia treści licznika, czytelny stan niedostatecznego salda |

Lokalnie pełny ESLint, TypeScript, produkcyjny build, składnia Node/Python, YAML
workflow i diff — PASS. Brak lokalnego PHP/MySQL oraz `socket() EPERM` podczas
startu Chromium uniemożliwiały wykonanie tych kontroli lokalnie; wszystkie właściwe
testy PHP, Chrome i pomiary wykonano następnie w powyższym CI. Artefakty przebiegu
zawierają zrzuty UI i `stage11-check-reports` z surowymi raportami pomiarów/auditów.

Naprawiony problem E19: niepełne lub niespójne potwierdzenie HTTP 200/201 nie kończy
formularza fałszywym sukcesem; UI zachowuje oryginalną treść i klucz bezpiecznego
ponowienia. Regresje nie wykonują drugiego obciążenia ani zwrotu. B35 korzysta
z istniejącego kalendarza backendu niezależnie od dostępnego salda. Nie dodano bibliotek.

Granice odbioru: mobile jest emulowanym viewportem Chrome, pomiary są małą sekwencyjną
próbą na stosie developerskim, bez VPN i produkcyjnego SLA. Nie wykonano odbioru
firmowego serwera ani backup/restore. S11-SEC-01 i Q7 są zapisane jawnie; T20 w części
restore oraz przygotowanie produkcji pozostają etapem 12. Etap 12 nie został rozpoczęty.

### Ręczny odbiór zmiany i całego procesu

1. Zaktualizuj gałąź `codex/etap-11-testy-systemu` według README i otwórz `http://localhost:8080/`.
2. Po zalogowaniu wybierz „Złóż wniosek” i zakres 10–16 listopada 2026 r. Bez dodatkowych
   firmowych dni wolnych licznik powinien pokazać **4 dni (32 h)**: pomija 11 listopada oraz weekend.
3. Wybierz sam weekend 14–15 listopada: oczekiwane **0 dni (0 h)**. Zmień daty kilka razy;
   wcześniejsza odpowiedź nie może zastąpić wyniku nowego zakresu. Dla części dnia roboczego
   wybierz 3 h — licznik powinien pokazać **3 h**. Nie trzeba zatwierdzać formularza.
4. Złóż prawidłowy wniosek na syntetycznym koncie z limitem; zaakceptuj jako kierownik.
   Po zmniejszeniu wymiaru lub zgłoszeniu anulowania sprawdź, że zwrot pojawia się dopiero
   po akceptacji. Odśwież ekran i sprawdź trwałość salda oraz autora decyzji w historii.
5. Sprawdź modal na telefonie oraz role/transfer według mapy STAGE11_COVERAGE.
   Własny wniosek zastępcy musi pozostać niedostępny do własnej decyzji.

## Pierwotny plan i historyczne wyniki etapów

Wersja bazowa 1.2 • 6 listopada 2026 r.

Poniższe scenariusze określono przed implementacją. Sama ich obecność nie oznacza
zaliczenia testu; aktualne dowody i ograniczenia opisano w odbiorze etapu 11 powyżej.

## Sposób weryfikacji

- Jednostkowo: kalendarz, godziny, saldo, pule i reguły przejść.
- Integracyjnie na zgodnym MySQL: migracje, transakcje, idempotencja i współbieżność.
- Przez API: wszystkie role, własność obiektu, izolacja zespołów i pola odpowiedzi.
- E2E: logowanie oraz pełne procesy użytkownika, decyzji, zmian i korekt.
- Ręcznie: komputer/telefon, czytelność, klawiatura, stany błędów i instrukcje operacyjne.
- Z kontrolowanym zegarem i kalendarzem: przypadki graniczne dat i lat.

Wynik zapisujemy z komendą/scenariuszem, środowiskiem, wynikiem i ograniczeniem.
Brak narzędzia lub środowiska oznacza brak weryfikacji, a nie zaliczenie testu.
Do testów używamy danych syntetycznych i oddzielnej bazy. Testów migracji lub
współbieżności dla MySQL nie zastępuje wyłącznie test na innym silniku bazy.

## Wyniki etapu 1

### Kontrole lokalne — 6 listopada 2026 r.

Środowisko pomocnicze: Linux, PHP 8.4.27, Composer 2.11.1, Node 24.22.1,
npm 11.9.0. Docelowe PHP 8.3 i Node 22 są sprawdzane osobno w kontenerach CI.

| Kontrola | Wynik i ograniczenie |
|---|---|
| `composer validate --no-check-publish` | PASS; manifest i lockfile zgodne |
| `composer install` | PASS z lockfile, wraz z cache clear i assets install; lokalnie użyto źródeł Git z powodu ograniczeń pobierania archiwów. Po wyczerpaniu miejsca w lokalnym cache przeniesiono go na dysk roboczy i ponowiono instalację. CI sprawdza standardowe `--prefer-dist`. |
| `APP_ENV=dev php bin/phpunit` | PASS: 2 testy, 4 asercje; wymuszenie środowiska `test` działa pomimo odziedziczonego `dev` |
| `php vendor/bin/phpstan analyse --no-progress` | PASS, poziom 6; usunięto zawsze prawdziwy warunek w bootstrapie testów |
| Symfony `lint:yaml config`, `lint:container` | PASS, środowisko `test`; 9 plików YAML, zgodne typy usług |
| `php vendor/bin/php-cs-fixer fix --dry-run --diff` | PASS, 0 plików wymagających poprawy; wykonano na PHP 8.4, bez modyfikacji kodu |
| `npm ci`, `npm run lint`, `npm run build` | PASS; instalacja z lockfile, ESLint, TypeScript i prerendering |
| Frontend `next dev` oraz `next start` po buildzie | PASS; w obu trybach `GET /` zwrócił HTTP 200 |
| Compose config development i produkcji | PASS walidacji składni; produkcja z wartościami syntetycznymi, bez wdrożenia |
| Makefile: tworzenie `.env`, przerwanie po błędzie init, blokada komend destrukcyjnych | PASS kontroli w izolowanym środowisku ze sztucznym wykonawcą Compose; istniejący `.env` zachowany, 4 komendy destrukcyjne odrzucone bez potwierdzenia |
| Dokumentacja | PASS porównania z planem: 12 etapów i promptów, Q1–Q9, T01–T23, macierz uprawnień, zachowane instrukcje frontendu |

PHP MD jest zainstalowany w szkielecie, ale repozytorium nie ma uzgodnionego zestawu
jego reguł; nie przedstawiamy go jako wykonanej bramki jakości. Nie wykonywano
jeszcze testów biznesowych T01–T23 — odpowiadają przyszłym etapom.

### Kontrole Docker w CI

Pełny [przebieg CI #6](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42247971165)
na commicie `589386e8c27de3ef938abad7b79078a2c2089f15` zakończył się **SUCCESS**.
Środowisko: GitHub Actions Ubuntu 24.04, rzeczywiste kontenery z Dockerfile,
PHP 8.3.36, Node 22.13, MySQL 8.0.32. Raport i status zadań dopisano po weryfikacji.

| Kontrola | Wynik |
|---|---|
| `make init` | PASS; build obrazów, instalacje z lockfile, zdrowe usługi, Doctrine create-if-missing i migrate bez migracji biznesowych |
| `make verify` | PASS; Symfony uruchomiony, `SELECT 1`, odpowiedzi HTTP frontendu i `/api/health` |
| `make test` | PASS; PHPUnit 12.5.43, 2 testy / 4 asercje na PHP 8.3.36 |
| `make lint` | PASS; YAML, kontener Symfony, PHPStan, ESLint i TypeScript |
| `npm run build` w kontenerze Node | PASS; produkcyjny build Next.js 16.4.5 |
| `make stop`, `make start`, ponowne `make verify` | PASS; usługi i HTTP ponownie dostępne |
| Zachowanie danych MySQL | PASS; marker zapisany w osobnej syntetycznej bazie przed stop/start odczytano z identyczną wartością po starcie; baza kontrolna usunięta |
| `make workers` | PASS; procesy `async` i `scheduler_default` stabilne przez co najmniej 12 s bez restartu |
| Zakończenie kontroli | PASS; kontenery zatrzymane, standardowy cleanup bez `down -v` |

Pierwszy przebieg CI wykrył konflikt `APP_ENV`; drugi został zastąpiony nowym po
poprawce PHPStan. Nie stanowią odbioru. Powyższy pełny przebieg obejmuje obie poprawki.
Test workerów sprawdza ich start, a nie obsługę przyszłych wiadomości biznesowych.
Test zachowania danych dotyczy stop/start, nie odtwarzania backupu — backup/restore
pozostają w etapie 12. Nie wykonano wdrożenia ani testu na firmowym serwerze/VPN.

### Ręczny odbiór szkieletu

1. W katalogu projektu uruchom `make init`, następnie `make verify`.
2. Otwórz `http://localhost:8080/`: oczekiwany jest ekran startowy Next.js.
3. Otwórz `http://localhost:8080/api/health`: oczekiwane `{"status":"ok"}`.
4. Uruchom `make test` oraz `make lint`.
5. Wykonaj `make stop`, `make start`, `make verify`; aplikacja powinna odpowiadać ponownie.

## Wyniki etapu 2

Etap obejmuje podstawy [modelu danych](DATA_MODEL.md), migrację i zestaw
demonstracyjny. Testy encji i trwałości nie są odbiorem pełnych procesów T01–T23:
nie ma jeszcze logowania, autoryzacji, API wniosków ani usług wykonujących całe
rozliczenie w jednej transakcji. Poniższe wyniki odnoszą się do wskazanego zakresu
i środowiska, a nie do gotowości całego systemu.

### Kontrole lokalne — 6 listopada 2026 r.

Środowisko pomocnicze: PHP 8.4.27. Lokalnie nie ma działającego Dockera;
kontrole MySQL i docelowego zestawu kontenerów zapisano osobno poniżej.

| Kontrola | Wynik i ograniczenie |
|---|---|
| PHPUnit — testy jednostkowe modelu | PASS: 69 testów / 191 asercji; walidacja godzin, dat, wersji zasad i wniosków, stanu początkowego, zapisów rozliczenia, audytu i zajętości dnia. Bez potwierdzenia zapisu do MySQL. |
| PHPUnit — dodatkowy `MigrationPreflightTest` | PASS: 3 testy / 12 asercji; kolizja tabeli aplikacji przerywa migrację przed zakolejkowaniem SQL, pusty `LazySchema` dopuszcza DDL, obca tabela nie zmienia planu migracji. Te 3 testy są już uwzględnione w powyższej sumie 69; nie wykonują DDL na MySQL. |
| `php vendor/bin/phpstan analyse --no-progress` | PASS: poziom 6, pełny skonfigurowany zakres, w tym migracje; rozszerzenie `phpstan/phpstan-doctrine`. |
| `composer validate --no-check-publish` | PASS; manifest i lockfile zgodne. |
| `php bin/console doctrine:schema:validate --skip-sync` | PASS mapowania Doctrine; `--skip-sync` nie sprawdza zgodności z rzeczywistą bazą. |
| Symfony `lint:container` | PASS; zgodne definicje i typy usług. |
| `php vendor/bin/php-cs-fixer fix --dry-run --diff` | PASS; kontrola stylu bez modyfikowania kodu. |
| `git diff --check` | PASS; brak błędów formatowania zmian. |

### Kontrole Docker w CI — przebiegi etapu 2

Kontrole uruchomiono w GitHub Actions na rzeczywistym zestawie kontenerów projektu
z PHP 8.3 i MySQL 8.0.32. Bazę developerską i bazę z sufiksem `_test` przygotowują
oddzielne migracje; testy używają wyłącznie syntetycznych danych.

| Przebieg | Wynik i zakres potwierdzony |
|---|---|
| [CI #9](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42291369803) | FAIL przed DDL: preflight migracji wywoływał niedostępną metodę DBAL. Poprawka używa `hasTable()` i obejmuje migracje analizą PHPStan; opis E05 w [DECISIONS](DECISIONS.md#e05--kontrola-istniejących-tabel-przed-migracją). Ten przebieg nie zalicza migracji. |
| [CI #10](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42291936845), commit `575be…` | FAIL całego przebiegu. PASS: `make init`, migracje obu baz, ponowienie migracji, walidacja schematu przez CLI i dwukrotne ładowanie demo. PHPUnit wykonał 78 testów / 384 asercje: 77 testów przeszło, 1 nie przeszedł. |
| [CI #11](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42292576627), commit `2917abae9a06ee92b3185062f667f2460cdd801c` | **SUCCESS**. Pełny przebieg: 81 testów / 397 asercji na PHP 8.3.36, migracje, schemat, demo, lint, build, zachowanie danych po restarcie i start workerów. |

Pełny odbiór kodu etapu 2 potwierdza **CI #11** na powyższym commicie.

| Kontrola | Wynik |
|---|---|
| `make init`, `make verify` | PASS; usługi zdrowe, odpowiedzi HTTP oraz połączenie MySQL. |
| Migracje w bazach development/test | PASS; po jednej migracji, 77 poleceń DDL, bez resetu danych. |
| Ponowne `make db-migrate`, `make db-validate` | PASS; brak migracji do wykonania, schemat zgodny z mapowaniem. |
| `make demo` dwukrotnie | PASS; pierwszy przebieg tworzy 5 użytkowników / 2 zespoły / 2 wnioski, drugi pozostawia istniejący zestaw bez zmian. |
| `make test` | PASS: 81 testów / 397 asercji, w tym 69 jednostkowych, 10 integracyjnych na MySQL oraz 2 dotychczasowe testy kernela/API. |
| `make lint` | PASS; YAML, kontener Symfony, PHPStan 6 wraz z migracjami, ESLint i TypeScript. |
| Produkcyjny build frontendu | PASS w kontenerze Node 22.13. |
| Stop/start i ponowne `verify`/`demo` | PASS; marker kontrolny oraz zestaw demonstracyjny zachowane. |
| `make workers` | PASS; oba procesy stabilne przez co najmniej 12 s. |
| Zakończenie kontroli | PASS; standardowe zatrzymanie kontenerów bez usuwania wolumenów. |

Jedyną porażką PHPUnit w CI #10 był test bezpośredniego `SchemaValidator`, który
uwzględniał tabelę metadanych migracji. Standardowe CLI uruchamia filtr tej tabeli
przez zdarzenie `console.command`; bezpośrednie wywołanie walidatora omijało to
zdarzenie. Test po poprawce korzysta ze standardowego polecenia konsoli. Szczegóły
zapisano w [E06](DECISIONS.md#e06--walidacja-schematu-w-teście-integracyjnym).
Poprawiony test przeszedł w pełnym CI #11.

Testy integracyjne potwierdzone również w pełnym CI #11 sprawdziły zapis i odczyt historii przypisań,
wersji zasad oraz decyzji, UTC niezależnie od strefy procesu PHP, początek ewidencji
i lata pochodzenia pul, a także ograniczenia unikalności, klucze obce i `CHECK`.
Sprawdziły również konkurujące zapisy przypisań, kont/pul, decyzji i zajętości dnia
oraz wycofanie przegrywającej transakcji. To dowód działania mechanizmów modelu
i blokad optymistycznych w tych przypadkach; nie potwierdza jeszcze autoryzacji,
pełnej idempotencji API, wyboru najstarszej puli ani całych scenariuszy T02/T03/T23.

### Ręczny odbiór modelu, migracji i demo

Poniższa procedura jest instrukcją odbioru, a nie zapisem dodatkowego wykonanego
testu na komputerze użytkownika lub firmowym serwerze.

1. Po pobraniu gałęzi etapu 2 zachowaj istniejący `.env` i wolumeny. Wykonaj
   `make init`, `make verify` i `make db-validate`. Oczekiwane: usługi gotowe,
   migracja ukończona i zgodność mapowania ze schematem.
2. Wykonaj ponownie `make db-migrate`, następnie `make db-validate`.
   Oczekiwane: brak kolejnej migracji do wykonania i brak zmiany istniejących danych.
3. Wykonaj `make test`. Polecenie automatycznie uruchamia `make test-prepare`,
   przygotowując oddzielną bazę `_test` przez migracje, bez resetu. Oczekiwany jest
   poprawny wynik całego PHPUnit; błąd wymaga sprawdzenia raportu.
4. Opcjonalnie, na bazie `dev` z pustymi tabelami aplikacji, wykonaj `make demo`
   dwa razy. Pierwszy przebieg powinien dodać zestaw syntetyczny, drugi rozpoznać go
   i pozostawić dane bez zmian. Nie usuwaj istniejących danych, aby umożliwić demo.
   Polecenie wymaga flagi `--confirm-demo` przekazywanej przez Makefile i działa
   tylko w `dev`/`test`. Nie tworzy haseł logowania.
5. Otwórz `http://localhost:8080/` i `http://localhost:8080/api/health`.
   Oczekiwane są nadal ekran startowy Next.js i `{"status":"ok"}`. Ekranów
   pracowników, wniosków i salda nie odbieramy w tym etapie.

W razie błędu startu lub migracji zachowaj dane i logi. Nie używaj resetu bazy,
`down -v` ani cofania migracji do usunięcia historii. Nie wykonano odbioru na
firmowym serwerze/VPN ani próby odtworzenia backupu; te prace pozostają w etapie 12.

## Wyniki etapu 3

Zakres opisuje [AUTHENTICATION.md](AUTHENTICATION.md): lokalne logowanie i sesja,
CSRF, hasła tymczasowe, wymuszona zmiana, reset przez administratora, bootstrap
i odzyskanie dostępu przez CLI oraz kontrola bieżących profili w API. Polityka
własnej decyzji kierownika/zastępcy jest podstawą autoryzacji; etap nie implementuje
API wykonywania decyzji urlopowych ani historycznej widoczności po przeniesieniu
(pozostała część Q5). Nie jest to odbiór całego procesu T12/T13/T19.

### Kontrole lokalne — 6 listopada 2026 r.

Środowisko pomocnicze: PHP 8.4.27 i Node 24.22.1. Nie ma lokalnego demona Docker;
docelowe PHP 8.3, Node 22 i MySQL 8.0.32 sprawdza osobny przebieg CI.

| Kontrola | Wynik i ograniczenie |
|---|---|
| `php bin/phpunit tests/Unit` | PASS: 102 testy / 325 asercji. Obejmuje model oraz politykę bieżących uprawnień, porównanie tożsamości, limity czasu sesji, politykę haseł i odmowy operacji CLI. Nie potwierdza jeszcze przepływu HTTP z MySQL. |
| `php vendor/bin/phpstan analyse --no-progress` | PASS; poziom 6 w pełnym skonfigurowanym zakresie backendu, także migracje i nowe testy. |
| Symfony `lint:container` | PASS; zgodność usług i zależności. |
| `php bin/console doctrine:schema:validate --skip-sync` | PASS mapowania; zgodność z żywą bazą potwierdza osobny CI poniżej. |
| `php vendor/bin/php-cs-fixer fix --dry-run --diff` | PASS; kontrola stylu backendu. |
| Frontend `npm run lint`, `npx --no-install tsc --noEmit`, `npm run build` | PASS; ESLint, typowanie i produkcyjny build. Nie zastępuje przeglądarkowego wykonania logowania. |

### Kontrole Docker, HTTP i przeglądarki — CI #14

Pełny [CI #14](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42305357024)
na commicie `2f039b7c1a02bf9b888f2a01cc1fa5e24054d0f2` zakończył się **SUCCESS**.
Środowisko: kontenery projektu na GitHub Actions Ubuntu 24.04, PHP 8.3.36,
Node 22.13, MySQL 8.0.32 oraz Chrome na hoście CI.

Poprzedni [CI #13](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42304653446)
zaliczył backend, migrację, lint i build, ale zatrzymał się na pierwszej asercji
adresu w skrypcie przeglądarkowym. Skrypt oczekiwał `/login`, podczas gdy aplikacja
przekierowała na `/login?expired=1`. Poprawka sprawdza dokładny origin i ścieżkę,
a dla udanej zmiany hasła nadal wymaga parametru `changed=1`. Nie wyłączono kontroli
przekierowań ani pozostałych asercji. Cały poprawiony przebieg jest zaliczony w CI #14.

| Kontrola | Wynik i zakres |
|---|---|
| Start, HTTP, migracje, zgodność schematu, dwukrotne demo | PASS; rzeczywisty Docker i MySQL, bez resetu bazy. |
| Migracja etapu 2 → 3 | PASS; syntetyczne konto zapisane przed migracją zachowało imię, nazwisko i brak hasła; nowe kolumny mają bezpieczne wartości domyślne. |
| `make test` | PASS: **156 testów / 3061 asercji** — 102 jednostkowe, 10 integracyjnych modelu, 42 funkcjonalne uwierzytelniania oraz 2 dotychczasowe testy kernela/health. |
| `tests/Functional/AuthTest.php` | PASS: rzeczywisty kernel Symfony i MySQL, bez skrótu `loginUser()`; wszystkie role, izolacja zespołów i profili, logowanie, CSRF, wymuszona zmiana, logout, reset, wygaśnięcie i unieważnianie sesji oraz limity prób. Dezaktywacja i ponowna aktywacja przed użyciem starego cookie nie przywracają sesji. |
| `tests/Support/credentials-cli-check.php` | PASS na oddzielnej bazie `_test`: rzeczywiste interaktywne komendy bootstrap i recovery, odmowa powtórnego bootstrapu, nowe hasło i wersja poświadczeń, ważność 24 h, odmowa bez potwierdzenia/dla pracownika oraz audyt bez haseł i ich skrótów. |
| `.github/scripts/auth-ui-check.cjs` | PASS w Chrome: przekierowania, błędy i czyszczenie haseł, wymuszona zmiana, walidacja powtórzenia, reset administratora, zamknięcie jednorazowego sekretu, CSRF, brak zapisu w browser storage, szerokości desktop/mobile i brak wyjątków JavaScript. API jest tu jawnie symulowane. |
| `.github/scripts/auth-http-check.cjs` | PASS przez rzeczywisty Nginx/PHP-FPM: bootstrap syntetycznego administratora, CSRF i cookie, zachowanie sesji między żądaniami, blokada funkcji przed zmianą hasła, zmiana, odrzucenie starego cookie, ponowne logowanie, profil/lista admina i logout. |
| `make lint`, produkcyjny build frontendu | PASS: YAML, kontener Symfony, PHPStan, ESLint, TypeScript i build Next.js. |
| Stop/start, ponowne verify/demo, marker MySQL | PASS; dane zachowane po restarcie. |
| Messenger/Scheduler | PASS; oba procesy stabilne przez co najmniej 12 s. |

Testy używają wyłącznie danych syntetycznych. Skrypt CLI odmawia zapisu poza bazą
`_test` i w bazie z już zainicjalizowanym administratorem. Test przez Nginx wymaga
`CI=true`, lokalnego adresu i środowiska `dev`; działa po ostatnim sprawdzeniu demo.
Nie resetują danych i nie wypisują wygenerowanych haseł ani odpowiedzi z sekretami.

Skrypt przeglądarkowy symuluje API, a rzeczywisty serwer jest sprawdzany osobnym
klientem HTTP. Nie przedstawiamy tego jako jednego pełnego testu E2E przeglądarki
z backendem. Lokalna próba Chrome została zablokowana przez `socket() EPERM`;
wykonanie przeglądarkowe potwierdza wskazany CI. Produkcja, TLS/VPN, komputer
użytkownika i odtworzenie kopii pozostają poza tym odbiorem. Testy jednostkowe
używają kontrolowanego zegara bez przestawiania zegara serwera.

### Ręczny odbiór logowania i dostępu

Poniższe kroki są instrukcją dla lokalnego środowiska z danymi syntetycznymi,
nie zapisem już wykonanego testu na komputerze użytkownika.

1. Przy aktualizacji zatrzymaj projekt przez `make stop`, wybierz gałąź etapu 3
   i wykonaj `make init`, `make verify`, `make db-validate`. Zachowaj `.env` i wolumeny.
2. Jeśli potrzebujesz kont wszystkich ról i dwóch zespołów, przed utworzeniem
   administratora załaduj `make demo` wyłącznie do pustych tabel aplikacji.
   Istniejącego zestawu nie resetuj. Konta demo nie mają wspólnych haseł.
3. Wykonaj interaktywnie `docker compose exec php php bin/console app:admin:bootstrap`.
   Utwórz administratora albo potwierdź inicjalizację istniejącego administratora demo
   bez hasła. Oczekiwane: losowe hasło tymczasowe na 24 h, bez wpisywania go w argumentach.
4. Otwórz `/login`. Sprawdź błędne hasło, potem poprawne tymczasowe. Oczekiwane:
   ogólny komunikat błędu, a po zalogowaniu obowiązkowa zmiana hasła; zwykły ekran
   i API profilu są zablokowane do jej zakończenia. Ustaw własne hasło 15–128 znaków
   i zaloguj się ponownie. Sprawdź, że poprzednie hasło już nie działa.
5. Otwórz konto w dwóch odrębnych profilach przeglądarki. Zmiana hasła w jednym
   kończy jego sesję oraz odbiera dostęp drugiemu przy następnym żądaniu. Oddzielnie
   sprawdź zwykły logout: wylogowany profil otrzymuje 401 przy `/api/auth/me`, a sam
   przycisk „Wstecz” nie przywraca uprawnień. Zwykły logout kończy bieżącą sesję.
6. Jako administrator wybierz inne aktywne konto i wykonaj reset. Błędne własne
   hasło ma zablokować operację; poprawne zwraca nowe hasło tymczasowe i jego ważność.
   Zamknięcie panelu lub przejście do innej karty usuwa sekret z widoku. Stare sesje
   pracownika tracą dostęp, a nowe hasło wymusza zmianę. Własne hasło administrator
   zmienia formularzem profilu, nie przyciskiem resetu konta.
7. Na kontach syntetycznych sprawdź `GET /api/users/{id}/profile` bezpośrednio
   w przeglądarce lub narzędziu API z daną sesją: pracownik własny profil 200,
   cudzy 403; kierownik/zastępca profil własnego zespołu 200, innego 403;
   administrator profil innego zespołu 200. Lista `/api/admin/users` i reset kont
   są niedostępne pozostałym rolom. Identyfikatory weź z lokalnych danych, nie zakładaj stałych ID.
8. Ponowny bootstrap nie powinien nadpisywać administratora. Odzyskanie jego dostępu
   sprawdź na koncie testowym przez `app:admin:recover`: jawne potwierdzenie, nowe hasło
   tymczasowe i utrata starych sesji. Komenda nie tworzy ani nie aktywuje konta.
9. Uruchom `make test` i `make lint`. Kontrole dezaktywacji, zmiany roli/przypisania
   oraz granic czasowych wykonują testy na izolowanych danych; panel tych zmian
   powstaje w etapie 4. Hasła z odbioru nie mogą trafić do raportu, repozytorium ani logów.

## Najważniejsze scenariusze odbioru

**T01–T23 stanowią kryteria odbioru całych procesów.** Faktycznie wykonane podzbiory
i granice kontroli opisano przy poszczególnych etapach; sam test modelu lub profilu
użytkownika nie zalicza całego scenariusza wniosków, kalendarza czy rozliczeń.

| ID | Scenariusz | Oczekiwany rezultat |
|---|---|---|
| T01 | Limit 80 h, oczekujący wniosek 16 h, akceptacja, upływ terminu | Dostępne 64 h w każdym kroku po złożeniu; brak podwójnego potrącenia |
| T02 | Dostępne 8 h, dwa równoczesne wnioski po 6 h na różne dni robocze | Tylko jeden może zarezerwować godziny; sprawdzenie salda niezależne od limitu dziennego |
| T03 | Ponowna akceptacja lub równoczesne akceptacja/odrzucenie | Jedna skuteczna decyzja i jedno rozliczenie |
| T04 | Zatwierdzone 16 h, propozycja 24 h | Dodatkowa rezerwacja 8 h; odrzucenie zwalnia tylko te 8 h |
| T05 | Zatwierdzone 16 h, propozycja 8 h albo anulowania | Zwrot dopiero po akceptacji zmiany/anulowania |
| T06 | Zatwierdzone 8 h w grudniu, proponowane 8 h w styczniu | Kontrola puli nowego roku, zachowanie starego urlopu do decyzji |
| T07 | 16 h starej puli, nowy limit 80 h | UoP 96 h, B2B 80 h; ponowne rozliczenie nie dodaje kolejnych 16 h |
| T08 | Zakres z jednym dniem roboczym grudnia i jednym stycznia | 8 h w każdym roku; święta/weekendy nie obciążają limitu |
| T09 | Domyślnie cały dzień, zmiana na 3 h | Dokładnie 3 h rezerwacji; brak wymyślonej pory nieobecności |
| T10 | Wnioski 3 h + 5 h, następnie kolejna 1 h tego dnia | Pierwsze dwa dopuszczalne; trzeci zablokowany niezależnie od rodzaju |
| T11 | 0 h, −1 h, 1,5 h albo 9 h w jednym dniu | Walidacja w backendzie odrzuca dane |
| T12 | Własny wniosek kierownika i własny zastępcy | Kierownik może zaakceptować; zastępca nie, także przez bezpośrednie API |
| T13 | Odczyt innego zespołu lub prywatnych pól współpracownika | Odmowa / brak ujawnienia danych zgodnie z rolą |
| T14 | Pracownik próbuje zmienić przeszły wniosek | Odmowa; uprawniona obsługa zgodna z Q2 |
| T15 | Zmiana płatnego 8 h na nieobciążający rodzaj i odwrotnie | Zwrot po akceptacji; w drugą stronę wymagana rezerwacja 8 h |
| T16 | Korekta starego roku po przeniesieniu | Spójna korekta pochodzącej stamtąd puli, bez podwójnego zwrotu; niedobór według Q4 |
| T17 | Wniosek sprzed daty odniesienia danych początkowych | Obsługa według Q8, bez automatycznego potrącenia z już pomniejszonego salda i bez zgadywania historii z agregatu |
| T18 | Wylogowanie, dezaktywacja lub zmiana uprawnień | Sesja nie daje dalszego niedozwolonego dostępu |
| T19 | Zmiana rodzaju/formy/zespołu z istniejącą historią | Wynik zgodny z Q5; brak niejawnego przeliczenia lub ujawnienia historii |
| T20 | Restart i odtworzenie kopii na odizolowanej bazie | Wnioski, salda i audyt zachowane i zweryfikowane |
| T21 | Oczekująca zmiana 8 h poniedziałek → 8 h wtorek albo 3 h → 5 h tego samego dnia | Ochrona obu dni w pierwszym przypadku; zajęte 5 h, nie 8 h, w drugim; zwolnienie starego terminu dopiero po decyzji |
| T22 | Dodanie/usunięcie dnia wolnego z istniejącymi wnioskami | Kontrolowany wpływ na godziny, saldo i historię zgodnie z Q9 |
| T23 | Dwa równoczesne wnioski po 6 h na ten sam dzień, także nieobciążające salda | Tylko jeden może przejść; suma aktywnych nieobecności nie przekracza 8 h |

## Etap 4 — odbiór zakończony, 7 listopada 2026 r.

Zakres: administracja kontami, przypisaniami, zespołami i wersjonowanymi słownikami,
Q5 oraz aktualizacja istniejącej bazy. Testy UI ze sztucznym API i rzeczywistego
HTTP są osobnymi kontrolami.

Wykonane lokalnie na PHP 8.4.24 i MySQL **8.0.32**, w odizolowanej bazie syntetycznej:

- pełny PHPUnit: **198 testów / 4599 asercji — OK**;
- PHPStan (poziom 6), mapowanie Doctrine, lint kontenera — OK;
- migracje 1–3 na MySQL — OK;
- frontend: ESLint, TypeScript, build produkcyjny — OK.

Nowe testy obejmują rzeczywiste logowanie i wymuszoną zmianę hasła utworzonego
konta; odmowę administracji pracownikowi/kierownikowi/zastępcy; CSRF; walidację
i wycofanie nieudanych zapisów; konflikty wersji; aktywację/dezaktywację i zmianę
globalnych uprawnień wraz z unieważnieniem sesji; ostatniego administratora;
pierwsze przypisanie konta bootstrap; wersje zasad i historyczne nazwy;
powtórzenie inicjalizacji katalogu; zachowanie wniosków i akceptacji po transferze.

Testy polityki Q5 obejmują granicę dnia Europe/Warsaw, przyszłe zaplanowane
przeniesienie, wnioski przekraczające granicę zespołów, przeszłość i kolejne
przeniesienia, brak przypisania, role oraz propozycję zmiany wraz z wersją zastępowaną.
Nie są odbiorem nieistniejącego jeszcze procesu wniosków.

Lokalny Chromium nie wystartował z powodu ograniczenia socketów środowiska.
Testów przeglądarkowych nie zaliczono lokalnie; wykonano je później w Chrome na CI,
łącznie z kontrolą PHP 8.3, migracją historycznych słowników, rzeczywistym HTTP
przez Nginx/PHP-FPM, restartem i workerami. Wyniki końcowe są poniżej.

### Odbiór ręczny etapu 4

1. Zaloguj się administratorem i otwórz Administrację.
2. Na pustej bazie dodaj słowniki startowe, zespół, pracownika i jego przypisanie.
3. Zaloguj się nowym kontem w osobnej przeglądarce, zmień hasło tymczasowe i
   sprawdź odmowę dostępu do `/admin` oraz API administracji.
4. Zmień nazwę zespołu i aktywność pozycji, sprawdź filtrowanie.
5. Zmień rodzaj nieobecności i jego dostępność; wcześniejsza wersja pozostaje w bazie.
6. Dodaj datowaną zmianę przypisania i sprawdź historię okresów.
7. Otwórz dwa formularze tego samego rekordu; po zapisie jednego drugi powinien
   pokazać konflikt i wymagać odświeżenia.
8. Sprawdź ochronę ostatniego administratora; konto z samym hasłem tymczasowym
   nie jest wystarczającym zastępstwem.

Nie używaj danych produkcyjnych do testów ani `make demo` na bazie z własnymi kontami.

### Dodatkowa weryfikacja lokalna

- Migracja nowej izolowanej bazy do etapu 3, zapis historycznych słowników, migracja
  etapu 4 i sprawdzenie starych nazw/aktywności/reguł/dostępności/wersji — **OK**.
  Ponowne migracje bez zmian i pełne `doctrine:schema:validate` — **OK**.
- Rzeczywisty HTTP na wbudowanym serwerze PHP 8.4.24 i MySQL 8.0.32 — **OK**:
  bootstrap, CSRF, cookie, wymuszona zmiana, wylogowanie, inicjalizacja słowników,
  tworzenie zespołu i konta, edycja, konflikt wersji oraz odmowa administracji
  pracownikowi. To sprawdzenie nie zastępuje Nginx/PHP-FPM w Dockerze.
- Dodatkowy test rzeczywistego komponentu React w DOM (scratch-only jsdom/esbuild)
  — **OK**: payloady/CSRF, tworzenie, filtrowanie, konflikty, aktywność, przypisania,
  wersje słowników, prywatne dane po odebraniu uprawnień, czyszczenie haseł.
  Nie jest to test wizualny ani wykonanie Chrome.

### Końcowy odbiór w Dockerze i Chrome

Commit implementacji: `4b1f4f42fd02c7550d17974f1dee6de465f4008d`.
[CI #16 — SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42349188537),
7 listopada 2026 r., PHP **8.3.36**, MySQL **8.0.32**.

| Kontrola | Wynik |
|---|---|
| Uruchomienie kontenerów i HTTP/baza | PASS |
| Migracje świeżej bazy, ponowienie, zgodność schematu | PASS |
| Migracja istniejących kont etapu 2 i słowników etapu 3 | PASS — zachowane nazwy, aktywność, reguły, dostępność i wersje |
| Jawne demo uruchomione dwukrotnie | PASS — bez duplikacji |
| PHPUnit | **198 testów / 4599 asercji — PASS** |
| Rzeczywiste komendy bootstrap i recovery administratora | PASS |
| PHPStan, YAML/container lint, ESLint, TypeScript | PASS |
| Produkcyjny build frontendu | PASS |
| Chrome: logowanie oraz administracja ze sztucznym API | PASS — uprawnienia, formularze, CSRF, wersje, filtry, hasła, przypisania, słowniki, desktop/mobile |
| Restart i zachowanie danych | PASS |
| HTTP przez Nginx/PHP-FPM | PASS — sesja, hasła, słowniki/konta, konflikty i odmowa administracji pracownikowi |
| Messenger i Scheduler | PASS — stabilne procesy |
| Zrzuty ekranu 1366 px / 390 px | Przejrzane: czytelne formularze/listy, brak poziomego przepełnienia; układ roboczy |

Zrzuty `stage4-users-desktop.png` i `stage4-dictionary-mobile.png` są w artefakcie
`synthetic-ui-screenshots` przebiegu CI (retencja 7 dni). Korzystają z danych
syntetycznych. Widoczne znaczniki developerskie Next i zachowanie elementów
przy zrzucie całej przewiniętej strony nie są elementem projektu produkcyjnego.

Test przeglądarkowy ze sztucznym API oraz rzeczywiste testy backendu/HTTP są
oddzielnymi kontrolami — nie deklarujemy pełnego przeglądarkowego E2E wszystkich
procesów urlopowych. Wnioski, kalendarz, limity i saldo należą do kolejnych etapów.
Końcowy styl Notus pozostaje w etapie 8; wdrożenie produkcyjne w etapie 12.


## Etap 5 — lokalna weryfikacja

7 listopada 2026 r. Zweryfikowano lokalnie na PHP 8.4.24 i MySQL 8.0.32:

- pełny PHPUnit po końcowych poprawkach: 268 testów / 6832 asercje;
- PHPStan, lint YAML i kontenera Symfony;
- migrację od schematu etapu 3 przez etap 4 z syntetycznymi danymi początkowymi,
  pulami, rezerwacją, wnioskiem i dniem firmowym do etapu 5; zachowanie wszystkich
  wcześniejszych kolumn, bezpieczny stan limitu i powtórzenie migracji;
- zgodność końcowego schematu z mapowaniem Doctrine;
- rzeczywiste HTTP z MySQL przez lokalny serwer PHP: logowanie, CSRF, administracja,
  dane początkowe, idempotencja, limit, konflikt wersji, jawny niedobór, korekta,
  podgląd i zmiana kalendarza, odczyt własnego salda i odmowa cudzych danych/zapisów.

Osobne testy obejmują blokadę współbieżnego zapisu na drugim połączeniu MySQL
oraz nieaktualną wersję wniosku w drugim EntityManager po równoczesnej akceptacji.
Testy dat i przełomu roku korzystają z kontrolowanego zegara.
Dodatkowe regresje potwierdzają, że późniejsze przejście B2B → UoP nie przywraca
starej puli B2B, a jawnie importowana zaległość nie wymaga fikcyjnych przypisań.
ESLint, TypeScript, build frontendu i scenariusze rzeczywistych komponentów React
w lokalnym DOM również przeszły; wynik Chrome zapisano poniżej przy odbiorze CI.

Lokalny serwer PHP nie zastępuje Nginx/PHP-FPM ani docelowego PHP 8.3. Pełny odbiór
Docker, Chrome, build, restart i workerów znajduje się w raporcie CI #20 poniżej.


### Pierwszy odbiór Docker etapu 5 — CI #18

[CI #18](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42356593584)
na `8b4db305a943d361036e22234e480f3cc7e0316f` zakończyło się błędem jednego testu
kalendarza przy odczycie dat po transferze. Uruchomienie, migracje i zachowanie
starych danych przeszły. Przyczynę i poprawkę porównywania SQL DATE niezależnie
od domyślnej strefy PHP opisuje E08 w DECISIONS. Testów późniejszych kroków tego
przebiegu nie oznaczamy jako wykonanych.

### Drugi odbiór Docker etapu 5 — CI #19

[CI #19](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42356996669)
na `bc55fe2d33d716614bb62c4e56e239e35689f79b` zaliczyło migracje i pełny PHPUnit
268 testów / 6832 asercje. Następnie PHPStan przekroczył domyślny limit PHP 128 MB.
Polecenie `make lint` przydziela teraz wyłącznie PHPStan limit 512 MB, zgodny
z lokalną weryfikacją; limit procesów aplikacji pozostaje bez zmian.
Build i testy Chrome/HTTP tego przebiegu zostały pominięte po błędzie analizy.

### Końcowy odbiór Docker i Chrome etapu 5 — CI #20

Commit implementacji: `c526ab8e2eda42ab029ba24a1f49ec6d5ff58701`.
[CI #20 — SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42357495900),
7 listopada 2026 r., PHP **8.3.36**, MySQL **8.0.32**.

| Kontrola | Wynik |
|---|---|
| Start kontenerów, HTTP i baza | PASS |
| Migracje świeżej bazy, powtórzenie, zgodność schematu | PASS |
| Aktualizacja danych etapów 2–4 | PASS — zachowane konta, słowniki, limity, otwarcie, księgowania, wnioski i kalendarz; bezpieczne uzupełnienie konfiguracji limitów i zajętości |
| Dwukrotne jawne demo | PASS — bez duplikacji |
| PHPUnit | **268 testów / 6832 asercje — PASS** |
| CLI administratora | PASS |
| PHPStan, lint YAML/kontenera, ESLint, TypeScript | PASS — PHPStan z limitem 512 MB |
| Produkcyjny build frontendu | PASS |
| Chrome: logowanie i administracja | PASS — regresje wcześniejszych etapów |
| Chrome: limity, saldo i kalendarz | PASS — zakres osób i ról, pełne godziny, 26 dni = 208 h, dane początkowe, korekty aktualnych wartości, limit 0/brak, konflikt i ponowienie, podgląd/potwierdzenie kalendarza, wycofanie nieaktualnego podglądu, odebranie uprawnień |
| Restart bez utraty danych | PASS |
| HTTP przez Nginx/PHP-FPM | PASS — sesja/CSRF, administracja, limit/otwarcie/korekty/niedobór, konflikty, kalendarz i izolacja pracowników |
| Messenger i Scheduler | PASS |
| Zrzuty desktop 1366 px / mobile 390 px | Przejrzane — czytelne formularze, przyciski w obrębie ekranu, tabele przewijane we własnych sekcjach |

Zrzuty `balance-desktop.png`, `balance-mobile.png` i `calendar-preview-mobile.png`
są w artefakcie `synthetic-ui-screenshots` przebiegu (retencja 7 dni). Przedstawiają
sztuczne odpowiedzi API przeznaczone do sprawdzania interakcji i układu; nie są
raportem poprawności obliczeń. Poprawność rozliczeń sprawdzono osobno na MySQL.
Znaczniki developerskie Next i widoczność linku przejścia do treści przy zrzucie
pełnej przewiniętej strony nie są projektem docelowego interfejsu.

Testy przeglądarkowe ze sztucznym API oraz rzeczywiste testy backendu/HTTP to
oddzielne kontrole. Nie deklarujemy pełnego E2E procesu urlopowego: publiczny
formularz wniosków i decyzje należą do etapu 6, indywidualne zmiany do etapu 7,
pełna stylistyka Notus do etapu 8, a główny kalendarz zespołu do etapu 9.
Nie dodano nowych bibliotek. Lokalne wykonanie Chrome nie było możliwe ze względu
na ograniczenie AF_UNIX środowiska; powyższe wykonanie Chrome pochodzi z CI.

### Ręczny odbiór etapu 5

Po aktualizacji zgodnie z README zaloguj się dotychczasowym administratorem.
Scenariusze wykonuj na syntetycznym pracowniku, bez zmieniania rzeczywistych sald:

1. Na `/balances` wybierz pracownika UoP i bieżący rok. Wpisz dane początkowe:
   26 dni limitu, 40 h wykorzystania bieżącej puli i 16 h pozostałości poprzedniego
   roku. Wynik: 208 h limitu, 184 h dostępnych, czyli 23 dni.
2. Zmień limit na 24 dni z uzasadnieniem. Saldo powinno wynosić 168 h. Sprawdź
   historię oraz zachowanie danych po odświeżeniu. Korekta bez powodu jest odrzucana.
3. Ustaw w przyszłym roku limit 0. Widok powinien odróżniać go od kolejnego roku
   bez konfiguracji; nie pokazuje przewidywanej zaległości przed początkiem roku.
4. Sprawdź role: pracownik widzi tylko własne saldo, zastępca ma odczyt zespołu,
   a kierownik może zmieniać limit innych osób swojego zespołu, ale nie własny.
5. Na `/calendar-settings` dodaj przyszły firmowy dzień wolny: podgląd, powód,
   potwierdzenie. Odśwież listę. Zmiana formularza unieważnia wcześniejszy podgląd.
6. Sprawdź obie strony przy szerokości telefonu i na komputerze.

Tworzenie i obsługa wniosków nie mają jeszcze publicznego formularza. Rezerwacje,
przełom roku, niedobory, korekty kalendarza na istniejących wnioskach i współbieżność
są na tym etapie sprawdzane testami usług/API na syntetycznych danych.

## Etap 6 — lokalna weryfikacja

7 listopada 2026 r., PHP **8.4.24**, MySQL **8.0.32**, domyślna strefa PHP `UTC`
i kontrolowane zegary testowe w Europe/Warsaw.

- Pełny PHPUnit: **337 testów / 10 662 asercje — PASS**.
- Nowy publiczny workflow: 38 testów / 1386 asercji; API: 13 / 2179.
- Współbieżność: 6 testów / 248 asercji. Niezależne procesy PHP i połączenia MySQL
  oczekują na rzeczywistej blokadzie, następnie konkurują o 8 h salda lub zajętości,
  decyzję albo to samo ponowienie. Nie są to mocki kolejności operacji.
- Testy modelu po rozszerzeniu kontekstu: 27 / 71; ścisłe wejście: 8 / 8.
- PHPStan, lint YAML/kontenera Symfony i składnia skryptu HTTP — PASS.
- Rzeczywisty HTTP przez lokalny serwer PHP i świeży MySQL — PASS: logowanie,
  administracja, saldo/kalendarz, złożenie, atomowa edycja, wycofanie, akceptacja,
  odrzucenie, obowiązkowe uzasadnienie, ponowienia, nieaktualna wersja, brak drugiego
  potrącenia i limit 8 h także dla niepłatnej nieobecności.

Regresja E09 w DECISIONS najpierw potwierdziła niedozwolone utworzenie niedoboru
przy przeniesieniu godzin ze starej puli UoP na nowy termin B2B. Po poprawce testy
potwierdzają zarówno odmowę tej operacji, jak i zachowanie dopuszczalnych przypadków.
Testy porównują potwierdzenia jako mapy o tych samych wartościach i typach — MySQL
może inaczej uporządkować klucze JSON; kolejność pól obiektu nie jest częścią API.

Lokalny serwer PHP nie zastępuje Nginx/PHP-FPM ani docelowego PHP 8.3. Pełny
Docker CI opisano poniżej. Etap 6 nie zmienia frontendu; dotychczasowe testy Chrome
pozostają regresją ekranów z poprzednich etapów.
Nie dodano bibliotek ani migracji. Publiczne zapisy korzystają z dotychczasowego
schematu, a uruchomienie nie resetuje danych.

### Końcowy odbiór Docker i regresji Chrome etapu 6 — CI #22

7 listopada 2026 r., commit `58f30102b85567952f5967663ad8f04028d79384`,
[CI #22 — SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42360588801).
Docelowy PHP **8.3.36**, MySQL **8.0.32**. Wszystkie wymagane kroki zakończone sukcesem.

| Kontrola | Wynik |
|---|---|
| Inicjalizacja stosu, HTTP, ponowienie migracji i schemat Doctrine | PASS; zgodny schemat, bez nowej migracji etapu 6 |
| Dwukrotne demo i próby aktualizacji wcześniejszych danych | PASS; brak duplikacji, zachowana historia |
| CLI administratora i pełny PHPUnit | PASS; **337 testów / 10 662 asercje**, w tym procesy konkurujące o saldo i decyzje |
| PHPStan, lint Symfony, ESLint/TypeScript i produkcyjny build | PASS |
| Chrome: logowanie, administracja, salda i kalendarz firmowy | PASS; syntetyczne odpowiedzi API, regresja istniejących ekranów desktop/mobile |
| Rzeczywiste API przez Nginx/PHP-FPM i MySQL | PASS; sesje/CSRF, administracja, rozliczenia oraz cały nowy proces wniosków, ponowienia i konflikty |
| Stop/start z kontrolą znacznika zachowania danych | PASS; wolumeny zachowane |
| Messenger i Scheduler | PASS; start procesów w tle |

Testy Chrome nie obejmują formularza wniosków, który powstaje w etapie 8.
Nowy proces jest sprawdzony przez rzeczywiste API oraz testy funkcjonalne,
integracyjne i współbieżności. Nie weryfikowano jeszcze środowiska produkcyjnego
ani uruchomienia na komputerze użytkownika. Zrzuty regresji są artefaktem
`synthetic-ui-screenshots` przebiegu CI; retencja 7 dni.

### Ręczny odbiór API etapu 6

Kontrakt i przykłady JSON są w [REQUESTS_API.md](REQUESTS_API.md). Używaj
syntetycznego pracownika; dane początkowe i przydział można ustawić w `/balances`.
Pełny formularz składania wniosków powstaje w etapie 8.

1. Po zalogowaniu sprawdź `GET /api/requests/options` oraz `GET /api/requests`.
   Wywołania zapisu wymagają tej samej sesji i `X-CSRF-Token` z `/api/auth/csrf`.
2. Przy 80 h dostępnych złóż wniosek na dwa kolejne dni robocze. Powinien mieć
   `pending`, 16 h i pozostawić 64 h. Ponów identyczny JSON z tym samym kluczem:
   odpowiedź powinna potwierdzić ten sam wniosek bez kolejnego potrącenia.
3. Jako uprawniony przełożony zaakceptuj go z odczytaną `expectedVersion`.
   Dostępne pozostaje 64 h. Nieaktualna nowa operacja ma dać konflikt.
4. Na innym dniu utwórz oczekujące 3 h, zmień na 5 h, następnie wycofaj.
   Po wycofaniu dostępne wraca do 64 h, a wszystkie wersje pozostają w historii.
5. Odrzuć kolejny wniosek: puste uzasadnienie jest odrzucane, prawidłowy powód
   widzi właściciel w szczegółach. Rezerwacja zostaje zwolniona.
6. Sprawdź własny wniosek na dzisiaj oraz odmowę wcześniejszej daty. Pracownik
   nie może zaakceptować własnego wniosku ani odczytać cudzych szczegółów.

Automatyczne odtworzenie: `make test`. Skrypt CI `auth-http-check.cjs` wykonuje
rozszerzony przebieg HTTP na jednorazowej bazie CI; nie uruchamiaj go na swoich
kontach i danych użytkowych.

## Etap 7 — lokalna weryfikacja

7 listopada 2026 r. Zatwierdzone B32–B33 zapisano przed implementacją. Bez nowych
bibliotek i migracji. Frontend bez zmian; formularz i stylistyka Notus pozostają
w etapie 8. Pełny lokalny PHPUnit po końcowych poprawkach: **391 testów / 15 091 asercji — PASS**.
PHPStan, lint YAML i kontenera Symfony oraz składnia skryptu HTTP — PASS.
Pełny odbiór Docker CI #24 zakończył się sukcesem; szczegóły poniżej.

Wykonane kontrole lokalne: PHP 8.4.24, MySQL 8.0.32, domyślne UTC i kontrolowany
czas Europe/Warsaw. Pierwsza grupa rozliczeń i workflow: 67 testów / 3047 asercji PASS;
rzeczywista współbieżność procesów: 6 / 264 PASS. Pełny pierwszy przebieg 387 testów
wykrył dwie rozbieżności opisane w E10; nie jest zaliczeniem pełnego odbioru.
Po poprawce odczytu uzasadnień 21 testów API / 4625 asercji PASS. Następnie dodano
4 regresje granicy ewidencji starszych danych oraz sprawdzono flagi działań po
korekcie historycznego urlopu do przyszłości. Całość ponowiono: 391 / 15 091 PASS,
43,6 s i 226,5 MB w lokalnym środowisku. Wyniki czasu/pamięci dotyczą testów, nie
wydajności aplikacji produkcyjnej.

Rzeczywiste HTTP na świeżej lokalnej bazie i serwerze PHP: PASS. Obejmuje stare
logowanie/administrację/rozliczenia, przesunięcie 16→24 h z rezerwacją tylko 8 h,
rozdzielenie wersji obowiązującej i oczekującej, ochronę starego dnia, odrzucenie
zmiany, skrócenie 16→8 h i zwrot po decyzji, anulowanie z jednokrotnym zwrotem,
podgląd bez zapisu oraz bezpośrednią korektę historyczną z jawnym niedoborem
administratora, ponowienie i anulowanie korekty. To nie zastępuje Nginx/PHP-FPM
w Docker CI. Skrypt używa wyłącznie syntetycznych danych w jednorazowej bazie.

### Końcowy odbiór Docker i regresji Chrome etapu 7 — CI #24

7 listopada 2026 r., kod `c08755da94e587c10e2ba9b7b5c5dd528e8f5bf9`,
[CI #24 — SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42401371870).
Docelowy PHP **8.3.36**, MySQL **8.0.32**. Wszystkie wymagane kroki zakończone sukcesem.

| Kontrola | Wynik |
|---|---|
| Inicjalizacja, HTTP, powtórzenie migracji i zgodność schematu Doctrine | PASS; bez nowej migracji etapu 7 |
| Dwukrotne demo, aktualizacja wcześniejszych danych i CLI administratora | PASS; dane i historia zachowane |
| Pełny PHPUnit, w tym współbieżność na rzeczywistych procesach i MySQL | PASS; **391 testów / 15 091 asercji** |
| PHPStan, lint Symfony, ESLint/TypeScript i produkcyjny build | PASS |
| Chrome: logowanie, administracja, saldo i kalendarz firmowy | PASS; syntetyczne odpowiedzi API, regresja desktop/mobile |
| Rzeczywiste API przez Nginx/PHP-FPM | PASS; propozycje, decyzje, przesunięte rezerwacje, zwroty, anulowanie, podgląd i korekta historyczna z niedoborem, ponowienia i izolacja |
| Stop/start z kontrolą znacznika zachowania danych | PASS; dane zachowane |
| Messenger i Scheduler | PASS; oba procesy stabilne |

Formularz wniosków i stylistyka Notus pozostają w etapie 8; testy Chrome obejmują
dotychczasowe ekrany. Nowe operacje sprawdzono przez rzeczywiste API i testy
funkcjonalne/integracyjne. Zrzuty są artefaktem `synthetic-ui-screenshots` przebiegu
CI (retencja 7 dni). Nie weryfikowano środowiska produkcyjnego ani uruchomienia
na komputerze użytkownika. Późniejszy zapis tego raportu zmienia tylko dokumentację.

### Ręczny odbiór API etapu 7

Przykłady i kontrakt są w [REQUESTS_API.md](REQUESTS_API.md). Wszystkie wywołania
zapisu wymagają sesji i tokenu CSRF; w etapie 7 nie dodano formularzy frontendu.

1. Ustaw syntetycznemu pracownikowi 80 h i zaakceptuj urlop 16 h. Dostępne: 64 h.
2. Przez `/change` zaproponuj 24 h, także na innym terminie tego samego roku.
   Dostępne: 56 h; szczegóły pokazują `effective` 16 h i `pending` 24 h. Oba
   terminy zajęte do decyzji. Odrzucenie zwraca tylko dodatkowe 8 h.
3. Zaproponuj skrócenie do 8 h. Dostępne pozostaje 64 h, po akceptacji 72 h.
4. Zgłoś `/cancel`. Saldo nie zmienia się do decyzji. Akceptacja zwraca końcowe
   8 h; powtórzenie tego samego klucza nie zwiększa salda ponownie.
5. Dla syntetycznego historycznego wniosku uprawniona osoba wykonuje
   `/correction-preview`, sprawdza skutki i potwierdza przez `/correct` z identycznymi
   danymi, tokenem oraz `confirm:true`. Podgląd nie zapisuje zmian. Zmiana danych
   po podglądzie wymaga ponowienia; sam pracownik ani zastępca dla własnego urlopu
   nie może wykonać korekty. Kierownik może skorygować własny wniosek.
6. Gdy korekta zwiększa historyczny niedobór, administrator musi dodatkowo
   potwierdzić `allowDeficit:true`. Pozostałe urlopy i pochodzenie godzin pozostają
   zachowane. Ten wyjątek nie finansuje nowych przyszłych godzin ani nie pozwala
   przekroczyć 8 h dziennie lub ponownie rozliczyć okresu sprzed początku ewidencji.

Automatyczne odtworzenie: `make test`; pełny stos sprawdza workflow GitHub Actions.
Nie uruchamiać skryptu HTTP CI na rzeczywistych kontach i danych użytkowych.

## Etap 8 — weryfikacja i zakres odbioru

7 listopada 2026 r. PHP 8.4.24 + MySQL 8.0.32, UTC i kontrolowany czas Warszawy:
**420 testów / 18 015 asercji — PASS**, 83,8 s, 268,5 MB. Nowe 19 testów preview
(1634 asercje) i 10 testów kalendarza (1290 asercji) przeszły też osobno.
PHPStan pełnego backendu, ESLint, TypeScript i produkcyjny build Next — PASS.
Bez nowych zależności aplikacji i migracji. Fonty oraz ich licencje zapisano lokalnie.

Nowe kontrole backendu obejmują rollback całego podglądu, 8→3 h, 16→24 h,
przełom roku, saldo, limit 8 h, nieaktualne wersje, CSRF, odwołany dostęp,
ograniczony kalendarz, transfery, ukryte propozycje, 102 wpisy bez obcinania i święta.

**[CI #29 — SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42409969383)**
na kodzie `95bf3874336b5d47e978d6756f248ecdf25e7fb7`, PHP 8.3.36 + MySQL 8.0.32:
**420 testów / 18 015 asercji**, 33,2 s, 62,5 MB. Migracje i aktualizacja danych,
PHPStan, lint YAML/container, ESLint, TypeScript, build Next, restart zachowujący
dane i oba workery — PASS. Nie są to deklaracje na podstawie samego odczytu kodu.

Regresje Chrome wcześniejszych ekranów oraz nowy scenariusz pracownika — PASS:
8→3 h, podgląd bez zapisu, Tab/Shift+Tab/Escape i powrót fokusu, wyśrodkowany
dialog, `?new=1`, podwójne kliknięcie i utrata odpowiedzi po zapisie, stabilny klucz
ponowienia, edycja/wycofanie, wersja obowiązująca i proponowana 16→24 h,
anulowanie bez zwrotu przed decyzją, walidacja, konflikt i ponowny odczyt,
kalendarz 7/14 dni i dwa miesiące, przełom roku, prywatność oraz cofnięcie sesji.
Te scenariusze UI używają syntetycznych odpowiedzi API.

Osobny rzeczywisty E2E Chrome → Nginx → PHP-FPM → MySQL również PASS:
logowanie pracownika, formularz 8→3 h, rollback podglądu, rezerwacja 3 h i odczyt
po odświeżeniu, akceptacja w oddzielnej sesji przełożonego przez API, widoczny
zaakceptowany urlop, propozycja 5 h z rezerwacją wyłącznie dodatkowych 2 h oraz
wycofanie propozycji zachowujące pierwotne 3 h. Dotychczasowy rzeczywisty HTTP
wniosków, rozliczeń, korekt, autoryzacji i administracji też PASS.

Odbiór wizualny zrzutów 1440 px / 390 px — PASS: kalendarz, lista, długie nazwiska,
przełom roku, szczegóły wersji, formularz i walidacja; brak obcięć i nakładania.
Artefakt `synthetic-ui-screenshots` zawiera 17 zrzutów, w tym 8 nowych ekranów.
CI #25 ujawnił wyścig testowej sesji (E11), CI #27 wyjście fokusu (E12), a kontrola
zrzutów po zielonym CI #28 pozycję dialogu po resecie CSS (E13). Poprawki są objęte
zielonym CI #29; nie pominięto ani nie osłabiono wcześniejszych asercji.

### Ręczny odbiór etapu 8

1. Zaktualizuj projekt według README, zachowując dane. Zaloguj się syntetycznym
   pracownikiem z przypisaniem i skonfigurowanym saldem.
2. Na ekranie głównym sprawdź kalendarz dwóch tygodni, na telefonie jednego,
   przełącznik okresu, strzałki i „Dzisiaj”. Lista poniżej pokazuje kolejne dwa
   miesiące. Współpracownik nie widzi cudzego rodzaju nieobecności ani salda.
3. „Złóż wniosek” otwiera krótki dialog. Wybierz przyszły dzień roboczy: podgląd
   powinien pokazać 8 h. Zmień na „Część dnia” i 3 h; podgląd nie zmienia salda.
4. Wyślij. W „Moich wnioskach” odśwież stronę, otwórz szczegóły i sprawdź 3 h
   oczekującej rezerwacji. Edytuj na 5 h lub wycofaj i sprawdź zmianę salda.
5. Po zatwierdzeniu przez istniejące API przełożonego sprawdź zaakceptowany status,
   zgłoś zmianę terminu/wymiaru i porównaj obowiązującą oraz proponowaną wersję.
   Anulowanie pozostawia dotychczasowy urlop i saldo do decyzji.
6. Przejdź Tab/Shift+Tab przez dialog, zamknij Escape (zmieniony formularz wymaga
   potwierdzenia zamknięcia), sprawdź powrót fokusu. Sprawdź telefon oraz komunikaty
   braku salda, konfliktu, wygasłej sesji i bezpiecznego ponowienia po utracie odpowiedzi.

Kolejka decyzji i formularze korekt przełożonego pozostają w etapie 10.

## Etap 9 — raport odbioru

Lokalny `TeamCalendarApiTest`: **12 testów / 1625 asercji — PASS**, 49,3 s,
PHP 8.4.24 + MySQL 8.0.32, UTC. Dwa nowe testy łączą wskazówkę kalendarza z faktycznym
odczytem szczegółów dla wszystkich ról oraz sprawdzają odebranie dostępu po
przeniesieniu pracownika o północy mimo nadal aktywnej sesji kierownika.
Uzupełniono kontrolę ukrytej propozycji i mieszanej historii zespołów.
PHPStan zmienionego testu, ESLint, TypeScript i produkcyjny build — PASS.
Kod produkcyjny backendu, schemat i zależności pozostają bez zmian.

Pełny [CI #32 — SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42412836879)
dla kodu `345eab527d808cf57f31994e3aac3d552e2c1e0c`, PHP 8.3.36 + MySQL 8.0.32:

- **422 testy / 18 350 asercji**; migracje i zgodność schematu, powtarzalne demo.
- PHPStan, lint YAML/Twig, ESLint, produkcyjny build i TypeScript — PASS.
- Chrome: dotychczasowe logowanie, administracja, saldo, formularze i kalendarz — PASS.
- Nowy `calendar-ui-check.cjs`: wspólne filtry obu zakresów, semantyka zatwierdzenia,
  powiązane wersje bez sumowania, niewidoczna propozycja, puste wyniki/reset,
  osoby z przyszłych/historycznych wpisów, zachowanie okresu i filtrów — PASS.
- Odczyt dla pracownika/kierownika/zastępcy/admina, brak prywatnych odczytów kolegów,
  odmowa 403, świeża autoryzacja po focus, historyczny zakres, zmiana zespołu,
  klawiatura, mobile i cofnięta sesja — PASS.
- Kontrolowana kolejność odpowiedzi: błąd kalendarza pozostaje widoczny po wolniejszym
  sukcesie `/requests/options`; kolejne ponowienie przywraca kalendarz — PASS.
- Rzeczywisty HTTP i proces wniosku przez Chrome/Nginx/PHP-FPM, restart z zachowaniem
  danych oraz workery Messenger/Scheduler — PASS.

Zrzuty `calendar-filters-desktop/mobile` i `calendar-details-desktop/mobile`
sprawdzono wizualnie: czytelne oznaczenia, zawijanie długich nazw, osobne wymiary
wersji, wyśrodkowany dialog desktop i pełnoekranowy mobile; bez poziomego przepełnienia.
Sprawdzono również podstawowy widok telefonu. Dane i odpowiedzi w regresji UI są
syntetyczne; rzeczywista polityka dostępu jest osobno sprawdzana przez PHP/MySQL.

Pierwszy CI #31 zatrzymał się na selektorze nowego testu: `getByLabel(..., exact)`
nie pasował do etykiety zawierającej opcje. Zmieniono go na stabilne identyfikatory
pól, zachowując wszystkie asercje danych. CI #32 zaliczył cały przebieg.

### Ręczny odbiór etapu 9

1. Na kalendarzu wybierz osobę i status. Sprawdź, że zawężają również listę
   kolejnych dwóch miesięcy. Przesuń okres, a następnie wyczyść filtry.
2. Wybierz „Zatwierdzone”: urlop oczekujący na zmianę lub anulowanie nadal obowiązuje.
   „Oczekujące” pokazuje nowe wnioski, „Zmiany i anulowania” odpowiednie procesy.
3. Przy zmianie terminu sprawdź jedną pozycję listy z osobno opisanymi terminami
   i wymiarami. Dwa niezależne wnioski 3 h + 5 h pozostają osobnymi pozycjami.
4. Jako przełożony otwórz dozwolone szczegóły i historię, zamknij okno i sprawdź
   zachowanie okresu, filtrów i fokusu. Zwykły współpracownik nie ma tego przycisku.
5. Na telefonie sprawdź filtry, listę dni i pełnoekranowe szczegóły. Administrator
   po zmianie zespołu rozpoczyna z wyczyszczonymi filtrami.

## Etap 10 — raport odbioru

Lokalne wyniki PHP 8.4.24 + MySQL 8.0.32, UTC:

- Nowy `DecisionPreviewApiTest` oraz istniejące preview/changes: **39 testów / 6205 asercji — PASS**.
- `BalanceApiTest` i `AdminTest`: **26 testów / 2378 asercji — PASS**.
- PHPStan zmienionych plików, składnia, TypeScript, ESLint i produkcyjny build — PASS.
- Nowa izolowana fixture rzeczywistego procesu przełożonego: uruchomienie na MySQL — PASS.

Sprawdzono rollback rzeczywistych podglądów, decyzje i wszystkie role, widoczność
historii po transferze, brak ujawnienia bieżącego salda, granice lat, wersje,
idempotencję i konkurencyjną decyzję; historię administracji z zagnieżdżonymi
markerami sekretów. Nie dodano migracji ani zależności.
Pierwsze przebiegi CI #34–36 zaliczyły **437 testów / 20 849 asercji** na PHP 8.3.36
i MySQL 8.0.32, kontrole statyczne oraz build. CI #34–35 wykryły mobilne przepełnienie
od ukrytej etykiety tabeli (E16). CI #36 zaliczył poprawkę, administrację i wszystkie
wcześniejsze ekrany, po czym wykrył utratę fokusu w nowym dialogu (E18).
Nie oznaczamy tych przebiegów jako pełnego odbioru. Podczas przeglądu dodano także
ochronę przed spóźnionym podglądem po utracie dostępu (E17) i osobne regresje.

Pełny [CI #37 — SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42417402132)
dla kodu `ed777e033e1a04341a87157bf4efad864897f096`, PHP 8.3.36 + MySQL 8.0.32:

- **437 testów / 20 849 asercji**; migracje, zgodność schematu, powtarzalne demo — PASS.
- PHPStan, lint YAML/Twig, ESLint, TypeScript i produkcyjny build — PASS.
- Chrome: wcześniejsze ekrany logowania, administracji, rozliczeń, pracownika i kalendarza — PASS.
- Salda zespołu: zakres ról, rok i wyszukiwanie, brak limitu a zero, przejście do osoby,
  mobilne przewijanie; edytory z podglądem, potwierdzeniem, konfliktem i ponowieniem — PASS.
- Historia administracji: autor/czas/dozwolone zmiany, stronicowanie, błędy/ponowienie,
  odświeżenie i odebranie dostępu; bez sekretów w odczycie i UI — PASS.
- Nowy `managed-requests-ui-check.cjs`: wszystkie role, własna decyzja kierownika,
  zakaz własnej zastępcy, drugi zespół admina, porównanie wersji, powód odrzucenia,
  podgląd i jawne potwierdzenie, historia autora/decydującego, korekty i niedobór — PASS.
- Dwie decyzje/stara wersja, niepełne potwierdzenie 200, utrata odpowiedzi,
  podwójne kliknięcie, ten sam klucz ponowienia, pusta strona z kursorem — PASS.
- Ponowna autoryzacja bez utraty roboczej korekty, spóźnione podglądy po 403,
  brak starych danych i akcji, klawiatura i mobile — PASS.
- Rzeczywisty HTTP przez Nginx/PHP-FPM oraz dotychczasowy E2E pracownika — PASS.
- Nowy `managed-requests-e2e-check.cjs`, bez przechwytywania API: kierownik akceptuje
  własne 3 h; zastępca dostaje 403 dla własnego wniosku i akceptuje cudze 5 h;
  administrator zatwierdza 4 h w drugim zespole. Podglądy nie zapisują zmian,
  korekta historyczna 6 h → 3 h zwraca dokładnie 3 h, rewizje i audyt są trwałe — PASS.
- Restart z zachowaniem danych i stabilne workery Messenger/Scheduler — PASS.

Zrzuty kolejki i szczegółów desktop/mobile, decyzji desktop, korekty mobile,
historii administracji oraz mobilnych sald sprawdzono wizualnie. Dialog decyzji
jest wyśrodkowany, korekta telefonu wypełnia ekran i przewija treść; przyciski
potwierdzenia pozostają czytelne. Tabela przewija się we własnym panelu.
Regresje ekranów używają danych syntetycznych; dodatkowe E2E wykonują prawdziwe
zapisy przez API i MySQL. To odbiór etapu 10; pełny audyt systemu jest zakresem
osobno zlecanego etapu 11, konfiguracja produkcji etapu 12.

Ręczny odbiór jest opisany w README, sekcja etapu 10. PR #10 pozostaje niescalony.

## Dodatkowe pentesty przed etapem 12 — raport odbioru badania

Raport ustaleń i dowodów: [PENTEST_PRE_STAGE12](PENTEST_PRE_STAGE12.md).
Nowe testy obejmują koszt odrzuconego loginu, nagłówki, duży snapshot z odległym
duplikatem, obcy iframe i negatywny rzeczywisty HTTP z kontrolą braku zapisów.
Do CI dołączono pasywny ZAP oraz skan faktycznie uruchomionych obrazów Trivy.
[CI #54 SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42527488779),
kod `44bdd6d76ae51933cbcfe529ac63104c2abc1ac2`:

- Backend: **458 testów / 21 703 asercje**, migracje, schemat i zachowanie danych — PASS.
- Statyczne kontrole, produkcyjny build, wszystkie UI, rzeczywiste HTTP/E2E,
  reprezentatywne pomiary i workery — PASS.
- Dodatkowy harness: **13 grup, 181 żądań / 3203 asercje** — PASS. Obejmuje m.in.
  IDOR, role, CSRF, walidację, brak skutków odmów, sesje/reset/dezaktywację i limiter.
- Rzeczywista blokada obcego iframe: Chrome `ERR_BLOCKED_BY_RESPONSE`, komunikat
  `frame-ancestors 'none'`, formularz niewyrenderowany w ramce — PASS.
- Pełne i produkcyjne npm audit: zero zgłoszeń. Composer: bez advisory i abandoned.
- Trivy ukończył sześć skanów, ale pozostawił zgłoszenia; PHP zlib retest potwierdza
  usunięcie trzech naprawionych CVE. ZAP: 14 typów / 57 wystąpień, WARNINGS, zero high.
  Wynik wykonania skanerów nie oznacza czystych obrazów ani odbioru produkcji.

Dowody: [ci54-summary.json](security-evidence/ci54-summary.json). Brak lokalnego
PHP/Dockera i blokada Chromium oznaczają, że zaliczenie tych kontroli pochodzi z CI.
Nie badano firmowego środowiska. PT-07, EOL i konfiguracja produkcyjna pozostają
otwarte w raporcie; wcześniejsze CI #51–53 nie stanowiły końcowego odbioru.
