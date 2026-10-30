<?php
declare(strict_types=1);

/** SMS na wskazany numer przez tę samą bramkę (kolejka Gammu); źródło „notify”, żeby błędy tych SMS nie wywoływały alarmów. */
final class SmsChannel implements Channel
{
    /** @param array{number?:string} $config */
    public function __construct(private array $config)
    {
    }

    public function send(array $msg): void
    {
        $phone = Phone::normalize((string) ($this->config['number'] ?? ''));
        if ($phone === null) {
            throw new RuntimeException(t('channels.err_number'));
        }
        Outbox::create([
            'phone' => $phone, 'body' => mb_substr($msg['subject'] . ($msg['text'] !== '' ? ': ' . $msg['text'] : ''), 0, 300),
            'translit' => true, 'source' => 'notify', 'priority' => Outbox::PRIORITY_SINGLE,
        ]);
    }
}
