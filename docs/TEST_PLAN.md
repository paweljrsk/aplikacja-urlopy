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

Raport wykonania należy uzupełnić po przeprowadzeniu kontroli. Na etapie tworzenia
tej dokumentacji nie zapisano tu wyników uruchomienia aplikacji.

| Kontrola | Dowód / wynik |
|---|---|
| Instalacja zależności z lockfile | Do uzupełnienia po wykonaniu |
| Start backendu i odpowiedź HTTP | Do uzupełnienia po wykonaniu |
| Start frontendu i odpowiedź HTTP | Do uzupełnienia po wykonaniu |
| Stan usług Compose | Do uzupełnienia po wykonaniu |
| Kontrole jakości dostępne w repozytorium | Do uzupełnienia po wykonaniu |
| Start/stop/restart bez utraty danych | Do uzupełnienia po wykonaniu |
| Zgodność dokumentacji z uzgodnionym planem | Do uzupełnienia po sprawdzeniu |

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
