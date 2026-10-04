<?php
declare(strict_types=1);

/** Szablony wiadomości (rozdz. 2.9). */
final class Templates
{
    public static function all(): array
    {
        return Db::all('SELECT * FROM templates ORDER BY name');
    }

    public static function find(int $id): ?array
    {
        return Db::row('SELECT * FROM templates WHERE id = ?', [$id]);
    }

    /** Zapis; [id, błąd]. */
    public static function save(?int $id, string $name, string $body): array
    {
        $name = trim($name);
        $body = SmsText::normalize(trim($body));
        if ($name === '' || $body === '') {
            return [null, 'Podaj nazwę i treść szablonu.'];
        }
        $data = ['name' => mb_substr($name, 0, 190), 'body' => $body, 'updated_at' => now_db()];
        if ($id === null) {
            return [Db::insert('templates', $data + ['created_at' => now_db()]), null];
        }
        Db::update('templates', $data, 'id = ?', [$id]);
        return [$id, null];
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM templates WHERE id = ?', [$id]);
    }
}
