<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Tests\Disposition;

use UamsWeb\WxrCleanup\Disposition\DuplicateDisposition;

/**
 * Builders for the duplicate-disposition tests: a small WXR export and a disposition document.
 */
final class Support
{
    /**
     * A WXR export holding the given items.
     *
     * @param  list<array{id: int, slug: string, type?: string, status?: string, date?: string, title?: string, body?: string}>  $items
     */
    public static function wxr(array $items): string
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"
                xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
                xmlns:content="http://purl.org/rss/1.0/modules/content/"
                xmlns:dc="http://purl.org/dc/elements/1.1/"
                xmlns:wp="http://wordpress.org/export/1.2/">
            <channel>
                <title>UAMS News</title>

            XML;

        foreach ($items as $item) {
            $xml .= sprintf(
                "\t<item>\n\t\t<title><![CDATA[%s]]></title>\n\t\t<link>https://uams-news.test/?p=%d</link>\n\t\t<content:encoded><![CDATA[%s]]></content:encoded>\n\t\t<wp:post_id>%d</wp:post_id>\n\t\t<wp:post_date><![CDATA[%s]]></wp:post_date>\n\t\t<wp:post_name><![CDATA[%s]]></wp:post_name>\n\t\t<wp:status><![CDATA[%s]]></wp:status>\n\t\t<wp:post_type><![CDATA[%s]]></wp:post_type>\n\t</item>\n",
                $item['title'] ?? 'A story',
                $item['id'],
                $item['body'] ?? '<p>One two three.</p>',
                $item['id'],
                $item['date'] ?? '2009-01-06 10:00:00',
                $item['slug'],
                $item['status'] ?? 'publish',
                $item['type'] ?? 'post',
            );
        }

        return $xml."</channel>\n</rss>\n";
    }

    /**
     * A news disposition holding the given sets, each attributed to #798.
     *
     * @param  list<array{canonical_slug: string, keep: array{post_id: int, slug: string, date: string}, drop: list<array{post_id: int, slug: string, date: string}>}>  $sets
     */
    public static function disposition(array $sets): DuplicateDisposition
    {
        return DuplicateDisposition::fromFoundSets('news.uams.edu', 'https://news.uams.edu', 'UAMS-Web/uams-statamic#798', $sets);
    }

    /**
     * @return array{post_id: int, slug: string, date: string}
     */
    public static function ref(int $postId, string $slug, string $date = '2009-01-06'): array
    {
        return ['post_id' => $postId, 'slug' => $slug, 'date' => $date];
    }
}
