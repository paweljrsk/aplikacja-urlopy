# Administracja — etap 4

Panel `/management/admin` w module „Zarządzanie” służy administratorowi do zarządzania kontami, zespołami, przypisaniami
oraz słownikami. Dostęp wymaga ukończonej zmiany hasła tymczasowego. Kontrola dostępu,
walidacja i audyt działają w API. Układ jest funkcjonalny i tymczasowy; docelowa
stylistyka Notus dla wszystkich ekranów pozostaje zakresem etapu 8.

## Pierwsza konfiguracja

1. Utwórz pierwszego administratora przez `app:admin:bootstrap` zgodnie z README.
2. Zaloguj się, zmień hasło tymczasowe i zaloguj się ponownie.
3. Otwórz administrację i wybierz „Dodaj słowniki startowe”. Operacja uzupełnia
   brakujące podstawowe formy zatrudnienia i trzy rodzaje nieobecności; powtórzenie
   nie nadpisuje istniejących ustawień. Nie ładuje pracowników ani danych demo.
4. Dodaj zespół, a następnie konta i ich początkowe przypisania. Administrator
   utworzony przez terminal może również otrzymać przypisanie do zespołu.
5. Przekaż każdemu pracownikowi wyświetlone jednorazowo hasło tymczasowe bezpiecznym
   kanałem firmowym. Hasło jest ważne 24 godziny i wymaga zmiany. Jeśli nie zostało
   zachowane, użyj resetu hasła; nie można odczytać go ponownie z konta.

Limity i saldo nie są konfigurowane w tym etapie — powstaną w etapie 5.

## Konta i przypisania

Dane konta obejmują imię, nazwisko, e-mail, aktywność i globalne uprawnienie
administratora. Rola pracownika/kierownika/zastępcy należy do datowanego przypisania
z zespołem i formą zatrudnienia. Słownik ról pozostaje zamknięty; administrator
nadaje role, ale nie definiuje nowych typów uprawnień.

Zmiany przypisania zachowują wcześniejsze okresy. Data końca starego okresu jest
wyłączna: przeniesienie od 1 grudnia oznacza stary zespół do 30 listopada i nowy
od 1 grudnia. Przypisania nie mogą się nakładać. Panel pozwala zaplanować kolejne
zmiany w porządku dat; nie jest edytorem dowolnego przepisywania historii.

Korekta ostatniego przypisania z tą samą datą startu jest dopuszczalna tylko wtedy,
gdy nie wskazuje go żaden dzień wniosku. Zmiana pozostaje w audycie. Gdy przypisanie
jest użyte, zachowujemy jego historyczną treść i wymagamy nowego okresu.

Dezaktywacja konta unieważnia wcześniejsze sesje; późniejsza reaktywacja ich nie
przywraca. Zmiana e-maila lub uprawnień także odbiera dostęp wcześniejszym sesjom.
Zmiany przypisań unieważniają sesje, a zaplanowana zmiana obowiązujących uprawnień
jest uwzględniana przy kolejnych żądaniach po nadejściu daty.

Operacje na kontach wymagają potwierdzenia własnym hasłem administratora. Limiter
jest wspólny z pozostałymi operacjami potwierdzania hasła. Hasła nie trafiają do
historii, profilu ani magazynu przeglądarki.

Nie można dezaktywować ani pozbawić uprawnień ostatniego aktywnego administratora
z ustawionym docelowym hasłem. Konto mające wyłącznie hasło tymczasowe nie jest
wystarczającym zastępstwem. Najpierw drugi administrator musi ukończyć logowanie
i zmianę hasła. Kontrola jest wykonywana w transakcji pod wspólną blokadą.

## Słowniki i historia

Zespoły oraz formy zatrudnienia mają stały kod, nazwę i aktywność. Nie usuwamy
rekordów użytych w historii. Nieaktywne pozycje nie mogą być wybierane w nowych
przypisaniach, lecz wcześniejsze przypisania pozostają zachowane.

UoP przenosi niewykorzystane godziny; pozostałe formy nie. Ta właściwość formy jest
niezmienna po utworzeniu: zmiana nazwy nie może przepisać reguł wcześniej złożonych
wniosków. Rozliczanie salda i przejścia roku pozostaje w etapie 5.

Rodzaj nieobecności ma aktywność oraz wersje zasad: nazwę, wpływ na wspólny limit,
dostępne formy zatrudnienia i opcjonalne nazwy zależne od formy. Nowa wersja jest
używana przez nowe wnioski. Dotychczasowe wnioski nadal wskazują swoją wersję;
deaktywacja lub zmiana słownika nie przelicza ich. Każdy rodzaj wymaga akceptacji.

Audyt zapisuje autora, czas, obiekt i poprzednie/nowe wartości. Nie zawiera haseł,
skrótów haseł ani cookies. Pełny ekran przeglądania audytu powstanie w etapie 10;
historia przypisań jest dostępna już w kartotece użytkownika.

## Zmiana zespołu i formy zatrudnienia — Q5

Obowiązują decyzje B22–B24 z REQUIREMENTS i DECISIONS. Widoczność wyznaczają dni
nieobecności. Stary zespół zachowuje wcześniejsze dni; od daty przeniesienia nowy
przełożony przejmuje przyszłe oczekujące wnioski. Zaplanowane przeniesienie nie
udostępnia ich nowemu przełożonemu przed wejściem zmiany w życie.

