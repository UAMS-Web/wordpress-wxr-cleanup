<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Disposition;

/**
 * Reads the posts out of one or more WXR files as plain records, for finding duplicates.
 *
 * Only `post` items are read. The files are split into `<item>` blocks by string search
 * rather than parsed as one XML document, so an export of any size and one carrying the
 * raw control bytes WordPress writes into attachment metadata both read the same way. Each
 * record holds just what the duplicate rule needs: id, slug, status, date, title and a
 * word count of the body.
 */
final class WxrPosts
{
    /**
     * Every post in the given WXR files, in file order.
     *
     * @param  list<string>  $paths  WXR files, such as the `.000`, `.001` and `.002` parts of one export
     * @return list<array{id: int, slug: string, status: string, date: string, title: string, words: int}>
     */
    public static function read(array $paths): array
    {
        $posts = [];

        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new \RuntimeException("Cannot read WXR file: {$path}");
            }

            foreach (self::items($contents) as $item) {
                if (self::field($item, 'wp:post_type') !== 'post') {
                    continue;
                }

                $body = html_entity_decode(self::field($item, 'content:encoded'), ENT_QUOTES | ENT_HTML5);

                $posts[] = [
                    'id' => (int) self::field($item, 'wp:post_id'),
                    'slug' => self::field($item, 'wp:post_name'),
                    'status' => self::field($item, 'wp:status'),
                    'date' => self::field($item, 'wp:post_date'),
                    'title' => html_entity_decode(self::field($item, 'title'), ENT_QUOTES | ENT_HTML5),
                    'words' => self::wordCount($body),
                ];
            }
        }

        return $posts;
    }

    /**
     * The words in an HTML body once its tags are removed.
     */
    public static function wordCount(string $html): int
    {
        $text = (string) preg_replace('/<[^>]+>/', ' ', $html);
        $words = preg_split('/\s+/u', mb_trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? 0 : \count($words);
    }

    /**
     * Each `<item>…</item>` block in a WXR document.
     *
     * @return list<string>
     */
    private static function items(string $contents): array
    {
        $items = [];
        $offset = 0;

        while (($bounds = self::nextItem($contents, $offset)) !== null) {
            [$start, $end] = $bounds;
            $items[] = substr($contents, $start, $end - $start);
            $offset = $end;
        }

        return $items;
    }

    /**
     * The start of the next `<item>` at or after the offset, and the end of its closing
     * `</item>`, or null when there is none. Markup inside a CDATA section is skipped, so a
     * post body that contains a literal `</item>` cannot end the item early.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function nextItem(string $contents, int $offset): ?array
    {
        $start = strpos($contents, '<item>', $offset);
        if ($start === false) {
            return null;
        }

        $cursor = $start + \strlen('<item>');
        while (true) {
            $close = strpos($contents, '</item>', $cursor);
            if ($close === false) {
                return null;
            }

            $cdata = strpos($contents, '<![CDATA[', $cursor);
            if ($cdata === false || $cdata > $close) {
                return [$start, $close + \strlen('</item>')];
            }

            $cdataEnd = strpos($contents, ']]>', $cdata);
            if ($cdataEnd === false) {
                return null;
            }

            $cursor = $cdataEnd + \strlen(']]>');
        }
    }

    /**
     * The text of an item's child element, unwrapped from CDATA, or '' when absent.
     */
    private static function field(string $item, string $name): string
    {
        $quoted = preg_quote($name, '/');

        if (preg_match('/<'.$quoted.'>(?:<!\[CDATA\[(.*?)\]\]>|(.*?))<\/'.$quoted.'>/s', $item, $match) !== 1) {
            return '';
        }

        return ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
    }
}
