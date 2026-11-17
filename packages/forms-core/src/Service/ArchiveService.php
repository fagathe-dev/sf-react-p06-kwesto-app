<?php

declare(strict_types=1);

namespace FormsCore\Service;

use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use FormsCore\Entity\Survey;
use FormsCore\Entity\UploadedAsset;
use FormsCore\Enum\LogChannel;
use FormsCore\Repository\SurveyRepository;
use FormsCore\Repository\UploadedAssetRepository;
use FormsCore\Trait\ChannelLoggerTrait;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\String\Slugger\AsciiSlugger;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * Archive .zip consolidée des pièces jointes d'un questionnaire (specs_fonc §4.5, §7.2 et §9.2).
 *
 * Arborescence : Q{position}-{intitulé}/{adresse du répondant}/{nom d'origine}.
 * Les sondages clôturés sont servis depuis l'archive pré-générée la nuit ; sinon l'archive est
 * produite à la volée, en streaming (aucun fichier temporaire, mémoire constante).
 */
final class ArchiveService
{
    use ChannelLoggerTrait;

    public function __construct(
        private readonly UploadedAssetRepository $assetRepository,
        private readonly SurveyRepository $surveyRepository,
        private readonly AssetStorage $storage,
        private readonly ClockInterface $clock,
    ) {
    }

    public function hasFiles(Survey $survey): bool
    {
        return $this->assetRepository->countAttachedBySurvey($survey) > 0;
    }

    public function download(Survey $survey): Response
    {
        $fileName = $this->archiveName($survey);
        $prebuilt = null !== $survey->getArchivePath() ? $this->storage->absolutePath((string) $survey->getArchivePath()) : null;

        if (null !== $prebuilt) {
            $response = new BinaryFileResponse($prebuilt);
            $response->headers->set('Content-Type', 'application/zip');
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $fileName);

            return $response;
        }

        $assets = $this->assetRepository->findAttachedBySurvey($survey);

        $response = new StreamedResponse(function () use ($assets): void {
            $output = fopen('php://output', 'wb');
            $this->write($assets, $output);
        });

        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName));
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * Pré-génère l'archive d'un sondage clôturé (tâche de 03h00).
     */
    public function build(Survey $survey): bool
    {
        $assets = $this->assetRepository->findAttachedBySurvey($survey);
        if ([] === $assets) {
            return false;
        }

        $relativePath = \sprintf('archives/%s/%s', $survey->getId()->toRfc4122(), $this->archiveName($survey));
        $absolutePath = $this->storage->writablePath($relativePath);

        $output = fopen($absolutePath, 'wb');
        if (false === $output) {
            return false;
        }

        try {
            $this->write($assets, $output);
        } catch (\Throwable $e) {
            @unlink($absolutePath);
            $this->logTo(LogChannel::Cron, LoggerLevelEnum::Error, 'Archive ZIP non générée.', 'archive.build_error', ['survey' => $survey->getId()->toRfc4122()], $e);

            return false;
        } finally {
            if (\is_resource($output)) {
                fclose($output);
            }
        }

        $survey->setArchivePath($relativePath);
        $this->surveyRepository->save($survey);

        return true;
    }

    /**
     * @param list<UploadedAsset> $assets
     * @param resource            $output
     */
    private function write(array $assets, $output): void
    {
        $zip = new ZipStream(
            outputStream: $output,
            defaultCompressionMethod: CompressionMethod::DEFLATE,
            sendHttpHeaders: false,
        );

        $slugger = new AsciiSlugger('fr');
        $usedNames = [];

        foreach ($assets as $asset) {
            $path = $this->storage->absolutePath($asset->getStoredPath());
            if (null === $path) {
                continue;
            }

            $question = $asset->getQuestion();
            $folder = \sprintf('Q%02d-%s', $question->getPosition(), mb_substr((string) $slugger->slug($question->getPlainLabel())->lower(), 0, 50));
            $entry = $folder.'/'.self::cleanSegment($asset->getRecipient()->getEmail()).'/'.self::cleanSegment($asset->getOriginalName());

            // Deux fichiers du même nom pour un même répondant : suffixe numérique
            $candidate = $entry;
            for ($i = 2; isset($usedNames[$candidate]); ++$i) {
                $candidate = preg_replace('/(\.[^.\/]+)?$/', '-'.$i.'$1', $entry, 1);
            }
            $usedNames[$candidate] = true;

            $zip->addFileFromPath(fileName: $candidate, path: $path, lastModificationDateTime: $asset->getCreatedAt());
        }

        $zip->finish();
    }

    private function archiveName(Survey $survey): string
    {
        $slug = (string) (new AsciiSlugger('fr'))->slug($survey->getTitle())->lower();

        return \sprintf('kwesto-%s-fichiers-%s.zip', mb_substr($slug, 0, 60), $this->clock->now()->format('Ymd'));
    }

    private static function cleanSegment(string $value): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '_', $value));

        return '' === $clean || '.' === $clean || '..' === $clean ? 'fichier' : $clean;
    }
}
