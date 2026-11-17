<?php

declare(strict_types=1);

namespace FormsCore;

use DoctrineExtensions\Query\Mysql\MatchAgainst;
use Fagathe\CorePhp\Generator\TokenGenerator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Bundle partagé par forms_api et forms_front.
 *
 * - Mapping Doctrine des entités de src/Entity (aucune déclaration dans les apps) ;
 * - fonction DQL MATCH_AGAINST (recherche plein texte, voir Trait\FullTextSearchTrait) ;
 * - paramètres forms_core.* lus depuis l'environnement (specs_tech §8) ;
 * - autowiring de tous les services du package.
 *
 * Les templates d'e-mails de templates/ sont exposés sous le namespace Twig @FormsCore.
 */
final class FormsCoreBundle extends AbstractBundle
{
    /**
     * Racine du package : Twig y trouve templates/ (namespace @FormsCore).
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'FormsCore' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => \dirname(__DIR__).'/src/Entity',
                        'prefix' => 'FormsCore\Entity',
                        'alias' => 'FormsCore',
                    ],
                ],
                'dql' => [
                    'string_functions' => [
                        'MATCH_AGAINST' => MatchAgainst::class,
                    ],
                ],
            ],
        ]);
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $parameters = $container->parameters();

        // Limite d'upload de la plateforme (specs_tech §8.5) : source unique UPLOAD_MAX_SIZE_MB, défaut 10 Mo
        $parameters->set('forms_core.upload.default_max_size_mb', '10');
        $parameters->set('forms_core.upload.max_size_mb', '%env(int:default:forms_core.upload.default_max_size_mb:UPLOAD_MAX_SIZE_MB)%');

        // Stockage privé des fichiers déposés par les participants (jamais servi directement par le serveur web)
        $parameters->set('forms_core.upload.default_storage_dir', '%kernel.project_dir%/var/uploads');
        $parameters->set('forms_core.upload.storage_dir', '%env(resolve:default:forms_core.upload.default_storage_dir:UPLOAD_STORAGE_DIR)%');

        // URLs des applications (liens des e-mails, CTA du site vitrine)
        $parameters->set('forms_core.url.bo', '%env(default::APP_BO_URL)%');
        $parameters->set('forms_core.url.front', '%env(default::APP_FRONT_URL)%');

        // Destinataire du formulaire de contact du site vitrine
        $parameters->set('forms_core.contact.recipient', '%env(default::CONTACT_RECIPIENT_EMAIL)%');

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        // Utilitaire de core-php sans dépendance : déclaré ici s'il ne l'est pas déjà
        if (!$builder->hasDefinition(TokenGenerator::class)) {
            $services->set(TokenGenerator::class);
        }

        $services->load('FormsCore\\', __DIR__.'/')
            ->exclude([
                __DIR__.'/DTO',
                __DIR__.'/Entity',
                __DIR__.'/Enum',
                __DIR__.'/Exception',
                __DIR__.'/Message',
                __DIR__.'/Trait',
                __DIR__.'/Util',
                __DIR__.'/FormsCoreBundle.php',
            ]);
    }
}
