<?php
// Teksty interfejsu (D10). Wersja angielska (en.php) – w etapie rozszerzeń.
return [
    'app.name' => 'SMS Gateway',

    // Menu
    'nav.compose' => 'Nowa wiadomość',
    'nav.dashboard' => 'Pulpit',
    'nav.threads' => 'Rozmowy',
    'nav.inbox' => 'Odebrane',
    'nav.sent' => 'Wysłane',
    'nav.contacts' => 'Kontakty',
    'nav.groups' => 'Grupy',
    'nav.templates' => 'Szablony',
    'nav.gateway' => 'Bramka',
    'nav.modem' => 'Modem i USSD',
    'nav.calls' => 'Połączenia',
    'nav.blocklist' => 'Zablokowane numery',
    'nav.system' => 'System',
    'nav.config' => 'Konfiguracja Gammu',
    'nav.log' => 'Log Gammu',
    'nav.settings' => 'Ustawienia panelu',
    'nav.logout' => 'Wyloguj',

    // Statusy wiadomości (rozdz. 3.6)
    'status.received' => 'Odebrana',
    'status.scheduled' => 'Zaplanowana',
    'status.queued' => 'W kolejce',
    'status.retrying' => 'Ponawiana',
    'status.sent' => 'Wysłana',
    'status.delivered' => 'Doręczona',
    'status.undelivered' => 'Niedoręczona',
    'status.failed' => 'Błąd',
    'status.cancelled' => 'Anulowana',

    // Stany sesji USSD (inbox.Status, rozdz. 3.10)
    'ussd.status.queued' => 'Wysyłane',
    'ussd.status.sent' => 'Czeka na odpowiedź',
    'ussd.status.timeout' => 'Przekroczony czas',
    'ussd.status.failed' => 'Błąd wysyłki',
    'ussd.session.1' => 'Stan nieznany',
    'ussd.session.2' => 'Zakończona',
    'ussd.session.3' => 'Operator czeka na wybór',
    'ussd.session.4' => 'Zakończona przez sieć',
    'ussd.session.5' => 'Obsłużona przez innego klienta',
    'ussd.session.6' => 'Nieobsługiwana',
    'ussd.session.7' => 'Przekroczony czas sieci',

    // Logowanie
    'auth.bad' => 'Nieprawidłowy login lub hasło.',
    'auth.left' => 'Pozostało prób: {n}, potem blokada na 15 minut.',
    'auth.locked' => 'Zbyt wiele nieudanych prób. Spróbuj ponownie za {min} min.',
    'auth.expired' => 'Sesja wygasła – zaloguj się ponownie.',
    'auth.logged_out' => 'Wylogowano.',
    'auth.csrf' => 'Formularz wygasł – odśwież stronę i spróbuj ponownie.',

    // Hasło
    'password.current_bad' => 'Obecne hasło jest nieprawidłowe.',
    'password.too_short' => 'Nowe hasło musi mieć co najmniej 10 znaków.',
    'password.mismatch' => 'Powtórzone hasło jest inne niż nowe.',
    'password.login_empty' => 'Login nie może być pusty.',
    'password.changed' => 'Hasło zmienione.',

    // Wspólne
    'common.saved' => 'Zapisano.',
    'common.deleted' => 'Usunięto.',
    'common.not_found' => 'Nie znaleziono.',
];
