<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMText;
use DOMXPath;
use UamsWeb\WxrCleanup\Rule;
use UamsWeb\WxrCleanup\XpathNodes;

/**
 * Replace MS-Word "smart" curly quotes with straight ASCII equivalents
 * in every text node.
 *
 *   ” (U+201D right double curly) → "
 *   “ (U+201C left double curly)  → "
 *   ’ (U+2019 right single curly) → '
 *   ‘ (U+2018 left single curly)  → '
 *
 * Operates on text nodes only — attribute values are left alone.
 */
final class ReplaceCurlyQuotes implements Rule
{
    private const array REPLACEMENTS = [
        "\u{201D}" => '"',
        "\u{201C}" => '"',
        "\u{2019}" => "'",
        "\u{2018}" => "'",
    ];

    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        foreach (XpathNodes::query($xpath, '//text()') as $textNode) {
            if (! $textNode instanceof DOMText) {
                continue;
            }

            $textNode->nodeValue = strtr($textNode->nodeValue ?? '', self::REPLACEMENTS);
        }
    }
}
