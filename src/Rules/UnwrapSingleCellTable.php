<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use UamsWeb\WxrCleanup\Rule;
use UamsWeb\WxrCleanup\XpathNodes;

/**
 * Unwrap every `<table>` whose structure contains exactly one `<tr>`
 * and exactly one `<td>` — leaving a blank line before and after each
 * unwrapped element.
 *
 * The legacy news.uams.edu exports use single-cell tables as
 * presentational containers (typically wrapping a centered header
 * `<p align="center">`-style title block at the top of an article).
 * The wrapper carries no semantic value in the destination CMS, so
 * peel it away and let the cell's contents stand at the table's
 * parent level.
 *
 * Structure recognition. The table may have a `<tbody>` between
 * itself and the row (the libxml HTML parser inserts one when the
 * source has bare `<table><tr>`), so the qualifying shape is:
 *
 *   `<table>` → (`<tbody>` →)? single `<tr>` → single `<td>`
 *
 * Tables with multiple rows, multiple cells, two `<tbody>`s, or
 * `<thead>`/`<tfoot>` siblings are NOT considered single-cell and
 * are left alone.
 *
 * Nested tables. A nested `<table>` inside the qualifying cell
 * survives intact along with its own descendants — only the outer
 * table chain (and its directly-owned tbody/tr/td) gets unwrapped.
 *
 * Blank-line bookkeeping. Same pattern as `UnwrapWholeContentTable`:
 * each unwrapped element emits `\n\n` text nodes on both sides.
 * `CollapseExtraLineBreaks` (later in the pipeline) collapses any
 * resulting runs of 3+ consecutive `\n` back down to 2 so the
 * stacked padding from peeling table/tbody/tr/td in succession
 * contributes exactly one normalized blank line above and below the
 * cell's content.
 *
 * Runs after `UnwrapWholeContentTable` so the whole-content special
 * case (which handles multi-cell tables too) fires first when both
 * rules would apply.
 */
final class UnwrapSingleCellTable implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        $tables = [];
        foreach (XpathNodes::query($xpath, '//table') as $table) {
            if ($table instanceof DOMElement) {
                $tables[] = $table;
            }
        }

        foreach ($tables as $table) {
            $chain = $this->qualifyingChain($table);
            if ($chain === null) {
                continue;
            }

            foreach ($chain as $element) {
                $this->unwrapWithBlankLines($element);
            }
        }
    }

    /**
     * Return the chain of elements to unwrap (table, optional tbody,
     * tr, td) if the table qualifies as single-cell; otherwise null.
     *
     * @return list<DOMElement>|null
     */
    private function qualifyingChain(DOMElement $table): ?array
    {
        $tbodies = $this->childElementsByTag($table, 'tbody');
        $directRows = $this->childElementsByTag($table, 'tr');

        if (count($tbodies) > 1) {
            return null;
        }

        if ($tbodies !== [] && $directRows !== []) {
            return null;
        }

        if ($this->hasChildElement($table, 'thead')
            || $this->hasChildElement($table, 'tfoot')
        ) {
            return null;
        }

        $tbody = $tbodies[0] ?? null;
        $rowOwner = $tbody ?? $table;
        $rows = $this->childElementsByTag($rowOwner, 'tr');

        if (count($rows) !== 1) {
            return null;
        }

        $row = $rows[0];
        $cells = $this->childElementsByTag($row, 'td');

        if (count($cells) !== 1) {
            return null;
        }

        $chain = [$table];
        if ($tbody !== null) {
            $chain[] = $tbody;
        }

        $chain[] = $row;
        $chain[] = $cells[0];

        return $chain;
    }

    /**
     * Return every direct child element of `$parent` whose tag name
     * matches `$tagName` (case-insensitive).
     *
     * @return list<DOMElement>
     */
    private function childElementsByTag(DOMElement $parent, string $tagName): array
    {
        $needle = strtolower($tagName);
        $result = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === $needle) {
                $result[] = $child;
            }
        }

        return $result;
    }

    private function hasChildElement(DOMElement $parent, string $tagName): bool
    {
        return $this->childElementsByTag($parent, $tagName) !== [];
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
