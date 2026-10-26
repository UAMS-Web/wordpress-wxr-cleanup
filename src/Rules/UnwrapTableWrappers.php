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
 * Lift every `<table>` out of a `<p>`, `<strong>`, or `<em>` wrapper,
 * leaving a blank line before the opening `<table>` and after the
 * closing `</table>`.
 *
 *     <p><table>…</table></p>
 *     <strong><table>…</table></strong>
 *     <em><table>…</table></em>
 *
 * all become
 *
 *     <table>…</table>
 *
 * with two `\n` characters (one blank line) of padding on each side.
 *
 * Why each of those three. `<p>` cannot legally contain a `<table>`
 * — the HTML parser implicitly closes the `<p>` when it sees a
 * `<table>`, so this case is normally an authoring artifact from
 * the legacy editor's serializer. `<strong>` and `<em>` are inline
 * elements and similarly cannot contain block-level structure;
 * when they do, the destination CMS interprets the markup
 * unpredictably.
 *
 * Both the padding and the wrapper-unwrap are done in two passes:
 * first the `\n\n` text nodes are inserted around each affected
 * `<table>` while the wrapper is still in place, then the wrapper
 * is unwrapped. Multiple-level wrappers (`<p><strong><table></strong></p>`)
 * are handled by walking the ancestor chain from each table — all
 * consecutive `<p>`/`<strong>`/`<em>` ancestors get collected and
 * unwrapped.
 *
 * Other content inside the wrapper survives. `<p>Lead text<table>…</table>
 * trail text</p>` becomes `Lead text` + blank line + `<table>` + blank
 * line + `trail text` at the wrapper's parent level — paragraphs of
 * mixed content that included a table lose their paragraph context,
 * but the table boundary is now clean and `CollapseExtraLineBreaks`
 * (later in the pipeline) normalizes any `\n` runs back down.
 *
 * Runs before `UnwrapWholeContentTable` so that a body whose only
 * meaningful child is a `<p><table>…</table></p>` becomes eligible
 * for whole-content-table unwrapping once this rule has lifted the
 * table out of the paragraph.
 */
final class UnwrapTableWrappers implements Rule
{
    private const array WRAPPER_TAGS = ['p', 'strong', 'em'];

    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        $tables = [];
        foreach (XpathNodes::query($xpath, '//table') as $table) {
            if ($table instanceof DOMElement) {
                $tables[] = $table;
            }
        }

        $wrappers = [];

        foreach ($tables as $table) {
            $parent = $table->parentNode;
            if (! $parent instanceof DOMElement) {
                continue;
            }

            if (! $this->isWrapper($parent)) {
                continue;
            }

            $this->padAroundTable($table);

            $cursor = $parent;
            while ($cursor instanceof DOMElement && $this->isWrapper($cursor)) {
                $wrappers[spl_object_hash($cursor)] = $cursor;
                $cursor = $cursor->parentNode;
            }
        }

        foreach ($wrappers as $wrapper) {
            $this->unwrapElement($wrapper);
        }
    }

    private function isWrapper(DOMElement $element): bool
    {
        return in_array(strtolower($element->nodeName), self::WRAPPER_TAGS, true);
    }

    private function padAroundTable(DOMElement $table): void
    {
        $parent = $table->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        $document = $table->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return;
        }

        $parent->insertBefore($document->createTextNode("\n\n"), $table);

        $afterAnchor = $table->nextSibling;
        if ($afterAnchor instanceof DOMNode) {
            $parent->insertBefore($document->createTextNode("\n\n"), $afterAnchor);
        } else {
            $parent->appendChild($document->createTextNode("\n\n"));
        }
    }

    private function unwrapElement(DOMElement $element): void
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
