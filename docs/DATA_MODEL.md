# Model danych — etap 2

Stan implementacji: 6 listopada 2026 r. Model odpowiada [wymaganiom](REQUIREMENTS.md)
i decyzjom B18–B21 w [rejestrze decyzji](DECISIONS.md). Obejmuje 18 encji Doctrine
oraz tabelę łączącą, łącznie 19 tabel aplikacji. Wyniki wykonanych kontroli zapisujemy
w [planie testów](TEST_PLAN.md); sam opis modelu nie jest wynikiem testu.

## Tabele i odpowiedzialność

| Obszar | Tabele | Przechowywane dane |
|---|---|---|
| Użytkownicy i przypisania | `app_user`, `team`, `employment_type`, `staff_assignment` | Konto, aktywność, globalne uprawnienie administratora; okresy przypisania do zespołu, formy zatrudnienia i roli pracownik/kierownik/zastępca |
| Rodzaje nieobecności | `absence_type`, `absence_type_version`, `absence_type_availability` | Stały kod i aktywność rodzaju; wersje nazwy, wpływu na saldo i dostępności dla form zatrudnienia |
| Kalendarz | `holiday` | Data i nazwa dnia wolnego oraz aktywność wpisu; generator polskich świąt powstanie w etapie 5 |
| Początek ewidencji | `opening_balance`, `opening_carry` | Data startu pracownika, roczny limit, wykorzystanie bieżącej puli oraz pozostałości starszych pul według roku pochodzenia |
| Rozliczenia | `leave_account`, `hour_pool`, `ledger_entry` | Konto pracownika na rok nieobecności, pula według roku pochodzenia oraz niezmienne zapisy zmian przydziału/rezerwacji/obciążenia |
| Wnioski i decyzje | `leave_request`, `request_revision`, `request_day`, `request_decision` | Tożsamość wniosku, kolejne propozycje, dzienne godziny i powiązania historyczne, pojedynczy wynik kończący daną propozycję |
| Zajętość dnia | `daily_occupancy` | Wersjonowana suma godzin aktywnych nieobecności pracownika w dniu, niezależnie od wpływu rodzaju na saldo |
| Historia operacji | `audit_event` | Klucz operacji, akcja, autor, pracownik lub cel globalny, czas i dane przed/po zmianie |

## Historia pracownika i zasad

`staff_assignment` obowiązuje w przedziale **[valid_from, valid_until)**. Pusta data
końca oznacza otwarty przedział. Przy zmianie zamykamy dotychczasowy okres i tworzymy
nowy; poprzedniego zespołu, formy zatrudnienia i roli nie nadpisujemy. `request_day`
wskazuje przypisanie wykorzystane przy zapisie danego dnia. Konta i słowniki można
deaktywować, zachowując odwołania historyczne.

Nowe zasady rodzaju tworzy `AbsenceType::createVersion()`. `request_revision`
wskazuje konkretną wersję, więc późniejsza zmiana słownika nie przepisuje starego
wniosku. Kolejna propozycja w tym samym wniosku zachowuje wcześniej używaną wersję
tego samego rodzaju. Wersje rodzaju i dzienne dane propozycji nie mają operacji
nadpisujących ich treść w publicznym interfejsie modelu.

Nie rozstrzygnięto jeszcze widoczności starej historii po przeniesieniu ani sposobu
obsługi otwartych i przyszłych wniosków przy zmianie zespołu lub umowy — to pozostała
część Q5 przed etapem 4. Samo powiązanie historyczne nie nadaje uprawnień do odczytu.

## Propozycje, decyzje i obowiązująca nieobecność

`leave_request` ma stałą referencję i kolejne numery `request_revision`. Propozycja
może być pierwotnym zgłoszeniem, zmianą albo anulowaniem. Zmiana/anulowanie wskazuje
zatwierdzoną wersję bazową; anulowanie zachowuje dokładną treść tej nieobecności.
Dni zapisujemy osobno, dzięki czemu zakres grudzień–styczeń może trafić do dwóch
kont rocznych. Godzinowy wniosek zawiera jeden dzień na 1–7 h, a pełny dzień 8 h.

Propozycja bez `request_decision` jest oczekująca. Model dopuszcza jedną oczekującą
propozycję na wniosek. Wyniki końcowe to `approved`, `rejected`, `withdrawn` i
`superseded`; tej samej propozycji nie nadajemy drugiego wyniku. Aktualna nieobecność
to zatwierdzona propozycja o najwyższym numerze. Jeśli jest anulowaniem, brak
obowiązującej nieobecności. Oczekująca lub odrzucona zmiana pozostawia starą wersję
zatwierdzoną w mocy.

