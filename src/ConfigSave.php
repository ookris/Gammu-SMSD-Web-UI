<?php
declare(strict_types=1);

/**
 * Zapis gammu-smsdrc przez okno potwierdzenia (panel zapisuje plik bezpośrednio, ale zawsze po potwierdzeniu): propozycja zmiany trafia do sesji (hasła nie wychodzą
 * do przeglądarki), okno pokazuje różnice i walidację, zapis tworzy kopię i opcjonalnie przeładowuje / restartuje Gammu.
 */
final class ConfigSave
{
    /**
     * $note – opis zmiany z msg_key() (trafia do notatki kopii zapasowej, tłumaczony przy wyświetlaniu).
     * $base – odcisk pliku z chwili otwarcia formularza (pole „base”); null dla zmian liczonych z bieżącej treści
     * (włączenie czarnej listy, połączeń).
     */
    public static function propose(string $newText, string $note, string $tab, ?string $base = null): never
    {
        $current = GammuConf::load(true) ?? throw new RuntimeException(t('file.cannot_read', ['path' => GammuConf::path()]));
        $now = GammuConf::fingerprint($current->text());
        if ($base !== null && !hash_equals($now, $base)) {
            flash('err', t('config.stale_flash'), t('config.stale_flash_text'));
            redirect(url('config', ['tab' => $tab === 'form' ? null : $tab]));
        }
        $_SESSION['conf_pending'] = [
            'token' => bin2hex(random_bytes(8)), 'text' => $newText, 'note' => $note, 'tab' => $tab, 'base' => $base ?? $now,
        ];
        redirect(url('config', ['tab' => $tab, 'confirm' => 1]));
    }

    /** Oczekująca zmiana z różnicami (maskowanymi) i wynikiem walidacji. */
    public static function pending(): ?array
    {
        $p = $_SESSION['conf_pending'] ?? null;
        $current = GammuConf::load();
        if ($p === null || $current === null) {
            return null;
        }
        $diff = Diff::lines(GammuConf::mask($current->text()), GammuConf::mask($p['text']));
        $validation = GammuConf::parse($p['text'])->validate($current);
        return $p + [
            'diff' => Diff::context($diff), 'changed' => Diff::changed($diff),
            'validation' => array_values(array_filter($validation, static fn ($v) => $v[0] !== 'ok')),
            'blocked' => GammuConf::hasErrors($validation), 'stale' => !hash_equals(GammuConf::fingerprint($current->text()), $p['base']),
        ];
    }

    /** Zapis po potwierdzeniu; zwraca adres przekierowania. */
    public static function confirm(string $token, string $after): string
    {
        $p = self::pending();
        unset($_SESSION['conf_pending']);
        if ($p === null || !hash_equals($p['token'], $token)) {
            flash('err', t('config.expired'), t('config.expired_text'));
            return url('config');
        }
        if ($p['stale']) {
            flash('err', t('config.changed_meanwhile'), t('config.changed_meanwhile_text'));
            return url('config', ['tab' => $p['tab']]);
        }
        if ($p['blocked']) {
            flash('err', t('config.has_errors'), implode('; ', array_column(array_filter($p['validation'], static fn ($v) => $v[0] === 'err'), 1)));
            return url('config', ['tab' => $p['tab']]);
        }
        try {
            $backup = GammuConf::save($p['text'], $p['note'], $p['base']);
        } catch (ConfStaleException) {
            flash('err', t('config.changed_meanwhile'), t('config.changed_meanwhile_text'));
            return url('config', ['tab' => $p['tab']]);
        } catch (Throwable $e) {
            flash('err', t('config.write_failed'), $e->getMessage());
            return url('config', ['tab' => $p['tab']]);
        }
        $msg = t('config.backup_msg', ['name' => $backup]);
        if ($after === 'restart') {
            return Service::restartAndWatch($msg);
        }
        if ($after === 'reload') {
            [$code, $out] = Service::reload();
            flash($code === 0 ? 'ok' : 'warn', t($code === 0 ? 'config.saved_reloaded' : 'config.saved_reload_failed'),
                $msg . ($code !== 0 ? ' ' . $out : ''));
        } else {
            flash('ok', t('config.saved'), t('config.saved_text', ['backup' => $msg]));
        }
        return url('config', ['tab' => $p['tab']]);
    }
}
