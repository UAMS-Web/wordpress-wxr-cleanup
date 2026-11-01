<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup;

use DOMDocument;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\AddMissingCaptionAttributes;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\CaptionizeImageCutline;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\CollapseBareDoubleBr;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\CollapseCaptionInternalWhitespace;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\CollapseExtraLineBreaks;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\CollapseHorizontalWhitespace;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\ConvertDoubleHyphenToEmDash;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\ConvertYoutubeEmbedToUrl;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\LiftCaptionsOutOfParagraph;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\LiftTrailingBr;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\MergeAdjacentLists;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\NormalizeCityDateline;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\NormalizeUamsDomainAnchors;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\PromoteBoldToInnerStrong;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\RemoveEmptyContainers;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\RenameBoldToStrong;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\RenameItalicsToEm;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\ReplaceBareBrWithBlankLine;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\ReplaceCurlyQuotes;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\RestoreSplitCaptionCloser;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\SplitParagraphsOnDoubleBr;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\StripAlignLeft;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\StripHtmlComments;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\StripLeadingBreaks;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\StripLeadingDateLine;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\StripMsoNoise;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\StripOfficeNamespaceTags;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\StripOrdinalSupTags;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\StripStyleTags;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\StripTableAttributes;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\StripWhitespaceBeforeBr;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\TrimBlockEdges;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\TrimLineEdges;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\UnwrapBareDivs;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\UnwrapCaptionImageAnchors;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\UnwrapCaptionOnlyParagraph;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\UnwrapCaptionOnlyTable;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\UnwrapDefinitionLists;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\UnwrapEmbedOnlyParagraph;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\UnwrapEmptyBolds;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\UnwrapRemainingDivs;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\UnwrapSingleCellTable;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\UnwrapSmartTags;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\UnwrapSpans;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\UnwrapTableWrappers;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\UnwrapUnderlines;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rules\UnwrapWholeContentTable;

/**
 * Applies a configured set of cleanup rules to a WordPress WXR export.
 *
 * Cleanup is scoped to three WXR elements per item: `<title>`,
 * `<content:encoded>`, and `<excerpt:encoded>`. Each one is wrapped in a
 * synthetic `<body>` envelope so it can be parsed as a single-rooted
 * DOMDocument, the configured rules run against the envelope in order,
 * and the cleaned result is written back into the original element as a
 * CDATA section. Other WXR elements (`<wp:meta_value>`, `<dc:creator>`,
 * etc.) are left untouched.
 *
 * `<title>` and `<content:encoded>` arrive as raw HTML. `<excerpt:encoded>`
 * arrives entity-encoded (`&lt;span&gt;` rather than `<span>`) — the entity
 * decode runs before rules; afterward `strip_tags` + `mb_trim` reduces the
 * excerpt to plain text. Content is `mb_trim`-ed too so the recurring
 * trailing `&nbsp;` "blank line" the legacy editor appended on save is
 * stripped.
 */
final class Transform
{
    /**
     * @param  list<Rule>  $rules
     */
    public function __construct(
        private readonly array $rules,
    ) {}

    /**
     * A transform running the default rule pipeline, the one `bin/wxr-cleanup` runs.
     */
    public static function withDefaultRules(): self
    {
        return new self(self::defaultRules());
    }

