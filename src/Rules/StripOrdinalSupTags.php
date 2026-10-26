<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use UamsWeb\WxrCleanup\Rule;

/**
 * Unwrap every `<sup>` element that carries the typographic ordinal
 * suffix `st`, `nd`, `rd`, or `th` (case-insensitive) immediately
 * after a digit. The legacy editors superscripted these by default,
 * which doesn't survive the migration cleanly; flattening them to
 * plain text (e.g. `1<sup>st</sup>` → `1st`) keeps the date / ordinal
 * inline with surrounding prose.
 *
 *     "Little Rock was the 17<sup>th</sup> city in the tour"
 *
 * becomes
 *
 *     "Little Rock was the 17th city in the tour"
 *
 * Match criteria. A `<sup>` qualifies if:
 *
 *   - its inner text (trimmed) is exactly one of `st`, `nd`, `rd`,
 *     `th` (case-insensitive),
 *   - the character immediately preceding the opening `<sup>` is an
 *     ASCII digit (`0`–`9`).
 *
 * The preceding-digit check guarantees we only flatten ordinal
 * superscripts; chemical formulas (`<sup>2+</sup>`), mathematical
 * exponents (`x<sup>n</sup>`), and other legitimate uses of `<sup>`
 * are left alone.
 *
 * The `<sup>`'s text content is moved up to its parent at the `<sup>`'s
 * position; the `<sup>` element itself is removed. Adjacent text nodes
 * are NOT merged — `CollapseHorizontalWhitespace` and friends already
 * handle text-node-edge normalization downstream.
 */
final class StripOrdinalSupTags implements Rule
{
    private const array ORDINAL_SUFFIXES = ['st', 'nd', 'rd', 'th'];

    public function apply(DOMDocument $document): void
    {
        $candidates = [];

        foreach ($document->getElementsByTagName('sup') as $sup) {
            if ($this->qualifies($sup)) {
                $candidates[] = $sup;
            }
        }

        foreach ($candidates as $sup) {
            $this->unwrap($sup);
        }
    }

    private function qualifies(DOMElement $sup): bool
    {
        $text = strtolower(mb_trim($sup->textContent));
        if (! in_array($text, self::ORDINAL_SUFFIXES, true)) {
            return false;
        }

        $previousChar = $this->previousCharacter($sup);
        if ($previousChar === null) {
            return false;
        }

        return ctype_digit($previousChar);
    }

    /**
     * Walk leftward across preceding siblings (and into the last text
     * node of element siblings) to find the single character that
     * immediately precedes `$sup`. Returns null if none exists or
     * the nearest preceding character is non-text (e.g. an element
     * boundary like `<br>`).
     */
    private function previousCharacter(DOMElement $sup): ?string
    {
        $previous = $sup->previousSibling;
        while ($previous instanceof DOMNode) {
            if ($previous instanceof DOMText) {
                $value = $previous->nodeValue ?? '';
                if ($value === '') {
                    $previous = $previous->previousSibling;

                    continue;
                }

                return substr($value, -1);
            }

            return null;
        }

        return null;
    }

    private function unwrap(DOMElement $sup): void
    {
        $parent = $sup->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        while ($sup->firstChild instanceof DOMNode) {
            $parent->insertBefore($sup->firstChild, $sup);
        }

        $parent->removeChild($sup);
    }
}
