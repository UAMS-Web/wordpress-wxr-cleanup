<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMText;
use DOMXPath;
use UamsWeb\WxrCleanup\Rule;
use UamsWeb\WxrCleanup\XpathNodes;

/**
 * Collapse runs of 3+ consecutive `\n` characters down to exactly 2.
 *
 * Two consecutive `\n`s (one blank line) is the conventional separator
 * between paragraphs in plain-text content and is preserved. Anything
 * more is excess whitespace from the legacy editor and gets collapsed.
 *
 * `$document->normalize()` runs first so a newline run split across
 * adjacent text-node siblings (e.g. the `\n\n` text nodes
 * `UnwrapRemainingDivs` inserts before and after each div) becomes a
 * single text node in which the regex can see the full run.
 */
final class CollapseExtraLineBreaks implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $document->normalize();

        $xpath = new DOMXPath($document);

        foreach (XpathNodes::query($xpath, '//text()') as $textNode) {
            if (! $textNode instanceof DOMText) {
                continue;
            }

            $textNode->nodeValue = (string) preg_replace(
                '/\n{3,}/',
                "\n\n",
                $textNode->nodeValue ?? '',
            );
        }
    }
}
