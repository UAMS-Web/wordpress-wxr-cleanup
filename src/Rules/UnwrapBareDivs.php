<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMXPath;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;
use UAMSWeb\Aether\WordPressImporter\Cleanup\XpathNodes;

/**
 * Unwrap every `<div>` element that carries no attributes, preserving
 * its children in place.
 *
 * A `<div>` with no attributes adds no semantic or styling information
 * to the markup — it's just structural noise from the legacy editor.
 * `<div class="callout">`, `<div id="x">`, etc. are left alone because
 * they may carry meaning consumers rely on.
 *
 * Nested bare divs flatten in a single pass: the snapshot of matching
 * elements is collected up front, then each is unwrapped, so the
 * grandparent absorbs the children of every layer.
 */
final class UnwrapBareDivs implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        $divs = [];
        foreach (XpathNodes::query($xpath, '//div') as $div) {
            if ($div instanceof DOMElement && $div->attributes->length === 0) {
                $divs[] = $div;
            }
        }

        foreach ($divs as $div) {
            $this->unwrap($div);
        }
    }

    private function unwrap(DOMElement $div): void
    {
        $parent = $div->parentNode;

        if (! $parent instanceof \DOMNode) {
            return;
        }

        while ($div->firstChild instanceof \DOMNode) {
            $parent->insertBefore($div->firstChild, $div);
        }

        $parent->removeChild($div);
    }
}
