# 8. Pomysły i propozycje

Funkcje spoza podstawowego zakresu wraz z decyzją (patrz D5 i D21 w [README](README.md)).
Nakład: S – mały, M – średni, L – duży. Specyfikacje przyjętych funkcji są w
[02-specyfikacja-funkcjonalna.md](02-specyfikacja-funkcjonalna.md).

## 8.1. Przyjęte – wersja 1

| Pomysł | Opis | Nakład | Specyfikacja |
|--------|------|:------:|--------------|
| **Licznik znaków z wyjaśnieniem kodowania** | pokazuje, który znak „przełącza” SMS na Unicode – najczęstsze źródło niespodziewanych kosztów | S | 2.3 |
| **Dławienie wysyłki do wielu odbiorców** | wysyłka maks. N SMS/min zamiast wszystkich naraz. Operatorzy potrafią zablokować kartę SIM za nagłe wysłanie wielu SMS | S | 2.3 |
| **Okno wysyłki** (dawniej „cisza nocna”) | wysyłki do wielu odbiorców tylko w ustalonych godzinach; realizuje je Gammu (`SendAfter`/`SendBefore`) – wiadomości czekają same | S | 2.3 |
| **Priorytet wiadomości** | pojedyncze SMS i odpowiedzi wyprzedzają wysyłki masowe (`outbox.Priority`) | S | 2.3, 3.6 |
| **Personalizacja** | zmienne `{nazwa}`, `{imie}` w treści – każdy odbiorca dostaje swoją wersję | S | 2.3 |
| **Kontrola zdrowia na pulpicie** | wykrywa problemy z bazą, konfiguracją Gammu, modemem i stojącą kolejką | S | 2.2 |
| **Stan modemu** | sygnał, operator, IMEI/IMSI, liczniki – z tabeli `phones`, bez dodatkowej konfiguracji | S | 2.12 |
| **USSD na żądanie** | sprawdzenie salda, numeru karty, pakietów z przeglądarki | M | 2.12 |
| **Połączenia przychodzące** | odrzucanie i lista połączeń (`HangupCalls` + `RunOnIncomingCall`) | M | 2.13 |
| **Czarna lista numerów** | SMS od wskazanych nadawców nie trafiają do panelu (`ExcludeNumbersFile`) | S | 2.14 |

## 8.2. Przyjęte – etap końcowy (rozszerzenia)

| Pomysł | Opis | Nakład | Specyfikacja |
|--------|------|:------:|--------------|
| **Przekazywanie odebranych SMS i połączeń** | na e-mail lub webhook wg reguł (wszystkie / od numeru / zawierające tekst) | M | 2.17.1 |
| **Proste HTTP API** | `POST /api/send` z tokenem – dla Zabbixa, Home Assistanta, skryptów | M | 2.17.2 |
| **Autoodpowiedzi** | „słowo kluczowe → odpowiedź”, odpowiedź poza godzinami, SMS po odrzuconym połączeniu | M | 2.17.3 |
| **Cykliczne sprawdzanie salda** | USSD co N godzin, wykres, alarm przy niskim saldzie | S | 2.17.4 |
| **Powiadomienia administracyjne** | gdy usługa nie działa, modem niedostępny, kolejka stoi lub kończy się saldo | S | 2.17.5 |
| **Statystyki** | SMS dziennie/miesięcznie, odsetek doręczeń, połączenia | S | 2.17.6 |
| **Kilka modemów** | osobne instancje Gammu (PhoneID), wybór modemu przy wysyłce | L | 2.17.7 |
| **Automatyczne czyszczenie historii** | usuwanie wiadomości starszych niż N dni (bez części RODO) | S | 2.17.8 |
| **Wersja angielska interfejsu** | | S | 2.17.9 |

## 8.3. Na przyszłość (po ukończeniu wersji bazowej)

| Pomysł | Opis |
|--------|------|
| Przekazywanie na Telegram i inne komunikatory | kanały przekazywania są projektowane jako wymienne moduły |
| Gammu 1.44+ (schemat 18) | gdy trafi do Ubuntu LTS albo jeśli poprawki z 2026 r. (raporty, duplikaty) okażą się potrzebne: migracja tabel `gammu`, skorzystanie z `SendDays` (dni tygodnia w oknie wysyłki) i grupowania części w `inbox` |
| Ważność wiadomości | `outbox.RelativeValidity` – po jakim czasie sieć przestaje próbować doręczyć SMS (np. kody jednorazowe) |

## 8.4. Odrzucone

| Pomysł | Powód |
|--------|-------|
| PWA i powiadomienia w przeglądarce | zbędne – wystarczy zwykły panel WWW |
| Lista zastrzeżona / obsługa „STOP”, prawo do usunięcia danych (RODO) | aplikacja nie służy do masowej wysyłki do klientów |
| Logowanie dwuskładnikowe (TOTP) | wystarczy zwykłe logowanie; panel domyślnie tylko w sieci lokalnej |
| Wysyłka przez `gammu-smsd-inject` | panel sam dzieli wiadomości i zapisuje je w jednej transakcji ([03, rozdz. 3.3](03-integracja-gammu-smsd.md#33-wysyłka--zapis-do-outbox)) |
| Synchronizacja wyzwalana skryptami `RunOnReceive`/`RunOnSent` | raporty doręczenia nie mają zdarzenia, więc i tak trzeba odpytywać bazę ([01, rozdz. 1.3](01-zalozenia-i-architektura.md#13-zasada-działania)) |
| Zapisywanie połączeń bez ich odrzucania | Gammu 1.42 uruchamia `RunOnIncomingCall` tylko przy `HangupCalls = yes` |

## 8.5. Zasady techniczne (czego unikamy)

- **Framework PHP (Laravel, Symfony) i proces budowania frontendu (npm, webpack)** – przy tej skali dodaje
  więcej zależności i aktualizacji niż pomaga. Wyjątek: małe biblioteki w jednym pliku, dołączone lokalnie,
  jeśli realnie zmniejszają ilość kodu (patrz [01, rozdz. 1.4](01-zalozenia-i-architektura.md#14-stos-technologiczny)).
- **Własna obsługa modemu z PHP** – Gammu SMSD robi to dobrze i stabilnie; panel ma być „cienką” warstwą nad bazą.
- **Zmiany struktury tabel Gammu** (własne kolumny, wyzwalacze) – utrudniłyby aktualizację Gammu; dane panelu są
  w osobnej bazie, a powiązanie to `gammu_id`.
- **Formularz „pierwszego uruchomienia” do tworzenia konta** – okno, w którym każdy w sieci może przejąć panel.
  Konto tworzy instalator.
- **Edycja ścieżek, danych dostępowych i poleceń systemowych z poziomu WWW** – patrz [05-bezpieczenstwo.md](05-bezpieczenstwo.md).
