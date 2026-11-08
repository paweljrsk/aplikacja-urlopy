import type { CurrentUser } from "./auth-api";

export type GuideKind = "employee" | "manager" | "deputy" | "administrator";
export type GuideStep = { path: string; target: string; title: string; body: string; tip?: string };
export type GuideOption = { kind: GuideKind; label: string; description: string; duration: string };

export function guideOptions(user: CurrentUser): GuideOption[] {
  const options: GuideOption[] = [{ kind: "employee", label: "Pracownik", description: "Zaplanuj urlop, złóż wniosek i sprawdź saldo.", duration: "6 kroków · około 2 min" }];
  if (user.isAdministrator || user.role === "manager") options.push({ kind: "manager", label: "Kierownik", description: "Decyzje, korekty i salda Twojego zespołu.", duration: "5 kroków · około 2 min" });
  if (!user.isAdministrator && user.role === "deputy") options.push({ kind: "deputy", label: "Zastępca", description: "Obsługa cudzych wniosków i podgląd sald.", duration: "5 kroków · około 2 min" });
  if (user.isAdministrator) options.push({ kind: "administrator", label: "Administrator", description: "Konta, uprawnienia, limity i dni wolne.", duration: "6 kroków · około 2 min" });
  return options;
}

export function guideSteps(kind: GuideKind): GuideStep[] {
  if (kind === "employee") return [
    { path: "/", target: "team-calendar", title: "Zacznij od kalendarza", body: "Tutaj zobaczysz, kto i kiedy jest nieobecny. Przełączaj tydzień lub dwa tygodnie i używaj filtrów, aby znaleźć interesujący Cię termin.", tip: "Status odróżnia zatwierdzony urlop od wniosku, który czeka na decyzję." },
    { path: "/", target: "new-request", title: "Nowy wniosek jest pod ręką", body: "Przycisk „Złóż wniosek” otwiera krótki formularz. Wybierz rodzaj nieobecności, daty albo część dnia. Licznik od razu pominie weekendy, święta i firmowe dni wolne.", tip: "Formularz pokaże wpływ na saldo przed zapisem. W tym przewodniku niczego nie wysyłamy." },
    { path: "/", target: "upcoming-absences", title: "Spójrz trochę dalej", body: "Pod kalendarzem czeka lista nadchodzących nieobecności na około dwa miesiące. Obowiązujący urlop i proponowana zmiana pozostają czytelnie rozróżnione." },
    { path: "/requests", target: "my-requests", title: "Śledź własne wnioski", body: "Otwórz szczegóły, aby sprawdzić status i historię. Oczekujący wniosek możesz zmienić lub wycofać, jeśli jego termin na to pozwala.", tip: "Zmiana lub anulowanie zatwierdzonego urlopu wymaga kolejnej decyzji. Do niej obowiązuje dotychczasowy urlop." },
    { path: "/balances", target: "own-balance", title: "Twoje saldo, bez administracji", body: "Zobacz dostępne godziny, wykorzystanie, zaplanowane urlopy i rezerwacje. Jeden dzień to 8 godzin. Rok salda możesz przełączyć bez zmieniania danych.", tip: "Wniosek oczekujący już rezerwuje godziny. Akceptacja nie odejmuje ich drugi raz." },
    { path: "/", target: "employee-navigation", title: "Wszystko do codziennej pracy", body: "Kalendarz, własne wnioski i własne saldo są zawsze w głównej nawigacji. Do tego przewodnika wrócisz przyciskiem „Przewodnik”.", tip: "Gotowe. Możesz teraz zaplanować swój urlop." },
  ];
  if (kind === "administrator") return [
    { path: "/management", target: "management-overview", title: "Jedno miejsce do zarządzania", body: "Moduł zarządzania zbiera obsługę wniosków, salda, administrację i firmowe dni wolne. Twoje własne sprawy pozostają w części pracowniczej." },
    { path: "/management/admin", target: "admin-navigation", title: "Osoby, zespoły i role", body: "W administracji tworzysz konta, zarządzasz zespołami i przypisujesz role. Zmiana zespołu lub formy zatrudnienia ma datę obowiązywania i zachowuje historię.", tip: "Hasła tymczasowe i reset hasła obsługujesz przy wybranym użytkowniku." },
    { path: "/management/admin", target: "admin-navigation", title: "Słowniki i ślad zmian", body: "Karty administracji obejmują formy zatrudnienia, rodzaje nieobecności i historię. Sprawdzisz w niej autora, czas i zmienione wartości.", tip: "Zmiana zasad rodzaju nieobecności nie przelicza automatycznie wcześniej złożonych wniosków." },
    { path: "/management/balances", target: "team-balances", title: "Limity i dane początkowe", body: "Wybierz osobę w zestawieniu sald. W szczegółach ustawisz limit roczny, dane początkowe i korekty. Podgląd pozwala sprawdzić skutki przed potwierdzeniem.", tip: "Korekty wymagają uzasadnienia. Niedobór jest jawny i nie anuluje wcześniej zatwierdzonych urlopów." },
    { path: "/management/calendar", target: "company-calendar", title: "Firmowe dni wolne", body: "Święta ustawowe są już w kalendarzu. Tutaj dodajesz przyszłe dni firmowe lub zmieniasz ich ustawienia. Najpierw zobaczysz wpływ na istniejące wnioski.", tip: "Zapis wymaga świadomego potwierdzenia. Przewodnik nie zmienia kalendarza ani sald." },
    { path: "/management", target: "management-navigation", title: "Zarządzanie w swoim miejscu", body: "Boczna nawigacja prowadzi do wszystkich narzędzi administratora. Przycisk powrotu do aplikacji przywraca kalendarz i Twoje własne wnioski.", tip: "Do przewodników możesz wrócić w dowolnym momencie." },
  ];
  const deputy = kind === "deputy";
  return [
    { path: "/management", target: "management-overview", title: deputy ? "Twoje narzędzia zastępcy" : "Twoje narzędzia kierownika", body: "W tym module obsługujesz zespół. Wnioski i salda są oddzielone od Twojego kalendarza, własnych wniosków i własnego salda." },
    { path: "/management/requests", target: "managed-requests", title: "Decyzje w jednej kolejce", body: "Lista pokazuje nowe wnioski oraz propozycje zmian i anulowań. Otwórz szczegóły, sprawdź wpływ decyzji i dopiero ją potwierdź. Odrzucenie wymaga uzasadnienia.", tip: deputy ? "Jako zastępca obsługujesz cudze wnioski. Własnego nie możesz zaakceptować ani skorygować." : "Możesz zaakceptować własny wniosek, ale wymaga to osobnej, świadomej decyzji." },
    { path: "/management/requests", target: "managed-requests", title: "Zmiany, zwroty i historia", body: "Do czasu decyzji o zmianie obowiązuje wcześniejszy urlop. Zmniejszenie wymiaru i anulowanie zwracają godziny dopiero po akceptacji.", tip: "Przeszłe nieobecności korygujesz z uzasadnieniem, po podglądzie i potwierdzeniu. Historia zachowuje autora oraz decyzję." },
    { path: "/management/balances", target: "team-balances", title: deputy ? "Salda zespołu do podglądu" : "Salda i limity zespołu", body: "Zestawienie pokazuje dostępne godziny, limity, wykorzystanie i rezerwacje. Wybierz osobę, aby zobaczyć szczegóły i historię rozliczeń.", tip: deputy ? "Jako zastępca odczytujesz salda. Limity i dane początkowe zmienia kierownik lub administrator." : "Limity innych osób zmieniasz po podglądzie i potwierdzeniu. Twój własny limit zmienia administrator." },
    { path: "/management", target: "management-navigation", title: "Zespół i własne sprawy", body: "Nawigacja tego modułu prowadzi do wniosków i sald dostępnych w Twoim zakresie. Wróć do aplikacji, aby przejść do własnych spraw.", tip: "Przycisk „Przewodnik” pozwoli Ci powtórzyć te kroki lub zobaczyć przewodnik pracownika." },
  ];
}
