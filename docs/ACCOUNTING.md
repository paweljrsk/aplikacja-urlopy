# Limity i rozliczenia godzinowe — etapy 5–7

Zasady biznesowe: B21, B25–B29 oraz B32–B33 w [DECISIONS.md](DECISIONS.md).
Kontrakt HTTP: [BALANCES_API.md](BALANCES_API.md).
Kalendarz i jego źródła: [CALENDAR.md](CALENDAR.md).

## Pierwsza konfiguracja

Na `/balances` pracownik widzi własne saldo. Kierownik i zastępca mają również
odczyt sald obecnego zespołu, administrator wszystkich osób. Kierownik ustawia
limity innych osób swojego zespołu; własny limit kierownika zmienia administrator.
Zastępca nie zmienia limitów ani danych początkowych.

Najpierw wybierz osobę i rok rozpoczęcia ewidencji, następnie **Ustaw dane
początkowe**. Wpisz datę rozpoczęcia, limit tego roku, wykorzystanie z jego puli
do dnia poprzedzającego start oraz pozostałe godziny starszych pul, osobno według
roku pochodzenia. Starsze pule są dostępne dla UoP. Ich pozostałość jest już
pomniejszona o wcześniejsze wykorzystanie; nie odejmujemy go ponownie.

Przykład: 208 h limitu, 40 h wykorzystania bieżącej puli i 16 h pozostałości
starszej puli daje 184 h dostępnych. Limit można wpisać w pełnych dniach: 26 dni
to 208 h. Pozostałości i korekty przyjmują pełne godziny, np. 68 h = 8 dni i 4 h.
Obliczenia i zapis wykorzystują liczby całkowite, bez arytmetyki zmiennoprzecinkowej.

Następne lata otrzymują ręczny przydział przez **Ustaw limit roczny**. Brak limitu
jest osobnym stanem od jawnego limitu 0. Brak konfiguracji roku blokuje rezerwację
obciążającą saldo w tym roku, nawet jeśli istnieją pozostałości starszych pul.

## Źródła i wzór salda

`LeaveAccount` określa rok nieobecności i skonfigurowany limit. `HourPool` określa
rok pochodzenia godzin. Ta sama pula może finansować różne lata; nie tworzymy
drugiej kopii przy przenoszeniu zaległości.

Dla każdej puli sumujemy wszystkie jej niezmienne zapisy `LedgerEntry`:

```text
pozostałość puli = suma przydziałów − suma rezerwacji − suma obciążeń
dostępne godziny = suma pozostałości pul dopuszczonych dla wybranego roku
niedobór = max(0, −dostępne godziny)
```

| Pozycja | Źródło |
|---|---|
| Limit roczny | Ręczny przydział i późniejsze korekty rocznej puli |
| Wykorzystanie przed startem | `OpeningBalance` oraz jawne korekty obciążenia bieżącej puli, bez fikcyjnych dni wniosków |
| Pozostałości starszych lat na start | `OpeningCarry` według roku pochodzenia oraz jawne korekty przydziałów tych pul |
| Oczekujące wnioski | Netto rezerwacji początkowych wersji wniosków |
| Dodatkowa rezerwacja na zmiany | Niepokryta część propozycji po dopasowaniu finansowania obowiązującego urlopu w tym samym roku nieobecności i w dozwolonych pulach |
| Zatwierdzone przyszłe | Netto obciążeń dni po dzisiejszej dacie w Europe/Warsaw |
| Wykorzystane w ewidencji | Netto obciążeń dni do dzisiejszej daty włącznie |
| Dostępna zaległość | Pozostałości dopuszczonych starszych pul po ich wszystkich rezerwacjach i obciążeniach |

Podział wykorzystane/przyszłe służy prezentacji; upływ dnia nie tworzy następnego
potrącenia. Godziny nie określają pory nieobecności. Nie sumuj tej samej puli między
widokami różnych lat. Widok roku pokazuje bieżącą dostępność do rozliczania jego
nieobecności, a nie zamrożony historyczny raport salda na 31 grudnia.

## Przełom roku

UoP korzysta z najstarszych dostępnych pul. Dopiero po rozpoczęciu roku można
używać jego zaległości; przedtem przyszłe nieobecności korzystają z ręcznie
wpisanego przydziału przyszłego roku. B2B i pozostałe formy nie używają starszych
pul. Roczna pula zakończona przy zatrudnieniu B2B nie staje się zaległością po
późniejszym przejściu na UoP. Kwalifikację zwykłej puli sprawdzamy również według
zapisanej formy na ostatni dzień roku jej pochodzenia. Jawnie wprowadzone
pozostałości początkowe mają własne potwierdzone pochodzenie; nie wymagają
odtwarzania przypisań sprzed granicy ewidencji. Nie wygaszamy automatycznie
zaległości UoP.

