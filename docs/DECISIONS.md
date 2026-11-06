# Rejestr decyzji i problemów

Stan początkowy: 6 listopada 2026 r.

Decyzje biznesowe poniżej wynikają z uzgodnień użytkownika zapisanych w planie
wersji 1.0. Szczegółowe wymagania są w [REQUIREMENTS.md](REQUIREMENTS.md).
Odpowiedzi do pytań Q1–Q9 nie zostały jeszcze udzielone.

## Uzgodnione decyzje biznesowe

| ID | Decyzja | Konsekwencja |
|---|---|---|
| B01 | Wszyscy członkowie zespołu procesują wnioski w nowej aplikacji | Obsługujemy UoP, B2B i inne formy; formalne rozliczenia UoP pozostają w kadrach |
| B02 | Na start około 10 osób i jeden zespół, z możliwością rozbudowy | Użytkownik ma jeden zespół, model i API uwzględniają wiele zespołów |
| B03 | Lokalny login e-mail/hasło, konta administratora, wymuszona zmiana hasła tymczasowego | Brak samodzielnej rejestracji; M365 poza MVP |
| B04 | Slack poza MVP | Brak zależności procesu decyzji od notyfikacji Slack |
| B05 | Trzy rodzaje: płatne dni wolne, bezpłatne dni wolne, inna nieobecność | Tylko pierwszy obciąża wspólny limit; wszystkie wymagają akceptacji |
| B06 | Administrator zarządza słownikiem rodzajów i dostępnością dla form zatrudnienia | Bez osobnych limitów dla dodatkowych rodzajów; wpływ zmian na historię wymaga Q5 |
| B07 | 8 h dziennie, poniedziałek–piątek, polskie dni wolne | Rozliczenia w pełnych godzinach, lokalne daty Europe/Warsaw |
| B08 | Domyślnie cały dzień, możliwość zmniejszenia liczby godzin | Bez połówek jako osobnego trybu, bez godzin od–do; zakres wielodniowy wymaga Q1 |
| B09 | Administrator i kierownik ustawiają limity na konkretny rok | Osobno limit, wykorzystanie początkowe i zaległość; własny limit kierownika wymaga Q6 |
| B10 | Oczekujący wniosek rezerwuje saldo; brak salda blokuje wniosek obciążający limit | Akceptacja nie potrąca godzin drugi raz, odrzucenie/wycofanie zwalnia rezerwację |
| B11 | Niewykorzystane dni UoP przechodzą i wykorzystujemy najstarsze najpierw | Bez automatycznego wygaszenia; pozostałe formy nie przenoszą dni |
| B12 | Kierownik zatwierdza własne wnioski, zastępca swoich nie zatwierdza | Zastępca stale może obsługiwać cudze wnioski zespołu; administrator ma pełne uprawnienia |
| B13 | Zmiana/anulowanie zaakceptowanego urlopu wymagają decyzji | Do decyzji obowiązuje stary urlop; odrzucenie zmiany zachowuje jego skutki |
| B14 | Nieobecności rozpoczęte/przeszłe obsługują kierownik, zastępca i administrator | Tryb korekty i dzisiejsze wnioski wymagają Q2 |
| B15 | Współpracownik widzi nazwisko, daty, status i godziny, bez cudzego salda i rodzaju | API ogranicza udostępniane pola; własne i uprawnione szczegóły osobno |
| B16 | Kalendarz jest ekranem głównym; stylistyka Notus, komputer i telefon | Responsywna aplikacja webowa; PWA opcjonalnie później |
| B17 | Własny serwer Docker, dostęp VPN; repozytorium może pozostać publiczne | Sekrety, rzeczywiste dane i backupy poza repozytorium |

## Ustalony sposób realizacji

| ID | Zasada | Status |
|---|---|---|
| P01 | Realizacja kolejnych etapów w istniejącym repozytorium i standardzie stacku | Obowiązuje |
| P02 | Przed zależną implementacją wyjaśniamy luki; propozycja nie jest odpowiedzią | Obowiązuje |
| P03 | Schemat przez Doctrine Migrations; reguły i uprawnienia w backendzie | Obowiązuje |
| P04 | Testy i dokumentacja na bieżąco; `[x]` dopiero po implementacji i weryfikacji | Obowiązuje |
| P05 | Decyzje i problemy zapisujemy, a wznowienie zaczyna się od dokumentacji i TASKS | Obowiązuje |

