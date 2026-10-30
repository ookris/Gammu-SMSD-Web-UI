<?php
declare(strict_types=1);

/** Kanał dostarczania powiadomień i przekazywanych wiadomości (e-mail, webhook, SMS). */
interface Channel
{
    /**
     * Wysyła wiadomość; wyjątek oznacza błąd do ponowienia.
     * @param array{subject:string,text:string,data:array<string,mixed>} $msg data – pola dla webhooka (type, from, text…)
     */
    public function send(array $msg): void;
}
