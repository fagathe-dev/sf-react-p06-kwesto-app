<?php

declare(strict_types=1);

namespace FormsCore\Service;

use FormsCore\Entity\Survey;
use FormsCore\Entity\User;
use FormsCore\Repository\SurveyRepository;
use FormsCore\Security\Voter\SurveyVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Résout l'utilisateur connecté et les questionnaires auxquels il a droit.
 */
final class SurveyAccess
{
    public function __construct(
        private readonly SurveyRepository $surveyRepository,
        private readonly Security $security,
    ) {
    }

    public function currentUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentification requise.');
        }

        return $user;
    }

    /**
     * @throws NotFoundHttpException questionnaire inexistant, ou appartenant à un autre créateur
     *                               (même réponse : l'existence d'un questionnaire n'est pas révélée)
     */
    public function get(string $id, string $attribute = SurveyVoter::VIEW): Survey
    {
        $survey = $this->surveyRepository->findOneById($id);

        if (null === $survey || !$this->security->isGranted($attribute, $survey)) {
            throw new NotFoundHttpException('Questionnaire introuvable.');
        }

        return $survey;
    }
}