To struktura danych i podstawowe niezmienniki. Etap 2 nie udostępnia API składania
ani zatwierdzania wniosków i nie implementuje autoryzacji czy całego procesu decyzji.

## Początek ewidencji i źródło salda

`opening_balance` jest jednym zapisem początkowym na pracownika. Wykorzystanie
`current_year_used_hours` dotyczy **wyłącznie bieżącej rocznej puli do dnia przed
startem**. `opening_carry.remaining_hours` jest już pozostałością na dzień startu;
nie odejmujemy od niej historycznego wykorzystania drugi raz. Każdy stary rok ma
osobny wiersz. Przykład: limit 208 h, wykorzystane z bieżącej puli 40 h, pozostałe
16 h z 2025 r. oznaczają 184 h przed nowymi rezerwacjami.

`leave_account.leave_year` określa rok nieobecności, a `hour_pool.origin_year` rok
pochodzenia godzin. Pula może więc finansować nieobecności innego roku. Nie ma
ręcznie nadpisywanej kolumny będącej jedynym źródłem salda: `ledger_entry` przechowuje
trzy zmiany ze znakiem. Dostępne godziny konta można odtworzyć jako
`SUM(allocated_delta - reserved_delta - charged_delta)`.

| Rodzaj zapisu | Zmiana przydziału | Zmiana rezerwacji | Zmiana obciążenia |
|---|---:|---:|---:|
| `grant` | +h | 0 | 0 |
| `opening_used` | 0 | 0 | +h |
| `reserve` | 0 | +h | 0 |
| `release` | 0 | −h | 0 |
| `approve` | 0 | −h | +h |
| `cancel` | 0 | 0 | −h |
| `adjustment` | Dowolna zmiana ze znakiem; przynajmniej jedna wartość różna od zera | Według korekty | Według korekty |

Każdy zapis ma źródłowy `audit_event` i numer linii. Zapisy związane z nieobecnością
wskazują jej konkretny dzień; `opening_used` jest agregatem bez wymyślonego wniosku.
Akceptacja przenosi godziny z rezerwacji do obciążenia i sama nie zmniejsza
ponownie dostępnej liczby godzin.

Algorytm zasilania kont, wyboru najstarszych pul, przeniesienia roku oraz blokowania
wniosku bez salda powstaje w późniejszych etapach. Ujemne delty korekty są formatem
zapisu, a nie zgodą na ujemne saldo czy rozstrzygnięciem Q4. Zapis początku ewidencji
nie odtwarza pojedynczych historycznych wniosków; procedura ich korekt pozostaje w Q8.

## Ograniczenia i zapis współbieżny

| Warstwa | Co chroni |
|---|---|
| MySQL: klucze obce `RESTRICT` | Istnienie powiązań oraz ochrona wskazywanej historii przed usunięciem. Brak kaskadowego kasowania wniosków i rozliczeń. |
| MySQL: unikalności | E-mail, kody słowników i zespołów, konto użytkownik/rok, pula użytkownik/rok pochodzenia, początek ewidencji użytkownika, stara pula/rok, wersje rodzaju i wniosku, data w propozycji, jedna decyzja na propozycję, zajętość użytkownik/dzień, `operation_key` oraz para zdarzenie/numer linii rozliczenia. |
| MySQL: `CHECK` | Zakresy godzin i lat, dozwolone wartości ról/statusów/rodzajów zapisów, kolejność dat i zgodność znaków delt z typem zapisu. |
| Konstruktory i metody modelu | Dodatkowo spójność pracownika między obiektami, brak nakładania przypisań w agregacie, wariant pojedynczego dnia godzinowego, dostępność rodzaju dla umowy, zakres dnia, zachowanie wersji zasad i zawartości anulowania. |
| Doctrine: wersje optymistyczne | Wykrywanie zapisu opartego na nieaktualnym stanie współdzielonego użytkownika, rodzaju, wniosku, konta, puli lub zajętości dnia. |
| Przyszłe usługi aplikacyjne | Jedna transakcja dla autoryzacji, wniosku, decyzji, audytu, rozliczenia i zajętości; aktualna kontrola salda/kalendarza; obsługa konfliktu i ponownego żądania. |

Tworzenie przypisania zmienia licznik w `User`, wersja rodzaju licznik w
`AbsenceType`, a propozycja i decyzja licznik w `LeaveRequest`. Nowy zapis rozliczenia
zmienia liczniki **zarówno konta, jak i puli**, aby współdzielona pula była chroniona
również przy zapisach dla różnych lat nieobecności. Obiekty potomne i zmienione
agregaty trzeba zapisać atomowo. Po konflikcie należy ponownie odczytać stan i
sprawdzić reguły, nie ponawiać zapisu starych obiektów.

