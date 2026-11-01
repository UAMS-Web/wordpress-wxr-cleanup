<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMXPath;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;
use UAMSWeb\Aether\WordPressImporter\Cleanup\XpathNodes;

/**
 * Renames every remaining `<b>` element to its semantic equivalent
 * `<strong>`, preserving children and attributes.
 *
 * Runs at the tail end of the `<b>`-handling pipeline — after
 * `PromoteBoldToInnerStrong` has lifted any "speaker block" arrangements
 * and `UnwrapEmptyBolds` has dropped whitespace-only shells. What's left
 * by the time this rule runs is the genuine inline-bold case
 * (`<p>foo <b>bold</b> bar</p>`), which is rewritten to `<strong>`.
 */
final class RenameBoldToStrong implements Rule
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
            $strong = $document->createElement('strong');

            foreach ($bold->attributes as $attr) {
                $strong->setAttribute($attr->name, $attr->value);
            }

            while ($bold->firstChild instanceof \DOMNode) {
                $strong->appendChild($bold->firstChild);
            }

            $bold->parentNode?->replaceChild($strong, $bold);
        }
    }
}
