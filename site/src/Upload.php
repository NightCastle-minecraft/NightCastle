<?php

declare(strict_types=1);

namespace SiteGate;

final class Upload
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Допустимые MIME и расширение, которое выбирается по реальному MIME, а не по имени файла. */
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public static function defaultDir(): string
    {
        return dirname(__DIR__) . '/public/uploads';
    }

    /** Файл не выбран в форме. */
    public static function isEmpty(?array $file): bool
    {
        return $file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE;
    }

    /**
     * Сохраняет картинку из элемента $_FILES.
     * Возвращает ТОЛЬКО имя файла (в БД хранится оно, URL = /uploads/<имя>).
     *
     * @param array<string, mixed> $file
     * @param bool $httpUpload false только для тестов (файл не из HTTP-загрузки)
     * @throws UploadException
     */
    public static function saveImage(array $file, ?string $dir = null, bool $httpUpload = true): string
    {
        $dir ??= self::defaultDir();
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            throw new UploadException(match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой (максимум 5 МБ)',
                UPLOAD_ERR_NO_FILE => 'Файл не выбран',
                default => 'Не удалось загрузить файл',
            });
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || ($httpUpload && !is_uploaded_file($tmp)) || !is_file($tmp)) {
            throw new UploadException('Не удалось загрузить файл');
        }
        $size = filesize($tmp);
        if ($size === false || $size === 0 || $size > self::MAX_BYTES) {
            throw new UploadException('Файл пустой или больше 5 МБ');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        if (!is_string($mime) || !isset(self::TYPES[$mime])) {
            throw new UploadException('Допустимы только изображения JPG, PNG и WebP');
        }
        $info = @getimagesize($tmp);
        if ($info === false || ($info['mime'] ?? '') !== $mime || $info[0] < 1 || $info[1] < 1) {
            throw new UploadException('Файл не является корректным изображением');
        }

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new UploadException('Не удалось сохранить файл');
        }
        $name = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        $dest = rtrim($dir, '/') . '/' . $name;
        $moved = $httpUpload ? @move_uploaded_file($tmp, $dest) : @copy($tmp, $dest);
        if (!$moved) {
            throw new UploadException('Не удалось сохранить файл');
        }
        @chmod($dest, 0644);
        return $name;
    }

    /** Имя файла в формате, который выдаёт saveImage (защита от мусора в БД). */
    public static function isValidName(string $name): bool
    {
        return preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $name) === 1;
    }
}
