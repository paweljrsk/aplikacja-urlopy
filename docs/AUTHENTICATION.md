# Logowanie i dostęp — etap 3

Dokument opisuje zakres i decyzje techniczne etapu 3. Wyniki rzeczywistych kontroli
są w [TEST_PLAN.md](TEST_PLAN.md); obecność opisu nie oznacza zaliczenia testów.
Podstawą są [wymagania](REQUIREMENTS.md), prompt 3 i decyzje DT23–DT30 w
[DECISIONS.md](DECISIONS.md).

## Zakres

Logowanie jest lokalne, przez e-mail i hasło. Nie ma samodzielnej rejestracji,
wysyłki e-maili, M365 ani Slacka. Dostępne są podstawowe ekrany logowania, wymuszonej
zmiany hasła i chronionej aplikacji; pełne widoki urlopowe należą do następnych etapów.

Konta zakłada administrator. Pierwszy administrator powstaje przez CLI, a kolejne
operacje zarządzania kartotekami są zakresem etapu 4. Model konta, lokalne
uwierzytelnianie i autoryzacja są oddzielone: przyszłe M365 może dostarczyć tożsamość
powiązaną z istniejącym `User.id`. Nie ma jeszcze powiązania kont z tenantem ani SSO.

## Pierwszy administrator

Po uruchomieniu usług i wykonaniu migracji, w zaufanym terminalu serwera:

```bash
docker compose exec php php bin/console app:admin:bootstrap
```

Komenda wymaga trybu interaktywnego. Pyta o e-mail i dane nowego administratora,
generuje losowe hasło tymczasowe oraz pokazuje je po udanym zapisie. Hasło nie jest
argumentem polecenia, wpisem `.env` ani stałą w repozytorium. Należy przekazać je
użytkownikowi uzgodnionym kanałem poza logami, zgłoszeniami i repozytorium.

Jeśli podany e-mail należy już do aktywnego administratora bez hasła, np. konta
demo, komenda prosi o potwierdzenie nadania pierwszego hasła i zachowuje dane konta.
Nie podnosi uprawnień istniejącego zwykłego pracownika. Istniejący administrator
z hasłem albo zapisany marker bootstrapu blokuje ponowną inicjalizację. Unikalny
klucz zdarzenia chroni również przed równoczesnymi próbami utworzenia pierwszego
administratora. Bootstrap nie jest ogólną komendą resetowania haseł.

Konta z zestawu demo początkowo nie mają haseł. Po inicjalizacji administratora
można nadać pozostałym aktywnym kontom hasła tymczasowe przez administracyjny reset;
nie istnieje wspólne hasło demonstracyjne.

Po utracie hasła administratora lub wygaśnięciu jego hasła tymczasowego dostęp
można odzyskać wyłącznie z zaufanej konsoli serwera:

```bash
docker compose exec php php bin/console app:admin:recover
```

Komenda pyta o e-mail i jawne potwierdzenie. Dotyczy tylko istniejącego, aktywnego
administratora z wcześniej ustawionym hasłem: nie tworzy, nie aktywuje i nie awansuje
konta. Generuje nowe hasło tymczasowe na 24 h, unieważnia stare sesje i zapisuje
zdarzenie `security.admin_recovered` z systemowym autorem, bez hasła w audycie.

## Hasła i reset

- Nowe hasło: od 15 do 128 znaków UTF-8, ze spacjami, bez narzucania znaków specjalnych.
- Hasło tymczasowe: losowe, ważne 24 godziny; dostęp do funkcji aplikacji wymaga zmiany.
- Zmiana wymaga obecnego hasła; nowe musi się od niego różnić.
- Po zmianie trzeba zalogować się ponownie. Wszystkie wcześniejsze sesje tracą dostęp.
- Reset innego aktywnego konta wymaga administratora, tokenu CSRF i jego obecnego hasła.
  Nowe hasło tymczasowe jest zwracane tylko w odpowiedzi na udany reset, z datą wygaśnięcia.
- Administrator własne hasło zmienia zwykłym formularzem; endpoint resetu własnego konta
  odmawia operacji. Nieaktywnego konta nie można przygotować do logowania przez reset.

Serwis zmiany danych uwierzytelniających zapisuje konto i audyt w jednej transakcji,
blokując odczytywane konta do zapisu w stałej kolejności. Audyt zawiera rodzaj
operacji, autora, pracownika i numer wersji danych uwierzytelniających, bez haseł
i ich skrótów. Kontroler nie zwraca encji ani całego grafu Doctrine.
Skróty tworzy hasher Symfony z konfiguracją `auto`; obniżony koszt w środowisku
`test` służy wyłącznie testom i nie zmienia konfiguracji użytkowej.

## Sesja i ochrona żądań

Frontend i API działają pod wspólnym adresem przez Nginx. Identyfikator sesji jest
w cookie `HttpOnly`, `SameSite=Lax`; na produkcji wymagane jest `Secure` i HTTPS.
Lokalny development na `http://localhost` działa bez produkcyjnego TLS. Tokenów
sesji i haseł nie zapisujemy w localStorage. CSRF jest przechowywany po stronie sesji;
frontend pobiera aktualny token i wysyła go w `X-CSRF-Token` dla operacji zmieniających
stan, także logowania i wylogowania.

