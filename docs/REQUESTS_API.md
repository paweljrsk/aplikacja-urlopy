# API wniosków — etapy 6–7

Reguły biznesowe: [REQUIREMENTS.md](REQUIREMENTS.md), B18–B33 w
[DECISIONS.md](DECISIONS.md). Rozliczenia: [ACCOUNTING.md](ACCOUNTING.md).
Wyniki uruchomionych kontroli są osobno w [TEST_PLAN.md](TEST_PLAN.md).
Opis kontraktu nie zastępuje odbioru testów.

## Dostęp i zakres

Wszystkie endpointy wymagają aktywnej zalogowanej sesji z docelowym hasłem.
Każdy zapis wymaga `X-CSRF-Token` z `/api/auth/csrf`. Odpowiedzi mają
`Cache-Control: no-store, private`. Dane pracowników, salda, surowe encje i pełne
obiekty audytu nie są automatycznie dołączane do odpowiedzi wniosku.

| Operacja | Dostęp |
|---|---|
| Złożenie | Własny wniosek każdego użytkownika, z poprawnymi przypisaniami i danymi ewidencji |
| Edycja oczekującego nowego wniosku | Właściciel, jeśli zakres nie obejmuje wcześniejszych dat |
| Propozycja zmiany/anulowania zatwierdzonego urlopu | Właściciel; dotychczasowy i proponowany zakres od dzisiaj, bez innej oczekującej propozycji |
| Wycofanie oczekującego wniosku/propozycji | Właściciel; zakres propozycji i zastępowanej wersji nie obejmuje wcześniejszych dat |
| Korekta rozpoczętego/przeszłego wniosku | Uprawniony kierownik/zastępca/admin po podglądzie i potwierdzeniu; własny wniosek zastępcy koryguje kierownik albo admin |
| Historyczna korekta zwiększająca niedobór | Wyłącznie administrator, z dodatkowym jawnym potwierdzeniem niedoboru |
| Akceptacja i odrzucenie | Kierownik i zastępca dla obsługiwanych wniosków zespołu; administrator dla wszystkich zespołów |
| Decyzja o własnym wniosku | Kierownik i administrator; zastępca nie |
| Własne szczegóły i historia | Właściciel i administrator |
| Cudze szczegóły i historia | Przełożony wyłącznie dla wersji dopuszczonych przez zasady widoczności po przeniesieniu |

Tworzenie nie przyjmuje `userId`. Kierownik i administrator składają własne wnioski
tą samą drogą; rola nie omija walidacji dat, godzin i salda ani nie zatwierdza
wniosku automatycznie. Samoobsługa nie służy do wprowadzania cudzych wniosków.
Odrzucenie cudzej propozycji jest decyzją przełożonego; wycofanie jest operacją autora.

Dzisiejszy dzień jest dozwolony aż do jego końca w `Europe/Warsaw`. Składanie,
edycja i wycofanie własnego oczekującego wniosku nie obejmują wcześniejszych dat.
Nie kontrolujemy godziny rozpoczęcia dzisiejszej nieobecności, bo model przechowuje
liczbę godzin bez pory dnia. Uprawniony przełożony nadal może zdecydować o starym
oczekującym wniosku; rezerwacja nie wygasa przy zmianie dnia ani roku.

Etap 7 dodaje zmiany zatwierdzonego urlopu, anulowanie oraz bezpośrednie korekty
przeszłości według B32–B33. Formularz i docelowe widoki powstają w dalszych etapach;
proces jest dostępny przez API, bez nowego ekranu frontendu.

## Endpointy

`{id}` jest dodatnim identyfikatorem wniosku. Mutacje poza utworzeniem zwracają
HTTP `200`. Utworzenie zwraca `201`, także przy identycznym ponowieniu operacji.

