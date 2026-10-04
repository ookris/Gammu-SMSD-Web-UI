# 8. Pomysły i propozycje

Funkcje spoza podstawowego zakresu wraz z decyzją (patrz D5 w [README](README.md)).
Nakład: S – mały, M – średni, L – duży. Specyfikacje przyjętych funkcji są w
[02-specyfikacja-funkcjonalna.md](02-specyfikacja-funkcjonalna.md).

## 8.1. Przyjęte – wersja 1

| Pomysł | Opis | Nakład | Specyfikacja |
|--------|------|:------:|--------------|
| **Licznik znaków z wyjaśnieniem kodowania** | pokazuje, który znak „przełącza” SMS na Unicode – najczęstsze źródło niespodziewanych kosztów | S | 2.3 |
| **Dławienie wysyłki do wielu odbiorców** | wysyłka maks. N SMS/min zamiast wszystkich naraz. Operatorzy potrafią zablokować kartę SIM za nagłe wysłanie wielu SMS (podejrzenie spamu) – także przy wysyłce „wewnętrznej”, nie tylko marketingowej | S | 2.3 |
| **Cisza nocna** | ostrzeżenie przy wysyłce do wielu odbiorców między 21:00 a 8:00 (godziny konfigurowalne, można wyłączyć) | S | 2.3 |
| **Personalizacja** | zmienne `{nazwa}`, `{imie}` w treści – każdy odbiorca dostaje swoją wersję | S | 2.3 |
| **Kontrola zdrowia na pulpicie** | wykrywa problemy z uprawnieniami, konfiguracją i stojącą kolejką | S | 2.2 |

## 8.2. Przyjęte – etap końcowy (rozszerzenia)

| Pomysł | Opis | Nakład | Specyfikacja |
|--------|------|:------:|--------------|
| **Przekazywanie odebranych SMS** | na e-mail lub webhook wg reguł (wszystkie / od numeru / zawierające tekst) | M | 2.14.1 |
| **Proste HTTP API** | `POST /api/send` z tokenem – dla Zabbixa, Home Assistanta, skryptów | M | 2.14.2 |
| **Autoodpowiedzi** | „słowo kluczowe → odpowiedź”, odpowiedź poza godzinami | M | 2.14.3 |
| **Stan modemu i karty SIM** | sygnał, operator, saldo przez USSD, alarm przy niskim saldzie | M | 2.14.4 |
| **Powiadomienia administracyjne** | gdy usługa nie działa, kolejka stoi lub kończy się saldo | S | 2.14.5 |
| **Statystyki** | SMS dziennie/miesięcznie, odsetek doręczeń | S | 2.14.6 |
| **Wybór modemu przy wysyłce** | przy kilku kartach SIM | M | 2.14.7 |
| **Automatyczne czyszczenie historii** | usuwanie wiadomości starszych niż N dni (bez części RODO) | S | 2.14.8 |
| **Wersja angielska interfejsu** | | S | 2.14.9 |

## 8.3. Na przyszłość (po ukończeniu wersji bazowej)

| Pomysł | Opis |
|--------|------|
| Przekazywanie na Telegram i inne komunikatory | kanały przekazywania są projektowane jako wymienne moduły |

## 8.4. Odrzucone

| Pomysł | Powód |
|--------|-------|
| PWA i powiadomienia w przeglądarce | zbędne – wystarczy zwykły panel WWW |
| Lista zastrzeżona / obsługa „STOP”, prawo do usunięcia danych (RODO) | aplikacja nie służy do masowej wysyłki do klientów |
| Logowanie dwuskładnikowe (TOTP) | wystarczy zwykłe logowanie; panel domyślnie tylko w sieci lokalnej |

## 8.5. Zasady techniczne (czego unikamy)

- **Framework PHP (Laravel, Symfony) i proces budowania frontendu (npm, webpack)** – przy tej skali dodaje
  więcej zależności i aktualizacji niż pomaga. Wyjątek: małe biblioteki w jednym pliku, dołączone lokalnie,
  jeśli realnie zmniejszają ilość kodu (patrz [01, rozdz. 1.4](01-zalozenia-i-architektura.md#14-stos-technologiczny)).
- **Własny demon zamiast smsd / obsługa modemu z PHP** – smstools3 robi to dobrze i stabilnie; panel ma być
  „cienką” warstwą nad plikami.
- **Formularz „pierwszego uruchomienia” do tworzenia konta** – okno, w którym każdy w sieci może przejąć panel.
  Konto tworzy instalator.
- **Edycja ścieżek i poleceń systemowych z poziomu WWW** – patrz [05-bezpieczenstwo.md](05-bezpieczenstwo.md).
