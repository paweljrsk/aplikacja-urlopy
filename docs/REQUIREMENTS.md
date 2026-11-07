# Wymagania aplikacji urlopowej

Wersja 1.3 • stan uzgodnień z 7 listopada 2026 r., po odpowiedziach przed etapem 5.

Ten plik jest źródłem uzgodnionych wymagań biznesowych. Uzupełnia go rejestr
[decyzji](DECISIONS.md) i [otwartych pytań](OPEN_QUESTIONS.md). Propozycje przy otwartych
pytaniach nie są zatwierdzonymi odpowiedziami. Q1 oraz podstawowe
ustalenia Q5 i Q8 potwierdzono przed etapem 2 i zapisano poniżej. Kolejne odpowiedzi
aktualizują ten plik oraz rejestr decyzji przed implementacją zależnego zachowania.

Opis funkcji poniżej określa cel MVP, a nie aktualnie zaimplementowane możliwości.
Stan realizacji znajduje się w [TASKS.md](../TASKS.md).

## 1. Uzgodnione wymagania MVP

### 1.1. Cel i zakres

Aplikacja ma zapewnić wspólną informację, kto i kiedy jest nieobecny, uporządkować wnioski i decyzje oraz pokazywać dostępne saldo. Korzystają z niej wszyscy członkowie zespołu, niezależnie od formy zatrudnienia. Wszyscy procesują wnioski w nowym systemie. Formalne rozliczenia osób na UoP pozostają w kadrach.

Na początku system obejmie około 10 osób i jeden zespół. Model danych i uprawnienia mają obsługiwać rozbudowę do wielu zespołów. Użytkownik należy do jednego zespołu. Administrator ma dostęp do wszystkich zespołów.

Zakres MVP obejmuje: lokalne logowanie, administrację, kartoteki pracowników, formy zatrudnienia, rodzaje nieobecności, kalendarz roboczy, limity i saldo, wnioski, akceptację i odrzucenie, edycję i anulowanie, korekty przeszłości, kalendarz zespołu, listy wniosków oraz historię operacji.

M365 i Slack pozostają poza MVP. Responsywna aplikacja webowa ma działać na komputerze i telefonie. PWA pozostaje opcją późniejszego rozwoju; plan MVP nie wymaga instalacji ani pracy offline.

### 1.2. Środowisko i standard projektu

- Development w repozytorium `paweljrsk/aplikacja-urlopy`. Repozytorium może pozostać publiczne.
- Produkcja na własnym serwerze z Dockerem, z dostępem przez VPN/sieć wewnętrzną.
- W odczytanym szkielecie są: PHP 8.3, Symfony 7.4, Doctrine ORM i Doctrine Migrations, Next.js 16, React 19, TypeScript, Tailwind oraz Docker Compose z MySQL, Redis i RabbitMQ.
- Wersje powyżej opisują odczytany szkielet, nie są poleceniem aktualizacji zależności. Przed zmianami wykonawca sprawdza aktualny stan repozytorium i jego instrukcje, w tym `frontend/AGENTS.md`.
- Migracje Doctrine są źródłem zmian schematu. Rozliczenia i kontrola dostępu działają po stronie backendu.
- README opisuje uruchamianie, testy, konfigurację i obsługę. TASKS zawiera statusy `[ ]`, `[-]`, `[x]`. Zakończenie wymaga implementacji i weryfikacji.

### 1.3. Użytkownicy i logowanie

Konta zakłada administrator. Użytkownik loguje się służbowym adresem e-mail i indywidualnym hasłem. Samodzielna rejestracja jest wyłączona. Hasło tymczasowe musi zostać zmienione przy pierwszym logowaniu.

Kartoteka zawiera imię, nazwisko, e-mail, formę zatrudnienia, zespół, rolę, aktywność oraz dane limitów. Dodatkowo system przechowuje identyfikatory techniczne, skrót hasła i historię operacji. Nie zbieramy dokumentów, diagnoz ani opisów medycznych.

