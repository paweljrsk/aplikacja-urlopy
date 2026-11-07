# Kalendarz roboczy i firmowe dni wolne

Etap 5, 7 listopada 2026 r. Reguły rozliczeń wynikają z wymagań aplikacji:
8 pełnych godzin dziennie, poniedziałek–piątek, daty w `Europe/Warsaw`.
`WorkingCalendar` wyklucza weekendy, polskie święta i aktywne firmowe dni wolne.
Nie używa połączenia internetowego podczas działania aplikacji.

## Zakres i źródła

Obsługiwane daty kalendarza: **2025–2100**. Rok pochodzenia pozostałych godzin może
być wcześniejszy; granica dotyczy dat nieobecności, nie historii pul.
Przyszłe lata są wyliczane według reguł zweryfikowanych 7 listopada 2026 r.
Zmiana przepisów wymaga świadomej aktualizacji kodu, danych testowych i źródeł.

Wykaz zweryfikowano w źródłach urzędowych:

- [Dz.U. 2025 poz. 296 — tekst jednolity ustawy o dniach wolnych od pracy](https://eli.gov.pl/eli/DU/2025/296/ogl),
  [tekst PDF](https://api.sejm.gov.pl/eli/acts/DU/2025/296/text.pdf), art. 1.
- [Dz.U. 2024 poz. 1965 — ustawa zmieniająca](https://eli.gov.pl/api/acts/DU/2024/1965/text.html),
  art. 1 i 5: dodanie Wigilii, wejście w życie 1 lutego 2025 r.
- [Gov.pl — daty dni wolnych w 2026 r.](https://www.gov.pl/web/portugalia/dni-wolne):
  kontrola dat ruchomych; strona zawiera też portugalskie dni wolne, których nie importujemy.

Stałe daty to 1 i 6 stycznia, 1 i 3 maja, 15 sierpnia, 1 i 11 listopada oraz
24, 25 i 26 grudnia. Ruchome: Wielkanoc, poniedziałek wielkanocny, pierwszy dzień
Zielonych Świątek i Boże Ciało. Datę Wielkanocy oblicza lokalny algorytm gregoriański;
pozostałe daty to odpowiednio +1, +49 i +60 dni. Kalendarz nie stosuje Wigilii do lat
sprzed 2025, które pozostają poza zakresem jego dat.

Święto wypadające w sobotę nie tworzy automatycznie wolnego poniedziałku.
Ustalony przez firmę termin dodatkowego dnia należy wprowadzić jawnie w kalendarzu.
Święto i weekend w tej samej dacie oznaczają nadal 0 godzin, bez podwójnego odliczenia.
Wspólny kalendarz dla form zatrudnienia wynika z wymagań tej aplikacji; aplikacja
nie wyznacza indywidualnych grafików ani formalnych uprawnień kadrowych.

## Kontrakt usług

`WorkingCalendar::hoursOn(date)` zwraca 0 lub 8. `days(start, end, hourlyHours)`
zwraca mapę dat roboczych do godzin, z pominięciem dat wolnych. Domyślnie pełne dni;
wariant godzinowy obejmuje jeden dzień na 1–7 godzin. Pusty wynik jest dozwolony
dla obliczenia zakresu, ale nie tworzy nowego pustego wniosku.

Arytmetyka działa na datach kalendarzowych, przez dodawanie kolejnych dni.
Nie dzielimy różnic znaczników czasu przez 86400; zmiana czasu nie zmienia liczby
dni ani godzin urlopu. Podział grudzień/styczeń zachowuje rok każdej daty.

## Zmiana firmowego dnia wolnego — B29 / Q9

Tylko aktywny administrator może zmienić firmowy kalendarz. Zbiorcza operacja dotyczy
wyłącznie dat późniejszych niż bieżący dzień w Warszawie. Święta ustawowe pozostają
niezależne: wyłączenie firmowego wpisu w święto ustawowe nie czyni go dniem roboczym.

Przebieg:

1. Administrator wybiera datę, nazwę i aktywność wpisu.
2. Podgląd pokazuje wpływ na aktualnie obowiązujące i oczekujące wersje wniosków:
   pracownika, referencję, godziny przed i po oraz anulowanie/wycofanie, jeśli znika
   cała nieobecność. Godziny propozycji zmiany są pełnym wymiarem propozycji,
   a nie dodatkową rezerwacją salda.
3. Administrator podaje powód i potwierdza podgląd. Ta operacja jest jawną decyzją
   administratora również dla korekty już zatwierdzonych urlopów.
4. W jednej transakcji zapisujemy kalendarz, nowe wersje, decyzje, rozliczenia,
   zajętość dni i audyt. Błąd dowolnego elementu wycofuje całą zmianę.

Dotychczasowe wersje wniosków i pierwotne godziny pozostają w historii.
Dodanie wolnego zwraca odpowiednie obciążenia/rezerwacje. Usunięcie wolnego może
zwiększyć obciążenie i ujawnić niedobór według B26; nie usuwa istniejących akceptacji.
Zajętość dnia nadal nie może przekroczyć 8 godzin, także dla rodzajów nieobciążających
salda. Jednodniowy zatwierdzony urlop po usunięciu jego ostatniego dnia jest anulowany
nową zatwierdzoną wersją anulowania; oczekujący wniosek jest wycofany.

Przy jednoczesnym obowiązującym urlopie i oczekującej zmianie najpierw kończymy starą
propozycję jako zastąpioną/wycofaną, zapisujemy korektę obowiązującej wersji, a potem
odtwarzamy nadal aktualną propozycję na nowej podstawie. Rozliczenie obejmuje oba
stany razem i uwzględnia tylko dodatkową rezerwację. Nie zwracamy tych samych godzin
dwa razy. Nie przeliczamy przy okazji innych dat niż wskazana data zmiany.

Przeszłości nie zmienia ta operacja. Wcześniejsze dane początkowe koryguje się jawnie
w rozliczeniach; indywidualne korekty wniosków należą do późniejszych etapów.

## Historia zasad po zmianie zatrudnienia

Nowe rewizje zapisują `RequestAssignmentContext` dla całego wskazanego zakresu,
również dla początkowo wykluczonych dni wolnych. Zawiera referencję przypisania
i kopię jego granic dat. Zamknięcie przypisania przy transferze nie zmienia tej kopii.
Przywrócenie dnia roboczego używa zatem pierwotnej formy zatrudnienia, zgodnie z B24.

Korekta kalendarza może kopiować kontekst tylko ze wcześniejszej wersji tego samego
wniosku z tym samym zakresem, trybem i wersją zasad. Nie ma flagi omijającej dowolnie
kontrolę przypisań. Przypisania użyte tylko w tym kontekście są chronione tak samo
jak przypisania użyte przez `RequestDay` i nie mogą być nadpisane w administracji.

Migracja zachowuje stare wersje bez dopisywania zmyślonej historii. Dla takich wersji
zachowujemy zasady zapisanych dni. Dodanie wcześniej wykluczonej daty jest dozwolone,
gdy jednoznacznie obejmuje ją przypisanie już zapisane we wniosku. Jeśli po transferze
nie można tego ustalić, podgląd zwraca `CALENDAR_LEGACY_CONTEXT_MISSING` z referencją
wniosku. Wymaga to indywidualnego uporządkowania; nie przyjmujemy po cichu obecnej
formy zatrudnienia. Ograniczenie dotyczy historii sprzed pełnego kontekstu etapu 5.

## API i konflikty

| Operacja | Wejście | Dostęp |
|---|---|---|
| `GET /api/calendar/{year}` | Rok 2025–2100 | Zalogowany użytkownik |
| `POST /api/calendar/preview` | `date`, `name`, `active` | Administrator |
| `POST /api/calendar/apply` | Te same dane oraz `previewToken`, `operationKey`, `reason` | Administrator |

Lista rozróżnia wpisy `statutory` i `company`; pokazuje także wyłączone wpisy firmowe.
Podgląd ma token obejmujący bieżący wpis kalendarza, wersje wniosków, użytkowników,
kont rocznych, ostatnie ruchy księgi i wynik obliczeń. Zmiana danych przed potwierdzeniem
daje 409 `CALENDAR_PREVIEW_STALE`, wymagający ponownego podglądu.

Zapisy używają wspólnej blokady administracji, następnie uporządkowanych blokad
użytkowników i wniosków. Ponowienie tego samego `operationKey` z identycznymi danymi
zwraca zapisany wynik, również po nadejściu wskazanej daty. Zmiana danych przy tym
samym kluczu daje 409 `IDEMPOTENCY_CONFLICT`. Klucz operacji nie zastępuje autoryzacji
ani ochrony CSRF.

## Weryfikacja

`WorkingCalendarTest` obejmuje daty świąt 2026, Wigilię, weekendy, brak automatycznego
poniedziałku zastępczego, rok przestępny, przełom roku, zmianę czasu i pełne godziny.
`CalendarAssignmentContextTest` sprawdza zachowanie zasad i odrzucenie obcego kontekstu.
`CompanyCalendarTest` sprawdza na MySQL wpływ na oba stany wniosku, pełne anulowanie,
zajętość niepłatnego wniosku, niedobór, nieaktualny podgląd, ponowienia, uprawnienia
oraz ochronę przypisania dla wykluczonego dnia. Faktyczne wyniki zbiorczego odbioru
pozostają w `TEST_PLAN.md`; opis scenariusza nie oznacza sam w sobie jego zaliczenia.
