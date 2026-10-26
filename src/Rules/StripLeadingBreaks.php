<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMText;
use UamsWeb\WxrCleanup\Rule;

/**
 * Strip leading `<br>` elements (and surrounding whitespace) from the
 * start of a body envelope.
 *
 * Some legacy posts open with one or more `<br>` tags before any real
 * content — typically an editor pressing Enter at the top of the post
 * before pasting. Peel off every leading whitespace-only text node and
 * every leading `<br>` until the first node with actual content.
 *
 * Scoped to the synthetic `<body>` envelope (i.e. top-level only). A
 * leading `<br>` inside a wrapping `<p>` is left alone — those tend to
 * be intentional line breaks within structured content, not noise.
 */
final class StripLeadingBreaks implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body instanceof DOMElement) {
            return;
        }

        while (($first = $body->firstChild) instanceof \DOMNode) {
            if ($first instanceof DOMText && mb_trim($first->nodeValue ?? '') === '') {
                $body->removeChild($first);

                continue;
            }

            if ($first instanceof DOMElement && $first->nodeName === 'br') {
                $body->removeChild($first);

                continue;
            }

            break;
        }
    }
}
