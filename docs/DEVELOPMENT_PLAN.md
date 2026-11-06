# Plan developmentu aplikacji urlopowej

Wersja 1.0 • 6 listopada 2026 r.

Plan obejmuje 12 etapów rozwoju repozytorium `paweljrsk/aplikacja-urlopy`.
Wymagania biznesowe znajdują się w [REQUIREMENTS.md](REQUIREMENTS.md), aktualne
odpowiedzi w [DECISIONS.md](DECISIONS.md), a nierozstrzygnięte kwestie
w [OPEN_QUESTIONS.md](OPEN_QUESTIONS.md). Status wykonania zapisujemy
w [TASKS.md](../TASKS.md), wyniki weryfikacji w [TEST_PLAN.md](TEST_PLAN.md).

Wykonujemy jeden wskazany etap naraz. Polecenie „Zacznij etap N” wystarcza, gdy
wykonawca ma dostęp do tej dokumentacji i aktualnego repozytorium. Po zakończeniu
przedstawia wyniki i instrukcję odbioru, a do kolejnego etapu przechodzi na polecenie.
Odpowiedzi potrzebne do reguł biznesowych uzyskuje przed zależną implementacją.

Prompty poniżej pochodzą z uzgodnionego planu. Odwołanie w prompcie 1 do dokumentu
„Aplikacja urlopowa — wymagania, etapy i prompty developmentu” oznacza teraz ten
pakiet dokumentacji w repozytorium. W późniejszych etapach aktualne REQUIREMENTS,
DECISIONS i OPEN_QUESTIONS rozstrzygają ustalenia doprecyzowane od wersji 1.0.

## 2. Zasady techniczne i odbioru

1. **Backend jest źródłem reguł.** Frontend może pokazywać podgląd, lecz backend ponownie sprawdza daty, godziny, saldo, status i uprawnienia.
2. **Historia rozliczeń zamiast ręcznie nadpisywanego salda.** Przydziały, dane początkowe, rezerwacje, obciążenia, zwroty i korekty muszą mieć źródło. Można stosować kontrolowaną projekcję salda, ale musi być odtwarzalna.
3. **Operacje atomowe.** Wniosek, jego stan, rezerwacja/obciążenie i audyt zapisują się spójnie w transakcji. Podwójne kliknięcie lub równoczesna decyzja nie mogą rozliczyć wniosku dwa razy.
4. **Synchroniczne rozliczenia.** Zatwierdzenie i saldo nie zależą od terminowego dostarczenia wiadomości przez RabbitMQ. Zachowujemy standard infrastruktury repozytorium, dobierając użycie usług do faktycznej potrzeby.
5. **Wersje i historia.** Zmiana słownika, limitu lub danych pracownika nie może niezauważenie przepisać historycznych rozliczeń. Obowiązywanie zmian ustalamy przed implementacją Q5.
6. **Bezpieczeństwo od początku.** Kontrola API, sesji i dostępu do obiektów, bezpieczne hasła, unieważnianie dostępu po dezaktywacji i ochrona mutacji mają powstawać razem z funkcjami. Końcowy etap bezpieczeństwa jest dodatkową weryfikacją.
7. **Publiczne repozytorium.** Sekrety produkcyjne, kopie bazy, dane pracowników i ich rzeczywiste salda nie mogą trafić do kodu ani danych demonstracyjnych. Dane testowe są syntetyczne.
8. **Powtarzalność.** Lockfile, migracje i udokumentowana konfiguracja muszą wystarczyć do uruchomienia. Nie ukrywamy błędów startu/migracji ani nie naprawiamy środowiska przez nieuzgodnione usuwanie danych.
9. **Testy właściwe dla funkcji.** Jednostkowe dla kalkulacji, integracyjne dla bazy/transakcji/uprawnień, E2E dla procesów użytkowników. Zegar testowy i kalendarz są kontrolowane. Testy współbieżności i migracji wykonujemy na zgodnym MySQL.
10. **Odbiór etapu.** Wykonawca podaje zmiany, uruchomione kontrole i wyniki, instrukcję sprawdzenia, nowe biblioteki z uzasadnieniem oraz pozostałe ograniczenia. Nie oznacza niewykonanych testów jako zaliczonych.

### Dokumentacja w repozytorium