`daily_occupancy` jest projekcją dla wszystkich rodzajów nieobecności; ma zakres
0–8 h i wersję. Jej aktualizacja nie zachodzi automatycznie przy utworzeniu dnia
wniosku. Musi być częścią późniejszego procesu transakcyjnego, z uwzględnieniem
starego i proponowanego terminu. Pełna idempotencja żądań wymaga również obsługi
powtórzenia klucza operacji; unikalny indeks sam nie zwraca wcześniejszego wyniku.
Bezpośredni SQL może ominąć walidację modelu i wersjonowanie Doctrine. Ograniczenia
między wieloma wierszami, np. nakładanie przedziałów, nie są w całości wyrażone
samymi kluczami i `CHECK`.

## Audyt i daty

`audit_event` zawiera autora (możliwy brak dla operacji systemowej), pracownika,
którego dotyczy zdarzenie, oraz cel `target_type`/`target_reference`. Dla operacji
globalnej pracownik może być pusty, ale cel musi zostać wskazany. JSON przed/po jest
kopiowany przez serializację, aby późniejsza zmiana przekazanego obiektu nie zmieniła
już zbudowanego zdarzenia. Do audytu nie przekazujemy haseł, sekretów ani danych
medycznych. Same encje nie zapisują automatycznie audytu każdej administracyjnej
mutacji — za jego dołączenie odpowiada przyszła usługa wykonująca operację.

Daty urlopów, przypisań i początku ewidencji są kolumnami `DATE`, interpretowanymi
jako daty kalendarzowe `Europe/Warsaw`, bez pory dnia. Czasy utworzenia i operacji są
przechowywane w UTC z dokładnością do sekundy. Typ DBAL `utc_datetime_immutable`
normalizuje zapis i odczytuje `DATETIME` jako UTC, niezależnie od domyślnej strefy
procesu PHP.

## Migracja i dane demonstracyjne

[Migracja Version20261106210000](../backend/migrations/Version20261106210000.php)
dodaje tabele aplikacji do bazy etapu 1, który nie miał migracji biznesowych.
Przed zmianami sprawdza kolizje nazw tabel i odmawia nadpisania istniejącego lub
częściowo utworzonego schematu. Nie wykonuje resetu ani usuwania danych. MySQL DDL
zatwierdza zmiany niejawnie, dlatego migracja jawnie zwraca `isTransactional=false`.
Po błędzie należy sprawdzić stan bazy; nie zakładać automatycznego wycofania DDL.
`down()` celowo odmawia usunięcia tabel z historią. Odtwarzanie kopii lub naprawa
migracją wymaga osobnej, sprawdzonej procedury.

```bash
make db-migrate
make db-validate
make demo
```

`make demo` wywołuje `php bin/console app:demo:load --confirm-demo` w kontenerze PHP.
Ładowanie jest dozwolone tylko w `dev`/`test` i wymaga jawnej flagi. Zestaw zawiera
5 syntetycznych kont, wszystkie role, UoP/B2B, 2 zespoły, 3 rodzaje nieobecności,
dane początkowe i 2 przykładowe wnioski. Adresy mają domenę `example.test`;
nie tworzymy haseł logowania. Dane nie ładują się automatycznie podczas migracji
ani startu, a środowisko produkcyjne jest odrzucane.

Pierwsze ładowanie wymaga pustych tabel aplikacji i odbywa się w transakcji.
Ponowne ładowanie rozpoznaje marker zestawu, sprawdza liczności i kluczowe
identyfikatory, po czym pozostawia dane bez zmian. Nie nadpisuje istniejących
rekordów; kontrola zestawu nie jest porównaniem wszystkich wartości jego pól.

## Granica etapu

Model nie zastępuje przyszłych procesów ani kontroli dostępu. Nadal trzeba uzyskać
odpowiedzi z [OPEN_QUESTIONS](OPEN_QUESTIONS.md): Q2 (dzisiaj/korekty), Q3 (zmiana
roku), Q4 (niedobór), pozostała część Q5 (historia i przyszłe wnioski), Q6 (własny
limit kierownika), Q7 (produkcja), pozostała część Q8 (korekty przed startem)
oraz Q9 (zmiana kalendarza). Nie interpretujemy pól i enumów jako odpowiedzi
na te pytania. Etapy logowania, API i właściwych rozliczeń pozostają osobnymi pracami.
