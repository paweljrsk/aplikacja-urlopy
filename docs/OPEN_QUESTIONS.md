# Otwarte pytania

Stan: 7 listopada 2026 r., po odpowiedziach przed etapem 6. Q1, Q3, Q5, Q6, Q8,
Q9 i Q10 rozstrzygnięte; Q4 rozstrzygnięte dla etapu 5, Q2 w zakresie etapu 6.
Otwarte pozostają Q7 oraz procedury indywidualnych korekt wniosków w etapie 7.

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
| Q2 | Dzisiejszy dzień rozstrzygnięty — B30; korekty przed 7 | Dzisiaj dozwolone składanie, edycja i wycofanie własnego oczekującego wniosku bez wcześniejszych dat. Do ustalenia przed 7: czy uprawniona osoba zmienia rozpoczęty/przeszły wniosek bezpośrednio, czy składa zmianę do akceptacji; jak obsłużyć jej własny wniosek. Zachować wyjątek kierownika i zakaz własnej akceptacji zastępcy. |
| Q3 | Rozstrzygnięte — B25 | Stare rezerwacje pozostają; UoP przenosi wolne godziny od początku roku. Późniejsze zwolnienie starej rezerwacji udostępnia dodatkową zaległość. Bez przewidywanego przeniesienia przed początkiem roku; przyszły ręczny limit dostępny. |
| Q4 | Rozstrzygnięte dla etapu 5 — B26; procedury wniosków w 7 | Korekta z uzasadnieniem może powodować jawny niedobór. Nie anulujemy zatwierdzonych urlopów; nowe obciążenia zwiększające niedobór są blokowane. |
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
danych początkowych i kalendarza rozstrzygnięto dla etapu 5. Do ustalenia przy
kolejnych etapach pozostają procedury indywidualnych korekt wniosków. Uzasadnienie
odrzucenia jest obowiązkowe według B31. Szczegóły wyglądu proponuje wykonawca w ramach stylistyki Notus;
decyzji biznesowych nie zastępuje domyślnym zachowaniem biblioteki UI.
