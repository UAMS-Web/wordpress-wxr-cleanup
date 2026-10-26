<?php

declare(strict_types=1);

use UamsWeb\WxrCleanup\Disposition\ApplyDuplicateDisposition;
use UamsWeb\WxrCleanup\Disposition\WxrPosts;
use UamsWeb\WxrCleanup\Tests\Disposition\Support;
use UamsWeb\WxrCleanup\Transform;

/**
 * The whole-post drop for `UAMS-Web/uams-statamic#798`: posts the disposition marks are removed,
 * a kept post takes its canonical slug, and nothing else in the export changes.
 *
 * @return array{wxr: string, dropped: list<int>, renamed: list<int>}
 */
function dispositionApply(string $wxr): array
{
    $disposition = Support::disposition([
        [
            'canonical_slug' => 'turnage-named-chairman',
            'keep' => Support::ref(20, 'turnage-named-chairman-2'),
            'drop' => [Support::ref(10, 'turnage-named-chairman'), Support::ref(30, 'turnagenamedchairman')],
        ],
    ]);

    return (new ApplyDuplicateDisposition($disposition))->apply($wxr);
}

function dispositionExport(): string
{
    return Support::wxr([
        ['id' => 10, 'slug' => 'turnage-named-chairman', 'body' => '<p>Short.</p>'],
        ['id' => 20, 'slug' => 'turnage-named-chairman-2', 'body' => '<p>The full story.</p>'],
        ['id' => 30, 'slug' => 'turnagenamedchairman'],
        ['id' => 40, 'slug' => 'an-unrelated-story'],
        ['id' => 10, 'slug' => 'image-10', 'type' => 'attachment'],
    ]);
}

test('drops the posts it marks and renames the kept post to the canonical slug', function () {
    $result = dispositionApply(dispositionExport());
    $posts = WxrPosts::read([dispositionTempFile($result['wxr'])]);

    expect($result['dropped'])->toBe([10, 30]);
    expect($result['renamed'])->toBe([20]);
    expect(array_column($posts, 'slug', 'id'))->toBe([20 => 'turnage-named-chairman', 40 => 'an-unrelated-story']);
});

test('leaves every other item byte-for-byte, including a non-post item sharing a dropped id', function () {
    $result = dispositionApply(dispositionExport());

    expect($result['wxr'])->toContain('<wp:post_name><![CDATA[an-unrelated-story]]></wp:post_name>');
    expect($result['wxr'])->toContain('<wp:post_name><![CDATA[image-10]]></wp:post_name>');
    expect($result['wxr'])->toContain('<p>The full story.</p>');
    expect($result['wxr'])->not->toContain('<p>Short.</p>');
    expect(substr_count($result['wxr'], '<item>'))->toBe(3);
});

test('leaves no blank line where a dropped item was', function () {
    $result = dispositionApply(dispositionExport());

    expect($result['wxr'])->not->toContain("\n\n\t<item>");
    expect($result['wxr'])->not->toContain("</item>\n\n");
});

test('changes nothing when applied a second time', function () {
    $once = dispositionApply(dispositionExport());
    $twice = dispositionApply($once['wxr']);

    expect($twice['wxr'])->toBe($once['wxr']);
    expect($twice['dropped'])->toBe([]);
    expect($twice['renamed'])->toBe([]);
});

test('skips a post the export does not hold, since an export can be split across files', function () {
    $result = dispositionApply(Support::wxr([
        ['id' => 20, 'slug' => 'turnage-named-chairman-2'],
        ['id' => 40, 'slug' => 'an-unrelated-story'],
    ]));

    expect($result['dropped'])->toBe([]);
    expect($result['renamed'])->toBe([20]);
});

test('refuses a post whose slug in the export is not the one recorded', function (int $id, string $slug) {
    dispositionApply(Support::wxr([['id' => $id, 'slug' => $slug]]));
})->with([
    'a dropped post' => [10, 'a-different-story'],
    'a kept post' => [20, 'a-different-story'],
])->throws(RuntimeException::class, 'the disposition records');

test('drops a post whose body contains a literal </item>', function () {
    $result = dispositionApply(Support::wxr([
        ['id' => 10, 'slug' => 'turnage-named-chairman', 'body' => '<p>Ends early</item> or not.</p>'],
        ['id' => 40, 'slug' => 'an-unrelated-story'],
    ]));

    expect($result['dropped'])->toBe([10]);
    expect($result['wxr'])->not->toContain('Ends early');
    expect(substr_count($result['wxr'], '<item>'))->toBe(1);
});

test('leaves no blank line where a dropped item was in a CRLF export', function () {
    $crlf = str_replace("\n", "\r\n", dispositionExport());
    $result = dispositionApply($crlf);

    expect($result['dropped'])->toBe([10, 30]);
    expect($result['wxr'])->not->toContain("\r\n\r\n\t<item>");
    expect($result['wxr'])->not->toContain("</item>\r\n\r\n");
});

test('the body rules alone never drop a post', function () {
    $cleaned = Transform::withDefaultRules()->apply(dispositionExport());

    expect(substr_count($cleaned, '<item>'))->toBe(5);
});

function dispositionTempFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'wxr-disposition-');
    if ($path === false) {
        throw new RuntimeException('Could not create a temporary file');
    }

    file_put_contents($path, $contents);
    register_shutdown_function(static fn () => @unlink($path));

    return $path;
}
