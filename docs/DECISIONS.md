# Rejestr decyzji i problemów

Stan początkowy: 6 listopada 2026 r.

Decyzje biznesowe poniżej wynikają z uzgodnień użytkownika zapisanych w planie
wersji 1.0. Szczegółowe wymagania są w [REQUIREMENTS.md](REQUIREMENTS.md).
Odpowiedzi do pytań Q1–Q9 nie zostały jeszcze udzielone.

## Uzgodnione decyzje biznesowe

| ID | Decyzja | Konsekwencja |
|---|---|---|
| B01 | Wszyscy członkowie zespołu procesują wnioski w nowej aplikacji | Obsługujemy UoP, B2B i inne formy; formalne rozliczenia UoP pozostają w kadrach |
| B02 | Na start około 10 osób i jeden zespół, z możliwością rozbudowy | Użytkownik ma jeden zespół, model i API uwzględniają wiele zespołów |
| B03 | Lokalny login e-mail/hasło, konta administratora, wymuszona zmiana hasła tymczasowego | Brak samodzielnej rejestracji; M365 poza MVP |
| B04 | Slack poza MVP | Brak zależności procesu decyzji od notyfikacji Slack |
| B05 | Trzy rodzaje: płatne dni wolne, bezpłatne dni wolne, inna nieobecność | Tylko pierwszy obciąża wspólny limit; wszystkie wymagają akceptacji |
| B06 | Administrator zarządza słownikiem rodzajów i dostępnością dla form zatrudnienia | Bez osobnych limitów dla dodatkowych rodzajów; wpływ zmian na historię wymaga Q5 |
| B07 | 8 h dziennie, poniedziałek–piątek, polskie dni wolne | Rozliczenia w pełnych godzinach, lokalne daty Europe/Warsaw |
| B08 | Domyślnie cały dzień, możliwość zmniejszenia liczby godzin | Bez połówek jako osobnego trybu, bez godzin od–do; zakres wielodniowy wymaga Q1 |
| B09 | Administrator i kierownik ustawiają limity na konkretny rok | Osobno limit, wykorzystanie początkowe i zaległość; własny limit kierownika wymaga Q6 |
| B10 | Oczekujący wniosek rezerwuje saldo; brak salda blokuje wniosek obciążający limit | Akceptacja nie potrąca godzin drugi raz, odrzucenie/wycofanie zwalnia rezerwację |
| B11 | Niewykorzystane dni UoP przechodzą i wykorzystujemy najstarsze najpierw | Bez automatycznego wygaszenia; pozostałe formy nie przenoszą dni |
| B12 | Kierownik zatwierdza własne wnioski, zastępca swoich nie zatwierdza | Zastępca stale może obsługiwać cudze wnioski zespołu; administrator ma pełne uprawnienia |
| B13 | Zmiana/anulowanie zaakceptowanego urlopu wymagają decyzji | Do decyzji obowiązuje stary urlop; odrzucenie zmiany zachowuje jego skutki |
| B14 | Nieobecności rozpoczęte/przeszłe obsługują kierownik, zastępca i administrator | Tryb korekty i dzisiejsze wnioski wymagają Q2 |
| B15 | Współpracownik widzi nazwisko, daty, status i godziny, bez cudzego salda i rodzaju | API ogranicza udostępniane pola; własne i uprawnione szczegóły osobno |
| B16 | Kalendarz jest ekranem głównym; stylistyka Notus, komputer i telefon | Responsywna aplikacja webowa; PWA opcjonalnie później |
| B17 | Własny serwer Docker, dostęp VPN; repozytorium może pozostać publiczne | Sekrety, rzeczywiste dane i backupy poza repozytorium |

## Ustalony sposób realizacji

| ID | Zasada | Status |
|---|---|---|
| P01 | Realizacja kolejnych etapów w istniejącym repozytorium i standardzie stacku | Obowiązuje |
| P02 | Przed zależną implementacją wyjaśniamy luki; propozycja nie jest odpowiedzią | Obowiązuje |
| P03 | Schemat przez Doctrine Migrations; reguły i uprawnienia w backendzie | Obowiązuje |
| P04 | Testy i dokumentacja na bieżąco; `[x]` dopiero po implementacji i weryfikacji | Obowiązuje |
| P05 | Decyzje i problemy zapisujemy, a wznowienie zaczyna się od dokumentacji i TASKS | Obowiązuje |

## Decyzje techniczne etapu 1

Do uzupełnienia na podstawie rzeczywistych zmian i wyników audytu. Opis planowanego
rozwiązania nie jest dowodem implementacji. Zmiany technologii i bibliotek należy
uzasadnić, zachowując zakres etapu 1.

## Problemy i ograniczenia

Do uzupełnienia potwierdzonym wynikiem kontroli. Ryzyka z odczytu szkieletu są
w [DEVELOPMENT_PLAN.md](DEVELOPMENT_PLAN.md), w sekcji uwag do bazowego repozytorium.
Nie należy oznaczać ich jako błędów wykonania bez sprawdzenia.

## Format kolejnego wpisu

Każdy wpis zawiera datę, identyfikator, decyzję lub problem, uzasadnienie, zakres
wpływu, wynik weryfikacji i ewentualne pytanie. Odpowiedzi użytkownika należy
powiązać z identyfikatorem Q z OPEN_QUESTIONS i odpowiednią zmianą REQUIREMENTS.
