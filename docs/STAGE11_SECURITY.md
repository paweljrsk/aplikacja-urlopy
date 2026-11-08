# Etap 11 — przegląd bezpieczeństwa

Data kontroli: 8 listopada 2026 r. Punkt wyjścia: kod etapu 10,
`4191f2ca26ebd205e36c4d5c7fcb606cb91bb1a4`. Raport opisuje zakres i faktyczne wyniki;
nie stanowi certyfikacji ani potwierdzenia bezpieczeństwa środowiska produkcyjnego.

## Zakres i pokrycie

| Obszar | Kontrola i dowód w repozytorium |
|---|---|
| Sesje i logowanie | `AuthTest`, `SecurityCoreTest`: rotacja sesji, ogólny błąd logowania, limity prób, 60 min bezczynności/8 h maksimum, hasła tymczasowe, reset, zmiana hasła i cofnięcie sesji. Przejrzano `JsonLoginAuthenticator`, `IdentityProvider`, `SecuritySession`, `ApiSecuritySubscriber` i konfigurację firewall/session. |
| CSRF | Wszystkie niebezpieczne metody API sprawdzają token sesji; osobne testy braku, błędnego i cudzego tokena. Podglądy również podlegają CSRF. Logowanie ma własny `CsrfTokenBadge`. |
| Własność i role | Istniejące `RequestApiTest`, `RequestChangesApiTest`, `BalanceApiTest`, `AdminTest`, `TeamCalendarApiTest`: cudzy zespół, własny wniosek zastępcy, własny limit kierownika, transfer i historyczny zakres. Nowa regresja dodatkowo wysyła fałszywe nagłówki tożsamości i pola właściciela/zespołu/roli w payloadzie. |
| Utrata uprawnień | Rewalidacja konta i przypisania przy odczytach/zapisach, dezaktywacja i reset hasła; istniejące testy HTTP oraz regresje UI. Odświeżenie formularza i spóźnione podglądy są sprawdzane osobno. |
| Walidacja i transakcje | Dotychczasowe testy odrzucają nieznane pola, błędny JSON, nadmierną długość, błędne typy godzin/dat, obcy identyfikator i stare wersje. Testy MySQL sprawdzają konflikty i idempotencję. Nowe próby modyfikacji tożsamości sprawdzają brak nowych wniosków i zmian salda. |
| Odpowiedzi i logi błędów | API ma JSON, `no-store, private` i `nosniff`. Nowy `ApiExceptionSubscriberTest` sprawdza 500 bez treści SQL/hasła/body/trace w odpowiedzi oraz logowanie wyłącznie klasy wyjątku. Osobny przypadek 405 zachowuje `Allow`, ale usuwa treść wewnętrznego wyjątku. |
| XSS i dane klienta | Przegląd `frontend/app` i `frontend/lib` nie znalazł `dangerouslySetInnerHTML`, przypisań `innerHTML`, `eval`, `new Function`, `document.write` ani magazynowania danych w local/sessionStorage. Rozszerzony `admin-ui-check.cjs` wprowadza syntetyczne HTML z event handlerem do historii i sprawdza widoczny tekst, brak elementów `img/script` i brak wykonania. |
| Sekrety | Skan bieżących plików śledzonych przez Git i historycznych blobów dla kluczy prywatnych, tokenów GitHub/AWS/Slack/OpenAI. Ręcznie przejrzano śledzone pliki konfiguracji środowisk: znalezione wartości są jawnymi przykładami dev/test, szablon produkcji ma puste sekrety. |

## Wykonane kontrole lokalne

W środowisku pomocniczym nie były dostępne PHP/Composer ani Docker. Lokalny
Chromium (`/usr/bin/chromium`) nie uruchomił się z powodu błędu `socket(): EPERM`.
Testów PHP i przeglądarkowych nie oznaczamy jako wykonanych lokalnie; uruchomił je CI.

| Polecenie/kontrola | Wynik |
|---|---|
| `npm audit --json --package-lock-only` | Wykonano; 8 wpisów moderate, 0 high, 0 critical. Wszystkie dotyczą jednego zgłoszenia i jego zależnych pakietów developerskich, opisanego niżej. |
| `npm audit --omit=dev --package-lock-only --json` | Wykonano, kod wyjścia 0; 0 zgłoszeń dla zależności produkcyjnych. |
| `python .github/scripts/security-source-check.py --history` | PASS na punkcie wyjścia: 257 śledzonych plików, 519 historycznych blobów, 5 wzorców sekretów oraz wzorce niebezpiecznych operacji HTML/JS w kodzie przeglądarki. Nie wyświetlano dopasowanych wartości. |
| `node --check .github/scripts/admin-ui-check.cjs` | PASS. To sprawdzenie składni, nie wykonanie testu XSS w Chrome. |
| `git diff --check` | PASS dla zmian bezpieczeństwa. |
| `composer audit --locked --format=json` | Nie wykonywano lokalnie; wynik kontenera CI poniżej. |
| Nowe testy PHP oraz test XSS w Chrome | Nie wykonywano lokalnie; wykonano je w CI opisanym poniżej. |

