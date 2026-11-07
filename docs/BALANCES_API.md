# API limitów, salda i kalendarza roboczego

Kontrakt etapu 5, rozszerzony o podglądy formularzy w etapie 10. Reguły rozliczeń i wzór salda opisuje [ACCOUNTING.md](ACCOUNTING.md).
Opis endpointów nie stanowi potwierdzenia przejścia testów; wyniki są w
[TEST_PLAN.md](TEST_PLAN.md).

## Dostęp i format

Wszystkie endpointy wymagają zalogowanej sesji z docelowym hasłem. Każdy `POST`
wymaga nagłówka `X-CSRF-Token` uzyskanego z `/api/auth/csrf`. Odpowiedzi mają
`Cache-Control: no-store, private`. Nie przekazujemy hasła administratora do operacji
limitów i dni firmowych.

| Operacja | Pracownik | Kierownik | Zastępca | Administrator |
|---|---|---|---|---|
| Odczyt salda | Własne | Własne i bieżącego zespołu | Własne i bieżącego zespołu | Wszystkie |
| Podgląd limitu lub danych początkowych | Nie | Bieżący zespół, z wyjątkiem własnych danych | Nie | Wszystkie, także własne |
| Limity i dane początkowe | Nie | Bieżący zespół, z wyjątkiem własnych danych | Nie | Wszystkie, także własne |
| Odczyt kalendarza roboczego | Tak | Tak | Tak | Tak |
| Podgląd i zatwierdzenie zmiany dnia firmowego | Nie | Nie | Nie | Tak |

Zespół i rola obowiązujące **dzisiaj w Europe/Warsaw** określają uprawnienia do
zarządzania saldem. Podany rok salda nie przywraca dawnego dostępu przełożonego.
Zmiana własnego limitu kierownika wymaga administratora, mimo jego osobnego prawa
do zatwierdzania własnego urlopu. API odfiltrowuje niedostępne osoby przed
serializacją; samo ukrycie kontrolki w interfejsie nie jest zabezpieczeniem.
Usługi zapisujące ponownie sprawdzają uprawnienia pod blokadą transakcyjną.

Treść `POST` jest obiektem JSON, maksymalnie 64 KiB. Godziny przekazujemy jako
nieujemne liczby całkowite JSON w zakresie 0–1 000 000; napisy `"8"`, wartości
ułamkowe, logiczne i ujemne są odrzucane. Limit w dniach interfejs przelicza po
8 godzin. Daty mają format `RRRR-MM-DD`. Lata nieobecności i kalendarza: 2025–2100;
starszy rok pochodzenia pozostałości może być wcześniejszy niż 2025.

## Endpointy salda

W tabeli `{id}` oznacza identyfikator pracownika, a `{year}` czterocyfrowy rok salda.
Udany zapis zwraca kod `200` oraz obiekt `{ "balance": ... }`.

| Metoda i ścieżka | Znaczenie | Dane poza wspólnymi polami zapisu |
|---|---|---|
| `GET /api/balances/people` | Osoby dostępne dla zalogowanego użytkownika | Brak |
| `GET /api/balances/users/{id}/{year}` | Saldo, pule i historia | Brak |
| `POST /api/balances/users/{id}/opening-preview` | Podgląd pierwszych danych początkowych | Jak `/opening`, ale bez `operationKey` |
| `POST /api/balances/users/{id}/{year}/limit-preview` | Podgląd ustawienia lub zmiany limitu | Jak `/limit`, ale bez `operationKey` |
| `POST /api/balances/users/{id}/opening/corrections-preview` | Podgląd korekty danych początkowych | Jak `/opening/corrections`, ale bez `operationKey` |
| `POST /api/balances/users/{id}/opening` | Pierwsze dane początkowe | `effectiveDate`, `annualHours`, `currentYearUsedHours`, `carries` |
| `POST /api/balances/users/{id}/{year}/limit` | Ustawienie albo zmiana limitu rocznego | `annualHours`, `reason` przy zmianie już ustawionego limitu |
| `POST /api/balances/users/{id}/opening/corrections` | Jawna korekta danych początkowych | `currentYearUsedHours`, kompletna lista `carries`, obowiązkowe `reason`; opcjonalne `effectiveDate` |
| `POST /api/balances/users/{id}/{year}/settle` | Utrwalenie rozliczenia roku bez ponownego przydzielania godzin | Brak |

