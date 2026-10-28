<?php

declare(strict_types=1);

use UamsWeb\WxrCleanup\Disposition\DuplicateSetFinder;

/**
 * The duplicate rule decided on `UAMS-Web/uams-statamic#46`: which posts form a set, and which
 * member each set keeps at which slug.
 *
 * @param  list<array{0: int, 1: string, 2?: int, 3?: string, 4?: string, 5?: string}>  $rows  id, slug, words, status, date, title
 * @return list<array{id: int, slug: string, status: string, date: string, title: string, words: int}>
 */
function finderPosts(array $rows): array
{
    return array_map(fn (array $row): array => [
        'id' => $row[0],
        'slug' => $row[1],
        'words' => $row[2] ?? 100,
        'status' => $row[3] ?? 'publish',
        'date' => $row[4] ?? '2009-01-06 10:00:00',
        'title' => $row[5] ?? 'Turnage Named Chairman',
    ], $rows);
}

/**
 * @param  list<array{0: int, 1: string, 2?: int, 3?: string, 4?: string, 5?: string}>  $rows
 * @param  list<array{date: string, slug: string}>  $excluded
 * @return list<array{canonical_slug: string, keep: array{post_id: int, slug: string, date: string}, drop: list<array{post_id: int, slug: string, date: string}>}>
 */
function finderSets(array $rows, array $excluded = []): array
{
    return DuplicateSetFinder::find(finderPosts($rows), $excluded);
}

test('groups a hyphenated slug with its concatenated and -N forms on the same day', function () {
    $sets = finderSets([[1, 'turnage-named-chairman'], [2, 'turnagenamedchairman'], [3, 'turnage-named-chairman-2'], [4, 'another-story']]);

    expect($sets)->toHaveCount(1);
    expect(array_column($sets[0]['drop'], 'post_id'))->toBe([2, 3]);
});

test('does not group the same slug form on different days', function () {
    expect(finderSets([[1, 'turnage-named-chairman', 100, 'publish', '2009-01-06 10:00:00'], [2, 'turnagenamedchairman', 100, 'publish', '2009-01-07 10:00:00']]))->toBe([]);
});

test('keeps apart two posts joined only by stripping a real trailing number', function () {
    $sets = finderSets([
        [1, 'workshop-to-be-held-june-30', 100, 'publish', '2016-06-08 09:00:00', 'Workshop to be held June 30'],
        [2, 'workshop-to-be-held-june-28', 100, 'publish', '2016-06-08 09:30:00', 'Workshop to be held June 28'],
    ]);

    expect($sets)->toBe([]);
});

test('still groups -N forms whose titles match when no member has the unsuffixed slug', function () {
    $sets = finderSets([[1, 'super-bowl-champ-visits-2'], [2, 'super-bowl-champ-visits-3']]);

    expect($sets)->toHaveCount(1);
});

test('still groups a -N form beside its unsuffixed slug when the titles differ', function () {
    $sets = finderSets([[1, 'cancer-imaging-archive', 100, 'publish', '2015-12-04 09:00:00', 'Cancer Imaging Archive for National Cancer Institute'], [2, 'cancer-imaging-archive-2', 100, 'publish', '2015-12-04 09:00:00', 'Cancer Imaging Archive for NCI']]);

    expect($sets)->toHaveCount(1);
});

test('folds case, whitespace and curly quotes when comparing titles', function () {
    $sets = finderSets([
        [1, 'patients-day-2', 100, 'publish', '2009-01-06 10:00:00', "Patients\u{2019}  Day"],
        [2, 'patients-day-3', 100, 'publish', '2009-01-06 10:00:00', "patients' day"],
    ]);

    expect($sets)->toHaveCount(1);
});

test('leaves out a set with any member in the excluded list', function () {
    expect(finderSets([[1, 'turnage-named-chairman'], [2, 'turnagenamedchairman']], [['date' => '2009-01-06', 'slug' => 'turnagenamedchairman']]))->toBe([]);
});

test('keeps the most complete published member and gives it the unsuffixed slug', function () {
    $set = finderSets([[1, 'turnage-named-chairman', 50], [2, 'turnage-named-chairman-2', 400], [3, 'turnagenamedchairman', 390]])[0];

    expect($set['keep']['post_id'])->toBe(2);
    expect($set['canonical_slug'])->toBe('turnage-named-chairman');
});

test('treats word counts within 2% as equal and then prefers the member at the canonical slug', function (int $otherWords, int $kept) {
    $set = finderSets([[1, 'turnage-named-chairman', 100], [2, 'turnagenamedchairman', $otherWords]])[0];

    expect($set['keep']['post_id'])->toBe($kept);
})->with([
    'within 2%' => [102, 1],
    'more than 2% fuller' => [103, 2],
]);

test('breaks a remaining tie by the earliest member', function () {
    $set = finderSets([
        [1, 'turnage-named-chairman-2', 100, 'publish', '2009-01-06 15:00:00'],
        [2, 'turnage-named-chairman-3', 100, 'publish', '2009-01-06 11:00:00'],
        [3, 'turnage-named-chairman', 10, 'publish', '2009-03-01 10:00:00'],
    ])[0];

    expect($set['keep']['post_id'])->toBe(2);
});

test('never keeps a draft', function () {
    $set = finderSets([[1, 'turnage-named-chairman', 50], [2, 'turnage-named-chairman-2', 900, 'draft']])[0];

    expect($set['keep']['post_id'])->toBe(1);
    expect(array_column($set['drop'], 'post_id'))->toBe([2]);
});

test('strips the -N when no member and no live post holds the unsuffixed slug', function () {
    $set = finderSets([[1, 'julep-cup-jaunt-2', 300], [2, 'julepcupjaunt', 200]])[0];

    expect($set['canonical_slug'])->toBe('julep-cup-jaunt');
});

test('keeps the -N when a live post elsewhere holds the unsuffixed slug', function () {
    $set = finderSets([
        [1, 'defensive-driving-course-4', 100, 'publish', '2009-08-24 10:00:00'],
        [2, 'defensive-driving-course-5', 100, 'publish', '2009-08-24 11:00:00'],
        [3, 'defensive-driving-course', 100, 'publish', '2009-05-01 10:00:00'],
    ])[0];

    expect($set['keep']['post_id'])->toBe(1);
    expect($set['canonical_slug'])->toBe('defensive-driving-course-4');
});

test('a draft holding the unsuffixed slug elsewhere does not stop the strip', function () {
    $set = finderSets([
        [1, 'julep-cup-jaunt-2', 300],
        [2, 'julepcupjaunt', 200],
        [3, 'julep-cup-jaunt', 100, 'draft', '2008-01-01 10:00:00'],
    ])[0];

    expect($set['canonical_slug'])->toBe('julep-cup-jaunt');
});

test('gives a kept concatenated post the lowest-numbered hyphenated slug when the unsuffixed one is taken', function () {
    $set = finderSets([
        [1, 'turnagenamedchairman', 500],
        [2, 'turnage-named-chairman-3', 100],
        [3, 'turnage-named-chairman-2', 100],
        [4, 'turnage-named-chairman', 100, 'publish', '2008-01-01 10:00:00'],
    ])[0];

    expect($set['keep']['post_id'])->toBe(1);
    expect($set['canonical_slug'])->toBe('turnage-named-chairman-2');
});
