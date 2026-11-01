<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;

/**
 * Unwrap every `<u>` element, preserving its children in place.
 *
 * `<u>` is presentational underline markup; modern accessible content
 * uses semantic emphasis (`<em>`/`<strong>`) or CSS. Strip the wrapper,
 * keep the text — let the destination CMS handle styling decisions.
 *
 * Nested cases (e.g. `<u><u>foo</u></u>`) flatten in a single pass: the
 * snapshot of `<u>` elements is collected up front, then each is
 * unwrapped, so the outermost ancestor absorbs every layer's children.
 */
final class UnwrapUnderlines implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $elements = [];
        foreach ($document->getElementsByTagName('u') as $element) {
            $elements[] = $element;
        }

        foreach ($elements as $element) {
            $this->unwrap($element);
        }
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if (! $parent instanceof DOMNode) {
            return;
        }

        while ($element->firstChild instanceof DOMNode) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }
}
