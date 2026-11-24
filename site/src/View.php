<?php

declare(strict_types=1);

namespace SiteGate;

final class View
{
    public static function templatesDir(): string
    {
        return dirname(__DIR__) . '/templates';
    }

    /**
     * Рендер templates/<name>.php внутри templates/layout.php.
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $name, array $data = [], string $layout = 'layout'): string
    {
        $content = self::partial($name, $data);
        return self::partial($layout, $data + ['title' => Settings::get('server_name', 'MyServer')] + ['content' => $content]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function partial(string $name, array $data = []): string
    {
        if (!preg_match('#^[a-zA-Z0-9_/-]+$#', $name)) {
            throw new \InvalidArgumentException('Bad template name');
        }
        $file = self::templatesDir() . '/' . $name . '.php';
        $render = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
            } catch (\Throwable $ex) {
                ob_end_clean();
                throw $ex;
            }
            return (string) ob_get_clean();
        };
        return $render($file, $data);
    }

    public static function notFound(): void
    {
        http_response_code(404);
        echo self::render('errors/404', ['title' => 'Страница не найдена']);
    }
}
