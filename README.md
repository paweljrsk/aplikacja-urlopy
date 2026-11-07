# Aplikacja urlopowa zespołu

Wewnętrzna aplikacja do wniosków i kalendarza nieobecności. Docelowo obejmuje wszystkie formy zatrudnienia, rozliczenia w pełnych godzinach oraz role pracownika, kierownika, zastępcy i administratora. Formalne rozliczenia UoP pozostają w kadrach.

**Bieżący zakres: etap 8 — formularz, widoki pracownika i kalendarz Notus — ukończony.**
Dostępny jest nowy ekran główny z kalendarzem zespołu (dwa tygodnie na komputerze,
jeden na telefonie, z przełącznikiem), listą kolejnych dwóch miesięcy i przyciskiem
„Złóż wniosek”. Krótki formularz otwiera się w dialogu, na telefonie na pełnym ekranie.
Podgląd godzin i wpływu na saldo liczy backend. W `/requests` są własne wnioski,
szczegóły, historia, edycja oczekującego, wycofanie i propozycje zmiany/anulowania.
Konto jest pod `/account`. Nawigacja, logowanie i wcześniejsze ekrany mają wspólną
stylistykę Notus; logo i fonty są lokalne, bez zewnętrznych skryptów marketingowych.

Gałąź: `codex/etap-8-formularz-kalendarz-notus`, [PR #8](https://github.com/paweljrsk/aplikacja-urlopy/pull/8), oparta na etapie 7.
Podstawowy kalendarz przeniesiono z etapu 9 na prośbę użytkownika (B34).
Etap 9 pozostaje do dalszego doskonalenia kalendarza; kolejka decyzji i widoki
przełożonego są zakresem etapu 10. Dotychczasowe API decyzji pozostaje dostępne.
Kontrakt kalendarza: [TEAM_CALENDAR_API.md](docs/TEAM_CALENDAR_API.md),
formularza: [REQUESTS_API.md](docs/REQUESTS_API.md), zasoby: [VISUAL_ASSETS.md](docs/VISUAL_ASSETS.md).
[CI #29 — SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42409969383)
dla kodu `95bf3874336b5d47e978d6756f248ecdf25e7fb7`: **420 testów / 18 015 asercji**,
kontrole statyczne, build, regresja Chrome, rzeczywisty E2E formularza przez
Nginx/PHP-FPM, zachowanie danych po restarcie i workery — PASS.
Sprawdzono wizualnie desktop/mobile; dialog na komputerze jest wyśrodkowany.
Lokalne kontrole również PASS. Bez nowych zależności aplikacji i migracji.
PR pozostaje niescalony; dalszy rozwój kalendarza wymaga polecenia etapu 9.

**Ukończony wcześniej etap 7.**
Dostępne są logowanie, panel administracji `/admin`, limity i saldo `/balances`
oraz zarządzanie firmowymi dniami wolnymi `/calendar-settings`. Rozliczenia
obejmują dane początkowe, rezerwacje, obciążenia, korekty i wspólne pule zaległości.
API umożliwia złożenie, odczyt, edycję oczekującego wniosku, wycofanie, akceptację
i odrzucenie z uzasadnieniem. Etap 7 dodaje propozycję zmiany/anulowania zatwierdzonego
urlopu oraz bezpośrednie korekty przeszłości po podglądzie i potwierdzeniu. Proces
etapu 7 był dostępny przez API. Obecny zakres interfejsu opisano powyżej.

Etap 7 ukończony w [PR #7](https://github.com/paweljrsk/aplikacja-urlopy/pull/7).
Gałąź `codex/etap-7-zmiany-anulowania-korekty` jest oparta na etapie 6; PR pozostaje niescalony.
[CI #24 — SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42401371870)
dla kodu `c08755da94e587c10e2ba9b7b5c5dd528e8f5bf9`: **391 testów / 15 091 asercji**
na PHP 8.3.36 i MySQL 8.0.32, migracje i schemat, kontrole statyczne, build,
regresja Chrome, rzeczywisty HTTP nowych operacji przez Nginx/PHP-FPM, restart
z zachowaniem danych i workery. Szczegóły oraz ręczny odbiór: [plan testów](docs/TEST_PLAN.md).

Etap 6 ukończony w [PR #6](https://github.com/paweljrsk/aplikacja-urlopy/pull/6).
Gałąź `codex/etap-6-wnioski-decyzje` jest oparta na ukończonym etapie 5.
[CI #22 — SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42360588801):
337 testów / 10 662 asercje na PHP 8.3.36 i MySQL 8.0.32, kontrole statyczne,
build, regresja Chrome, rzeczywisty HTTP, restart z zachowaniem danych i workery.
Zakres kontroli i ręczny odbiór API opisuje [plan testów](docs/TEST_PLAN.md).

Etap 5 ukończony w [PR #5](https://github.com/paweljrsk/aplikacja-urlopy/pull/5),
gałąź `codex/etap-5-kalendarz-limity-saldo`, oparta na ukończonym
[PR #4](https://github.com/paweljrsk/aplikacja-urlopy/pull/4). PR-y pozostają niescalone.
[CI #20 — SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42357495900):
268 testów / 6832 asercje, Chrome, migracje, Nginx/PHP-FPM, restart i workery.
Szczegóły i instrukcja ręcznego odbioru: [plan testów](docs/TEST_PLAN.md).
Instrukcje: [API wniosków](docs/REQUESTS_API.md), [rozliczenia](docs/ACCOUNTING.md), [API salda](docs/BALANCES_API.md),
[kalendarz](docs/CALENDAR.md), [administracja](docs/ADMINISTRATION.md).
`GET /api/health` sprawdza kernel/routing, nie gotowość bazy.


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
- Dostęp do rejestrów obrazów i pakietów podczas pierwszego budowania. Interfejs używa lokalnych fontów Inter i Host Grotesk; nie pobiera fontów Google przy starcie ani buildzie.

Nie trzeba instalować PHP ani Node na komputerze — standardowym środowiskiem projektu są kontenery.

## Pierwsze uruchomienie

```bash
git clone https://github.com/paweljrsk/aplikacja-urlopy.git
cd aplikacja-urlopy
git switch codex/etap-8-formularz-kalendarz-notus
make init
make verify
make db-validate
```

`make init` przygotowuje lokalną konfigurację bez nadpisywania istniejącej, buduje i uruchamia usługi, czeka na ich gotowość oraz wykonuje inicjalizację bazy i migracje Doctrine. Migracja etapu 2 dodaje tabele aplikacji i odmawia nadpisania kolidującego schematu. Błąd startu lub migracji przerywa polecenie. Start i migracje nie resetują danych ani nie ładują zestawu demonstracyjnego.

Przy aktualizacji istniejącego projektu najpierw wykonaj `make stop`, pobierz i wybierz gałąź etapu 8, następnie wykonaj `make init`, `make verify` i `make db-validate`. Zatrzymanie przed aktualizacją zapobiega instalowaniu pakietów w katalogu używanym jednocześnie przez działającą aplikację. Migracje zachowują istniejące konta; nie nadają im automatycznie haseł. Etap 5 dodaje jawny stan konfiguracji rocznego limitu, projekcję zajętości wniosku i kontekst zasad zatrudnienia obejmujący również pierwotnie wolne dni. Etapy 6–8 wykorzystują ten schemat bez nowej migracji ani przepisywania istniejących wniosków. Istniejące limity, dane początkowe, księgowania, słowniki i historia pozostają zachowane. Zachowaj dotychczasowy `.env` oraz wolumeny. Nie usuwaj bazy, aby rozwiązać błąd migracji; MySQL DDL nie gwarantuje wycofania częściowo wykonanej zmiany. Szczegóły i celowo zablokowane cofnięcie migracji opisano w [modelu danych](docs/DATA_MODEL.md#migracja-i-dane-demonstracyjne).

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

## Konfiguracja limitów i kalendarza

Po zalogowaniu otwórz **Limity i saldo** (`/balances`). Administrator wybiera osobę,
rok i **Ustaw dane początkowe**: datę startu, limit roczny, wykorzystanie bieżącej
puli przed startem i pozostałości starszych pul UoP. Kolejne lata otrzymują ręczny
limit. Kierownik może robić to dla innych osób własnego zespołu; jego własny limit
zmienia administrator. Zastępca ma odczyt. Pracownik widzi własne saldo.

Korekty wymagają powodu i pozostają w historii. Ujemne saldo jest jawne i nie
anuluje zatwierdzonych urlopów. Szczegóły oraz wzór: [rozliczenia](docs/ACCOUNTING.md).

Administrator zarządza przyszłymi firmowymi dniami wolnymi przez
`/calendar-settings`: najpierw podgląd wpływu na wnioski, potem potwierdzenie.
Polskie święta wyliczane są lokalnie dla lat 2025–2100 według opisanych reguł;
firmowe dni zastępcze wpisuje administrator. [Zasady kalendarza](docs/CALENDAR.md).

## Codzienna praca

Po logowaniu `/` pokazuje kalendarz. Przełącz tydzień/dwa tygodnie, przesuń okres
strzałkami lub wróć do dzisiaj. Współpracownicy widzą jedynie nazwiska, daty, status
i godziny; rodzaje nieobecności i salda są prywatne. Administrator może wybrać zespół.
Lista pod kalendarzem obejmuje najbliższe dwa miesiące niezależnie od przesunięcia
górnego widoku. Przycisk „Złóż wniosek” jest w głównej nawigacji.

W formularzu wybierz rodzaj i daty. Domyślnie są pełne dni po 8 h; „Część dnia”
pozwala wybrać 1–7 h w jednym dniu. Podsumowanie pokazuje godziny i saldo przed/po,
osobno dla lat. Sam podgląd nie zapisuje wniosku. Po wysłaniu wniosek znajdziesz
w „Moich wnioskach”; oczekujący można edytować/wycofać, zatwierdzony zmienić
lub poprosić o anulowanie. Powód odrzucenia i wcześniejsze wersje są w szczegółach.
Zatwierdzony urlop i jego oczekująca propozycja mają osobne oznaczenia.

Proces wniosków etapów 6–7 jest dostępny w `/api/requests`. Wymaga sesji logowania,
CSRF, przypisania pracownika oraz jego danych początkowych; rodzaj obciążający
saldo wymaga też skonfigurowanego limitu danego roku. Złożenie rezerwuje godziny,
akceptacja nie potrąca ich ponownie, a odrzucenie i wycofanie zwalniają rezerwację.
Dzisiejszy dzień jest dozwolony do jego końca w Warszawie. Odrzucenie wymaga powodu.
Propozycja zmiany chroni dotychczasowy termin oraz rezerwuje tylko dodatkowe godziny
w odpowiednich latach i pulach. Skrócenie lub anulowanie zwraca saldo po decyzji.
Korekty wcześniejszych dat wymagają uprawnionej osoby, uzasadnienia, świeżego
podglądu i potwierdzenia. Zwiększenie historycznego niedoboru zatwierdza wyłącznie
administrator; nie można w ten sposób finansować nowych przyszłych godzin.
Kontrakt, przejścia stanów i przykłady: [API wniosków](docs/REQUESTS_API.md).

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

GitHub Actions w `.github/workflows/checks.yml` definiuje kontrole startu, API, bazy, powtórzenia migracji, zgodności schematu, ponownego ładowania demo, testów, lintowania, builda, zachowania danych po stop/start oraz opcjonalnych workerów. Wynik workflow należy sprawdzić dla konkretnego commita. Samo istnienie konfiguracji CI nie oznacza zaliczenia kontroli. Scenariusze przeglądarkowe logowania, administracji, salda i kalendarza firmowego w Chrome używają jawnie syntetycznych odpowiedzi API i sprawdzają zachowanie ekranów; prawdziwy backend jest sprawdzany osobnymi testami HTTP z MySQL. Dodatkowy przebieg przez Nginx i PHP-FPM potwierdza działanie cookie, CSRF, wymuszonej zmiany hasła, tworzenia słowników/kont, konfliktów wersji, danych początkowych, korekt, niedoboru, zmiany kalendarza, izolacji pracownika i wylogowania. Obejmuje również składanie i decyzje wniosków, zmiany zatwierdzonych urlopów, anulowanie, różnicowe rezerwacje, terminy zwrotów oraz potwierdzone korekty historyczne z niedoborem administratora i idempotencją. Narzędzie `playwright-core@1.62.1` jest instalowane tylko na czas kontroli CI, poza zależnościami aplikacji.

Opcjonalne procesy Messenger/Scheduler uruchamia `make workers`. Używają transportów `async` i `scheduler_default`; nie wykonują jeszcze zadań urlopowych. Biblioteka `symfony/amqp-messenger` zapewnia obsługę istniejącego transportu RabbitMQ. Zwykły start aplikacji nie wymaga uruchamiania workerów.

Faktyczne wyniki i ograniczenia środowiska opisano w [planie testów](docs/TEST_PLAN.md). Testów lokalnych na innych wersjach PHP/Node nie traktujemy jako pełnego odbioru docelowych kontenerów.

## Dokumentacja i dalsza praca

- [Wymagania i macierz uprawnień](docs/REQUIREMENTS.md)
- [Limity, rozliczenia i wzór salda](docs/ACCOUNTING.md)
- [API salda i kalendarza](docs/BALANCES_API.md)
- [Kalendarz roboczy i kontrolowane zmiany](docs/CALENDAR.md)
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
