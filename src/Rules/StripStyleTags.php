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
 * Strip — wrapper AND content — every `<style>` element from the body.
 *
 * Word's HTML paste emits a top-of-content `<style>` block carrying the
 * full font-definition + Mso paragraph stylesheet (often hundreds of
 * rules wrapped in an HTML comment). The CSS never reaches the
 * destination CMS, the comment-wrapped body is parsed as a stylesheet
 * by some libxml builds and as text by others, and the whole block is
 * pure clutter regardless. Drop it.
 *
 * Case-insensitive on the tag name so `<STYLE>` from older Word
 * variants matches too.
 */
final class StripStyleTags implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        $lower = "translate(name(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')";
        $query = "//*[{$lower}='style']";

        $elements = [];
        foreach (XpathNodes::query($xpath, $query) as $element) {
            if ($element instanceof DOMElement) {
                $elements[] = $element;
            }
        }

        foreach ($elements as $element) {
            $parent = $element->parentNode;
            if (! $parent instanceof DOMNode) {
                continue;
            }

            $parent->removeChild($element);
        }
    }
}
