<?php

declare(strict_types=1);

use SiteGate\AdminLog;
use SiteGate\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

const MIN_PASSWORD_LENGTH = 12;

$args = array_slice($argv, 1);
$reset = in_array('--reset', $args, true);
$positional = array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--')));
if (count($positional) !== 1) {
    fwrite(STDERR, "Использование: php bin/create-admin.php <логин> [--reset]\n");
    exit(1);
}
$username = trim($positional[0]);
if ($username === '' || mb_strlen($username) > 64) {
    fwrite(STDERR, "Логин: от 1 до 64 символов.\n");
    exit(1);
}

$existing = Db::one('SELECT id FROM admins WHERE username = ?', [$username]);
if ($existing !== null && !$reset) {
    fwrite(STDERR, "Админ «{$username}» уже существует. Чтобы сменить пароль, добавь флаг --reset.\n");
    exit(1);
}
if ($existing === null && $reset) {
    fwrite(STDERR, "Админа «{$username}» нет, --reset не нужен. Запусти без флага.\n");
    exit(1);
}

function promptHidden(string $prompt): string
{
    $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
    fwrite(STDERR, $prompt);
    if ($tty) {
        shell_exec('stty -echo');
    }
    try {
        $line = fgets(STDIN);
    } finally {
        if ($tty) {
            shell_exec('stty echo');
            fwrite(STDERR, "\n");
        }
    }
    return $line === false ? '' : rtrim($line, "\r\n");
}

$password = promptHidden('Пароль (минимум ' . MIN_PASSWORD_LENGTH . ' символов): ');
if (mb_strlen($password) < MIN_PASSWORD_LENGTH) {
    fwrite(STDERR, 'Пароль короче ' . MIN_PASSWORD_LENGTH . " символов.\n");
    exit(1);
}
if ($password !== promptHidden('Повтори пароль: ')) {
    fwrite(STDERR, "Пароли не совпадают.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
if ($existing === null) {
    Db::pdo()->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')->execute([$username, $hash]);
    $id = (int) Db::pdo()->lastInsertId();
    AdminLog::write($id, 'admin_create', 'username=' . $username . ' (cli)');
    echo "Админ «{$username}» создан.\n";
} else {
    $id = (int) $existing['id'];
    Db::pdo()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
    AdminLog::write($id, 'admin_password_reset', 'username=' . $username . ' (cli)');
    echo "Пароль админа «{$username}» обновлён.\n";
}
