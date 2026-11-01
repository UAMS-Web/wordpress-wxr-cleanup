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
 * Convert every `<embed>` whose `src` matches the legacy WordPress
 * YouTube-embed shape (`http://www.youtube.com/v/{id}…` /
 * `https://www.youtube.com/v/{id}…`) into a standalone YouTube watch
 * URL — leaving a blank line before and after the converted URL.
 *
 *     <embed src="http://www.youtube.com/v/wc3wToFuBww&hl=en&fs=1&rel=0" width="425" height="344" type="application/x-shockwave-flash" allowfullscreen="true" allowscriptaccess="always" />
 *
 * becomes
 *
 *     \nhttps://www.youtube.com/watch?v=wc3wToFuBww\n
 *
 * The destination CMS auto-converts a bare YouTube URL on its own
 * line into an embed; `\n` padding ensures the URL sits on its own
 * line.
 *
 * Match. `src` starts with `http://www.youtube.com/v/` or
 * `https://www.youtube.com/v/`, followed by a YouTube video ID
 * (one or more of `A-Za-z0-9_-`). Trailing `&hl=…`, `&fs=1`, `?rel=0`,
 * etc. are dropped; the canonical watch URL contains only `v=<id>`.
 *
 * Non-YouTube `<embed>` elements (other src patterns, no src
 * attribute) are left alone.
 *
 * Same blank-line bookkeeping as `UnwrapEmbedOnlyParagraph` — the
 * late `CollapseExtraLineBreaks` rule collapses any 3+ consecutive
 * `\n` runs back to 2.
 */
final class ConvertYoutubeEmbedToUrl implements Rule
{
    public function apply(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        $candidates = [];
        foreach (XpathNodes::query($xpath, '//embed[@src]') as $embed) {
            if (! $embed instanceof DOMElement) {
                continue;
            }

            $videoId = $this->extractVideoId($embed->getAttribute('src'));
            if ($videoId === null) {
                continue;
            }

            $candidates[] = [$embed, $videoId];
        }

        foreach ($candidates as [$embed, $videoId]) {
            $this->convert($embed, $videoId);
        }
    }

    private function extractVideoId(string $src): ?string
    {
        if (preg_match('#^https?://www\.youtube\.com/v/([A-Za-z0-9_-]+)#', $src, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function convert(DOMElement $embed, string $videoId): void
    {
        $parent = $embed->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        $document = $embed->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return;
        }

        $url = 'https://www.youtube.com/watch?v='.$videoId;
        $parent->insertBefore($document->createTextNode("\n".$url."\n"), $embed);
        $parent->removeChild($embed);
    }
}
