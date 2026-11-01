<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;

/**
 * Strips a leading "publication date" prefix from the post body.
 *
 * Legacy press releases on news.uams.edu open with a date stamp like
 * `Nov. 12, 2002` (sometimes uppercased, sometimes followed by a separator
 * like ` | ` or ` — ` and a city tag). The date can appear either as a
 * bare text node at the start of `<body>` or as leading text inside the
 * first `<p>`. This rule handles both arrangements.
 *
 * If the leading text contains meaningful content AFTER the date and
 * separator, only the date prefix is removed; the remainder stays in
 * place. If the date emptied out its containing `<p>`, that `<p>` is
 * removed too.
 *
 * Case-insensitive. American format only — `Month [DD], YYYY` with
 * optional ordinal suffix and optional trailing separator. Run AFTER
 * `UnwrapSpans` so any presentational wrappers are gone. Idempotent.
 */
final class StripLeadingDateLine implements Rule
{
    /**
     * Matches the leading date pattern with optional ordinal day suffix
     * (`12th`, `1st`, …) and optional trailing separator (` — `, ` - `,
     * ` | `, ` – `). Anchored to the start of the input; no end anchor,
     * so meaningful text after the date is preserved.
     */
    private const string DATE_PATTERN =
        '/^\s*(?:Jan\.?|January|Feb\.?|February|March|April|May|June|July|Aug\.?|August|Sept\.?|Sep\.?|September|Oct\.?|October|Nov\.?|November|Dec\.?|December)\s+\d{1,2}(?:st|nd|rd|th)?,\s+\d{4}(?:\s+[\|–—-]\s+)?/iu';

    public function apply(DOMDocument $document): void
    {
        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return;
        }

        $first = $this->firstNonWhitespaceChild($body);

        if ($first instanceof DOMText) {
            $this->stripDateFrom($first);
            $this->trimLeadingWhitespaceTextNodes($body);

            return;
        }

        if ($first instanceof DOMElement && $first->nodeName === 'p') {
            $leadingText = $this->firstNonWhitespaceChild($first);

            if (! $leadingText instanceof DOMText) {
                return;
            }

            $this->stripDateFrom($leadingText);

            if ($this->isEffectivelyEmpty($first)) {
                $first->parentNode?->removeChild($first);
                $this->trimLeadingWhitespaceTextNodes($body);
            }
        }
    }

    private function stripDateFrom(DOMText $textNode): void
    {
        if (preg_match(self::DATE_PATTERN, $textNode->textContent, $matches) !== 1) {
            return;
        }

        $remainder = substr($textNode->textContent, strlen($matches[0]));

        if (trim($remainder) === '') {
            $textNode->parentNode?->removeChild($textNode);
        } else {
            $textNode->nodeValue = $remainder;
        }
    }

    private function firstNonWhitespaceChild(DOMNode $parent): ?DOMNode
    {
        $cursor = $parent->firstChild;

        while ($cursor instanceof DOMText && trim($cursor->textContent) === '') {
            $cursor = $cursor->nextSibling;
        }

        return $cursor;
    }

    private function trimLeadingWhitespaceTextNodes(DOMNode $parent): void
    {
        $cursor = $parent->firstChild;

        while ($cursor instanceof DOMText && trim($cursor->textContent) === '') {
            $toRemove = $cursor;
            $cursor = $cursor->nextSibling;
            $parent->removeChild($toRemove);
        }
    }

    private function isEffectivelyEmpty(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->textContent) === '') {
                continue;
            }

            return false;
        }

        return true;
    }
}