| Metoda i ścieżka | Znaczenie |
|---|---|
| `GET /api/requests/options` | Aktualne aktywne rodzaje nieobecności i parametry formularza |
| `GET /api/requests` | Lista własna albo obsługiwanych wniosków |
| `GET /api/requests/{id}` | Szczegóły i dozwolone wersje historii |
| `POST /api/requests` | Nowy własny wniosek i rezerwacja |
| `POST /api/requests/{id}/edit` | Zastąpienie oczekującej wersji nową, z atomową korektą rezerwacji |
| `POST /api/requests/{id}/change` | Propozycja zmiany zatwierdzonego urlopu; zabezpieczenie dodatkowych godzin |
| `POST /api/requests/{id}/cancel` | Propozycja anulowania; dotychczasowy urlop nadal obowiązuje |
| `POST /api/requests/{id}/withdraw` | Wycofanie własnego oczekującego wniosku/propozycji i zwolnienie tylko odpowiedniej rezerwacji |
| `POST /api/requests/{id}/approve` | Akceptacja, zamiana rezerwacji na obciążenie |
| `POST /api/requests/{id}/reject` | Odrzucenie z obowiązkowym powodem i zwolnienie odpowiedniej rezerwacji |
| `POST /api/requests/{id}/correction-preview` | Podgląd historycznej korekty, bez zapisania zmian; wymaga CSRF |
| `POST /api/requests/{id}/correct` | Jawne potwierdzenie korekty z aktualnego podglądu |

### Dane zapisu

Treść jest obiektem JSON, maksymalnie 64 KiB. Nieznane pola są odrzucane.
Identyfikatory, wersja i godziny są liczbami całkowitymi JSON; napisy, ułamki,
wartości logiczne i `null` nie zastępują liczb.

| Pole | Wymaganie |
|---|---|
| `operationKey` | Każdy zapis; 1–64 liter ASCII, cyfr lub znaków `.` `_` `:` `-`; unikalne dla logicznej operacji użytkownika |
| `expectedVersion` | Operacje na istniejącym wniosku, również podgląd; dodatnia wersja z bieżącego odczytu |
| `absenceTypeId` | Utworzenie, edycja i propozycja zmiany; dodatni identyfikator rodzaju |
| `startDate`, `endDate` | Utworzenie, edycja i propozycja zmiany; daty `RRRR-MM-DD` w uporządkowanym zakresie |
| `mode` | Utworzenie, edycja i propozycja zmiany; `full_days` domyślnie albo `hourly` |
| `hours` | Tylko `hourly`: pełna liczba 1–7; start i koniec muszą wskazywać ten sam dzień |
| `reason` | Odrzucenie oraz podgląd/potwierdzenie korekty: niepuste uzasadnienie, maksymalnie 1000 znaków |

`full_days` oznacza 8 godzin za każdy dzień roboczy, z pominięciem weekendów,
świąt i aktywnych dni firmowych. Sam dzień wolny albo zakres bez godzin roboczych
jest niepoprawny. Limit 8 h dotyczy łącznej aktywnej nieobecności ze wszystkich
rodzajów: 3 h + 5 h jest dozwolone, kolejne godziny są blokowane.

Przykład złożenia:

```json
{
  "operationKey": "request-47dcf756-709e-4478-a7b9-d7ddc129c382",
  "absenceTypeId": 1,
  "mode": "hourly",
  "startDate": "2026-11-10",
  "endDate": "2026-11-10",
  "hours": 3
}
```

Przykład odpowiedzi:

```json
{
  "operation": {
    "id": 27,
    "reference": "99059ba6a8e8410eb58d4d47e7fc3a31",
    "version": 1,
    "revision": 1,
    "status": "pending"
  }
}
```

Potwierdzenie operacji nie zawiera salda ani całej historii. Identyczne ponowienie
zachowuje klucz i treść; nie tworzy nowego wniosku, decyzji ani drugiego rozliczenia.
Klucz należy do użytkownika. Użycie go z inną operacją lub innymi danymi powoduje
konflikt. `expectedVersion` chroni nowy zapis; nie zmienia tożsamości ponawianej
operacji. Odtwarzanie potwierdzenia nadal wymaga aktualnego dostępu do odpowiedniej
wersji wniosku. Potwierdzenie dotyczy wykonanego zapisu; późniejsze zmiany odczytaj
przez `GET /api/requests/{id}`.

## Przejścia stanu

