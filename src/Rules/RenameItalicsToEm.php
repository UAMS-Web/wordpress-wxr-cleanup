<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMXPath;
use UamsWeb\WxrCleanup\Rule;
use UamsWeb\WxrCleanup\XpathNodes;

/**
 * Renames every `<i>` element to its semantic equivalent `<em>`,
 * preserving children and attributes.
 *
 * `<i>` is presentational ("italic"); `<em>` is semantic ("emphasis").
 * Modern markup prefers the semantic form.
 */
final class RenameItalicsToEm implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        $italics = [];
        foreach (XpathNodes::query($xpath, '//i') as $element) {
            if ($element instanceof DOMElement) {
                $italics[] = $element;
            }
        }

        foreach ($italics as $italic) {
            $em = $document->createElement('em');

            foreach ($italic->attributes as $attr) {
                $em->setAttribute($attr->name, $attr->value);
            }

            while ($italic->firstChild instanceof \DOMNode) {
                $em->appendChild($italic->firstChild);
            }

            $italic->parentNode?->replaceChild($em, $italic);
        }
    }
}
