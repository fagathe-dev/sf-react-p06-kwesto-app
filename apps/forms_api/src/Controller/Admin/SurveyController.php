<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\RespondsWithServiceResult;
use FormsCore\Service\SurveyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Module 1 — conception du questionnaire (specs_fonc §3).
 */
#[Route('/admin/surveys', name: 'api_admin_surveys_')]
final class SurveyController extends AbstractController
{
    use RespondsWithServiceResult;

    public function __construct(private readonly SurveyService $surveys)
    {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->respond($this->surveys->list($request));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->respond($this->surveys->create($request));
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return $this->respond($this->surveys->show($id));
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => Requirement::UUID], methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        return $this->respond($this->surveys->updateMetadata($id, $request));
    }

    /**
     * Enregistre toute la structure (sections + questions ordonnées) d'un brouillon.
     */
    #[Route('/{id}/structure', name: 'structure', requirements: ['id' => Requirement::UUID], methods: ['PUT'])]
    public function structure(string $id, Request $request): JsonResponse
    {
        return $this->respond($this->surveys->saveStructure($id, $request));
    }

    #[Route('/{id}/validate', name: 'validate', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function validate(string $id): JsonResponse
    {
        return $this->respond($this->surveys->validate($id));
    }

    #[Route('/{id}/duplicate', name: 'duplicate', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function duplicate(string $id): JsonResponse
    {
        return $this->respond($this->surveys->duplicate($id));
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        return $this->respond($this->surveys->delete($id));
    }
}
