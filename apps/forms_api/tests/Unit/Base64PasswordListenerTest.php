<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventListener\Base64PasswordListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

final class Base64PasswordListenerTest extends TestCase
{
    public function testDecodesTheBase64PasswordBeforeTheCredentialsCheck(): void
    {
        $passport = $this->dispatch(base64_encode('Été€ Kwesto!2026'));

        /** @var PasswordCredentials $credentials */
        $credentials = $passport->getBadge(PasswordCredentials::class);
        self::assertSame('Été€ Kwesto!2026', $credentials->getPassword());
    }

    public function testRejectsAPasswordThatIsNotBase64(): void
    {
        $this->expectException(BadCredentialsException::class);
        $this->dispatch('Kwesto!2026');
    }

    private function dispatch(string $password): Passport
    {
        $passport = new Passport(new UserBadge('camille@kwesto.test'), new PasswordCredentials($password));
        (new Base64PasswordListener())(new CheckPassportEvent($this->createStub(AuthenticatorInterface::class), $passport));

        return $passport;
    }
}
