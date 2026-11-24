<?php

declare(strict_types=1);

namespace SiteGate;

/**
 * Ссылки из писем (D18): подтверждение почты (24 ч) и сброс пароля (1 ч).
 * Токен того же вида, что тикет входа: «<номер базы><43 символа>», в базе только sha256.
 */
final class EmailFlow
{
    public const VERIFY_TTL = 86400;
    public const RESET_TTL = 3600;

    /** @param array<string, mixed> $account */
    public static function sendVerify(array $account): bool
    {
        $email = (string) ($account['email'] ?? '');
        if ($email === '') {
            return false;
        }
        $token = self::issue($account, 'verify', self::VERIFY_TTL);
        return self::deliver($email, MailTemplates::verify((string) $account['name'], Env::siteUrl() . '/email/verify/' . $token));
    }

    /** @param array<string, mixed> $account */
    public static function sendWelcome(array $account): bool
    {
        return self::deliver((string) $account['email'], MailTemplates::welcome((string) $account['name'], Env::siteUrl()));
    }

    /** @param array<string, mixed> $account */
    public static function sendReset(array $account): bool
    {
        $token = self::issue($account, 'reset', self::RESET_TTL);
        return self::deliver((string) $account['email'], MailTemplates::reset((string) $account['name'], Env::siteUrl() . '/password/reset/' . $token));
    }

    /**
     * Подтвердить почту по ссылке. Аккаунт (уже с отметкой) или null, если ссылка не годится.
     *
     * @return array<string, mixed>|null
     */
    public static function verify(string $token): ?array
    {
        $row = self::find($token, 'verify');
        if ($row === null) {
            return null;
        }
        $shard = (int) $row['shard'];
        $account = Players::byId($shard, (int) $row['account_id']);
        // Почту успели сменить: старая ссылка больше не подтверждает новую
        if ($account === null || strcasecmp((string) $account['email'], (string) $row['email']) !== 0) {
            return null;
        }
        self::markUsed($shard, (string) $row['hash']);
        Db::shardExec($shard, 'UPDATE accounts SET email_verified_at = UTC_TIMESTAMP() WHERE id = ? AND email_verified_at IS NULL', [(int) $account['id']]);
        return Players::byId($shard, (int) $account['id']);
    }

    /**
     * Живая ссылка сброса пароля: аккаунт или null. Ссылку гасит {@see consume()} после смены пароля.
     *
     * @return array<string, mixed>|null
     */
    public static function resetAccount(string $token): ?array
    {
        $row = self::find($token, 'reset');
        return $row === null ? null : Players::byId((int) $row['shard'], (int) $row['account_id']);
    }

    public static function consume(string $token): bool
    {
        $shard = Ticket::shardOf($token);
        return $shard !== null && self::markUsed($shard, Ticket::hash($token));
    }

    /** @param array<string, mixed> $account */
    private static function issue(array $account, string $purpose, int $ttl): string
    {
        $shard = (int) $account['shard'];
        $id = (int) $account['id'];
        // Прошлые ссылки того же вида гасим: работает только последнее письмо
        Db::shardExec($shard, "UPDATE email_tokens SET used_at = UTC_TIMESTAMP() WHERE account_id = ? AND purpose = ? AND used_at IS NULL", [$id, $purpose]);
        Db::shardExec($shard, 'DELETE FROM email_tokens WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 7 DAY');
        $token = Ticket::generate($shard);
        Db::shardExec(
            $shard,
            'INSERT INTO email_tokens (hash, account_id, purpose, email, created_at, expires_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ' . $ttl . ' SECOND)',
            [Ticket::hash($token), $id, $purpose, (string) $account['email']]
        );
        return $token;
    }

    /** @return array<string, mixed>|null */
    private static function find(string $token, string $purpose): ?array
    {
        $shard = Ticket::shardOf($token);
        if ($shard === null) {
            return null;
        }
        $row = Db::shardOne(
            $shard,
            'SELECT hash, account_id, email FROM email_tokens
             WHERE hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            [Ticket::hash($token), $purpose]
        );
        return $row === null ? null : $row + ['shard' => $shard];
    }

    private static function markUsed(int $shard, string $hash): bool
    {
        return Db::shardExec($shard, 'UPDATE email_tokens SET used_at = UTC_TIMESTAMP() WHERE hash = ? AND used_at IS NULL', [$hash]) === 1;
    }

    /** @param array{0: string, 1: string, 2: string} $mail */
    private static function deliver(string $to, array $mail): bool
    {
        $mailer = Mailer::fromEnv();
        if ($mailer === null) {
            error_log('Mail: MAIL_HOST не задан, письмо «' . $mail[0] . '» не отправлено');
            return false;
        }
        try {
            $mailer->send($to, $mail[0], $mail[1], $mail[2]);
            return true;
        } catch (MailException $ex) {
            error_log('Mail: ' . $ex->getMessage());
            return false;
        }
    }
}
