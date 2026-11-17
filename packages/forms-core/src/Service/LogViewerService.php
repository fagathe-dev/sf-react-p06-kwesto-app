<?php

declare(strict_types=1);

namespace FormsCore\Service;

use Fagathe\CorePhp\Http\ResponseTrait;
use Fagathe\CorePhp\Logger\Log;
use FormsCore\Enum\LogChannel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Consultation des journaux applicatifs sans accès SSH (specs_fonc §10.2).
 *
 * Fichiers lus : <logs_dir>/<canal>/<fichier>-jj-mm-aaaa.json, écrits par le Logger de core-php.
 * Filtres : canal, sévérité, période, recherche textuelle (jeton, identifiant de sondage, e-mail…).
 */
final class LogViewerService
{
    use ResponseTrait;

    /** Sévérités filtrables (DEBUG et NOTICE sont regroupés avec leur voisin le plus proche). */
    public const LEVELS = ['INFO', 'WARNING', 'ERROR', 'CRITICAL'];
    private const LEVEL_ALIASES = ['debug' => 'INFO', 'info' => 'INFO', 'notice' => 'INFO', 'warning' => 'WARNING', 'error' => 'ERROR', 'critical' => 'CRITICAL'];
    private const DATE_PATTERN = '/-(\d{2})-(\d{2})-(\d{4})\.json$/';
    private const DEFAULT_DAYS = 7;
    private const MAX_DAYS = 31;
    private const PAGE_SIZE = 50;

    private readonly string $logsDir;

    public function __construct(
        #[Autowire(param: 'core_php.logger.logs_dir')]
        string $logsDir,
    ) {
        $this->logsDir = rtrim($logsDir, '/\\');
    }

    /**
     * Paramètres : channel, level, from, to (YYYY-MM-DD), q, page.
     */
    public function search(Request $request): object
    {
        $channel = LogChannel::tryFrom((string) $request->query->get('channel', ''));
        $level = strtoupper((string) $request->query->get('level', ''));
        $level = \in_array($level, self::LEVELS, true) ? $level : null;
        $query = mb_strtolower(trim(mb_substr((string) $request->query->get('q', ''), 0, 100)));
        $page = max(1, $request->query->getInt('page', 1));

        $to = self::parseDay((string) $request->query->get('to', '')) ?? new \DateTimeImmutable('today');
        $from = self::parseDay((string) $request->query->get('from', '')) ?? $to->modify(\sprintf('-%d days', self::DEFAULT_DAYS - 1));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ((int) $from->diff($to)->days >= self::MAX_DAYS) {
            $from = $to->modify(\sprintf('-%d days', self::MAX_DAYS - 1));
        }

        $entries = [];
        $levelCounts = array_fill_keys(self::LEVELS, 0);

        foreach ($this->files($channel, $from, $to) as [$fileChannel, $file]) {
            foreach ($this->readEntries($file) as $data) {
                $log = Log::fromArray($data);
                $entryLevel = self::LEVEL_ALIASES[$log->getLevel()?->value ?? 'info'] ?? 'INFO';
                $entry = [
                    'id' => (string) $log->getId(),
                    'channel' => $fileChannel,
                    'source' => basename($file, '.json'),
                    'level' => $entryLevel,
                    'timestamp' => $log->getTimestamp()?->format(\DATE_ATOM),
                    'message' => (string) ($log->getContents()['message'] ?? ''),
                    'action' => (string) ($log->getContexts()['action'] ?? ''),
                    'content' => $log->getContents() ?? [],
                    'context' => $log->getContexts() ?? [],
                ];

                if ('' !== $query && !str_contains(mb_strtolower((string) json_encode([$entry['content'], $entry['context']], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)), $query)) {
                    continue;
                }

                ++$levelCounts[$entryLevel];

                if (null === $level || $level === $entryLevel) {
                    $entries[] = $entry;
                }
            }
        }

        usort($entries, static fn (array $a, array $b): int => strcmp((string) $b['timestamp'], (string) $a['timestamp']));
        $total = \count($entries);

        return $this->sendJson([
            'items' => \array_slice($entries, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
            'meta' => ['page' => $page, 'limit' => self::PAGE_SIZE, 'total' => $total, 'pages' => max(1, (int) ceil($total / self::PAGE_SIZE))],
            'filters' => [
                'channels' => array_map(static fn (LogChannel $c): string => $c->value, LogChannel::cases()),
                'levels' => self::LEVELS,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            'levelCounts' => $levelCounts,
        ]);
    }

    /**
     * @return iterable<array{string, string}> [canal, chemin du fichier]
     */
    private function files(?LogChannel $channel, \DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        $channels = null !== $channel ? [$channel] : LogChannel::cases();

        foreach ($channels as $candidate) {
            $dir = $this->logsDir.'/'.$candidate->value;
            if (!is_dir($dir)) {
                continue;
            }

            foreach ((new Finder())->files()->in($dir)->name('*.json') as $file) {
                if (1 !== preg_match(self::DATE_PATTERN, $file->getFilename(), $m)) {
                    continue;
                }

                $day = \DateTimeImmutable::createFromFormat('!d-m-Y', \sprintf('%s-%s-%s', $m[1], $m[2], $m[3]));
                if (false !== $day && $day >= $from && $day <= $to) {
                    yield [$candidate->value, $file->getPathname()];
                }
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readEntries(string $file): array
    {
        $content = (string) @file_get_contents($file);
        if ('' === trim($content)) {
            return [];
        }

        $data = json_decode($content, true);

        return \is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    private static function parseDay(string $value): ?\DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return false === $day ? null : $day;
    }
}