| Plik | Zawartość |
|---|---|
| `README.md` | Technologie, start/stop, konfiguracja, testy, migracje i instrukcje użytkowe |
| `TASKS.md` | Etapy i zadania ze statusami `[ ]`, `[-]`, `[x]` oraz odniesieniem do weryfikacji |
| `docs/REQUIREMENTS.md` | Aktualne wymagania biznesowe, macierz ról, zasady godzin i salda |
| `docs/DEVELOPMENT_PLAN.md` | Plan etapów, kryteria odbioru i prompty |
| `docs/DECISIONS.md` | Uzgodnione odpowiedzi, decyzje techniczne, problemy i ich rozwiązania |
| `docs/OPEN_QUESTIONS.md` | Nierozstrzygnięte kwestie z etapem, przed którym blokują prace |
| `docs/TEST_PLAN.md` | Scenariusze akceptacyjne i instrukcje testów |
| `docs/OPERATIONS.md` | Wdrożenie, HTTPS/VPN, backup, odtworzenie, aktualizacje i rollback |
| `AGENTS.md` | Wspólny protokół pracy AI, z zachowaniem istniejących instrukcji repozytorium |

## 4. Etapy developmentu

Każdy etap korzysta z poprzednich. Testy i dokumentacja powstają na bieżąco.

| Etap | Zakres | Rezultat do odbioru |
|---|---|---|
| 1 | Audyt i uruchomienie repozytorium | Powtarzalny start, znany stan narzędzi i dokumentacja projektu |
| 2 | Model danych i migracje | Odtwarzalny schemat, powiązania, ograniczenia i dane syntetyczne |
| 3 | Logowanie i uprawnienia | Lokalne konta, bezpieczne sesje i kontrola dostępu w API |
| 4 | Administracja | Użytkownicy, zespoły, role, formy zatrudnienia i rodzaje nieobecności |
| 5 | Kalendarz roboczy i rozliczenia | Godziny, limity, salda początkowe, zaległości i przejście roku |
| 6 | Podstawowy proces wniosków | Złożenie, rezerwacja, akceptacja, odrzucenie i wycofanie |
| 7 | Zmiany, anulowania i korekty | Bezpieczna zmiana zaakceptowanego urlopu z historią |
| 8 | Formularz i widoki pracownika | Czytelna aplikacja w stylistyce Notus, własne wnioski i saldo |
| 9 | Główny kalendarz zespołu | Responsywny podgląd nieobecności i lista zgodne z widocznością danych |
| 10 | Widoki przełożonego i administratora | Kolejka decyzji, korekty, salda zespołu i historia |
| 11 | Weryfikacja całego systemu | Testy E2E, uprawnień, współbieżności, bezpieczeństwa i wydajności |
| 12 | Przygotowanie wdrożenia i odbiór | Docker produkcyjny, sprawdzone backup/restore i instrukcja pilotażu |

## 5. Wspólny protokół dla wszystkich promptów

Etap 1 utrwala poniższy protokół w dokumentacji repozytorium. Każdy kolejny prompt odwołuje się do niego.

```text
Pracujesz jako Senior Full Stack Developer w repozytorium paweljrsk/aplikacja-urlopy.
Wykonuj tylko wskazany etap, zachowując uzgodnione wymagania i standard repozytorium.

PRZED PRACĄ
1. Przeczytaj obowiązujące AGENTS.md, README.md, TASKS.md, docs/REQUIREMENTS.md,
   docs/DECISIONS.md, docs/OPEN_QUESTIONS.md i opis wskazanego etapu.
2. Sprawdź stan Git, aktualny kod i wyniki poprzedniego etapu. Zachowaj cudze zmiany.
3. Sprawdź braki, sprzeczności i zależności. Gdy odpowiedź wpływa na reguły,
   dostęp do danych lub rozliczenia, najpierw zadaj konkretne pytanie i zaczekaj
   z zależną implementacją. Nie traktuj propozycji z dokumentacji jako decyzji.
4. Oznacz realizowane zadanie jako [-]. Nie powtarzaj ukończonej pracy bez powodu.

W TRAKCIE
5. Zachowaj istniejący stack. Nową bibliotekę uzasadnij potrzebą, utrzymaniem,
   licencją, kompatybilnością i wpływem na bezpieczeństwo. Korzystaj z dokumentacji
   wersji zainstalowanej w projekcie, w tym wskazanej w frontend/AGENTS.md.
6. Reguły i uprawnienia egzekwuj w API. Zmiany schematu realizuj migracjami Doctrine.
   Zadbaj o transakcje, historię, brak podwójnego rozliczenia i ochronę istniejących danych.
7. Nie umieszczaj sekretów, rzeczywistych danych pracowników ani kopii bazy w repo.
   Nie resetuj bazy/wolumenów w ramach zwykłego startu, aktualizacji lub naprawy.
8. Pisz sensowne testy dodawanego zachowania; nie odkładaj ich na etap 11.

PO PRACY
9. Uruchom adekwatne kontrole i scenariusze odbioru. Opisz faktyczne wyniki,
   a brak możliwości wykonania testu wskaż jawnie. Nie deklaruj testu jako zaliczonego
   wyłącznie na podstawie przeglądu kodu. Napraw wykryte problemy w zakresie etapu.
10. Aktualizuj README, TASKS, wymagania, decyzje i test plan. Status [x] nadaj dopiero
    po implementacji i weryfikacji. Utrwal, od czego wznowić pracę.
11. Zakończ raportem: co działa, ważne zmiany/biblioteki, testy i wyniki, instrukcja
    ręcznego sprawdzenia, ograniczenia i pytania. Nie przechodź samodzielnie do następnego etapu.
```

