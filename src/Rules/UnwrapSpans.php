<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMXPath;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;
use UAMSWeb\Aether\WordPressImporter\Cleanup\XpathNodes;

/**
 * Unwraps every `<span>` element in the fragment, preserving its children
 * in place.
 *
 * The legacy news.uams.edu / inside.uams.edu corpus uses `<span>` almost
 * exclusively as a presentational shim from the WordPress editor or from
 * Word-paste — `class="content"`, `style="font-size: 13px; …"`, etc. —
 * never as a meaningful inline anchor. Unwrapping is a safe blanket
 * transform for this corpus.
 */
final class UnwrapSpans implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        // Snapshot first; removing elements mutates the live DOMNodeList.
        $spans = [];
        foreach (XpathNodes::query($xpath, '//span') as $element) {
            if ($element instanceof DOMElement) {
                $spans[] = $element;
            }
        }

        foreach ($spans as $span) {
            $parent = $span->parentNode;
            if (! $parent instanceof \DOMNode) {
                continue;
            }

            while ($span->firstChild instanceof \DOMNode) {
                $parent->insertBefore($span->firstChild, $span);
            }

            $parent->removeChild($span);
        }
    }
}
