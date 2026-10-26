<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use UamsWeb\WxrCleanup\Rule;
use UamsWeb\WxrCleanup\XpathNodes;

/**
 * Rewrites the legacy `<b><p>…</p></b>` "speaker block" markup into valid
 * `<p><strong>…</strong></p>`.
 *
 * The HTML parser auto-closes inline `<b>` immediately before any block
 * element like `<p>`, so by the time the DOM is built the markup
 *
 *     <b>
 *     <p>X</p>
 *     </b>
 *
 * arrives as three sibling nodes — an empty `<b>`, a whitespace text node,
 * a `<p>`, then a second whitespace text node "after" what was the closing
 * `</b>`. This rule detects that arrangement: an empty (whitespace-only)
 * `<b>` immediately followed by a `<p>` (skipping whitespace between).
 * It wraps the `<p>`'s contents in a `<strong>` and discards the orphan
 * `<b>` along with the whitespace bridging it to the `<p>`.
 *
 * `<b>` elements with real inline content (`<b>bold text</b>` inside a
 * paragraph) are left untouched — those are valid HTML and a blanket
 * `<b>` → `<strong>` rename is a separate concern.
 */
final class PromoteBoldToInnerStrong implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        // Snapshot first; we mutate the tree as we iterate.
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

            $paragraph = $this->nextElementSiblingSkippingWhitespace($bold);

            if (! $paragraph instanceof DOMElement || $paragraph->nodeName !== 'p') {
                continue;
            }

            $parent = $bold->parentNode;
            if (! $parent instanceof DOMNode) {
                continue;
            }

            // Wrap the paragraph's contents in <strong>.
            $strong = $document->createElement('strong');
            while ($paragraph->firstChild instanceof DOMNode) {
                $strong->appendChild($paragraph->firstChild);
            }

            $paragraph->appendChild($strong);

            // Drop the orphan <b> and any whitespace between it and <p>.
            $cursor = $bold->nextSibling;
            while ($cursor instanceof DOMNode && $cursor !== $paragraph) {
                $toRemove = $cursor;
                $cursor = $cursor->nextSibling;
                $parent->removeChild($toRemove);
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

    private function nextElementSiblingSkippingWhitespace(DOMNode $node): ?DOMNode
    {
        $next = $node->nextSibling;

        while ($next instanceof DOMText && trim($next->textContent) === '') {
            $next = $next->nextSibling;
        }

        return $next;
    }
}