## 6. Prompty etapów

### Prompt 1 — audyt, uruchomienie i dokumentacja

```text
Wykonaj etap 1 aplikacji urlopowej na repozytorium paweljrsk/aplikacja-urlopy.
Kontekst biznesowy i protokół pracy znajdują się w załączonym dokumencie
„Aplikacja urlopowa — wymagania, etapy i prompty developmentu”. Najpierw go przeczytaj.
Jeśli dokumentu lub repozytorium nie ma w środowisku, poproś o dostęp zamiast zgadywać.

Przeczytaj instrukcje repozytorium, w tym frontend/AGENTS.md. Sprawdź istniejący kod,
stan Git, Compose, Dockerfile, Makefile, zależności, konfigurację Symfony/Doctrine/Next,
narzędzia jakości i możliwości uruchomienia testów. Oddziel potwierdzone błędy od ryzyk.

Zweryfikuj m.in. zgodność next i eslint-config-next, efektywną konfigurację MySQL
w Doctrine, obsługę błędów init/migracji, hasła przykładowe oraz użycie obrazów latest.
Nie rób szerokiej aktualizacji zależności bez potrzeby. Napraw przeszkody niezbędne
do powtarzalnego startu; w razie niejasności o standardzie zespołu najpierw zapytaj.
Nie uruchamiaj komend reset/delete na danych użytkownika.

Utwórz lub uzupełnij README.md, TASKS.md, docs/REQUIREMENTS.md,
docs/DEVELOPMENT_PLAN.md, docs/DECISIONS.md, docs/OPEN_QUESTIONS.md,
docs/TEST_PLAN.md i szkielet docs/OPERATIONS.md. Przenieś wszystkie uzgodnienia
oraz otwarte pytania z planu. Utrwal wspólny protokół w głównym AGENTS.md,
zachowując istniejące instrukcje i frontend/AGENTS.md.

Odbiór: aplikację bazową da się uruchomić zgodnie z README; backend i frontend
odpowiadają, stan usług jest zweryfikowany, dostępne kontrole jakości są uruchomione.
Opisz start, stop i restart bez utraty danych. Wskaż osobno operacje destrukcyjne.
Nie implementuj jeszcze logiki urlopowej. Zakończ zgodnie ze wspólnym protokołem.
```

### Prompt 2 — model danych i migracje

```text
Wykonaj etap 2 zgodnie ze wspólnym protokołem i aktualną dokumentacją repozytorium.
Najpierw sprawdź, czy nierozstrzygnięte pytania wpływają na model; jeśli tak, zapytaj.

Zaprojektuj i wdroż model Doctrine: użytkownicy, zespoły, role, formy zatrudnienia,
rodzaje nieobecności i ich dostępność, dni wolne, roczne przydziały i salda początkowe,
pochodzenie puli godzin, wnioski z datami/godzinami, proponowane zmiany, decyzje
oraz historia operacji. Rozróżnij rok daty nieobecności i rok pochodzenia puli.
Przygotuj miejsce na rezerwacje, obciążenia, zwroty i korekty z identyfikacją źródła.
Nie ograniczaj modelu do nadpisywanej kolumny saldo. Obliczenia mają używać pełnych godzin.

Zdefiniuj klucze obce, indeksy, unikalność e-maila i zabezpieczenia przed podwójnym
rozliczeniem tej samej operacji. Uwzględnij współbieżność oraz zachowanie historii
po dezaktywacji konta lub pozycji słownika. Nie dodawaj danych medycznych.

Użyj Doctrine Migrations do utworzenia schematu. Dodaj syntetyczne dane dla wszystkich
ról, UoP i B2B oraz co najmniej dwóch zespołów do testów izolacji. Dane testowe nie mogą
zostać automatycznie załadowane na produkcję. Nie udostępniaj niezabezpieczonych endpointów.

Odbiór: migracje przechodzą na pustym MySQL, ponowne uruchomienie niczego nie dubluje,
walidacja schematu przechodzi, relacje i ograniczenia są sprawdzone. Opisz model,
strategię historii, źródło salda i bezpieczeństwo aktualizacji istniejącej bazy.
Nie twórz osobnego instalatora schematu zastępującego migracje Doctrine.
```