| Stan | Operacja | Rezultat |
|---|---|---|
| Brak | Złożenie | `pending`; rezerwacja godzin i zajętości dnia |
| `pending` | Edycja | Nadal `pending`; stara wersja `superseded`, nowa wersja i końcowe rozliczenie w jednej transakcji |
| `pending` | Wycofanie | `withdrawn`; zwolnienie rezerwacji i zajętości |
| `pending` | Akceptacja | `approved`; obciążenie zastępuje rezerwację bez drugiego potrącenia |
| `pending` | Odrzucenie | `rejected`; wymagany powód, zwolnienie rezerwacji i zajętości |
| `approved` | Propozycja zmiany | `change_pending`; stary urlop obowiązuje, ewentualna dodatkowa rezerwacja |
| `approved` | Propozycja anulowania | `cancellation_pending`; stary urlop nadal obowiązuje bez zwrotu godzin |
| `change_pending` | Akceptacja | `approved`; nowa wersja obowiązuje, rozliczenie różnicy godzin |
| `change_pending` / `cancellation_pending` | Odrzucenie albo dozwolone wycofanie | `approved`; dotychczasowy urlop pozostaje, uwalniana tylko dodatkowa rezerwacja |
| `cancellation_pending` | Akceptacja | `cancelled`; zwrot godzin raz i zwolnienie zajętości |
| Historyczny `approved` | Potwierdzona korekta zmiany/anulowania | `approved` z nową obowiązującą wersją albo `cancelled`; decyzja i rozliczenie w tej samej operacji |
| Historyczny `pending` | Potwierdzona korekta zmiany/wycofania | Nadal `pending` z poprawioną wersją albo `withdrawn`; korekta sama nie zatwierdza pierwotnego wniosku |

Zwykła edycja nie wznawia odrzuconego lub wycofanego wniosku i nie zmienia
zaakceptowanego urlopu. Kolejna nowa próba akceptacji zakończonej wersji jest
konfliktem; ponowienie identycznej wcześniej wykonanej operacji jest bezpieczne.

Zapis wersji, decyzji, audytu, księgowań i dziennej zajętości jest atomowy.
Wspólna blokada z administracją zapobiega równoczesnej zmianie przypisań lub zasad
w trakcie składania. Przy edycji zachowujemy użyte wcześniej wersje rodzaju i
zapisane zasady dotychczasowego zakresu. Nowo dodane daty korzystają z przypisania
obowiązującego w tych dniach. Błąd któregokolwiek dnia lub roku wycofuje całą edycję.

## Zmiana i anulowanie zatwierdzonego urlopu

`/change` przyjmuje te same pola co `/edit`, w tym `expectedVersion` i `operationKey`.
`/cancel` przyjmuje `expectedVersion` i `operationKey`. Propozycje składa właściciel.
Jednocześnie istnieje najwyżej jedna oczekująca wersja. Nie ma edycji propozycji
przez `/edit`: właściciel może wycofać ją, a następnie zgłosić nową. Przełożony
podejmuje decyzję przez dotychczasowe `/approve` albo `/reject`.

Do decyzji obowiązuje stara wersja. Przy skróceniu z 16 h do 8 h zwrot 8 h następuje
dopiero po akceptacji. Przy zwiększeniu 16 h do 24 h zabezpieczamy dodatkowo 8 h;
odrzucenie zwalnia wyłącznie ten dodatek. Anulowanie zwraca godziny dopiero po
akceptacji. Zmiana rodzaju na nieobciążający nie zwalnia salda już przy zgłoszeniu.

Rezerwacje są sprawdzane według roku i dozwolonych pul. Przesunięcie 8 h z grudnia
na styczeń wymaga pokrycia w nowym roku, mimo niezmiennej sumy wniosku. Dla zajętości
każdego dnia chroniony jest większy wymiar ze starej i proponowanej wersji, plus
inne aktywne wnioski: 3 h → 5 h zajmuje 5 h; przeniesienie poniedziałku na wtorek
chroni oba dni do decyzji. Wersje nie sumują się podwójnie w tym samym dniu.

## Bezpośrednia korekta przeszłości

