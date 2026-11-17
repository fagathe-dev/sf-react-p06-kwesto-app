<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use FormsCore\Entity\User;
use FormsCore\Enum\UserRole;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Comptes de démonstration. Tous partagent le mot de passe PASSWORD (environnement de développement uniquement).
 *
 * | E-mail                    | Rôle       |
 * |---------------------------|------------|
 * | admin@kwesto.test         | ROLE_ADMIN |
 * | camille@kwesto.test       | ROLE_USER  |
 * | alex@kwesto.test          | ROLE_USER  |
 * | sacha@kwesto.test         | ROLE_USER  |
 */
final class AppFixtures extends Fixture
{
    public const PASSWORD = 'Kwesto!2026';

    /** @var list<array{string, string, bool}> e-mail, nom affiché, administrateur */
    public const USERS = [
        ['admin@kwesto.test', 'Dominique Admin', true],
        ['camille@kwesto.test', 'Camille Martin', false],
        ['alex@kwesto.test', 'Alex Durand', false],
        ['sacha@kwesto.test', 'Sacha Lefèvre', false],
    ];

    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::USERS as [$email, $name, $isAdmin]) {
            $user = (new User())
                ->setEmail($email)
                ->setDisplayName($name)
                ->setRoles($isAdmin ? [UserRole::Admin->value] : []);
            $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));

            $manager->persist($user);
            $this->addReference('user_'.$email, $user);
        }

        $manager->flush();
    }
}