### Prompt 3 — logowanie lokalne i kontrola dostępu

```text
Wykonaj etap 3 zgodnie ze wspólnym protokołem. W MVP nie ma M365 ani Slacka.

Dodaj logowanie e-mailem i hasłem, bez samodzielnej rejestracji. Konta zakłada
administrator. Zaimplementuj hasło tymczasowe z wymuszoną zmianą przed dostępem
do funkcji aplikacji, wylogowanie i bezpieczny reset hasła przez administratora.
Nie wprowadzaj zależności od wysyłki e-maili. Zaproponuj bezpieczny sposób utworzenia
pierwszego administratora, bez stałego hasła w repozytorium.

Użyj mechanizmów bezpieczeństwa Symfony. Zaprojektuj sesje/cookies, ochronę CSRF,
limity prób logowania, bezpieczne skróty haseł i unieważnianie dostępu po dezaktywacji,
zmianie hasła/roli. Wyjaśnij wybór. Nie przechowuj haseł w historii ani logach.

Dodaj kontrolę roli, zespołu i konkretnego obiektu w API. Administrator ma pełny
dostęp; kierownik i zastępca obsługują własny zespół; pracownik własne szczegóły.
Kierownik może zatwierdzać własny wniosek, zastępca nie. Zapewnij podstawowe ekrany
logowania, zmiany hasła i chronionej aplikacji; pełny layout powstanie później.

Odbiór: poprawne i błędne logowanie, wymuszona zmiana hasła, logout, nieaktywny
użytkownik, brak sesji, zmiana identyfikatora obiektu na cudzy i próby dostępu między
zespołami są przetestowane. Testuj API, nie tylko ukrywanie przycisków.
Przygotuj architekturę umożliwiającą późniejsze dodanie M365 bez tworzenia integracji teraz.
```

### Prompt 4 — administracja użytkownikami i słownikami

```text
Wykonaj etap 4 zgodnie ze wspólnym protokołem. Przed edycjami wpływającymi na istniejące
wnioski rozstrzygnij Q5: zmiany zespołu/formy zatrudnienia i wpływu rodzaju na saldo.

Dodaj API i funkcjonalne ekrany administracji użytkownikami, zespołami, rolami,
formami zatrudnienia oraz rodzajami nieobecności. Użytkownik należy do jednego zespołu.
Administrator zarządza wszystkimi zespołami. Uwzględnij aktywację/dezaktywację kont
i bezpieczne nadawanie/reset hasła tymczasowego zgodnie z etapem 3.

Rodzaje startowe: urlop/płatne dni wolne obciążające wspólny limit, bezpłatne dni wolne
i inna nieobecność bez wpływu na limit. Wszystkie wymagają akceptacji. Administrator
ustawia nazwę, aktywność, dostępne formy zatrudnienia i wpływ na saldo. Bez oddzielnych
limitów dla dodatkowych rodzajów w MVP. Nazwy mogą zależeć od formy zatrudnienia.

Zachowaj historię kont i słowników użytych we wnioskach. Nie pozwalaj, aby zmiana
słownika samoczynnie przeliczała przeszłość. Zapisuj audyt zmian administracyjnych
bez sekretów. Zabezpiecz możliwość dalszego administrowania systemem.

Odbiór: utworzenie konta, przypisanie zespołu/roli, zmiana aktywności, filtrowanie
słowników i próby nieuprawnionych zmian. Testy potwierdzają izolację zespołów,
zachowanie historii oraz zgodność z odpowiedzią na Q5. Zarządzanie limitami będzie w 5.
```

### Prompt 5 — kalendarz roboczy, limity i saldo godzinowe

