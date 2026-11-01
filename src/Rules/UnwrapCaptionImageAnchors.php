<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;

/**
 * Unwrap every `<a>` element whose only meaningful child is an
 * `<img>`, when the anchor sits between a `[caption …]` opening
 * shortcode and a `[/caption]` closing shortcode.
 *
 *     [caption id="" align="alignleft"]<a href="…" rel="…"><img src="…" alt="…"></a> Caption text.[/caption]
 *
 * becomes
 *
 *     [caption id="" align="alignleft"]<img src="…" alt="…"> Caption text.[/caption]
 *
 * The legacy WordPress caption shortcode wraps a "click to enlarge"
 * anchor around the in-line image; the anchor links from the
 * resized thumbnail to the full-size original. The destination CMS
 * generates the click-to-enlarge interaction from the caption's
 * image attachment, so the anchor is redundant inside a caption.
 *
 * Outside of a caption shortcode the anchor IS meaningful (e.g. an
 * inline thumbnail that links to a download) — leave it alone.
 *
 * The `[caption …]` opener can carry shortcode attributes
 * (`id="…"`, `align="…"`, `width="…"`) or be bare (`[caption]`,
 * which is what `CaptionizeImageCutline` produces). Both forms are
 * recognized by the `[caption` prefix; `[/caption]` (closer) does
 * not start with `[caption` so the two markers are unambiguous.
 *
 * Walk is depth-first across the document. State (`inCaption`)
 * persists across element boundaries because shortcode tokens are
 * TEXT, not elements — an opener inside a `<p>` and a closer inside
 * a sibling text node both contribute to the same caption span.
 */
final class UnwrapCaptionImageAnchors implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return;
        }

        $inCaption = false;
        $anchorsToUnwrap = [];

        $this->walk($body, $inCaption, $anchorsToUnwrap);

        foreach ($anchorsToUnwrap as $anchor) {
            $this->unwrapAnchor($anchor);
        }
    }

    /**
     * Depth-first walk. Tracks `$inCaption` as it crosses text-node
     * shortcode markers and collects matching `<a>` elements.
     *
     * @param  list<DOMElement>  $anchorsToUnwrap
     */
    private function walk(DOMNode $node, bool &$inCaption, array &$anchorsToUnwrap): void
    {
        if ($node instanceof DOMText) {
            $this->updateCaptionState($node->wholeText, $inCaption);

            return;
        }

        if ($node instanceof DOMElement
            && strtolower($node->nodeName) === 'a'
            && $inCaption
            && $this->onlyMeaningfulChildIsImg($node)
        ) {
            $anchorsToUnwrap[] = $node;
        }

        foreach ($node->childNodes as $child) {
            $this->walk($child, $inCaption, $anchorsToUnwrap);
        }
    }

    /**
     * Scan `$text` left-to-right for `[caption` / `[/caption]` markers
     * and update `$inCaption` to the state at end-of-text. The two
     * marker strings don't share a prefix (`[caption` vs `[/caption]`)
     * so the search is unambiguous.
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
     * True iff the anchor's only meaningful child is exactly one
     * `<img>`. Whitespace text nodes are tolerated; any other child
     * (more text, another element) disqualifies.
     */
    private function onlyMeaningfulChildIsImg(DOMElement $anchor): bool
    {
        $foundImg = false;

        foreach ($anchor->childNodes as $child) {
            if ($child instanceof DOMText && mb_trim($child->wholeText) === '') {
                continue;
            }

            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'img') {
                if ($foundImg) {
                    return false;
                }

                $foundImg = true;

                continue;
            }

            return false;
        }

        return $foundImg;
    }

    private function unwrapAnchor(DOMElement $anchor): void
    {
        $parent = $anchor->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        while ($anchor->firstChild instanceof DOMNode) {
            $parent->insertBefore($anchor->firstChild, $anchor);
        }

        $parent->removeChild($anchor);
    }
}
