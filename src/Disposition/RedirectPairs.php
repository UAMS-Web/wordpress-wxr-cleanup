<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Disposition;

/**
 * The redirect pairs a duplicate disposition implies: each dropped post's public permalink, and
 * a kept post's old permalink when its slug changes, pointed at the set's canonical permalink.
 *
 * `uams-statamic`'s `content:generate-migration-redirects --dedup-redirects` reads this list
 * (`UAMS-Web/uams-statamic-archive#2943`) and resolves each target to the kept entry's Statamic URL by
 * `target_wordpress_post_id` (`UAMS-Web/uams-statamic-archive#2960`), so one `301` takes a dropped URL
 * straight to its new page. The pairs are derived from the disposition alone, not from an
 * export, because one set's members can sit in different files of a split export.
 *
 * A pair whose source equals its target is left out: a dropped post that held the canonical
 * slug on the same day shares the kept post's URL, which still resolves.
 */
final class RedirectPairs
{
    /**
     * Every pair, in disposition order, each source listed once.
     *
     * @return list<array{source: string, target: string, response: string, notes: string, source_wordpress_post_id?: int, target_wordpress_post_id: int}>
     */
    public static function from(DuplicateDisposition $disposition): array
    {
        $pairs = [];
        $seen = [];

        foreach ($disposition->sets as $set) {
            $keep = $set['keep'];
            $target = $disposition->permalink($keep['date'], $set['canonical_slug']);
            $notes = sprintf('Duplicate of WordPress post %d (%s).', $keep['post_id'], $set['ticket']);

            foreach ($set['drop'] as $dropped) {
                $source = $disposition->permalink($dropped['date'], $dropped['slug']);
                if ($source === $target || isset($seen[$source])) {
                    continue;
                }

                $seen[$source] = true;
                $pairs[] = [
                    'source' => $source,
                    'target' => $target,
                    'response' => '301',
                    'notes' => $notes,
                    'source_wordpress_post_id' => $dropped['post_id'],
                    'target_wordpress_post_id' => $keep['post_id'],
                ];
            }

            // The kept post's own old URL, when its slug changes. It is not a dropped post, so the
            // row carries no source post id for a dropped-target check to match.
            $oldSource = $disposition->permalink($keep['date'], $keep['slug']);
            if ($oldSource !== $target && ! isset($seen[$oldSource])) {
                $seen[$oldSource] = true;
                $pairs[] = [
                    'source' => $oldSource,
                    'target' => $target,
                    'response' => '301',
                    'notes' => sprintf('Old slug of WordPress post %d, renamed to its canonical slug (%s).', $keep['post_id'], $set['ticket']),
                    'target_wordpress_post_id' => $keep['post_id'],
                ];
            }
        }

        return $pairs;
    }

    /**
     * The pairs as the JSON file holds them: pretty-printed, slashes unescaped, one final newline.
     */
    public static function toJson(DuplicateDisposition $disposition): string
    {
        return json_encode(self::from($disposition), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
