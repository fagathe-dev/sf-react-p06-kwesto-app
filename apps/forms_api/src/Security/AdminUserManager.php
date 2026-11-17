<?php

declare(strict_types=1);

namespace App\Security;

use Fagathe\CorePhp\Command\AdminUserManagerInterface;
use FormsCore\Entity\User;
use FormsCore\Enum\UserRole;
use FormsCore\Repository\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Branche la commande `core-php:create-admin-user` de core-php sur le modèle User de kwesto :
 *
 *     php bin/console core-php:create-admin-user "Camille Admin" admin@example.com 'MotDePasse!2026'
 *     php bin/console core-php:create-admin-user            # saisie interactive, mot de passe masqué
 *
 * Le « nom d'utilisateur » demandé par la commande devient le nom affiché (displayName) ;
 * la connexion se fait par adresse e-mail.
 */
#[AsAlias(AdminUserManagerInterface::class)]
final class AdminUserManager implements AdminUserManagerInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function getAdminRoles(): array
    {
        // Le premier type est la valeur par défaut
        return ['admin' => UserRole::Admin->value];
    }

    public function usernameExists(string $username): bool
    {
        // Le nom affiché n'est pas un identifiant de connexion : plusieurs comptes peuvent le partager
        return false;
    }

    public function emailExists(string $email): bool
    {
        return null !== $this->userRepository->findOneByEmail($email);
    }

    public function createAdmin(string $username, string $email, string $plainPassword, string $role): void
    {
        $user = (new User())
            ->setEmail($email)
            ->setDisplayName($username)
            ->setRoles([$role]);
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            throw new \InvalidArgumentException((string) $violations->get(0)->getMessage());
        }

        $this->userRepository->save($user);
    }
}