Sesja ma limit **60 minut bezczynności** i **8 godzin od logowania**. Provider
odświeża konto z bazy przy obsłudze sesji. Porównanie tożsamości uwzględnia aktywność,
uprawnienie administratora, `credentialVersion`, wersję przypisań oraz aktualne
przypisanie zespołu i roli według daty w `Europe/Warsaw`. Dezaktywacja, zmiana hasła
lub zmiana tych uprawnień odbiera dostęp przy następnym żądaniu, również ze starego
cookie. Sesja nie zawiera skrótu hasła ani serializowanej encji Doctrine.

Limity logowania wynoszą 5 prób na minutę dla pary konto+IP i 25 na minutę dla IP.
Potwierdzanie obecnego hasła przy zmianie lub resecie ma wspólny limit 5 prób na
minutę na użytkownika; zmiana identyfikatora resetowanego konta nie omija tego limitu.
Brak sesji daje 401, odmowa uprawnień/CSRF 403, przekroczenie limitu 429.

## Kontrakt API

| Metoda i ścieżka | Dostęp / przeznaczenie |
|---|---|
| `GET /api/auth/csrf` | Token CSRF bieżącej sesji, także przed logowaniem. |
| `POST /api/auth/login` | JSON `email` i `password`, z tokenem CSRF. |
| `GET /api/auth/me` | Własna tożsamość i informacja o wymaganej zmianie hasła. |
| `POST /api/auth/change-password` | JSON `currentPassword` i `newPassword`; dozwolone podczas wymuszonej zmiany. |
| `POST /api/auth/logout` | Kończy sesję bieżącej przeglądarki. |
| `GET /api/users/{id}/profile` | Bieżący profil według własności, roli i zespołu. |
| `GET /api/admin/users` | Lista profili tylko dla administratora. |
| `POST /api/admin/users/{id}/reset-password` | JSON `adminPassword`; administrator resetuje inne aktywne konto. |

Po zalogowaniu hasłem tymczasowym dozwolone są tylko własna tożsamość, token CSRF,
zmiana hasła i logout. Pozostałe operacje otrzymują `PASSWORD_CHANGE_REQUIRED` (403).
Odpowiedzi API mają `Cache-Control: no-store, private`; frontend nie zapisuje hasła
z resetu w localStorage ani nie wysyła go do historii operacji.

## Bieżące uprawnienia i granica historii

`CurrentAssignmentResolver` wybiera przypisanie obejmujące dzisiejszą datę według
przedziału `[valid_from, valid_until)`. `AccessPolicy` sprawdza aktywność aktora,
własność i zespół obiektu po stronie backendu. Pracownik czyta własny profil;
kierownik i zastępca również profile swojego bieżącego zespołu; administrator
wszystkie. Brak bieżącego przypisania nie przyznaje uprawnień przełożonego.

Polityka decyzji pozwala kierownikowi obsługiwać własny wniosek, a zastępcy tego
zabrania; administrator zachowuje pełne uprawnienia. Jest to warunek autoryzacji,
nie implementacja procesu składania/akceptacji ani zwolnienie z walidacji godzin,
salda, stanu i kalendarza. Operacje urlopowe powstają w kolejnych etapach.

API etapu 3 nie udostępnia historii nieobecności po przeniesieniu pracownika.
Nie ustala, czy dawna historia należy do zakresu dawnego czy nowego przełożonego,
ani kto przejmuje otwarte/przyszłe wnioski. Pozostała część Q5 musi zostać
rozstrzygnięta przed zależnymi operacjami etapu 4. Bieżąca polityka profilu nie
może zostać bez dodatkowej decyzji użyta jako filtr historycznego kalendarza.

## Dane i zależności

Migracja `Version20261106233000` dodaje do `app_user` licznik `credential_version`
oraz `temporary_password_expires_at` w UTC i ich ograniczenia. Zachowuje konta
i historię utworzone w etapie 2. MySQL DDL jest nietransakcyjne; `down()` celowo
odmawia usunięcia danych uwierzytelniania. Naprawę schematu należy przygotować
kontrolowaną migracją do przodu, bez resetu bazy.

Bezpośrednio dodano `symfony/security-bundle`, `symfony/security-csrf`,
`symfony/rate-limiter` i `symfony/lock` z linii 7.4. Lockfile dodaje łącznie 10
pakietów MIT: powyższe oraz `security-core`, `security-http`, `password-hasher`,
`property-access`, `property-info`, `type-info`. `security-csrf` i `password-hasher`
mają wersję 7.4.8; pozostałe 7.4.23. Nie zmieniono wersji ani nie usunięto istniejących
pakietów. Składniki dostarczają mechanizmy sesji, CSRF, hashy, throttlingu i blokad;
nie zastępują polityk dostępu aplikacji.