Administrator może zmienić zespół lub formę zatrudnienia osoby mającej historię urlopów. Zmiana obowiązuje od wskazanej daty; wcześniejsze dane i rozliczenia pozostają zachowane. Po zmianie zespołu przełożeni poprzedniego zespołu zachowują dostęp do nieobecności sprzed przeniesienia, a nowego zespołu — do nieobecności od daty przeniesienia. Pracownik i administrator widzą całą historię. Widoczność dotyczy dat nieobecności, nie daty złożenia wniosku. Nadal obowiązuje ograniczenie danych kalendarza współpracowników.

Od dnia przeniesienia oczekujące wnioski dotyczące przyszłych nieobecności obsługuje nowy przełożony. Wcześniejsze akceptacje pozostają ważne. Wniosek obejmujący dni przed przeniesieniem i po nim obsługuje administrator, bez automatycznego dzielenia. Poszczególne zespoły widzą tylko właściwe dla nich dni; pełne szczegóły przekraczające granicę zespołów pozostają dostępne pracownikowi i administratorowi.

Po zmianie formy zatrudnienia już złożone wnioski zachowują dotychczasowe zasady rozliczenia, również gdy dotyczą przyszłości. Nowe wnioski korzystają z formy obowiązującej w dniu nieobecności. Sama zmiana formy nie przelicza automatycznie wcześniejszych rozliczeń. Zapisane przypisania dni wniosku pozostają historią zasad rozliczeń; aktualna widoczność i obsługa są wyznaczane osobno (B22–B24).

| Czynność | Pracownik | Kierownik | Zastępca | Administrator |
|---|---|---|---|---|
| Kalendarz zespołu | Własny zespół | Własny zespół | Własny zespół | Wszystkie zespoły |
| Własne wnioski i saldo | Tak | Tak | Tak | Tak |
| Obsługa cudzych wniosków | Nie | W swoim zespole | W swoim zespole, zawsze | Wszystkie zespoły |
| Zatwierdzanie własnego wniosku | Nie | Tak | Nie | Tak |
| Obsługa rozpoczętych/przeszłych nieobecności | Nie | W swoim zespole | W swoim zespole | Wszystkie zespoły |
| Ustawianie limitów pracowników | Nie | W swoim zespole | Nie | Wszystkie zespoły |
| Zarządzanie kontami, zespołami i słownikami | Nie | Nie | Nie | Tak |

Administrator ma pełny dostęp do funkcji. To nie znosi automatycznie reguł poprawności dat, godzin ani rozliczeń. Własny limit kierownika zmienia administrator. Tryb korekt samych wniosków pozostaje do uzgodnienia przed etapem 7.

W kalendarzu współpracowników pokazujemy imię i nazwisko, daty, status oraz liczbę godzin nieobecności. Nie ujawniamy ich sald ani rodzaju nieobecności. Pracownik widzi własne szczegóły; uprawnione osoby obsługujące wnioski mają dostęp do danych potrzebnych do podjęcia decyzji.

### 1.4. Rodzaje nieobecności

| Rodzaj | Obciąża roczny limit płatnych dni | Akceptacja |
|---|---|---|
| Urlop wypoczynkowy / płatne dni wolne | Tak | Tak |
| Urlop bezpłatny / bezpłatne dni wolne | Nie | Tak |
| Inna nieobecność | Nie | Tak |

Nazewnictwo pierwszych dwóch pozycji może zależeć od formy zatrudnienia. Administrator zarządza nazwą, dostępnością dla form zatrudnienia, wpływem na saldo i aktywnością rodzaju. W MVP nie ma oddzielnych limitów dla dodatkowych rodzajów. Nieaktywny rodzaj nie jest dostępny dla nowych wniosków, lecz pozostaje czytelny w historii.

Zmiana ustawienia rodzaju „pomniejsza saldo” dotyczy tylko nowych wniosków. Wnioski już złożone zachowują dotychczasowe zasady wpływu na saldo; zmiana słownika nie przelicza ich automatycznie.

### 1.5. Dni i godziny

