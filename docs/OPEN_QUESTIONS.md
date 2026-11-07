# Otwarte pytania

Stan: 7 listopada 2026 r., po odpowiedzi „Tak” przed implementacją etapu 7.
Q1–Q6 oraz Q8–Q10 rozstrzygnięte. Otwarte pozostaje Q7 przed produkcją.

Pytania nie blokują audytu i dokumentacji w etapie 1. Należy je zadać przed pracą,
której wynik zależy od odpowiedzi. Jeżeli wpływ na model ujawni się wcześniej,
np. w etapie 2, trzeba zapytać wtedy. Nie wolno uznawać propozycji za decyzję
ani omijać niejasności przez zastosowanie domyślnego zachowania biblioteki.

Po otrzymaniu odpowiedzi zapisz datę i jej treść w [DECISIONS.md](DECISIONS.md),
uzupełnij [REQUIREMENTS.md](REQUIREMENTS.md) i zmień status pytania tutaj.

## 3. Pytania przed właściwymi etapami

Nie blokują przedstawienia planu, ale blokują implementację zależnego zachowania. Nie traktować proponowanej odpowiedzi jako zatwierdzonej. Jeśli odpowiedź okaże się potrzebna już do modelu w etapie 2, zapytać wtedy, zamiast odraczać problem.

| ID | Status / kiedy zapytać | Ustalenie lub kwestia do rozstrzygnięcia |
|---|---|---|
| Q1 | Rozstrzygnięte przed etapem 2 | Jeden wniosek obejmuje zakres pełnych dni albo jeden dzień na 1–7 pełnych godzin. Nie obsługujemy częściowych godzin dla zakresu wielodniowego. Decyzja B18. |
| Q2 | Rozstrzygnięte — B30/B32 | Dzisiejszy dzień według B30; korekty z wcześniejszymi datami bezpośrednio przez uprawnioną osobę po podglądzie i potwierdzeniu, z powodem i historią. Kierownik może własny wniosek, zastępca nie. |
| Q3 | Rozstrzygnięte — B25 | Stare rezerwacje pozostają; UoP przenosi wolne godziny od początku roku. Późniejsze zwolnienie starej rezerwacji udostępnia dodatkową zaległość. Bez przewidywanego przeniesienia przed początkiem roku; przyszły ręczny limit dostępny. |
| Q4 | Rozstrzygnięte — B26/B33 | Korekty limitów według B26; historyczne korekty wniosku zwiększające niedobór tylko przez administratora po jawnym potwierdzeniu. Inne zatwierdzone urlopy pozostają zachowane. |
| Q5 | Rozstrzygnięte przed etapem 4 | B19–B20: datowane zmiany i zachowanie wersji zasad. B22–B24: historia według dat nieobecności (stary/nowy zespół), pełna historia dla właściciela/admina; od dnia przeniesienia przyszłe oczekujące wnioski obsługuje nowy przełożony, wcześniejsze akceptacje pozostają ważne. Wnioski przekraczające granicę zespołów obsługuje administrator bez dzielenia. Zmiana formy zatrudnienia nie przelicza złożonych wniosków; nowe używają formy z dnia nieobecności. |
| Q6 | Rozstrzygnięte — B27 | Własny limit kierownika zmienia administrator. Zastępca nie zmienia limitów. |
| Q7 | Przed wdrożeniem produkcji | Docelowy adres i TLS, odpowiedzialny za serwer, miejsce i retencja backupów, retencja historii, wymagania firmowych skanerów i sposób przekazywania haseł tymczasowych. Ustalić konkretną konfigurację bez publikowania sekretów. |
| Q8 | Rozstrzygnięte — B21 i B28 | Dane początkowe według daty rozpoczęcia: limit, wykorzystanie bieżącej puli, pozostałości starszych pul. Korekty z uzasadnieniem i audytem, bez ponownego obciążania historycznymi wnioskami. Data startu zablokowana po rozpoczęciu rozliczeń wniosków. |
| Q9 | Rozstrzygnięte — B29 | Przyszłe daty, podgląd wpływu i potwierdzenie administratora; jawne korekty istniejących wniosków, zwrot/zwiększenie godzin, anulowanie lub wycofanie przy zerze. Brak zbiorczego przeliczania przeszłości. |
| Q10 | Rozstrzygnięte — B31 | Obowiązkowy powód odrzucenia, widoczny właścicielowi wniosku i osobom uprawnionym do jego szczegółów. |

