<?php
declare(strict_types=1);

/** Tabele Gammu (rozdz. 3.1–3.5): zapis do outbox, odczyt stanu wiadomości, odczyt inbox i phones. */
final class GammuDb
{
    /** Wartości kolumn ENUM Gammu – tylko z tej listy (rozdz. 9.1). */
    public const SENT_OK = ['SendingOK', 'SendingOKNoReport', 'DeliveryPending', 'DeliveryUnknown'];
    public const SENT_ERROR = ['SendingError', 'Error'];

    /** Opisy kodów +CMS ERROR (sentitems/outbox.StatusCode). */
    public const CMS_ERRORS = [
        8 => 'operator zablokował numer', 10 => 'połączenie zablokowane', 21 => 'wiadomość odrzucona przez sieć',
        27 => 'nieznany odbiorca', 28 => 'nieznany abonent', 29 => 'usługa odrzucona', 30 => 'nieznany abonent',
        38 => 'awaria sieci', 41 => 'awaria tymczasowa', 42 => 'przeciążenie sieci', 47 => 'brak zasobów sieci',
        50 => 'usługa niedostępna', 69 => 'funkcja nieobsługiwana', 96 => 'błędne dane wiadomości', 111 => 'błąd protokołu',
        300 => 'awaria modemu', 301 => 'usługa SMS zarezerwowana', 302 => 'operacja niedozwolona', 304 => 'błędne parametry PDU',
        310 => 'brak karty SIM', 311 => 'wymagany PIN', 313 => 'awaria karty SIM', 322 => 'pamięć pełna',
        330 => 'nieznany numer centrum SMS', 331 => 'brak sieci', 332 => 'przekroczony czas sieci', 500 => 'nieznany błąd',
    ];

    /** Opisy TP-Status z raportu doręczenia (sentitems.StatusError). */
    public const TP_STATUS = [
        0 => 'doręczona', 1 => 'przekazana, brak potwierdzenia', 2 => 'zastąpiona przez centrum SMS',
        32 => 'przeciążenie – sieć ponawia', 33 => 'telefon odbiorcy zajęty – sieć ponawia', 34 => 'brak odpowiedzi – sieć ponawia',
        64 => 'błąd procedury zdalnej', 65 => 'niezgodny odbiorca', 66 => 'połączenie odrzucone przez odbiorcę',
        67 => 'numer nieosiągalny', 68 => 'brak jakości usługi', 69 => 'brak współpracy sieci',
        70 => 'upłynął czas ważności – odbiorca nieosiągalny', 71 => 'usunięta przez nadawcę', 72 => 'usunięta przez centrum SMS',
        73 => 'wiadomość nie istnieje', 96 => 'przeciążenie sieci', 97 => 'telefon odbiorcy zajęty', 98 => 'brak odpowiedzi odbiorcy',
        99 => 'usługa odrzucona',
    ];

    public static function cmsError(?int $code): string
    {
        if ($code === null || $code < 0) {
            return '';
        }
        return 'kod ' . $code . ': ' . (self::CMS_ERRORS[$code] ?? 'szczegóły w logu Gammu');
    }

    public static function tpStatus(?int $code): string
    {
        if ($code === null || $code < 0) {
            return '';
        }
        $desc = self::TP_STATUS[$code] ?? match (true) {
            $code < 32 => 'doręczona',
            $code < 64 => 'błąd tymczasowy – sieć ponawia',
            default => 'błąd trwały',
        };
        return "Status $code: $desc";
    }

    /** MaxRetries z gammu-smsdrc (domyślnie 1, czyli 2 próby). */
    public static function maxRetries(): int
    {
        $v = GammuConf::load()?->get('smsd', 'maxretries');
        return $v !== null && ctype_digit($v) ? (int) $v : 1;
    }