Każdy zapis salda zawiera:

- `operationKey`: unikalny identyfikator logicznej operacji, 1–64 znaków z zestawu
  liter ASCII, cyfr oraz `.` `_` `:` `-`. Przy ponowieniu tego
  samego żądania klient zachowuje ten sam klucz i identyczną treść. Inne dane pod
  wykorzystanym kluczem powodują konflikt.
- `expectedVersion`: nieprzetwarzany przez klienta ciąg `version` z odczytanego
  salda. Nieaktualny formularz nie nadpisuje nowszych danych.

Wersja salda jest wspólna dla wszystkich lat pracownika i uwzględnia również
bieżący dzień w Europe/Warsaw. Utworzenie lub korekta danych początkowych zwraca
saldo roku daty rozpoczęcia ewidencji. Korekta daty startu jest ograniczona do tego samego
roku i nie jest dozwolona po rozpoczęciu rozliczania wniosków.

`carries` to tablica `{ "originYear": 2025, "remainingHours": 16 }`, bez
powtórzonych lat, maksymalnie 100 pozycji. Przy korekcie przesyłana jest pełna lista prawidłowych pozostałości,
nie różnice względem poprzednich wartości. Tak samo `currentYearUsedHours` jest
prawidłową wartością docelową. Usługa zapisuje różnice jako nowe ruchy i audyt;
pierwotny zapis danych początkowych pozostaje zachowany. Roczny limit koryguje
osobny endpoint `/limit`.

Przykład utworzenia danych początkowych:

```json
{
  "operationKey": "opening-7fc7255f-80d4-4213-95ae-52d113c076f6",
  "expectedVersion": "wartosc-version-z-odczytu-salda-roku-2026",
  "effectiveDate": "2026-11-01",
  "annualHours": 208,
  "currentYearUsedHours": 80,
  "carries": [{ "originYear": 2025, "remainingHours": 16 }]
}
```

### Podgląd przed zapisem limitów i danych początkowych

Trzy endpointy zakończone `-preview` przyjmują identyczne dane formularza jak
odpowiadające im zapisy, w tym `expectedVersion`, ale odrzucają `operationKey`.
Obowiązują te same wymagania uzasadnienia, zakresu dat, pełnych godzin i uprawnień.
Sam dostęp do odczytu salda nie daje prawa do podglądu zmiany: zastępca nie może
go wykonać, a kierownik nie może symulować zmiany własnego limitu.

Backend sprawdza uprawnienia i wersję pod blokadą, wykonuje normalną operację
rozliczeniową, a następnie zawsze wycofuje transakcję. Podgląd nie pozostawia
przydziałów, danych początkowych, ruchów księgowych, audytu ani zmiany wersji.
Zmodyfikowane encje ORM są odłączane po wycofaniu. CSRF jest wymagany również
przy tym odczycie wykonywanym przez `POST`.

Odpowiedź HTTP `200` ma postać `{ "preview": ... }`:

| Pole `preview` | Znaczenie |
|---|---|
| `userId` | Pracownik, którego dotyczy podgląd |
| `year` | Rok limitu albo rok rozpoczęcia ewidencji dla danych początkowych i ich korekty |
| `version` | Oryginalna wersja salda przed podglądem |
| `before`, `after` | Rzeczywisty stan przed zmianą i symulowany stan po niej |

`before` i `after` zawierają wyłącznie: `configured`, `annualHours`, `opening`,
`carriedHours`, `allocatedHours`, `openingUsedHours`, `usedHours`,
`approvedFutureHours`, `reservedHours`, `changeReservedHours`, `availableHours`,
`deficitHours`, `displayAvailable`, `pools`. Nie zawierają historii ani wersji
hipotetycznego zapisu. Struktury `opening` i `pools` odpowiadają opisowi salda poniżej.
Podgląd pokazuje również ujemne saldo, jeśli dozwolona korekta powoduje niedobór.

