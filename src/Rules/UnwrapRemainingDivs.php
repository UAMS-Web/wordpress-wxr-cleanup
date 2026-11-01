<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;
use UAMSWeb\Aether\WordPressImporter\Cleanup\XpathNodes;

/**
 * Unwrap every remaining `<div>` element, leaving a blank line before
 * and after the unwrapped content.
 *
 * `UnwrapBareDivs` (pipeline rule 10) handles `<div>`s with no
 * attributes. `CaptionizeImageCutline` (rule 6) consumes the
 * `<div class="wp-caption …">` pattern. `RemoveEmptyContainers`
 * (rule 19) removes empty divs. Anything still standing at this
 * point is an attributed, non-empty, non-wp-caption div — a legacy
 * structural wrapper (`<div class="news_contentTitleLine">`,
 * `<div class="Section1">`, `<div align="center">`, …) whose
 * presentational shell carries no semantic value in the destination
 * CMS. Drop the wrapper; keep the content.
 *
 * The blank-line padding (`\n\n` text nodes inserted before and
 * after the unwrapped content) preserves the visual paragraph
 * separation a `<div>` was implicitly providing. `CollapseExtraLineBreaks`
 * (rule 27) then collapses any resulting 3+ consecutive `\n` runs
 * back down to 2, so two divs in a row, or a div adjacent to other
 * blank-line content, still ends up with exactly one blank line of
 * separation.
 *
 * Iteration uses a snapshot of all div elements at apply time. The
 * loop processes them in document order; nested divs unwrap
 * outermost-first, which moves the inner div up to the grandparent —
 * the inner div is still in the snapshot and gets unwrapped on its
 * own iteration. Outer wrappers around already-canonical
 * `[caption id="…"]…[/caption]` shortcodes (the double-wrap case)
 * collapse to just the inner shortcode plus blank-line padding.
 */
final class UnwrapRemainingDivs implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        $divs = [];
        foreach (XpathNodes::query($xpath, '//div') as $div) {
            if ($div instanceof DOMElement) {
                $divs[] = $div;
            }
        }

        foreach ($divs as $div) {
            $this->unwrapWithBlankLines($div);
        }
    }

    private function unwrapWithBlankLines(DOMElement $div): void
    {
        $parent = $div->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        $document = $div->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return;
        }

        $parent->insertBefore($document->createTextNode("\n\n"), $div);

        while ($div->firstChild instanceof DOMNode) {
            $parent->insertBefore($div->firstChild, $div);
        }

        $parent->insertBefore($document->createTextNode("\n\n"), $div);
        $parent->removeChild($div);
    }
}