Oczekujący grudniowy wniosek pozostaje zarezerwowany również w styczniu. Przy
24 h pozostałości, z czego 8 h zarezerwowanych, nowy rok może korzystać z 16 h.
Odrzucenie starego wniosku udostępnia kolejne 8 h. Zatwierdzenie zamienia rezerwację
w obciążenie bez dodatkowego potrącenia. Wniosek grudzień–styczeń korzysta z kont
odpowiednich lat według dat poszczególnych dni.

**Rozlicz rok** zapisuje fakt rozliczenia. Dostępność zaległości wynika z daty,
zasad zatrudnienia i wspólnej puli; operacja nie powiela przydziałów. Ponowienie
rozliczenia nie dodaje godzin. Istniejące wnioski zachowują zapisaną formę
zatrudnienia po zmianie umowy.

## Zmiana i anulowanie zatwierdzonego wniosku

Do decyzji obowiązuje stary termin i jego obciążenie. Propozycja nie zwraca godzin
ze skrócenia ani anulowania. Dodatkowo rezerwujemy tylko część potrzebną do pokrycia
wariantu proponowanego, z osobną kontrolą roku nieobecności i dozwolonych źródeł.
Zwykła zmiana nie może zwiększyć niedoboru, nawet przy niezmienionej sumie godzin.

| Przypadek | Do decyzji | Po akceptacji |
|---|---|---|
| 16 h → 24 h w tym samym roku i zgodnych pulach | 16 h nadal obciążone, dodatkowe 8 h zarezerwowane, również przy zmianie dat | 24 h obciążone, brak dodatkowej rezerwacji |
| 16 h → 8 h | Nadal 16 h obciążone | Zwrot 8 h |
| 8 h z grudnia → 8 h w styczniu | Grudniowe obciążenie oraz pokrycie nowych 8 h w roku styczniowym | Zwrot starego zobowiązania i obciążenie nowego roku |
| Płatne 8 h → nieobciążające salda 8 h | Stare 8 h nadal obciążone | Zwrot 8 h |
| Nieobciążające salda 8 h → płatne 8 h | Nowe 8 h zarezerwowane | Rezerwacja zamieniona na obciążenie |
| Anulowanie | Dotychczasowe obciążenie i zajętość dnia pozostają | Jednorazowy zwrot i zwolnienie dni |

Pokrycie finansowe propozycji sprawdzamy na faktycznych źródłach już zatwierdzonego
wniosku. Godziny finansowane zaległością UoP nie pokrywają propozycji B2B wymagającej
puli rocznej. Najpierw dopasowywane są godziny wymagające wyłącznie bieżącej puli,
potem godziny dopuszczające starsze pule. Każda godzina pokrycia jest używana raz.
Przy propozycji zachowujemy dokładne źródła obowiązującego obciążenia; dopisanie
starszej puli do danych początkowych nie refinansuje samoczynnie starego urlopu.

Po decyzji najpierw wykorzystujemy już zaangażowane w ten wniosek godziny w tym
samym roku i w dozwolonym źródle. Rejestr dostaje przeciwstawne wpisy do starych
pozycji oraz nowe pozycje z identyfikatorami nowej wersji. Zwrot wraca do pierwotnej
puli, a nie do umownej puli bieżącego roku. Nie przepisujemy księgowań innych
wniosków. Akceptacja istniejącej rezerwacji po korekcie limitu zachowuje jej
pochodzenie i nie przenosi istniejącego niedoboru do innej puli.

Zajętość dnia jest niezależna od finansowania: na każdy dzień bierzemy maksimum
godzin wersji obowiązującej i proponowanej, następnie dodajemy inne aktywne wnioski.
Zmiana 3 h → 5 h w jednym dniu zajmuje 5 h. Przeniesienie 8 h z poniedziałku na
wtorek chroni oba dni do decyzji, choć w tym samym roku i przy zgodnych źródłach
nie wymaga drugiej rezerwacji finansowej. Limit 8 h obowiązuje również rodzaje
nieobciążające salda.

## Korekty i historia

