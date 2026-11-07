# API wniosków — etap 6

Reguły biznesowe: [REQUIREMENTS.md](REQUIREMENTS.md), B18–B31 w
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
| Edycja i wycofanie | Właściciel oczekującego wniosku, jeśli zakres nie obejmuje wcześniejszych dat |
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

Indywidualna zmiana zatwierdzonego urlopu, anulowanie oraz korekty przeszłości są
zakresem etapu 7. Formularz i docelowe widoki powstają w dalszych etapach; etap 6
udostępnia proces przez API, bez nowego ekranu frontendu.

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
| `POST /api/requests/{id}/withdraw` | Wycofanie własnego oczekującego wniosku i zwolnienie rezerwacji |
| `POST /api/requests/{id}/approve` | Akceptacja, zamiana rezerwacji na obciążenie |
| `POST /api/requests/{id}/reject` | Odrzucenie z obowiązkowym powodem i zwolnienie rezerwacji |

### Dane zapisu

Treść jest obiektem JSON, maksymalnie 64 KiB. Nieznane pola są odrzucane.
Identyfikatory, wersja i godziny są liczbami całkowitymi JSON; napisy, ułamki,
wartości logiczne i `null` nie zastępują liczb.

| Pole | Wymaganie |
|---|---|
| `operationKey` | Każdy zapis; 1–64 liter ASCII, cyfr lub znaków `.` `_` `:` `-`; unikalne dla logicznej operacji użytkownika |
| `expectedVersion` | Edycja, wycofanie i decyzje; dodatnia wersja z bieżącego odczytu wniosku |
| `absenceTypeId` | Utworzenie i edycja; dodatni identyfikator rodzaju |
| `startDate`, `endDate` | Utworzenie i edycja; daty `RRRR-MM-DD` w uporządkowanym zakresie |
| `mode` | Utworzenie i edycja; `full_days` domyślnie albo `hourly` |
| `hours` | Tylko `hourly`: pełna liczba 1–7; start i koniec muszą wskazywać ten sam dzień |
| `reason` | Odrzucenie: niepuste uzasadnienie, maksymalnie 1000 znaków |

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

Zwykła edycja nie wznawia odrzuconego lub wycofanego wniosku i nie zmienia
zaakceptowanego urlopu. Kolejna nowa próba akceptacji zakończonej wersji jest
konfliktem; ponowienie identycznej wcześniej wykonanej operacji jest bezpieczne.

Zapis wersji, decyzji, audytu, księgowań i dziennej zajętości jest atomowy.
Wspólna blokada z administracją zapobiega równoczesnej zmianie przypisań lub zasad
w trakcie składania. Przy edycji zachowujemy użyte wcześniej wersje rodzaju i
zapisane zasady dotychczasowego zakresu. Nowo dodane daty korzystają z przypisania
obowiązującego w tych dniach. Błąd któregokolwiek dnia lub roku wycofuje całą edycję.

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
  `change_pending` lub `cancellation_pending`. Dwa ostatnie są odczytem istniejącego
  modelu zmian; ich obsługa nie jest udostępniona przez mutacje etapu 6.

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
`edit`, `withdraw`, `approve`, `reject`; stanowią wskazówkę dla interfejsu,
a uprawnienia i wersja są zawsze ponownie sprawdzane przy zapisie.

### Szczegóły

`GET /api/requests/{id}` zwraca `{ "request": ... }`. Poza polami listy zawiera
`current` oraz `history`. Każda wersja jest autoryzowana niezależnie przez
`RequestAccessPolicy`. Dostęp do aktualnego wniosku nie daje automatycznie dostępu
do wcześniejszego zakresu innego zespołu.

Jeżeli przełożony może czytać wyłącznie historyczne wersje, zachowuje tę historię,
ale `currentVisible=false`, `current=null`, `version=null`, `status=null` i wszystkie
`actions=false`. Odpowiedź nie ujawnia obecnych dat, rodzaju ani decyzji innego
zespołu. Brak jakiejkolwiek dostępnej wersji daje odmowę dostępu.

Wersja zawiera `id`, `revision`, `kind`, `mode`, `startDate`, `endDate`,
`totalHours`, `days` (`date`, `hours`), `absenceType` (`id`, `code`, `revision`,
`name`, `deductsBalance`), `submitted` (`at`, `actorName`) i `decision`.
Decyzja to `null` albo `outcome`, `at`, `actorName`, `reason`.
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
