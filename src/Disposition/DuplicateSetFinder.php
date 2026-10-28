<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Disposition;

/**
 * Finds same-day slug-format duplicate sets among a site's posts and picks each set's canonical.
 *
 * The rule was decided on `UAMS-Web/uams-statamic#46`:
 *
 * - **Group** posts whose slugs match once hyphens and any trailing `-N` are removed, and whose
 *   publish dates fall on the same day.
 * - **Guard:** a trailing `-N` is removed for grouping only when the set also has a member with
 *   that slug minus the number, or when the members' titles match (case, whitespace and curly
 *   quotes folded), so `…-june-30` and `…-june-28` stay two posts.
 * - **Exclude** a set when any member's permalink is in the excluded list, which holds the sets
 *   another ticket decides (#1639 and #2810).
 *
 * Within a set:
 *
 * - **URL:** the set's hyphenated slug with its `-N` removed, when that slug is a member, or when
 *   no member and no live (published) post holds it. Otherwise the kept post keeps its own slug,
 *   or takes the lowest-numbered hyphenated slug when its own has no hyphens.
 * - **Text:** the most complete published member, where word counts within 2% are equal, then
 *   the member already at the canonical slug, then the earliest.
 * - **Date:** the date of the member the text comes from, so the kept post is that member.
 */
final class DuplicateSetFinder
{
    /** Word counts within this fraction of the largest are treated as equally complete. */
    public const float EQUAL_WORDS_TOLERANCE = 0.02;

    /**
     * Every duplicate set among the posts, ordered by date and then canonical slug.
     *
     * @param  list<array{id: int, slug: string, status: string, date: string, title: string, words: int}>  $posts  Every post on the site
     * @param  list<array{date: string, slug: string}>  $excluded  Permalinks whose sets another ticket decides
     * @return list<array{canonical_slug: string, keep: array{post_id: int, slug: string, date: string}, drop: list<array{post_id: int, slug: string, date: string}>}>
     */
    public static function find(array $posts, array $excluded): array
    {
        $groups = [];
        foreach ($posts as $post) {
            $groups[self::groupKey($post['slug']).'|'.substr($post['date'], 0, 10)][] = $post;
        }

        $excludedKeys = [];
        foreach ($excluded as $permalink) {
            $excludedKeys[$permalink['date'].'|'.$permalink['slug']] = true;
        }

        $liveSlugs = [];
        foreach ($posts as $post) {
            if ($post['status'] === 'publish') {
                $liveSlugs[$post['slug']] = true;
            }
        }

        $sets = [];
        foreach ($groups as $members) {
            $members = self::guard($members);
            if (\count($members) < 2) {
                continue;
            }

            foreach ($members as $member) {
                if (isset($excludedKeys[substr($member['date'], 0, 10).'|'.$member['slug']])) {
                    continue 2;
                }
            }

            $set = self::resolve($members, $liveSlugs);
            if ($set !== null) {
                $sets[] = $set;
            }
        }

        usort($sets, fn (array $a, array $b): int => [$a['keep']['date'], $a['canonical_slug']] <=> [$b['keep']['date'], $b['canonical_slug']]);

        return $sets;
    }

    /**
     * A slug's grouping key: hyphens and a trailing `-N` removed.
     */
    public static function groupKey(string $slug): string
    {
        return str_replace('-', '', (string) preg_replace('/-\d+$/', '', $slug));
    }

    /**
     * Drop a member whose only tie to the group is a stripped trailing number that is neither a
     * collision suffix of another member's slug nor shared with a matching title.
     *
     * @param  list<array{id: int, slug: string, status: string, date: string, title: string, words: int}>  $members
     * @return list<array{id: int, slug: string, status: string, date: string, title: string, words: int}>
     */
    private static function guard(array $members): array
    {
        $slugs = array_column($members, 'slug');
        $kept = [];

        foreach ($members as $member) {
            if (preg_match('/^(.*)-\d+$/', $member['slug'], $match) === 1
                && ! \in_array($match[1], $slugs, true)
                && str_replace('-', '', $member['slug']) !== self::groupKey($member['slug'])
                && ! self::sharesTitle($member, $members)) {
                continue;
            }

            $kept[] = $member;
        }

        return $kept;
    }

