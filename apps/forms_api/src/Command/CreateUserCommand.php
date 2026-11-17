<?php

declare(strict_types=1);

namespace App\Command;

use FormsCore\Entity\User;
use FormsCore\Enum\UserRole;
use FormsCore\Repository\UserRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Création d'un compte créateur (ROLE_USER). Pour un administrateur : core-php:create-admin-user.
 */
#[AsCommand(name: 'app:user:create', description: 'Crée un compte créateur (ROLE_USER) ; --admin pour un administrateur.')]
final class CreateUserCommand
{
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Adresse e-mail (identifiant de connexion)')]
        ?string $email = null,
        #[Argument(description: 'Nom affiché')]
        ?string $name = null,
        #[Argument(description: 'Mot de passe (saisie masquée si omis)')]
        ?string $password = null,
        #[Option(description: 'Donne le rôle ROLE_ADMIN (espace Administration)')]
        bool $admin = false,
    ): int {
        $email ??= (string) $io->ask('Adresse e-mail');
        $name ??= (string) $io->ask('Nom affiché');
        $password ??= (string) $io->askHidden('Mot de passe');

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $io->error(\sprintf('Le mot de passe doit contenir au moins %d caractères.', self::MIN_PASSWORD_LENGTH));

            return Command::INVALID;
        }

        if (null !== $this->userRepository->findOneByEmail($email)) {
            $io->error(\sprintf('Un compte existe déjà avec l\'adresse « %s ».', $email));

            return Command::FAILURE;
        }

        $user = (new User())
            ->setEmail($email)
            ->setDisplayName($name)
            ->setRoles($admin ? [UserRole::Admin->value] : []);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error($violation->getPropertyPath().' : '.$violation->getMessage());
            }

            return Command::INVALID;
        }

        $this->userRepository->save($user);
        $io->success(\sprintf('Compte %s créé : %s', $admin ? 'administrateur' : 'créateur', $user->getEmail()));

        return Command::SUCCESS;
    }
}
