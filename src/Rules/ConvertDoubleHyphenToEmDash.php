<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Rules;

use DOMDocument;
use DOMText;
use DOMXPath;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;
use UAMSWeb\Aether\WordPressImporter\Cleanup\XpathNodes;

/**
 * Replace the typographic shorthand ` -- ` (space + two hyphens + space)
 * with ` — ` (space + em dash + space) in every text node.
 *
 * The legacy editor lacked a smart-punctuation pass, so writers typed
 * double-hyphens as a stand-in for an em dash. Only the spaced form is
 * converted — unspaced `foo--bar` patterns (e.g. command-line flags or
 * compound terms) are left alone.
 */
final class ConvertDoubleHyphenToEmDash implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        foreach (XpathNodes::query($xpath, '//text()') as $textNode) {
            if (! $textNode instanceof DOMText) {
                continue;
            }

            $textNode->nodeValue = str_replace(
                ' -- ',
                " \u{2014} ",
                $textNode->nodeValue ?? '',
            );
        }
    }
}