Interfejs pokazuje wpływ i prosi o potwierdzenie przed wywołaniem dotychczasowego
endpointu zapisu z tą samą treścią formularza i nowym `operationKey`. Zmiana pola
formularza usuwa wcześniejszy podgląd. API podstawowych limitów i danych
początkowych nie wymaga `previewToken` ani dodatkowego pola `confirm`; zapis
ponownie sprawdza `expectedVersion` i uprawnienia oraz stosuje zwykłą idempotencję.
Po utracie potwierdzenia należy ponowić identyczny zapis z tym samym kluczem,
a po konflikcie pobrać aktualny stan i nowy podgląd. Tokeny i potwierdzenia zmian
dni firmowych oraz historycznych korekt wniosków zachowują osobne dotychczasowe zasady.

### Odpowiedź listy osób

`GET /api/balances/people` zwraca `people`, `today`, `currentYear` i
`isAdministrator`. Każda osoba zawiera `id`, `firstName`, `lastName`, `active`,
`team` (`id`, `name` albo `null`), `employmentType` (`id`, `name`,
`carriesUnusedHours` albo `null`), `role` i `canManage`. Odpowiedź nie zawiera haseł,
adresów e-mail ani sald innych osób. Pracownik otrzymuje wyłącznie własną pozycję.

### Odpowiedź salda

| Pole `balance` | Znaczenie |
|---|---|
| `userId`, `year`, `version` | Pracownik, rok salda i wersja do ochrony zapisu |
| `configured`, `annualHours` | Czy limit wpisano; `annualHours: null` oznacza brak, `0` świadomy zerowy limit |
| `opening` | Aktualne dane początkowe po korektach albo `null`: `effectiveDate`, `annualHours`, `currentYearUsedHours`, `carries` |
| `carriedHours`, `allocatedHours` | Pozycje przydziału i dostępności starszych pul według wzoru w ACCOUNTING |
| `openingUsedHours` | Wykorzystanie uwzględnione przed rozpoczęciem ewidencji |
| `usedHours`, `approvedFutureHours` | Obciążenia przypadające odpowiednio do dzisiaj oraz na przyszłość |
| `reservedHours`, `changeReservedHours` | Oczekujące wnioski i dodatkowe rezerwacje zmian |
| `availableHours`, `deficitHours`, `displayAvailable` | Dostępne saldo, jawny niedobór oraz zapis godzin w dniach i godzinach |
| `pools` | Pochodzenie godzin i stan wspólnych pul |
| `history` | Historia operacji finansowych bez sekretów |

Każda pula zawiera `originYear`, `allocatedHours`, `reservedHours`, `chargedHours`,
`availableHours` oraz `eligible`. Ta sama pula może być wykorzystywana przez różne
lata nieobecności; nie wolno sumować jej jako niezależnych przydziałów między latami.

Odpowiedź zawiera maksymalnie 100 ostatnich zdarzeń; pełny audyt pozostaje w bazie.
Element historii zawiera `id`, `action`, `occurredAt`, `actorName`, `reason`,
`before` i `after`. Czas operacji jest zapisywany ze strefą, a interfejs prezentuje
go w Europe/Warsaw. Historia salda nie zastępuje osobnego uprawnienia do szczegółów
wniosków po zmianie zespołu. Dla przełożonego czytającego cudze saldo API przekazuje
wyłącznie zdarzenia ustawiania i korygowania danych finansowych (`balance.*`,
`opening.*`), bez szczegółów zdarzeń wniosków i kalendarza. Ta sama projekcja
obowiązuje odpowiedź po zapisie. Właściciel i administrator widzą również
zdarzenia rozliczenia wniosków. `actorName` może być `null` dla zdarzenia systemowego.

## Kalendarz roboczy i dni firmowe

