<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;

/**
 * Collapse `<br>` tags and whitespace runs inside `[caption]…[/caption]`
 * shortcodes down to a single ASCII space.
 *
 *     [caption]<img …> Line one<br>\nLine two<br>\nLine three.[/caption]
 *
 * becomes
 *
 *     [caption]<img …> Line one Line two Line three.[/caption]
 *
 * Why. The destination CMS renders the caption text as a single
 * flowing line; the legacy WordPress editor sprinkled `<br>`s and
 * literal newlines inside caption text for visual layout that the
 * destination caption block lays out automatically. Collapsing
 * preserves the words and the inter-word single spaces; the
 * presentational line breaks go.
 *
 * Algorithm.
 *   1. Depth-first walk, tracking `inCaption` via `[caption` / `[/caption]`
 *      markers in text nodes (the two markers are unambiguous because the
 *      closer doesn't start with `[caption`).
 *   2. Each `<br>` element encountered while `inCaption` is true is
 *      replaced with a text node containing a single ASCII space.
 *   3. `$document->normalize()` merges any adjacent text nodes the
 *      replacement created.
 *   4. A second walk visits each text node and applies a position-aware
 *      whitespace collapse — runs of `\s` (whitespace including `\n`,
 *      `\r`, `\t`, `\v`) inside a caption span collapse to a single
 *      ASCII space; content outside any caption is left alone.
 *
 * Img attribute values (notably `alt`) are never touched — only text
 * nodes are visited.
 */
final class CollapseCaptionInternalWhitespace implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return;
        }

        $inCaption = false;
        $brsToReplace = [];
        $this->collectBrs($body, $inCaption, $brsToReplace);

        foreach ($brsToReplace as $br) {
            $parent = $br->parentNode;
            if (! $parent instanceof DOMNode) {
                continue;
            }

            $parent->replaceChild($document->createTextNode(' '), $br);
        }

        $document->normalize();

        $inCaption = false;
        $this->collapseTextNodes($body, $inCaption);
    }

    /**
     * @param  list<DOMElement>  $brsToReplace
     */
    private function collectBrs(DOMNode $node, bool &$inCaption, array &$brsToReplace): void
    {
        if ($node instanceof DOMText) {
            $this->updateCaptionState($node->nodeValue ?? '', $inCaption);

            return;
        }

        if ($node instanceof DOMElement
            && strtolower($node->nodeName) === 'br'
            && $inCaption
        ) {
            $brsToReplace[] = $node;

            return;
        }

        foreach ($node->childNodes as $child) {
            $this->collectBrs($child, $inCaption, $brsToReplace);
        }
    }

    private function collapseTextNodes(DOMNode $node, bool &$inCaption): void
    {
        if ($node instanceof DOMText) {
            $rewritten = $this->rewriteTextNode($node->nodeValue ?? '', $inCaption);
            if ($rewritten !== $node->nodeValue) {
                $node->nodeValue = $rewritten;
            }

            return;
        }

        if (! $node instanceof DOMElement) {
            return;
        }

        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            $this->collapseTextNodes($child, $inCaption);
        }
    }

    /**
     * Update `$inCaption` to the state at end-of-text, without rewriting.
     */
    private function updateCaptionState(string $text, bool &$inCaption): void
    {
        $pos = 0;
        $len = strlen($text);

        while ($pos < $len) {
            $openerAt = strpos($text, '[caption', $pos);
            $closerAt = strpos($text, '[/caption]', $pos);

            $nextOpener = $openerAt !== false;
            $nextCloser = $closerAt !== false;

            if (! $nextOpener && ! $nextCloser) {
                return;
            }

            if ($nextOpener && (! $nextCloser || $openerAt < $closerAt)) {
                $inCaption = true;
                $pos = $openerAt + strlen('[caption');

                continue;
            }

            $inCaption = false;
            $pos = $closerAt + strlen('[/caption]');
        }
    }

    /**
     * Rewrite a single text node — apply `\s+` → ` ` collapse to the
     * substrings that lie inside a caption span; pass through the rest
     * (including marker tokens) unchanged. Updates `$inCaption` to the
     * state at end-of-text.
     */
    private function rewriteTextNode(string $text, bool &$inCaption): string
    {
        $out = '';
        $pos = 0;
        $len = strlen($text);

        while ($pos < $len) {
            $openerAt = strpos($text, '[caption', $pos);
            $closerAt = strpos($text, '[/caption]', $pos);

            $nextOpener = $openerAt !== false;
            $nextCloser = $closerAt !== false;

            if (! $nextOpener && ! $nextCloser) {
                $segment = substr($text, $pos);
                $out .= $inCaption ? $this->collapseWhitespace($segment) : $segment;

                return $out;
            }

            if ($nextOpener && (! $nextCloser || $openerAt < $closerAt)) {
                $segment = substr($text, $pos, $openerAt - $pos);
                $out .= $inCaption ? $this->collapseWhitespace($segment) : $segment;

                $endAt = strpos($text, ']', $openerAt + strlen('[caption'));
                if ($endAt === false) {
                    // Malformed opener — pass through.
                    $out .= substr($text, $openerAt);

                    return $out;
                }

                $out .= substr($text, $openerAt, $endAt - $openerAt + 1);
                $inCaption = true;
                $pos = $endAt + 1;

                continue;
            }

            $segment = substr($text, $pos, $closerAt - $pos);
            $out .= $inCaption ? $this->collapseWhitespace($segment) : $segment;
            $out .= '[/caption]';
            $inCaption = false;
            $pos = $closerAt + strlen('[/caption]');
        }

        return $out;
    }

    private function collapseWhitespace(string $segment): string
    {
        return (string) preg_replace('/\s+/', ' ', $segment);
    }
}
