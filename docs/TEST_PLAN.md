# Plan testów i odbioru

Wersja 1.0 • 6 listopada 2026 r.

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

## 7. Najważniejsze scenariusze odbioru

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
