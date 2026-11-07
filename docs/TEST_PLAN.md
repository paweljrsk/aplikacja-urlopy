# Plan testów i odbioru

Wersja 1.2 • 6 listopada 2026 r.

Poniższe scenariusze są kryteriami przyszłej implementacji. Ich obecność w tym
pliku nie oznacza wykonania ani zaliczenia testu. Testy dodajemy wraz z funkcją;
etap 11 scala weryfikację całego systemu.

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
