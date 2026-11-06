# Aplikacja urlopowa zespołu

Wewnętrzna aplikacja do wniosków i kalendarza nieobecności. Docelowo obejmuje wszystkie formy zatrudnienia, rozliczenia w pełnych godzinach oraz role pracownika, kierownika, zastępcy i administratora. Formalne rozliczenia UoP pozostają w kadrach.

**Bieżący zakres: etap 1 — szkielet, konfiguracja i dokumentacja.** Nie ma jeszcze logowania, kartotek, wniosków ani salda. Strona główna to ekran startowy Next.js. Backend udostępnia minimalne `GET /api/health` zwracające `{"status":"ok"}`; ten endpoint sprawdza uruchomienie aplikacji i routingu, nie dostępność bazy.

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
- Dostęp do rejestrów obrazów i pakietów podczas pierwszego budowania. `next/font/google` pobiera font podczas builda, a aplikacja podaje go później lokalnie.

Nie trzeba instalować PHP ani Node na komputerze — standardowym środowiskiem projektu są kontenery.

## Pierwsze uruchomienie

```bash
git clone https://github.com/paweljrsk/aplikacja-urlopy.git
cd aplikacja-urlopy
make init
make verify
```

`make init` przygotowuje lokalną konfigurację bez nadpisywania istniejącej, buduje i uruchamia usługi, czeka na ich gotowość oraz wykonuje inicjalizację bazy i migracje Doctrine. W etapie 1 nie ma jeszcze migracji biznesowych. Błąd startu lub migracji przerywa polecenie.

Po uruchomieniu:

- Frontend: http://localhost:8080/
- Backend: http://localhost:8080/api/health

Domyślny port i adres nasłuchiwania są konfigurowalne w głównym `.env`. Domyślnie HTTP jest dostępne tylko na `127.0.0.1`. Wartości przykładowe służą wyłącznie lokalnemu developmentowi. Zachowano domyślne hasła pierwotnego szkieletu, aby nie odciąć dostępu do istniejących lokalnych wolumenów. Przy własnych dotychczasowych hasłach ustaw je w `.env` przed startem; zmiana zmiennej nie zmienia automatycznie użytkownika w istniejącej bazie lub RabbitMQ.

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

Baza testowa jest oddzielona sufiksem `_test` dodawanym przez Doctrine. `DATABASE_URL` nie powinien już zawierać tego sufiksu. Dane testowe i produkcyjne muszą pozostać rozdzielone.

## Weryfikacja i migracje

```bash
make verify
make test
make lint
make db-migrate
```

- `verify`: konfiguracja Compose, uruchomienie Symfony, połączenie z bazą i odpowiedzi HTTP frontendu/backendu.
- `test`: PHPUnit; w etapie 1 testuje uruchomienie kernela oraz kontrakt diagnostycznego endpointu.
- `lint`: walidacja YAML i kontenera Symfony, PHPStan, ESLint i TypeScript.
- `db-migrate`: migracje Doctrine, bez resetowania bazy i bez ręcznego instalatora schematu.

Build frontendu:

```bash
docker compose exec -T node npm run build
```

GitHub Actions w `.github/workflows/checks.yml` wykonuje kontenerowe sprawdzenie startu, API, bazy, testów, lintowania, builda oraz stop/start. Wynik workflow należy sprawdzić dla konkretnego commita. Samo istnienie konfiguracji CI nie oznacza zaliczenia kontroli.

Opcjonalne procesy Messenger/Scheduler uruchamia `make workers`. Używają transportów `async` i `scheduler_default`; w etapie 1 nie wykonują jeszcze zadań urlopowych. Biblioteka `symfony/amqp-messenger` zapewnia obsługę istniejącego transportu RabbitMQ. Zwykły start aplikacji nie wymaga uruchamiania workerów.

Faktyczne wyniki i ograniczenia środowiska opisano w [planie testów](docs/TEST_PLAN.md). Testów lokalnych na innych wersjach PHP/Node nie traktujemy jako pełnego odbioru docelowych kontenerów.

## Dokumentacja i dalsza praca

- [Wymagania i macierz uprawnień](docs/REQUIREMENTS.md)
- [Etapy i prompty developmentu](docs/DEVELOPMENT_PLAN.md)
- [Lista zadań i punkt wznowienia](TASKS.md)
- [Otwarte pytania](docs/OPEN_QUESTIONS.md)
- [Decyzje i problemy](docs/DECISIONS.md)
- [Scenariusze i wyniki testów](docs/TEST_PLAN.md)
- [Operacje i przygotowanie produkcji](docs/OPERATIONS.md)

Przed kolejnym etapem przeczytaj `AGENTS.md`, dokumentację i aktualny stan TASKS. Dla frontendu obowiązuje także `frontend/AGENTS.md` i dokumentacja zainstalowanej wersji Next.js.

Konfiguracja produkcyjna, HTTPS/VPN, polityka backupów oraz próba odtworzenia będą odbierane w etapie 12. Nie używaj konfiguracji developerskiej ani przykładowych haseł na produkcji. Kod, dokumentacja i dane demonstracyjne mogą być publiczne; rzeczywiste dane pracowników, sekrety i kopie bazy pozostają poza repozytorium.