```text
Wykonaj etap 5 zgodnie ze wspólnym protokołem. Najpierw rozstrzygnij Q3, Q4 i Q6:
oczekujące stare wnioski/przewidywane przeniesienie, korekty powodujące niedobór
oraz zmiana własnego limitu przez kierownika. Rozstrzygnij też Q8 o dacie odniesienia
danych początkowych i Q9 o wpływie zmiany kalendarza na istniejące wnioski.

Zaimplementuj serwis kalendarza i rozliczeń: 8 h dziennie, pn–pt, polskie święta,
strefa Europe/Warsaw i pełne godziny. Zweryfikuj kalendarz świąt dla obsługiwanych lat
w aktualnym źródle urzędowym; dane mają być dostępne lokalnie w czasie pracy aplikacji.

Administrator/kierownik ustawia limit na rok. Oddziel wykorzystanie sprzed startu,
pozostałości z wcześniejszych lat, przydziały, rezerwacje, obciążenia i korekty.
Dodaj API i podstawowy ekran ustawienia limitów/danych początkowych. Limit w dniach
przeliczaj na godziny; prezentuj np. 68 h = 8 dni i 4 h. Nie używaj float do rozliczeń.

Uwzględnij lata od razu: UoP wykorzystuje najstarszą pulę, niewykorzystane godziny
przechodzą bez automatycznego wygaszenia; dla pozostałych form nie przechodzą.
Przydział nowego roku jest ręczny. Nie udostępniaj tej samej przeniesionej godziny
w dwóch pulach do równoczesnego wydania. Rozliczenie roku musi być idempotentne.

Przygotuj atomowe operacje rezerwacji, zwolnienia, zatwierdzenia i korekty dla kolejnych
etapów. Odróżnij brak skonfigurowanego limitu od limitu 0. Nie dubluj początkowego
wykorzystania i historycznych wniosków. Nie przeliczaj historii bez jawnej operacji.

Odbiór: weekend/święto, pełne godziny, 26 dni = 208 h, rok przestępny, podział
grudzień/styczeń, 16 h zaległości + 80 h nowego limitu = 96 h UoP / 80 h B2B,
powtórne rozliczenie roku, kolejność najstarszych pul oraz scenariusze Q3/Q4/Q6.
Testy uruchamiaj z kontrolowanym zegarem. Udokumentuj wzór salda i źródło każdej pozycji.
```

### Prompt 6 — wnioski, rezerwacje i decyzje

```text
Wykonaj etap 6 zgodnie ze wspólnym protokołem. Przed obsługą godzin dla zakresu dat
rozstrzygnij Q1, a przed regułami dat część Q2 o wnioskach na dzisiaj.
Nie wybieraj samodzielnie znaczenia liczby godzin dla wielu dni.

Dodaj API tworzenia, odczytu, edycji oczekującego wniosku, wycofania, akceptacji
i odrzucenia. Zapisz dozwolone przejścia statusów. Domyślnie pełny dzień 8 h,
możliwość podania mniejszej liczby pełnych godzin, bez pory dnia. Uwzględnij zakres
pełnych dni i pomijanie dni wolnych; wniosek bez godzin roboczych jest niepoprawny.

Backend sprawdza zespół, rodzaj, uprawnienia, daty, limit godzin danego dnia i saldo.
Aktywne nieobecności wszystkich rodzajów nie mogą przekroczyć 8 h dziennie.
Przy godzinach bez od–do nie wymyślaj kolizji zegarowych: 3 h + 5 h jest dopuszczalne.
Pracownik nie obsługuje rozpoczętych/przeszłych nieobecności; przypadek dzisiejszego
dnia stosuj zgodnie z Q2, a granice dat ustalaj w Europe/Warsaw.
Uprawnione korekty powstaną w etapie 7.

Złożenie rezerwuje saldo, akceptacja nie potrąca drugi raz, odrzucenie/wycofanie
zwalnia rezerwację. Kierownik akceptuje własny wniosek; zastępca może zawsze cudze
wnioski zespołu, ale nie własne; administrator ma pełny zakres zespołów.
Wszystkie mutacje, rozliczenie i audyt są atomowe. Zabezpiecz równoczesne operacje,
ponowne kliknięcie, nieaktualną wersję wniosku i próbę obejścia API.

Odbiór: 80 h → wniosek 16 h → dostępne 64 h także po akceptacji i po wykorzystaniu;
brak salda blokuje, nieobciążający rodzaj nie potrąca; przy dostępnych 8 h dwa
równoczesne wnioski po 6 h na różne dni robocze nie mogą oba przejść. Osobno sprawdź
równoczesne wnioski na ten sam dzień, także dla rodzaju nieobciążającego salda.
Ponowna akceptacja nie rozlicza drugi raz. Sprawdź macierz ról i wniosek przechodzący
przez dwa lata bez częściowego zapisu.
```

