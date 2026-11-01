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
 * Move a leading `[/caption]` closer from one `<p>` back to the end
 * of the previous `<p>` — undoing the WordPress `wpautop` filter's
 * habit of splitting a single caption shortcode across a paragraph
 * boundary when the caption text contained a blank line.
 *
 * Textual model. Replaces every occurrence of
 *
 *     </p>(whitespace)<p>(whitespace)[/caption]
 *
 * with
 *
 *     [/caption]</p>(whitespace)<p>(whitespace)
 *
 * The inter-paragraph whitespace and any whitespace inside the
 * second `<p>` before `[/caption]` are preserved in their original
 * positions; only the `[/caption]` token itself moves.
 *
 * Why. `wpautop` wraps each paragraph-separated chunk in a `<p>`.
 * A caption with a blank line in its body gets serialized as
 *
 *     <p>[caption]<img …> Caption first line</p>
 *     <p>Caption second line[/caption]</p>
 *
 * — the opener is in one `<p>`, the closer in the next, and
 * neither is alone "captionizable." Without this rule
 * `LiftCaptionsOutOfParagraph` (rule 24) sees only unbalanced
 * shortcode markers in each `<p>` and conservatively skips both.
 * Joining the closer back yields a single balanced shortcode that
 * `LiftCaptionsOutOfParagraph` can then lift out cleanly.
 *
 * Match criteria. A `<p>` qualifies if its first non-whitespace
 * text content (skipping any leading whitespace-only text nodes
 * and ignoring leading whitespace within the first content text
 * node) starts with `[/caption]`, AND its immediately-preceding
 * non-whitespace sibling at the parent level is another `<p>`.
 * Anything else (a leading `<img>`, a leading `<a>`, a leading
 * `<br>`, a non-`<p>` previous sibling) disqualifies.
 *
 * Runs before `LiftCaptionsOutOfParagraph` (rule 24) so the
 * paragraphs are balanced by the time the lift rule scans for
 * matching shortcode markers.
 */
final class RestoreSplitCaptionCloser implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        $candidates = [];
        foreach (XpathNodes::query($xpath, '//p') as $p) {
            if (! $p instanceof DOMElement) {
                continue;
            }

            $closerTextNode = $this->leadingCloserTextNode($p);
            if (! $closerTextNode instanceof DOMText) {
                continue;
            }

            $previousParagraph = $this->previousMeaningfulParagraph($p);
            if (! $previousParagraph instanceof DOMElement) {
                continue;
            }

            $candidates[] = [
                'paragraph' => $p,
                'closerTextNode' => $closerTextNode,
                'previousParagraph' => $previousParagraph,
            ];
        }

        foreach ($candidates as $candidate) {
            $this->moveCloser(
                $candidate['paragraph'],
                $candidate['closerTextNode'],
                $candidate['previousParagraph'],
            );
        }
    }

    /**
     * Return the leading text node inside `$p` whose content begins
     * with `[/caption]` (after any leading whitespace within the
     * text node itself), iff every preceding child of `$p` is a
     * whitespace-only text node. Otherwise null.
     */
    private function leadingCloserTextNode(DOMElement $p): ?DOMText
    {
        foreach ($p->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text = $child->nodeValue ?? '';
                if (trim($text) === '') {
                    continue;
                }

                if (str_starts_with(ltrim($text), '[/caption]')) {
                    return $child;
                }

                return null;
            }

            return null;
        }

        return null;
    }

    private function previousMeaningfulParagraph(DOMElement $p): ?DOMElement
    {
        $prev = $p->previousSibling;
        while ($prev instanceof DOMText && trim($prev->nodeValue ?? '') === '') {
            $prev = $prev->previousSibling;
        }

        if ($prev instanceof DOMElement && strtolower($prev->nodeName) === 'p') {
            return $prev;
        }

        return null;
    }

    private function moveCloser(DOMElement $paragraph, DOMText $closerTextNode, DOMElement $previousParagraph): void
    {
        $document = $paragraph->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return;
        }

        // Strip `[/caption]` from the leading text node, preserving
        // any leading whitespace inside the same text node.
        $text = $closerTextNode->nodeValue ?? '';
        $leadingWhitespaceLength = strlen($text) - strlen(ltrim($text));
        $afterCloser = substr($text, $leadingWhitespaceLength + strlen('[/caption]'));
        $closerTextNode->nodeValue = substr($text, 0, $leadingWhitespaceLength).$afterCloser;

        // Append `[/caption]` to the end of the previous paragraph.
        $previousParagraph->appendChild($document->createTextNode('[/caption]'));
    }
}
