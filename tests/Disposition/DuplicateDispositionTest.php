<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Cleanup\Disposition\DuplicateDisposition;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Disposition\RedirectPairs;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Tests\Disposition\Support;

/**
 * Reading and validating a disposition file, and the redirect pairs it implies.
 *
 * @return array<string, mixed>
 */
function dispositionDocument(): array
{
    return [
        'format' => 1,
        'site' => 'news.uams.edu',
        'permalink_base' => 'https://news.uams.edu',
        'sets' => [[
            'ticket' => 'UAMS-Web/uams-statamic#798',
            'canonical_slug' => 'turnage-named-chairman',
            'keep' => ['post_id' => 20, 'slug' => 'turnage-named-chairman-2', 'date' => '2009-01-06'],
            'drop' => [['post_id' => 10, 'slug' => 'turnage-named-chairman', 'date' => '2009-01-06'], ['post_id' => 30, 'slug' => 'turnagenamedchairman', 'date' => '2009-01-06']],
        ]],
    ];
}

test('reads a valid disposition and writes it back unchanged', function () {
    $json = json_encode(dispositionDocument(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

    expect(DuplicateDisposition::fromJson($json)->toJson())->toBe($json);
});

test('refuses an invalid disposition', function (string $search, string $replace, string $message) {
    $json = json_encode(dispositionDocument(), JSON_THROW_ON_ERROR);
    $broken = str_replace($search, $replace, $json, $count);

    expect($count)->toBe(1);
    expect(fn () => DuplicateDisposition::fromJson($broken))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a missing format' => ['{"format":1,', '{', '"format"'],
    'a base with a path' => ['"https:\\/\\/news.uams.edu"', '"https:\\/\\/news.uams.edu\\/news"', 'permalink_base'],
    'a post in two sets' => ['"sets":[{', '"sets":[{"ticket":"t","canonical_slug":"other","keep":{"post_id":40,"slug":"x","date":"2009-01-06"},"drop":[{"post_id":10,"slug":"y","date":"2009-01-06"}]},{', 'a post belongs to one set'],
    'the kept post also dropped' => ['{"post_id":30,', '{"post_id":20,', 'a post belongs to one set'],
    'an empty drop list' => ['"drop":[{"post_id":10,"slug":"turnage-named-chairman","date":"2009-01-06"},{"post_id":30,"slug":"turnagenamedchairman","date":"2009-01-06"}]', '"drop":[]', '"drop" must be a non-empty list'],
    'a canonical slug with a double hyphen' => ['"canonical_slug":"turnage-named-chairman"', '"canonical_slug":"turnage--named"', 'canonical_slug'],
    'a date with a time' => ['"post_id":20,"slug":"turnage-named-chairman-2","date":"2009-01-06"', '"post_id":20,"slug":"turnage-named-chairman-2","date":"2009-01-06 10:00:00"', 'YYYY-MM-DD'],
    'a string post id' => ['"post_id":20,', '"post_id":"20",', 'positive integer'],
    'no ticket' => ['"ticket":"UAMS-Web\\/uams-statamic#798",', '', '"ticket"'],
]);

test('refuses a drop at another set\'s canonical URL, since the redirects would chain', function () {
    expect(fn () => Support::disposition([
        ['canonical_slug' => 'story-a', 'keep' => Support::ref(1, 'story-a-2'), 'drop' => [Support::ref(2, 'storya')]],
        ['canonical_slug' => 'story-b', 'keep' => Support::ref(3, 'story-b'), 'drop' => [Support::ref(4, 'story-a')]],
    ]))->toThrow(InvalidArgumentException::class, 'the redirects would chain');
});

test('refuses a URL redirected by two sets', function () {
    expect(fn () => Support::disposition([
        ['canonical_slug' => 'story-a', 'keep' => Support::ref(1, 'story-a-2'), 'drop' => [Support::ref(2, 'storya')]],
        ['canonical_slug' => 'story-c', 'keep' => Support::ref(5, 'story-c'), 'drop' => [Support::ref(6, 'storya')]],
    ]))->toThrow(InvalidArgumentException::class, 'both redirect');
});

test('names the public permalink by day and slug', function () {
    expect(Support::disposition([])->permalink('2009-01-06', 'turnage-named-chairman'))
        ->toBe('https://news.uams.edu/2009/01/06/turnage-named-chairman/');
});

test('pairs each dropped URL, and the kept post\'s old URL, with the canonical URL', function () {
    $pairs = RedirectPairs::from(DuplicateDisposition::fromJson(json_encode(dispositionDocument(), JSON_THROW_ON_ERROR)));
    $canonical = 'https://news.uams.edu/2009/01/06/turnage-named-chairman/';

    expect($pairs)->toBe([
        [
            'source' => 'https://news.uams.edu/2009/01/06/turnagenamedchairman/',
            'target' => $canonical,
            'response' => '301',
            'notes' => 'Duplicate of WordPress post 20 (UAMS-Web/uams-statamic#798).',
            'source_wordpress_post_id' => 30,
            'target_wordpress_post_id' => 20,
        ],
        [
            'source' => 'https://news.uams.edu/2009/01/06/turnage-named-chairman-2/',
            'target' => $canonical,
            'response' => '301',
            'notes' => 'Old slug of WordPress post 20, renamed to its canonical slug (UAMS-Web/uams-statamic#798).',
            'target_wordpress_post_id' => 20,
        ],
    ]);
});

test('lists a source once when two dropped posts share a URL', function () {
    $pairs = RedirectPairs::from(Support::disposition([[
        'canonical_slug' => 'turnage-named-chairman',
        'keep' => Support::ref(1, 'turnage-named-chairman'),
        'drop' => [Support::ref(2, 'turnagenamedchairman'), Support::ref(3, 'turnagenamedchairman')],
    ]]));

    expect(array_column($pairs, 'source_wordpress_post_id'))->toBe([2]);
});
