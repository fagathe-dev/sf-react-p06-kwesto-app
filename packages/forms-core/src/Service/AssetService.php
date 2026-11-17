<?php

declare(strict_types=1);

namespace FormsCore\Service;

use Fagathe\CorePhp\Http\ResponseTrait;
use Fagathe\CorePhp\Uploader\UploaderValidationService;
use FormsCore\DTO\QuestionConfig\FileConfig;
use FormsCore\Entity\SurveyRecipient;
use FormsCore\Entity\UploadedAsset;
use FormsCore\Enum\QuestionType;
use FormsCore\QuestionType\FileHandler;
use FormsCore\Repository\QuestionRepository;
use FormsCore\Repository\UploadedAssetRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Mime\MimeTypes;

/**
 * Téléversement des fichiers d'une question FILE (specs_fonc §4.5) et téléchargement par le créateur.
 *
 * Le fichier est déposé avant la soumission : il reste orphelin jusqu'à l'envoi du formulaire,
 * puis il est rattaché à la réponse (ParticipantService). Les orphelins sont purgés après 24 h.
 */
final class AssetService
{
    use ResponseTrait;

    public function __construct(
        private readonly QuestionRepository $questionRepository,
        private readonly UploadedAssetRepository $assetRepository,
        private readonly FileHandler $fileHandler,
        private readonly AssetStorage $storage,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Dépôt d'un fichier (champ multipart `file`) pour une question, par un destinataire autorisé à répondre.
     */
    public function upload(SurveyRecipient $recipient, string $questionId, Request $request): object
    {
        $survey = $recipient->getSurvey();
        $question = $this->questionRepository->findOneInSurvey($survey, $questionId);

        if (null === $question || QuestionType::File !== $question->getType()) {
            return $this->notFoundResponse('Question introuvable.');
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->sendViolations(['file' => 'Aucun fichier reçu.']);
        }

        if (!$file->isValid()) {
            // Fichier coupé par PHP (upload_max_filesize / post_max_size) ou transfert interrompu
            return $this->sendViolations(['file' => 'Le fichier n\'a pas pu être reçu : il dépasse peut-être la taille autorisée.']);
        }

        $config = FileConfig::fromArray($question->getConfig());
        $maxBytes = $this->fileHandler->effectiveMaxBytes($question);

        if (\count($this->assetRepository->findPendingForAnswer($recipient, $question)) >= $config->maxFiles) {
            return $this->sendViolations(['file' => \sprintf('Vous avez déjà déposé le nombre maximal de fichiers (%d).', $config->maxFiles)]);
        }

        // Type MIME réel (lecture du contenu) et poids : validateur de core-php, instance dédiée à la question
        $validation = (new UploaderValidationService($config->allowedMimeTypes, $maxBytes))->validate($file);
        if (true !== $validation) {
            return $this->sendViolations(['file' => implode(' ', $validation)]);
        }

        $mimeType = (string) $file->getMimeType();
        $extension = MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? strtolower($file->getClientOriginalExtension());
        $size = (int) $file->getSize();
        $originalName = self::safeName($file->getClientOriginalName(), $extension);

        $storedPath = $this->storage->store($file, \sprintf('surveys/%s/%s', $survey->getId()->toRfc4122(), $question->getId()->toRfc4122()), $extension);

        $asset = new UploadedAsset($recipient, $question, $originalName, $storedPath, $mimeType, $extension, $size, $this->clock->now());
        $this->assetRepository->save($asset);

        return $this->sendJson($asset, Response::HTTP_CREATED, context: ['groups' => ['asset.read']]);
    }

    /**
     * Retrait d'un fichier déposé, tant que le formulaire n'est pas envoyé.
     */
    public function deletePending(SurveyRecipient $recipient, string $assetId): object
    {
        $asset = $this->assetRepository->findPendingOne($recipient, $assetId);
        if (null === $asset) {
            return $this->notFoundResponse('Fichier introuvable.');
        }

        $this->storage->delete($asset->getStoredPath());
        $this->assetRepository->remove($asset);

        return $this->sendNoContent();
    }

    /**
     * Supprime tous les fichiers déposés et non envoyés d'un participant (il recommence le questionnaire).
     */
    public function deleteAllPending(SurveyRecipient $recipient): int
    {
        $assets = $this->assetRepository->findBy(['recipient' => $recipient, 'answer' => null]);
        foreach ($assets as $asset) {
            $this->storage->delete($asset->getStoredPath());
            $this->assetRepository->remove($asset);
        }

        return \count($assets);
    }

    /**
     * Téléchargement (ou affichage, `inline`) d'un fichier rattaché, pour le créateur.
     */
    public function download(UploadedAsset $asset, bool $inline = false): Response
    {
        $path = $this->storage->absolutePath($asset->getStoredPath());
        if (null === $path) {
            return new Response('Fichier introuvable.', Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $asset->getMimeType());
        // Le contenu déposé par un participant ne doit jamais être interprété (HTML, SVG…)
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        $response->setContentDisposition(
            $inline && $asset->isImage() ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $asset->getOriginalName(),
            self::asciiName($asset->getOriginalName()),
        );

        return $response;
    }

    /**
     * Nom d'origine nettoyé (aucun chemin, aucun caractère de contrôle), extension réelle imposée.
     */
    private static function safeName(string $clientName, string $extension): string
    {
        $base = pathinfo(basename(str_replace('\\', '/', $clientName)), \PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '', $base));
        $base = '' === $base ? 'fichier' : mb_substr($base, 0, 180);

        return '' !== $extension ? $base.'.'.$extension : $base;
    }

    private static function asciiName(string $name): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name));

        return '' === trim($ascii, '-.') ? 'fichier' : $ascii;
    }
}
