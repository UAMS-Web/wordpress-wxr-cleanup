<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup;

use DOMDocument;

/**
 * A transform rule applied to the HTML content of a single WXR post.
 *
 * Each rule receives the DOMDocument wrapping the post's HTML fragment
 * (with a synthetic `<body>` envelope so DOMDocument has a single root)
 * and mutates it in place. The Transform pipeline handles envelope wrap /
 * unwrap and the WXR-level XML round-trip; rules just see the post body.
 */
interface Rule
{
    /**
     * Mutate the supplied document in place.
     */
    public function apply(DOMDocument $document): void;
}
