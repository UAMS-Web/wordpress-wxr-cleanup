<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;

/**
 * Replace every `<br>` whose direct parent is `<body>` (i.e. not
 * wrapped inside any other element) with two `\n` characters — one
 * blank line of plain-text separation.
 *
 * Companion to `CollapseBareDoubleBr` (rule 17). That rule collapses
 * RUNS of 2+ consecutive body-level `<br>`s into a single blank
 * line; this one handles the LONE body-level `<br>` case that the
 * run rule conservatively leaves alone. Together they make every
 * stray body-level `<br>` -- alone or in a run -- become exactly
 * one blank line, with `CollapseExtraLineBreaks` (rule 33) cleaning
 * up any 3+ `\n` runs that accumulate at the boundaries.
 *
 * `<br>` inside a wrapping `<p>`, `<li>`, `<td>`, heading, or any
 * other element is left alone — those carry meaningful inline
 * line-break semantics. Only the unwrapped body-level case is
 * structural cruft.
 *
 * Runs after the structural unwrap rules
 * (`UnwrapRemainingDivs`/`UnwrapDefinitionLists`/`UnwrapTableWrappers`/
 * `UnwrapWholeContentTable`) so a `<br>` that emerges at the body
 * level from one of those unwraps gets caught too.
 */
final class ReplaceBareBrWithBlankLine implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return;
        }

        $brs = [];
        foreach ($body->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'br') {
                $brs[] = $child;
            }
        }

        foreach ($brs as $br) {
            $parent = $br->parentNode;
            if (! $parent instanceof DOMNode) {
                continue;
            }

            $parent->insertBefore($document->createTextNode("\n\n"), $br);
            $parent->removeChild($br);
        }
    }
}