    /**
     * Zapis wiadomości: część 1 do outbox, części 2…N do outbox_multipart (w transakcji wywołującego).
     * @param array{number:string,parts:list<array{text:string,udh:string}>,coding:string,class?:int,report?:bool,
     *   send_at?:?string,after?:?string,before?:?string,priority?:int,sender?:?string} $m
     */
    public static function insertOutbox(array $m): int
    {
        $first = $m['parts'][0];
        $multi = count($m['parts']) > 1;
        $id = Db::insert(Db::g() . '.outbox', [
            'DestinationNumber' => $m['number'],
            'TextDecoded' => $first['text'],
            'Text' => '',
            'Coding' => $m['coding'],
            'UDH' => $first['udh'],
            'Class' => $m['class'] ?? -1,
            'MultiPart' => $multi ? 'true' : 'false',
            'SendingDateTime' => $m['send_at'] ?? now_db(),
            'SendAfter' => $m['after'] ?? '00:00:00',
            'SendBefore' => $m['before'] ?? '23:59:59',
            'DeliveryReport' => ($m['report'] ?? false) ? 'yes' : 'no',
            'SenderID' => $m['sender'] ?? null,
            'CreatorID' => (string) cfg('creator_id', 'smsgui'),
            'Priority' => $m['priority'] ?? 0,
        ]);
        foreach (array_slice($m['parts'], 1) as $i => $part) {
            Db::insert(Db::g() . '.outbox_multipart', [
                'ID' => $id,
                'SequencePosition' => $i + 2,
                'TextDecoded' => $part['text'],
                'Text' => '',
                'Coding' => $m['coding'],
                'UDH' => $part['udh'],
                'Class' => $m['class'] ?? -1,
            ]);
        }
        return $id;
    }

    /** Anulowanie: usunięcie wiersza, o ile Gammu go właśnie nie wysyła (rozdz. 3.3). */
    public static function cancel(int $id): bool
    {
        return Db::tx(static function () use ($id): bool {
            $n = Db::exec('DELETE FROM {g}.outbox WHERE ID = ? AND (SendingTimeOut <= NOW() OR SendingTimeOut IS NULL)', [$id]);
            if ($n > 0) {
                Db::exec('DELETE FROM {g}.outbox_multipart WHERE ID = ?', [$id]);
            }
            return $n > 0;
        });
    }

    /** Czy któraś część wiadomości ponawianej została już wysłana (outbox_multipart.Status). */
    public static function partlySent(int $id): bool
    {
        return Db::val("SELECT 1 FROM {g}.outbox_multipart WHERE ID = ? AND Status IN ('SendingOK','SendingOKNoReport') LIMIT 1", [$id]) !== null;
    }

