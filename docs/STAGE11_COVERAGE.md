# Etap 11 — pokrycie wymagań i pełnych procesów

Przegląd: 8 listopada 2026 r. Baza: etap 10, commit
`4191f2ca26ebd205e36c4d5c7fcb606cb91bb1a4`, CI #38 SUCCESS.
Poniższa mapa wskazuje rzeczywiste pliki i sprawdzane zachowanie. Obecność testu
nie jest wynikiem jego uruchomienia; odbiór bieżącego kodu opisuje TEST_PLAN.

## Kryteria T01–T23

Nazwy PHP odnoszą się do `backend/tests/Functional` lub `backend/tests/Integration`.
Skrypty Chrome/HTTP znajdują się w `.github/scripts`.

| Kryterium | Dowód w testach i dodatkowa kontrola etapu 11 |
|---|---|
| T01 — jedno potrącenie od rezerwacji do wykorzystania | `RequestApiTest::testSubmissionApprovalAndElapsedDatesDeductOnlyOnce`; nowy `system-e2e-check.cjs` sprawdza 80 → 64 po 16 h i nadal 64 po decyzji. Przejście czasu kontroluje MockClock w PHP. |
| T02 — dwa równoczesne wnioski wobec 8 h salda | `RequestConcurrencyTest::testTwoCompetingSixHourRequestsCannotSpendTheSameEightHourBalance` — dwa procesy i rzeczywiste blokady MySQL. |
| T03 — konkurencyjne decyzje i ponowienie | `RequestConcurrencyTest` (approval/rejection, same approval receipt), `RequestChangesConcurrencyTest` (decisions on a change); Chrome `managed-requests-ui-check.cjs` sprawdza konflikt i niepewne potwierdzenie. |
| T04 — większa propozycja i zwrot dodatkowej rezerwacji | `RequestChangesWorkflowTest::testShiftedLargerProposalReservesOnlyEightAndRejectionPreservesOriginalSixteen`; `RequestChangesApiTest`; rzeczywisty `requests-e2e-check.cjs` obejmuje 3 → 5 h i wycofanie propozycji. |
| T05 — mniejsza propozycja/anulowanie, zwrot dopiero po decyzji | `RequestChangesWorkflowTest` (smaller proposal, cancellation); nowy real E2E wykonuje 16 → 5 h oraz anulowanie, sprawdza 64 → 75 → 80 h i trwałą historię trzech decyzji. |
| T06 — przeniesienie terminu między latami | `RequestChangesWorkflowTest::testChangingYearRequiresNewYearsPoolAndKeepsDecemberUntilDecision`; `RequestChangeAccountingTest`. |
| T07 — zaległość UoP/B2B i idempotentne rozliczenie | `AccountingServiceTest` (old reservations, B2B release, UoP origin pool, repeated settlement, forecast carry). |
| T08 — pojedynczy wniosek grudzień–styczeń | `AccountingServiceTest::testDecemberJanuaryRequestReservesEachAnnualLimitAndApprovesWithoutSecondCharge`; nowy real E2E sprawdza dokładnie 8 h w każdym roku i 72 h dostępne z dwóch niezależnych limitów 80 h. |
| T09 — domyślne pełne dni i zmiana na godziny | `requests-ui-check.cjs`, `requests-e2e-check.cjs`; nowy real E2E zapisuje pełne 16 h, następnie zmienia na 5 h. Nowy licznik dni ma osobne testy API i przeglądarki. |
| T10 — 3 h + 5 h i odrzucenie dziewiątej godziny | `RequestApiTest::testHourlyCapacityIncludesUnpaidAbsenceAndAllowsThreePlusFive`; nowy real E2E wykonuje te operacje dla niepłatnego rodzaju, ze stałym saldem 80 h. |
| T11 — nieprawidłowe wartości godzin | `RequestInputTest`, `RequestApiTest::testInputBoundaryRejectsMalformedJsonUnsupportedFieldsAndNonIntegerHours`, testy modelu godzin. |
| T12 — kierownik własne, zastępca tylko cudze | `RequestApiTest::testManagerOwnDeputyOtherAndAdministratorCrossTeamDecisionMatrix`; `managed-requests-e2e-check.cjs` — rzeczywiste UI i bezpośrednia odmowa 403. |
| T13 — izolacja zespołów i prywatnych pól | `RequestApiTest`, `TeamCalendarApiTest`, `RequestChangesApiTest`; nowy real E2E odmawia nowemu kierownikowi szczegółów starego zespołu i ukrywa przed starym kierownikiem bieżące pule przeniesionego pracownika. |
| T14 — przeszłość niedostępna samoobsłudze | `RequestChangesWorkflowTest::testHistoricalSelfServiceAndDeputyOwnCorrectionAreForbiddenWhileManagerOwnWorks`; `RequestApiTest` (past dates), `RequestChangesApiTest` (confirmed historical correction). |
| T15 — zmiana płatny ↔ niepłatny | `RequestChangesWorkflowTest::testPaidToUnpaidRefundsAfterApprovalAndReverseReservesBeforeDecision`; rzeczywisty HTTP w `auth-http-check.cjs` obejmuje obieg zmian i ograniczenia niepłatnych nieobecności. |
| T16 — korekta starej puli po przeniesieniu | `AccountingServiceTest` (origin pools, shared old pool), `RequestChangesWorkflowTest::testPastYearDeficitRequiresExplicitAdministratorConfirmationAndPreservesLaterLeave`, `RequestChangesConcurrencyTest` (one accounting effect). Dodatkowo nowy real E2E sprawdza korektę po przeniesieniu **zespołu**, z dokładnym zwrotem 3 h i historią. |
| T17 — granica danych początkowych | `AccountingServiceTest::testPreOpeningRequestCannotDoubleChargeAggregateUsage`; `RequestChangesWorkflowTest` (before opening, legacy cancellation) oraz `BalanceApiTest`. |
| T18 — wylogowanie, dezaktywacja, odebranie roli | `AuthTest`, `AdminTest`, `RequestApiTest` (stale actor); `auth-http-check.cjs`, `auth-ui-check.cjs`, testy focus/reauth w `managed-requests-ui-check.cjs`. |
| T19 — historia po zmianie słownika i przypisania | `AdminTest`, `RequestApiTest` (revision visibility), `RequestChangesApiTest`, `CompanyCalendarTest` (captured employment after transfer); real system E2E potwierdza odczyt/korektę historii po transferze bez ujawnienia bieżącego salda. |
| T20 — restart i odtworzenie kopii | CI sprawdza stop/start i trwałość danych; późniejsze E2E odczytują rzeczywistą bazę. **Odtworzenie backupu na odizolowanej bazie pozostaje zakresem etapu 12**; restart nie jest dowodem restore. |
| T21 — oba terminy propozycji i maksymalna zajętość | `RequestChangesWorkflowTest::testMovingFullDayProtectsBothDatesButDoesNotReserveSameYearTwice`, `testSameDayThreeToFiveUsesFiveHoursAndAllowsAnotherThree`; `RequestChangeAccountingTest`. |
| T22 — zmiana dnia wolnego | `CompanyCalendarTest`: dodanie/usunięcie, zero godzin, stale preview, audyt, zachowanie zasad; `balance-ui-check.cjs` i `auth-http-check.cjs` sprawdzają podgląd i potwierdzenie. |
| T23 — równoczesna zajętość, także niepłatna | `RequestConcurrencyTest::testNonDeductingRequestsStillSerializeTheEightHourDailyCapacity` oraz `RequestChangesConcurrencyTest::testUnpaidChangeAndNewRequestCannotOverbookTheSameDay` — MySQL i dwa procesy. |

