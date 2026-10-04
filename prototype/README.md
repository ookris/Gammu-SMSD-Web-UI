# Prototyp interfejsu (etap P)

Statyczne strony HTML w docelowej technologii panelu – wzór, z którego w etapach 1–5 powstają widoki
`views/*.php` (decyzja D23, [docs/09, rozdz. 9.2a](../docs/09-plan-implementacji.md)). Wygląd odpowiada
zaakceptowanym makietom.

## Uruchomienie

```bash
php -S 127.0.0.1:8081 -t prototype prototype/router.php
```

Panel: http://127.0.0.1:8081 – serwer dodaje te same nagłówki co panel (CSP z rozdz. 5.4), więc błędy
CSP widać już tutaj. Pliki można też otworzyć bezpośrednio z dysku (`prototype/index.html`).

## Zasady

- Ten sam HTML i CSS, który trafi do aplikacji: Pico.css 2.1.1 + `assets/app.css`, bez stylów i skryptów inline
  (także bez `style="…"`, `onclick`) – wszystko przez klasy i atrybuty `data-*`.
- Kolory tylko jako zmienne `--c-*` w `app.css`; jasny i ciemny motyw wg ustawienia systemu.
- Ikony Solar wstawiane inline (`<svg class="icon">`); w panelu zrobi to funkcja `icon('nazwa')`. Pliki SVG
  i licencja w `assets/icons/` (CC BY 4.0, atrybucja w stopce menu).
- Dane przykładowe wpisane w HTML. Stany dynamiczne (htmx) jako osobne warianty strony.

## Strony

| Plik | Ekran (rozdz. 2) | Uwagi |
|------|------------------|-------|
| `index.html` | Pulpit (2.2) | |
| `login.html` | Logowanie (2.1) | z komunikatem o błędnym haśle |
| `compose.html` | Nowa wiadomość (2.3) | działający licznik SMS, okno potwierdzenia |
| `compose-errors.html` | Nowa wiadomość – walidacja | błędne numery, czarna lista, okno wysyłki; `#confirm-send` otwiera okno |
| `threads.html` | Rozmowy (2.4) | lista + rozmowa, wpis o połączeniu |
| `inbox.html` | Odebrane (2.5) | zaznaczenie wierszy pokazuje pasek akcji zbiorczych |
| `sent.html`, `batch.html` | Wysłane i raport wysyłki (2.6, 2.3) | |
| `contacts.html`, `contact.html`, `groups.html` | Kontakty, kontakt, grupy (2.7, 2.8) | |
| `templates.html` | Szablony (2.9) | |
| `config.html`, `config-editor.html`, `config-backups.html`, `config-service.html` | Konfiguracja Gammu (2.10) | zakładki = osobne adresy; `#confirm-save` otwiera okno zapisu z różnicami |
| `log.html` | Log Gammu (2.11) | |
| `modem.html`, `modem-waiting.html`, `modem-menu.html` | Modem i USSD (2.12) | warianty: odpowiedź, oczekiwanie, menu operatora |
| `calls.html` | Połączenia (2.13) | |
| `blocklist.html` | Zablokowane numery (2.14) | |
| `settings.html`, `password.html` | Ustawienia panelu, zmiana hasła (2.15) | |
| `states.html`, `error-db.html`, `404.html` | Stany wspólne i strony błędów | |
| `_layout.html` | Szkielet strony | punkt wyjścia dla `views/layout.php` |

## Pliki

| Plik | Zawartość |
|------|-----------|
| `assets/app.css` | style panelu (tokeny kolorów, układ, komponenty) |
| `assets/app.js` | menu na telefonie, okna `<dialog>`, zaznaczanie wierszy, wstawianie zmiennych, szybkie kody USSD |
| `assets/sms-text.js` | szkic licznika SMS (GSM-7 / UCS-2, części, transliteracja) – podstawa zadania 2.2 |
| `assets/vendor/pico/` | Pico.css 2.1.1 (MIT) |
| `assets/fonts/` | IBM Plex Sans (zmienna) i IBM Plex Mono 400, łacina + łacina rozszerzona (OFL) |
| `assets/icons/` | ikony Solar *linear* (CC BY 4.0) |
| `router.php` | router wbudowanego serwera PHP z nagłówkami bezpieczeństwa |
