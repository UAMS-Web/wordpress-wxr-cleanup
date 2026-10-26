<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use UamsWeb\WxrCleanup\Rule;

/**
 * Unwrap every `<p>` whose meaningful content is exactly one or more
 * `<embed>` elements — leaving a blank line before and after each
 * unwrapped embed.
 *
 *     <p><embed src="…" /></p>
 *
 * becomes (at the parent level)
 *
 *     \n<embed src="…" />\n
 *
 * Multiple embeds inside the same `<p>` each get their own `\n`
 * padding, so a `<p><embed/><embed/></p>` unwraps to
 * `\n<embed>\n\n<embed>\n` — one blank line between them.
 *
 * Match criteria. Every child of the `<p>` is one of:
 *
 *   - an `<embed>` element,
 *   - a whitespace-only text node,
 *   - a `<br>` element.
 *
 * And the `<p>` contains at least one `<embed>`.
 *
 * Mixed-content paragraphs (`<p>Lead. <embed /> Trail.</p>`) are left
 * alone.
 *
 * Same blank-line bookkeeping as the other unwrap rules
 * (`UnwrapRemainingDivs`, `UnwrapCaptionOnlyParagraph`, …); the late
 * `CollapseExtraLineBreaks` rule collapses any 3+ consecutive `\n`
 * runs back to 2 so adjacent embeds and surrounding padding normalize
 * to a single blank line of separation.
 */
final class UnwrapEmbedOnlyParagraph implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $candidates = [];
        foreach ($document->getElementsByTagName('p') as $p) {
            if ($this->qualifies($p)) {
                $candidates[] = $p;
            }
        }

        foreach ($candidates as $p) {
            $this->unwrap($p);
        }
    }

    private function qualifies(DOMElement $p): bool
    {
        $hasEmbed = false;

        foreach ($p->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'embed') {
                $hasEmbed = true;

                continue;
            }

            if ($child instanceof DOMText && trim($child->nodeValue ?? '') === '') {
                continue;
            }

            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'br') {
                continue;
            }

            return false;
        }

        return $hasEmbed;
    }

    private function unwrap(DOMElement $p): void
    {
        $parent = $p->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        $document = $p->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return;
        }

        $embeds = [];
        foreach ($p->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'embed') {
                $embeds[] = $child;
            }
        }

        foreach ($embeds as $embed) {
            $parent->insertBefore($document->createTextNode("\n"), $p);
            $parent->insertBefore($embed, $p);
            $parent->insertBefore($document->createTextNode("\n"), $p);
        }

        $parent->removeChild($p);
    }
}
