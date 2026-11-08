# Etap 11 — protokół pomiaru wydajności

Protokół zapisano przed pomiarem 8 listopada 2026 r. Pomiar wykonano w
[CI #39 — SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42487633929)
dla kodu `f8bc8b37a2e645d817524bedaffa9a8881ae8221`. Wszystkie 14 przypadków
zmieściło się w proponowanych budżetach diagnostycznych; brak ostrzeżeń.

## Środowisko i dane

Pomiar działa wyłącznie przy `CI=true`, przez lokalny HTTP (`localhost` albo
`127.0.0.1`), w developerskim stosie Docker Compose. Fixture wymaga osobnego
znacznika `PERFORMANCE_FIXTURE=ALLOW_SYNTHETIC_CI` i środowiska PHP `dev` lub `test`.
Nie usuwa istniejących danych i nie jest przeznaczona do bazy produkcyjnej.

Tworzy izolowane syntetyczne **100 osób, 5 zespołów i 2000 wniosków**: 20 kolejnych
jednogodzinnych nieobecności roboczych na osobę, po 10 oczekujących i zatwierdzonych.
Zespół mierzonego kierownika liczy 20 osób i 400 wniosków. Konta mają datowane
przypisania, limit 208 h i rzeczywiste rezerwacje/księgowania; wnioski powstają
przez istniejące usługi domenowe. Terminy wybiera kalendarz aplikacji, od następnego
dnia roboczego, z limitami obu lat przy przełomie roku. Czas budowy zestawu jest
raportowany osobno i nie wchodzi do czasów odczytu.

Przed rozpoczęciem pomiarów zapisuje się plan, wersje PHP/MySQL/Node/Chrome,
parametry hosta CI, daty, liczność nowego zestawu i łączne liczności bazy. Inne
syntetyczne dane wcześniejszych E2E pozostają w bazie i są ujawnione w licznościach.
Raport zawiera metadane oraz czasy, bez haseł, sesji, identyfikatorów kont i treści
odpowiedzi. Poświadczenia fixture pozostają w pamięci procesu sprawdzającego.

## Metoda i proponowane kryteria

To pomiar funkcjonalny małego zespołu przy większej bazie, bez generowania dużego
równoległego obciążenia. **Budżety diagnostyczne są propozycją wykonawcy**, a nie
uzgodnionym SLA dla firmowego serwera:

| Przypadek | Rozgrzewka | Próby | Proponowany budżet p95 |
|---|---:|---:|---:|
| API: kalendarz 14 dni | 2 | 10 | 2000 ms |
| API: lista nieobecności 61 dni (400 wniosków) | 2 | 10 | 2000 ms |
| API: własne wnioski, pierwsza strona 20 | 2 | 10 | 2000 ms |
| API: kolejka do decyzji, pierwsza strona 20 | 2 | 10 | 2000 ms |
| API: saldo jednej osoby | 2 | 10 | 2000 ms |
| API: lista 20 osób dostępnych dla sald | 2 | 10 | 2000 ms |
| Gotowy kalendarz / własne wnioski / kolejka / salda zespołu w Chrome, każdy desktop i mobile | 1 | 3 | 5000 ms |

Czasy API obejmują HTTP i odczyt JSON. Przeglądarka mierzy od rozpoczęcia nawigacji
do wyrenderowania danych; kalendarz oczekuje również na nadchodzące nieobecności,
a zestawienie sald na wszystkie 20 wierszy. Sprawdza błędy API/JS i przepełnienie
poziome. Zestawienie sald korzysta z istniejącego ładowania maksymalnie czterech
odczytów równolegle; liczba odpowiedzi API jest raportowana dla każdego widoku.

Próby są sekwencyjne. Nie ograniczamy sztucznie CPU ani sieci. Desktop ma
1440 × 1050, mobile 390 × 844; to emulacja rozmiaru i renderowania, nie fizyczny
telefon. Frontend to **serwer Next w trybie dev**, mimo osobnej kontroli produkcyjnego
builda w CI. Pierwszy przebieg rozgrzewa kompilację stron. Nie porównywać tych liczb
bezpośrednio z produkcyjnym SSR, rzeczywistym VPN ani wydajnością urządzeń.

p50/p95 są obliczane metodą najbliższej rangi. Przy 10 i 3 próbach p95 odpowiada
maksimum; niewielka próba nie stanowi statystycznego dowodu przepustowości.
Przekroczenia budżetów są jawnie wypisywane jako ostrzeżenia w raporcie i wymagają
oceny w odbiorze. Błędy funkcjonalne lub niedokończony pomiar kończą skrypt błędem.

## Uruchomienie i wynik

Po uruchomieniu developerskiego stosu i migracjach, w izolowanym CI:

```bash
NODE_PATH=/tmp/urlopy-ui/node_modules CI=true BASE_URL=http://localhost:8080 \
CHROME_PATH="$(command -v google-chrome)" node .github/scripts/performance-check.cjs
```

Skrypt uruchamia chronioną fixture i zapisuje `/tmp/urlopy-performance/report.json`.
Raport zapisuje się również po częściowym przebiegu lub błędzie, z ostatnim krokiem.
Należy zachować go jako artefakt CI i przepisać rzeczywiste czasy do końcowego
raportu odbioru. Nie zapisujemy żadnego wyniku pomiaru przed jego wykonaniem.

## Wynik CI #39 — 8 listopada 2026 r.

Przebieg pomiarowy trwał od 15:05:54 do 15:06:44 czasu Europe/Warsaw. Zbudowanie
syntetycznego zestawu zajęło **24 191 ms**, poza mierzonymi odczytami. Dodano
100 osób, 5 zespołów i 2000 wniosków (1000 zatwierdzonych, 1000 oczekujących).
Łącznie z wcześniejszymi danymi E2E baza zawierała **120 osób i 2019 wniosków**.
Mierzono zakres kierownika: 20 osób / 400 wniosków; kalendarz 8–21 listopada
oraz listę 8 listopada 2026 – 7 stycznia 2027.

Środowisko: PHP **8.3.36**, MySQL **8.0.32**, Node **22.23.4**, Chrome
**156.0.8078.79**, aplikacja `dev`; Linux x64 `7.0.0-1017-azure`, host CI z
4 logicznymi CPU (AMD EPYC 9V45) i około 15,6 GiB pamięci. To parametry hosta
raportowane przez Node, nie gwarantowana wyłączna rezerwacja zasobów.

| Odczyt API | Próby po rozgrzewce | p50 [ms] | p95 / maksimum [ms] | Budżet [ms] |
|---|---:|---:|---:|---:|
| Kalendarz 14 dni | 10 | 80,0 | 114,9 | 2000 |
| Kalendarz/lista 61 dni | 10 | 128,8 | 150,8 | 2000 |
| Własne wnioski, pierwsza strona | 10 | 29,8 | 38,1 | 2000 |
| Kolejka do decyzji, pierwsza strona | 10 | 44,6 | 50,0 | 2000 |
| Saldo jednej osoby | 10 | 27,4 | 445,8 | 2000 |
| Lista osób do sald | 10 | 26,3 | 34,2 | 2000 |

| Gotowy widok w Chrome | Próby po rozgrzewce | p50 [ms] | p95 / maksimum [ms] | Budżet [ms] |
|---|---:|---:|---:|---:|
| Kalendarz — desktop | 3 | 917,7 | 934,4 | 5000 |
| Moje wnioski — desktop | 3 | 273,3 | 893,2 | 5000 |
| Obsługa wniosków — desktop | 3 | 310,3 | 333,4 | 5000 |
| Salda zespołu — desktop | 3 | 933,7 | 1402,3 | 5000 |
| Kalendarz — mobile | 3 | 461,2 | 873,7 | 5000 |
| Moje wnioski — mobile | 3 | 285,5 | 868,0 | 5000 |
| Obsługa wniosków — mobile | 3 | 273,9 | 277,1 | 5000 |
| Salda zespołu — mobile | 3 | 884,9 | 1380,2 | 5000 |

Każdy zmierzony przebieg strony wykonał odpowiednio 6 odpowiedzi API dla
kalendarza, 3 dla własnych wniosków, 2 dla obsługi wniosków i 26 dla sald zespołu,
tak samo na desktop i mobile. Nie wystąpiły błędy JS/API ani poziome przepełnienie.
Najdłuższy odczyt API wyniósł 445,8 ms; najdłuższe uzyskanie gotowego widoku
1402,3 ms. To pojedyncze maksima małych prób, nie oszacowanie stabilnego p95
produkcyjnego ani przyczyny obserwowanego rozrzutu.

Surowe próbki i metadane są w artefakcie CI `urlopy-performance/report.json`.
Wynik potwierdza działanie tych odczytów w opisanym większym zestawie i lokalnym
środowisku dev. Nie obejmuje jednoczesnej pracy wielu użytkowników, opóźnień VPN,
fizycznego telefonu ani administracyjnego widoku wszystkich 100 osób; nie stanowi
produkcyjnego SLA. Nie zmieniano kodu aplikacji w celu dopasowania wyniku pomiaru.

## Zaobserwowana struktura odczytów przed pomiarem

Zestawienie sald wykonuje osobny odczyt na osobę, do czterech równolegle. Dla
20-osobowego zespołu oznacza to pięć partii; dla administratora z dostępem do
100 osób więcej. Ten pomiar obejmuje praktyczny zakres kierownika, nie całą
administrację wielozespołową. Listy wniosków mają stronicowanie i ograniczenie
liczby skanowanych kandydatów. Kalendarz ogranicza daty do 93 dni, ale liczba
wpisów rośnie z wielkością wybranego zespołu. Są to obserwacje kodu, nie wynik
benchmarku ani potwierdzone wąskie gardła; optymalizacje należy opierać na pomiarze.