### Zatwierdzone Q2 dla etapu 6 — B30

Własny wniosek można złożyć na dzisiaj. Do końca dnia w Europe/Warsaw można też
edytować lub wycofać własny oczekujący wniosek, jeżeli nie obejmuje wcześniejszych
dni. Brak godzin zegarowych oznacza brak kontroli pory rozpoczęcia dzisiejszej
nieobecności. Wcześniejsze daty pozostają zablokowane dla samoobsługi pracownika.
Indywidualne korekty rozpoczętych i przeszłych wniosków pozostają zakresem etapu 7.

Zarządzanie firmowymi dniami wolnymi oraz obowiązkowe uzasadnienie korekt limitów,
danych początkowych i kalendarza rozstrzygnięto dla etapu 5. Procedury indywidualnych korekt wniosków rozstrzygnięto w B32–B33. Uzasadnienie
odrzucenia jest obowiązkowe według B31. Szczegóły wyglądu proponuje wykonawca w ramach stylistyki Notus;
decyzji biznesowych nie zastępuje domyślnym zachowaniem biblioteki UI.

### Zatwierdzone na początku etapu 7 — B32 i B33

Obie poniższe propozycje użytkownik zatwierdził odpowiedzią „Tak” 7 listopada 2026 r.

1. **Q2 — tryb korekty rozpoczętego/przeszłego wniosku.** Zatwierdzono: uprawniony
   kierownik, zastępca lub administrator zapisuje korektę bezpośrednio, po podglądzie
   skutków i jawnym potwierdzeniu, z obowiązkowym powodem. Ta operacja zapisuje również
   decyzję i pełną historię; nie tworzy kolejnej kolejki akceptacji. Kierownik może
   skorygować własny wniosek, natomiast własny wniosek zastępcy koryguje kierownik albo
   administrator w swoim dozwolonym zakresie. Zachować B22–B24 i kontrolę obu wersji,
   w tym obsługę zakresu przekraczającego zespoły przez administratora.
   Zwykłe przyszłe zmiany/anulowania pozostają propozycją wymagającą osobnej decyzji.
   Dzisiejszy dzień rozpatrywać jak dotąd według B30; ta propozycja dotyczy przypadków
   zawierających wcześniejsze daty. Przeszłość sprzed początku ewidencji nadal koryguje
   się przez dane początkowe (B28), nie przez ponowne księgowanie starych wniosków.
2. **Q4 — historyczna korekta zwiększająca niedobór po wykorzystaniu przeniesienia.**
   Zatwierdzono: wolno ją zatwierdzić wyłącznie administratorowi po podglądzie wpływu
   i jawnym potwierdzeniu niedoboru, z obowiązkowym uzasadnieniem. Korekta pozostawia
   źródło i rok pochodzenia godzin oraz historię zmian; nie anuluje innych zaakceptowanych
   urlopów. System pokazuje niedobór i blokuje nowe obciążenia, które go zwiększają.
   Kierownik/zastępca wykonuje korekty w swoim zakresie, jeśli nie zwiększają niedoboru;
   w przeciwnym razie sprawę obsługuje administrator. To zatwierdzone rozszerzenie na
   historyczne wnioski, nie zmiana zatwierdzonych uprawnień do korekt limitów z B26/B27.

Odpowiedź zapisano w REQUIREMENTS 1.5 i DECISIONS B32–B33 przed zależną implementacją.