Wniosek obejmujący oba zespoły obsługuje administrator. Widoki zespołu mogą
pokazywać wyłącznie własne dni, a pełne szczegóły pozostają dostępne właścicielowi
i administratorowi. Kierownik może decydować o swoim wniosku, zastępca nie.
Akceptacje i zapisane zasady obliczeń pozostają niezmienne.

`RequestAccessPolicy` udostępnia osobną, testowaną politykę odczytu i decyzji.
Zapisane `RequestDay.assignment` to historia rozliczeń, nie bieżące źródło uprawnień.
Etap 4 nie udostępnia jeszcze API wniosków ani kalendarza: odpowiednie endpointy
muszą użyć tej polityki w etapach 6–10. Tworzenie wniosku/propozycji musi blokować
agregat użytkownika w transakcji przed utrwaleniem przypisań dni, aby nie ścigało
się z korektą nieużywanego przypisania w panelu administracji. Nie wolno uznać samych testów polityki za
odbiór całego procesu składania i akceptacji wniosków.

## Kontrakt API

Każde żądanie zmieniające stan wymaga sesji i `X-CSRF-Token`. Wszystkie endpointy
poniżej wymagają administratora. Odpowiedzi zawierają `Cache-Control: no-store`.

| Metoda i ścieżka | Cel |
|---|---|
| `GET /api/admin/catalog` | Zespoły, formy, aktualne wersje rodzajów, dostępne role |
| `POST /api/admin/catalog/initialize` | Jawne, powtarzalne uzupełnienie słowników startowych |
| `GET /api/admin/users` | Lista kont z przypisaniami |
| `GET /api/admin/users/{id}` | Kartoteka i historia przypisań |
| `POST /api/admin/users` | Konto, przypisanie i jednorazowe hasło tymczasowe |
| `PATCH /api/admin/users/{id}` | Dane, aktywność i globalne uprawnienie administratora |
| `POST /api/admin/users/{id}/assignments` | Datowana zmiana zespołu, formy lub roli |
| `POST /api/admin/users/{id}/reset-password` | Reset hasła z etapu 3 |
| `POST /api/admin/teams` | Utworzenie zespołu |
| `PATCH /api/admin/teams/{id}` | Nazwa i aktywność zespołu |
| `POST /api/admin/employment-types` | Utworzenie formy zatrudnienia |
| `PATCH /api/admin/employment-types/{id}` | Nazwa i aktywność formy |
| `POST /api/admin/absence-types` | Utworzenie rodzaju i pierwszej wersji zasad |
| `PATCH /api/admin/absence-types/{id}` | Aktywność oraz nowa wersja zasad |

Tworzenie konta i słowników zwraca HTTP 201, pozostałe udane operacje HTTP 200.
Odpowiedź słownika to `{item: ...}`, konta `{user: ...}`; utworzenie konta zawiera
też `temporaryPassword` i `expiresAt`. Inicjalizacja zwraca katalog.

Zmiana istniejącego obiektu wymaga jego `version`. Nieaktualna wersja zwraca 409
`CONFLICT` — należy odświeżyć dane, bez automatycznego nadpisania. Unikalność kodu
i e-maila, ograniczenia przypisań i ostatniego administratora są egzekwowane w API
oraz transakcji. Niepoprawne pola zwracają 400/422; brak sesji 401, brak uprawnień
403, brak obiektu 404. Szczegóły błędu są w `{error:{code,message}}`.

## Weryfikacja i granice

Wyniki faktycznie wykonanych kontroli zapisujemy w TEST_PLAN. Testy funkcjonalne
weryfikują rzeczywisty backend na MySQL; scenariusze UI ze sztucznym API sprawdzają
ekrany oddzielnie. Smoke HTTP przez Nginx/PHP-FPM sprawdza wspólny origin, sesję,
CSRF, tworzenie słowników i kont, zmianę hasła oraz izolację uprawnień.

Nie uruchomiono jeszcze funkcji wniosków, sald, kalendarza ani integracji M365/Slack.

## Historia administracji — etap 10

Karta „Historia” korzysta z `GET /api/admin/history?limit=20&beforeId=...`.
Dostęp ma wyłącznie aktualny administrator. `limit` ma zakres 1–100;
`beforeId` jest dodatnim kursorem. Odpowiedź to `events` oraz `nextBeforeId`.
Zdarzenie zawiera `id`, `action`, `occurredAt`, `actorName`, opcjonalne `subject`
(`id`, `firstName`, `lastName`), `targetType`, `targetReference` i `changes`.
Zmiana to `field`, `label`, `before` i `after`; wartości są tekstem albo `null`.

Odczyt dopuszcza tylko rozpoznane zdarzenia administracji i cyklu dostępu.
Zmienione pola oraz zagnieżdżone przypisania mają jawne listy dopuszczonych wartości.
Historia haseł pokazuje jedynie fakt, autora i czas operacji. Nie serializujemy
surowych migawek, haseł/skrótów, wersji poświadczeń, sesji ani kluczy operacji.
Historia decyzji i korekt urlopowych jest dostępna przy wniosku, a limitów przy saldzie.
