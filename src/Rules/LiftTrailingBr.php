<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;
use UAMSWeb\Aether\WordPressImporter\Cleanup\XpathNodes;

/**
 * Lifts every trailing `<br>` out of its parent element so the closing
 * tag of the parent precedes the `<br>`, with no whitespace between.
 *
 * Legacy content frequently emits
 *
 *     <b>foo<br>
 *     </b>
 *
 * — a `<br>` as the last visible child of `<b>`, often with intervening
 * whitespace before the closing tag. This rule rewrites that to
 *
 *     <b>foo</b><br>
 *
 * by removing the trailing whitespace inside the parent and re-parenting
 * the `<br>` as the parent's next sibling. Applied iteratively: stacked
 * trailing `<br>`s (e.g. `<b>foo<br><br></b>`) and nested cases (e.g.
 * `<b>foo<i>bar<br></i></b>`) flatten all the way out until stable.
 *
 * Skips lifting out of `<body>` (the synthetic envelope) — there's
 * nowhere outside to put it — and skips block-level parents (`<p>`,
 * `<li>`, `<h1>`–`<h6>`) so the trailing `<br>` doesn't end up between
 * paragraphs. Trailing `<br>`s in block tags are handled instead by
 * `TrimBlockEdges`, which strips them outright.
 */
final class LiftTrailingBr implements Rule
{
    private const array SKIP_PARENTS = ['body', 'p', 'li', 'td', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        do {
            $changed = false;

            $brs = [];
            foreach (XpathNodes::query($xpath, '//br') as $br) {
                if ($br instanceof DOMElement) {
                    $brs[] = $br;
                }
            }

            // Process trailing-most first so a chain of trailing `<br>`s
            // (or a nested case) collapses out one layer per pass.
            foreach (array_reverse($brs) as $br) {
                if ($this->liftIfTrailing($br)) {
                    $changed = true;
                }
            }
        } while ($changed);
    }

    private function liftIfTrailing(DOMElement $br): bool
    {
        $parent = $br->parentNode;

        if (! $parent instanceof DOMElement) {
            return false;
        }

        if (in_array($parent->nodeName, self::SKIP_PARENTS, true)) {
            return false;
        }

        if (! $this->isLastNonWhitespaceChild($br)) {
            return false;
        }

        $grandparent = $parent->parentNode;
        if (! $grandparent instanceof \DOMNode) {
            return false;
        }

        $this->dropFollowingWhitespace($br, $parent);

        $parent->removeChild($br);
        $grandparent->insertBefore($br, $parent->nextSibling);

        return true;
    }

    private function isLastNonWhitespaceChild(DOMElement $br): bool
    {
        $cursor = $br->nextSibling;

        while ($cursor instanceof \DOMNode) {
            if ($cursor instanceof DOMText && trim($cursor->textContent) === '') {
                $cursor = $cursor->nextSibling;

                continue;
            }

            return false;
        }

        return true;
    }

    private function dropFollowingWhitespace(DOMElement $br, DOMElement $parent): void
    {
        $cursor = $br->nextSibling;

        while ($cursor instanceof \DOMNode) {
            $toRemove = $cursor;
            $cursor = $cursor->nextSibling;
            $parent->removeChild($toRemove);
        }
    }
}
