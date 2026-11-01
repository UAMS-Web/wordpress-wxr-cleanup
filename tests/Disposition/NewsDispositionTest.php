<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Cleanup\Disposition\DuplicateDisposition;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Disposition\RedirectPairs;

/**
 * The committed news.uams.edu disposition and its redirect pairs (`UAMS-Web/uams-statamic#46`).
 *
 * The 140 sets were built from the news export by `bin/build-duplicate-disposition`; these tests
 * keep the committed files consistent with each other and with the rules a disposition must meet.
 */
function newsDisposition(): DuplicateDisposition
{
    return DuplicateDisposition::fromFile(dirname(__DIR__, 2).'/dispositions/news-uams-edu.json');
}

test('the news disposition is valid and holds the 140 sets #798 records', function () {
    $disposition = newsDisposition();

    expect($disposition->site)->toBe('news.uams.edu');
    expect($disposition->sets)->toHaveCount(140);
    expect(array_sum(array_map(fn (array $set): int => \count($set['drop']), $disposition->sets)))->toBe(150);
});

test('the committed redirect pairs are exactly what the disposition implies', function () {
    $committed = file_get_contents(dirname(__DIR__, 2).'/dispositions/news-uams-edu.redirects.json');

    expect($committed)->toBe(RedirectPairs::toJson(newsDisposition()));
});

test('no redirect target is itself a redirect source, and no source repeats', function () {
    $pairs = RedirectPairs::from(newsDisposition());
    $sources = array_column($pairs, 'source');

    expect(array_values(array_intersect(array_column($pairs, 'target'), $sources)))->toBe([]);
    expect(\count(array_unique($sources)))->toBe(\count($sources));
});

// Only the member half of the URL rule can be checked here: whether a published post elsewhere
// holds the unsuffixed slug needs the export, which is not in this repository. The finder tests
// cover that half, and the build was checked against the export (#798).
test('a canonical slug keeps a -N only when no member holds the unsuffixed slug', function () {
    foreach (newsDisposition()->sets as $set) {
        if (preg_match('/^(.*)-\d+$/', $set['canonical_slug'], $match) !== 1) {
            continue;
        }

        $memberSlugs = array_column([$set['keep'], ...$set['drop']], 'slug');
        expect($memberSlugs)->not->toContain($match[1]);
    }
});
