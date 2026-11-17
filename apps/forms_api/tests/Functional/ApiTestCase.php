<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use FormsCore\Entity\User;
use FormsCore\Enum\UserRole;
use FormsCore\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Requêtes JSON authentifiées comme le back-office. La base de test (suffixe _test) doit être migrée :
 * APP_ENV=test bin/console doctrine:migrations:migrate.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected const PASSWORD = 'Test!Password2026';

    protected KernelBrowser $client;
    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function createUser(bool $admin = false): User
    {
        $container = static::getContainer();
        $user = (new User())
            ->setEmail(\sprintf('test-%s@kwesto.test', bin2hex(random_bytes(6))))
            ->setDisplayName('Utilisateur de test')
            ->setRoles($admin ? [UserRole::Admin->value] : []);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $container->get(UserRepository::class)->save($user);

        return $user;
    }

    protected function loginAs(User $user): void
    {
        $this->token = null;
        $response = $this->api('POST', '/api/auth/login', ['email' => $user->getEmail(), 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
        $this->token = $response['token'];
    }

    protected function logout(): void
    {
        $this->token = null;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    protected function api(string $method, string $uri, ?array $body = null): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $this->token) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token;
        }

        $this->client->request($method, $uri, server: $headers, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : (array) json_decode($content, true);
    }
}