- Dzień pracy ma 8 godzin. Dni pracy to poniedziałek–piątek z wyłączeniem polskich świąt i skonfigurowanych dni wolnych.
- Formularz domyślnie wskazuje cały dzień, czyli 8 godzin. Użytkownik może zmniejszyć wymiar i podać liczbę godzin.
- Obsługujemy pełne godziny. Nie przyjmujemy 0, wartości ujemnych, ułamków ani ponad 8 godzin nieobecności w jednym dniu.
- Wniosek godzinowy podaje ilość godzin, bez godzin zegarowych „od–do”. Kalendarz nie może sugerować, że zna porę tej nieobecności.
- Jeden wniosek obejmuje albo zakres pełnych dni, albo pojedynczy dzień na 1–7 godzin. MVP nie obsługuje częściowego wymiaru godzinowego dla zakresu wielodniowego (Q1 rozstrzygnięte).
- Łączna liczba godzin aktywnych nieobecności jednej osoby danego dnia nie może przekroczyć 8, niezależnie od tego, czy rodzaj obciąża saldo.
- Daty nieobecności są datami lokalnymi. Granice dni i lat określa strefa `Europe/Warsaw`.

### 1.6. Limity i saldo

Administrator albo kierownik ustawia pracownikowi limit na konkretny rok. Dla pracownika określa się datę rozpoczęcia ewidencji w aplikacji. Na ten dzień dane początkowe obejmują:

- limit bieżącego roku;
- godziny wykorzystane **z bieżącej rocznej puli** do dnia poprzedzającego rozpoczęcie ewidencji;
- pozostałe godziny z wcześniejszych lat, osobno według roku ich pochodzenia.

Pozostałość starszych pul oznacza stan na dzień rozpoczęcia ewidencji, a nie pierwotną wielkość przeniesienia na początek roku. Nie odejmujemy od niej ponownie wcześniejszego wykorzystania. Wcześniejsze nieobecności są już uwzględnione w danych początkowych; późniejsze wprowadzenie historycznego wniosku nie może drugi raz pomniejszyć salda. Okres sprzed tej granicy poprawiamy wyłącznie jawną korektą danych początkowych z uzasadnieniem i historią zmian, bez dopisywania wniosków ponownie obciążających saldo. Po rozpoczęciu rozliczania wniosków data rozpoczęcia ewidencji pozostaje zablokowana (B28).

Limit można wprowadzać w dniach, przeliczanych po 8 godzin. Obliczenia wykorzystują pełne godziny. Saldo pokazujemy w godzinach oraz w przeliczeniu na dni i godziny, np. **68 h = 8 dni i 4 h**. Do zachowania reszty godzinowej przy danych początkowych i korektach potrzebne są pola godzinowe.

- Wniosek oczekujący rezerwuje godziny. Brak dostępnego salda blokuje złożenie wniosku obciążającego limit.
- Akceptacja zmienia rezerwację w zatwierdzone obciążenie, bez drugiego potrącenia.
- Odrzucenie lub wycofanie oczekującego wniosku zwalnia jego rezerwację.
- Upływ terminu nie powoduje kolejnego potrącenia: zmienia się prezentacja z zaplanowanego na wykorzystany.
- Rodzaje nieobciążające salda nadal podlegają kontroli godzin danego dnia i uprawnieniom.
- Pokazujemy osobno wykorzystane, zatwierdzone przyszłe, oczekujące, dodatkowo rezerwowane na zmiany oraz dostępne godziny.

Przykład kontrolny: limit 80 h → wniosek na 16 h → dostępne 64 h. Po akceptacji nadal 64 h. Po wykorzystaniu nadal 64 h.

Dla UoP niewykorzystane dni przechodzą na kolejne lata i zużywane są od najstarszej puli. Nie wygaszamy ich automatycznie. Dla pozostałych form, w tym B2B, niewykorzystane dni nie przechodzą. Limit kolejnego roku jest wpisywany osobno. Wniosek grudzień–styczeń rozlicza godziny według dat w odpowiednich latach. System rozróżnia rok nieobecności od roku pochodzenia wykorzystanej puli.

