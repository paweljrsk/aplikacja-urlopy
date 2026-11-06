# Instrukcje pracy w repozytorium

Repozytorium: `paweljrsk/aplikacja-urlopy`.
Dokumentacja biznesowa i plan: wersja 1.0 z 6 listopada 2026 r., rozwijane w `docs/`.
Pracuj w zakresie aktualnego polecenia użytkownika i uzgodnionego etapu.

## Przed pracą

1. Przeczytaj `README.md`, `TASKS.md`, `docs/REQUIREMENTS.md`, `docs/DECISIONS.md`,
   `docs/OPEN_QUESTIONS.md` oraz opis wskazanego etapu w `docs/DEVELOPMENT_PLAN.md`.
2. Respektuj instrukcje katalogowe. Dla frontendu przeczytaj `frontend/AGENTS.md`
   i wskazaną tam dokumentację do zainstalowanej wersji Next.js przed zmianami kodu.
   Zachowaj ten plik; główne instrukcje go nie zastępują.
3. Sprawdź stan Git i aktualny kod. Zachowaj cudze zmiany i ukończoną pracę.
4. Jeżeli brak lub sprzeczność wpływa na zachowanie biznesowe, dostęp do danych albo
   rozliczenia, najpierw zadaj konkretne pytanie i zaczekaj z zależną implementacją.
   Propozycje przy pytaniach nie są decyzjami. Odpowiedź zapisz w dokumentacji.
5. Oznacz realizowane zadanie jako `[-]`. Wznowienie zaczynaj od ostatniego
   niezakończonego zadania wskazanego w TASKS.

## W trakcie

6. Zachowaj stack i standard konfiguracji repozytorium. Zmiany zależności uzasadniaj
   potrzebą, kompatybilnością, utrzymaniem, licencją i wpływem na bezpieczeństwo.
   Korzystaj z dokumentacji wersji rzeczywiście zainstalowanej w projekcie.
7. Reguły i uprawnienia egzekwuj w backendzie. Schemat zmieniaj migracjami Doctrine,
   bez osobnego instalatora zastępującego migracje. Zadbaj o transakcje, audyt,
   idempotencję oraz zachowanie istniejących danych.
8. Nie umieszczaj w repozytorium sekretów, rzeczywistych danych pracowników ani
   kopii bazy. Dane demonstracyjne i testowe muszą być syntetyczne.
9. Nie resetuj bazy ani wolumenów w ramach startu, aktualizacji lub naprawy.
   Nie ukrywaj błędów uruchomienia lub migracji. Zachowaj informacje potrzebne
   do rozpoznania i odtworzenia problemu.
10. Dodawaj sensowne testy nowego zachowania. Nie odkładaj testów salda i uprawnień
    na etap końcowy. Używaj kontrolowanego czasu i izolowanych danych testowych.

## Po pracy

11. Uruchom adekwatne testy i kontrole. Raportuj rzeczywisty wynik. Weryfikacja
    kodu nie zastępuje uruchomienia testu; niewykonanego testu nie oznaczaj jako zaliczonego.
12. Aktualizuj README, TASKS, wymagania, decyzje i plan testów. Zapisuj istotne
    problemy i rozwiązania. Status `[x]` nadaj dopiero po implementacji i weryfikacji.
13. Zakończ raportem: co działa, co zmieniono i dlaczego, nowe biblioteki, wyniki
    testów, instrukcja ręcznego odbioru, ograniczenia i pytania. Wskaż punkt wznowienia.
14. Nie przechodź samodzielnie do następnego etapu. Wykonuj go po kolejnym poleceniu.
