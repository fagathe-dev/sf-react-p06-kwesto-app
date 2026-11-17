<?php

declare(strict_types=1);

namespace FormsCore\DTO\AnswerPayload;

final readonly class FileAnswer implements AnswerPayload
{
    /**
     * @param list<string> $assetIds identifiants (UUID) des UploadedAsset rattachés à la réponse
     */
    public function __construct(public array $assetIds)
    {
    }

    public function toArray(): array
    {
        return ['assetIds' => $this->assetIds];
    }
}
