<?php
declare(strict_types=1);

/**
 * Synchronizacja z tabelami Gammu. Wywołują ją: proces w tle (co worker_interval s),
 * `smsgui sync` i panel przy odświeżeniu strony (nie częściej niż co 10 s). Blokada GET_LOCK – nigdy równolegle.
 */
final class Sync
{
    private const LOCK = 'smsgui-sync';

    /** Jeden przebieg; null = inny proces właśnie synchronizuje. */
    public static function run(bool $wait = false): ?array
    {
        if (!Db::lock(self::LOCK, $wait ? 10 : 0)) {
            return null;
        }
        $r = ['statuses' => 0, 'external' => 0, 'received' => 0, 'ussd' => 0, 'modems' => 0, 'calls' => 0, 'errors' => 0];
        $errors = [];
        try {
            Settings::forget();
            Status::forget();
            GammuConf::forget();
            foreach ([
                'statuses' => self::outgoing(...),
                'external' => self::external(...),
                'ussd' => Ussd::sync(...),
                'received' => self::inbox(...),
                'modems' => self::phones(...),
                'calls' => Calls::process(...),
            ] as $step => $fn) {
                try {
                    $r[$step] = $fn();
                } catch (PDOException $e) {
                    throw $e; // błąd bazy – cały przebieg do powtórzenia
                } catch (Throwable $e) {
                    $r['errors']++;
                    $errors[] = "$step: " . $e->getMessage();
                    app_log('error', "synchronizacja ($step): " . $e->getMessage());
                }
            }
            $checked = ts(Settings::get('service_checked_at'));
            if ($checked === null || time() - $checked >= 15) {
                Service::refreshStatus();
            }
            Messages::dailyCleanup();
            Settings::set('last_sync_at', now_db());
            Settings::set('last_sync_result', $r);
            Settings::set('last_sync_error', implode('; ', $errors));
        } finally {
            Db::unlock(self::LOCK);
        }
        return $r;
    }

    /** Synchronizacja przy odświeżeniu strony – gdy proces w tle nie działa (nie częściej niż co 10 s). */
    public static function onPageLoad(): void
    {
        $last = ts(Settings::get('last_sync_at'));
        if ($last !== null && time() - $last < 10) {
            return;
        }
        try {
            self::run();
        } catch (Throwable $e) {
            app_log('error', 'synchronizacja przy odświeżeniu: ' . $e->getMessage());
        }
    }

    public static function summary(array $r): string
    {
        return t('sync.summary', $r);
    }

