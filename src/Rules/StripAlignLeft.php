<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMXPath;
use UamsWeb\WxrCleanup\Rule;
use UamsWeb\WxrCleanup\XpathNodes;

/**
 * Remove every `align="left"` attribute, regardless of which tag carries it.
 *
 * Left alignment is the default for LTR content, so an explicit
 * `align="left"` adds nothing visually — and the `align` attribute is
 * deprecated in HTML5 anyway. Other values (`right`, `center`,
 * `justify`) are preserved since they DO change rendering.
 *
 * Case-insensitive on the value (`align="LEFT"` → stripped too) via
 * XPath's `translate()` fold.
 */
final class StripAlignLeft implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        $query = "//*[translate(@align, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='left']";

        $elements = [];
        foreach (XpathNodes::query($xpath, $query) as $element) {
            if ($element instanceof DOMElement) {
                $elements[] = $element;
            }
        }

        foreach ($elements as $element) {
            $element->removeAttribute('align');
        }
    }
}
