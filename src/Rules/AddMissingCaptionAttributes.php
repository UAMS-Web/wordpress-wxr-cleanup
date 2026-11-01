<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;

/**
 * Fill in the canonical attribute defaults on `[caption]` shortcodes
 * and on the `<img>` they wrap. Existing attributes are left untouched;
 * only missing ones are added.
 *
 *     [caption]<img src="…" alt="…">  Caption text.[/caption]
 *
 * becomes
 *
 *     [caption id="" align="aligncenter" width="300"]<img src="…" alt="…" width="300"> Caption text.[/caption]
 *
 * Defaults applied to the `[caption …]` opener token (text):
 *
 *   - `id=""` if no `id` attr is present.
 *   - `align="aligncenter"` if no `align` attr is present.
 *   - `width="300"` if no `width` attr is present.
 *
 * Default applied to each `<img>` between a `[caption]` opener and its
 * matching `[/caption]` closer:
 *
 *   - `width="300"` if the `<img>` has no `width` attribute.
 *
 * The img `height` is intentionally left alone — the importer derives
 * height from the aspect ratio when only `width` is present.
 *
 * Added attributes are prepended at the head of the opener's attribute
 * list in canonical order (`id`, `align`, `width`); existing attributes
 * are preserved verbatim in their original positions after the prepended
 * defaults. Whitespace inside the opener (`[caption  id="…"  align="…"]`)
 * is not normalized.
 *
 * Caption state tracking. The walk is depth-first; `$inCaption` flips
 * on each `[caption` opener and each `[/caption]` closer encountered
 * in text nodes. The two markers are unambiguous because `[/caption]`
 * does not start with `[caption`. State persists across element
 * boundaries — an opener inside a `<p>` and an img inside a sibling
 * still belong to the same caption span.
 *
 * Runs LATE in the pipeline (after `LiftCaptionsOutOfParagraph`) so
 * the structural caption-related rules see the original opener tokens
 * and don't accidentally key off attribute presence.
 */
final class AddMissingCaptionAttributes implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return;
        }

        $inCaption = false;
        $this->walk($body, $inCaption);
    }

    private function walk(DOMNode $node, bool &$inCaption): void
    {
        if ($node instanceof DOMText) {
            $text = $node->nodeValue ?? '';
            $rewritten = $this->rewriteTextNode($text, $inCaption);
            if ($rewritten !== $text) {
                $node->nodeValue = $rewritten;
            }

            return;
        }

        if ($node instanceof DOMElement
            && strtolower($node->nodeName) === 'img'
            && $inCaption
            && ! $node->hasAttribute('width')
        ) {
            $node->setAttribute('width', '300');
        }

        // Snapshot children — text rewrites don't mutate structure, so
        // a plain foreach over $node->childNodes is safe, but use a
        // snapshot for symmetry with other rules.
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            $this->walk($child, $inCaption);
        }
    }

    /**
     * Scan `$text` left-to-right for `[caption …]` openers and
     * `[/caption]` closers, rewriting each opener to add missing
     * attribute defaults. Updates `$inCaption` to the state at
     * end-of-text. The two marker strings don't share a prefix
     * (`[caption` vs `[/caption]`) so the search is unambiguous.
     */
    private function rewriteTextNode(string $text, bool &$inCaption): string
    {
        $out = '';
        $pos = 0;
        $len = strlen($text);

        while ($pos < $len) {
            $openerAt = strpos($text, '[caption', $pos);
            $closerAt = strpos($text, '[/caption]', $pos);

            $nextOpener = $openerAt !== false;
            $nextCloser = $closerAt !== false;

            if (! $nextOpener && ! $nextCloser) {
                $out .= substr($text, $pos);

                return $out;
            }

            if ($nextOpener && (! $nextCloser || $openerAt < $closerAt)) {
                $out .= substr($text, $pos, $openerAt - $pos);

                $endAt = strpos($text, ']', $openerAt + strlen('[caption'));
                if ($endAt === false) {
                    // Malformed opener (no closing `]`) — pass through.
                    $out .= substr($text, $openerAt);

                    return $out;
                }

                $opener = substr($text, $openerAt, $endAt - $openerAt + 1);
                $out .= $this->fillOpenerDefaults($opener);
                $inCaption = true;
                $pos = $endAt + 1;

                continue;
            }

            $out .= substr($text, $pos, $closerAt - $pos);
            $out .= '[/caption]';
            $inCaption = false;
            $pos = $closerAt + strlen('[/caption]');
        }

        return $out;
    }

    /**
     * Given a full opener token like `[caption]` or
     * `[caption id="x" width="500"]`, return a version with any of
     * `id`, `align`, `width` that were missing prepended in canonical
     * order. Existing attributes are preserved verbatim in their
     * original positions.
     */
    private function fillOpenerDefaults(string $opener): string
    {
        // Trim the `[caption` prefix and `]` suffix; what's left is the
        // attribute body (possibly empty, possibly leading-with-space).
        $body = substr($opener, strlen('[caption'), -1);

        $hasId = preg_match('/(?<![A-Za-z0-9_-])id\s*=/i', $body) === 1;
        $hasAlign = preg_match('/(?<![A-Za-z0-9_-])align\s*=/i', $body) === 1;
        $hasWidth = preg_match('/(?<![A-Za-z0-9_-])width\s*=/i', $body) === 1;

        if ($hasId && $hasAlign && $hasWidth) {
            return $opener;
        }

        $prepended = '';
        if (! $hasId) {
            $prepended .= ' id=""';
        }

        if (! $hasAlign) {
            $prepended .= ' align="aligncenter"';
        }

        if (! $hasWidth) {
            $prepended .= ' width="300"';
        }

        return '[caption'.$prepended.$body.']';
    }
}
