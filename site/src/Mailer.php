<?php

declare(strict_types=1);

namespace SiteGate;

/**
 * Отправка писем через свой Postfix (контейнер mail, D18). Минимальный SMTP-клиент без зависимостей:
 * Postfix в той же docker-сети пускает сайт без логина. DKIM подписывает сам Postfix.
 * Настройки: MAIL_HOST (пусто = почта выключена), MAIL_PORT, MAIL_FROM, MAIL_FROM_NAME, MAIL_HELO.
 */
final class Mailer
{
    public function __construct(
        private string $host,
        private int $port,
        private string $fromEmail,
        private string $fromName,
        private string $helo,
    ) {
    }

    public static function fromEnv(): ?self
    {
        $host = Env::get('MAIL_HOST');
        if ($host === '') {
            return null;
        }
        return new self(
            $host,
            (int) Env::get('MAIL_PORT', '587'),
            Env::get('MAIL_FROM', 'noreply@example.com'),
            Env::get('MAIL_FROM_NAME', 'MyServer'),
            Env::get('MAIL_HELO', 'example.com'),
        );
    }

    public static function validAddress(string $email): bool
    {
        return strlen($email) <= 254
            && preg_match('/[\x00-\x20\x7f<>]/', $email) !== 1
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Письмо целиком: заголовки и тело multipart/alternative (текст + HTML), строки через CRLF. */
    public function build(string $to, string $subject, string $text, string $html, ?int $now = null, ?string $boundary = null, ?string $id = null): string
    {
        if (!self::validAddress($to)) {
            throw new MailException('Кривой адрес получателя.');
        }
        $now ??= time();
        $boundary ??= 'nc-' . bin2hex(random_bytes(12));
        $id ??= bin2hex(random_bytes(16));
        $domain = substr((string) strrchr($this->fromEmail, '@'), 1);
        $headers = [
            'From: ' . self::encodeHeader($this->fromName) . ' <' . $this->fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'Date: ' . gmdate('D, d M Y H:i:s', $now) . ' +0000',
            'Message-ID: <' . $id . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $part = static fn (string $type, string $body): string => '--' . $boundary . "\r\n"
            . 'Content-Type: ' . $type . '; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
            . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n";
        return implode("\r\n", $headers) . "\r\n\r\n"
            . $part('text/plain', $text)
            . $part('text/html', $html)
            . '--' . $boundary . "--\r\n";
    }

    public function send(string $to, string $subject, string $text, string $html): void
    {
        $message = $this->build($to, $subject, $text, $html);
        $fp = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, 10);
        if ($fp === false) {
            throw new MailException("Почтовый сервер недоступен: {$errstr}");
        }
        stream_set_timeout($fp, 20);
        try {
            self::expect($fp, [220]);
            self::command($fp, 'EHLO ' . $this->helo, [250]);
            self::command($fp, 'MAIL FROM:<' . $this->fromEmail . '>', [250]);
            self::command($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($fp, 'DATA', [354]);
            // Точка в начале строки в SMTP значит «конец письма»: удваиваем её (dot-stuffing)
            fwrite($fp, (string) preg_replace('/^\./m', '..', $message) . ".\r\n");
            self::expect($fp, [250]);
            @fwrite($fp, "QUIT\r\n");
        } finally {
            fclose($fp);
        }
    }

    public static function encodeHeader(string $value): string
    {
        $value = (string) preg_replace('/[\r\n]+/', ' ', $value);
        return preg_match('/^[\x20-\x7e]*$/', $value) === 1
            ? $value
            : mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n ");
    }

    /**
     * @param resource $fp
     * @param list<int> $ok
     */
    private static function command($fp, string $line, array $ok): void
    {
        fwrite($fp, $line . "\r\n");
        self::expect($fp, $ok);
    }

    /**
     * @param resource $fp
     * @param list<int> $ok
     */
    private static function expect($fp, array $ok): void
    {
        $reply = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new MailException('Почтовый сервер ответил: ' . trim($reply !== '' ? $reply : 'тишина (таймаут)'));
        }
    }
}
