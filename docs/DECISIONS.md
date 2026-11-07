# Rejestr decyzji i problemów

Stan początkowy: 6 listopada 2026 r.

Decyzje biznesowe poniżej wynikają z uzgodnień użytkownika zapisanych w planie
wersji 1.0. Szczegółowe wymagania są w [REQUIREMENTS.md](REQUIREMENTS.md).
Przed etapem 2 użytkownik potwierdził Q1 oraz podstawowe ustalenia Q5 i Q8.
Pozostałe kwestie i nierozstrzygnięte części tych pytań są w OPEN_QUESTIONS.

## Uzgodnione decyzje biznesowe

| ID | Decyzja | Konsekwencja |
|---|---|---|
| B01 | Wszyscy członkowie zespołu procesują wnioski w nowej aplikacji | Obsługujemy UoP, B2B i inne formy; formalne rozliczenia UoP pozostają w kadrach |
| B02 | Na start około 10 osób i jeden zespół, z możliwością rozbudowy | Użytkownik ma jeden zespół, model i API uwzględniają wiele zespołów |
| B03 | Lokalny login e-mail/hasło, konta administratora, wymuszona zmiana hasła tymczasowego | Brak samodzielnej rejestracji; M365 poza MVP |
| B04 | Slack poza MVP | Brak zależności procesu decyzji od notyfikacji Slack |
| B05 | Trzy rodzaje: płatne dni wolne, bezpłatne dni wolne, inna nieobecność | Tylko pierwszy obciąża wspólny limit; wszystkie wymagają akceptacji |
| B06 | Administrator zarządza słownikiem rodzajów i dostępnością dla form zatrudnienia | Bez osobnych limitów dla dodatkowych rodzajów; zmiana wpływu na saldo dotyczy nowych wniosków (B20) |
| B07 | 8 h dziennie, poniedziałek–piątek, polskie dni wolne | Rozliczenia w pełnych godzinach, lokalne daty Europe/Warsaw |
| B08 | Domyślnie cały dzień, możliwość zmniejszenia liczby godzin | Bez połówek jako osobnego trybu, bez godzin od–do; pełne dni lub jeden dzień na 1–7 h (B18) |
| B09 | Administrator i kierownik ustawiają limity na konkretny rok | Osobno limit, wykorzystanie początkowe i zaległość; własny limit kierownika wymaga Q6 |
| B10 | Oczekujący wniosek rezerwuje saldo; brak salda blokuje wniosek obciążający limit | Akceptacja nie potrąca godzin drugi raz, odrzucenie/wycofanie zwalnia rezerwację |
| B11 | Niewykorzystane dni UoP przechodzą i wykorzystujemy najstarsze najpierw | Bez automatycznego wygaszenia; pozostałe formy nie przenoszą dni |
| B12 | Kierownik zatwierdza własne wnioski, zastępca swoich nie zatwierdza | Zastępca stale może obsługiwać cudze wnioski zespołu; administrator ma pełne uprawnienia |
| B13 | Zmiana/anulowanie zaakceptowanego urlopu wymagają decyzji | Do decyzji obowiązuje stary urlop; odrzucenie zmiany zachowuje jego skutki |
| B14 | Nieobecności rozpoczęte/przeszłe obsługują kierownik, zastępca i administrator | Tryb korekty i dzisiejsze wnioski wymagają Q2 |
| B15 | Współpracownik widzi nazwisko, daty, status i godziny, bez cudzego salda i rodzaju | API ogranicza udostępniane pola; własne i uprawnione szczegóły osobno |
| B16 | Kalendarz jest ekranem głównym; stylistyka Notus, komputer i telefon | Responsywna aplikacja webowa; PWA opcjonalnie później |
| B17 | Własny serwer Docker, dostęp VPN; repozytorium może pozostać publiczne | Sekrety, rzeczywiste dane i backupy poza repozytorium |

## Doprecyzowania przed etapem 2 — 6 listopada 2026 r.

Użytkownik zaakceptował wszystkie cztery przedstawione propozycje odpowiedziami
„1. Tak, 2. OK, 3. Tak, 4. Tak”. Poniższe decyzje obowiązują przed implementacją
zależnego modelu. Nie rozstrzygają niewymienionych procedur z późniejszych etapów.

| ID | Pytanie | Potwierdzona decyzja | Konsekwencja dla modelu i granice ustalenia |
|---|---|---|---|
| B18 | Q1 | Jeden wniosek obejmuje zakres pełnych dni albo pojedynczy dzień na 1–7 pełnych godzin. | Model rozróżnia oba warianty. Nie wprowadzamy częściowych godzin dla wielu dni ani godzin zegarowych od–do. |
| B19 | Q5 — dane pracownika | Administrator może zmienić zespół lub formę zatrudnienia osoby z historią, od wskazanej daty. | Zachowujemy wcześniejsze dane i rozliczenia. Widoczność historii oraz obsługę otwartych i już złożonych przyszłych wniosków trzeba ustalić przed etapem 4. |
| B20 | Q5 — wpływ rodzaju na saldo | Zmiana ustawienia „pomniejsza saldo” obowiązuje tylko nowe wnioski; już złożone zachowują dotychczasowe zasady. | Wniosek zachowuje zasady z momentu złożenia. Edycja słownika nie zmienia automatycznie dotychczasowych rozliczeń. |
| B21 | Q8 — stan początkowy | Dla pracownika ustalamy datę rozpoczęcia ewidencji, limit bieżącego roku, wykorzystanie z bieżącej rocznej puli do dnia poprzedniego oraz pozostałość wcześniejszych pul osobno według roku pochodzenia. | Starsze nieobecności są już uwzględnione w stanie początkowym i nie mogą pomniejszyć salda drugi raz. Pozostałości starszych pul nie pomniejszamy ponownie o historyczne wykorzystanie. Szczegółowa procedura korekt sprzed daty granicznej pozostaje w Q8. |

