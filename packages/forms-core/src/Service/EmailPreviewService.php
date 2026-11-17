<?php

declare(strict_types=1);

namespace FormsCore\Service;

use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use Fagathe\CorePhp\Http\ResponseTrait;
use Fagathe\CorePhp\Mailer\Exception\MailerExceptionInterface;
use FormsCore\Enum\LogChannel;
use FormsCore\Mailer\MailDispatcher;
use FormsCore\Mailer\Preview\MailPreviewInterface;
use FormsCore\Trait\ChannelLoggerTrait;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\BodyRendererInterface;

/**
 * Prévisualisation des templates d'e-mails (specs_fonc §10.1) : templates métier et templates
 * prédéfinis de core-php, rendus avec un jeu de données réaliste et personnalisable.
 *
 * L'e-mail est construit par son handler puis rendu par le BodyRenderer de l'envoi réel :
 * l'aperçu correspond exactement au HTML envoyé.
 */
final class EmailPreviewService
{
    use ChannelLoggerTrait;
    use ResponseTrait;

    /** @var array<string, MailPreviewInterface> */
    private readonly array $previews;

    /**
     * @param iterable<string, MailPreviewInterface> $previews indexés par slug
     */
    public function __construct(
        private readonly MailDispatcher $mailDispatcher,
        private readonly BodyRendererInterface $bodyRenderer,
        private readonly MailerInterface $mailer,
        #[AutowireIterator(MailPreviewInterface::TAG, defaultIndexMethod: 'getSlug')]
        iterable $previews,
    ) {
        $this->previews = iterator_to_array($previews);
    }

    public function list(): object
    {
        $items = [];

        foreach ($this->previews as $slug => $preview) {
            $email = $this->mailDispatcher->createEmail($preview->createDto());

            $items[] = [
                'slug' => $slug,
                'code' => $preview->getCode(),
                'label' => $preview->getLabel(),
                'description' => $preview->getDescription(),
                'audience' => $preview->getAudience(),
                'subject' => $email->getSubject(),
                'from' => self::formatAddresses($email->getFrom()),
                'fields' => $preview->getFields(),
            ];
        }

        return $this->sendJson($items);
    }

    /**
     * HTML rendu de l'aperçu, ou null si le slug est inconnu.
     *
     * @param array<string, mixed> $data valeurs personnalisées du jeu de données
     *
     * @return array{subject: string, html: string}|null
     */
    public function render(string $slug, array $data = []): ?array
    {
        $email = $this->build($slug, $data);
        if (null === $email) {
            return null;
        }

        return ['subject' => (string) $email->getSubject(), 'html' => self::htmlBody($email)];
    }

    /**
     * Envoi d'un e-mail de test à l'adresse de l'administrateur connecté.
     *
     * @param array<string, mixed> $data
     */
    public function sendTest(string $slug, array $data, string $adminEmail): object
    {
        $email = $this->build($slug, $data);
        if (null === $email) {
            return $this->notFoundResponse('Aperçu introuvable.');
        }

        $email->to($adminEmail)->cc()->bcc()->subject('[Test] '.$email->getSubject());

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logTo(LogChannel::Mailer, LoggerLevelEnum::Error, 'E-mail de test non envoyé.', 'email_preview.test_error', ['slug' => $slug], $e);

            return $this->sendJson(['message' => 'L\'e-mail de test n\'a pas pu être envoyé : vérifiez la configuration du mailer.'], Response::HTTP_BAD_GATEWAY);
        }

        $this->logTo(LogChannel::Mailer, LoggerLevelEnum::Info, 'E-mail de test envoyé.', 'email_preview.test_sent', ['slug' => $slug]);

        return $this->sendJson(['message' => \sprintf('E-mail de test envoyé à %s.', $adminEmail)]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function build(string $slug, array $data): ?TemplatedEmail
    {
        $preview = $this->previews[$slug] ?? null;
        if (null === $preview) {
            return null;
        }

        try {
            $email = $this->mailDispatcher->createEmail($preview->createDto($data));
        } catch (MailerExceptionInterface) {
            // Valeurs personnalisées invalides (adresse mal formée…) : repli sur le jeu par défaut
            $email = $this->mailDispatcher->createEmail($preview->createDto());
        }

        $this->bodyRenderer->render($email);

        return $email;
    }

    private static function htmlBody(TemplatedEmail $email): string
    {
        $html = $email->getHtmlBody();

        return \is_resource($html) ? (string) stream_get_contents($html) : (string) $html;
    }

    /**
     * @param Address[] $addresses
     *
     * @return list<array{name: string|null, email: string}>
     */
    private static function formatAddresses(array $addresses): array
    {
        return array_values(array_map(static fn (Address $address): array => [
            'name' => '' !== $address->getName() ? $address->getName() : null,
            'email' => $address->getAddress(),
        ], $addresses));
    }
}
