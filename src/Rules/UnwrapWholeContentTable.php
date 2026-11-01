<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;

/**
 * When the entirety of a body fragment is wrapped in a single
 * `<table>`, unwrap that table along with its `<tbody>`, `<tr>`, and
 * `<td>` descendants — leaving a blank line before and after each
 * unwrapped element.
 *
 * The legacy news.uams.edu exports used a top-level layout `<table>`
 * with a single `<td>` cell whose contents are the actual article
 * markup (paragraphs, headings, lists, etc.). The table itself
 * carries no semantic value in the destination CMS, so peel it
 * away and let the inner paragraphs stand at the body level.
 *
 * Guard. The rule fires only when the body has exactly one
 * meaningful child (whitespace text nodes tolerated) and that
 * child is a `<table>`. A body with mixed top-level content (a
 * caption shortcode + a table, or paragraphs alongside a table)
 * leaves all tables alone.
 *
 * Nested tables. The collection step skips into `<table>` children
 * — only the outer table and its directly-owned `<tbody>`/`<tr>`/`<td>`
 * (transitively, through non-table elements) get unwrapped. A
 * nested table and its own structure survives intact.
 *
 * Blank-line bookkeeping. Same pattern as `UnwrapRemainingDivs` and
 * `UnwrapDefinitionLists`: each unwrapped element emits `\n\n` text
 * nodes on both sides. `CollapseExtraLineBreaks` (later in the
 * pipeline) collapses the resulting runs of 3+ consecutive `\n`
 * back down to 2, so empty rows / empty cells contribute nothing
 * to the final output beyond one normalized blank line.
 */
final class UnwrapWholeContentTable implements Rule
{
    private const array TABLE_STRUCTURE_TAGS = ['tbody', 'tr', 'td'];

    public function apply(DOMDocument $document): void
    {
        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return;
        }

        $outerTable = $this->bodyOnlyMeaningfulTable($body);
        if (! $outerTable instanceof DOMElement) {
            return;
        }

        $toUnwrap = [$outerTable];
        $this->collectStructureDescendants($outerTable, $toUnwrap);

        foreach ($toUnwrap as $element) {
            $this->unwrapWithBlankLines($element);
        }
    }

    /**
     * Return the body's sole meaningful child if it's a `<table>`,
     * otherwise null. Whitespace text nodes are tolerated; any other
     * non-whitespace content disqualifies.
     */
    private function bodyOnlyMeaningfulTable(DOMElement $body): ?DOMElement
    {
        $found = null;

        foreach ($body->childNodes as $child) {
            if ($child instanceof DOMText && mb_trim($child->wholeText) === '') {
                continue;
            }

            if (! $child instanceof DOMElement) {
                return null;
            }

            if ($found instanceof DOMElement) {
                return null;
            }

            $found = $child;
        }

        if (! $found instanceof DOMElement) {
            return null;
        }

        if (strtolower($found->nodeName) !== 'table') {
            return null;
        }

        return $found;
    }

    /**
     * Walk the descendants of `$element` collecting every `<tbody>`,
     * `<tr>`, and `<td>` we encounter — but stopping at nested
     * `<table>` boundaries so the nested table's own structure is
     * left intact.
     *
     * @param  list<DOMElement>  $collected
     */
    private function collectStructureDescendants(DOMElement $element, array &$collected): void
    {
        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if ($tag === 'table') {
                continue;
            }

            if (in_array($tag, self::TABLE_STRUCTURE_TAGS, true)) {
                $collected[] = $child;
            }

            $this->collectStructureDescendants($child, $collected);
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
