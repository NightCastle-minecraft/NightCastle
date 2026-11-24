<?php

declare(strict_types=1);

namespace SiteGate\Tests;

use SiteGate\Controller\AuthApiController;
use SiteGate\Players;
use PHPUnit\Framework\TestCase;

final class PlayersTest extends TestCase
{
    public function testNickRules(): void
    {
        foreach (['Steve', 'abc', 'A_b_9', str_repeat('x', 16)] as $ok) {
            $this->assertTrue(Players::validName($ok), $ok);
        }
        foreach (['ab', str_repeat('x', 17), 'Стив', 'a b', 'a-b', "abc\n", ''] as $bad) {
            $this->assertFalse(Players::validName($bad), $bad);
        }
    }

    public function testPasswordRules(): void
    {
        $this->assertNull(Players::passwordError('correct horse', 'correct horse', 'Steve'));
        $this->assertNotNull(Players::passwordError('short', 'short', 'Steve'));
        $this->assertNotNull(Players::passwordError('Steve123', 'Steve123', 'steve123'), 'совпадает с ником');
        $this->assertNotNull(Players::passwordError('longenough', 'longenougH', 'Steve'), 'не совпали');
        $this->assertNotNull(Players::passwordError(str_repeat('a', 129), str_repeat('a', 129), 'Steve'));
        $this->assertNull(Players::passwordError('пароль-кириллицей', 'пароль-кириллицей', 'Steve'));
    }

    public function testRandomPassword(): void
    {
        $p = Players::randomPassword();
        $this->assertSame(14, strlen($p));
        $this->assertMatchesRegularExpression('/^[a-zA-Z2-9]+$/', $p);
        $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $p);
    }

    public function testBearerFromServerAndHeaders(): void
    {
        $this->assertSame('abc', AuthApiController::bearer(['HTTP_AUTHORIZATION' => 'Bearer abc'], []));
        $this->assertSame('abc', AuthApiController::bearer(['REDIRECT_HTTP_AUTHORIZATION' => 'bearer abc '], []));
        $this->assertSame('xyz', AuthApiController::bearer([], ['authorization' => 'Bearer xyz']));
        $this->assertSame('', AuthApiController::bearer(['HTTP_AUTHORIZATION' => 'Basic abc'], []));
        $this->assertSame('', AuthApiController::bearer(['HTTP_AUTHORIZATION' => 'Bearer a b'], []));
        $this->assertSame('', AuthApiController::bearer([], []));
    }
}