## Decyzje techniczne etapu 1

Data wpisów: 6 listopada 2026 r. Zmiany dotyczą uruchomienia istniejącego szkieletu,
bez implementacji funkcji urlopowych. Podstawą porównania jest commit
`3793ba36dca14a7c0fd977a1c8e5b88013891141`.

| ID | Decyzja i uzasadnienie | Zakres zmiany |
|---|---|---|
| DT01 | Zachowujemy istniejący stack oraz osobne warianty development i produkcja. Start lokalny nie wymaga wcześniejszego produkcyjnego builda aplikacji. | Osobne targety `frontend-dev`, `backend-dev`, `server-dev` i `supervisor-dev`; runtime produkcyjny pozostaje oddzielony w Dockerfile. |
| DT02 | Zależności instalujemy z lockfile, a podstawowe obrazy Docker przypinamy do digestów. Aktualizacja jest świadomą zmianą wymagającą ponownej weryfikacji, nie skutkiem przesunięcia znacznika `latest`. | `npm ci`, Composer install, digests obrazów Node/PHP/Composer/Nginx/Redis/RabbitMQ/MySQL oraz wersje rozszerzeń PECL. Digesty nie zastępują późniejszych aktualizacji bezpieczeństwa. |
| DT03 | Zwykły start, stop i restart zachowują wolumeny. Inicjalizacja ma przerwać się po błędzie i poczekać na gotowość usług przed operacjami bazy. | Makefile bez ignorowania błędów `init`; healthchecki i zależności Compose; `db-init` oraz migracje przez Doctrine. Usuwanie lokalnych danych wymaga jawnego `CONFIRM=DELETE_LOCAL_DATA`. |
| DT04 | Ustawienia lokalne są jawnie oddzielone od produkcyjnych. Zachowujemy pierwotne przykładowe hasła development, aby nie zerwać połączenia z już istniejącymi wolumenami. | `.env.example`, domyślne wartości Compose i `backend/.env` pozostają wyłącznie lokalne. Zmiana zmiennej nie zmienia hasła zapisanego w istniejącym wolumenie MySQL/RabbitMQ. Produkcyjny Compose wymaga osobnych sekretów i DSN. |
| DT05 | Pliki tworzone w bind mountach mają należeć do użytkownika Linux/WSL, a lokalna aplikacja domyślnie nasłuchuje tylko na loopback. | `LOCAL_UID`/`LOCAL_GID` ustawiane przez `make env`, `BIND_ADDRESS=127.0.0.1`; sekrety lokalne i kopie SQL są wyłączone z Git oraz kontekstu builda. |
| DT06 | Konfiguracja Doctrine odpowiada MySQL, a baza testowa otrzymuje jeden sufiks `_test`. | Usunięto nieadekwatną preferencję identyfikatorów PostgreSQL; wersja MySQL pochodzi z DSN. `.env.test` wskazuje nazwę bazową, a `dbname_suffix` dodaje sufiks. |
| DT07 | Dodajemy minimalny endpoint diagnostyczny do sprawdzenia kernela i routingu, bez ujawniania konfiguracji. | `GET /api/health` zwraca tylko `{"status":"ok"}`; test funkcjonalny obejmuje GET oraz odrzucenie POST. Endpoint nie deklaruje gotowości bazy ani wszystkich usług; `make verify` osobno wykonuje `SELECT 1`. |
| DT08 | Konfigurację ESLint wyrównujemy do zainstalowanego Next.js. | `eslint-config-next` zmieniono z gałęzi 15 na dokładne `16.4.5`, aktualizując lockfile. Wersje `next` i React oraz kod interfejsu pozostają zgodne z dotychczasowym szkieletem. |
| DT09 | Skonfigurowany transport AMQP wymaga właściwego mostka Symfony; samo rozszerzenie PHP AMQP nie zapewnia transportu Messenger. | Dodano `symfony/amqp-messenger` w linii `7.4.*`; lockfile wskazuje `v7.4.23`, licencja MIT. Ograniczona aktualizacja Composer doinstalowała jeden pakiet, bez aktualizacji i usuwania innych; nie zgłosiła advisories. To uzupełnienie istniejącego transportu, nie nowy mechanizm rozliczeń. |
| DT10 | Workery są opcjonalnym profilem i korzystają z nazw transportów faktycznie skonfigurowanych w aplikacji. | Profil `workers`, transporty `async` i `scheduler_default`, logi stdout/stderr, limit czasu/pamięci oraz poprawne zatrzymywanie procesów. Poprawiono entrypoint Redis, zachowując inicjalizację i obniżenie uprawnień z oficjalnego obrazu. |
| DT11 | Pełną weryfikację Docker wykonujemy na runnerze GitHub Actions, ponieważ lokalne środowisko wykonawcy nie ma działającego demona Docker. | Workflow `Baseline checks` uruchamia init, kontrolę HTTP/bazy, PHPUnit, analizę statyczną, frontend build i stop/start. Zapisane kroki workflow są planem kontroli; ich wykonanie oceniamy po konkretnym przebiegu. |
| DT12 | Testy muszą wymuszać `APP_ENV=test` także w rzeczywistych zmiennych środowiska procesu. | W `phpunit.dist.xml` pozostawiono wpis `server` i dodano `<env name="APP_ENV" value="test" force="true" />`, aby środowisko `dev` przekazane przez Compose nie uruchamiało testów na kernelu developerskim. |
| DT13 | Bootstrap testów odpowiada zainstalowanemu Symfony 7.4. | Usunięto historyczne sprawdzanie `method_exists(Dotenv::class, 'bootEnv')`; metoda istnieje w wymaganej wersji, a PHPStan 2.3.5 wykrył warunek zawsze prawdziwy. Bez wyciszania reguły analizy. |