### Prompt 7 — edycja, anulowanie i korekty

```text
Wykonaj etap 7 zgodnie ze wspólnym protokołem. Najpierw rozstrzygnij Q2 dotyczące
korekt rozpoczętych/przeszłych wniosków oraz doprecyzuj Q4 dla korekty starego roku
po wykorzystaniu przeniesionej puli. Nie rozszerzaj prawa zastępcy do własnej akceptacji.

Dodaj proponowaną zmianę i anulowanie zaakceptowanego wniosku, decyzję o zmianie,
odrzucenie zmiany oraz wgląd w wersję obowiązującą i proponowaną. Do decyzji nadal
obowiązuje pierwotny urlop; odrzucenie zmiany go zachowuje. Zwracaj saldo po przyjęciu
skrócenia/anulowania, nie w chwili zgłoszenia.

Wyznacz dodatkową rezerwację tak, by chronić dotychczasowy urlop i pokryć nową
propozycję. Nie sumuj dwukrotnie zastępowanej wersji w kontroli godzin danego dnia.
Sprawdzaj zmiany per rok/pula i per dzień: przesunięcie 8 h z grudnia na styczeń
nie oznacza braku zapotrzebowania w nowym roku tylko dlatego, że suma się nie zmienia.
Obsłuż zmianę rodzaju obciążającego na nieobciążający i odwrotnie.

Przy oczekującej zmianie proponowane zabezpieczenie pojemności dnia to większy
wymiar z wersji obowiązującej i proponowanej plus inne aktywne wnioski. Przeniesienie
8 h z poniedziałku na wtorek chroni oba dni do decyzji; zmiana 3 h na 5 h tego samego
dnia zajmuje 5 h, nie 8 h. Nie zwalniaj starego dnia przed decyzją. Wyjaśnij tę strategię
w dokumentacji i sprawdź jej zgodność z ustalonym przepływem.

Dodaj uprawnione korekty przeszłości i rejestr ich wpływu na wykorzystanie/przeniesienie
według uzgodnionych zasad. Nie przepisuj po cichu zamkniętej historii, nie dubluj
zwrotów i nie anuluj innych zaakceptowanych urlopów przy niedoborze.

Odbiór: 16 h → propozycja 24 h rezerwuje dodatkowo 8 h; odrzucenie uwalnia tylko
dodatkowe 8 h. 16 h → propozycja 8 h zwraca 8 h dopiero po decyzji. Anulowanie zwraca
raz. Sprawdź zmianę roku, typu, korektę po przeniesieniu, równoczesne decyzje i role
pracownik/kierownik/zastępca/admin. Zapisuj kompletną historię starych i nowych wartości.
```

### Prompt 8 — formularz i widoki pracownika

```text
Wykonaj etap 8 zgodnie ze wspólnym protokołem. Korzystaj z gotowych API i reguł,
nie buduj drugiego niezależnego silnika salda w frontendzie. Sprawdź odpowiedź na Q1.

Przygotuj spójny responsywny layout nawiązujący do notus.pl i notus.pl/banki:
czytelna typografia, kolory, logo, prosta nawigacja i jednoznaczne działania.
Zweryfikuj materiały wizualne przed użyciem; nie kopiuj trackerów ani zewnętrznych
skryptów strony marketingowej. Użyte zasoby aplikacji podawaj lokalnie.

Dodaj formularz nieobecności: rodzaj, data/zakres, domyślnie cały dzień 8 h,
możliwość zmniejszenia wymiaru do pełnych godzin. Pokaż liczbę godzin obliczoną
przez backend, aktualne dostępne saldo i wpływ wniosku. Nie pytaj o godzinę od–do.
Regułę zakresów godzinowych zastosuj zgodnie z Q1.

Dodaj „Moje wnioski”, szczegóły, status, historię, wycofanie oczekującego,
zgłoszenie zmiany/anulowania i „Moje saldo” z rozbiciem pozycji. Rozróżnij zatwierdzony
urlop od oczekującej propozycji jego zmiany. Obsłuż ładowanie, pusty wynik,
błąd walidacji, brak uprawnień, wygaśnięcie sesji i konflikt równoczesnej edycji.

Odbiór: na komputerze i telefonie użytkownik składa wniosek domyślnie na 8 h,
zmienia go na 3 h, widzi rezerwację i decyzję, zgłasza zmianę/anulowanie oraz rozumie
saldo. Sprawdź klawiaturę, etykiety pól i brak ponownego wysłania po podwójnym kliknięciu.
Główny kalendarz zespołu zostanie ukończony w etapie 9.
```

