<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\ContactType;
use App\Seo\PageCatalog;
use FormsCore\DTO\Mail\ContactRequestMailDto;
use FormsCore\Service\ContactService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Formulaire de contact (specs_tech §5.2), traité par ContactService (forms-core) et le mailer de core-php.
 */
final class ContactController extends AbstractController
{
    public function __construct(
        private readonly PageCatalog $pages,
        private readonly ContactService $contactService,
        #[Autowire(service: 'limiter.contact_form')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/contact', name: 'app_contact', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(ContactType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name: ?string, email: ?string, subject: ?string, message: ?string, website: ?string} $data */
            $data = $form->getData();

            // Robot (pot de miel rempli) : réponse identique à un envoi réussi, rien n'est transmis
            if ('' !== trim((string) $data['website'])) {
                return $this->redirectToRoute('app_contact', ['envoye' => 1]);
            }

            if (!$this->limiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
                $form->addError(new FormError('Trop de messages envoyés. Merci de réessayer dans quelques minutes.'));
            } else {
                $result = $this->contactService->send(new ContactRequestMailDto(
                    name: trim((string) $data['name']),
                    email: trim((string) $data['email']),
                    subject: trim((string) $data['subject']),
                    message: trim((string) $data['message']),
                ));

                if ($result['sent']) {
                    // Post/Redirect/Get : un rafraîchissement ne renvoie pas le message
                    return $this->redirectToRoute('app_contact', ['envoye' => 1]);
                }

                foreach ($result['errors'] as $field => $message) {
                    $form->has($field) ? $form->get($field)->addError(new FormError($message)) : $form->addError(new FormError($message));
                }

                if ([] === $result['errors']) {
                    $form->addError(new FormError('Votre message n\'a pas pu être envoyé. Réessayez dans un instant ou écrivez-nous directement.'));
                }
            }
        }

        $response = $this->render('pages/contact.html.twig', [
            'seo' => $this->pages->get('app_contact'),
            'form' => $form,
            'sent' => $request->query->getBoolean('envoye'),
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));

        // Formulaire avec jeton CSRF : jamais mis en cache
        $response->setPrivate()->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
