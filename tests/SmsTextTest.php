<?php
function smstext_case_text(array $c): string
{
    if (isset($c['text'])) {
        return $c['text'];
    }
    return implode('', array_map(static fn ($r) => str_repeat($r[0], $r[1]), $c['repeat']));
}

foreach (json_decode(file_get_contents(__DIR__ . '/cases/smstext.json'), true) as $c) {
    test($c['name'], function () use ($c) {
        $text = smstext_case_text($c);
        if (!empty($c['translit'])) {
            $text = SmsText::translit($text);
        }
        $a = SmsText::analyze($text);
        assert_same([$c['gsm'], $c['units'], $c['parts']], [$a['gsm'], $a['units'], $a['parts']]);
    });
}

test('lista znaków wymuszających Unicode', function () {
    assert_same(['ą', 'ł', 'ż'], SmsText::analyze('ąąłżab')['bad']);
});

test('podział: 500 znaków z polskimi literami = 8 części z UDH', function () {
    $text = str_repeat('Zażółć gęślą jaźń. ', 27) . 'abcdefg'; // 513 znaków
    $text = mb_substr($text, 0, 500);
    $parts = SmsText::split($text, 0x4F);
    assert_same(8, count($parts));
    assert_same('0500034F0801', $parts[0]['udh']);
    assert_same('0500034F0808', $parts[7]['udh']);
    assert_same($text, implode('', array_column($parts, 'text')));
    assert_same(67, mb_strlen($parts[0]['text']));
});

test('podział: znak rozszerzony nie jest rozdzielany', function () {
    $text = str_repeat('a', 152) . '€' . str_repeat('b', 10);
    $parts = SmsText::split($text, 1);
    assert_same(str_repeat('a', 152), $parts[0]['text']);
    assert_same('€' . str_repeat('b', 10), $parts[1]['text']);
});

test('podział: emoji nie jest rozdzielane', function () {
    $text = str_repeat('ą', 66) . '👍' . 'xxxxx';
    $parts = SmsText::split($text, 1);
    assert_same(str_repeat('ą', 66), $parts[0]['text']);
    assert_same('👍xxxxx', $parts[1]['text']);
});

test('podział: limit 10 części', function () {
    try {
        SmsText::split(str_repeat('a', 1531), 1);
        throw new AssertionFailed('brak wyjątku');
    } catch (InvalidArgumentException) {
    }
    assert_same(10, count(SmsText::split(str_repeat('a', 1530), 1)));
});

test('UDH: 8- i 16-bitowy numer referencyjny', function () {
    assert_same(['ref' => 0x4F, 'total' => 3, 'seq' => 2], SmsText::parseUdh('0500034F0302'));
    assert_same(['ref' => 0x1234, 'total' => 2, 'seq' => 1], SmsText::parseUdh('060804123402' . '01'));
    assert_same(null, SmsText::parseUdh(''));
});
