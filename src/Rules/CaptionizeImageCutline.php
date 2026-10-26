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
 * Convert every `<img>` paired with a cutline element into a
 * `[caption]…[/caption]` shortcode wrapping the img and the cutline
 * text — the legacy news.uams.edu markup patterns for image captions.
 *
 *     <img class="x" style="float:left;" src="…" alt="…" />
 *     <span class="cutline">Caption text.</span>
 *
 *     <div class="wp-caption alignright" style="width: 310px;">
 *         <img src="…" alt="…" width="300" height="194" />
 *         <p class="wp-caption-text">Caption text.</p>
 *     </div>
 *
 * both become
 *
 *     [caption]<img src="…" alt="…"> Caption text.[/caption]
 *
 * Pairing logic.
 *
 *   E. `<p align="right|left">` or `<p style="… text-align: right|left">`
 *      with an `<img>` followed by text content (any mix of text
 *      nodes and `<br>` separators) — the `<br>` is optional, and
 *      multiple `<br>`s inside the text region are tolerated (each
 *      becomes a single space; `CollapseCaptionInternalWhitespace`
 *      later normalizes runs of whitespace to a single space).
 *      Other elements in the text region disqualify. The `<p>`'s
 *      alignment maps to the shortcode's `align` attribute
 *      (`right` → `alignright`, `left` → `alignleft`). The wrapping
 *      `<p>` is removed; the caption appears at its position padded
 *      with `\n` so it sits on its own line.
 *
 *   F. `<td>` whose meaningful children are exactly an `<img>`, a
 *      `<br>`, and one or more text nodes (in that order, with
 *      whitespace-only text nodes tolerated). The wrapping `<td>`
 *      stays put; its contents are replaced with a bare
 *      `[caption]<img …> caption text[/caption]` shortcode. The
 *      downstream `UnwrapCaptionOnlyTable` rule (rule 30) lifts
 *      the captions out of the table when every cell qualifies.
 *
 *   G. A cutline element (`<font|span|div|p class="cutline">`) that
 *      WRAPS its `<img>` (img is a child of the cutline, not a
 *      sibling). Inside the wrapper we accept exactly one `<img>`,
 *      one or more text nodes, and `<br>` separators (treated as
 *      soft spaces); any other element disqualifies. On success the
 *      cutline element is removed; the resulting bare `[caption]`
 *      lands at the cutline's parent level.
 *
 *   D. `<div class="wp-caption …">` (any alignment modifier, or none)
 *      wrapping an `<img>` and a `<p class="wp-caption-text">`. The
 *      wrapping div is removed; the img and the paragraph's text
 *      migrate up to its position.
 *
 *   A. The next non-trivial sibling of the img is itself a
 *      `<div class="cutline">`, `<span class="cutline">`, or
 *      `<font class="cutline">` (the legacy serializer's form).
 *   B. The next non-trivial sibling of the img is a `<p>` whose
 *      ONLY meaningful child is a cutline. The wrapping `<p>` is
 *      unwrapped before captionizing.
 *   C. The img is the only meaningful child of a wrapping `<p>`,
 *      and the wrapping `<p>`'s next non-trivial sibling is either
 *      a cutline (A pattern lifted by one level) or a `<p>` whose
 *      only meaningful child is a cutline (B pattern lifted by one
 *      level). The img is lifted out of the wrapping `<p>`.
 *
 * Path E, Path F, Path G, and Path D run first as paragraph- /
 * table-cell- / cutline-wrapper- / div-anchored pre-passes; A/B/C
 * then run as img-anchored sweeps over whatever's left.
 *
 * "Non-trivial" means: not a whitespace-only text node and not a
 * `<br>` element. Whitespace and `<br>`s between the img and the
 * cutline are consumed as part of the conversion.
 *
 * Img attribute handling. The img keeps `src`, `alt`, `width`, and
 * `height`. Every other attribute (`class`, `style`, `longdesc`, …)
 * is dropped — the importer reads structure from the shortcode, not
 * from presentational img attrs. `width` and `height` are preserved
 * because downstream rules use them (and pass them through to the
 * importer) to size the caption block.
 *
 * `class="cutline"` and `class="wp-caption"` / `class="wp-caption-text"`
 * are all matched as whitespace-separated tokens, so multi-class
 * attrs (`class="wp-caption alignright"`) trigger as well as plain
 * single-class. Tag name match is case-insensitive.
 *
 * Runs BEFORE `UnwrapSpans` so the `<span class="cutline">` form is
 * still detectable. Runs after `StripMsoNoise` so any inline `style`
 * attrs on the img are already gone when attribute-stripping runs.
 */
final class CaptionizeImageCutline implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        // Path E — <p align="right|left"> or <p style="text-align: right|left">
        // with img + br + text (paragraph-anchored pre-pass).
        $alignedParagraphs = [];
        foreach (XpathNodes::query($xpath, '//p') as $p) {
            if ($p instanceof DOMElement && $this->paragraphAlignment($p) !== null) {
                $alignedParagraphs[] = $p;
            }
        }

        foreach ($alignedParagraphs as $p) {
            $this->tryCaptionizeAlignedParagraph($p);
        }

        // Path F — <td> whose meaningful children are img + br + text
        // (table-cell-anchored pre-pass). The wrapping <td> stays put;
        // `UnwrapCaptionOnlyTable` later unwraps the table when every
        // cell holds only a caption.
        $cells = [];
        foreach (XpathNodes::query($xpath, '//td') as $td) {
            if ($td instanceof DOMElement) {
                $cells[] = $td;
            }
        }

        foreach ($cells as $td) {
            $this->tryCaptionizeTableCell($td);
        }

        // Path G — cutline element WRAPS the img (img is inside the
        // cutline rather than a sibling of it). The legacy markup
        // sometimes serializes a captioned image as
        // `<font class="cutline"><img …><br>text</font>` or the
        // equivalent with `<span|div|p class="cutline">` as the
        // wrapper. Convert each such wrapper to a `[caption]` shortcode
        // at the wrapper's parent level.
        $cutlineWrappers = [];
        foreach (XpathNodes::query($xpath, '//*[@class]') as $element) {
            if ($element instanceof DOMElement && $this->isCutlineElement($element)) {
                $cutlineWrappers[] = $element;
            }
        }

        foreach ($cutlineWrappers as $cutline) {
            $this->tryCaptionizeCutlineWrapper($cutline);
        }

        // Path D — wp-caption div blocks (div-anchored pre-pass).
        $wpCaptionDivs = [];
        foreach (XpathNodes::query($xpath, '//div') as $div) {
            if ($div instanceof DOMElement
                && $this->hasClassToken($div->getAttribute('class'), 'wp-caption')
            ) {
                $wpCaptionDivs[] = $div;
            }
        }

        foreach ($wpCaptionDivs as $div) {
            $this->tryCaptionizeWpCaptionDiv($div);
        }

        // Paths A/B/C — img-anchored sweep.
        $imgs = [];
        foreach (XpathNodes::query($xpath, '//img') as $img) {
            if ($img instanceof DOMElement) {
                $imgs[] = $img;
            }
        }

        foreach ($imgs as $img) {
            $this->tryCaptionize($img);
        }
    }

    /**
     * Convert a `<p align="right">` or `<p align="left">` whose
     * meaningful children are an `<img>`, a `<br>`, and one or more
     * text nodes (in that order) into a `[caption align="…"]…[/caption]`
     * shortcode at the paragraph's position. The wrapping `<p>` is
     * removed and `\n` padding is added before the opener and after
     * the closer so the caption sits on its own line.
     */
    private function tryCaptionizeAlignedParagraph(DOMElement $p): void
    {
        $align = $this->paragraphAlignment($p);
        if ($align === null) {
            return;
        }

        $img = null;
        $textParts = [];
        $stage = 'expect_img';

        foreach ($p->childNodes as $child) {
            if ($child instanceof DOMText
                && mb_trim($child->nodeValue ?? '') === ''
                && $stage === 'expect_img'
            ) {
                continue;
            }

            if ($stage === 'expect_img') {
                if ($child instanceof DOMElement && strtolower($child->nodeName) === 'img') {
                    $img = $child;
                    $stage = 'collect_text';

                    continue;
                }

                return;
            }

            // stage === 'collect_text' — accept text and <br>, anything else
            // disqualifies. <br> becomes a soft separator (single space);
            // `CollapseCaptionInternalWhitespace` later normalizes runs of
            // whitespace inside the caption to a single space.
            if ($child instanceof DOMText) {
                $textParts[] = $child->nodeValue ?? '';

                continue;
            }

            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'br') {
                $textParts[] = ' ';

                continue;
            }

            return;
        }

        if (! $img instanceof DOMElement) {
            return;
        }

        $text = mb_trim(implode('', $textParts));
        if ($text === '') {
            return;
        }

        $parent = $p->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        $document = $p->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return;
        }

        $this->stripNonEssentialImgAttributes($img);

        $alignToken = ($align === 'right') ? 'alignright' : 'alignleft';

        $parent->insertBefore(
            $document->createTextNode("\n[caption align=\"{$alignToken}\"]"),
            $p,
        );
        $parent->insertBefore($img, $p);
        $parent->insertBefore(
            $document->createTextNode(' '.$text."[/caption]\n"),
            $p,
        );
        $parent->removeChild($p);
    }

    /**
     * Return `'right'` / `'left'` if `$p` carries either an
     * `align="right|left"` attribute (case-insensitive) or a
     * `style="… text-align: right|left …"` declaration (any case,
     * any whitespace, with or without a trailing semicolon).
     * Returns null otherwise.
     */
    private function paragraphAlignment(DOMElement $p): ?string
    {
        $align = strtolower($p->getAttribute('align'));
        if ($align === 'right' || $align === 'left') {
            return $align;
        }

        $style = $p->getAttribute('style');
        if ($style !== '' && preg_match('/(?<![A-Za-z-])text-align\s*:\s*(right|left)\b/i', $style, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * Convert a `<td>` whose meaningful children are an `<img>`, a
     * `<br>`, and one or more text nodes into a bare
     * `[caption]<img …> caption text[/caption]` shortcode living
     * inside the same `<td>`. The wrapping `<td>` stays put;
     * `UnwrapCaptionOnlyTable` (rule 30) lifts the caption out
     * later when every cell in the table qualifies. The bare
     * `[caption]` opener picks up its `id`/`align`/`width` defaults
     * from `AddMissingCaptionAttributes` (rule 27) — the resulting
     * shortcode ends up `[caption id="" align="aligncenter" width="300"]`.
     */
    private function tryCaptionizeTableCell(DOMElement $td): void
    {
        $img = null;
        $br = null;
        $textParts = [];
        $stage = 'expect_img';

        foreach ($td->childNodes as $child) {
            if ($child instanceof DOMText
                && mb_trim($child->nodeValue ?? '') === ''
                && $stage !== 'collect_text'
            ) {
                continue;
            }

            if ($stage === 'expect_img') {
                if ($child instanceof DOMElement && strtolower($child->nodeName) === 'img') {
                    $img = $child;
                    $stage = 'expect_br';

                    continue;
                }

                return;
            }

            if ($stage === 'expect_br') {
                if ($child instanceof DOMElement && strtolower($child->nodeName) === 'br') {
                    $br = $child;
                    $stage = 'collect_text';

                    continue;
                }

                return;
            }

            // stage === 'collect_text'
            if ($child instanceof DOMText) {
                $textParts[] = $child->nodeValue ?? '';

                continue;
            }

            return;
        }

        if (! $img instanceof DOMElement || ! $br instanceof DOMElement) {
            return;
        }

        $text = mb_trim(implode('', $textParts));
        if ($text === '') {
            return;
        }

        $document = $td->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return;
        }

        $this->stripNonEssentialImgAttributes($img);

        while ($td->firstChild instanceof DOMNode) {
            $td->removeChild($td->firstChild);
        }

        $td->appendChild($document->createTextNode('[caption]'));
        $td->appendChild($img);
        $td->appendChild($document->createTextNode(' '.$text.'[/caption]'));
    }

    /**
     * Convert a cutline element (`<font|span|div|p class="cutline">`)
     * that WRAPS its `<img>` (img is a child of the cutline rather
     * than a sibling) into a `[caption]<img …> caption text[/caption]`
     * shortcode at the cutline's parent level. The cutline must
     * contain exactly one `<img>` and one or more text nodes (with
     * `<br>` separators tolerated as soft spaces); any other element
     * disqualifies. On success the wrapping cutline element is
     * removed.
     */
    private function tryCaptionizeCutlineWrapper(DOMElement $cutline): void
    {
        $img = null;
        $textParts = [];

        foreach ($cutline->childNodes as $child) {
            if ($child instanceof DOMText) {
                $textParts[] = $child->nodeValue ?? '';

                continue;
            }

            if (! $child instanceof DOMElement) {
                return;
            }

            $tag = strtolower($child->nodeName);

            if ($tag === 'img') {
                if ($img instanceof DOMElement) {
                    return;
                }

                $img = $child;

                continue;
            }

            if ($tag === 'br') {
                $textParts[] = ' ';

                continue;
            }

            return;
        }

        if (! $img instanceof DOMElement) {
            return;
        }

        $text = mb_trim(implode('', $textParts));
        if ($text === '') {
            return;
        }

        $parent = $cutline->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        $document = $cutline->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return;
        }

        $this->stripNonEssentialImgAttributes($img);

        $parent->insertBefore($document->createTextNode('[caption]'), $cutline);
        $parent->insertBefore($img, $cutline);
        $parent->insertBefore(
            $document->createTextNode(' '.$text.'[/caption]'),
            $cutline,
        );
        $parent->removeChild($cutline);
    }

    /**
     * Convert a `<div class="wp-caption …">` wrapper into a `[caption]`
     * shortcode. The div must contain exactly one `<img>` and exactly
     * one `<p class="wp-caption-text">` as its meaningful children
     * (whitespace text nodes and `<br>` are tolerated). On success the
     * div is removed; the img and caption text emerge at the div's
     * position wrapped in `[caption]` / `[/caption]`.
     */
    private function tryCaptionizeWpCaptionDiv(DOMElement $div): void
    {
        $img = null;
        $captionP = null;

        foreach ($div->childNodes as $child) {
            if ($child instanceof DOMText && mb_trim($child->wholeText) === '') {
                continue;
            }

            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'br') {
                continue;
            }

            if (! $child instanceof DOMElement) {
                return;
            }

            $tag = strtolower($child->nodeName);

            if ($tag === 'img' && ! $img instanceof DOMElement) {
                $img = $child;

                continue;
            }

            if ($tag === 'p'
                && $this->hasClassToken($child->getAttribute('class'), 'wp-caption-text')
                && ! $captionP instanceof DOMElement
            ) {
                $captionP = $child;

                continue;
            }

            return;
        }

        if (! $img instanceof DOMElement || ! $captionP instanceof DOMElement) {
            return;
        }

        $parent = $div->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        $document = $div->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return;
        }

        $this->stripNonEssentialImgAttributes($img);

        $parent->insertBefore($document->createTextNode('[caption]'), $div);
        $parent->insertBefore($img, $div);
        $parent->insertBefore($document->createTextNode(' '), $div);

        while ($captionP->firstChild instanceof DOMNode) {
            $parent->insertBefore($captionP->firstChild, $div);
        }

        $parent->insertBefore($document->createTextNode('[/caption]'), $div);
        $parent->removeChild($div);
    }

    private function tryCaptionize(DOMElement $img): void
    {
        // Path A / B — cutline is reachable from img's own siblings.
        $match = $this->findCutlineForAnchor($img);
        if ($match !== null) {
            [$cutline, $wrappingP] = $match;
            if ($wrappingP !== null) {
                $this->unwrapWrappingP($wrappingP, $cutline);
            }

            $this->convertToCaption($img, $cutline);

            return;
        }

        // Path C — img is the only meaningful child of a wrapping <p>;
        // cutline is reachable from the wrapping <p>'s siblings. Lift the
        // img out of its wrapping <p> first.
        $wrapper = $img->parentNode;
        if (! $wrapper instanceof DOMElement) {
            return;
        }

        if (strtolower($wrapper->nodeName) !== 'p') {
            return;
        }

        if (! $this->isImgOnlyMeaningfulChild($wrapper, $img)) {
            return;
        }

        $match = $this->findCutlineForAnchor($wrapper);
        if ($match === null) {
            return;
        }

        [$cutline, $wrappingP] = $match;
        if ($wrappingP !== null) {
            $this->unwrapWrappingP($wrappingP, $cutline);
        }

        $grandparent = $wrapper->parentNode;
        if (! $grandparent instanceof DOMNode) {
            return;
        }

        // Lift img out of its wrapping <p>, drop the now-empty <p>.
        $grandparent->insertBefore($img, $wrapper);
        $grandparent->removeChild($wrapper);

        $this->convertToCaption($img, $cutline);
    }

    /**
     * Look for a cutline reachable from `$anchor`'s next-sibling
     * direction. Returns `[cutlineElement, wrappingP|null]` on success.
     * `wrappingP` is non-null when the cutline is wrapped in a `<p>` and
     * that `<p>` should be removed after captionizing.
     *
     * @return array{0: DOMElement, 1: DOMElement|null}|null
     */
    private function findCutlineForAnchor(DOMElement $anchor): ?array
    {
        $next = $this->nextSubstantiveSibling($anchor);
        if (! $next instanceof DOMElement) {
            return null;
        }

        $tag = strtolower($next->nodeName);

        if ($this->isCutlineElement($next)) {
            return [$next, null];
        }

        if ($tag === 'p') {
            $inner = $this->onlyMeaningfulChild($next);
            if ($inner instanceof DOMElement && $this->isCutlineElement($inner)) {
                return [$inner, $next];
            }
        }

        return null;
    }

    /**
     * True iff `$element` is a `<div>`, `<span>`, or `<font>` carrying
     * the `cutline` class token. `<font>` is included for the legacy
     * news.uams.edu markup that wraps caption text in `<FONT class=cutline>`
     * (the editor's old serializer); semantically equivalent to the
     * later `<span class="cutline">` form.
     */
    private function isCutlineElement(DOMElement $element): bool
    {
        $tag = strtolower($element->nodeName);

        if (! in_array($tag, ['div', 'span', 'font', 'p'], true)) {
            return false;
        }

        return $this->hasClassToken($element->getAttribute('class'), 'cutline');
    }

    /**
     * Next sibling skipping whitespace-only text nodes and `<br>` elements.
     */
    private function nextSubstantiveSibling(DOMNode $node): ?DOMNode
    {
        $next = $node->nextSibling;

        while ($next instanceof DOMNode) {
            if ($next instanceof DOMText && mb_trim($next->wholeText) === '') {
                $next = $next->nextSibling;

                continue;
            }

            if ($next instanceof DOMElement && strtolower($next->nodeName) === 'br') {
                $next = $next->nextSibling;

                continue;
            }

            return $next;
        }

        return null;
    }

    /**
     * True iff every child of `$parent` is either `$target`, a
     * whitespace-only text node, or a `<br>`.
     */
    private function isImgOnlyMeaningfulChild(DOMElement $parent, DOMNode $target): bool
    {
        foreach ($parent->childNodes as $child) {
            if ($child === $target) {
                continue;
            }

            if ($child instanceof DOMText && mb_trim($child->wholeText) === '') {
                continue;
            }

            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'br') {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * If `$parent` has exactly one meaningful child element (after
     * skipping whitespace text and `<br>`s), return it. Otherwise null.
     */
    private function onlyMeaningfulChild(DOMElement $parent): ?DOMElement
    {
        $found = null;

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMText && mb_trim($child->wholeText) === '') {
                continue;
            }

            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'br') {
                continue;
            }

            if (! $child instanceof DOMElement) {
                return null;
            }

            if ($found instanceof DOMElement) {
                return null;
            }

            $found = $child;
        }

        return $found;
    }

    /**
     * Move `$cutline` to take `$wrappingP`'s place at the grandparent
     * level, then drop the (now empty) `$wrappingP`.
     */
    private function unwrapWrappingP(DOMElement $wrappingP, DOMElement $cutline): void
    {
        $grandparent = $wrappingP->parentNode;
        if (! $grandparent instanceof DOMNode) {
            return;
        }

        $grandparent->insertBefore($cutline, $wrappingP);
        $grandparent->removeChild($wrappingP);
    }

    private function hasClassToken(string $classAttr, string $token): bool
    {
        $tokens = preg_split('/\s+/', mb_trim($classAttr));
        if ($tokens === false) {
            return false;
        }

        return in_array($token, $tokens, true);
    }

    private function convertToCaption(DOMElement $img, DOMElement $cutline): void
    {
        $parent = $img->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        $document = $img->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return;
        }

        $this->stripNonEssentialImgAttributes($img);
        $this->removeNodesBetween($parent, $img, $cutline);

        $openerToken = '[caption';
        if ($parent instanceof DOMElement && strtolower($parent->nodeName) === 'p') {
            $align = $this->paragraphAlignment($parent);
            if ($align !== null) {
                $openerToken .= ' align="align'.$align.'"';
            }
        }

        $openerToken .= ']';

        $parent->insertBefore($document->createTextNode($openerToken), $img);
        $parent->insertBefore($document->createTextNode(' '), $cutline);

        while ($cutline->firstChild instanceof DOMNode) {
            $parent->insertBefore($cutline->firstChild, $cutline);
        }

        $parent->insertBefore($document->createTextNode('[/caption]'), $cutline);
        $parent->removeChild($cutline);
    }

    private function stripNonEssentialImgAttributes(DOMElement $img): void
    {
        $keep = ['src', 'alt', 'width', 'height'];

        $toRemove = [];
        foreach ($img->attributes as $attr) {
            if (! in_array(strtolower($attr->nodeName), $keep, true)) {
                $toRemove[] = $attr->nodeName;
            }
        }

        foreach ($toRemove as $name) {
            $img->removeAttribute($name);
        }
    }

    private function removeNodesBetween(DOMNode $parent, DOMNode $start, DOMNode $end): void
    {
        $cursor = $start->nextSibling;
        $toRemove = [];

        while ($cursor instanceof DOMNode && $cursor !== $end) {
            $toRemove[] = $cursor;
            $cursor = $cursor->nextSibling;
        }

        foreach ($toRemove as $node) {
            $parent->removeChild($node);
        }
    }
}