| Metoda i ścieżka | Treść żądania | Odpowiedź |
|---|---|---|
| `GET /api/calendar/{year}` | Brak | `year`, `timezone`, `hoursPerDay`, `minimumYear`, `maximumYear`, `holidays` |
| `POST /api/calendar/preview` | `date`, `name`, `active` | Dane zmiany, `previewToken`, `impacts`, `changedRequests` |
| `POST /api/calendar/apply` | Dane zmiany, `previewToken`, `operationKey`, `reason` | Potwierdzony podgląd oraz `applied: true` |

Element `holidays`: `date`, `name`, `source` (`statutory` albo `company`) i `active`.
Lista obejmuje święta ustawowe i konfigurację firmową; weekend wynika z reguły
poniedziałek–piątek. Wyłączenie wpisu firmowego nie odwołuje święta ustawowego.

Zmiana dnia firmowego jest możliwa wyłącznie dla dat późniejszych od dzisiaj
w Europe/Warsaw. `active: false` wyłącza firmowy dzień wolny. Zatwierdzenie wymaga
niepustego uzasadnienia i tokena podglądu; nowy stan wniosków lub rozliczeń może
unieważnić podgląd. Po konflikcie należy pobrać nowy podgląd i ponownie pokazać
skutki administratorowi.

Każdy element `impacts` zawiera:

- `requestId`, `reference`, `userId`, `employeeName`;
- `status`: `approved`, `pending` albo `approved_with_pending`;
- `beforeHours`, `afterHours` dla obowiązującego urlopu;
- `beforePendingHours`, `afterPendingHours` dla oczekującej propozycji;
- `action`: opis przeliczenia, anulowania lub wycofania.

Podgląd nie zmienia wniosków. `apply` zapisuje zmianę kalendarza, nowe wersje i
decyzje wniosków oraz ruchy salda w jednej transakcji. Anulowanie całego urlopu
wynikające ze zmiany kalendarza jest jawnie prezentowane i objęte potwierdzeniem.
Pełne wcześniejsze wersje pozostają w historii. Szczegóły: [CALENDAR.md](CALENDAR.md).

## Błędy

Odpowiedzi błędów zachowują wspólny format
`{ "error": { "code": "...", "message": "..." } }`.

| HTTP / kod | Znaczenie dla klienta |
|---|---|
| `401 AUTH_REQUIRED` | Brak albo wygaśnięcie sesji; ponowne logowanie |
| `403 PASSWORD_CHANGE_REQUIRED` | Najpierw zmiana hasła tymczasowego |
| `403 CSRF_INVALID` | Odświeżenie tokena zabezpieczającego |
| `403 FORBIDDEN` | Brak uprawnień; nie ponawiamy automatycznie zapisu |
| `404 NOT_FOUND` | Brak wskazanej osoby |
| `400 VALIDATION_FAILED` | Nieprawidłowy obiekt JSON albo zbyt duże żądanie |
| `422 VALIDATION_FAILED` | Niepoprawne pola, godziny, daty albo uzasadnienie |
| `409 CONFLICT` | Nieaktualna wersja lub równoczesna zmiana; ponowny odczyt |
| `409 IDEMPOTENCY_CONFLICT` | Ten sam klucz operacji z innymi danymi |
| `409 CALENDAR_PREVIEW_STALE` | Podgląd nie odpowiada aktualnemu stanowi |
| `409 CALENDAR_LEGACY_CONTEXT_MISSING` | Starszy wniosek nie ma jednoznacznych zapisanych zasad dla dodawanego dnia; wymaga indywidualnego uporządkowania |
| `422 CALENDAR_DATE_NOT_FUTURE` | Zbiorcza zmiana dotyczy daty dzisiejszej lub przeszłej |
| `422 CALENDAR_YEAR_UNSUPPORTED` | Rok poza obsługiwanym kalendarzem |
| `422 INVALID_YEAR` | Rok salda poza zakresem 2025–2100 |

Usługi mogą zwrócić dodatkowy konkretny kod walidacji. Klient zachowuje komunikat
serwera, lecz nie traktuje błędu lub utraty odpowiedzi jako dowodu wykonania zmiany.
Po utracie odpowiedzi ponawia tę samą logiczną operację z tym samym kluczem;
nowy klucz oznacza nową operację.
