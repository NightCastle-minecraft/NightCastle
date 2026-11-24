<?php

declare(strict_types=1);

/** @var bool $ok */
/** @var string $heading */
/** @var string $text */
?>
<section class="container section auth-page">
    <div class="card form-card">
        <h1 class="form-title"><?= e($heading) ?></h1>
        <p class="alert <?= $ok ? 'alert-ok' : 'alert-error' ?>"><?= e($text) ?></p>
        <p><a class="btn btn-ghost" href="/account">Личный кабинет</a></p>
    </div>
</section>
