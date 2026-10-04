# Gammu SMSD Web UI

Panel WWW do bramki SMS opartej na [Gammu SMSD](https://docs.gammu.org/smsd/) (backend SQL, MariaDB) dla Ubuntu Server.
Wszystko z przeglądarki: wysyłka do jednego i wielu odbiorców, rozmowy, odebrane i wysłane z raportami doręczenia,
książka telefoniczna z grupami, szablony, USSD, odrzucone połączenia, czarna lista, konfiguracja Gammu z kopiami
zapasowymi, log i stan modemu.

PHP 8.5 bez frameworka, htmx + Pico.css, bez Composera i npm. Dokumentacja projektu: [docs/](docs/README.md).

## Instalacja (Ubuntu Server 26.04)

```bash
curl -fsSL https://raw.githubusercontent.com/ookris/Gammu-SMSD-Web-UI/main/deploy/install.sh | sudo bash
```

Skrypt instaluje Gammu SMSD, MariaDB, nginx i PHP, tworzy bazy i konta (losowe hasła w `/etc/smsgui/secrets.env`),
pomaga wybrać port modemu i sprawdza PIN (tylko raz), konfiguruje `/etc/gammu-smsdrc`, proces w tle `smsgui-worker`
i nginx, pyta o login i hasło do panelu, uruchamia diagnostykę i opcjonalnie wysyła testowy SMS. Można go uruchomić
ponownie – nie zmienia skonfigurowanego modemu ani konta. Bez pytań: zmienne `SMSGUI_*` opisane na początku
[deploy/install.sh](deploy/install.sh) i w [docs/06](docs/06-plan-wdrozenia.md#63-skrypt-installsh).

Panel jest domyślnie dostępny **tylko z sieci lokalnej**. Do dostępu z zewnątrz zalecany jest VPN (WireGuard, Tailscale);
jeśli bezpośrednio – HTTPS (`certbot --nginx`) i fail2ban (filtr i wyłączony jail instaluje skrypt).

> ⚠ Instalator nie był jeszcze uruchomiony na docelowym Ubuntu z modemem – część wartości zależnych od paczki
> (U1–U10, [docs/03, rozdz. 3.14](docs/03-integracja-gammu-smsd.md#314-do-weryfikacji-na-ubuntu-etap-0)) skrypt wykrywa,
> ale wymaga to sprawdzenia w etapie 6.

## Aktualizacja i odinstalowanie

```bash
cd /opt/smsgui && sudo git pull                 # albo ponownie polecenie instalacyjne
sudo -u www-data php bin/smsgui check           # migracje bazy wykonują się automatycznie
sudo deploy/uninstall.sh [--purge]              # --purge usuwa też dane panelu i bazę smsgui
```

Proces w tle sam się restartuje po zmianie plików. Zapomniane hasło: `sudo -u www-data php /opt/smsgui/bin/smsgui passwd`.

## Narzędzie konsolowe

| Polecenie | Działanie |
|-----------|-----------|
| `bin/smsgui check [--quiet]` | diagnostyka (baza, schemat Gammu, silnik tabel, konfiguracja, usługa, modem); kod ≠ 0 przy błędzie |
| `bin/smsgui worker` / `sync` | proces w tle / jednorazowa synchronizacja |
| `bin/smsgui passwd [login]` | login i hasło konta |
| `bin/smsgui send <numer> <treść> [--wait=N]` | wysyłka testowa |
| `bin/smsgui cleanup --days=N` | usunięcie historii starszej niż N dni |
| `bin/smsgui setup db` / `setup gammu` | kroki instalatora |
| `bin/smsgui hook call --phone=ID [numer]` | zapis połączenia (wywołuje Gammu – `RunOnIncomingCall`) |

## Praca lokalna (Mac, bez modemu)

Wymagane PHP 8.5 i MariaDB z Homebrew (`brew install php mariadb@11.8`). MariaDB działa jako osobna instancja
na porcie 3307 (dane w `~/.local/share/smsgui-mariadb`), więc nie koliduje z MySQL zainstalowanym obok.
Symulator Gammu SMSD zachowuje się jak Gammu 1.42 na bazie `gammu_dev` (wysyłka, ponowienia, raporty doręczenia,
odbiór wieloczęściowy, USSD, połączenia, czarna lista).

```bash
tests/db/mariadb.sh start                      # MariaDB na 127.0.0.1:3307 (stop | status | client [baza])
SIM_DB_PORT=3307 php tests/sim/gammu-sim.php init   # var/dev/, bazy *_dev i *_test, config/config.php
php bin/smsgui setup db && php bin/smsgui passwd admin
php tests/sim/gammu-sim.php run                # „demon” Gammu (osobny terminal)
php bin/smsgui worker                          # proces w tle (osobny terminal)
php -S 127.0.0.1:8080 -t public                # panel: http://127.0.0.1:8080
php tests/run.php && node tests/js/run.mjs     # testy PHP i zgodność licznika SMS w JS
```

Symulator: `receive <numer> <treść> [--parts=N] [--incomplete] [--flash]`, `external <numer> <treść>`, `call <numer>`;
numery kończące się na `000` – nieudana wysyłka, na `999` – raport „niedoręczona”; `*100#` – menu operatora.

## Licencje

Kod panelu – zob. repozytorium. Dołączone biblioteki: [htmx](https://htmx.org) (0BSD), [Pico.css](https://picocss.com) (MIT),
czcionki IBM Plex (OFL). Schemat bazy `deploy/sql/gammu-mysql-17.sql` na podstawie Gammu (GPL-2.0).

**Ikony:** [Solar Icon Set](https://icon-sets.iconify.design/solar/) – autor 480 Design, licencja
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) (pliki w `resources/icons/`, atrybucja także w stopce panelu).