    /** Wiersze outbox i sentitems dla wielu ID naraz: [outbox[ID] => wiersz, sent[ID] => list części]. */
    public static function fetchStates(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [[], []];
        }
        $in = Db::in($ids);
        $outbox = [];
        foreach (Db::all("SELECT ID, SendingDateTime, SendAfter, SendBefore, Retries, StatusCode, SendingTimeOut,
                (SendingDateTime > NOW() OR CURTIME() < SendAfter OR CURTIME() > SendBefore) AS waiting
                FROM {g}.outbox WHERE ID IN ($in)", $ids) as $r) {
            $outbox[(int) $r['ID']] = $r;
        }
        $sent = [];
        foreach (Db::all("SELECT ID, SequencePosition, Status, StatusError, StatusCode, SendingDateTime, DeliveryDateTime, SenderID,
                DestinationNumber, TextDecoded, Coding, CreatorID, InsertIntoDB, Class
                FROM {g}.sentitems WHERE ID IN ($in) ORDER BY ID, SequencePosition", $ids) as $r) {
            $sent[(int) $r['ID']][] = $r;
        }
        return [$outbox, $sent];
    }

    /**
     * Status wiadomości z wiersza outbox i części w sentitems (tabela w rozdz. 3.5).
     * @return array{status:string,error:?string,status_code:?int,retries:int,modem:?string,sent_at:?string,delivered_at:?string}
     */
    public static function state(?array $outbox, array $parts, int $maxRetries): array
    {
        $s = ['status' => 'queued', 'error' => null, 'status_code' => null, 'retries' => 0, 'modem' => null, 'sent_at' => null, 'delivered_at' => null];
        if ($outbox !== null) {
            $retries = (int) $outbox['Retries'];
            $s['retries'] = $retries;
            if ($retries > 0) {
                $code = (int) $outbox['StatusCode'];
                $s['status_code'] = $code >= 0 ? $code : null;
                $next = ts($outbox['SendingTimeOut']);
                $s['error'] = 'próba ' . ($retries + 1) . ' z ' . ($maxRetries + 1)
                    . ($next !== null && $next > time() ? ', kolejna o ' . date('H:i', $next) : '')
                    . ($code >= 0 ? ' · ' . self::cmsError($code) : '');
            }
            $s['status'] = $retries === 0 && (int) $outbox['waiting'] === 1 ? 'scheduled' : 'queued';
            return $s;
        }
        if ($parts === []) {
            return ['status' => 'failed', 'error' => 'wiadomość usunięta z kolejki Gammu'] + $s;
        }
        $statuses = array_column($parts, 'Status');
        $s['modem'] = $parts[0]['SenderID'] ?: null;
        $s['sent_at'] = max(array_column($parts, 'SendingDateTime'));
        foreach ($parts as $p) {
            if (in_array($p['Status'], self::SENT_ERROR, true)) {
                $code = (int) $p['StatusCode'];
                $s['status_code'] = $code >= 0 ? $code : null;
                return ['status' => 'failed', 'error' => $p['Status'] . ($code >= 0 ? ' – ' . self::cmsError($code) : '')] + $s;
            }
        }
        foreach ($parts as $p) {
            if ($p['Status'] === 'DeliveryFailed') {
                $s['status_code'] = (int) $p['StatusError'];
                return ['status' => 'undelivered', 'error' => self::tpStatus((int) $p['StatusError'])] + $s;
            }
        }
        if (count(array_filter($statuses, static fn ($x) => $x === 'DeliveryOK')) === count($statuses)) {
            $s['delivered_at'] = max(array_column($parts, 'DeliveryDateTime'));
            return ['status' => 'delivered'] + $s;
        }
        $s['status'] = 'sent';
        if (in_array('DeliveryPending', $statuses, true)) {
            $p = array_values(array_filter($parts, static fn ($x) => $x['Status'] === 'DeliveryPending'))[0];
            $s['error'] = 'raport: ' . self::tpStatus((int) $p['StatusError']);
        } elseif (in_array('DeliveryUnknown', $statuses, true)) {
            $s['error'] = 'raport doręczenia: stan nieznany';
        } elseif (in_array('SendingOKNoReport', $statuses, true)) {
            $s['error'] = 'bez raportu doręczenia';
        }
        return $s;
    }

    /**
     * Wiersze inbox gotowe do importu: nieprzetworzone, bez najświeższych (Gammu zapisuje części pojedynczo – rozdz. 3.4).
     */
    public static function inboxReady(int $limit = 500): array
    {
        $fresh = Db::val("SELECT MIN(ID) FROM {g}.inbox WHERE Processed = 'false' AND UpdatedInDB >= NOW() - INTERVAL 2 SECOND");
        $sql = "SELECT ID, SenderNumber, ReceivingDateTime, TextDecoded, Text, Coding, UDH, Class, RecipientID, Status
                FROM {g}.inbox WHERE Processed = 'false'" . ($fresh !== null ? ' AND ID < ?' : '') . ' ORDER BY ID LIMIT ' . $limit;
        return Db::all($sql, $fresh !== null ? [(int) $fresh] : []);
    }

    /**
     * Grupowanie części odebranych wiadomości po UDH (nadawca, modem, numer referencyjny, liczba części).
     * @return list<array{rows:list<array>,total:int,ref:?int}>
     */
    public static function groupInbox(array $rows): array
    {
        $groups = [];
        $open = [];
        foreach ($rows as $r) {
            $u = (int) $r['Class'] === 127 ? null : SmsText::parseUdh((string) $r['UDH']);
            if ($u === null || $u['total'] < 2) {
                $groups[] = ['rows' => [$r], 'total' => 1, 'ref' => null];
                continue;
            }
            $key = $r['SenderNumber'] . '|' . $r['RecipientID'] . '|' . $u['ref'] . '|' . $u['total'];
            $g = $open[$key] ?? null;
            if ($g === null || isset($groups[$g]['seqs'][$u['seq']])) {
                $groups[] = ['rows' => [], 'total' => $u['total'], 'ref' => $u['ref'] & 0xFF, 'seqs' => []];
                $g = $open[$key] = array_key_last($groups);
            }
            $groups[$g]['rows'][] = $r + ['_seq' => $u['seq']];
            $groups[$g]['seqs'][$u['seq']] = true;
        }
        foreach ($groups as &$g) {
            usort($g['rows'], static fn ($a, $b) => ($a['_seq'] ?? 0) <=> ($b['_seq'] ?? 0));
            unset($g['seqs']);
        }
        return $groups;
    }

    /** Treść odebranej grupy: złączenie TextDecoded części (działa, gdy Gammu skleił tekst i gdy nie). */
    public static function groupText(array $group): string
    {
        $text = '';
        foreach ($group['rows'] as $r) {
            if ($r['Coding'] === '8bit' && (string) $r['TextDecoded'] === '') {
                $text .= '[dane binarne] ' . $r['Text'];
            } else {
                $text .= (string) $r['TextDecoded'];
            }
        }
        return $text;
    }

    public static function markInbox(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        if (cfg('gammu_rows') === 'delete') {
            Db::exec('DELETE FROM {g}.inbox WHERE ID IN (' . Db::in($ids) . ')', $ids);
        } else {
            Db::exec("UPDATE {g}.inbox SET Processed = 'true' WHERE ID IN (" . Db::in($ids) . ')', $ids);
        }
    }
}
