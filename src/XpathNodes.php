<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup;

use DOMNameSpaceNode;
use DOMNode;
use DOMXPath;
use EmptyIterator;
use Iterator;

final class XpathNodes
{
    /**
     * Run an XPath query and yield every matching node except namespace declarations.
     *
     * `DOMXPath::query()` returns false on invalid expressions; this helper
     * treats that as an empty result so callers can foreach safely.
     *
     * @return Iterator<int, DOMNode>
     */
    public static function query(DOMXPath $xpath, string $expression): Iterator
    {
        $matches = $xpath->query($expression);
        if ($matches === false) {
            return new EmptyIterator;
        }

        foreach ($matches as $node) {
            if ($node instanceof DOMNameSpaceNode) {
                continue;
            }

            yield $node;
        }
    }
}
