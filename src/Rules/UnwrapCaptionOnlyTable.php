<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use UamsWeb\WxrCleanup\Rule;
use UamsWeb\WxrCleanup\XpathNodes;

/**
 * Unwrap every `<table>` whose `<td>` cells each contain exactly one
 * `[caption]…[/caption]` shortcode (and nothing else of substance) —
 * lifting the captions to the table's parent level and dropping the
 * table / tbody / tr / td wrappers. Each unwrapped caption is padded
 * with `\n` so consecutive captions sit on their own lines separated
 * by a single blank line.
 *
 *     <table>
 *         <tbody>
 *             <tr><td>[caption]<img …> A.[/caption]</td></tr>
 *             <tr><td>[caption]<img …> B.[/caption]</td></tr>
 *         </tbody>
 *     </table>
 *
 * becomes
 *
 *     \n[caption]<img …> A.[/caption]\n
 *     \n[caption]<img …> B.[/caption]\n
 *
 * The legacy news.uams.edu exports use a multi-row layout table as a
 * gallery wrapper for a column of related captioned photos; the
 * destination CMS handles each caption shortcode independently at
 * the body level, so the table structure carries no semantic value.
 *
 * Match criteria. The `<table>` must have at least one `<td>` whose
 * trimmed text content is a single `[caption]…[/caption]` shortcode,
 * AND every other `<td>` under the table must EITHER:
 *
 *   - be empty (whitespace-only text content, no images or other
 *     non-trivial content) — the legacy markup uses an empty spacer
 *     `<td></td>` cell next to the caption cell, which carries no
 *     semantic value and is dropped along with the unwrap, OR
 *   - hold exactly one `[caption]…[/caption]` shortcode (trimmed
 *     text starts with `[caption`, ends with `[/caption]`, contains
 *     exactly one of each marker).
 *
 * Tables that mix caption and non-empty-non-caption cells, or that
 * contain cells with multiple captions or unbalanced markers, are
 * left alone.
 *
 * Same blank-line bookkeeping as the other unwrap rules
 * (`UnwrapWholeContentTable`, `UnwrapSingleCellTable`, …); the late
 * `CollapseExtraLineBreaks` rule collapses any 3+ consecutive `\n`
 * runs back to 2.
 */
final class UnwrapCaptionOnlyTable implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        $candidates = [];
        foreach (XpathNodes::query($xpath, '//table') as $table) {
            if ($table instanceof DOMElement && $this->qualifies($table)) {
                $candidates[] = $table;
            }
        }

        foreach ($candidates as $table) {
            $this->unwrap($table);
        }
    }

    private function qualifies(DOMElement $table): bool
    {
        $tds = $table->getElementsByTagName('td');
        if ($tds->length === 0) {
            return false;
        }

        $captionCells = 0;
        foreach ($tds as $td) {
            $trimmed = mb_trim($td->textContent);

            if ($trimmed === '' && $this->isVisuallyEmpty($td)) {
                continue;
            }

            if (! str_starts_with($trimmed, '[caption')) {
                return false;
            }

            if (! str_ends_with($trimmed, '[/caption]')) {
                return false;
            }

            if (substr_count($trimmed, '[caption') !== 1) {
                return false;
            }

            if (substr_count($trimmed, '[/caption]') !== 1) {
                return false;
            }

            $captionCells++;
        }

        return $captionCells > 0;
    }

    /**
     * True iff `$td` has no `<img>`/`<embed>`/other meaningful element
     * content and no non-whitespace text — i.e. a legacy spacer cell.
     */
    private function isVisuallyEmpty(DOMElement $td): bool
    {
        foreach ($td->childNodes as $child) {
            if ($child instanceof DOMText) {
                if (mb_trim($child->nodeValue ?? '') === '') {
                    continue;
                }

                return false;
            }

            if ($child instanceof DOMElement) {
                $tag = strtolower($child->nodeName);
                if ($tag === 'br') {
                    continue;
                }

                return false;
            }
        }

        return true;
    }

    private function unwrap(DOMElement $table): void
    {
        $parent = $table->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        $document = $table->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return;
        }

        $tds = [];
        foreach ($table->getElementsByTagName('td') as $td) {
            $tds[] = $td;
        }

        foreach ($tds as $td) {
            if (mb_trim($td->textContent) === '' && $this->isVisuallyEmpty($td)) {
                continue;
            }

            $parent->insertBefore($document->createTextNode("\n"), $table);
            while ($td->firstChild instanceof DOMNode) {
                $child = $td->firstChild;
                if ($child instanceof DOMText && mb_trim($child->nodeValue ?? '') === '') {
                    $td->removeChild($child);

                    continue;
                }

                $parent->insertBefore($child, $table);
            }

            $parent->insertBefore($document->createTextNode("\n"), $table);
        }

        $parent->removeChild($table);
    }
}
