<?php

declare(strict_types=1);

namespace FormsCore\Service;

use FormsCore\Repository\SurveyRecipientRepository;
use FormsCore\Repository\SurveyRepository;
use FormsCore\Repository\UploadedAssetRepository;
use Psr\Clock\ClockInterface;

/**
 * Maintenance nocturne (specs_fonc §9.2). Chaque méthode est idempotente et renvoie un rapport chiffré.
 */
final class MaintenanceService
{
    public const ORPHAN_TTL = '-24 hours';
    public const ANONYMIZE_AFTER = '-30 days';
    public const ARCHIVE_WINDOW = '-24 hours';
    public const STALE_DRAFT_AFTER = '-60 days';

    public function __construct(
        private readonly SurveyRepository $surveyRepository,
        private readonly SurveyRecipientRepository $recipientRepository,
        private readonly UploadedAssetRepository $assetRepository,
        private readonly AssetStorage $storage,
        private readonly StatsService $statsService,
        private readonly ArchiveService $archiveService,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * 02h00 — fichiers téléversés mais jamais rattachés à une soumission validée depuis plus de 24 h.
     */
    public function purgeOrphanAssets(): int
    {
        $before = $this->clock->now()->modify(self::ORPHAN_TTL);
        $purged = 0;

        do {
            $batch = $this->assetRepository->findOrphansOlderThan($before);

            foreach ($batch as $asset) {
                $this->storage->delete($asset->getStoredPath());
                $this->assetRepository->remove($asset, flush: false);
                ++$purged;
            }

            $this->assetRepository->flush();
            $this->assetRepository->clear();
        } while ([] !== $batch);

        return $purged;
    }

    /**
     * 02h30 — RGPD : IP tronquée de façon irréversible sur les sondages clôturés depuis plus de 30 jours.
     * Navigateur, système et appareil sont conservés (statistiques agrégées).
     */
    public function anonymizeTelemetry(): int
    {
        $now = $this->clock->now();
        $anonymized = 0;

        foreach ($this->surveyRepository->findClosedToAnonymize($now->modify(self::ANONYMIZE_AFTER)) as $survey) {
            foreach ($this->recipientRepository->findBySurvey($survey) as $recipient) {
                if (null !== $recipient->getIpAddress()) {
                    $recipient->anonymizeIp(TelemetryService::anonymizeIp($recipient->getIpAddress()));
                    ++$anonymized;
                }
            }

            $survey->setTelemetryAnonymizedAt($now);
            $this->surveyRepository->flush();
        }

        return $anonymized;
    }

    /**
     * 03h00 — archives ZIP des sondages avec questions FILE clôturés dans les dernières 24 h.
     */
    public function buildArchives(): int
    {
        $built = 0;

        foreach ($this->surveyRepository->findClosedWithoutArchive($this->clock->now()->modify(self::ARCHIVE_WINDOW)) as $survey) {
            if ($this->archiveService->build($survey)) {
                ++$built;
            }
        }

        return $built;
    }

    /**
     * 03h30 — instantanés des indicateurs lourds des sondages actifs volumineux.
     */
    public function refreshStatsSnapshots(): int
    {
        $refreshed = 0;

        foreach ($this->surveyRepository->findActive() as $survey) {
            if ($this->statsService->isLarge($survey)) {
                $this->statsService->snapshot($survey);
                ++$refreshed;
            }
        }

        return $refreshed;
    }

    /**
     * 04h00 (hebdomadaire) — brouillons inactifs depuis plus de 60 jours.
     */
    public function purgeStaleDrafts(): int
    {
        $purged = 0;

        foreach ($this->surveyRepository->findStaleDrafts($this->clock->now()->modify(self::STALE_DRAFT_AFTER)) as $survey) {
            $this->surveyRepository->remove($survey);
            ++$purged;
        }

        return $purged;
    }
}
