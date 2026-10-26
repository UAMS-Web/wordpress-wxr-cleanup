<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use UamsWeb\WxrCleanup\Rule;

/**
 * Merge consecutive `<ul>`s into one and consecutive `<ol>`s into one.
 *
 * Legacy editors often committed each list item to its own list — a
 * sequence of single-item `<ul>` blocks separated only by whitespace.
 * Each item is structurally a sibling list, but semantically should be
 * one list. Walk the document, and when a `<ul>` (or `<ol>`) is
 * followed — past any whitespace text — by another of the same tag,
 * move the second list's children into the first and drop the second.
 *
 * Mixed-type sequences (`<ul>` followed by `<ol>`) are left alone —
 * those are intentionally different list semantics. Lists separated by
 * non-whitespace text or a non-list element are left alone.
 *
 * Attribute merging: the first list's attributes are preserved; the
 * second list's attributes are discarded. Real cases here are bare
 * `<ul>`/`<ol>` with no attributes, so conflicts are rare.
 *
 * The leading whitespace text node of the second list is dropped before
 * merge so the resulting list has exactly one `\n` between consecutive
 * `<li>`s — not the doubled boundary whitespace that would otherwise
 * accumulate.
 *
 * Iterates until stable so a run of N adjacent lists collapses to one
 * in O(N) passes.
 */
final class MergeAdjacentLists implements Rule
{
    private const array LIST_TAGS = ['ul', 'ol'];

    public function apply(DOMDocument $document): void
    {
        do {
            $changed = false;
            foreach (self::LIST_TAGS as $tag) {
                $lists = [];
                foreach ($document->getElementsByTagName($tag) as $list) {
                    $lists[] = $list;
                }

                foreach ($lists as $list) {
                    if ($list->parentNode === null) {
                        continue;
                    }

                    $next = $this->nextNonWhitespaceSibling($list);
                    if (! $next instanceof DOMElement) {
                        continue;
                    }

                    if ($next->nodeName !== $tag) {
                        continue;
                    }

                    $this->merge($list, $next);
                    $changed = true;
                }
            }
        } while ($changed);
    }

    private function nextNonWhitespaceSibling(DOMElement $element): ?DOMNode
    {
        $sibling = $element->nextSibling;
        while ($sibling instanceof DOMNode) {
            if ($sibling instanceof DOMText && mb_trim($sibling->nodeValue ?? '') === '') {
                $sibling = $sibling->nextSibling;

                continue;
            }

            return $sibling;
        }

        return null;
    }

    private function merge(DOMElement $list, DOMElement $next): void
    {
        $firstChild = $next->firstChild;
        if ($firstChild instanceof DOMText && mb_trim($firstChild->nodeValue ?? '') === '') {
            $next->removeChild($firstChild);
        }

        while ($next->firstChild instanceof DOMNode) {
            $list->appendChild($next->firstChild);
        }

        $parent = $next->parentNode;
        if ($parent instanceof DOMNode) {
            $parent->removeChild($next);
        }
    }
}
