<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Rules;

use DOMComment;
use DOMDocument;
use DOMNode;
use DOMXPath;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;
use UAMSWeb\Aether\WordPressImporter\Cleanup\XpathNodes;

/**
 * Remove every HTML comment node from the document.
 *
 * WordPress block-editor markup litters posts with paired comments
 * like `<!-- wp:paragraph -->` … `<!-- /wp:paragraph -->`, plus the
 * occasional editor-side note. None of those render — strip them all,
 * leaving the surrounding HTML intact.
 *
 * Runs first in the pipeline so subsequent rules never have to think
 * about comment siblings or interleaved comment-and-text nodes.
 */
final class StripHtmlComments implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        $comments = [];
        foreach (XpathNodes::query($xpath, '//comment()') as $comment) {
            if ($comment instanceof DOMComment) {
                $comments[] = $comment;
            }
        }

        foreach ($comments as $comment) {
            $parent = $comment->parentNode;
            if ($parent instanceof DOMNode) {
                $parent->removeChild($comment);
            }
        }
    }
}
