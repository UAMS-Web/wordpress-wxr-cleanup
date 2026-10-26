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
 * Unwrap every Microsoft Office "smart tag" element — any element whose
 * tag name begins with `st<digit>:` (e.g. `st1:*`, `st2:*`, …) —
 * preserving its children.
 *
 * Word and Outlook annotate copy-pasted content with semantic wrappers
 * like `<st1:city>Little Rock</st1:city>`, `<st1:state>Ark</st1:state>`,
 * `<st1:country-region>USA</st1:country-region>`, `<st1:date>`,
 * `<st1:time>`, `<st1:place>`, `<st1:address>`, `<st1:street>`,
 * `<st1:postalcode>`, `<st1:personname>`, etc. SmartTags v2 introduces
 * person-name-component wrappers: `<st2:sn>` (surname), `<st2:givenname>`,
 * `<st2:middlename>`, ... — same idea, newer namespace.
 *
 * Matches *any* element whose name starts with `st<digit>:`
 * (case-insensitive via XPath `translate()` mapping uppercase to
 * lowercase and digits to a sentinel `N`), so tag names beyond the
 * canonical list — and future SmartTags versions — are handled
 * automatically. Nested smart tags flatten in a single pass: the
 * snapshot of matching elements is collected up front, then each is
 * unwrapped, so the outermost ancestor absorbs every layer's children.
 */
final class UnwrapSmartTags implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        // Enumerate st1: through st9: explicitly. A sentinel-letter
        // translate() trick collides with the uppercase-to-lowercase
        // fold (e.g. mapping digits to `N` clashes with letter `N`
        // mapping to `n`), so the simpler form is also the correct one.
        $lower = "translate(name(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')";
        $prefixes = ['st1:', 'st2:', 'st3:', 'st4:', 'st5:', 'st6:', 'st7:', 'st8:', 'st9:'];
        $clauses = array_map(
            static fn (string $p): string => "starts-with({$lower}, '{$p}')",
            $prefixes,
        );
        $query = '//*['.implode(' or ', $clauses).']';

        $elements = [];
        foreach (XpathNodes::query($xpath, $query) as $element) {
            if ($element instanceof DOMElement) {
                $elements[] = $element;
            }
        }

        foreach ($elements as $element) {
            $this->unwrap($element);
        }
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if (! $parent instanceof DOMNode) {
            return;
        }

        while ($element->firstChild instanceof DOMNode) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }
}