## Doprecyzowania przed etapem 4 — 7 listopada 2026 r.

Użytkownik potwierdził trzy propozycje odpowiedzią „tak dla wszystkich”. Q5 jest zamknięte.

| ID | Decyzja | Konsekwencja |
|---|---|---|
| B22 | Przełożeni starego zespołu widzą nieobecności sprzed przeniesienia, nowego — od daty przeniesienia; właściciel i administrator całą historię. | Daty nieobecności wyznaczają widoczność; nie ujawniamy pozostałych dni ani szczegółów obcego zespołu. |
| B23 | Od dnia przeniesienia przyszłe oczekujące wnioski obsługuje nowy przełożony. Wcześniejsze akceptacje pozostają ważne. Wnioski obejmujące obie strony przeniesienia obsługuje administrator. | Bez automatycznego podziału lub ponownej akceptacji. Zaplanowane przeniesienie nie daje nowemu przełożonemu dostępu przed datą wejścia w życie. |
| B24 | Już złożone wnioski zachowują zasady rozliczeń po zmianie formy zatrudnienia; nowe stosują formę z dnia nieobecności. | Przypisania zapisane w dniach wniosków pozostają niezmienne; edycja kartoteki nie przelicza limitów i rozliczeń. |

Użytkownik potwierdził także pozostawienie tymczasowego interfejsu na tym etapie.
Pełne dopasowanie wszystkich ekranów do notus.pl i notus.pl/banki pozostaje w etapie 8.

## Doprecyzowania przed etapem 5 — 7 listopada 2026 r.

Użytkownik potwierdził wszystkie pięć propozycji odpowiedzią „tak dla wszystkich”.
Q3, Q4 (zakres etapu 5), Q6, pozostała część Q8 i Q9 są rozstrzygnięte.

| ID | Pytanie | Potwierdzona decyzja |
|---|---|---|
| B25 | Q3 | Oczekujące stare wnioski zachowują rezerwacje. UoP udostępnia przeniesione wolne godziny dopiero od początku nowego roku; późniejsze zwolnienie starej rezerwacji zwiększa dostępną zaległość. Przed rozpoczęciem roku można rezerwować jego ręcznie wpisany limit, bez przewidywanego przeniesienia. B2B i pozostałe formy nadal nie przenoszą godzin. Jedna godzina pozostaje w jednej puli pochodzenia. |
| B26 | Q4 | Jawna korekta limitu lub danych początkowych z uzasadnieniem może powodować niedobór. Pokazujemy ujemne saldo, zachowujemy zatwierdzone urlopy i blokujemy nowe obciążenia zwiększające niedobór. Zamiana istniejącej rezerwacji na obciążenie nie potrąca drugi raz. Procedury korekty samych wniosków pozostają w etapie 7. |
| B27 | Q6 | Własny limit kierownika zmienia administrator. Kierownik zarządza limitami innych osób własnego zespołu; zastępca nie zmienia limitów. Nie zmienia to prawa kierownika do akceptacji własnych wniosków. |
| B28 | Q8 | Okres sprzed rozpoczęcia ewidencji rozliczamy korektami danych początkowych z uzasadnieniem i historią. Nie dopisujemy przed granicą ewidencji starych wniosków ponownie pomniejszających saldo. Po rozpoczęciu rozliczania wniosków data rozpoczęcia ewidencji jest zablokowana. |
| B29 | Q9 | Administrator zmienia firmowe dni wolne wyłącznie dla przyszłych dat. Najpierw otrzymuje podgląd wpływu na istniejące wnioski, następnie jawnie potwierdza korekty. Dodanie wolnego zwraca godziny, usunięcie zwiększa obciążenie według B26. Zniknięcie całego jednodniowego urlopu obejmuje jawne anulowanie/wycofanie. Potwierdzenie skutków dla zatwierdzonych wniosków zapisujemy jako decyzję; przeszłości nie przeliczamy zbiorczo. |

## Doprecyzowania przed etapem 6 — 7 listopada 2026 r.

Użytkownik potwierdził obie przedstawione propozycje odpowiedzią „Tak”.

| ID | Pytanie | Potwierdzona decyzja |
|---|---|---|
| B30 | Q2 — dzisiejszy dzień | Własny wniosek można złożyć na dzisiaj. Do końca dnia w Europe/Warsaw można edytować lub wycofać własny oczekujący wniosek, jeśli nie obejmuje wcześniejszych dni. Nie ustalamy pory nieobecności, bo wniosek nie ma godzin zegarowych. Przeszłe daty pozostają zablokowane dla samoobsługi; procedury indywidualnych korekt pozostają w etapie 7. |
| B31 | Q10 — odrzucenie | Powód odrzucenia jest obowiązkowy i widoczny dla właściciela wniosku oraz osób uprawnionych do jego szczegółów. |

## Doprecyzowania przed etapem 7 — 7 listopada 2026 r.

Użytkownik potwierdził obie przedstawione propozycje odpowiedzią „Tak”.

