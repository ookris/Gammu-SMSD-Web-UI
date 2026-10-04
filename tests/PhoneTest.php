<?php
test('normalizacja wg tabeli 3.13', function () {
    foreach ([
        '601 234 567' => '48601234567', '+48 601-234-567' => '48601234567', '0048601234567' => '48601234567',
        '+44 7911 123456' => '447911123456', '8080' => '8080', 'abc' => null, '12' => null, '601 23' => null,
        '(22) 456-78-90' => '48224567890',
    ] as $in => $out) {
        assert_same($out, Phone::normalize((string) $in), "wejście: $in");
    }
});

test('format dla Gammu i wyświetlanie', function () {
    assert_same('+48601234567', Phone::toGammu('48601234567'));
    assert_same('8080', Phone::toGammu('8080'));
    assert_same('+48 601 234 567', Phone::format('48601234567'));
    assert_same('+44 7911 123456', Phone::format('447911123456'));
    assert_same('ORLEN', Phone::format('ORLEN'));
});

test('numer odebrany od Gammu', function () {
    assert_same('48601234567', Phone::fromGammu('+48601234567'));
    assert_same('ORLEN', Phone::fromGammu('ORLEN'));
    assert_same('48601234567', Phone::fromGammu('601234567'));
    assert_true(Phone::isAlpha('InfoPLAY'));
});

test('warianty do czarnej listy', function () {
    assert_same(['+48601234567', '48601234567', '0048601234567', '601234567'], Phone::variants('48601234567'));
    assert_same(['PROMO-SMS'], Phone::variants('PROMO-SMS'));
});