## Nowy realny przebieg systemowy

`system-e2e-check.cjs` uzupełnia istniejące E2E pracownika i przełożonego.
Nie przechwytuje odpowiedzi API. Chrome wykonuje formularze, API w tej samej sesji
odczytuje zapisane wyniki. Dane pochodzą z odrębnej fixture z losowym prefiksem,
a nie z rzeczywistych kont pracowników.

1. Pracownik loguje się przez UI. W nowym wniosku wybiera dwa kolejne dni robocze;
   automatyczny licznik pokazuje 2 dni / 16 h, bez zmiany salda. Zapisuje formularz.
2. Kierownik akceptuje przez UI. Pracownik zmienia wniosek na 5 h w innym terminie.
   Przed kolejną decyzją saldo nie otrzymuje zwrotu; po niej wraca dokładnie 11 h.
3. Pracownik prosi o anulowanie przez UI; zwrot 5 h następuje dopiero po akceptacji.
   Wszystkie trzy wersje mają osobnego autora decyzji i pozostają w historii.
4. Niepłatne wnioski 3 h i 5 h pozostawiają saldo bez zmian. API odrzuca kolejną
   godzinę kodem `DAILY_CAPACITY_EXCEEDED`; kierownik akceptuje niepłatne 3 h w UI.
5. Jeden zakres obejmuje ostatni roboczy dzień grudnia i pierwszy roboczy dzień
   stycznia. Po zatwierdzeniu każdy roczny limit jest obciążony dokładnie 8 h.
