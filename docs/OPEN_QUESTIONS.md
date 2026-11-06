# Otwarte pytania

Stan: 6 listopada 2026 r. Wszystkie Q1–Q9 poniżej pozostają otwarte.

Pytania nie blokują audytu i dokumentacji w etapie 1. Należy je zadać przed pracą,
której wynik zależy od odpowiedzi. Jeżeli wpływ na model ujawni się wcześniej,
np. w etapie 2, trzeba zapytać wtedy. Nie wolno uznawać propozycji za decyzję
ani omijać niejasności przez zastosowanie domyślnego zachowania biblioteki.

Po otrzymaniu odpowiedzi zapisz datę i jej treść w [DECISIONS.md](DECISIONS.md),
uzupełnij [REQUIREMENTS.md](REQUIREMENTS.md) i zmień status pytania tutaj.

## 3. Pytania przed właściwymi etapami

Nie blokują przedstawienia planu, ale blokują implementację zależnego zachowania. Nie traktować proponowanej odpowiedzi jako zatwierdzonej. Jeśli odpowiedź okaże się potrzebna już do modelu w etapie 2, zapytać wtedy, zamiast odraczać problem.

| ID | Kiedy zapytać | Kwestia do rozstrzygnięcia |
|---|---|---|
| Q1 | Przed etapem 6, najpóźniej przed formularzem | Gdy użytkownik zmniejsza liczbę godzin: czy wniosek dotyczy tylko jednego dnia? Propozycja prostego MVP: zakres pełnych dni albo godziny pojedynczego dnia. Jeśli godziny mają dotyczyć wielu dni, ustalić, czy wartość jest dzienna, sumaryczna czy osobna dla każdego dnia. |
| Q2 | Część o dzisiaj przed etapem 6; korekty przed 7 | Czy pracownik może składać wnioski na dzisiejszy dzień? Bez godzin od–do nie znamy faktycznej pory rozpoczęcia. Czy uprawniona osoba zmienia rozpoczęty/przeszły wniosek bezpośrednio, czy składa zmianę do akceptacji? Jak obsłużyć jej własny wniosek? Zachować wyjątek kierownika i zakaz własnej akceptacji zastępcy. |
| Q3 | Przed etapem 5 | Jak potraktować oczekujące stare wnioski przy zmianie roku? Czy przyszły urlop może korzystać z przewidywanego przeniesienia przed rozliczeniem poprzedniego roku? Nie udostępniać tych samych godzin dwukrotnie. |
| Q4 | Przed etapem 5, rozszerzyć w 7 | Co zrobić, gdy obniżenie limitu lub korekta historyczna powoduje niedobór wobec zatwierdzonych urlopów: blokada korekty czy jawny niedobór wymagający uporządkowania? Nie anulować samodzielnie zatwierdzonych wniosków. |
| Q5 | Przed etapem 4 | Czy MVP dopuszcza zmianę zespołu/formy zatrudnienia osoby z historią oraz zmianę wpływu rodzaju na saldo? Jeśli tak, ustalić datę obowiązywania, widoczność historii i obsługę otwartych wniosków. Propozycja: brak automatycznej zmiany przeszłych rozliczeń. |
| Q6 | Przed etapem 5 | Czy kierownik może zmieniać także własny limit? Uprawnienie do zatwierdzania własnego urlopu nie rozstrzyga tej kwestii. |
| Q7 | Przed wdrożeniem produkcji | Docelowy adres i TLS, odpowiedzialny za serwer, miejsce i retencja backupów, retencja historii, wymagania firmowych skanerów i sposób przekazywania haseł tymczasowych. Ustalić konkretną konfigurację bez publikowania sekretów. |
| Q8 | Przed etapem 5 | Na jaki dzień wprowadzamy saldo początkowe i wykorzystanie? Czy zaległość oznacza stan na początek roku, czy pozostałość na dzień startu? Ustalić przypisanie wykorzystania do pul oraz procedurę wprowadzania/korygowania wniosków sprzed granicy danych początkowych. |
| Q9 | Przed etapem 5 | Jak dodanie lub usunięcie firmowego dnia wolnego wpływa na złożone i zaakceptowane wnioski? Ustalić zakres dat i kontrolowane przeliczenie godzin/salda; nie zmieniać historycznych rozliczeń po cichu. |

Dodatkowe dni wolne firmy i obowiązkowość komentarza do korekty/odrzucenia można ustalić przy odpowiednim ekranie. Szczegóły wyglądu proponuje wykonawca w ramach stylistyki Notus; decyzji biznesowych nie zastępuje domyślnym zachowaniem biblioteki UI.
