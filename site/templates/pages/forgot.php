<?php

declare(strict_types=1);

/** @var string|null $error */
/** @var bool $sent */
/** @var string $name */
/** @var string $csrf */
?>
<section class="container section auth-page">
    <div class="card form-card">
        <h1 class="form-title">Восстановление пароля</h1>
<?php if ($sent) : ?>
        <p class="alert alert-ok" role="status">Если у аккаунта <?= e($name) ?> есть подтверждённая почта, письмо со ссылкой уже в пути. Ссылка действует 1 час.</p>
        <p class="muted small">Письма нет? Загляните в «Спам». Почта не была подтверждена — напишите администрации в Discord.</p>
<?php else : ?>
<?php if ($error !== null) : ?>
        <p class="alert alert-error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
        <p>Введите ник: на почту аккаунта придёт ссылка для смены пароля.</p>
        <form method="post" action="/password/forgot" class="form" novalidate>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label class="label" for="f-name">Ник в игре</label>
            <input class="input" id="f-name" name="name" type="text" required maxlength="16" autocomplete="username" value="<?= e($name) ?>" autofocus>
            <button class="btn btn-primary" type="submit">Отправить ссылку</button>
        </form>
<?php endif; ?>
        <p class="muted small"><a href="/login">← Ко входу</a></p>
    </div>
</section>
