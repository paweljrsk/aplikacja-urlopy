# Otwarte pytania

Stan: 6 listopada 2026 r., po odpowiedziach przed etapem 2. Q1 rozstrzygnięte;
Q5 i Q8 rozstrzygnięte częściowo. Pozostałe pytania są otwarte.

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
| Q2 | Część o dzisiaj przed etapem 6; korekty przed 7 | Czy pracownik może składać wnioski na dzisiejszy dzień? Bez godzin od–do nie znamy faktycznej pory rozpoczęcia. Czy uprawniona osoba zmienia rozpoczęty/przeszły wniosek bezpośrednio, czy składa zmianę do akceptacji? Jak obsłużyć jej własny wniosek? Zachować wyjątek kierownika i zakaz własnej akceptacji zastępcy. |
| Q3 | Przed etapem 5 | Jak potraktować oczekujące stare wnioski przy zmianie roku? Czy przyszły urlop może korzystać z przewidywanego przeniesienia przed rozliczeniem poprzedniego roku? Nie udostępniać tych samych godzin dwukrotnie. |
| Q4 | Przed etapem 5, rozszerzyć w 7 | Co zrobić, gdy obniżenie limitu lub korekta historyczna powoduje niedobór wobec zatwierdzonych urlopów: blokada korekty czy jawny niedobór wymagający uporządkowania? Nie anulować samodzielnie zatwierdzonych wniosków. |
| Q5 | Częściowo rozstrzygnięte; reszta przed etapem 4 | Potwierdzono zmianę zespołu/formy zatrudnienia osoby z historią od wskazanej daty, z zachowaniem wcześniejszych danych i rozliczeń (B19). Zmiana wpływu rodzaju na saldo dotyczy wyłącznie nowych wniosków; już złożone zachowują poprzednie zasady (B20). Nadal trzeba ustalić widoczność historii po zmianie zespołu oraz obsługę otwartych i już złożonych przyszłych wniosków przy zmianie zespołu/formy zatrudnienia. |
| Q6 | Przed etapem 5 | Czy kierownik może zmieniać także własny limit? Uprawnienie do zatwierdzania własnego urlopu nie rozstrzyga tej kwestii. |
| Q7 | Przed wdrożeniem produkcji | Docelowy adres i TLS, odpowiedzialny za serwer, miejsce i retencja backupów, retencja historii, wymagania firmowych skanerów i sposób przekazywania haseł tymczasowych. Ustalić konkretną konfigurację bez publikowania sekretów. |
| Q8 | Podstawa rozstrzygnięta; procedura korekt przed etapem 5 | Potwierdzono datę rozpoczęcia ewidencji dla pracownika, limit bieżącego roku, wykorzystanie z bieżącej rocznej puli do dnia poprzedniego i pozostałość starszych pul osobno według roku pochodzenia (B21). Wcześniejsze nieobecności są uwzględnione w danych początkowych i nie mogą pomniejszyć salda ponownie. Nadal trzeba ustalić szczegółową procedurę korekt danych/wniosków sprzed daty granicznej; nie odtwarzamy pojedynczych wniosków z samego agregatu. |
| Q9 | Przed etapem 5 | Jak dodanie lub usunięcie firmowego dnia wolnego wpływa na złożone i zaakceptowane wnioski? Ustalić zakres dat i kontrolowane przeliczenie godzin/salda; nie zmieniać historycznych rozliczeń po cichu. |

Dodatkowe dni wolne firmy i obowiązkowość komentarza do korekty/odrzucenia można ustalić przy odpowiednim ekranie. Szczegóły wyglądu proponuje wykonawca w ramach stylistyki Notus; decyzji biznesowych nie zastępuje domyślnym zachowaniem biblioteki UI.
