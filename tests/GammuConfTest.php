<?php
const CONF_SAMPLE = "# Konfiguracja\n[gammu]\ndevice = /dev/ttyUSB0\nConnection = at\n\n[smsd]\nservice = sql\n; komentarz średnikiem\npassword = tajne#hasło\nPhoneID = GSM1\nlogfile = /var/log/smsd.log\n\n# koniec\n";

test('odczyt: sekcje, klucze bez wielkości liter, # w wartości', function () {
    $c = GammuConf::parse(CONF_SAMPLE);
    assert_same(['gammu', 'smsd'], $c->sections());
    assert_same('at', $c->get('GAMMU', 'connection'));
    assert_same('GSM1', $c->get('smsd', 'phoneid'));
    assert_same('tajne#hasło', $c->get('smsd', 'password'));
    assert_same(null, $c->get('smsd', 'pin'));
});

test('wczytaj i zapisz = plik bez zmian', function () {
    assert_same(CONF_SAMPLE, GammuConf::parse(CONF_SAMPLE)->text());
    $noEol = "[smsd]\nservice = sql";
    assert_same($noEol, GammuConf::parse($noEol)->text());
});

test('zmiana parametru dotyka tylko jego linii (zachowuje pisownię klucza)', function () {
    $c = GammuConf::parse(CONF_SAMPLE);
    $c->set('smsd', 'phoneid', 'GSM2');
    assert_same(str_replace('PhoneID = GSM1', 'PhoneID = GSM2', CONF_SAMPLE), $c->text());
});

test('dodanie parametru za ostatnim w sekcji', function () {
    $c = GammuConf::parse(CONF_SAMPLE);
    $c->set('smsd', 'deliveryreportdelay', '172800');
    assert_same(str_replace("logfile = /var/log/smsd.log\n", "logfile = /var/log/smsd.log\ndeliveryreportdelay = 172800\n", CONF_SAMPLE), $c->text());
});

test('usunięcie parametru pustą wartością', function () {
    $c = GammuConf::parse(CONF_SAMPLE);
    $c->set('gammu', 'connection', '');
    assert_same(str_replace("Connection = at\n", '', CONF_SAMPLE), $c->text());
    $c->set('gammu', 'nieistniejacy', null);
    assert_same(str_replace("Connection = at\n", '', CONF_SAMPLE), $c->text());
});

test('nowa sekcja na końcu pliku', function () {
    $c = GammuConf::parse(CONF_SAMPLE);
    $c->set('exclude_numbers', 'number1', '+48601234567');
    assert_same(CONF_SAMPLE . "\n[exclude_numbers]\nnumber1 = +48601234567\n", $c->text());
});

test('maskowanie hasła i zachowanie go przy zapisie', function () {
    $masked = GammuConf::mask(CONF_SAMPLE);
    assert_contains('password = ' . GammuConf::MASK, $masked);
    assert_true(!str_contains($masked, 'tajne'));
    $edited = str_replace('PhoneID = GSM1', 'PhoneID = GSM9', $masked);
    assert_same(str_replace('PhoneID = GSM1', 'PhoneID = GSM9', CONF_SAMPLE), GammuConf::unmask($edited, CONF_SAMPLE));
});

test('walidacja: brak sekcji, service, niezrozumiałe linie', function () {
    $v = GammuConf::parse("[smsd]\nservice = files\nto nie jest linia\n")->validate();
    $text = implode(' | ', array_column($v, 1));
    assert_true(GammuConf::hasErrors($v));
    assert_contains('Brak sekcji: [gammu]', $text);
    assert_contains('service = files', $text);
    assert_contains('Niezrozumiałe linie: 3', $text);
});