| ID | Pytanie | Potwierdzona decyzja |
|---|---|---|
| B32 | Q2 — korekty rozpoczętych/przeszłych wniosków | Uprawniony kierownik, zastępca lub administrator wykonuje korektę bezpośrednio po podglądzie i jawnym potwierdzeniu, z obowiązkowym powodem, decyzją i historią. Kierownik może poprawić własny wniosek; własny wniosek zastępcy poprawia kierownik albo administrator. Zachowujemy zakres zespołów i B22–B24. Dzisiejsze/przyszłe zmiany stosują zwykłą propozycję i decyzję; granica dnia według B30. Przeszłość sprzed początku ewidencji nadal poprawiana przez dane początkowe B28. |
| B33 | Q4 — korekta historycznego wniosku i niedobór | Korektę zwiększającą niedobór, także po wykorzystaniu przeniesionej puli w kolejnym roku, dopuszcza wyłącznie administrator po podglądzie i jawnym potwierdzeniu niedoboru, z uzasadnieniem. Nie anuluje innych urlopów; zachowuje pochodzenie godzin i audyt. Nowe obciążenia zwiększające niedobór są blokowane. Kierownik/zastępca koryguje w swoim zakresie bez zwiększenia niedoboru; uprawnienia do limitów z B26/B27 bez zmian. |

## Doprecyzowanie przed etapem 8 — 7 listopada 2026 r.

| ID | Decyzja użytkownika | Konsekwencja |
|---|---|---|
| B34 | Aplikacja ma być prosta i intuicyjna. Po logowaniu kalendarz urlopów pracowników, pod nim przyszłe nieobecności na około dwa miesiące i dostępny przycisk dodania wniosku. | Podstawowy kalendarz i lista powstają już w etapie 8. Użytkownik pozostawił wykonawcy wybór jednego/dwóch tygodni oraz ocenę modalu; zakres danych pozostaje według B15 i B22–B24. |

Decyzja UX wykonawcy: dwa tygodnie na komputerze, jeden na telefonie, przełącznik
1/2 tygodnie i nawigacja okresu. Nowy wniosek w krótkim modalu na komputerze,
pełnoekranowym dialogu na telefonie; bez kreatora wielu kroków. Formularz pokazuje
podgląd obliczony przez backend, a zapis ponownie sprawdza reguły. Dane kalendarza
współpracowników pochodzą z osobnego, ograniczonego DTO, bez sald i rodzajów.
To decyzje realizacyjne w udzielonym zakresie, nie dodatkowe odpowiedzi biznesowe.

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

## Decyzje techniczne etapu 2 — 6 listopada 2026 r.

Szczegółowy opis encji, ograniczeń i granic implementacji znajduje się w
[DATA_MODEL.md](DATA_MODEL.md). Poniższe wpisy dokumentują kod i przyjęty sposób
zapisu; wyniki uruchomienia testów pozostają w TEST_PLAN.

| ID | Decyzja i uzasadnienie | Zakres i granice |
|---|---|---|
| DT14 | Tożsamość pracownika oddzielamy od okresów przypisania do zespołu, umowy i roli. Administrator jest uprawnieniem globalnym użytkownika. | `staff_assignment` stosuje przedziały z wyłącznym końcem; historyczne dni wniosku wskazują zapisane przypisanie. Nie ustala to jeszcze widoczności historii po przeniesieniu (Q5). |
| DT15 | Zasady rodzaju i treść propozycji wniosku zachowujemy w osobnych wersjach. | Nowe zasady dotyczą nowych wniosków; dotychczasowy wniosek zachowuje wersję danego rodzaju. Dni propozycji są niezmienne w interfejsie modelu. Jedna decyzja kończy propozycję; ostatnia zatwierdzona wersja pozostaje obowiązująca do akceptacji zmiany/anulowania. |
| DT16 | Stan początkowy zachowujemy osobno od dalszych zapisów rozliczeniowych. | Data startu i wykorzystanie bieżącej puli są oddzielone od pozostałości starszych lat. `opening_used` nie tworzy fikcyjnego dnia wniosku i nie obciąża ponownie pozostałości dawnych pul. Procedura korekt sprzed granicy pozostaje w Q8. |
| DT17 | Źródłem salda są zapisy zmian przydziału, rezerwacji i obciążenia z odniesieniem do zdarzenia oraz puli. | Rozdzielono rok nieobecności (`leave_account`) i rok pochodzenia (`hour_pool`). Klucz operacji audytu i para zdarzenie/numer linii są unikalne. To podstawa idempotencji, nie gotowa obsługa ponowień API. Najstarsza pula, przejście roku i blokada braku salda należą do późniejszych usług. |
| DT18 | Zmiana obiektu potomnego aktualizuje również wersjonowany agregat; zajętość dnia ma własną wersję. | Liczniki zmian aktywują optimistic locking Doctrine dla użytkownika, rodzaju, wniosku, konta i puli. Dotknięcie puli chroni zapisy dotyczące różnych lat. `daily_occupancy` obejmuje wszystkie rodzaje. Usługi muszą zapisywać powiązane obiekty w jednej transakcji i obsługiwać konflikt. |
| DT19 | Audyt ma jawny cel także dla operacji globalnych, a czasy operacji są normalizowane do UTC. | Pracownik zdarzenia może być pusty, lecz `target_type`/`target_reference` muszą określać cel. Kopia JSON odłącza zagnieżdżone obiekty wejściowe. Typ DBAL UTC zapisuje/odczytuje sekundy; daty nieobecności pozostają `DATE` według Europe/Warsaw. |
| DT20 | Pierwsza migracja dodaje 19 tabel i odmawia nadpisania kolidującego schematu. | Klucze obce `RESTRICT`, unikalności i `CHECK` uzupełniają walidację konstruktorów. MySQL DDL nie jest transakcyjne, więc migracja tego nie deklaruje. `down()` jest celowo nieodwracalne, aby nie usuwać historii. Nie ma instalatora zastępującego Doctrine Migrations. |
| DT21 | Dane demonstracyjne są osobnym, jawnym poleceniem na pustej bazie development/test. | `app:demo:load --confirm-demo` odrzuca produkcję, nie tworzy haseł i nie uruchamia się automatycznie. Zestaw ma 5 kont, wszystkie role, UoP/B2B i 2 zespoły. Powtórzenie rozpoznanego zestawu nie dubluje ani nie nadpisuje danych. |
| DT22 | Dodajemy rozszerzenie analizy statycznej rozumiejące mapowania Doctrine zamiast wyciszać poprawne właściwości utrwalane przez ORM. | Nowa zależność tylko developerska: `phpstan/phpstan-doctrine` 2.1.2, licencja MIT. Porównanie lockfile potwierdza jeden dodany pakiet, bez usunięć i zmian wersji pozostałych pakietów. Nie dodaje zależności do runtime produkcyjnego. |

