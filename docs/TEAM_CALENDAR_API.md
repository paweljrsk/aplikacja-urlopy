# API kalendarza zespołu — etapy 8–9

Kalendarz jest ekranem startowym. Widoczność wynika z B15–B16 i B22–B24;
reguły zapisano w [REQUIREMENTS.md](REQUIREMENTS.md). Ten odczyt jest osobną,
ograniczoną projekcją, a nie listą prywatnych szczegółów wniosków.

## Zapytanie

`GET /api/team-calendar?from=2026-11-09&to=2026-11-22`

Wymagana aktywna sesja z docelowym hasłem. Odczyt nie potrzebuje CSRF. Odpowiedź
ma `Cache-Control: no-store, private`; odczyt i sprawdzenie aktualnych uprawnień
wykonują się w jednej transakcji odczytowej, po odświeżeniu encji użytkownika.

| Parametr | Znaczenie |
|---|---|
| `from`, `to` | Obowiązkowe daty `RRRR-MM-DD`, obie włącznie; lata 2025–2100; od 1 do 93 dni |
| `teamId` | Opcjonalny dodatni identyfikator zespołu, wyłącznie dla administratora |

Nieznane parametry, błędne daty i przekroczony zakres dają `422`. Próba użycia
`teamId` przez inną rolę daje `403`, także dla własnego zespołu. Nieistniejący
zespół w zapytaniu administratora daje `404`. Nie ma ukrytego limitu liczby wpisów:
zwracany jest kompletny dozwolony wynik w ograniczonym okresie.

## Odpowiedź

```json
{
  "today": "2026-11-09",
  "from": "2026-11-09",
  "to": "2026-11-22",
  "timezone": "Europe/Warsaw",
  "teams": [{"id": 1, "name": "Zespół IT"}],
  "selectedTeamId": 1,
  "people": [{"id": 7, "firstName": "Anna", "lastName": "Przykładowa"}],
  "entries": [{
    "key": "42:effective",
    "requestId": 42,
    "canViewDetails": false,
    "userId": 7,
    "status": "approved",
    "kind": "effective",
    "days": [{"date": "2026-11-12", "hours": 3}],
    "startDate": "2026-11-12",
    "endDate": "2026-11-12",
    "totalHours": 3
  }],
  "holidays": [{"date": "2026-11-11", "name": "Narodowe Święto Niepodległości"}]
}
```

Przykład jest syntetyczny. `people` zawiera tylko identyfikator i imię/nazwisko.
Są to aktywni bieżący członkowie dostępnego zespołu oraz osoby mające widoczny
wpis w zadanym okresie (także historyczne). Administrator bez `teamId` widzi
wszystkie zespoły i ma `selectedTeamId=null`; po wybraniu zespołu widzi jego
członków i odpowiednio przycięte wpisy. Pozostali otrzymują wyłącznie własny
bieżący zespół w `teams` i jego identyfikator w `selectedTeamId`. Osoba bez
przypisania ma pustą listę zespołów i dostęp jedynie do własnych wpisów.

`holidays` zawiera aktywne firmowe dni wolne i święta z serwisu kalendarza,
tylko w zadanym okresie. Weekendy wynikają z dat; nie są dodatkowymi wpisami.
Gdy dzień firmowy i święto mają tę samą datę, etykieta obejmuje obie nazwy.

| `kind` | `status` | Znaczenie |
|---|---|---|
| `pending` | `pending` | Nowy wniosek oczekujący na decyzję |
| `effective` | `approved` | Obowiązujący zatwierdzony urlop |
| `effective` | `change_pending` | Dotychczasowy urlop nadal obowiązuje; dostępna propozycja zmiany czeka |
| `proposed_change` | `change_pending` | Proponowane dni/godziny powiązane z obowiązującym urlopem przez `requestId` |
| `effective` | `cancellation_pending` | Dotychczasowy urlop nadal obowiązuje, anulowanie oczekuje na decyzję |

Anulowanie nie tworzy drugiego wpisu. Odrzucone, wycofane, zastąpione i skutecznie
anulowane wersje nie są nieobecnościami kalendarza. Historia pozostaje dostępna
przez osobno autoryzowane API wniosków. Klient nie może sumować wersji
obowiązującej i proponowanej jako dwóch oddzielnych urlopów tej samej osoby.

