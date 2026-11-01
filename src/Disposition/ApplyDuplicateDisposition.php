<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Disposition;

use RuntimeException;

/**
 * Applies a duplicate disposition to a whole WXR export: drops each post it marks to drop and
 * gives each kept post its set's canonical slug.
 *
 * This works on whole `<item>` blocks and runs before the body rules, never inside them. The
 * importer runs the body rules on single post bodies captured through the migration API
 * (`UAMS-Web/wordpress-importer#1303`), and a body alone has no post to drop, so the rules
 * never consult a disposition.
 *
 * Before acting on a post, the slug in the export is checked against the slug the disposition
 * recorded for that post id. A mismatch stops the run instead of dropping or renaming the wrong
 * post, which is what a disposition applied to a different site's export, or to an export taken
 * after a post was edited, would otherwise do. A kept post already at its canonical slug is left
 * alone, so applying a disposition twice changes nothing the second time. A post the disposition
 * names but the export lacks is skipped, because one site's posts can be split across several
 * export files.
 *
 * Only `post` items are matched. Items of other types are left untouched, whatever their id.
 */
final class ApplyDuplicateDisposition
{
    public function __construct(
        private readonly DuplicateDisposition $disposition,
    ) {}

    /**
     * Apply the disposition and report what changed.
     *
     * @return array{wxr: string, dropped: list<int>, renamed: list<int>}
     */
    public function apply(string $wxr): array
    {
        $drops = [];
        $keeps = [];
        foreach ($this->disposition->sets as $set) {
            foreach ($set['drop'] as $reference) {
                $drops[$reference['post_id']] = $reference['slug'];
            }

            $keeps[$set['keep']['post_id']] = [$set['keep']['slug'], $set['canonical_slug']];
        }

        $output = '';
        $offset = 0;
        $dropped = [];
        $renamed = [];

        while (($bounds = WxrPosts::nextItem($wxr, $offset)) !== null) {
            [$start, $end] = $bounds;
            $item = substr($wxr, $start, $end - $start);
            $postId = $this->postId($item);

            if ($postId !== null && isset($drops[$postId])) {
                $this->assertSlug($item, $postId, [$drops[$postId]]);
                // Remove the item's whole line: the indentation before it and the newline after.
                $lineStart = $start;
                while ($lineStart > $offset && \in_array($wxr[$lineStart - 1], [' ', "\t"], true)) {
                    $lineStart--;
                }

                $output .= substr($wxr, $offset, $lineStart - $offset);
                $offset = match (true) {
                    substr($wxr, $end, 2) === "\r\n" => $end + 2,
                    ($wxr[$end] ?? '') === "\n" => $end + 1,
                    default => $end,
                };
                $dropped[] = $postId;

                continue;
            }

            $output .= substr($wxr, $offset, $start - $offset);

            if ($postId !== null && isset($keeps[$postId])) {
                [$recorded, $canonical] = $keeps[$postId];
                $current = $this->assertSlug($item, $postId, [$recorded, $canonical]);
                if ($current !== $canonical) {
                    $item = $this->withSlug($item, $canonical);
                    $renamed[] = $postId;
                }
            }

            $output .= $item;
            $offset = $end;
        }

        $output .= substr($wxr, $offset);

        return ['wxr' => $output, 'dropped' => $dropped, 'renamed' => $renamed];
    }

    /**
     * The item's post id when it is a `post`, else null.
     */
    private function postId(string $item): ?int
    {
        if (self::field($item, 'wp:post_type') !== 'post') {
            return null;
        }

        $id = self::field($item, 'wp:post_id');

        return $id !== null && ctype_digit($id) ? (int) $id : null;
    }

    /**
     * Confirm the item's slug is one the disposition expects for this post, and return it.
     *
     * @param  list<string>  $expected
     */
    private function assertSlug(string $item, int $postId, array $expected): string
    {
        $slug = self::field($item, 'wp:post_name') ?? '';
        if (! \in_array($slug, $expected, true)) {
            throw new RuntimeException(sprintf(
                'Post %d has slug "%s" in this export, but the disposition records "%s". Refusing to act on a post the disposition may not mean.',
                $postId,
                $slug,
                implode('" or "', $expected),
            ));
        }

        return $slug;
    }

    private function withSlug(string $item, string $slug): string
    {
        $replaced = preg_replace(
            '/<wp:post_name>(?:<!\[CDATA\[.*?\]\]>|[^<]*)<\/wp:post_name>/s',
            '<wp:post_name><![CDATA['.$slug.']]></wp:post_name>',
            $item,
            1,
            $count,
        );

        if ($replaced === null || $count !== 1) {
            throw new RuntimeException("Could not rewrite a kept post's <wp:post_name>.");
        }

        return $replaced;
    }

    /**
     * The text of an item's child element, unwrapped from CDATA, or null when absent.
     */
    private static function field(string $item, string $name): ?string
    {
        $quoted = preg_quote($name, '/');
        if (preg_match('/<'.$quoted.'>(?:<!\[CDATA\[(.*?)\]\]>|([^<]*))<\/'.$quoted.'>/s', $item, $match) !== 1) {
            return null;
        }

        return ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
    }
}
