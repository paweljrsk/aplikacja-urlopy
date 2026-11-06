# Operacje i wdrożenie

Szkielet dokumentacji na etapie 1. Ten plik nie potwierdza gotowości produkcyjnej,
wykonanego wdrożenia, backupu ani odtworzenia bazy.

## Środowiska

- Development i testy używają oddzielnych danych syntetycznych.
- Docelowa produkcja działa na własnym serwerze Docker, za VPN/siecią wewnętrzną.
- Repozytorium może być publiczne. Konfiguracja produkcyjna i sekrety pozostają poza Git.
- Polecenia lokalnego startu, zatrzymania, restartu i diagnostyki utrzymujemy
  w [README.md](../README.md). Muszą odpowiadać aktualnemu Compose i Makefile.

## Zasady obsługi danych

Zatrzymanie/restart usług nie może usuwać danych. Operacje usuwania bazy i wolumenów
są destrukcyjne i nie mogą stanowić zwykłej procedury naprawy ani aktualizacji.
Zmiany schematu realizujemy migracjami Doctrine. Przed aktualizacją istniejącej bazy
trzeba znać wpływ migracji, plan odtworzenia i zgodność wersji kodu ze schematem.

Nie ładujemy demonstracyjnych kont na produkcję. Bootstrap administratora i sposób
przekazania hasła tymczasowego zostaną opisane po implementacji logowania.

## Do ustalenia przed produkcją

Pytanie [Q7](OPEN_QUESTIONS.md) obejmuje:

- docelowy adres aplikacji, TLS i reverse proxy;
- osobę odpowiedzialną za serwer i administrację dostępem;
- źródło sekretów i sposób ich zmiany;
- miejsce, harmonogram i retencję kopii bazy;
- retencję historii i logów;
- wymagania skanerów firmy oraz sposób przekazania haseł tymczasowych.

## Backup i odtworzenie

W etapie 12 uzupełnimy konkretne polecenia backupu MySQL i bezpieczne miejsce
przechowywania zgodnie z Q7. Kopii nie zapisujemy w repozytorium. Weryfikacja obejmie
odtworzenie na odizolowanej bazie i odczyt kont, wniosków, sald oraz audytu.
Samo utworzenie pliku kopii nie potwierdza możliwości odtworzenia.

## Aktualizacja i powrót po awarii

W etapie 12 trzeba opisać i sprawdzić kolejność backupu, wdrożenia kodu, migracji,
weryfikacji usług i ewentualnego powrotu. Powrót do poprzedniego kodu nie wystarcza,
jeżeli zmienił się niekompatybilnie schemat danych. Dokładna procedura będzie oparta
na implementacji i testach, a nie na założeniu, że każda migracja jest odwracalna.

## Lista odbiorowa produkcji

- [ ] Konfiguracja produkcyjna z debug wyłączonym i sekretami poza repozytorium.
- [ ] HTTPS, VPN i ograniczenie dostępności usług infrastruktury.
- [ ] Bezpieczny bootstrap administratora bez danych demonstracyjnych.
- [ ] Restart usług z zachowaniem danych.
- [ ] Migracje przetestowane na istniejącej bazie testowej.
- [ ] Backup i odtworzenie zweryfikowane na odizolowanej bazie.
- [ ] Udokumentowany plan powrotu po nieudanej aktualizacji.
- [ ] Instrukcja pilotażu dla 10 osób i potwierdzony odbiór.
