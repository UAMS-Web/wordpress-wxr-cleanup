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
 * Strip breaking whitespace (ASCII space, tab, line break, CR) from the
 * end of any text node that's immediately followed by a `<br>` element.
 *
 * Legacy editors often planted indentation and line breaks right before
 * `<br>` tags as a side-effect of how the source was wrapped:
 *
 *     Foo
 *         <br>
 *
 * `<br>` already provides the visual break, so the leading whitespace
 * on the `<br>` line is redundant. NBSP (`U+00A0`) is preserved as
 * non-breaking content.
 */
final class StripWhitespaceBeforeBr implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        foreach (XpathNodes::query($xpath, '//br') as $br) {
            if (! $br instanceof DOMElement) {
                continue;
            }

            $prev = $br->previousSibling;
            if (! $prev instanceof DOMText) {
                continue;
            }

            $prev->nodeValue = rtrim($prev->nodeValue ?? '', " \t\n\r");
        }
    }
}
