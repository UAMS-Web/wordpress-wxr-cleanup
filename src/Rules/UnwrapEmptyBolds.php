<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
use UamsWeb\WxrCleanup\Rule;
use UamsWeb\WxrCleanup\XpathNodes;

/**
 * Unwraps `<b>` elements whose only content is whitespace text.
 *
 * Legacy WordPress content sometimes contains accidental `<b>` shells
 * holding nothing but a single space — e.g. `<b> </b>` between a sentence
 * and the following quote. These add no semantic value and break syntax
 * highlighters / clean serialization. This rule replaces each such `<b>`
 * with its whitespace content directly.
 *
 * `<b>` elements with real text content (`<b>bold word</b>`) are left
 * untouched. `<b>` shells that wrap a `<p>` (the parser-auto-closed
 * "speaker block" arrangement) should be handled by
 * `PromoteBoldToInnerStrong` first; this rule runs after that one and
 * cleans up the remainder.
 */
final class UnwrapEmptyBolds implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        $bolds = [];
        foreach (XpathNodes::query($xpath, '//b') as $element) {
            if ($element instanceof DOMElement) {
                $bolds[] = $element;
            }
        }

        foreach ($bolds as $bold) {
            if (! $this->isWhitespaceOnly($bold)) {
                continue;
            }

            $parent = $bold->parentNode;
            if (! $parent instanceof \DOMNode) {
                continue;
            }

            while ($bold->firstChild instanceof \DOMNode) {
                $parent->insertBefore($bold->firstChild, $bold);
            }

            $parent->removeChild($bold);
        }
    }

    private function isWhitespaceOnly(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return false;
            }

            if ($child instanceof DOMText && trim($child->textContent) !== '') {
                return false;
            }
        }

        return true;
    }
}