    /**
     * Every rule, in the order the pipeline applies them.
     *
     * Order is significant; the README's `## Rules` table explains why each rule sits where it does.
     * This is the one place the order is defined, so the CLI and any library consumer run the same
     * pipeline.
     *
     * @return list<Rule>
     */
    public static function defaultRules(): array
    {
        return [
            new StripHtmlComments,
            new StripStyleTags,
            new CaptionizeImageCutline,
            new StripMsoNoise,
            new StripTableAttributes,
            new StripAlignLeft,
            new UnwrapSpans,
            new UnwrapSmartTags,
            new StripOfficeNamespaceTags,
            new UnwrapBareDivs,
            new UnwrapUnderlines,
            new MergeAdjacentLists,
            new SplitParagraphsOnDoubleBr,
            new PromoteBoldToInnerStrong,
            new LiftTrailingBr,
            new TrimBlockEdges,
            new CollapseBareDoubleBr,
            new UnwrapEmptyBolds,
            new RemoveEmptyContainers,
            new UnwrapRemainingDivs,
            new UnwrapDefinitionLists,
            new UnwrapCaptionImageAnchors,
            new UnwrapCaptionOnlyParagraph,
            new RestoreSplitCaptionCloser,
            new LiftCaptionsOutOfParagraph,
            new AddMissingCaptionAttributes,
            new UnwrapTableWrappers,
            new UnwrapWholeContentTable,
            new UnwrapSingleCellTable,
            new UnwrapCaptionOnlyTable,
            new UnwrapEmbedOnlyParagraph,
            new ConvertYoutubeEmbedToUrl,
            new CollapseCaptionInternalWhitespace,
            new StripOrdinalSupTags,
            new ReplaceBareBrWithBlankLine,
            new RenameBoldToStrong,
            new RenameItalicsToEm,
            new ReplaceCurlyQuotes,
            new ConvertDoubleHyphenToEmDash,
            new NormalizeUamsDomainAnchors,
            new TrimLineEdges,
            new CollapseHorizontalWhitespace,
            new StripWhitespaceBeforeBr,
            new CollapseExtraLineBreaks,
            new StripLeadingBreaks,
            new StripLeadingDateLine,
            new NormalizeCityDateline,
        ];
    }

    /**
     * Run every configured rule against every post in the supplied WXR.
     *
     * @param  string  $wxr  Raw WXR XML
     * @return string Cleaned WXR XML
     *
     * @throws InvalidArgumentException When the input is empty once invalid XML characters are stripped
     */
    public function apply(string $wxr): string
    {
        $wxr = $this->stripInvalidXmlChars($wxr);

        if ($wxr === '') {
            throw new InvalidArgumentException('WXR input is empty.');
        }

        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;

        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            $document->loadXML($wxr);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
        $xpath->registerNamespace('excerpt', 'http://wordpress.org/export/1.2/excerpt/');
        $xpath->registerNamespace('wp', 'http://wordpress.org/export/1.2/');

        foreach (XpathNodes::query($xpath, '//item/title') as $node) {
            $this->processTitle($document, $node);
        }

        foreach (XpathNodes::query($xpath, '//item/content:encoded') as $node) {
            $this->processContent($document, $node);
        }

        foreach (XpathNodes::query($xpath, '//item/excerpt:encoded') as $node) {
            $this->processExcerpt($document, $node);
        }

        foreach (XpathNodes::query($xpath, '//item/wp:postmeta[wp:meta_key="_uams_summary"]/wp:meta_value') as $node) {
            $this->processSummaryMeta($document, $node);
        }

        $saved = $document->saveXML();
        if ($saved === false) {
            throw new \RuntimeException('Failed to serialize WXR document.');
        }

        // libxml emits CRLF on Windows; normalize so output matches LF fixtures and
        // stays stable across macOS, Linux, and Windows checkouts.
        return str_replace(["\r\n", "\r"], "\n", $saved);
    }

    /**
     * Run the rule pipeline against a `<content:encoded>` node, then
     * write the cleaned HTML back into the node as a fresh CDATA section.
     */
    private function processContent(DOMDocument $document, DOMNode $node): void
    {
        $cleaned = $this->applyToHtml($node->textContent);

        $this->replaceCdata($document, $node, $cleaned);
    }

    /**
     * Run the rule pipeline against an `<item><title>` node, then
     * reduce the result to plain single-line text.
     *
     * Titles render on a single line in every destination context, so
     * any `<br>` and any literal `\n` get replaced with a single space
     * and runs of spaces are collapsed. All remaining HTML tags
     * (`<em>`, `<strong>`, `<a>`, …) are then stripped — the
     * destination CMS treats the title as plain text and the inline
     * markup the legacy editor sprinkled in doesn't survive the
     * transition. The pipeline runs first so curly-quote / em-dash /
     * similar text normalization still applies to the inner text.
     */
    private function processTitle(DOMDocument $document, DOMNode $node): void
    {
        $cleaned = $this->applyToHtml($node->textContent);

        $cleaned = (string) preg_replace('/<br\b[^>]*>/i', ' ', $cleaned);
        $cleaned = str_replace(["\r\n", "\n", "\r"], ' ', $cleaned);
        $cleaned = strip_tags($cleaned);
        $cleaned = (string) preg_replace('/ {2,}/', ' ', $cleaned);
        $cleaned = mb_trim($cleaned);

        $this->replaceCdata($document, $node, $cleaned);
    }

