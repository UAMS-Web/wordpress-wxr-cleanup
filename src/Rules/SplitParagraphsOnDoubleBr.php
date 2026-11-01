<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;
use UAMSWeb\Aether\WordPressImporter\Cleanup\XpathNodes;

/**
 * Splits a `<p>` on every run of two or more `<br>` elements, replacing
 * the original `<p>` with one new `<p>` per resulting group of content.
 *
 * The legacy editor frequently emits "tall" paragraphs that visually
 * split into sub-paragraphs only via consecutive `<br />` pairs — i.e.
 * a single `<p>` like
 *
 *     <p>One.<br />
 *     <br />
 *     Two.<br />
 *     <br />
 *     Three.</p>
 *
 * renders to readers as three paragraphs but to the importer as one.
 * This rule splits at every double-br run (with any whitespace between),
 * producing three real `<p>` elements. Single `<br>`s are preserved.
 *
 * Matching is form-agnostic: `<br>`, `<br />`, and `<br/>` are all the
 * same `br` element once the parser has built the DOM, so the XPath
 * `//br` query catches every form. (When serialized back out, every
 * `<br>` is written in the HTML5 void-element form `<br>`.)
 *
 * Run AFTER `UnwrapSpans` so the `<br>`s have surfaced as direct
 * children of the paragraph.
 */
final class SplitParagraphsOnDoubleBr implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        $paragraphs = [];
        foreach (XpathNodes::query($xpath, '//p') as $element) {
            if ($element instanceof DOMElement) {
                $paragraphs[] = $element;
            }
        }

        foreach ($paragraphs as $paragraph) {
            $this->splitParagraph($document, $paragraph);
        }
    }

    private function splitParagraph(DOMDocument $document, DOMElement $paragraph): void
    {
        /** @var list<DOMNode> $children */
        $children = iterator_to_array($paragraph->childNodes);
        $count = count($children);

        /** @var list<list<DOMNode>> $groups */
        $groups = [];
        $currentGroup = [];

        $i = 0;
        while ($i < $count) {
            $node = $children[$i];

            if ($this->isBrElement($node)) {
                [$endIndex, $isDouble] = $this->scanBrRange($children, $i);

                if ($isDouble) {
                    if ($currentGroup !== []) {
                        $groups[] = $currentGroup;
                        $currentGroup = [];
                    }

                    $i = $endIndex + 1;

                    continue;
                }
            }

            $currentGroup[] = $node;
            $i++;
        }

        if ($currentGroup !== []) {
            $groups[] = $currentGroup;
        }

        if (count($groups) <= 1) {
            return;
        }

        $parent = $paragraph->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        $emitted = 0;
        foreach ($groups as $group) {
            $group = $this->trimWhitespaceEnds($group);

            if ($group === []) {
                continue;
            }

            // Newline between consecutive new <p>s so they don't end up
            // running together as `</p><p>` on the same line.
            if ($emitted > 0) {
                $parent->insertBefore($document->createTextNode("\n"), $paragraph);
            }

            $newParagraph = $document->createElement('p');

            foreach ($group as $node) {
                $newParagraph->appendChild($node);
            }

            $parent->insertBefore($newParagraph, $paragraph);
            $emitted++;
        }

        $parent->removeChild($paragraph);
    }

    /**
     * Drop whitespace-only text nodes from the head and tail of the group,
     * then `ltrim` / `rtrim` whitespace inside the new boundary text nodes
     * — so a group that starts with "\nSecond sentence." becomes simply
     * "Second sentence." once promoted into its own paragraph.
     *
     * @param  list<DOMNode>  $group
     * @return list<DOMNode>
     */
    private function trimWhitespaceEnds(array $group): array
    {
        while ($group !== [] && $this->isWhitespaceText($group[0])) {
            array_shift($group);
        }

        while ($group !== [] && $this->isWhitespaceText($group[array_key_last($group)])) {
            array_pop($group);
        }

        if ($group !== [] && $group[0] instanceof DOMText) {
            $group[0]->nodeValue = ltrim($group[0]->textContent);
        }

        if ($group !== [] && $group[array_key_last($group)] instanceof DOMText) {
            $last = $group[array_key_last($group)];
            $last->nodeValue = rtrim($last->textContent);
        }

        return $group;
    }

    private function isBrElement(?DOMNode $node): bool
    {
        return $node instanceof DOMElement && $node->nodeName === 'br';
    }

    private function isWhitespaceText(?DOMNode $node): bool
    {
        return $node instanceof DOMText && trim($node->textContent) === '';
    }

    /**
     * Starting at the supplied index — which must be a `<br>` — walk
     * forward through any `<br>` / whitespace-text sequence. Returns the
     * index of the last `<br>` in the run and whether the run had two or
     * more `<br>`s.
     *
     * @param  list<DOMNode>  $children
     * @return array{int, bool}
     */
    private function scanBrRange(array $children, int $start): array
    {
        $count = count($children);
        $brCount = 0;
        $lastBrIndex = $start;
        $i = $start;

        while ($i < $count) {
            $node = $children[$i];

            if ($this->isBrElement($node)) {
                $brCount++;
                $lastBrIndex = $i;
                $i++;

                continue;
            }

            if ($this->isWhitespaceText($node)) {
                $i++;

                continue;
            }

            break;
        }

        return [$lastBrIndex, $brCount >= 2];
    }
}