    /** Proces w tle: pętla co worker_interval s; koniec po 1 h lub po zmianie plików aplikacji (systemd uruchamia ponownie). */
    public static function worker(): void
    {
        $interval = max(1, (int) cfg('worker_interval', 3));
        $started = time();
        $fingerprint = self::codeFingerprint();
        $stop = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use (&$stop) { $stop = true; });
            pcntl_signal(SIGINT, static function () use (&$stop) { $stop = true; });
        }
        app_log('info', 'proces w tle: start (co ' . $interval . ' s)');
        while (!$stop) {
            try {
                self::run();
                Settings::set('worker_seen_at', now_db());
            } catch (PDOException $e) {
                app_log('error', 'proces w tle: ' . $e->getMessage() . ' – ponowne połączenie');
                Db::set(null);
                sleep(5);
            }
            if (time() - $started > 3600) {
                app_log('info', 'proces w tle: zakończenie po 1 h');
                break;
            }
            if (self::codeFingerprint() !== $fingerprint) {
                app_log('info', 'proces w tle: zmiana plików aplikacji – zakończenie');
                break;
            }
            sleep($interval);
        }
    }

    private static function codeFingerprint(): string
    {
        clearstatcache();
        $parts = [];
        foreach ([...glob(APP_ROOT . '/src/*.php'), APP_ROOT . '/bin/smsgui', APP_ROOT . '/config/config.php'] as $f) {
            $parts[] = $f . '@' . @filemtime($f);
        }
        return md5(implode('|', $parts));
    }

    // ---------- Krok 1: statusy wysłanych ----------

    private static function outgoing(): int
    {
        $rows = Db::all("SELECT id, gammu_id, status, error, status_code, retries, modem, sent_at, delivered_at FROM messages
            WHERE direction = 'out' AND gammu_id IS NOT NULL
              AND (status IN ('scheduled','queued') OR (status = 'sent' AND sent_at >= ?))", [now_db(-7 * 86400)]);
        $changed = 0;
        $max = GammuDb::maxRetries();
        foreach (array_chunk($rows, 500) as $chunk) {
            [$outbox, $sent] = GammuDb::fetchStates(array_column($chunk, 'gammu_id'));
            foreach ($chunk as $m) {
                $gid = (int) $m['gammu_id'];
                $s = GammuDb::state($outbox[$gid] ?? null, $sent[$gid] ?? [], $max);
                $new = [
                    'status' => $s['status'], 'error' => $s['error'], 'status_code' => $s['status_code'], 'retries' => $s['retries'],
                    'modem' => $s['modem'] ?? $m['modem'], 'sent_at' => $s['sent_at'] ?? $m['sent_at'], 'delivered_at' => $s['delivered_at'],
                ];
                $old = array_intersect_key($m, $new);
                if (array_map('strval', $old) != array_map('strval', $new)) {
                    Db::update('messages', $new + ['updated_at' => now_db()], 'id = ?', [$m['id']]);
                    $changed++;
                }
            }
        }
        return $changed;
    }

    // ---------- Krok 2: wiadomości zewnętrzne (gammu-smsd-inject, inne programy piszące do bazy Gammu) ----------

    private static function external(): int
    {
        $rows = Db::all("SELECT s.ID FROM {g}.sentitems s
            WHERE s.SequencePosition = 1 AND s.Class <> 127 AND s.CreatorID <> ? AND s.InsertIntoDB >= NOW() - INTERVAL 7 DAY
              AND NOT EXISTS (SELECT 1 FROM messages m WHERE m.direction = 'out' AND m.gammu_id = s.ID)
              AND NOT EXISTS (SELECT 1 FROM {g}.outbox o WHERE o.ID = s.ID)
            ORDER BY s.ID LIMIT 200", [(string) cfg('creator_id', 'smsgui')]);
        if ($rows === []) {
            return 0;
        }
        [, $sent] = GammuDb::fetchStates(array_column($rows, 'ID'));
        $max = GammuDb::maxRetries();
        $n = 0;
        foreach ($sent as $gid => $parts) {
            $s = GammuDb::state(null, $parts, $max);
            $first = $parts[0];
            $body = implode('', array_column($parts, 'TextDecoded'));
            Db::insert('messages', [
                'direction' => 'out', 'phone' => Phone::fromGammu((string) $first['DestinationNumber']), 'body' => $body,
                'encoding' => $first['Coding'] === 'Unicode_No_Compression' ? 'UCS2' : ($first['Coding'] === '8bit' ? '8bit' : 'GSM'),
                'parts' => count($parts), 'status' => $s['status'], 'error' => $s['error'], 'status_code' => $s['status_code'],
                'source' => 'external', 'gammu_id' => $gid, 'modem' => $s['modem'], 'flash' => (int) ((int) $first['Class'] === 0),
                'report' => (int) ($first['Status'] !== 'SendingOKNoReport'), 'is_read' => 1, 'priority' => 0,
                'created_at' => $first['InsertIntoDB'], 'scheduled_at' => $first['InsertIntoDB'], 'sent_at' => $s['sent_at'],
                'delivered_at' => $s['delivered_at'], 'updated_at' => now_db(),
            ]);
            $n++;
        }
        return $n;
    }

    // ---------- Krok 3: odebrane ----------

    private static function inbox(): int
    {
        $n = 0;
        foreach (GammuDb::groupInbox(GammuDb::inboxReady()) as $group) {
            $ids = array_map('intval', array_column($group['rows'], 'ID'));
            $first = $group['rows'][0];
            try {
                Db::tx(static function () use ($group, $ids, $first, &$n): void {
                    if ((int) $first['Class'] === 127) {
                        Ussd::onResponse($first);
                    } else {
                        $text = GammuDb::groupText($group);
                        $coding = (string) $first['Coding'];
                        Db::insert('messages', [
                            'direction' => 'in',
                            'phone' => Phone::fromGammu((string) $first['SenderNumber']),
                            'body' => $text,
                            'encoding' => $coding === '8bit' ? '8bit' : (SmsText::analyze($text)['gsm'] && !str_starts_with($coding, 'Unicode') ? 'GSM' : 'UCS2'),
                            'parts' => max(count($group['rows']), $group['total']),
                            'status' => 'received',
                            'incomplete' => (int) (count($group['rows']) < $group['total']),
                            'flash' => (int) ((int) $first['Class'] === 0),
                            'source' => 'gui',
                            'gammu_id' => $ids[0],
                            'udh_ref' => $group['ref'],
                            'modem' => mb_substr((string) $first['RecipientID'], 0, 64) ?: null,
                            'is_read' => 0,
                            'received_at' => $first['ReceivingDateTime'],
                            'created_at' => now_db(),
                            'updated_at' => now_db(),
                        ]);
                        $n++;
                    }
                    GammuDb::markInbox($ids);
                });
            } catch (PDOException $e) {
                throw $e;
            } catch (Throwable $e) {
                // Uszkodzony wiersz nie blokuje pozostałych: oznaczony jako przetworzony, wpis w logu
                app_log('error', 'import inbox ID ' . implode(',', $ids) . ': ' . $e->getMessage());
                GammuDb::markInbox($ids);
            }
        }
        return $n;
    }

    // ---------- Krok 4: stan modemów ----------

    private static function phones(): int
    {
        $n = 0;
        foreach (Db::all('SELECT ID, IMEI, IMSI, `Signal` AS sig, Battery, NetCode, NetName, Sent, Received, Client, UpdatedInDB FROM {g}.phones') as $p) {
            Db::exec('INSERT INTO modem_status (modem, imei, imsi, signal_pct, battery_pct, net_code, net_name, sent, received, client, gammu_updated_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE imei = VALUES(imei), imsi = VALUES(imsi), signal_pct = VALUES(signal_pct), battery_pct = VALUES(battery_pct),
                  net_code = VALUES(net_code), net_name = VALUES(net_name), sent = VALUES(sent), received = VALUES(received), client = VALUES(client),
                  gammu_updated_at = VALUES(gammu_updated_at), updated_at = VALUES(updated_at)', [
                mb_substr((string) $p['ID'], 0, 64), (string) $p['IMEI'], (string) $p['IMSI'], max(-1, min(100, (int) $p['sig'])),
                max(-1, min(100, (int) $p['Battery'])), (string) $p['NetCode'], (string) $p['NetName'], (int) $p['Sent'], (int) $p['Received'],
                mb_substr((string) $p['Client'], 0, 255), $p['UpdatedInDB'], now_db(),
            ]);
            $n++;
        }
        return $n;
    }
}
