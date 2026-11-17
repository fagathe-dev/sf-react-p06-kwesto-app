<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Site vitrine : pages publiques, référencement (specs_fonc §11.4) et formulaire de contact.
 */
final class SiteTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        foreach (['/', '/fonctionnalites', '/cas-usage', '/tarifs', '/contact', '/mentions-legales', '/confidentialite'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('pages')]
    public function testPublicPageExposesSeoMetadata(string $path): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('h1')->count(), 'Un seul titre h1 par page.');
        self::assertNotEmpty($crawler->filter('title')->text());
        self::assertNotEmpty($crawler->filter('meta[name="description"]')->attr('content'));
        self::assertSame('https://kwesto.test'.('/' === $path ? '/' : $path), $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertCount(1, $crawler->filter('meta[property="og:title"]'));
        self::assertCount(1, $crawler->filter('meta[name="twitter:card"]'));
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringContainsString('"SoftwareApplication"', $crawler->filter('script[type="application/ld+json"]')->first()->text());

        // Le site vitrine est indexable : aucun en-tête noindex
        self::assertFalse($client->getResponse()->headers->has('X-Robots-Tag'));

        // Ni Stimulus, ni Turbo, ni AssetMapper (specs_tech §5.1.1)
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('data-controller', $html);
        self::assertStringNotContainsString('data-action', $html);
        self::assertStringNotContainsString('importmap', $html);
        self::assertStringContainsString('/css/ds-site.css', $html);
        self::assertStringContainsString('/js/main.js', $html);
    }

    public function testPricingPageDeclaresFaqStructuredData(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/tarifs');

        $jsonLd = $crawler->filter('script[type="application/ld+json"]')->each(static fn ($node) => json_decode($node->text(), true));
        $faq = array_values(array_filter($jsonLd, static fn (?array $data) => 'FAQPage' === ($data['@type'] ?? null)))[0] ?? null;

        self::assertNotNull($faq);
        self::assertNotEmpty($faq['mainEntity']);
        self::assertSame('Question', $faq['mainEntity'][0]['@type']);
    }

    public function testSitemapListsEveryPublicPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');

        $xml = new \SimpleXMLElement((string) $client->getResponse()->getContent());
        $locations = [];
        foreach ($xml->url as $url) {
            $locations[] = (string) $url->loc;
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $url->lastmod);
            self::assertNotSame('', (string) $url->priority);
        }

        self::assertCount(7, $locations);
        self::assertContains('https://kwesto.test/tarifs', $locations);
    }

    public function testRobotsTxtPointsToSitemap(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sitemap: https://kwesto.test/sitemap.xml', (string) $client->getResponse()->getContent());
    }

    public function testCallToActionTargetsBackOffice(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertGreaterThan(0, $crawler->filter('a[href="https://app.kwesto.test/login"]')->count());
    }

    public function testContactFormSendsEmailThenRedirects(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/contact');

        $form = $crawler->selectButton('Envoyer le message')->form([
            'contact[name]' => 'Camille Martin',
            'contact[email]' => 'camille@example.com',
            'contact[subject]' => 'Demande de démonstration',
            'contact[message]' => 'Bonjour, nous aimerions une démonstration pour notre équipe RH.',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/contact?envoye=1');
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'To', 'contact@kwesto.test');
        self::assertEmailHeaderSame($email, 'Reply-To', 'Camille Martin <camille@example.com>');

        $client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Merci, votre message a bien été envoyé.');
    }

    public function testContactFormRejectsInvalidInput(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/contact');

        $client->submit($crawler->selectButton('Envoyer le message')->form([
            'contact[name]' => 'Camille',
            'contact[email]' => 'pas-une-adresse',
            'contact[subject]' => 'Autre',
            'contact[message]' => 'Court',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
        self::assertSelectorTextContains('.field--invalid', 'L\'adresse e-mail est invalide.');
    }

    public function testHoneypotSilentlyDropsBots(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/contact');

        $client->submit($crawler->selectButton('Envoyer le message')->form([
            'contact[name]' => 'Robot',
            'contact[email]' => 'robot@example.com',
            'contact[subject]' => 'Autre',
            'contact[message]' => 'Message automatique de plus de dix caractères.',
            'contact[website]' => 'http://spam.example',
        ]));

        self::assertResponseRedirects('/contact?envoye=1');
        self::assertEmailCount(0);
    }
}