Oczekujące wnioski z poprzedniego roku zachowują rezerwacje. Przeniesienie dla UoP
udostępnia wolne godziny od początku nowego roku, bez kopiowania pul. Późniejsze
odrzucenie lub wycofanie starego wniosku udostępnia również zwolnione godziny.
Przed początkiem roku przyszły wniosek może korzystać z ręcznie wpisanego limitu
tego roku, lecz nie z przewidywanego przeniesienia (B25).

Korekta limitu lub danych początkowych wymaga uzasadnienia i może powodować jawny
niedobór. Pokazujemy ujemne saldo; nie anulujemy zatwierdzonych urlopów. Nowe
obciążenia zwiększające niedobór są blokowane, natomiast zatwierdzenie istniejącej
rezerwacji nie zwiększa obciążenia (B26). Kierownik nie zmienia własnego limitu;
wykonuje to administrator (B27).

Administrator może dodawać i usuwać firmowe dni wolne dla przyszłych dat, po
podglądzie skutków i jawnym potwierdzeniu korekt istniejących wniosków. Dodanie
wolnego zwraca odpowiednie godziny, usunięcie zwiększa obciążenie zgodnie z B26.
Jeśli znika cały jednodniowy urlop, operacja obejmuje jawne anulowanie/wycofanie.
Potwierdzenie administratora dla zatwierdzonych wniosków jest zapisaną decyzją.
Przeszłości nie przeliczamy zbiorczo (B29).

### 1.7. Wnioski, zmiany i korekty

Podstawowy przebieg: pracownik składa wniosek → system weryfikuje uprawnienia, kalendarz, godziny i saldo → rezerwuje godziny → uprawniona osoba akceptuje lub odrzuca. Oczekujący wniosek można edytować lub wycofać. Kierownik może osobno zaakceptować własny wniosek; nie przyjmujemy automatycznej akceptacji tylko z powodu jego roli.

Zmiana zaakceptowanego urlopu oraz jego anulowanie wymagają decyzji. Do czasu decyzji obowiązuje dotychczas zatwierdzony termin i wymiar. Odrzucenie proponowanej zmiany zachowuje pierwotny urlop. Anulowanie nie zwraca salda przed akceptacją anulowania.

Do obsługi rozpoczętych i przeszłych nieobecności uprawnieni są kierownik, zastępca i administrator. Zastępca nie otrzymuje przy tym prawa do zatwierdzania własnych wniosków. Szczegóły korekty bezpośredniej albo zmiany oczekującej na decyzję wymagają rozstrzygnięcia Q2.

Historia obejmuje autora operacji, czas, zmienione wartości, decyzję i powiązanie z wnioskiem/limitem. Rejestrujemy historię od pierwszych mutacji, a nie dopiero przy budowie ekranu historii.

### 1.8. Interfejs

Głównym ekranem jest kalendarz zespołu. Wygląd powinien korzystać ze stylistyki https://notus.pl i https://notus.pl/banki/ oraz zapewniać prostą, czytelną obsługę. Widok ma rozróżniać oczekujące i zaakceptowane nieobecności, także bez polegania wyłącznie na kolorze.

Potrzebne widoki: logowanie i zmiana hasła, kalendarz, formularz, moje wnioski i szczegóły, moje saldo, kolejka decyzji przełożonego, salda zespołu oraz administracja. Widoki mają działać na telefonie i komputerze. Dane z innych zespołów lub prywatne dane kolegów nie mogą trafiać do nieuprawnionego klienta przez API.

## 9. Granice MVP i późniejszy rozwój

Po MVP można dodać logowanie M365, powiadomienia Slack, PWA, indywidualne grafiki, integrację kadrową i dodatkowe rodzaje z osobnymi limitami. Te funkcje nie należą do obecnych promptów. Rozbudowę poprzedza analiza aktualnych wymagań i migracji danych.

W MVP formalna obsługa kadrowa pozostaje poza aplikacją; bezpieczeństwo i zgodność z firmowymi wymaganiami podlegają sprawdzeniu przed uruchomieniem. Godziny są ilością nieobecności w dniu, a nie harmonogramem godzin zegarowych.
