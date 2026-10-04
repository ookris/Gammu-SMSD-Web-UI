<?php
declare(strict_types=1);

/**
 * Zapis gammu-smsdrc przez okno potwierdzenia (decyzja D4): propozycja zmiany trafia do sesji (hasła nie wychodzą
 * do przeglądarki), okno pokazuje różnice i walidację, zapis tworzy kopię i opcjonalnie przeładowuje / restartuje Gammu.
 */
final class ConfigSave
{
    /**
     * $base – odcisk pliku z chwili otwarcia formularza (pole „base”); null dla zmian liczonych z bieżącej treści
     * (włączenie czarnej listy, połączeń).
     */
    public static function propose(string $newText, string $note, string $tab, ?string $base = null): never
    {
        $current = GammuConf::load(true) ?? throw new RuntimeException('Nie można odczytać ' . GammuConf::path());
        $now = GammuConf::fingerprint($current->text());
        if ($base !== null && !hash_equals($now, $base)) {
            flash('err', 'Plik został zmieniony od otwarcia formularza.', 'Nic nie zapisano – sprawdź bieżącą treść i wprowadź zmianę ponownie.');
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
            flash('err', 'Zmiana wygasła.', 'Otwórz formularz i zapisz ponownie.');
            return url('config');
        }
        if ($p['stale']) {
            flash('err', 'Plik został w międzyczasie zmieniony.', 'Nic nie zapisano – sprawdź bieżącą treść i zapisz ponownie.');
            return url('config', ['tab' => $p['tab']]);
        }
        if ($p['blocked']) {
            flash('err', 'Nie zapisano – konfiguracja ma błędy.', implode('; ', array_column(array_filter($p['validation'], static fn ($v) => $v[0] === 'err'), 1)));
            return url('config', ['tab' => $p['tab']]);
        }
        try {
            $backup = GammuConf::save($p['text'], $p['note'], $p['base']);
        } catch (ConfStaleException) {
            flash('err', 'Plik został w międzyczasie zmieniony.', 'Nic nie zapisano – sprawdź bieżącą treść i zapisz ponownie.');
            return url('config', ['tab' => $p['tab']]);
        } catch (Throwable $e) {
            flash('err', 'Nie udało się zapisać pliku.', $e->getMessage());
            return url('config', ['tab' => $p['tab']]);
        }
        $msg = 'Kopia zapasowa: ' . $backup . '.';
        if ($after === 'restart') {
            return Service::restartAndWatch('zapis konfiguracji', $msg);
        }
        if ($after === 'reload') {
            [$code, $out] = Service::reload();
            flash($code === 0 ? 'ok' : 'warn', 'Zapisano konfigurację' . ($code === 0 ? ' i przeładowano Gammu.' : ', ale przeładowanie się nie udało.'),
                $msg . ($code !== 0 ? ' ' . $out : ''));
        } else {
            flash('ok', 'Zapisano konfigurację.', $msg . ' Zmiany zaczną działać po przeładowaniu Gammu.');
        }
        return url('config', ['tab' => $p['tab']]);
    }
}
