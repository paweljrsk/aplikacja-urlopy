# Aplikacja urlopowa zespołu

Wewnętrzna aplikacja do wniosków i kalendarza nieobecności. Docelowo obejmuje wszystkie formy zatrudnienia, rozliczenia w pełnych godzinach oraz role pracownika, kierownika, zastępcy i administratora. Formalne rozliczenia UoP pozostają w kadrach.

**Bieżący zakres: etap 4 — administracja użytkownikami, zespołami i słownikami.**
Dostępne są logowanie, zmiana hasła i panel `/admin`: tworzenie kont z hasłami
jednorazowymi, edycja i aktywność kont, datowane przypisania zespołu/roli/formy,
zespoły i wersjonowane rodzaje nieobecności. Kontrolę dostępu, audyt i konflikty
zapisów egzekwuje backend. Wygląd jest tymczasowy — stylistyka Notus w etapie 8.
Wnioski, kalendarz i pełne rozliczenia powstaną w kolejnych etapach.
`GET /api/health` sprawdza kernel/routing, nie gotowość bazy. Zakres opisują
[administracja](docs/ADMINISTRATION.md) i [model danych](docs/DATA_MODEL.md).

Kod etapu 4: [PR #4](https://github.com/paweljrsk/aplikacja-urlopy/pull/4), gałąź
`codex/etap-4-administracja`, oparta na [PR #3](https://github.com/paweljrsk/aplikacja-urlopy/pull/3). Poprzednie etapy pozostają
niescalone; instrukcja poniżej wybiera pełną gałąź etapu 4.
[CI #16](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42349188537) przeszło:
198 testów / 4599 asercji, migracje, kontrole kodu, build, Chrome, HTTP, restart i workery.


## Technologie

| Warstwa | Technologia |
|---|---|
| Backend | PHP 8.3, Symfony 7.4 |
| Dane | MySQL 8.0.32, Doctrine ORM i Doctrine Migrations |
| Frontend | Next.js 16.4.5, React 19.3.0, TypeScript, Tailwind 4 |
| Infrastruktura | Docker Compose, Nginx, Redis, RabbitMQ |
| Kontrole | PHPUnit, PHPStan, Symfony lint, ESLint, TypeScript |

Wersje pakietów zapisane są w `backend/composer.lock` i `frontend/package-lock.json`. Development nie wymaga M365 ani Slacka. Integracje te są poza MVP.

## Wymagania lokalne

- Docker Engine / Docker Desktop z pluginem Compose v2 obsługującym `--wait`.
- Git i GNU Make.
- Na Windows używaj terminala WSL, np. w PHPStorm, z działającym Dockerem w tej dystrybucji. Polecenia wykonuj w katalogu głównym projektu.
- Dostęp do rejestrów obrazów i pakietów podczas pierwszego budowania. Interfejs używa fontów systemowych i nie pobiera fontów Google przy starcie ani buildzie.

Nie trzeba instalować PHP ani Node na komputerze — standardowym środowiskiem projektu są kontenery.

## Pierwsze uruchomienie

```bash
git clone https://github.com/paweljrsk/aplikacja-urlopy.git
cd aplikacja-urlopy
git switch codex/etap-4-administracja
make init
make verify
make db-validate
```

`make init` przygotowuje lokalną konfigurację bez nadpisywania istniejącej, buduje i uruchamia usługi, czeka na ich gotowość oraz wykonuje inicjalizację bazy i migracje Doctrine. Migracja etapu 2 dodaje tabele aplikacji i odmawia nadpisania kolidującego schematu. Błąd startu lub migracji przerywa polecenie. Start i migracje nie resetują danych ani nie ładują zestawu demonstracyjnego.

Przy aktualizacji istniejącego projektu najpierw wykonaj `make stop`, pobierz i wybierz gałąź etapu 4, następnie wykonaj `make init`, `make verify` i `make db-validate`. Zatrzymanie przed aktualizacją zapobiega instalowaniu pakietów w katalogu używanym jednocześnie przez działającą aplikację. Migracje zachowują istniejące konta; nie nadają im automatycznie haseł. Etap 4 dodaje wersje zespołów i form zatrudnienia, historyczne nazwy rodzajów oraz blokadę transakcyjną administracji. Istniejące słowniki, wnioski i ich zasady pozostają zachowane. Zachowaj dotychczasowy `.env` oraz wolumeny. Nie usuwaj bazy, aby rozwiązać błąd migracji; MySQL DDL nie gwarantuje wycofania częściowo wykonanej zmiany. Szczegóły i celowo zablokowane cofnięcie migracji opisano w [modelu danych](docs/DATA_MODEL.md#migracja-i-dane-demonstracyjne).

Po uruchomieniu:

- Frontend: http://localhost:8080/
- Backend: http://localhost:8080/api/health

Domyślny port i adres nasłuchiwania są konfigurowalne w głównym `.env`. Domyślnie HTTP jest dostępne tylko na `127.0.0.1`. Wartości przykładowe służą wyłącznie lokalnemu developmentowi. Zachowano domyślne hasła pierwotnego szkieletu, aby nie odciąć dostępu do istniejących lokalnych wolumenów. Przy własnych dotychczasowych hasłach ustaw je w `.env` przed startem; zmiana zmiennej nie zmienia automatycznie użytkownika w istniejącej bazie lub RabbitMQ.

## Pierwszy administrator i logowanie

Po migracji opcjonalnie załaduj demo poleceniem `make demo` (tylko przy pustych tabelach),
a następnie uruchom interaktywnie:

```bash
docker compose exec php php bin/console app:admin:bootstrap
```

Podaj e-mail oraz dane administratora. Polecenie tworzy pierwsze konto albo — po
jawnym potwierdzeniu — inicjalizuje istniejącego aktywnego administratora demo bez hasła.
Hasło tymczasowe pokaże raz; jest ważne 24 h. Otwórz `http://localhost:8080/login`,
zaloguj się i zmień hasło (15–128 znaków, można używać spacji). Zmiana hasła kończy
wszystkie sesje, także bieżącą, więc zaloguj się ponownie nowym hasłem.

Po zalogowaniu administrator może otworzyć **Administrację** (`/admin`). Na pustej
bazie wybierz **Dodaj słowniki startowe**, dodaj zespół i konta pracowników. Ta jawna
operacja uzupełnia brakujące słowniki bez nadpisywania istniejących ustawień.
Administrator utworzony przez terminal może otrzymać własne przypisanie do zespołu.

Utworzenie/edycja konta, zmiana przypisania i reset wymagają obecnego hasła
administratora. Hasło tymczasowe pracownika jest pokazywane raz; następnie można je
tylko zresetować. Nie można wyłączyć ostatniego administratora z działającym,
zmienionym hasłem. Instrukcja i kontrakt API: [administracja](docs/ADMINISTRATION.md).
Po inicjalizacji haseł nie używaj `make demo` jako sposobu aktualizacji danych.

Gdy administrator utraci hasło lub jego pierwsze hasło wygaśnie, operator mający
dostęp do terminala serwera może uruchomić:

```bash
docker compose exec php php bin/console app:admin:recover
```

Polecenie wymaga wskazania istniejącego aktywnego administratora i potwierdzenia;
nie reaktywuje kont i nie zmienia pracownika w administratora. Żadne polecenie
nie przyjmuje hasła w argumentach. Nie zapisuj haseł w repozytorium ani logach.

Sesja wygasa po 60 minutach bezczynności lub 8 godzinach od logowania. Dezaktywacja,
zmiana danych uwierzytelniania lub uprawnień unieważniają dostęp przy następnym żądaniu.
Szczegóły API, granice kontroli dostępu i założenia techniczne:
[uwierzytelnianie](docs/AUTHENTICATION.md).

## Codzienna praca

```bash
make start
make verify
make stop
```

`start` uruchamia projekt i czeka na gotowość. `stop` zatrzymuje kontenery, zachowując dane. Po restarcie komputera ponownie wykonaj `make start`.

Restart z oczekiwaniem na gotowość: `make stop`, następnie `make start` i `make verify`. Szybkie `make restart` jedynie restartuje istniejące kontenery; sprawdź ich stan przez `make status` przed ponowną weryfikacją.

`make down` usuwa kontenery i sieć projektu, ale zachowuje wolumeny. Nie stosuj `docker compose down -v`, `make delete`, `make reset` ani resetu bazy do zwykłego zatrzymywania lub aktualizacji. Operacje destrukcyjne wymagają osobnego, jawnego potwierdzenia w poleceniu; szczegóły pokazuje `make help`.

## Konfiguracja

Główny `.env` powstaje z `.env.example` i jest ignorowany przez Git. Zawiera port, ustawienia użytkownika kontenerów, dane połączeń i sekret lokalny. Nie zapisuj w nim produkcyjnych danych w publicznym repozytorium.

`backend/.env` zawiera wyłącznie bezpieczne domyślne wartości developerskie. Compose przekazuje wartości rzeczywiste przez środowisko. Nadpisania do pracy PHP poza Dockerem umieszczaj w ignorowanym `backend/.env.local`.

Baza testowa jest oddzielona sufiksem `_test` dodawanym przez Doctrine. `DATABASE_URL` nie powinien już zawierać tego sufiksu. `make test-prepare` nadaje lokalnemu użytkownikowi MySQL dostęp do tej bazy, tworzy ją, jeśli nie istnieje, i wykonuje migracje w środowisku `test`. Nie usuwa ani nie resetuje baz i wolumenów. Dane testowe i produkcyjne muszą pozostać rozdzielone.

## Weryfikacja i migracje

```bash
make verify
make db-migrate
make db-validate
make test-prepare
make test
make lint
```

- `verify`: konfiguracja Compose, uruchomienie Symfony, połączenie z bazą i odpowiedzi HTTP frontendu/backendu.
- `db-migrate`: migracje Doctrine, bez resetowania bazy i bez ręcznego instalatora schematu.
- `db-validate`: zgodność mapowania Doctrine z bieżącym schematem bazy.
- `test-prepare`: przygotowanie oddzielnej bazy `_test`; można wykonać samodzielnie do diagnostyki.
- `test`: automatycznie wykonuje `test-prepare`, a następnie PHPUnit: testy modelu, testy integracyjne zapisu i ograniczeń MySQL, rzeczywiste żądania logowania i autoryzacji API oraz testy kernela i endpointu diagnostycznego. Używa danych syntetycznych.
- `lint`: walidacja YAML i kontenera Symfony, PHPStan z rozszerzeniem Doctrine, ESLint i TypeScript.

## Dane demonstracyjne

Opcjonalnie, po migracji pustej bazy developerskiej:

```bash
make demo
```

Polecenie wywołuje `app:demo:load --confirm-demo`; działa tylko w środowisku `dev` lub `test`. Pierwsze ładowanie wymaga pustych tabel aplikacji. Zestaw zawiera 5 syntetycznych kont w domenie `example.test`, wszystkie role, UoP/B2B, 2 zespoły, 3 rodzaje nieobecności, dane początkowe i 2 przykładowe wnioski. Nie tworzy haseł logowania ani interfejsu do obsługi tych danych.

Powtórzenie polecenia rozpoznaje marker zestawu, sprawdza liczności i kluczowe identyfikatory, po czym pozostawia dane bez zmian. Nie nadpisuje istniejących rekordów. Nie jest to pełne porównanie wszystkich pól. Zestaw nie ładuje się przy zwykłym starcie; nie czyść istniejącej bazy, aby go załadować.

## Build i CI

Build frontendu:

```bash
docker compose exec -T node npm run build
```

GitHub Actions w `.github/workflows/checks.yml` definiuje kontrole startu, API, bazy, powtórzenia migracji, zgodności schematu, ponownego ładowania demo, testów, lintowania, builda, zachowania danych po stop/start oraz opcjonalnych workerów. Wynik workflow należy sprawdzić dla konkretnego commita. Samo istnienie konfiguracji CI nie oznacza zaliczenia kontroli. Scenariusze przeglądarkowe logowania i administracji w Chrome używają jawnie syntetycznych odpowiedzi API i sprawdzają zachowanie ekranów; prawdziwy backend jest sprawdzany osobnymi testami HTTP z MySQL. Dodatkowy przebieg logowania przez Nginx i PHP-FPM potwierdza działanie cookie, CSRF, wymuszonej zmiany hasła, tworzenia słowników/kont, konfliktów wersji, odmowy administracji pracownikowi i wylogowania w uruchomionym stosie. Narzędzie `playwright-core@1.62.1` jest instalowane tylko na czas kontroli CI, poza zależnościami aplikacji.

Opcjonalne procesy Messenger/Scheduler uruchamia `make workers`. Używają transportów `async` i `scheduler_default`; nie wykonują jeszcze zadań urlopowych. Biblioteka `symfony/amqp-messenger` zapewnia obsługę istniejącego transportu RabbitMQ. Zwykły start aplikacji nie wymaga uruchamiania workerów.

Faktyczne wyniki i ograniczenia środowiska opisano w [planie testów](docs/TEST_PLAN.md). Testów lokalnych na innych wersjach PHP/Node nie traktujemy jako pełnego odbioru docelowych kontenerów.

## Dokumentacja i dalsza praca

- [Wymagania i macierz uprawnień](docs/REQUIREMENTS.md)
- [Administracja, pierwsza konfiguracja i API](docs/ADMINISTRATION.md)
- [Model danych i granice etapu 2](docs/DATA_MODEL.md)
- [Logowanie, API i obsługa haseł](docs/AUTHENTICATION.md)
- [Etapy i prompty developmentu](docs/DEVELOPMENT_PLAN.md)
- [Lista zadań i punkt wznowienia](TASKS.md)
- [Otwarte pytania](docs/OPEN_QUESTIONS.md)
- [Decyzje i problemy](docs/DECISIONS.md)
- [Scenariusze i wyniki testów](docs/TEST_PLAN.md)
- [Operacje i przygotowanie produkcji](docs/OPERATIONS.md)

Przed kolejnym etapem przeczytaj `AGENTS.md`, dokumentację i aktualny stan TASKS. Dla frontendu obowiązuje także `frontend/AGENTS.md` i dokumentacja zainstalowanej wersji Next.js.

Konfiguracja produkcyjna, HTTPS/VPN, polityka backupów oraz próba odtworzenia będą odbierane w etapie 12. Nie używaj konfiguracji developerskiej ani przykładowych haseł na produkcji. Kod, dokumentacja i dane demonstracyjne mogą być publiczne; rzeczywiste dane pracowników, sekrety i kopie bazy pozostają poza repozytorium.