Korekta limitu oraz danych początkowych wymaga uzasadnienia. Oryginalne
`OpeningBalance`, `OpeningCarry`, zdarzenia i księgowania pozostają zachowane.
Nowa operacja dopisuje różnicę i audyt: kto, kiedy, poprzednie i nowe wartości.
Ekran danych początkowych pokazuje wartości po korektach.

Korekta może ujawnić ujemne saldo. Nie odwołuje zatwierdzonych urlopów.
Nowe obciążenia zwiększające niedobór są blokowane; istniejącą rezerwację nadal
można zatwierdzić albo zwolnić. Okres sprzed granicy ewidencji poprawiamy przez
dane początkowe. Daty startu nie można zmienić po wprowadzeniu wniosków; przedtem
dopuszczona jest korekta w obrębie tego samego roku. Zmiana roku startu wymaga
osobnej migracji rozliczeń, której ten etap nie wykonuje.

Przy edycji wniosku sprawdzamy również źródło godzin. Zmiana terminu z UoP na B2B
nie może zastąpić finansowania zaległością niepokrytym obciążeniem bieżącej puli,
nawet jeśli łączny wymiar się nie zmienia. Ochrona istniejącego zobowiązania obejmuje
zarówno sumę danego roku, jak i godziny wymagające finansowania wyłącznie jego
roczną pulą. Akceptacja istniejącej rezerwacji po korekcie limitu nadal jest możliwa.

B32–B33 dopuszczają jawną indywidualną korektę historycznego wniosku po podglądzie
skutków i potwierdzeniu, z uzasadnieniem oraz zapisaną decyzją. Zwiększenie niedoboru
jest uprawnieniem administratora. Wyjątek dotyczy nowych niepokrytych godzin z dat
wcześniejszych niż dzisiaj; przesunięcie starego wniosku na większy przyszły wymiar
nie omija kontroli dostępnego salda. Korekty kalendarza administratora zachowują
osobne, wcześniej zatwierdzone zasady B29 dla przyszłych dat.

Historia salda w API ogranicza zakres informacji według uprawnień. Przełożony
obecnego zespołu nie otrzymuje tą drogą szczegółów wniosków poprzedniego zespołu.

## Transakcje i późniejsze etapy

Zapisy synchronizują się ze zmianami administracyjnymi przez wspólny rekord
blokady, następnie blokują konta użytkowników i wnioski. Uprawnienia są sprawdzane
ponownie pod blokadą. Formularze przekazują wersję stanu pracownika, obejmującą
również wspólne pule i zmianę daty. Nieaktualny formularz otrzymuje konflikt 409.

Odczyt salda obejmuje jedną transakcję, aby powiązane sumy i wersja pochodziły
z tego samego obrazu bazy.

Klucz operacji zabezpiecza ponowienie po przerwaniu połączenia. Powtórzenie tej
samej operacji nie nalicza godzin ponownie; użycie klucza z innymi danymi jest
odrzucane. Błąd dowolnego dnia wycofuje całą transakcję.

Przygotowano wewnętrzne operacje dla zwalidowanych, zapisanych wersji:
`reserveRevision`, `releaseRevision`, `approveRevision` oraz
transakcyjne `reconcileRequest`. Ostatnia operacja rozlicza obowiązującą i
oczekującą wersję, zapisuje różnice oraz kontroluje zajętość dnia dla wszystkich
rodzajów nieobecności. `RequestAccountingState` jest projekcją zajętości jednego
wniosku; źródłem finansowej historii pozostaje niezmienny rejestr księgowań.

Etap 6 dodaje publiczny `RequestWorkflowService` oraz API tworzenia, edycji
oczekującego wniosku i decyzji. Usługa sprawdza reguły dat, kalendarz, dostępność
rodzaju, zatrudnienie i uprawnienia przed atomowym rozliczeniem. Techniczne
potwierdzenie `request.operation_completed` zapewnia idempotencję, ale nie jest
drugim księgowaniem i nie pojawia się w historii salda. Właściwe zdarzenia
`request.created`, `request.edited` i decyzje pozostają widoczne zgodnie z rolą.

Etap 7 rozszerza ten proces o zmiany zaakceptowanego urlopu i indywidualne korekty.
Zakres faktycznie zweryfikowanego działania i wyniki odbioru są w TEST_PLAN. Formularz
składania i pełna stylistyka Notus pozostają w etapie 8, główny kalendarz w etapie 9.
Kontrakt procesu opisuje [REQUESTS_API.md](REQUESTS_API.md).
