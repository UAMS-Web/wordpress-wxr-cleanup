<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;
use UAMSWeb\Aether\WordPressImporter\Cleanup\XpathNodes;

/**
 * Unwrap every `<dl>`, `<dt>`, and `<dd>` element, leaving a blank
 * line before and after the unwrapped content.
 *
 * Definition-list markup shows up in the news.uams.edu exports
 * almost exclusively as a structural carrier for an attachment-id
 * `[caption]` shortcode -- e.g.
 *
 *     <dl id="attachment_33944">
 *         <dt>[caption id="" …]<img …> caption text[/caption]</dt>
 *         <dd>Body paragraph that goes with the image.</dd>
 *     </dl>
 *
 * The `<dl>`/`<dt>`/`<dd>` wrappers themselves carry no semantic
 * value in the destination CMS — the `[caption]` shortcode and the
 * body paragraph stand on their own. Drop all three wrapper tag
 * names; keep the content; insert blank-line padding so each
 * previously-bounded block remains visually separated.
 *
 * Pairs with `CollapseExtraLineBreaks` (rule 28) the same way
 * `UnwrapRemainingDivs` does: the `\n\n` text nodes inserted on
 * both sides of each unwrapped element can produce runs of 3+
 * consecutive `\n` characters where a `<dl>` directly bookends its
 * `<dt>`/`<dd>` children — the downstream collapse normalizes any
 * such run back down to exactly two `\n`s.
 *
 * Iteration uses a single snapshot of all dl/dt/dd elements at
 * apply time and processes them in document order. Children
 * unwrapped after their parent stay in the snapshot, just at a
 * shallower depth in the tree.
 */
final class UnwrapDefinitionLists implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        $elements = [];
        foreach (XpathNodes::query($xpath, '//dl|//dt|//dd') as $element) {
            if ($element instanceof DOMElement) {
                $elements[] = $element;
            }
        }

        foreach ($elements as $element) {
            $this->unwrapWithBlankLines($element);
        }
    }

    private function unwrapWithBlankLines(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        $document = $element->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return;
        }

        $parent->insertBefore($document->createTextNode("\n\n"), $element);

        while ($element->firstChild instanceof DOMNode) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->insertBefore($document->createTextNode("\n\n"), $element);
        $parent->removeChild($element);
    }
}
