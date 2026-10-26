<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMXPath;
use UamsWeb\WxrCleanup\Rule;
use UamsWeb\WxrCleanup\XpathNodes;

/**
 * Strips Microsoft-Word-paste noise from HTML fragments.
 *
 * Removes:
 * - `class` attribute tokens matching `Mso*` (e.g. `MsoNormal`,
 *   `MsoNormalTable`); other tokens on the same element are preserved
 * - inline `style` attributes (these are almost always Word-injected)
 * - `<font>` legacy presentation tags (unwrapped; their children are
 *   preserved in place)
 *
 * `<o:p>` Office placeholder tags are stripped earlier by
 * `Transform::stripOfficeParagraphTags()` at the raw-string level — they
 * can't be reliably matched via XPath because libxml's HTML parser handles
 * namespaced tags inconsistently across versions.
 *
 * Table structure is preserved. The aggressive "unwrap MsoNormalTable
 * into paragraphs" transform is a separate rule.
 */
final class StripMsoNoise implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        $this->stripMsoClassTokens($xpath);
        $this->removeInlineStyles($xpath);
        $this->unwrapFontTags($xpath);
    }

    private function stripMsoClassTokens(DOMXPath $xpath): void
    {
        foreach (XpathNodes::query($xpath, '//*[@class]') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            // Remove Mso-prefixed tokens; preserve everything else
            $original = $element->getAttribute('class');
            $cleaned = (string) preg_replace('/\bMso[A-Za-z0-9_-]*\b/i', '', $original);
            $cleaned = trim((string) preg_replace('/\s+/', ' ', $cleaned));

            if ($cleaned === '') {
                $element->removeAttribute('class');
            } else {
                $element->setAttribute('class', $cleaned);
            }
        }
    }

    private function removeInlineStyles(DOMXPath $xpath): void
    {
        foreach (XpathNodes::query($xpath, '//*[@style]') as $element) {
            if ($element instanceof DOMElement) {
                $element->removeAttribute('style');
            }
        }
    }

    private function unwrapFontTags(DOMXPath $xpath): void
    {
        // Iterate a fresh snapshot since removing elements mutates the live
        // DOMNodeList in-flight.
        $fontElements = [];
        foreach (XpathNodes::query($xpath, '//font') as $element) {
            if ($element instanceof DOMElement) {
                $fontElements[] = $element;
            }
        }

        foreach ($fontElements as $font) {
            $parent = $font->parentNode;
            if (! $parent instanceof \DOMNode) {
                continue;
            }

            while ($font->firstChild instanceof \DOMNode) {
                $parent->insertBefore($font->firstChild, $font);
            }

            $parent->removeChild($font);
        }
    }
}