### Prompt 9 — kalendarz zespołu

```text
Wykonaj etap 9 zgodnie ze wspólnym protokołem. Zbuduj kalendarz jako ekran startowy
po zalogowaniu. Zaproponuj czytelny widok miesiąca na komputerze i wygodny widok
listy/dni na telefonie, z łatwym przejściem do dzisiaj i dodania wniosku.

Pokaż członków dostępnego zespołu i ich nieobecności z imieniem/nazwiskiem, datami,
statusem i godzinami. Odróżnij oczekujące i zaakceptowane wpisy oraz zaznacz dni wolne.
Dla nieobecności częściowej pokaż np. 3 h, bez wymyślonego przedziału zegarowego.
Wersję obowiązującą odróżnij od propozycji zmiany, aby nie sugerować dwóch urlopów.

Dodaj listę nieobecności, zmianę okresu i przydatne filtrowanie osoby/statusu.
Zakres zespołów wynika z API: pracownik/kierownik/zastępca tylko własny zespół,
administrator wszystkie. W danych dostępnych współpracownikom nie ujawniaj rodzaju,
salda ani prywatnych szczegółów. Uprawniony użytkownik może przejść do odpowiednich
szczegółów z osobno kontrolowanego endpointu.

Odbiór: syntetyczny zespół 10 osób, kilka równoczesnych nieobecności, 3 h + 5 h
w jednym dniu, długie nazwiska, przełom miesiąca/roku, pusty kalendarz i telefon.
Sprawdź opisy statusów niezależne od kolorów i negatywne testy odczytu cudzych danych.
Zweryfikuj widok na małym i dużym ekranie, popraw wykryte problemy układu.
```

### Prompt 10 — obsługa przełożonego, salda i historia

```text
Wykonaj etap 10 zgodnie ze wspólnym protokołem. Wykorzystaj procesy i uprawnienia
z wcześniejszych etapów; nie zmieniaj reguł biznesowych przy budowie ekranów.

Dodaj kolejkę wniosków do decyzji: nowe wnioski, propozycje zmiany i anulowania.
Pokaż wniosek, wymiar, wpływ na saldo oraz przy zmianie porównanie wersji
obowiązującej i proponowanej. Kierownik widzi też własne wnioski do akceptacji;
zastępca nie może zaakceptować własnego. Administrator może działać między zespołami.

Uzupełnij widok sald zespołu i edytor rocznych przydziałów/danych początkowych/korekt
dla właściwych ról, zgodnie z Q3/Q4/Q6. Dodaj obsługę rozpoczętych i przeszłych
nieobecności według Q2. Przed zapisem pokaż wpływ operacji; rejestruj audyt.

Dodaj przegląd historii wniosku oraz zmian limitów i administracji dla uprawnionych
osób. Rozróżnij autora zgłoszenia od osoby decydującej. Nie ujawniaj haseł, sesji
ani nadmiarowych danych w historii. Zweryfikuj kompletność wcześniejszych wpisów audytu.

Odbiór: kierownik akceptuje własny wniosek, zastępca cudzy lecz nie własny,
administrator działa w drugim zespole. Korekta historyczna i limit mają widoczny
ślad oraz poprawne saldo. Nieaktualny ekran i dwie równoczesne decyzje prowadzą
do czytelnego wyniku, bez podwójnego rozliczenia. Sprawdź też widoki na telefonie.
```

### Prompt 11 — testy całego systemu i usunięcie problemów