    /**
     * Run the rule pipeline against an `<excerpt:encoded>` node, then
     * reduce the result to plain text and write it back.
     *
     * Excerpts arrive entity-encoded — `&lt;span&gt;` rather than
     * `<span>` — so the entity decode happens first; the rule pipeline
     * then cleans the now-readable HTML; `strip_tags` + `trim` reduces
     * any residual tags or surrounding whitespace to a plain-text
     * snippet, which is what excerpt consumers want.
     */
    private function processExcerpt(DOMDocument $document, DOMNode $node): void
    {
        $html = html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5);
        $cleaned = $this->applyToHtml($html);
        $plainText = mb_trim(strip_tags($cleaned));
        $plainText = $this->stripLeadingCityDateline($plainText);

        $this->replaceCdata($document, $node, $plainText);
    }

    /**
     * Strip a canonical `CITY, Ark. — ` dateline from the start of the
     * excerpt. Excerpts are summary snippets — the dateline belongs in
     * the body, not the teaser. `NormalizeCityDateline` (run during
     * `applyToHtml`) normalizes any variant into this canonical form
     * first, so a single regex catches every case here.
     */
    private function stripLeadingCityDateline(string $text): string
    {
        return (string) preg_replace(
            '/^[A-Z][A-Z ]+, Ark\. \x{2014} /u',
            '',
            $text,
            1,
        );
    }

    /**
     * Run the rule pipeline against an `_uams_summary` postmeta value.
     *
     * Like `<excerpt:encoded>`, the value arrives as entity-encoded HTML
     * (the legacy plugin wrote `&lt;p&gt;` etc. inside the CDATA), but
     * unlike the excerpt the HTML structure is preserved — consumers
     * expect the same shape back, just cleaner. Decode → run rules →
     * re-encode → CDATA.
     *
     * Only `<` and `>` are re-encoded on output, matching the source's
     * encoding scheme (attribute quotes and `&` entities are left bare —
     * `saveHTML` already produced `&amp;` for text-content ampersands).
     */
    private function processSummaryMeta(DOMDocument $document, DOMNode $node): void
    {
        $html = html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5);
        $cleaned = $this->applyToHtml($html);
        $reEncoded = str_replace(['<', '>'], ['&lt;', '&gt;'], $cleaned);

        $this->replaceCdata($document, $node, $reEncoded);
    }

    /**
     * Replace every child of `$node` with a single CDATA section
     * carrying `$content`.
     */
    private function replaceCdata(DOMDocument $document, DOMNode $node, string $content): void
    {
        while ($node->firstChild instanceof DOMNode) {
            $node->removeChild($node->firstChild);
        }

        $node->appendChild($document->createCDATASection($content));
    }

    /**
     * Wrap an HTML fragment in a parseable envelope, apply rules, unwrap.
     */
    private function applyToHtml(string $html): string
    {
        $html = $this->stripVmlAndOfficeNamespaceTags($html);
        $html = $this->stripSmartTagWrappers($html);

        $wrapper = new DOMDocument('1.0', 'UTF-8');

        // <?xml encoding hint forces DOMDocument to treat the input as UTF-8;
        // LIBXML_HTML_NOIMPLIED skips the implicit <html>/<body> wrappers so
        // our explicit <body> stays as the document root.
        $envelope = '<?xml encoding="UTF-8"?><body>'.$html.'</body>';

        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            $wrapper->loadHTML($envelope, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        foreach ($this->rules as $rule) {
            $rule->apply($wrapper);
        }

        $body = $wrapper->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return $html;
        }

        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $wrapper->saveHTML($child);
        }

        $result = $this->normalizeVoidEmbeds($result);

        // mb_trim strips Unicode whitespace including NBSP (U+00A0), which
        // shows up at the end of plain-text WXR content as the trailing
        // `&nbsp;` line that the legacy editor appended on save.
        return mb_trim($result);
    }

    /**
     * Strip characters that are illegal in XML 1.0 before parsing.
     *
     * Some legacy news.uams.edu exports embed stray control bytes — most
     * notably a literal `\0` inside an `<![CDATA[…]]>` block — that
     * libxml's XML parser rejects, aborting the load and producing an
     * empty document. The XML 1.0 spec permits only `\t`, `\n`, `\r` and
     * the ranges U+0020–U+D7FF / U+E000–U+FFFD / U+10000–U+10FFFF; every
     * other code point < U+0020 is stripped here.
     */
    private function stripInvalidXmlChars(string $xml): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $xml);
    }

    /**
     * Strip — wrapper AND content — every Word VML (`<v:*>`) or Office
     * (`<o:*>`) namespaced element from raw HTML before DOM parsing.
     *
     * libxml's HTML parser handles namespaced tags inconsistently across
     * versions — some preserve `<v:shape>`/`<o:p>` as literal elements,
     * some drop the namespace prefix and produce `<shape>`/`<p>`, some
     * discard entirely. The DOM-level rules (`StripOfficeNamespaceTags`)
     * only see the prefixed form, so prefix-dropping libxml builds leave
     * them untouched. Stripping at the string level sidesteps the
     * variance and the DOM rule becomes a defense-in-depth no-op.
     *
     * Two passes per namespace: one for paired open/close tags
     * (`<v:shape>…</v:shape>`), one for self-closing forms
     * (`<o:lock v:ext="edit"/>`). Bare opening tags without closers are
     * left to the DOM rule.
     */
    private function stripVmlAndOfficeNamespaceTags(string $html): string
    {
        $html = (string) preg_replace('/<v:[a-z][a-z0-9-]*\b[^>]*>.*?<\/v:[a-z][a-z0-9-]*\s*>/is', '', $html);
        $html = (string) preg_replace('/<v:[a-z][a-z0-9-]*\b[^>]*\/>/i', '', $html);
        $html = (string) preg_replace('/<o:[a-z][a-z0-9-]*\b[^>]*>.*?<\/o:[a-z][a-z0-9-]*\s*>/is', '', $html);

        return (string) preg_replace('/<o:[a-z][a-z0-9-]*\b[^>]*\/>/i', '', $html);
    }

    /**
     * Unwrap — preserve content, drop tags — every Microsoft Office
     * "smart tag" element (`<stN:*>…</stN:*>`) from raw HTML before
     * DOM parsing.
     *
     * Same libxml-version-variance story as `<v:*>` / `<o:*>`: the DOM
     * rule (`UnwrapSmartTags`) only sees the prefixed form, but older
     * libxml builds drop the `stN:` prefix during parse and produce
     * `<city>`/`<state>`/`<personname>` — names the rule no longer
     * recognizes. Stripping the wrappers (without their content) at the
     * string level sidesteps the variance.
     */
    private function stripSmartTagWrappers(string $html): string
    {
        $html = (string) preg_replace('/<st[0-9]+:[a-z][a-z0-9-]*\b[^>]*>/i', '', $html);

        return (string) preg_replace('/<\/st[0-9]+:[a-z][a-z0-9-]*\s*>/i', '', $html);
    }

    /**
     * Normalize `<embed></embed>` to the void-element form `<embed>`.
     *
     * libxml's HTML serializer disagrees with itself across versions on
     * whether `<embed>` is a void element: 2.15.x emits `<embed src="x">`
     * with no closing tag, older 2.9.x/2.10.x builds emit
     * `<embed src="x"></embed>`. The HTML5 spec treats `<embed>` as
     * void, so we standardize on that form — collapsing the empty-closer
     * variant to match the void-element output.
     */
    private function normalizeVoidEmbeds(string $html): string
    {
        return (string) preg_replace('/<embed\b([^>]*)><\/embed>/i', '<embed$1>', $html);
    }
}
