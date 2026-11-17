<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\RespondsWithServiceResult;
use FormsCore\Service\DistributionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Module 2 — liste de diffusion, lancement et clôture (specs_fonc §5 et §7.1).
 */
#[Route('/admin/surveys/{id}', name: 'api_admin_distribution_', requirements: ['id' => Requirement::UUID])]
final class DistributionController extends AbstractController
{
    use RespondsWithServiceResult;

    public function __construct(private readonly DistributionService $distribution)
    {
    }

    #[Route('/recipients', name: 'recipients', methods: ['GET'])]
    public function recipients(string $id, Request $request): JsonResponse
    {
        return $this->respond($this->distribution->listRecipients($id, $request));
    }

    /**
     * JSON { emails } ou multipart avec un fichier CSV (`file`).
     */
    #[Route('/recipients', name: 'recipients_add', methods: ['POST'])]
    public function addRecipients(string $id, Request $request): JsonResponse
    {
        return $this->respond($this->distribution->addRecipients($id, $request));
    }

    #[Route('/recipients', name: 'recipients_remove', methods: ['DELETE'])]
    public function removeRecipients(string $id, Request $request): JsonResponse
    {
        return $this->respond($this->distribution->removeRecipients($id, $request));
    }

    #[Route('/launch', name: 'launch', methods: ['POST'])]
    public function launch(string $id, Request $request): JsonResponse
    {
        return $this->respond($this->distribution->launch($id, $request));
    }

    #[Route('/close', name: 'close', methods: ['POST'])]
    public function close(string $id): JsonResponse
    {
        return $this->respond($this->distribution->closeManually($id));
    }
}