## Decyzje techniczne etapu 3 — 6 listopada 2026 r.

Prompt 3 zleca zaprojektowanie sposobu uwierzytelniania i zabezpieczeń. Poniższe
wartości są decyzjami technicznymi wykonawcy, nie nowymi odpowiedziami biznesowymi
użytkownika. Zakres opisuje [AUTHENTICATION.md](AUTHENTICATION.md); faktyczne wyniki
testów pozostają w TEST_PLAN. Sam wpis nie oznacza zaliczenia odbioru.

| ID | Decyzja i uzasadnienie | Zakres i granice |
|---|---|---|
| DT23 | Używamy mechanizmów Symfony Security i osobnej tożsamości sesyjnej powiązanej z `User.id`. | Provider odczytuje konto z bazy. Sesja nie serializuje grafu encji ani skrótu hasła. Lokalne uwierzytelnianie jest oddzielone od polityki dostępu, co pozwala później dołączyć inny provider; integracja M365 nie powstaje w tym etapie. |
| DT24 | Frontend i API korzystają ze wspólnego origin oraz sesji serwerowej. | Cookie sesji: `HttpOnly`, `SameSite=Lax`, `Secure` na produkcji. Operacje zmieniające stan wymagają tokenu CSRF powiązanego z sesją w nagłówku `X-CSRF-Token`. Nie przechowujemy tokenu sesji ani haseł w localStorage. |
| DT25 | Sesja wygasa po 60 minutach bezczynności albo po 8 godzinach od logowania. | Odświeżana tożsamość uwzględnia aktywność, administratora, `credentialVersion`, zmianę przypisań i przypisanie obowiązujące dzisiaj w Europe/Warsaw. Zmiana hasła/reset unieważnia wcześniejsze sesje przy kolejnym żądaniu; zmiana własnego hasła kończy też sesję bieżącą. Dezaktywacja zwiększa `credentialVersion`, aby ponowne uaktywnienie nie przywracało starych sesji. |
| DT26 | Ograniczamy próby logowania i potwierdzania hasła do operacji wrażliwych. | Logowanie: 5 prób/min dla pary konto+IP i 25 prób/min dla IP. Potwierdzanie obecnego hasła: 5 prób/min na zalogowanego użytkownika, wspólnie dla zmiany hasła i resetów różnych kont. Po przekroczeniu limitu API zwraca 429. |
| DT27 | Nowe hasło ma 15–128 znaków; dopuszczamy spacje bez narzuconego zestawu symboli. | Hasła tymczasowe są losowe, ważne 24 h i wymagają zmiany przed dostępem do funkcji aplikacji. Nie zapisujemy wartości haseł ani ich skrótów w audycie. |
| DT28 | Pierwszego administratora inicjalizuje jawna, interaktywna komenda na serwerze. | Brak stałego hasła i parametru CLI zawierającego hasło. Bootstrap może zachować dane aktywnego administratora demo bez hasła. Osobna komenda odzyskania dostępu dotyczy tylko istniejącego, aktywnego administratora z wcześniej ustawionym hasłem; nie aktywuje kont ani nie nadaje uprawnień. Reset przez API wymaga administratora i jego obecnego hasła; własne hasło zmienia zwykłym formularzem. |
| DT29 | W etapie 3 API uprawnień obejmuje bieżące profile, a nie historię nieobecności po przeniesieniu. | Pracownik czyta własny profil; kierownik i zastępca także profile bieżącego zespołu; administrator wszystkie. Polityka decyzji zachowuje wyjątek kierownika i zakaz własnej akceptacji zastępcy, lecz nie wykonuje decyzji urlopowych. Historyczny zakres dostępu i przenoszenie otwartych wniosków pozostają w Q5. |
| DT30 | Dodajemy komponenty bezpieczeństwa zgodne z linią Symfony 7.4. | Bezpośrednie zależności: `security-bundle`, `security-csrf`, `rate-limiter`, `lock`. Lockfile dodaje łącznie 10 pakietów MIT, bez zmiany wersji lub usunięcia pozostałych. Instalacja nie zgłosiła advisories. Dokładne wersje i przeznaczenie są w AUTHENTICATION. |

