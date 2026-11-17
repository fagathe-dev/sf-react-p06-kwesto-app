<?php

declare(strict_types=1);

namespace FormsCore\Security\Voter;

use FormsCore\Entity\Survey;
use FormsCore\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Un questionnaire n'est accessible qu'à son créateur. L'administrateur a les droits du créateur
 * sur ses propres questionnaires, plus l'espace Administration (specs_fonc §1).
 *
 * @extends Voter<string, Survey>
 */
final class SurveyVoter extends Voter
{
    public const VIEW = 'SURVEY_VIEW';
    public const EDIT = 'SURVEY_EDIT';
    public const DELETE = 'SURVEY_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Survey && \in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $subject->getOwner()->getId()->equals($user->getId());
    }
}