    /**
     * Whether another member's title matches this one's, with case, whitespace and quotes folded.
     *
     * @param  array{id: int, slug: string, status: string, date: string, title: string, words: int}  $member
     * @param  list<array{id: int, slug: string, status: string, date: string, title: string, words: int}>  $members
     */
    private static function sharesTitle(array $member, array $members): bool
    {
        return array_any($members, fn (array $other): bool => $other['id'] !== $member['id'] && self::foldTitle($other['title']) === self::foldTitle($member['title']));
    }

    private static function foldTitle(string $title): string
    {
        $title = str_replace(["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}"], ["'", "'", '"', '"'], $title);

        return mb_strtolower(mb_trim((string) preg_replace('/\s+/u', ' ', $title)));
    }

    /**
     * Pick a set's canonical slug and kept post, or null when it has no published member.
     *
     * @param  list<array{id: int, slug: string, status: string, date: string, title: string, words: int}>  $members
     * @param  array<string, true>  $liveSlugs  Slugs held by published posts anywhere on the site
     * @return array{canonical_slug: string, keep: array{post_id: int, slug: string, date: string}, drop: list<array{post_id: int, slug: string, date: string}>}|null
     */
    private static function resolve(array $members, array $liveSlugs): ?array
    {
        $memberSlugs = array_column($members, 'slug');
        $hyphenated = array_values(array_filter($memberSlugs, fn (string $slug): bool => str_contains($slug, '-')));

        $base = null;
        foreach ($hyphenated as $slug) {
            $stripped = (string) preg_replace('/-\d+$/', '', $slug);
            if ($base === null || \strlen($stripped) < \strlen($base)) {
                $base = $stripped;
            }
        }

        $evidenced = $base !== null
            && (\in_array($base, $memberSlugs, true) || ! isset($liveSlugs[$base]))
            ? $base
            : null;

        $published = array_values(array_filter($members, fn (array $member): bool => $member['status'] === 'publish'));
        if ($published === []) {
            return null;
        }

        $most = max(array_column($published, 'words'));
        $complete = array_values(array_filter(
            $published,
            fn (array $member): bool => $member['words'] >= $most * (1 - self::EQUAL_WORDS_TOLERANCE),
        ));

        usort($complete, fn (array $a, array $b): int => [
            $a['slug'] === $evidenced ? 0 : 1, $a['date'], $a['id'],
        ] <=> [
            $b['slug'] === $evidenced ? 0 : 1, $b['date'], $b['id'],
        ]);
        $keep = $complete[0];

        $canonical = $evidenced ?? (str_contains($keep['slug'], '-') ? $keep['slug'] : self::lowestSuffixed($hyphenated, $keep['slug']));

        $drop = [];
        foreach ($members as $member) {
            if ($member['id'] !== $keep['id']) {
                $drop[] = self::reference($member);
            }
        }

        usort($drop, fn (array $a, array $b): int => $a['post_id'] <=> $b['post_id']);

        return ['canonical_slug' => $canonical, 'keep' => self::reference($keep), 'drop' => $drop];
    }

    /**
     * The hyphenated slug with the lowest trailing number, or the fallback when there is none.
     *
     * @param  list<string>  $hyphenated
     */
    private static function lowestSuffixed(array $hyphenated, string $fallback): string
    {
        if ($hyphenated === []) {
            return $fallback;
        }

        usort($hyphenated, fn (string $a, string $b): int => [\strlen($a), $a] <=> [\strlen($b), $b]);

        return $hyphenated[0];
    }

    /**
     * @param  array{id: int, slug: string, status: string, date: string, title: string, words: int}  $post
     * @return array{post_id: int, slug: string, date: string}
     */
    private static function reference(array $post): array
    {
        return ['post_id' => $post['id'], 'slug' => $post['slug'], 'date' => substr($post['date'], 0, 10)];
    }
}
