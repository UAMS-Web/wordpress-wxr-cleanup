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
 * Lift every `[caption …]…[/caption]` shortcode out of any wrapping
 * `<p>`, splitting the paragraph around the captions and wrapping
 * any prose chunks left behind in fresh `<p>`s.
 *
 * For a `<p>` that contains one or more matched caption shortcodes
 * (`UnwrapCaptionOnlyParagraph` already consumed the simple
 * single-caption case before this rule runs), parse the children
 * into a sequence of:
 *
 *   - **caption chunks** — the text + element nodes spanning a
 *     `[caption` opener to a `[/caption]` closer.
 *   - **prose chunks** — everything outside a caption, with `<br>`
 *     elements and whitespace text serving as chunk boundaries.
 *
 * Each caption emerges at the original `<p>`'s parent level on its
 * own. Each prose chunk that has visible content after trimming
 * gets wrapped in a brand-new `<p>` at the same level. Whitespace-
 * only or empty chunks are dropped. The original `<p>` itself is
 * removed.
 *
 * Inter-chunk separators:
 *
 *   - caption + caption → single `\n`
 *   - caption + prose or prose + caption → `\n\n` (blank line)
 *
 * Plus `\n\n` padding before the first chunk and after the last
 * chunk. `CollapseExtraLineBreaks` (later in the pipeline) collapses
 * any 3+ consecutive `\n` runs back down to 2.
 *
 * Worked example. The legacy editor sometimes serialized stacked
 * captions inside a single paragraph:
 *
 *     <p>
 *         [caption]<img …> Foo[/caption]
 *         Lorem ipsum dolor sit amet.
 *         [caption]<img …> Baz[/caption]
 *     </p>
 *
 * becomes
 *
 *     [caption]<img …> Foo[/caption]
 *
 *     <p>Lorem ipsum dolor sit amet.</p>
 *
 *     [caption]<img …> Baz[/caption]
 *
 * Bookkeeping. The rule fires only on `<p>`s where the count of
 * `[caption` openers equals the count of `[/caption]` closers and
 * both are at least 1 — paragraphs with unbalanced shortcode
 * markers are left alone rather than risk producing malformed
 * output.
 *
 * Runs after `UnwrapCaptionOnlyParagraph` (which handles the simple
 * single-caption case) and before the structural table rules so
 * captions land at their final parent level before downstream
 * normalization.
 */
