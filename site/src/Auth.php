<?php

declare(strict_types=1);

namespace SiteGate;

final class Auth
{
    public const SESSION_NAME = 'sg_admin';
    private const KEY = 'admin_id';

    /** @var array<string, mixed>|null */
    private static ?array $current = null;

    /** @param array<string, mixed>|null $server */
    public static function startSession(?array $server = null, string $name = self::SESSION_NAME): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $server ??= $_SERVER;
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::isHttps($server),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }

    /**
     * REMOTE_ADDR входит в TRUSTED_PROXY? Элемент списка: IP, CIDR или имя хоста.
     *
     * @param array<string, mixed> $server
     * @param (callable(string): list<string>)|null $resolver резолвер имён (для тестов)
     */
    public static function isTrustedProxy(array $server, ?string $trusted = null, ?callable $resolver = null): bool
    {
        $trusted ??= (string) (getenv('TRUSTED_PROXY') ?: '');
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($remote === '' || trim($trusted) === '') {
            return false;
        }
        foreach (explode(',', $trusted) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (self::ipMatches($remote, $entry)) {
                return true;
            }
            if (self::isIpOrCidr($entry)) {
                continue;
            }
            foreach (self::resolveHost($entry, $resolver) as $ip) {
                if (self::ipMatches($remote, $ip)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Чистая проверка: $ip равен $rule (IP) или входит в $rule (CIDR). Имена хостов тут не обрабатываются. */
    public static function ipMatches(string $ip, string $rule): bool
    {
        $ipBin = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
        if ($ipBin === false) {
            return false;
        }
        $rule = trim($rule);
        $bits = null;
        if (str_contains($rule, '/')) {
            [$net, $len] = explode('/', $rule, 2);
            if ($len === '' || !ctype_digit($len)) {
                return false;
            }
            $bits = (int) $len;
            $rule = $net;
        }
        if (filter_var($rule, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $netBin = inet_pton($rule);
        if ($netBin === false || strlen($netBin) !== strlen($ipBin)) {
            return false; // разные семейства адресов
        }
        $max = strlen($netBin) * 8;
        if ($bits === null) {
            return $netBin === $ipBin;
        }
        if ($bits > $max) {
            return false;
        }
        $fullBytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
    }

    private static function isIpOrCidr(string $entry): bool
    {
        $addr = explode('/', $entry, 2)[0];
        return filter_var($addr, FILTER_VALIDATE_IP) !== false || str_contains($entry, '/');
    }

    /**
     * @param (callable(string): list<string>)|null $resolver
     * @return list<string>
     */
    private static function resolveHost(string $host, ?callable $resolver): array
    {
        if ($resolver !== null) {
            return $resolver($host);
        }
        /** @var array<string, list<string>> $cache */
        static $cache = [];
        return $cache[$host] ??= (gethostbynamel($host) ?: []);
    }

    /** @param array<string, mixed> $server */
    public static function isHttps(array $server, ?string $trusted = null): bool
    {
        $https = $server['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        return self::isTrustedProxy($server, $trusted)
            && strtolower(trim((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
    }

    /** @param array<string, mixed>|null $server */
    public static function clientIp(?array $server = null, ?string $trusted = null): string
    {
        $server ??= $_SERVER;
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if (self::isTrustedProxy($server, $trusted)) {
            $xff = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
            $first = trim(explode(',', $xff)[0]);
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }
        return $remote !== '' ? $remote : '0.0.0.0';
    }

    public static function login(string $username, string $password): bool
    {
        $row = Db::one('SELECT id, password_hash FROM admins WHERE username = ?', [$username]);
        // Для несуществующего логина всё равно считаем хеш, чтобы не выдавать его по времени ответа.
        $hash = $row !== null ? (string) $row['password_hash'] : password_hash('dummy-password', PASSWORD_DEFAULT);
        $ok = password_verify($password, $hash);
        if (!$ok || $row === null) {
            return false;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Db::pdo()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $row['id']]);
        }
        session_regenerate_id(true);
        $_SESSION[self::KEY] = (int) $row['id'];
        self::$current = null;
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(self::SESSION_NAME, '', [
                'expires' => time() - 3600,
                'path' => $p['path'],
                'secure' => (bool) $p['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_destroy();
        }
        self::$current = null;
    }

    /** @return array{id: int, username: string}|null */
    public static function currentAdmin(): ?array
    {
        $id = $_SESSION[self::KEY] ?? null;
        if (!is_int($id)) {
            return null;
        }
        if (self::$current === null) {
            $row = Db::one('SELECT id, username FROM admins WHERE id = ?', [$id]);
            if ($row === null) {
                unset($_SESSION[self::KEY]);
                return null;
            }
            self::$current = ['id' => (int) $row['id'], 'username' => (string) $row['username']];
        }
        return self::$current;
    }

    /** Нет сессии: 302 на /admin/login и выход. */
    public static function requireAdmin(): array
    {
        $admin = self::currentAdmin();
        if ($admin === null) {
            self::redirect('/admin/login');
        }
        return $admin;
    }

    public static function redirect(string $to): never
    {
        http_response_code(302);
        header('Location: ' . $to);
        exit;
    }
}