## Prywatność, transfery i granice danych

Każdy dzień każdej wersji jest sprawdzany przez `RequestAccessPolicy`.
Współpracownik widzi dni własnego zespołu. Dawne daty należą do zespołu pracownika
z tamtego dnia; dzisiejsze i przyszłe do jego obecnego zespołu. Zaplanowany
transfer nie daje nowemu zespołowi dostępu przed dniem wejścia w życie.
Właściciel zachowuje widoczność własnych dni również po transferze.

`days`, `startDate`, `endDate` i `totalHours` opisują wyłącznie udostępnione dni
wewnątrz zakresu zapytania. Nie są metadanymi całego wniosku. Nie zwracamy
dat ani sum niedostępnej części. Status obowiązującego urlopu nie ujawnia
oczekującej propozycji, jeśli jej żaden dzień nie jest dostępny w danym zakresie
zespołu. Dostępna propozycja może leżeć poza aktualnym oknem dat: wtedy sam status
informuje o oczekiwaniu, a jej dni pojawiają się po przejściu do właściwego okresu.

`canViewDetails` wskazuje, czy użytkownik ma prawo do prywatnych szczegółów tej
wersji. Przycisk może prowadzić do `GET /api/requests/{requestId}`, który ponownie
sprawdza uprawnienia i osobno filtruje każdą wersję. Współpracownik zwykle otrzyma
`false`; mieszany zakres dwóch zespołów nie daje przełożonemu dostępu do pełnych
szczegółów. Sam identyfikator w kalendarzu nie uprawnia do ich odczytu.

Nawet administrator nie otrzymuje w tym feedzie rodzaju nieobecności, salda,
adresów e-mail, form zatrudnienia, uzasadnień, aktorów decyzji, historii audytu,
kluczy operacji ani wersji technicznych. Takie dane należą do innych endpointów
z odrębną kontrolą dostępu. Sposób składania i obsługi wniosku opisuje
[REQUESTS_API.md](REQUESTS_API.md).

Scenariusze automatyczne są w `TeamCalendarApiTest`: sesja, wymuszone hasło,
unieważnienie dostępu, prywatność, statusy zmian/anulowania, transfery i zakresy,
odświeżanie roli, ścisłe wejście, puste przypisanie i brak cichego obcinania ponad
100 wpisów. Faktyczny wynik wykonania należy odnotować w TEST_PLAN.

## Prezentacja w etapie 9

Późniejsze rozszerzenie B37 dodaje „Miesiąc” do widoków 7/14 dni. Używa tego samego
endpointu z pierwszym i ostatnim dniem miesiąca; nie zmienia DTO ani zakresu danych.
Przejścia miesięczne uwzględniają długość miesiąca i rok przestępny. Lista przyszłych
nieobecności nadal pobiera osobny przedział dwóch miesięcy od daty dzisiejszej.

Kontrakt i reguły backendu pozostają bez zmian. Interfejs zawęża autoryzowane
odpowiedzi filtrami osoby i statusu wspólnymi dla kalendarza oraz listy dwóch
miesięcy. Status „Zatwierdzone” oznacza `kind=effective` także podczas zmiany
lub anulowania; „Oczekujące” oznacza `kind=pending`; „Zmiany i anulowania”
zachowuje dostępne wpisy ze statusem `change_pending` lub `cancellation_pending`.

Lista grupuje wpisy po `requestId`, pokazując osobno obowiązujący i proponowany
termin z ich własnymi godzinami. Nie sumuje wersji i nie łączy odrębnych wniosków
tej samej osoby w tym samym dniu. Odfiltrowana lub niedostępna wersja nie jest
odtwarzana po stronie klienta.

Kliknięcie dozwolonego wpisu wykonuje świeże `GET /api/requests/{id}` w dialogu.
Flaga z feedu nie zastępuje autoryzacji: datowane przeniesienie może unieważnić
prawo do szczegółów, mimo nadal aktywnej sesji. Gdy API zwraca tylko historię
(`currentVisible=false`, `status=null`), ekran nie przypisuje statusu ukrytej
wersji. Zestaw testów sprawdza przejście pomiędzy endpointami, role, zakres
historyczny i zmianę dostępu po północy w Warszawie.