6. Pracownik przeniesiony przed dzisiaj ma historyczne 6 h z poprzedniego zespołu.
   Nowy kierownik nie odczytuje tego wniosku. Stary może skorygować go przez mobilny
   formularz do 3 h, ale nie odczytuje bieżącego salda ani pul. Administrator
   potwierdza zwrot i zapis autora/uzasadnienia, także po ponownym odczycie.

Daty przyszłego procesu wyznacza kalendarz aplikacji na następny rok, a granice
historii fixture mają kontrolowany `MockClock`. Nie przestawiamy zegara serwera
podczas rzeczywistego E2E. Zmiany roku/dnia i współbieżność są dodatkowo badane
w testach PHP z kontrolowanym zegarem. Fixture wymaga środowiska dev/test,
`CI=true` i osobnego potwierdzenia; skrypt wymaga lokalnego HTTP. Hasła losowe
pozostają w pamięci procesu, bez screenshotów, trace i logowania odpowiedzi.

## Uruchomienie i stan dowodów

Po uruchomieniu lokalnego stosu CI i instalacji przeglądarkowego narzędzia:

```bash
NODE_PATH=/tmp/urlopy-ui/node_modules \
BASE_URL=http://localhost:8080 \
CHROME_PATH="$(command -v google-chrome)" \
node .github/scripts/system-e2e-check.cjs
```

`CI=true` jest dodatkowym wymaganiem, ustawianym przez workflow. Skrypt sam
uruchamia wyłącznie chronioną fixture. Nie należy go wykonywać na produkcji.
Wstępnie wykonano `node --check` oraz `git diff --check`; wykonanie PHP/MySQL
oraz Chrome połączonego z API wymaga rzeczywistego przebiegu CI. Wynik tego
przebiegu i ewentualne poprawki muszą zostać zapisane w TEST_PLAN przed odbiorem.

## Granice i dalszy odbiór

- Testy syntetycznego UI obejmują telefony, klawiaturę, opóźnienia i utracone odpowiedzi.
  Realne E2E sprawdzają trwałość i poprawność obiegu; nie są testem obciążenia.
- Wydajność, pomiary większego zbioru i audyt bezpieczeństwa mają osobne raporty.
- Przygotowanie produkcji, firmowe skanery, Q7 oraz backup/restore są zakresem etapu 12.
- Nie zidentyfikowano brakującego kryterium T01–T19/T21–T23 bez testu na poziomie
  backendu. Rzeczywisty UI dla pełnego zmniejszenia/anulowania, przekroczenia roku
  i korekty po zmianie zespołu uzupełnia opisany nowy przebieg.
