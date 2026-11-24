<?php

declare(strict_types=1);

namespace SiteGate;

/** Проверка формы товара в админке (без БД). */
final class ShopProductValidator
{
    /**
     * @param array<string, mixed> $input
     * @return array{values: array{code: string, title: string, category: string, price: int, description_md: string, position: int, is_active: int}, errors: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $str = static fn (string $k): string => is_string($input[$k] ?? null) ? trim($input[$k]) : '';
        $errors = [];
        $code = strtolower($str('code'));
        if (!Shop::validCode($code)) {
            $errors['code'] = 'Код: 2–64 символа, латиница в нижнем регистре, цифры и _. Как имя файла products/<код>.yml в плагине.';
        }
        $title = $str('title');
        if ($title === '' || mb_strlen($title) > 120) {
            $errors['title'] = 'Название: от 1 до 120 символов.';
        }
        $category = $str('category');
        if (mb_strlen($category) > 60) {
            $errors['category'] = 'Категория: до 60 символов.';
        }
        $priceRaw = $str('price');
        $price = ctype_digit($priceRaw) ? (int) $priceRaw : -1;
        if ($price < 1 || $price > Shop::MAX_PRICE) {
            $errors['price'] = 'Цена: целое число от 1 до ' . Shop::MAX_PRICE . '.';
        }
        $description = $str('description_md');
        if (mb_strlen($description) > 5000) {
            $errors['description_md'] = 'Описание: до 5000 символов.';
        }
        $positionRaw = $str('position');
        $position = preg_match('/^-?\d{1,6}$/D', $positionRaw) === 1 ? (int) $positionRaw : 0;
        return [
            'values' => [
                'code' => $code,
                'title' => $title,
                'category' => $category,
                'price' => max(0, $price),
                'description_md' => $description,
                'position' => $position,
                'is_active' => !empty($input['is_active']) ? 1 : 0,
            ],
            'errors' => $errors,
        ];
    }
}