Korekta wymaga zakresu z wcześniejszą datą; dzisiaj nadal podlega B30. Nie służy do
ominięcia zwykłego obiegu przyszłych wniosków. Istniejąca propozycja zmiany/anulowania
blokuje korektę do czasu jej rozstrzygnięcia. Poprawiane daty nie mogą poprzedzać
początku ewidencji: ten okres obsługują korekty danych początkowych, bez powtórnego
obciążenia historycznymi wnioskami.

Najpierw wyślij `POST /api/requests/{id}/correction-preview`:

```json
{
  "expectedVersion": 4,
  "action": "change",
  "reason": "Korekta błędnie wpisanej liczby godzin",
  "absenceTypeId": 1,
  "mode": "hourly",
  "startDate": "2026-11-05",
  "endDate": "2026-11-05",
  "hours": 4
}
```

Dla `action: "cancel"` przekaż tylko `expectedVersion`, `action` i `reason`.
Podgląd nie przyjmuje `operationKey`; niczego nie zapisuje. Odpowiedź
`{ "preview": ... }` zawiera `id`, `version`, `action`, nieprzezroczysty `previewToken`,
`before`, `after`, `impact` i `requiresDeficitConfirmation`. `impact.hoursByYear`
pokazuje `year`, `beforeHours`, `afterHours`, `deltaHours` dla godzin obciążających
saldo (rodzaj nieobciążający ma tu 0; rzeczywisty wymiar jest w migawkach `before`
i `after`). `impact.pools` zawiera listy `before` i `after` według roku pochodzenia;
te informacje są dostępne tylko przy osobnym uprawnieniu do salda. Dla przełożonego, który ma
prawo do historycznego wniosku, ale nie do obecnego salda osoby, `impact.pools=null`.
Brak uprawnienia do salda nie znosi kontroli poprawności rozliczeń.

Potwierdzenie to `POST /api/requests/{id}/correct` z identyczną treścią podglądu,
uzupełnioną o `operationKey`, otrzymany `previewToken` i `confirm: true`.
Przed zapisem ponownie weryfikujemy dane, uprawnienia i aktualność podglądu;
zmieniony wniosek lub wpływ operacji wymaga nowego podglądu. Samo przesłanie
`confirm: true` bez prawidłowego podglądu nie upoważnia do zapisu.

Jeśli korekta zwiększa niedobór, tylko administrator może ją potwierdzić, dodając
`allowDeficit: true`. Pole nie jest domyślną zgodą ani obejściem limitu 8 h dziennie,
granicy ewidencji, zatrudnienia czy uprawnień do zespołu. Pozostałe zaakceptowane
urlopy i pochodzenie godzin pozostają zachowane. Zwykły nowy wniosek nie może
zwiększać niedoboru. Każda korekta zapisuje uzasadnienie i historię; ponowienie
z tym samym kluczem odtwarza potwierdzenie bez kolejnego zwrotu/obciążenia.

## Odczyty i prywatność

### Opcje formularza

`GET /api/requests/options` zwraca `today`, `timezone`, `hoursPerDay`, `modes` i
`absenceTypes`. Każdy aktywny rodzaj zawiera `id`, `code`, `revision`, `name`,
`deductsBalance`, `availableForCurrentEmployment`, `employmentTypeIds` oraz
`namesByEmploymentType`. Lista opisuje aktualne słowniki; nie potwierdza jeszcze
dopuszczalności wybranego zakresu. Backend ponownie sprawdza zatrudnienie i
dostępność rodzaju dla dat składanego wniosku. Nie ma tu cudzych profili ani sald.

### Lista

Parametry `GET /api/requests`:

- `scope=mine` (domyślnie) albo `scope=managed`; drugi zakres wymaga roli
  kierownika, zastępcy albo administratora. Lista obsługi może obejmować własne
  wnioski, lecz uprawnienie do ich decyzji jest osobno w `actions`.
- `limit`: 1–100, domyślnie 50.
- `beforeId`: kursor do kolejnej strony; dodatni identyfikator zwrócony w
  `nextBeforeId`. Porządek malejąco po identyfikatorze.
- `status`: opcjonalnie `pending`, `approved`, `rejected`, `withdrawn`, `cancelled`,
  `change_pending` lub `cancellation_pending`.