## Decyzje techniczne etapu 4 — 7 listopada 2026 r.

| ID | Decyzja i uzasadnienie | Zakres i granice |
|---|---|---|
| DT31 | Administracyjne zapisy są transakcyjne, serializowane rekordem `administration_lock` i chronione wersją obiektu. | Ponowne sprawdzenie aktualnych uprawnień pod blokadą, brak nadpisania nieaktualnego formularza, ochrona ostatniego aktywnego administratora z docelowym hasłem. |
| DT32 | Słowniki startowe powstają przez jawną idempotentną operację administratora. | `POST /api/admin/catalog/initialize` uzupełnia brakujące UoP/B2B i trzy rodzaje; nie nadpisuje ustawień i nie uruchamia demo. |
| DT33 | Nazwy rodzaju zależne od formy zatrudnienia należą do niezmiennej wersji zasad. | Nowa kolumna JSON ma bezpieczną pustą mapę dla istniejących wersji. Zmiana słownika nie dotyka wcześniejszych wniosków. |
| DT34 | Przypisania dopisujemy chronologicznie; nieużywane ostatnie przypisanie można skorygować od tej samej daty z audytem. | Gdy wskazuje je `RequestDay`, korekta treści jest blokowana; nowy okres zachowuje stare referencje. Przyszła usługa wniosków musi blokować agregat użytkownika przed zapisem przypisań dni. |
| DT35 | Uprawnienia wniosków po transferze są osobną polityką od zapisanych zasad rozliczenia. | `RequestAccessPolicy` rozdziela dni kalendarza, szczegóły wersji i decyzję. Mieszany zakres wymaga administratora także przy zaplanowanym transferze; nowe API wniosków wykorzysta politykę później. |
| DT36 | Zachowujemy stack i zależności, dodając funkcjonalny panel `/admin`. | Bez nowych pakietów runtime; pełny projekt wizualny Notus pozostaje w etapie 8. Testy backendu, przeglądarki ze sztucznym API i rzeczywistego HTTP są raportowane osobno. |

## Decyzje techniczne etapu 5 — 7 listopada 2026 r.

| ID | Decyzja i uzasadnienie | Zakres i granice |
|---|---|---|
| DT37 | Dostępne godziny liczymy z niezmiennych zapisów wspólnych pul pochodzenia, bez kopiowania zaległości. | Ręczny przydział roczny, jawny stan brak/zero, korekty różnicowe, rezerwacje i obciążenia. Wzór i źródła w ACCOUNTING. |
| DT38 | Rozliczenia korzystają z tej samej blokady co administracja; uprawnienia sprawdzamy pod blokadą. | Transakcje obejmują audyt, księgowania, decyzje i zajętość. Wersja formularza obejmuje wspólne pule; klucz operacji chroni ponowienia. |
| DT39 | Kalendarz lokalny obejmuje lata 2025–2100 według zweryfikowanych obecnych reguł. | Starsze lata pochodzenia pul są dozwolone; brak połączeń do internetu w runtime. Przyszłe lata nie gwarantują niezmienności przepisów. Źródła i ograniczenia w CALENDAR. |
| DT40 | Zmiana kalendarza wymaga świeżego podglądu oraz jawnych nowych wersji i decyzji. | Oryginalne wnioski i zasady zatrudnienia pozostają zachowane. Nowe wersje utrwalają kontekst także pierwotnie wolnych dni. Niejednoznaczne stare dane nie są automatycznie uzupełniane bieżącymi zasadami. |
| DT41 | Dodajemy podstawowe ekrany `/balances` i `/calendar-settings` bez nowych zależności aplikacji. | Formularz wniosków, główny kalendarz zespołu i pełny projekt Notus pozostają w zaplanowanych dalszych etapach. |

## Decyzje techniczne etapu 6 — 7 listopada 2026 r.

| ID | Decyzja i uzasadnienie | Zakres i granice |
|---|---|---|
| DT42 | Publiczny proces wniosków ma jedną granicę transakcji i korzysta z `reconcileRequest`. | Wspólna blokada administracji, uporządkowane blokady użytkowników i wniosku; ponowne wczytanie całego grafu po blokadzie usuwa nieaktualne relacje Doctrine. Wywołujący musi wcześniej zapisać niezależne zmiany; żadne obce niezapisane encje nie są częścią tej operacji. |
| DT43 | Edycja oczekującego zastępuje wersję, zachowując kontekst tylko dotychczasowego zakresu. | Poprzednia `INITIAL` otrzymuje `SUPERSEDED`, nowa `INITIAL` oraz rozliczenie powstają atomowo. Nowe daty korzystają z przypisań obowiązujących w tych dniach; nie rozciągamy starego otwartego kontekstu. |
| DT44 | Idempotencja przechowuje końcowe potwierdzenie niezależnie od właściwego zdarzenia rozliczenia. | Klucz jest przypisany do aktora; konflikt treści daje 409. Techniczny `request.operation_completed` nie stanowi kolejnego księgowania. Replay wymaga aktualnego dostępu do konkretnej wersji i nie ujawnia salda. |
| DT45 | Każda wersja historii ma osobne uprawnienie do odczytu. | Odczyt we wspólnej transakcji i świeżym grafie; przełożony ze starą historią nie otrzymuje obecnego statusu, wersji ani szczegółów nowego zespołu. Lista jest stronicowana, sprawdza najwyżej 500 kandydatów i pozwala kontynuować kursorem bez liczników niedostępnych danych. |
| DT46 | Etap 6 obejmuje API, bez nowych bibliotek, schematu i ekranów frontendu. | Publiczny proces i kontrakt w REQUESTS_API; UI w etapie 8, korekty zatwierdzonych w 7. PHPUnit ma jawny limit 512 MB wyłącznie podczas testów; pełny zestaw lokalnie przekracza domyślne 128 MB PHP. |