```text
Wykonaj etap 11 zgodnie ze wspólnym protokołem. To weryfikacja całości; wcześniejsze
etapy powinny już mieć testy. Najpierw porównaj wdrożone zachowanie z REQUIREMENTS,
odpowiedziami w DECISIONS oraz scenariuszami TEST_PLAN. Wykryte luki zapisz w TASKS.

Uruchom i uzupełnij E2E dla pełnego procesu pracownika, kierownika, zastępcy i admina:
logowanie, zmiana hasła, wniosek pełny/godzinowy, decyzja, zmiana/anulowanie, saldo,
korekta, historia i izolacja zespołów. Sprawdź wnioski nieobciążające salda, przełom
roku i korekty po przeniesieniu. Używaj kontrolowanego czasu i danych syntetycznych.

Wykonaj negatywne testy API, próby zmiany właściciela/zespołu przez payload,
własnej akceptacji zastępcy i odczytu ukrytych danych. Sprawdź sesje, CSRF, XSS,
walidację, logowanie, dezaktywację, ujawnianie błędów oraz zależności i sekrety w repo.
Zastosuj dostępne skanery i narzędzia analizy; nie deklaruj zgodności regulacyjnej
na podstawie samego skanu. Przypadki współbieżności przetestuj na MySQL.

Sprawdź działanie na komputerze i telefonie oraz czas odpowiedzi podstawowych
widoków przy danych większych niż początkowe 10 osób. Przed pomiarem zapisz
środowisko, rozmiar danych i uzgodnione/proponowane kryteria; nie podawaj wyniku bez pomiaru.

Napraw znalezione problemy w zakresie MVP i ponów adekwatne testy. Odbiór: raport
faktycznie wykonanych kontroli, wyniki, instrukcja ręcznego odbioru oraz jawna lista
pozostałych blokad. Nie oznaczaj systemu jako gotowego przy niesprawdzonym saldzie,
niesprawnych uprawnieniach lub niewyjaśnionych błędach krytycznych.
```

### Prompt 12 — przygotowanie produkcji i pilotażu

```text
Wykonaj etap 12 zgodnie ze wspólnym protokołem. Przygotuj wdrożenie na własny serwer
Docker dostępny przez VPN; nie używaj hostingu Sites. Przed konfiguracją docelową
rozstrzygnij Q7 i pozostałe kwestie operacyjne, bez proszenia o sekrety w publicznym repo.

Przygotuj i sprawdź konfigurację produkcyjną: osobne środowiska, sekrety poza kodem,
HTTPS/reverse proxy, wyłączony debug, ograniczony dostęp do usług infrastruktury,
trwałe dane, healthchecki i poprawne uruchomienie po restarcie. Nie uruchamiaj
demonstracyjnych kont ani danych na produkcji. Uwzględnij bezpieczny bootstrap admina.

Uzupełnij README i OPERATIONS: pierwszy start, stop/restart bez utraty danych,
logi, aktualizacja z migracjami Doctrine, backup MySQL, bezpieczne przechowywanie,
odtworzenie i plan powrotu po nieudanej aktualizacji. Reset kontenerów/wolumenów
nie może być procedurą aktualizacji. Rollback musi uwzględniać zgodność kodu i schematu.

Wykonaj próbne odtworzenie backupu na odizolowanej bazie. Sprawdź nie tylko powstanie
pliku kopii, ale też odczyt kont, wniosków, sald i historii po przywróceniu. Przetestuj
restart usług z zachowaniem danych oraz aktualizację istniejącej testowej bazy.

Przygotuj instrukcję pilotażu dla 10 osób: konta, zespoły, limity, dane początkowe,
przykładowy obieg wniosku i lista odbiorowa. Opisz odpowiedzialności oraz znane
ograniczenia MVP. Samo przygotowanie planu nie upoważnia do publikacji ani wykonania
operacji na rzeczywistym serwerze; wykonaj je dopiero w uzgodnionym zakresie dostępu.

Odbiór: działający wariant produkcyjny w środowisku weryfikacyjnym, udane backup/restore,
sprawdzone migracje i instrukcje. Wyraźnie odróżnij przygotowanie wdrożenia od faktycznej
instalacji na firmowym serwerze. Nie oznaczaj zdalnego wdrożenia jako wykonanego bez dowodu.
```

## 8. Uwagi do bazowego repozytorium

Odczyt szkieletu wskazuje konkretne miejsca do sprawdzenia w etapie 1. Nie są to wyniki uruchomienia aplikacji ani skanu bezpieczeństwa.

- `frontend/package.json`: różne główne wersje `next` i `eslint-config-next` — sprawdzić kompatybilność.
- `backend/config/packages/doctrine.yaml`: odniesienie do PostgreSQL przy MySQL w Compose — sprawdzić efektywną konfigurację i usunąć nieadekwatne pozostałości, jeśli są zbędne.
- Compose/Makefile: przykładowe jawne hasła — oddzielić dane lokalne od konfiguracji produkcji.
- `make delete/reset`: operacje mogą usuwać wolumeny i dane — jasno opisać i nie stosować jako zwykłego restartu.
- `make init`: część błędów jest ignorowana — sprawdzać faktyczny wynik uruchomienia i migracji.
- Dockerfile: obrazy oznaczone `latest` — ustalić odtwarzalne wersje bez nieuzgodnionej zmiany standardu.
