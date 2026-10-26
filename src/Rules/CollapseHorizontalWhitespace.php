<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMText;
use DOMXPath;
use UamsWeb\WxrCleanup\Rule;
use UamsWeb\WxrCleanup\XpathNodes;

/**
 * Collapse runs of 2+ horizontal whitespace characters (ASCII space,
 * tab, and NBSP) in every text node.
 *
 * Two outcomes per run:
 *
 * - If the run contains at least one NBSP (`U+00A0`), it collapses to
 *   a single NBSP. Legacy press releases planted patterns like
 *   `Fax:  &nbsp;&#160;(501) 686-5067` where the NBSPs signal an
 *   intentional non-breaking gap; we keep the non-breaking semantics
 *   but drop the redundancy.
 * - If the run is purely breaking whitespace (only spaces and/or tabs),
 *   it collapses to a single space.
 *
 * Newlines are left alone — line structure is preserved.
 */
final class CollapseHorizontalWhitespace implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        foreach (XpathNodes::query($xpath, '//text()') as $textNode) {
            if (! $textNode instanceof DOMText) {
                continue;
            }

            $textNode->nodeValue = (string) preg_replace_callback(
                '/[ \t\x{00A0}]{2,}/u',
                static fn (array $match): string => str_contains($match[0], "\u{00A0}") ? "\u{00A0}" : ' ',
                $textNode->nodeValue ?? '',
            );
        }
    }
}