## Decyzje techniczne etapu 7 — 7 listopada 2026 r.

| ID | Decyzja i uzasadnienie | Zakres i granice |
|---|---|---|
| DT47 | Rezerwacja propozycji porównuje zapotrzebowanie per rok nieobecności i uprawnienie do puli pochodzenia, niezależnie od dat zajętości. | Obowiązujący urlop zachowuje finansowanie; dodatkowe pokrycie zabezpiecza nową propozycję. Zajętość to maksimum starego i nowego wymiaru na każdej dacie, plus inne wnioski. |
| DT48 | Korekta przeszłości wymaga świeżego podglądu i jawnego potwierdzenia. | Podgląd wykonuje te same walidacje i rozliczenie pod blokadą, po czym zawsze wycofuje transakcję i odłącza encje. HMAC wiąże aktora, treść i powód, wersję wniosku, dane rozliczeń, zasady i dzień w Warszawie. Zmiana danych wymaga nowego podglądu; znacznik audytu celowo może unieważnić go także po innej operacji. |
| DT49 | Korekta historycznego zaakceptowanego wniosku zapisuje nową wersję i decyzję w jednej transakcji; stary oczekujący INITIAL zachowuje pierwotną konieczność akceptacji. | Anulowanie historycznego INITIAL jest wycofaniem. Istniejącą oczekującą zmianę/anulowanie trzeba najpierw rozstrzygnąć, nie jest po cichu usuwana. Wyjątek niedoboru B33 dotyczy nowych brakujących godzin przeszłych, nie przyszłych; B29 korekty kalendarza pozostaje osobną operacją. |
| DT50 | Zatwierdzona zmiana ma własny zakres uprawnień, a zastąpiona wersja jest osobną historią. | Oczekująca propozycja wymaga dostępu do starego i nowego zakresu. Odczyt osobno filtruje wersję obowiązującą i oczekującą. Podgląd nie ujawnia pul przełożonemu uprawnionemu tylko do dawnego wniosku, bez prawa do bieżącego salda. |

## Decyzje realizacyjne etapu 9 — 7 listopada 2026 r.

Użytkownik zlecił etap 9 po przedstawieniu pozostałego zakresu: filtry,
czytelność zmian, szczegóły dla uprawnionych osób i odbiór. Zachowujemy B34
oraz widok 1/2 tygodnie; widok miesiąca pozostaje opcją późniejszego rozwoju.

| ID | Decyzja wykonawcza | Uzasadnienie i granice |
|---|---|---|
| DT51 | Filtry osoby i statusu działają wspólnie na kalendarz i listę dwóch miesięcy. | Zawężają już autoryzowany kompletny feed w pamięci strony; nie zmieniają kontraktu API ani uprawnień. Opcje osób to suma obu widocznych okresów. Wybrana osoba zostaje podczas nawigacji, zmiana zespołu czyści filtry. Bez localStorage i bez nowych zależności. |
| DT52 | „Zatwierdzone” pokazuje obowiązujące wersje, także oczekujące na zmianę/anulowanie; „Oczekujące” wyłącznie nowe wnioski; „Zmiany i anulowania” dostępne wersje powiązanego procesu. | Zmiana i anulowanie nie unieważniają dotychczasowego urlopu. Filtr nie wnioskuje o niedostępnej propozycji. Lista grupuje po wniosku, nie sumuje wersji i nie łączy dwóch niezależnych wniosków 3 h + 5 h. |
| DT53 | Szczegóły z kalendarza otwieramy w krótkim oknie tylko po wskazaniu `canViewDetails`, zawsze z nowym odczytem istniejącego API. | Backend ponownie autoryzuje każdą wersję. Modal czyści wcześniejszy DTO przed odświeżeniem, po odmowie i przy ukryciu strony. Zamknięcie zachowuje okres/filtry i przywraca fokus. Brak nowych uprawnień lub kontrolek decyzji; panel przełożonego pozostaje w etapie 10. |

## Decyzje realizacyjne etapu 10 — 7 listopada 2026 r.

Użytkownik zlecił etap 10. Nie pozostały pytania biznesowe blokujące ten zakres;
Q7 dotyczy konfiguracji przed produkcją. Zachowujemy B22–B33.