Produkcja nadal wymaga prac etapu 12: konfiguracji TLS/VPN, sekretów, polityki kopii
i odtworzenia oraz operacyjnego odbioru. Zmiany w `compose-deploy.yaml` zapewniają
jawne wymaganie ustawień, ale nie potwierdzają gotowości produkcyjnej.

## Problemy i ograniczenia

### E01 — środowisko testowe PHPUnit w Compose

W pierwszym [przebiegu CI](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42246247884)
inicjalizacja kontenerów i `make verify` zakończyły się powodzeniem na PHP 8.3.36.
Krok PHPUnit nie przeszedł: rzeczywiste `APP_ENV=dev` z Compose nie zostało zastąpione
przez dotychczasowy wpis PHPUnit ustawiający wyłącznie zmienną serwera. Poprawkę
opisuje DT12. Jej wynik oraz wynik całego przebiegu po poprawce należy odczytać
z ponownej weryfikacji zapisanej w [TEST_PLAN.md](TEST_PLAN.md); pierwszy przebieg
nie jest dowodem zaliczenia całej kontroli etapu 1.

### E02 — ograniczenie lokalnej weryfikacji Docker

Lokalne środowisko wykonawcy nie ma działającego demona Docker. Walidacja plików
i testy uruchomione poza kontenerami nie zastępują testu startu całego zestawu usług.
Dlatego dowodem działania Compose jest konkretny przebieg CI na rzeczywistych
kontenerach. Nie należy przypisywać lokalnym kontrolom wyników z innego środowiska.

### E03 — zgodność istniejących danych lokalnych

MySQL i RabbitMQ inicjalizują użytkowników/hasła przy tworzeniu danych; zmiana `.env`
nie przestawia istniejących kont. Przywrócono oryginalne lokalne wartości przykładowe
`pass1234`/`root` w konfiguracji development, aby aktualizacja szkieletu nie wymagała
kasowania dotychczasowych wolumenów. Dla istniejących własnych wartości należy
zachować odpowiednią konfigurację lub wykonać świadomą zmianę hasła w usłudze.
Wartości przykładowe nie są konfiguracją produkcyjną.

### E04 — granice etapu 1

Endpoint diagnostyczny i testy uruchomienia nie implementują logowania, kontroli ról,
wniosków, salda ani innych funkcji biznesowych. Nie wykonano wdrożenia na firmowym
serwerze ani odbioru produkcyjnego. Ostateczne wyniki poszczególnych kontroli i status
etapu pozostają w [TEST_PLAN.md](TEST_PLAN.md) i [TASKS.md](../TASKS.md).

## Format kolejnego wpisu

Każdy wpis zawiera datę, identyfikator, decyzję lub problem, uzasadnienie, zakres
wpływu, wynik weryfikacji i ewentualne pytanie. Odpowiedzi użytkownika należy
powiązać z identyfikatorem Q z OPEN_QUESTIONS i odpowiednią zmianą REQUIREMENTS.