## Wyniki pełnego CI

[CI #39 — SUCCESS](https://github.com/paweljrsk/aplikacja-urlopy/actions/runs/42487633929)
dla kodu `f8bc8b37a2e645d817524bedaffa9a8881ae8221` zakończył się powodzeniem.
Wyniki zależności odczytano z rzeczywistych artefaktów `stage11-check-reports`,
plików `urlopy-security/composer.json`, `npm-all.json` i `npm-production.json`.

| Kontrola | Wynik |
|---|---|
| Pełna regresja backendu na MySQL | **445 testów / 21 607 asercji — PASS**, w tym nowe testy podszywania się i redakcji wyjątków. |
| Administracja w Chrome | PASS, w tym syntetyczny zapisany payload XSS w historii: widoczny tekst, brak elementów HTML i brak wykonania event handlera. |
| Skan źródeł i pełnej historii Git | PASS: **270 śledzonych plików / 552 historyczne bloby**, 5 wzorców sekretów i wzorce operacji HTML/JS. Checkout zawierał pełną historię. Nadal obowiązują ograniczenia detektora opisane niżej. |
| `composer audit --locked --format=json` | PASS; artefakt zawiera `advisories: []` oraz `abandoned: []`. Brak zgłoszonych podatności i porzuconych pakietów. |
| `npm audit --omit=dev --audit-level=low --json` | PASS; 0 info/low/moderate/high/critical dla zależności produkcyjnych. |
| `npm audit --audit-level=high --json` | Próg high/critical zaliczony, ale pełny audit **nie jest czysty**: 8 wpisów moderate, 0 high/critical. Osiem wpisów wskazuje ten sam adres GHSA opisany w S11-SEC-01. |

Raport Composera i raport npm nie zawierają błędu odczytu auditu. Bramka CI
zbiera oba raporty npm, a niepowodzenie auditu kończy cały workflow błędem przez
końcową kontrolę obowiązkową; `continue-on-error` służy wyłącznie zebraniu
niezależnych wyników. Wynik SUCCESS nie zamyka ustalenia S11-SEC-01.

Skan źródeł jest celowo ograniczony i deterministyczny. Nie wykrywa dowolnych haseł,
tokenów nieznanych dostawców ani sekretów w plikach ignorowanych przez Git. Nie
kontaktuje się z dostawcami w celu sprawdzania ważności poświadczeń. Historia
wymaga pełnego checkoutu (`fetch-depth: 0`); płytki checkout kończy się błędem.
Przegląd nie obejmował firmowego serwera, VPN ani faktycznych sekretów produkcji.

## Otwarte ustalenie S11-SEC-01 — zależność developerska minimatch

`@typescript-eslint/typescript-estree` zainstalowane przez `eslint-config-next`
używa zagnieżdżonego `minimatch 10.2.6`. Audit zgłasza
[GHSA-g3hv-75wg-m249 / CVE-2026-118764](https://github.com/advisories/GHSA-g3hv-75wg-m249):
moderate, nadmierne zużycie CPU/pamięci przy przetwarzaniu wzorca glob kontrolowanego
przez atakującego, przy `optimizationLevel >= 2`. Wersje objęte zgłoszeniem:
`>=7.0.0 <11.0.1`; poprawiona wersja: `11.0.1`. Źródło zweryfikowano 8 listopada 2026 r.

Osiem wpisów z npm to jeden problem wraz z pakietami zależnymi, nie osiem osobnych
luk aplikacji. W lockfile jest to ścieżka developerskiego ESLint/TypeScript.
Przegląd nie znalazł przekazywania danych z formularzy HTTP do tego parsera glob;
wniosek o braku ekspozycji tej ścieżki przez aplikację wynika z zakresu zależności
i przejrzanego kodu, nie z samej oceny npm. Produkcyjny audit nie zgłasza problemów.

Nie wykonano `npm audit fix --force`: proponowany downgrade `eslint-config-next`
z 16.4.5 do 15.5.29 rozjechałby narzędzia z wersją Next.js aplikacji. Nie wymuszono
również obcej wersji major minimatch przez override bez sprawdzenia kompatybilności.
Do zamknięcia pozostaje zgodna aktualizacja łańcucha narzędzi i ponowny lint/build/audit.
Do tego czasu wzorce projektu i konfigurację narzędzi należy traktować jako kod
podlegający przeglądowi. To jawne ograniczenie narzędzi developerskich, nie zielony
wynik pełnego auditu zależności.

CI zachowuje pełny raport npm (również moderate), blokuje dowolne wykryte problemy
zależności produkcyjnych oraz high/critical w pełnym lockfile i wykonuje audyt
Composera. Ten próg nie usuwa ani nie wycisza powyższego zgłoszenia.