| ID | Decyzja wykonawcza | Uzasadnienie i granice |
|---|---|---|
| DT54 | `/management` domyślnie pokazuje wnioski wymagające decyzji aktora, z filtrem historii i stronicowaniem. | Backendowe `status=awaiting` łączy trzy stany, UI używa `actions`; własny wniosek zastępcy nie trafia do kolejki decyzji, pozostaje w jego historii. Nie ujawniamy statusu niedostępnej wersji. |
| DT55 | Podgląd decyzji i limitu/danych początkowych wykonuje istniejący zapis w wycofywanej transakcji. | Te same walidacje, blokady i obliczenia; brak trwałych zmian. Podgląd decyzji nie ujawnia bieżącego salda bez osobnego prawa. Potwierdzenie UI poprzedza kompatybilny zapis z wersją i kluczem; sam podgląd nie nadaje uprawnień. |
| DT56 | Historyczne korekty używają istniejącego podglądu z tokenem i jawnego potwierdzenia, bez ponownego implementowania rozliczeń na froncie. | Każda edycja unieważnia podgląd. Administrator osobno potwierdza niedobór. Niepewny zapis zachowuje treść, token i klucz; konflikt wymaga świeżego odczytu. |
| DT57 | Historia administracji ma osobny stronicowany odczyt administratora z jawną listą zdarzeń i pól. | UI pokazuje autora/czas i zmienione wartości jako tekst. Zdarzenia haseł mają tylko metadane; brak surowego JSON, sekretów, sesji, kluczy operacji i technicznych potwierdzeń. |
| DT58 | Salda zespołu są drugim widokiem istniejącego `/balances`, z rokiem, wyszukiwaniem i przejściem do osoby. | Lista uprawnionych osób jest pobierana ponownie; maksymalnie cztery równoległe odczyty sald. Brak sumowania wspólnych pul między latami. Własny limit kierownika i brak edycji zastępcy pozostają zgodne z B27. |

## Problemy i ograniczenia

### E15 — równoległe przekierowania wygasłej sesji w teście

CI #33, po dokumentacji etapu 9, zakończył się `net::ERR_ABORTED` podczas oczekiwania
na załadowanie strony logowania. Odczyt konta i otwartych szczegółów równolegle
otrzymały 401 i oba przekierowały na login. Test sprawdza teraz końcowy URL oraz
wyrenderowany formularz zamiast cyklu pojedynczej, zastąpionej nawigacji.
Asercje braku prywatnych danych i magazynu przeglądarki pozostają bez zmian.

### E14 — niezależne błędy opcji i kalendarza

Przegląd etapu 9 wykrył współdzielenie komunikatu błędu przez pobranie daty
serwerowej i kalendarza. Po ponowieniu wolniejszy sukces `/requests/options`
mógł usunąć błąd kalendarza i pozostawić wskaźnik ładowania bez zakończenia.
Oba odczyty mają teraz odrębny stan błędu. Regresja Chrome wymusza kolejność:
błąd kalendarza, opóźniony sukces opcji, widoczny komunikat i skuteczne ponowienie.

### E13 — pozycja dialogu na komputerze

Po zielonym CI #28 odbiór zrzutów wykrył dialog przy lewej górnej krawędzi:
reset CSS usunął domyślny margines okna. Dialog ma teraz jawne `inset: 0`
i `margin: auto`; telefon nadal używa pełnego ekranu z marginesem 0.
Test Chrome sprawdza wyśrodkowanie względem okna, oprócz dotychczasowych
kontroli rozmiaru i klawiatury.

### E12 — nawigacja klawiaturą w dialogu

CI #27 wykrył wyjście fokusu z dialogu przy Shift+Tab. Samo natywne `showModal()`
zapewnia nieaktywne tło, ale w Chrome nie gwarantowało oczekiwanego zawinięcia
klawiatury. Dodano jawne zawijanie Tab/Shift+Tab między widocznymi aktywnymi
kontrolkami, z obsługą początkowego nagłówka i wyłączonych fieldsetów. Zachowano
natywne Escape, nieaktywne tło i przywrócenie fokusu; asercja pozostaje bez zmian.

### E11 — wyścig syntetycznej sesji w regresji Chrome

