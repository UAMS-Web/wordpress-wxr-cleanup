<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMText;
use UamsWeb\WxrCleanup\Rule;

/**
 * Canonicalize `<a>` tags whose visible text is one of the UAMS hostnames.
 *
 * The legacy site is full of bare-hostname links that disagree with
 * themselves — the visible text says `uams.edu` but the `href` points
 * at `http://www.uams.edu/`, or worse, at an unrelated page on
 * `news.uams.edu`. The visible text is the authoritative signal of
 * intent, so we rewrite both `href` and text to a canonical form keyed
 * off the visible text.
 *
 *     <a href="http://www.uams.edu/">www.uams.edu</a>
 *     <a href="https://news.uams.edu/">uamshealth.com</a>
 *
 * become
 *
 *     <a href="https://uams.edu/">uams.edu</a>
 *     <a href="https://uamshealth.com/">uamshealth.com</a>
 *
 * Match criteria. An `<a>` qualifies if:
 *
 *   - it contains only text nodes (no nested elements),
 *   - the trimmed, lowercased text equals one of: `uams.edu`,
 *     `www.uams.edu`, `uamshealth.com`, `www.uamshealth.com`.
 *
 * Anchors with no `href` attribute (the legacy editor saved plenty of
 * link-styled-but-not-actually-linked `<a class="content">…</a>` tags)
 * are matched too — the visible text still expresses a clear intent,
 * and gaining a working link beats keeping a broken non-link. libxml's
 * HTML parser also occasionally drops the `href` when the source has
 * unbalanced attribute quoting (`<a target=" originalAttribute=">…`);
 * those cases get healed for free.
 *
 * Nested-element anchors (`<a><strong>uams.edu</strong></a>`) are left
 * alone — they're rare and rewriting them would lose markup; the
 * `<strong>` content gets canonicalized once `RenameBoldToStrong` and
 * friends settle the inner formatting, but the surrounding anchor is a
 * judgment call we defer.
 */
final class NormalizeUamsDomainAnchors implements Rule
{
    private const array CANONICAL = [
        'uams.edu' => ['href' => 'https://uams.edu/', 'text' => 'uams.edu'],
        'www.uams.edu' => ['href' => 'https://uams.edu/', 'text' => 'uams.edu'],
        'uamshealth.com' => ['href' => 'https://uamshealth.com/', 'text' => 'uamshealth.com'],
        'www.uamshealth.com' => ['href' => 'https://uamshealth.com/', 'text' => 'uamshealth.com'],
    ];

    public function apply(DOMDocument $document): void
    {
        $candidates = [];

        foreach ($document->getElementsByTagName('a') as $anchor) {
            $candidates[] = $anchor;
        }

        foreach ($candidates as $anchor) {
            if (! $this->isTextOnly($anchor)) {
                continue;
            }

            $key = strtolower(mb_trim($anchor->textContent));
            if (! isset(self::CANONICAL[$key])) {
                continue;
            }

            $target = self::CANONICAL[$key];
            $anchor->setAttribute('href', $target['href']);

            while ($anchor->firstChild !== null) {
                $anchor->removeChild($anchor->firstChild);
            }

            $anchor->appendChild($document->createTextNode($target['text']));
        }
    }

    private function isTextOnly(DOMElement $anchor): bool
    {
        foreach ($anchor->childNodes as $child) {
            if (! $child instanceof DOMText) {
                return false;
            }
        }

        return true;
    }
}
