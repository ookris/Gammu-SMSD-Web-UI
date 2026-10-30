<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/vendor/PHPMailer/Exception.php';
require_once __DIR__ . '/vendor/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/vendor/PHPMailer/SMTP.php';

/** E-mail przez PHPMailer: systemowy sendmail albo bezpośrednio SMTP (ustawienia „Poczta”, Channels::mailSettings()). */
final class MailChannel implements Channel
{
    /** @param array{to?:string} $config */
    public function __construct(private array $config)
    {
    }

    public static function recipients(string $list): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $list, -1, PREG_SPLIT_NO_EMPTY) as $a) {
            if (filter_var($a, FILTER_VALIDATE_EMAIL)) {
                $out[strtolower($a)] = $a;
            }
        }
        return array_values($out);
    }

    public function send(array $msg): void
    {
        $to = self::recipients((string) ($this->config['to'] ?? ''));
        if ($to === []) {
            throw new RuntimeException(t('channels.err_no_recipient'));
        }
        $s = Channels::mailSettings();
        $m = new PHPMailer(true);
        $m->CharSet = PHPMailer::CHARSET_UTF8;
        $m->XMailer = ' ';
        if ($s['mode'] === 'smtp') {
            $m->isSMTP();
            $m->Host = $s['host'];
            $m->Port = (int) $s['port'];
            $m->SMTPSecure = match ($s['secure']) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            $m->SMTPAutoTLS = $s['secure'] !== '';
            $m->Timeout = 8;
            if ($s['user'] !== '') {
                $m->SMTPAuth = true;
                $m->Username = $s['user'];
                $m->Password = $s['pass'];
            }
        } else {
            $m->isSendmail();
        }
        $host = gethostname() ?: 'localhost';
        $from = $s['from'] !== '' ? $s['from'] : 'smsgui@' . (str_contains($host, '.') ? $host : $host . '.local'); // adres bez kropki w domenie jest odrzucany
        $m->setFrom($from, $s['from_name'] !== '' ? $s['from_name'] : t('app.name'));
        foreach ($to as $a) {
            $m->addAddress($a);
        }
        $m->Subject = $msg['subject'];
        $m->Body = $msg['text'];
        $m->isHTML(false);
        $m->send();
    }
}
