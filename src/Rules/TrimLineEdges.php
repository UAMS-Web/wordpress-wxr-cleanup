<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMText;
use DOMXPath;
use UamsWeb\WxrCleanup\Rule;
use UamsWeb\WxrCleanup\XpathNodes;

/**
 * Strip horizontal whitespace (ASCII space, tab, and NBSP) that sits
 * directly adjacent to a `\n` line break in every text node.
 *
 * "Line" is bounded by `\n`. Only horizontal whitespace that touches a
 * newline gets stripped — the absolute start/end of a text node is
 * NOT touched, since a text node that's part of an inline flow (e.g.
 * `…named <strong>Claudia Barone</strong> the new dean…`) has
 * meaningful leading/trailing spaces around the surrounding element.
 * `TrimBlockEdges` handles whitespace at the start of a wrapping block.
 *
 * NBSP (`U+00A0`) is included because a lone NBSP on its own line is
 * almost always editor cruft, not an intentional non-breaking gap —
 * legacy exports like ` &nbsp;Friday, April 25\n` come through as a
 * leading NBSP that should be stripped. Mid-line NBSPs are preserved
 * by `CollapseHorizontalWhitespace`'s separate handling.
 */
final class TrimLineEdges implements Rule
{
    public function apply(DOMDocument $document): void
    {
        // Earlier unwrapping rules (`UnwrapSpans`, `UnwrapBareDivs`)
        // leave adjacent text nodes whose split hides line boundaries
        // from a per-node regex. Merge them so e.g. `…include:\n\n` +
        // `[NBSP]Friday…` (two siblings after a span unwrap) becomes a
        // single text node where the regex can see the NBSP as
        // newline-adjacent.
        $document->normalize();

        $xpath = new DOMXPath($document);

        foreach (XpathNodes::query($xpath, '//text()') as $textNode) {
            if (! $textNode instanceof DOMText) {
                continue;
            }

            $textNode->nodeValue = (string) preg_replace(
                '/[ \t\x{00A0}]+\n|\n[ \t\x{00A0}]+/u',
                "\n",
                $textNode->nodeValue ?? '',
            );
        }
    }
}