Odpowiedź: `{ "requests": [...], "nextBeforeId": liczba_lub_null }`.
Każda strona sprawdza najwyżej 500 kandydatów, po czym umożliwia kontynuację
kursorem. Strona listy obsługiwanej może być krótsza lub pusta, jeśli kandydaci
nie mają dostępnych wersji. Dopóki `nextBeforeId` nie jest `null`, klient może
kontynuować odczyt. Nie zwracamy liczby wszystkich ani niedostępnych wniosków.
Kursor nie pomija niesprawdzonych elementów. Filtr statusu nie ujawnia statusu
niedostępnej obecnej wersji.

Element listy zawiera `id`, `reference`, `user` (tylko identyfikator, imię i nazwisko),
`version`, `status`, `currentVisible`, `latestVisibleRevision` oraz `actions`.
Nie zawiera pełnej historii ani danych salda. `actions` to wartości logiczne
`edit`, `withdraw`, `approve`, `reject`, `change`, `cancel`, `correct`; stanowią wskazówkę dla interfejsu,
a uprawnienia i wersja są zawsze ponownie sprawdzane przy zapisie.

### Szczegóły

`GET /api/requests/{id}` zwraca `{ "request": ... }`. Poza polami listy zawiera
`current`, `effective`, `pending` oraz `history`. `effective` oznacza obowiązujący
urlop, `pending` oczekującą propozycję, a `current` zachowuje dotychczasową semantykę
(najpierw propozycja, potem obowiązująca wersja lub końcowa wersja zakończonego wniosku).
Każde pole wersji jest `null`, gdy wersja nie istnieje albo nie jest dostępna.
Każda wersja jest autoryzowana niezależnie przez
`RequestAccessPolicy`. Dostęp do aktualnego wniosku nie daje automatycznie dostępu
do wcześniejszego zakresu innego zespołu.

Jeżeli przełożony może czytać wyłącznie historyczne wersje, zachowuje tę historię,
ale `currentVisible=false`, `current=null`, `version=null`, `status=null` i wszystkie
`actions=false`. Odpowiedź nie ujawnia obecnych dat, rodzaju ani decyzji innego
zespołu. Jeśli osobno dostępna wersja nadal obowiązuje podczas niedostępnej
propozycji, może być pokazana w `effective`; `pending` pozostaje `null` i nie
ujawnia danych obcego zespołu. Brak jakiejkolwiek dostępnej wersji daje odmowę dostępu.

Wersja zawiera `id`, `revision`, `baseRevision` (numer zastępowanej wersji lub `null`),
`kind`, `mode`, `startDate`, `endDate`,
`totalHours`, `days` (`date`, `hours`), `absenceType` (`id`, `code`, `revision`,
`name`, `deductsBalance`), `submitted` (`at`, `actorName`, `reason`) i `decision`.
Decyzja to `null` albo `outcome`, `at`, `actorName`, `reason`. `submitted.reason`
pochodzi wyłącznie z korekty dotyczącej treści tej wersji; nie ujawnia ogólnego
uzasadnienia współdzielonego zdarzenia audytu starszej wersji.
Zwracamy wyłącznie te jawne pola; nie serializujemy kontekstu przypisań, surowego
`before/after`, kluczy idempotencji, skrótów payloadu ani technicznych potwierdzeń.

Odczyt, autoryzacja i projekcja odbywają się w jednej transakcji odczytowej.
Timestamps są w formacie ISO 8601; daty nieobecności nie zawierają czasu.

## Błędy

Wspólny format: `{ "error": { "code": "...", "message": "..." } }`.

- `400`: niepoprawny JSON lub przekroczony rozmiar treści.
- `401`: brak ważnej aktywnej sesji.
- `403`: brak uprawnień, niewłaściwy CSRF albo wymagane ustawienie hasła.
- `404`: nieistniejący identyfikator lub ścieżka.
- `409`: nieaktualna wersja, niedozwolone przejście stanu, kolizja operacji
  albo konflikt współbieżności/dziennej zajętości.
- `422`: niepoprawny zakres, godziny, rodzaj, uzasadnienie lub brak dostępnego salda.

Treść błędu nie zawiera wyjątków Doctrine, SQL ani danych innych pracowników.
