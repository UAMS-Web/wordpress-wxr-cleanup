<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Disposition;

use InvalidArgumentException;

/**
 * A site's duplicate disposition: which posts are dropped before import, and which post each
 * set keeps, at which slug.
 *
 * The file format is documented in the README's `## Duplicate dispositions` section. Every post
 * is named by its WordPress post id and carries its slug and day as recorded, so applying the
 * disposition to an export can confirm each id still names the same post before acting on it.
 *
 * @phpstan-type PostReference array{post_id: int, slug: string, date: string}
 * @phpstan-type DuplicateSet array{ticket: string, canonical_slug: string, keep: PostReference, drop: list<PostReference>}
 */
final class DuplicateDisposition
{
    /** The format version this class reads and writes. */
    public const int FORMAT = 1;

    /**
     * @param  string  $site  The site's public host, such as `news.uams.edu`
     * @param  string  $permalinkBase  The scheme and host its permalinks start with
     * @param  list<DuplicateSet>  $sets
     */
    private function __construct(
        public readonly string $site,
        public readonly string $permalinkBase,
        public readonly array $sets,
    ) {}

    /**
     * Read and validate a disposition file.
     */
    public static function fromFile(string $path): self
    {
        $json = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new InvalidArgumentException("Cannot read disposition file: {$path}");
        }

        return self::fromJson($json);
    }

    /**
     * Parse and validate a disposition document.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (! \is_array($data)) {
            throw new InvalidArgumentException('A disposition must be a JSON object.');
        }

        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('A disposition must declare "format": '.self::FORMAT.'.');
        }

        $site = self::string($data, 'site', 'the disposition');
        $base = self::string($data, 'permalink_base', 'the disposition');
        if (preg_match('#^https://[^/]+$#', $base) !== 1) {
            throw new InvalidArgumentException('"permalink_base" must be an https scheme and host with no path, such as https://news.uams.edu.');
        }

        $rawSets = $data['sets'] ?? null;
        if (! \is_array($rawSets) || ! array_is_list($rawSets)) {
            throw new InvalidArgumentException('"sets" must be a list.');
        }

        $sets = [];
        $seen = [];
        foreach ($rawSets as $index => $rawSet) {
            $where = 'set '.($index + 1);
            if (! \is_array($rawSet)) {
                throw new InvalidArgumentException("{$where} must be an object.");
            }

            $canonical = self::string($rawSet, 'canonical_slug', $where);
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $canonical) !== 1) {
                throw new InvalidArgumentException("{$where}: \"canonical_slug\" must be lowercase words joined by single hyphens.");
            }

            $keep = self::reference($rawSet['keep'] ?? null, "{$where} keep");
            $rawDrop = $rawSet['drop'] ?? null;
            if (! \is_array($rawDrop) || ! array_is_list($rawDrop) || $rawDrop === []) {
                throw new InvalidArgumentException("{$where}: \"drop\" must be a non-empty list.");
            }

            $drop = [];
            foreach ($rawDrop as $dropIndex => $rawReference) {
                $drop[] = self::reference($rawReference, "{$where} drop ".($dropIndex + 1));
            }

            foreach ([$keep, ...$drop] as $reference) {
                if (isset($seen[$reference['post_id']])) {
                    throw new InvalidArgumentException("{$where}: post {$reference['post_id']} is already in {$seen[$reference['post_id']]}; a post belongs to one set.");
                }

                $seen[$reference['post_id']] = $where;
            }

            $sets[] = [
                'ticket' => self::string($rawSet, 'ticket', $where),
                'canonical_slug' => $canonical,
                'keep' => $keep,
                'drop' => $drop,
            ];
        }

        $disposition = new self($site, $base, $sets);
        $disposition->assertNoChains();

        return $disposition;
    }

    /**
     * Refuse a disposition whose redirects would chain or collide: a URL that one set redirects
     * away from may not be another set's canonical URL, and may not be redirected by two sets.
     */
    private function assertNoChains(): void
    {
        $canonicalOwner = [];
        foreach ($this->sets as $index => $set) {
            $canonicalOwner[$this->permalink($set['keep']['date'], $set['canonical_slug'])] = $index;
        }

        $sourceOwner = [];
        foreach ($this->sets as $index => $set) {
            $canonical = $this->permalink($set['keep']['date'], $set['canonical_slug']);
            foreach ([...$set['drop'], $set['keep']] as $reference) {
                $source = $this->permalink($reference['date'], $reference['slug']);
                if ($source === $canonical) {
                    continue;
                }

                if (isset($canonicalOwner[$source]) && $canonicalOwner[$source] !== $index) {
                    throw new InvalidArgumentException(sprintf("set %d redirects %s, which is set %d's canonical URL; the redirects would chain.", $index + 1, $source, $canonicalOwner[$source] + 1));
                }

                if (isset($sourceOwner[$source]) && $sourceOwner[$source] !== $index) {
                    throw new InvalidArgumentException(sprintf('sets %d and %d both redirect %s.', $sourceOwner[$source] + 1, $index + 1, $source));
                }

                $sourceOwner[$source] = $index;
            }
        }
    }

    /**
     * Build a disposition from sets found by {@see DuplicateSetFinder}, all attributed to one ticket.
     *
     * @param  list<array{canonical_slug: string, keep: PostReference, drop: list<PostReference>}>  $found
     */
    public static function fromFoundSets(string $site, string $permalinkBase, string $ticket, array $found): self
    {
        $sets = [];
        foreach ($found as $set) {
            $sets[] = ['ticket' => $ticket, 'canonical_slug' => $set['canonical_slug'], 'keep' => $set['keep'], 'drop' => $set['drop']];
        }

        return self::fromJson(json_encode(['format' => self::FORMAT, 'site' => $site, 'permalink_base' => $permalinkBase, 'sets' => $sets], JSON_THROW_ON_ERROR));
    }

    /**
     * The disposition as the JSON its file holds: pretty-printed, slashes unescaped, one final newline.
     */
    public function toJson(): string
    {
        return json_encode(
            ['format' => self::FORMAT, 'site' => $this->site, 'permalink_base' => $this->permalinkBase, 'sets' => $this->sets],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /**
     * A post's public permalink: `{base}/YYYY/MM/DD/{slug}/`.
     */
    public function permalink(string $date, string $slug): string
    {
        return $this->permalinkBase.'/'.str_replace('-', '/', $date).'/'.$slug.'/';
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function string(array $data, string $key, string $where): string
    {
        $value = $data[$key] ?? null;
        if (! \is_string($value) || mb_trim($value) === '') {
            throw new InvalidArgumentException("{$where}: \"{$key}\" must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @return PostReference
     */
    private static function reference(mixed $value, string $where): array
    {
        if (! \is_array($value)) {
            throw new InvalidArgumentException("{$where} must be an object.");
        }

        $postId = $value['post_id'] ?? null;
        if (! \is_int($postId) || $postId < 1) {
            throw new InvalidArgumentException("{$where}: \"post_id\" must be a positive integer.");
        }

        $date = self::string($value, 'date', $where);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException("{$where}: \"date\" must be YYYY-MM-DD.");
        }

        return ['post_id' => $postId, 'slug' => self::string($value, 'slug', $where), 'date' => $date];
    }
}
