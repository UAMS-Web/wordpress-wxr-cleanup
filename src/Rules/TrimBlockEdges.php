<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
use UamsWeb\WxrCleanup\Rule;
use UamsWeb\WxrCleanup\XpathNodes;

/**
 * Strip leading and trailing whitespace and `<br>` tags from inside
 * `<p>`, `<li>`, `<td>`, and heading tags (`<h1>` through `<h6>`).
 *
 * The legacy editor liked to plant a stray `<br>` plus indentation
 * immediately before the closing tag of a paragraph, list item, or
 * table cell — a cosmetic side-effect of how the editor handled the
 * trailing-Enter keystroke. Strip those edges down to the first/last
 * real content.
 *
 * Targets block tags only. Inline tags (`<b>`, `<i>`, `<strong>`, …) are
 * left for `LiftTrailingBr` to handle, since lifting a trailing `<br>`
 * out of an inline element keeps the line break intact between the
 * inline phrase and the surrounding paragraph.
 */
final class TrimBlockEdges implements Rule
{
    private const array BLOCK_TAGS = ['p', 'li', 'td', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        $query = implode(
            ' | ',
            array_map(static fn (string $tag): string => '//'.$tag, self::BLOCK_TAGS),
        );

        $blocks = [];
        foreach (XpathNodes::query($xpath, $query) as $block) {
            if ($block instanceof DOMElement) {
                $blocks[] = $block;
            }
        }

        foreach ($blocks as $block) {
            $this->trim($block);
        }
    }

    private function trim(DOMElement $block): void
    {
        while (($first = $block->firstChild) instanceof \DOMNode) {
            if ($this->isStrippable($first)) {
                $block->removeChild($first);

                continue;
            }

            break;
        }

        while (($last = $block->lastChild) instanceof \DOMNode) {
            if ($this->isStrippable($last)) {
                $block->removeChild($last);

                continue;
            }

            break;
        }

        $first = $block->firstChild;
        if ($first instanceof DOMText) {
            $first->nodeValue = mb_ltrim($first->nodeValue ?? '');
        }

        $last = $block->lastChild;
        if ($last instanceof DOMText) {
            $last->nodeValue = mb_rtrim($last->nodeValue ?? '');
        }
    }

    private function isStrippable(\DOMNode $node): bool
    {
        if ($node instanceof DOMText && mb_trim($node->nodeValue ?? '') === '') {
            return true;
        }

        return $node instanceof DOMElement && $node->nodeName === 'br';
    }
}
