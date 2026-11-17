<?php

declare(strict_types=1);

namespace FormsCore\Service;

use FormsCore\Entity\Survey;
use FormsCore\Entity\SurveyRecipient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Liens absolus vers le back-office (forms_bo), utilisés dans les e-mails.
 * Le lien personnel d'un participant est servi par le back-office : {APP_BO_URL}/s/{token}.
 */
final class LinkGenerator
{
    private readonly string $boUrl;

    public function __construct(
        #[Autowire(param: 'forms_core.url.bo')]
        ?string $boUrl,
    ) {
        $this->boUrl = rtrim((string) $boUrl, '/');
    }

    public function participantUrl(SurveyRecipient $recipient): string
    {
        return $this->participantUrlForToken((string) $recipient->getToken());
    }

    public function participantUrlForToken(string $token): string
    {
        return $this->bo('/s/'.$token);
    }

    public function recipientsUrl(Survey $survey): string
    {
        return $this->bo(\sprintf('/surveys/%s/recipients', $survey->getId()->toRfc4122()));
    }

    public function statsUrl(Survey $survey): string
    {
        return $this->bo(\sprintf('/surveys/%s/stats', $survey->getId()->toRfc4122()));
    }

    public function bo(string $path = '/'): string
    {
        return $this->boUrl.'/'.ltrim($path, '/');
    }
}
