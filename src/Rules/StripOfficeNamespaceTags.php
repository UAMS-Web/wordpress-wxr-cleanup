<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use UamsWeb\WxrCleanup\Rule;
use UamsWeb\WxrCleanup\XpathNodes;

/**
 * Strip — wrapper AND content — every element whose tag name begins
 * with the `v:` (Word VML) or `o:` (Office) namespace prefix.
 *
 * Word's HTML paste embeds vector drawing artifacts as `<v:shapetype>`,
 * `<v:shape>`, `<v:stroke>`, `<v:imagedata>`, `<v:f>` (formula),
 * `<v:path>`, … plus Office attribute carriers like `<o:lock>`. The
 * contents are XML attribute soup, not display text, so unlike
 * SmartTags (`UnwrapSmartTags`) we drop the whole subtree.
 *
 * `<o:p>` is also handled here at the DOM level. The pre-parse string
 * strip in `Transform::stripOfficeParagraphTags` stays as a
 * libxml-version safety net (some libxml builds drop the `o:` prefix
 * during parse and produce `<p>` — the DOM rule wouldn't match those).
 *
 * Iterative-by-snapshot: collect every matching element up front, then
 * remove each from its parent. Removing an outer element detaches its
 * descendants too, so subsequent `removeChild` calls on already-
 * detached descendants are no-ops at the live tree level.
 */
final class StripOfficeNamespaceTags implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        // Lowercase the name via translate() so `<V:Shape>` matches too.
        $lower = "translate(name(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')";
        $query = "//*[starts-with({$lower}, 'v:') or starts-with({$lower}, 'o:')]";

        $elements = [];
        foreach (XpathNodes::query($xpath, $query) as $element) {
            if ($element instanceof DOMElement) {
                $elements[] = $element;
            }
        }

        foreach ($elements as $element) {
            $parent = $element->parentNode;
            if (! $parent instanceof DOMNode) {
                continue;
            }

            $parent->removeChild($element);
        }
    }
}
