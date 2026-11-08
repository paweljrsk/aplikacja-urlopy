# Etap 11 — protokół pomiaru wydajności

Protokół zapisano przed pomiarem 8 listopada 2026 r. Wyniki pozostają do potwierdzenia
w CI; obecność skryptu nie oznacza wykonania testu.

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

## Zaobserwowana struktura odczytów przed pomiarem

Zestawienie sald wykonuje osobny odczyt na osobę, do czterech równolegle. Dla
20-osobowego zespołu oznacza to pięć partii; dla administratora z dostępem do
100 osób więcej. Ten pomiar obejmuje praktyczny zakres kierownika, nie całą
administrację wielozespołową. Listy wniosków mają stronicowanie i ograniczenie
liczby skanowanych kandydatów. Kalendarz ogranicza daty do 93 dni, ale liczba
wpisów rośnie z wielkością wybranego zespołu. Są to obserwacje kodu, nie wynik
benchmarku ani potwierdzone wąskie gardła; optymalizacje należy opierać na pomiarze.