[CI #25](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42402053433),
uruchomione po samym dopisaniu raportu etapu 7, zatrzymało test ekranu salda.
Test przełączał syntetyczną sesję na administratora po samej zmianie URL na login,
przed zakończeniem odczytu sesji przez formularz. Login wracał wtedy na stronę
główną i wykonywał GET, którego matcher błędnie oczekiwał jako zapisu z CSRF.
Mechanizm odtworzono na rzeczywistym komponencie React i kliencie API w lokalnym DOM.
Testy czekają teraz na gotowy anonimowy formularz przed zmianą sesji. Asercje CSRF
zapisów pozostają ścisłe; dodano diagnostykę metody i ścieżki. Produkcyjny klient
już pobierał świeży token dla każdej mutacji i odmawiał pustego tokenu, więc nie
wymagał zmiany. Odtworzenie i test klienta PASS; Chrome zostanie sprawdzony z etapem 8.

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

### E05 — kontrola istniejących tabel przed migracją

Pierwszy przebieg etapu 2, [CI #9](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42291369803),
wykrył wywołanie nieistniejącej w używanym DBAL metody `Schema::getTableNames()`.
Błąd wystąpił przed wykonaniem DDL. Kontrola kolizji korzysta teraz z `hasTable()`,
obsługiwanego również przez `LazySchema` z Doctrine Migrations. Katalog migracji
dodano do PHPStan, aby analiza obejmowała także ten kod. Wynik ponownego wykonania
migracji i pełnego odbioru zapisujemy w TEST_PLAN; pierwszy przebieg nie jest zaliczeniem.

### E06 — walidacja schematu w teście integracyjnym

[CI #10](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42291936845)
potwierdził migrację obu baz, zgodność schematu przez CLI, powtórne wykonanie migracji
i dwukrotne ładowanie demo. Jeden test bezpośredniego `SchemaValidator` zgłosił jednak
różnicę. Doctrine Migrations aktywuje filtr własnej tabeli metadanych przy zdarzeniu
`console.command`; bezpośredni walidator nie uruchamiał tego mechanizmu. Test wykonuje
teraz standardowe polecenie `doctrine:schema:validate` przez aplikację konsolową ze
zdarzeniami. Nie filtrujemy arbitralnie tabel aplikacji ani nie pomijamy zgodności
schematu. Pozostałe 77 testów tego przebiegu przeszło, lecz całość nie była zielona.

### E07 — lokalny frontend nie osiągał stanu healthy

Po etapie 2 użytkownik zgłosił `node: unhealthy`. Logi wykazały odpowiedzi HTTP 500
podczas przetwarzania CSS i brak możliwości załadowania
`@tailwindcss/turbopack/dist/index.js`. Projekt znajdował się w katalogu Linuksa WSL;
sam komunikat nie wskazywał na opóźnienia dysku Windows ani na brak Node.

Użytkownik potwierdził „Jest OK” po zatrzymaniu frontendu i instalatora, ponownym
`npm ci --include=dev`, sprawdzeniu `require.resolve`, usunięciu wyłącznie
wygenerowanego `.next`, uruchomieniu Node/Nginx z `--no-deps` i dokończeniu migracji.
Nie resetowano bazy, wolumenów ani lockfile i nie aktualizowano zależności.

W logach były trzy zakończone instalacje npm. Ponowne `npm ci` współdzielące
`node_modules` z uruchomionym Next jest możliwą przyczyną; brak znaczników czasu
nie pozwala potwierdzić tego mechanizmu dla tej awarii. Nie opisujemy hipotezy
jako ustalonej przyczyny. Przy aktualizacji przez `make init` należy najpierw
wykonać `make stop`, aby instalatory nie zmieniały zależności działających procesów.
Potwierdzenie użytkownika dotyczy lokalnego odzyskania działania, nie odbioru
produkcji ani nowego wyniku testów automatycznych.

### E08 — daty kalendarzowe po odczycie z bazy

[CI #18](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42356593584)
zaliczyło uruchomienie, migracje i zachowanie danych, lecz jeden z 268 testów
zatrzymał korektę dnia wolnego po transferze pracownika. Błąd odtworzono lokalnie
przy domyślnej strefie PHP `UTC`: DBAL odczytał SQL DATE jako północ UTC, a model
normalizował datę do północy Europe/Warsaw. Porównanie obiektów czasu uznawało
identyczną datę kalendarzową za inną chwilę.

Walidacja zakresów i granic przypisań porównuje teraz wartości `Y-m-d`, zgodnie
z semantyką dat nieobecności. Nie osłabiono kontroli tożsamości ani wersji zasad.
Testy integracyjne kalendarza jawnie używają domyślnego UTC i przywracają poprzednią
strefę. Pełny wynik kolejnego odbioru pozostaje w TEST_PLAN; CI #18 nie jest zaliczeniem.

### E09 — edycja wniosku i zmiana dostępnych pul

Przy testowaniu etapu 6 odtworzono błąd: oczekujące 6 h UoP finansowane starszą
pulą można było przenieść na nową datę B2B mimo zerowego przydziału bieżącego roku.
Dotychczasowa ochrona istniejącego zobowiązania porównywała tylko sumę godzin roku,
przez co taka edycja mogła utworzyć nowy niedobór w innej puli.

Warunek sprawdza teraz również podzbiór wymagający bieżącej puli: nowe godziny bez
przenoszenia nie mogą korzystać z ochrony większej niż wcześniejsze finansowanie
bieżącą pulą. Brak pokrycia powoduje pełne wycofanie edycji. Osobne testy potwierdzają
odmowę wariantu finansowanego zaległością, dopuszczenie wariantu z rocznej puli i
możliwość akceptacji istniejącej rezerwacji B2B po jawnej korekcie powodującej niedobór.
To uszczelnienie B26, nie zmiana zasad przenoszenia ani anulowanie innych urlopów.

### E10 — uzasadnienie ze współdzielonego zdarzenia audytu

Pełna regresja etapu 7 wykryła, że nowe pole `submitted.reason` pobierało ogólny
powód ze źródłowego zdarzenia audytu. Starsze dane mogą używać tego samego zdarzenia
jako decyzji poprzedniej wersji i źródła nowej; w takim przypadku powód ze starego
zespołu trafiał do odczytu nowego zespołu. Istniejący negatywny test nie został osłabiony.

Odczyt uzasadnienia zgłoszenia wymaga teraz zdarzenia `request.corrected` wskazującego
ten sam wniosek, właściwy rodzaj korekty oraz zgodną treść konkretnej wersji.
Ponowione testy API: 21 testów / 4625 asercji PASS. Pozostałe decyzje mają własne,
osobno kontrolowane uzasadnienie. Wynik pełnego odbioru zapisano w TEST_PLAN.

Test kalendarza z etapu 5 zaktualizowano do zatwierdzonej w etapie 7 zasady
pokrycia propozycji per rok/pula: przesunięte 16 h nie rezerwuje dodatkowych 8 h
wyłącznie ze względu na inny dzień. Dodanie wolnego nadal zwraca dokładnie 8 h,
a oba pozostałe terminy zachowują zajętość do decyzji.

## Format kolejnego wpisu

Każdy wpis zawiera datę, identyfikator, decyzję lub problem, uzasadnienie, zakres
wpływu, wynik weryfikacji i ewentualne pytanie. Odpowiedzi użytkownika należy
powiązać z identyfikatorem Q z OPEN_QUESTIONS i odpowiednią zmianą REQUIREMENTS.
