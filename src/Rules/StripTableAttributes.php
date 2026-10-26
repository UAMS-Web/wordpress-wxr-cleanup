<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMXPath;
use UamsWeb\WxrCleanup\Rule;
use UamsWeb\WxrCleanup\XpathNodes;

/**
 * Strip a configured set of presentational attributes from `<table>`,
 * `<td>`, `<ul>` (and any other tag configured in the map).
 *
 * The default map matches the legacy news.uams.edu export patterns:
 * `<table>` carries `height`/`width`/`border`/`cellpadding`/`cellspacing`,
 * `<td>` carries `valign`, `<ul>` carries `type` (e.g. `circle`, `disc`,
 * `square` — presentational bullet style). Structural attributes
 * (`scope`, `headers`, `rowspan`, `colspan`, etc.) and class names are
 * deliberately left untouched so accessible markup on genuine data
 * tables survives. The rule keeps its name even though its scope has
 * grown past tables — the class is configurable on construction.
 */
final class StripTableAttributes implements Rule
{
    /**
     * @param  array<string, list<string>>  $tagAttributes  Map of tag name → list of attribute names to strip.
     */
    public function __construct(
        private readonly array $tagAttributes = [
            'table' => ['height', 'width', 'border', 'cellpadding', 'cellspacing'],
            'td' => ['valign'],
            'ul' => ['type'],
        ],
    ) {}

    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        foreach ($this->tagAttributes as $tag => $attrs) {
            foreach (XpathNodes::query($xpath, '//'.$tag) as $element) {
                if (! $element instanceof DOMElement) {
                    continue;
                }

                foreach ($attrs as $attr) {
                    if ($element->hasAttribute($attr)) {
                        $element->removeAttribute($attr);
                    }
                }
            }
        }
    }
}