final class LiftCaptionsOutOfParagraph implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        $candidates = [];
        foreach (XpathNodes::query($xpath, '//p') as $p) {
            if ($p instanceof DOMElement && $this->paragraphHasBalancedCaptions($p)) {
                $candidates[] = $p;
            }
        }

        foreach ($candidates as $p) {
            $this->processParagraph($p);
        }
    }

    private function paragraphHasBalancedCaptions(DOMElement $p): bool
    {
        $text = $p->textContent;
        $openers = substr_count($text, '[caption');
        $closers = substr_count($text, '[/caption]');

        return $openers > 0 && $openers === $closers;
    }

    private function processParagraph(DOMElement $p): void
    {
        $chunks = $this->parseChunks($p);
        $chunks = $this->trimAndDropEmptyProse($chunks);

        if ($chunks === []) {
            $parent = $p->parentNode;
            if ($parent instanceof DOMNode) {
                $parent->removeChild($p);
            }

            return;
        }

        $this->emitChunks($p, $chunks);
    }

    /**
     * @return list<array{type: string, nodes: list<DOMNode>}>
     */
    private function parseChunks(DOMElement $p): array
    {
        $document = $p->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return [];
        }

        $chunks = [];
        $currentType = 'prose';
        /** @var list<DOMNode> $currentNodes */
        $currentNodes = [];
        $inCaption = false;

        $flush = function () use (&$chunks, &$currentNodes, &$currentType): void {
            if ($currentNodes !== []) {
                $chunks[] = ['type' => $currentType, 'nodes' => $currentNodes];
                $currentNodes = [];
            }
        };

        foreach (iterator_to_array($p->childNodes) as $child) {
            if ($child instanceof DOMText) {
                $text = $child->nodeValue ?? '';
                $pos = 0;
                $len = strlen($text);

                while ($pos < $len) {
                    if (! $inCaption) {
                        $openerAt = strpos($text, '[caption', $pos);
                        if ($openerAt === false) {
                            $segment = substr($text, $pos);
                            if ($currentType !== 'prose') {
                                $flush();
                                $currentType = 'prose';
                            }

                            $currentNodes[] = $document->createTextNode($segment);
                            $pos = $len;

                            continue;
                        }

                        if ($openerAt > $pos) {
                            $segment = substr($text, $pos, $openerAt - $pos);
                            if ($currentType !== 'prose') {
                                $flush();
                                $currentType = 'prose';
                            }

                            $currentNodes[] = $document->createTextNode($segment);
                        }

                        $flush();
                        $currentType = 'caption';
                        $inCaption = true;
                        $pos = $openerAt;

                        continue;
                    }

                    $closerAt = strpos($text, '[/caption]', $pos);
                    if ($closerAt === false) {
                        $segment = substr($text, $pos);
                        $currentNodes[] = $document->createTextNode($segment);
                        $pos = $len;

                        continue;
                    }

                    $end = $closerAt + strlen('[/caption]');
                    $segment = substr($text, $pos, $end - $pos);
                    $currentNodes[] = $document->createTextNode($segment);
                    $flush();
                    $inCaption = false;
                    $currentType = 'prose';
                    $pos = $end;
                }

                continue;
            }

            if ($child instanceof DOMElement) {
                $tag = strtolower($child->nodeName);

                if (! $inCaption && $tag === 'br') {
                    $flush();

                    continue;
                }

                $desired = $inCaption ? 'caption' : 'prose';
                if ($currentType !== $desired) {
                    $flush();
                    $currentType = $desired;
                }

                $currentNodes[] = $child;
            }
        }

        $flush();

        return $chunks;
    }

    /**
     * @param  list<array{type: string, nodes: list<DOMNode>}>  $chunks
     * @return list<array{type: string, nodes: list<DOMNode>}>
     */
    private function trimAndDropEmptyProse(array $chunks): array
    {
        $result = [];

        foreach ($chunks as $chunk) {
            if ($chunk['type'] === 'caption') {
                $result[] = $chunk;

                continue;
            }

            $nodes = $chunk['nodes'];

            while ($nodes !== [] && $this->isWhitespaceTextNode($nodes[0])) {
                array_shift($nodes);
            }

            while ($nodes !== [] && $this->isWhitespaceTextNode($nodes[count($nodes) - 1])) {
                array_pop($nodes);
            }

            if ($nodes !== [] && $nodes[0] instanceof DOMText) {
                $nodes[0]->nodeValue = ltrim($nodes[0]->nodeValue ?? '');
            }

            if ($nodes !== []) {
                $last = $nodes[count($nodes) - 1];
                if ($last instanceof DOMText) {
                    $last->nodeValue = rtrim($last->nodeValue ?? '');
                }
            }

            if ($nodes !== []) {
                $result[] = ['type' => 'prose', 'nodes' => $nodes];
            }
        }

        return $result;
    }

    private function isWhitespaceTextNode(DOMNode $node): bool
    {
        return $node instanceof DOMText && trim($node->nodeValue ?? '') === '';
    }

    /**
     * @param  list<array{type: string, nodes: list<DOMNode>}>  $chunks
     */
    private function emitChunks(DOMElement $p, array $chunks): void
    {
        $document = $p->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return;
        }

        $parent = $p->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        $parent->insertBefore($document->createTextNode("\n\n"), $p);

        $prevType = null;
        foreach ($chunks as $chunk) {
            if ($prevType !== null) {
                $separator = ($prevType === 'caption' && $chunk['type'] === 'caption')
                    ? "\n"
                    : "\n\n";
                $parent->insertBefore($document->createTextNode($separator), $p);
            }

            if ($chunk['type'] === 'caption') {
                foreach ($chunk['nodes'] as $node) {
                    $parent->insertBefore($node, $p);
                }
            } else {
                $newParagraph = $document->createElement('p');
                foreach ($chunk['nodes'] as $node) {
                    $newParagraph->appendChild($node);
                }

                $parent->insertBefore($newParagraph, $p);
            }

            $prevType = $chunk['type'];
        }

        $parent->insertBefore($document->createTextNode("\n\n"), $p);
        $parent->removeChild($p);
    }
}
