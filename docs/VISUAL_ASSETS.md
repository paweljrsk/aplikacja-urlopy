# Zasoby wizualne

Stan: 7 listopada 2026 r. Etap 8 nawiązuje do [notus.pl](https://notus.pl/)
i [notus.pl/banki](https://notus.pl/banki/). Układ aplikacji jest dostosowany do
planowania nieobecności, a nie skopiowany z serwisu marketingowego.

## Kolory i typografia

Poniższe kolory oraz rodziny pisma odczytano ze stylów obliczonych działających
stron referencyjnych. Są punktem odniesienia dla interfejsu; stany ostrzeżeń,
kontrasty i oznaczenia wniosków należy dodatkowo oceniać w kontekście aplikacji.

| Zastosowanie na stronach referencyjnych | Wartość |
|---|---|
| Główny przycisk „Sprawdź ratę” | `#9f1e53` |
| Zielony przycisk promocyjny „Sprawdź teraz” | `#158478` |
| Jasne tło powiązanych sekcji | `#eaf6f5` |
| Nagłówki | `#0a0a0a` |
| Rodziny pisma | Host Grotesk, Inter |

Host Grotesk jest przeznaczony do nagłówków, a Inter do tekstu i kontrolek.
Nieobecności oczekujące i zaakceptowane mają także tekstowe oznaczenie statusu;
sam kolor nie przekazuje całej informacji.

## Fonty serwowane lokalnie

Oba fonty pobrano z oficjalnego repozytorium [Google Fonts](https://github.com/google/fonts).
Pełne pliki zmienne TTF przekonwertowano do WOFF2 bez usuwania znaków ani osi.
Nie dodano bibliotek do zależności aplikacji. Narzędzia fontTools/Brotli były użyte
wyłącznie do jednorazowego przygotowania plików; nie są potrzebne przy starcie,
budowaniu ani działaniu aplikacji.

| Rodzina | Lokalny plik publiczny | Styl / zakres |
|---|---|---|
| Host Grotesk | `/fonts/host-grotesk-variable.woff2` | normal, `font-weight: 300 800` |
| Inter | `/fonts/inter-variable.woff2` | normal, `font-weight: 100 900`; oś optyczna `opsz` 14–32 |

Źródła plików:

- [Host Grotesk TTF](https://raw.githubusercontent.com/google/fonts/main/ofl/hostgrotesk/HostGrotesk%5Bwght%5D.ttf),
  [licencja źródłowa](https://raw.githubusercontent.com/google/fonts/main/ofl/hostgrotesk/OFL.txt).
- [Inter TTF](https://raw.githubusercontent.com/google/fonts/main/ofl/inter/Inter%5Bopsz,wght%5D.ttf),
  [licencja źródłowa](https://raw.githubusercontent.com/google/fonts/main/ofl/inter/OFL.txt).

Oba fonty mają licencję **SIL Open Font License 1.1**. Zachowano pełne teksty
licencji i informacje o autorach, odpowiednio w
`frontend/public/fonts/host-grotesk-OFL.txt` oraz `frontend/public/fonts/inter-OFL.txt`.
Żadna z tych kopii licencji nie określa zastrzeżonej nazwy fontu po deklaracji
copyright. Pliki fontów pozostają objęte OFL; nie oznacza to zmiany licencji kodu
aplikacji ani logotypu.

Weryfikacja po konwersji: odczyt WOFF2, zgodność pełnej mapy znaków z TTF,
zachowanie osi zmiennych oraz obecność `ąćęłńóśźżĄĆĘŁŃÓŚŹŻ` — **PASS**.
Host Grotesk: 31 620 B; Inter: 350 460 B.

| Plik | SHA-256 |
|---|---|
| Źródłowy Host Grotesk TTF | `74c77b8e850d58dd3d8b22e39111a73bef3450de9fb57018ef99119f2d484f5d` |
| Host Grotesk WOFF2 | `313798cc85dbb4af89af6712f7e6d69f38ef4d4acf7103373895c984af068e8e` |
| Źródłowy Inter TTF | `29160a80ff49ddcab2c97711247e08b1fab27a484a329ce8b813d820dc559031` |
| Inter WOFF2 | `13b4cddc57045d40b411008e61588c8f81aa0bd72fe884624831a0ff9c4d159a` |

Definicje `@font-face` powinny korzystać z powyższych lokalnych ścieżek,
`font-display: swap`, właściwego zakresu wag i systemowego fontu zapasowego.
Nie używamy zdalnego importu Google Fonts ani pobierania fontów podczas builda.

## Logotyp Notus

Plik: `frontend/public/brand/notus.svg`, publiczny adres `/brand/notus.svg`.
Źródło: [oficjalny zasób Notus](https://ecom-core.notuskredyt.pl/_next/static/media/logo-notus.160aq9vfgibrn.svg).
SHA-256: `ce6280a0a7b263f400f4bd1a4d45cb0b8425661311ec0636d59edd65b43e6722`.

Logotyp służy identyfikacji wewnętrznej aplikacji Notus. Nie przypisujemy mu
licencji fontów ani nie przedstawiamy go jako własnej grafiki. Oryginalny SVG
zawiera osadzony PNG o wymiarach 495 × 104 px i nominalny rozmiar 148 × 31 px;
nie jest to logotyp zbudowany z wektorowych ścieżek. Zachowano oryginalny plik.

Kontrola SVG: brak elementów `script` i `foreignObject`, obsługi zdarzeń oraz
zewnętrznych odwołań. Odwołania prowadzą wyłącznie do lokalnego identyfikatora
i osadzonego `data:image/png;base64` — **PASS**. Kontrola nie wykonuje skryptów SVG.

Zasoby są podawane z własnego serwera aplikacji. Nie przenosimy trackerów,
skryptów marketingowych, formularzy kontaktowych ani treści sprzedażowych Notus.
