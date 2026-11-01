# aether-wordpress-importer-cleanup

Deterministic CLI tool to transform WordPress XML exports (WXR) for [news.uams.edu](https://news.uams.edu/) and [inside.uams.edu](https://inside.uams.edu/news-archive/), stripping legacy `<table>` markup and Microsoft-Word-paste noise so the Statamic importer in [`uams-web/uams-statamic`](https://github.com/UAMS-Web/uams-statamic) consumes clean content.

The live WordPress sites are **not modified by this tool.** Cleanup runs offline as a transform on the WXR files in [`uams-web/wordpress-importer-exports`](https://github.com/UAMS-Web/wordpress-importer-exports). Re-runnable, fixture-tested, idempotent. Originating ticket: [`uams-web/uams-statamic-archive#475`](https://github.com/UAMS-Web/uams-statamic-archive/issues/475).

> [!WARNING]
> **Not a general-purpose WordPress tool.** This cleaner was built for the post bodies of news.uams.edu and the inside.uams.edu news archive, as those sites stood in May and June 2026. Every rule encodes that content's specific legacy markup, Word-paste patterns, datelines and domains. It is published for reference and for the UAMS migration only. Running it on any other site, or on these sites' later content, is unsupported and may damage the content.

## Renamed from `wordpress-wxr-cleanup`

The repository, the Composer package and the PHP namespace were renamed together. A consumer changes three things:

| Was | Now |
| --- | --- |
| Package `uams-web/wordpress-wxr-cleanup` | `uams-web/aether-wordpress-importer-cleanup` |
| Namespace `UamsWeb\WxrCleanup` | `UAMSWeb\Aether\WordPressImporter\Cleanup` |
| Remote `UAMS-Web/wordpress-wxr-cleanup` | `UAMS-Web/aether-wordpress-importer-cleanup` (the old URL redirects) |

An existing checkout updates its remote with `git remote set-url origin https://github.com/UAMS-Web/aether-wordpress-importer-cleanup.git`. The `wxr-cleanup`, `wxr-redirect-pairs` and `build-duplicate-disposition` commands keep their names.

## Setup

- PHP 8.4+
- `ext-dom`, `ext-libxml` (both ship with stock PHP)

From a clone of this repo:

```
composer install
```

Composer does not symlink this package's own `bin/wxr-cleanup` into `vendor/bin` when you're working in the repo itself — invoke the script with PHP (see Usage). If another project installs this package via Composer, `vendor/bin/wxr-cleanup` is available there.

## Usage

```
php bin/wxr-cleanup input.xml output.xml
```

Reads a raw WXR file, applies every configured cleanup rule, and writes the cleaned WXR to `output.xml`. Omit the output path to write to stdout (for piping). The Statamic importer is pointed at the cleaned output; the raw WXR stays in `wordpress-importer-exports` as the source artifact.

### From PHP

```php
use UAMSWeb\Aether\WordPressImporter\Cleanup\Transform;

$cleaned = Transform::withDefaultRules()->apply($wxr);
```

`Transform::withDefaultRules()` runs the same pipeline as the CLI. The rule order is defined once, in `Transform::defaultRules()`, and the CLI, the full-pipeline test and any library consumer all use it. `DefaultRulesTest` pins that order and fails if a rule class in `src/Rules/` is left out of it.

## Duplicate dispositions

Some sites carry the same story more than once, under a hyphenated slug and a concatenated or `-N` slug. A **disposition** names, for each such set, the one post to keep and the posts to drop. Pass it to drop the duplicates before the body rules run:

```
php bin/wxr-cleanup --disposition=dispositions/news-uams-edu.json input.xml output.xml
```

The drop works on whole `<item>` blocks, never inside the body rules. The importer also runs the body rules on single post bodies (`UAMS-Web/wordpress-importer#1303`), and a body alone has no post to drop. Dropped posts are never imported, so nothing is deleted from the live site or from any content repository. Each dropped post's URL gets a redirect pair instead (below).

Applying a disposition:

- **Drops** each `post` item it marks to drop.
- **Renames** each kept post to its set's canonical slug, when its slug differs.
- **Checks before acting.** A post whose slug in the export differs from the slug the disposition records stops the run, so a disposition applied to the wrong site's export, or to an export taken after a post was edited, drops nothing.
- **Skips** a post the export lacks. One site's posts can be split across several export files (`.000.xml`, `.001.xml`, …), and each file is cleaned on its own.
- **Is idempotent.** A kept post already at its canonical slug is left alone, so a second run changes nothing.
- **Touches only `post` items.** An attachment or page sharing a dropped post's id is left in place.

### The disposition file

`dispositions/<site>.json`, one per site. `#2810` adds sets to the news file. `UAMS-Web/wordpress-importer#1305` reads the same file for posts captured through the migration API.

```json
{
    "format": 1,
    "site": "news.uams.edu",
    "permalink_base": "https://news.uams.edu",
    "sets": [
        {
            "ticket": "UAMS-Web/uams-statamic#46",
            "canonical_slug": "former-student-delivers-inaugural-yasargil-lecture-at-uams",
            "keep": {"post_id": 12240, "slug": "former-student-delivers-inaugural-yasargil-lecture-at-uams", "date": "2008-10-06"},
            "drop": [
                {"post_id": 16831, "slug": "formerstudentdeliversinauguralyasargillectureatuams", "date": "2008-10-06"}
            ]
        }
    ]
}
```

| key | meaning |
| --- | --- |
| `format` | `1`. A file with any other value is refused. |
| `site` | The site's public host. |
| `permalink_base` | The scheme and host every public permalink starts with, with no path. Permalinks are `{permalink_base}/YYYY/MM/DD/{slug}/`. |
| `sets[].ticket` | The ticket that decided the set, as `owner/repo#N`. |
| `sets[].canonical_slug` | The slug the kept post ends up with. Lowercase words joined by single hyphens. |
| `sets[].keep` | The kept post: its WordPress `post_id`, its `slug` as exported, and its publish day as `YYYY-MM-DD`. The kept post's text and date are the ones that survive. |
| `sets[].drop` | A non-empty list of the posts to drop, in the same shape. |

A post id may appear in one set only, kept or dropped. A file breaking any of these rules is refused before anything is applied.

### The news.uams.edu sets

`dispositions/news-uams-edu.json` holds 140 sets, decided on `UAMS-Web/uams-statamic#46`. `bin/build-duplicate-disposition` rebuilds it from the news export by the rule in `DuplicateSetFinder`:

- **Group** posts whose slugs match once hyphens and any trailing `-N` are removed, and whose publish dates fall on the same day.
- **Guard:** a trailing `-N` is removed for grouping only when the set also has a member with that slug minus the number, or when the members' titles match (case, whitespace and curly quotes folded).
- **Exclude** a set when any member's permalink is in `dispositions/news-uams-edu.excluded.txt`. That file holds the permalinks `UAMS-Web/uams-statamic-archive#1639` reviews and `#2810` applies.
- **URL:** the hyphenated slug with its `-N` removed, when a member holds that slug or no published post does. Otherwise the kept post keeps its own slug.
- **Text and date:** the most complete published member, where word counts within 2% are equal, then the member already at the canonical slug, then the earliest.

```
php bin/build-duplicate-disposition --site=news.uams.edu --ticket='UAMS-Web/uams-statamic#46' \
    --excluded=dispositions/news-uams-edu.excluded.txt dispositions/news-uams-edu.json \
    wordpress-export_news-uams-edu.000.xml wordpress-export_news-uams-edu.001.xml wordpress-export_news-uams-edu.002.xml
```

Rebuilding after the export changes rewrites the whole file, so review the diff before committing it.

### Redirect pairs

```
php bin/wxr-redirect-pairs dispositions/news-uams-edu.json dispositions/news-uams-edu.redirects.json
```

writes the redirects a disposition implies, derived from the disposition alone rather than from an export. `uams-statamic`'s `content:generate-migration-redirects --dedup-redirects=<file>` reads the file (`UAMS-Web/uams-statamic-archive#2943`) and turns each row into one `301`, from the old URL straight to the kept post's Statamic URL.

```json
[
    {
        "source": "https://news.uams.edu/2008/10/06/formerstudentdeliversinauguralyasargillectureatuams/",
        "target": "https://news.uams.edu/2008/10/06/former-student-delivers-inaugural-yasargil-lecture-at-uams/",
        "response": "301",
        "notes": "Duplicate of WordPress post 12240 (UAMS-Web/uams-statamic#46).",
        "source_wordpress_post_id": 16831,
        "target_wordpress_post_id": 12240
    }
]
```

- **Rows:** one for each dropped post, and one for a kept post's old URL when its slug changes. A kept post's row has no `source_wordpress_post_id`, because that post is not dropped.
- **URLs:** `source` and `target` are public permalinks. `target` is always the canonical URL, never another row's `source`.
- **Post ids:** `target_wordpress_post_id` lets the generator find the kept entry by its WordPress post id (`UAMS-Web/uams-statamic-archive#2960`). The exports' own links use a local host, and some use the `?p=` form, so a URL alone cannot find it.
- **Skipped:** a dropped post whose URL equals the canonical URL gets no row, since that URL still resolves. Each source is listed once.

The committed `dispositions/news-uams-edu.redirects.json` must equal this script's output, and `NewsDispositionTest` fails when it drifts.

## Rules

Each rule lives in `src/Rules/` and operates on the parsed HTML inside four targets per item: `<title>`, `<content:encoded>`, `<excerpt:encoded>`, and the `<wp:meta_value>` of the `_uams_summary` postmeta. Other WXR elements (`<dc:creator>`, the rest of `<wp:postmeta>`, etc.) are left untouched.

`<title>` and `<content:encoded>` are treated as raw HTML. `<excerpt:encoded>` arrives entity-encoded (`&lt;span&gt;` rather than `<span>`) and is entity-decoded before rules run; after rules run the excerpt is reduced to plain text via `strip_tags` + `mb_trim`. The `_uams_summary` postmeta value is also entity-encoded HTML, but unlike the excerpt its structure is preserved — decode → run rules → re-encode the `<` and `>` characters → write back to CDATA. Trailing Unicode whitespace (including `&nbsp;`) is stripped from `<title>` and `<content:encoded>` output too — that's the recurring `&nbsp;` "blank line" the legacy editor liked to append on save.

Void elements are normalized to the HTML5 form on output: every `<br />` and `<br/>` becomes `<br>` once the parsed DOM is serialized back out. This is a free side-effect of DOMDocument's HTML serialization mode, not a rule, so it applies uniformly to all three processed elements without any rule configuration.

The pipeline runs every configured rule in order against every processed element. Order is significant — `StripLeadingDateLine` must see the body after `UnwrapSpans` has unwrapped any presentational wrapper, `UnwrapEmptyBolds` must run after `PromoteBoldToInnerStrong` so the "speaker block" lift consumes the orphan `<b>` first, `RenameBoldToStrong` must run after the `<b>`-specific rules so it only sees the genuine inline-bold case, `TrimBlockEdges` runs after `LiftTrailingBr` so a trailing `<br>` lifted out of an inline gets stripped from the surrounding block, etc.

| # | Rule | What it does |
| --- | --- | --- |
| 1 | `StripHtmlComments` | Removes every HTML comment node from the body. Targets WordPress block-editor markers (`<!-- wp:paragraph -->`, `<!-- /wp:paragraph -->`, …) and any other inline/standalone comments. WXR-level XML comments (importer instructions, the `generator` marker) live outside the processed CDATA and are preserved. |
| 2 | `StripStyleTags` | Removes — wrapper AND content — every `<style>` element from the body. Targets the Word-paste top-of-content stylesheet block (font definitions plus Mso paragraph rules wrapped in an HTML comment) and any other inline CSS. Case-insensitive on the tag name so `<STYLE>` matches too. |
| 4 | `StripMsoNoise` | Removes `Mso*` `class` tokens, inline `style` attributes, `<o:p>` placeholder tags, and unwraps `<font>` tags. Table structure preserved. Runs after `CaptionizeImageCutline` so the captionizer can still see `<p style="text-align: right">` alignment and `<font class="cutline">` wrappers before this rule strips/unwraps them. |
| 5 | `StripTableAttributes` | Strips presentational attributes from `<table>` (`height`, `width`, `border`, `cellpadding`, `cellspacing`), `<td>` (`valign`), and `<ul>` (`type` — presentational bullet style). Structural attributes (`class`, `summary`, `scope`, `headers`, `rowspan`, `colspan`) and other tags are left alone. The rule keeps its name even though its scope extends past tables; it's configurable on construction. |
| 3 | `CaptionizeImageCutline` | Converts legacy image-caption markup into a `[caption]<img …> caption text[/caption]` shortcode pair. Matches seven pattern families (paths A through G). Sibling-style cutlines: (a) `<img>` followed by a `<div class="cutline">`, `<span class="cutline">`, or `<font class="cutline">` (the last is the legacy serializer's form; in practice `<font>` is unwrapped by `StripMsoNoise` before this rule runs, so by the time Path A scans for cutlines the text has already become plain — `<font>` matching is still in the tag list as a belt-and-suspenders safeguard); accepts whitespace/`<br>` between the img and the cutline, optionally with either the img or the cutline wrapped in its own `<p>`; (b) `<div class="wp-caption …">` (any alignment modifier or none) wrapping an `<img>` and a `<p class="wp-caption-text">`; (c) `<p>` whose alignment is right or left — via `align="right\|left"` OR `style="text-align: right\|left"` — and whose meaningful children are an `<img>` followed by any mix of text nodes and `<br>` separators (the `<br>` is optional; multiple `<br>`s become soft spaces). Converts to `[caption align="alignright\|alignleft"]…[/caption]`, padded with `\n`; (d) `<td>` whose meaningful children are an `<img>`, a `<br>`, and one or more text nodes — the cell's contents are replaced with a bare `[caption]<img …> caption text[/caption]` shortcode and the wrapping `<td>` stays put, leaving `UnwrapCaptionOnlyTable` (rule 30) to lift the captions out when every cell in the table qualifies; (e) a cutline element (`<font\|span\|div\|p class="cutline">`) that WRAPS its `<img>` (img is a child of the cutline rather than a sibling) — the wrapper must contain exactly one `<img>` and one or more text nodes with `<br>` tolerated as soft spaces. The img keeps `src`, `alt`, `width`, and `height`; every other attribute (`class`, `style`, `longdesc`, …) is stripped. `width` and `height` are preserved so the destination importer can size the caption block. Multi-token classes (`class="wp-caption alignright"`, `class="foo cutline bar"`) still match. Leading/trailing NBSP (` `) inside a `<td>` or `<p>` does not disqualify the match — whitespace skipping uses `mb_trim`, which strips NBSP along with ASCII whitespace. When Path A's img sits inside an aligned `<p>`, the resulting caption inherits the parent's alignment. Runs before `StripMsoNoise` so `<font class="cutline">` wrappers and `<p style="text-align: …">` declarations are still present, before `StripAlignLeft` so `<p align="left">` is still detectable, and before `UnwrapSpans` so `<span class="cutline">` is still detectable. |
| 6 | `StripAlignLeft` | Removes every `align="left"` attribute regardless of which tag carries it. Left alignment is the default for LTR content and `align` is deprecated in HTML5, so the attribute is pure clutter. Other values (`right`, `center`, `justify`) are preserved. Case-insensitive on the value (`align="LEFT"` stripped too). Runs after `CaptionizeImageCutline` so Path E (the `<p align="left">` caption shape) sees the unstripped attribute. |
| 7 | `UnwrapSpans` | Unwraps every `<span>` element, preserving its children in place. |
| 8 | `UnwrapSmartTags` | Unwraps every Microsoft Office "smart tag" — any element whose name starts with `stN:` (where `N` is a digit; default coverage st1–st9). Covers the v1 forms (`<st1:city>`, `<st1:state>`, `<st1:place>`, `<st1:country-region>`, `<st1:street>`, `<st1:address>`, `<st1:personname>`, `<st1:time>`, `<st1:postalcode>`, `<st1:date>`) and the v2 person-name components (`<st2:sn>`, `<st2:givenname>`, `<st2:middlename>`, …). Children flatten in a single pass. |
| 9 | `StripOfficeNamespaceTags` | Removes — wrapper AND content — every element whose tag name begins with `v:` (Word VML) or `o:` (Office). Targets vector drawing artifacts (`<v:shapetype>`, `<v:shape>`, `<v:stroke>`, `<v:imagedata>`, `<v:f>`, `<v:path>`, …) and Office attribute carriers (`<o:lock>`). Unlike SmartTags, the contents are XML attribute soup, not display text, so the whole subtree is dropped. |
| 10 | `UnwrapBareDivs` | Unwraps every `<div>` element with no attributes, preserving its children in place. `<div class="…">`, `<div id="…">`, etc. are left alone. Nested bare divs flatten in a single pass. |
| 11 | `UnwrapUnderlines` | Unwraps every `<u>` element, preserving its children in place. `<u>` is presentational underline markup; the destination CMS handles styling. |
| 12 | `MergeAdjacentLists` | Merges consecutive `<ul>`s into one and consecutive `<ol>`s into one — when a list's next non-whitespace sibling is another list of the same tag, the second list's children are moved into the first and the second is dropped. Mixed-type sequences (`<ul>` then `<ol>`) and lists separated by non-whitespace are left alone. Iterates until stable so a run of N adjacent lists collapses in O(N) passes. The first list's attributes are preserved. |
| 13 | `SplitParagraphsOnDoubleBr` | Splits any `<p>` at every run of two or more `<br>` elements, producing one new `<p>` per resulting group. Single `<br>`s are preserved. Matches `<br>`, `<br />`, and `<br/>` identically — they're the same DOM element once parsed. |
| 14 | `PromoteBoldToInnerStrong` | Rewrites the legacy `<b><p>…</p></b>` "speaker block" markup into valid `<p><strong>…</strong></p>`. Inline `<b>` inside paragraphs is left alone. |
| 15 | `LiftTrailingBr` | Lifts every trailing `<br>` out of its inline parent so the closing tag precedes the `<br>` with no whitespace between. Iterative — stacked trailing `<br>`s (`<b>foo<br><br></b>`) and nested cases (`<b>foo<i>bar<br></i></b>`) flatten all the way out. Skips block parents (`<p>`, `<li>`, `<td>`, `<h1>`–`<h6>`) so trailing breaks there stay inside the block for `TrimBlockEdges` to strip. |
| 16 | `TrimBlockEdges` | Strips leading and trailing whitespace and `<br>` tags from inside `<p>`, `<li>`, `<td>`, and `<h1>`–`<h6>`. Targets block tags only; inline tags are handled by `LiftTrailingBr`. |
| 17 | `CollapseBareDoubleBr` | Replaces runs of 2+ consecutive body-level `<br>` tags (with optional whitespace between) with a single blank-line text node, consuming adjacent whitespace so exactly one blank line remains. `<br><br>` inside a `<p>` is left for `SplitParagraphsOnDoubleBr`. |
| 18 | `UnwrapEmptyBolds` | Unwraps `<b>` elements whose only content is whitespace (e.g. `<b> </b>` accidentally sprinkled between a sentence and the following quote, or `<b></b>` left behind after `LiftTrailingBr` emptied one out). |
| 19 | `RemoveEmptyContainers` | Recursively removes every container element whose visible content is empty — `<p></p>`, `<p>  </p>`, `<p><br></p>`, `<p><strong><br><br></strong></p>` (strong first, then the now-empty p) all go. Preserves void/atomic elements (`<br>`, `<hr>`, `<img>`, …), content-producing elements (`<iframe>`, `<object>`, `<audio>`, `<video>`, `<svg>`, `<canvas>`), interactive (`<button>`, `<select>`, `<textarea>`), `<a>` (anchors carry meaning), and table structure (`<table>`/`<tr>`/`<td>` etc.). Loops until stable so arbitrary nesting collapses out. |
| 20 | `UnwrapRemainingDivs` | Unwraps every remaining `<div>`, leaving a blank line before and after the unwrapped content. After `UnwrapBareDivs` (rule 10), `CaptionizeImageCutline` (rule 6), and `RemoveEmptyContainers` (rule 19), surviving divs are attributed non-empty non-wp-caption wrappers (`<div class="news_contentTitleLine">`, `<div class="Section1">`, `<div align="center">`, …) — drop the wrapper, keep the content. The blank-line padding (`\n\n` before and after) preserves the visual paragraph separation a `<div>` implicitly provided; `CollapseExtraLineBreaks` (rule 44) then collapses any 3+ consecutive `\n` runs back to 2. Outer wrappers around already-canonical `[caption id="…"]…[/caption]` shortcodes (the double-wrap case) collapse to just the inner shortcode plus padding. |
| 21 | `UnwrapDefinitionLists` | Unwraps every `<dl>`, `<dt>`, and `<dd>` element, leaving a blank line before and after the unwrapped content. The legacy exports use `<dl id="attachment_NNN"><dt>[caption …]…[/caption]</dt><dd>Body paragraph.</dd></dl>` as a structural carrier for an attachment-id caption shortcode plus the body paragraph that goes with it — the wrappers themselves carry no semantic value in the destination CMS. Same blank-line bookkeeping as `UnwrapRemainingDivs`; `CollapseExtraLineBreaks` (rule 44) collapses any 3+ consecutive `\n` runs back to 2. |
| 22 | `UnwrapCaptionImageAnchors` | Unwraps every `<a>` element whose only meaningful child is an `<img>`, when the anchor sits between a `[caption …]` opening shortcode and a `[/caption]` closing shortcode. The legacy WordPress caption shortcode wraps a "click to enlarge" anchor around the in-line image; the destination CMS generates that interaction from the caption's image attachment, so the anchor is redundant inside a caption. Anchors outside a caption shortcode (inline thumbnails linking to downloads, etc.) are left alone. Walk is depth-first across the document with state (`inCaption`) tracked through text-node shortcode markers — an opener inside a `<p>` and a closer inside a sibling text node still contribute to the same caption span. Recognizes both attributed (`[caption id="…" align="…"]`) and bare (`[caption]`) opener forms. |
| 23 | `UnwrapCaptionOnlyParagraph` | Unwraps every `<p>` whose entire content (after trimming whitespace) is exactly one `[caption …]…[/caption]` shortcode pair — leaving a blank line before the opening `<p>` and after the closing `</p>`. WordPress's `wpautop` filter wraps standalone caption shortcodes in `<p>`, and the destination CMS parses `[caption]` at the body level natively. Match criteria: the trimmed text content must start with `[caption`, end with `[/caption]`, and contain exactly one of each marker. Paragraphs that mix a caption with surrounding prose (`<p>Lead. [caption]…[/caption] Trail.</p>`) or stack multiple captions in one paragraph are left alone. Same blank-line bookkeeping as `UnwrapRemainingDivs`/`UnwrapDefinitionLists`; `CollapseExtraLineBreaks` (rule 44) collapses any 3+ consecutive `\n` runs back to 2. |
| 24 | `RestoreSplitCaptionCloser` | Moves a leading `[/caption]` from a `<p>` back to the end of the preceding `<p>` — undoing the WordPress `wpautop` filter's habit of splitting a single caption shortcode across a paragraph boundary when the caption text contained a blank line. Match: a `<p>` whose first non-whitespace text content (after leading whitespace text nodes and leading whitespace within the first content text node) starts with `[/caption]`, AND whose immediately-preceding non-whitespace sibling is another `<p>`. The `[/caption]` token is appended to the previous `<p>` and stripped from the current one; surrounding whitespace stays put. Runs before `LiftCaptionsOutOfParagraph` (rule 25) so the rejoined paragraphs have balanced shortcode markers in time for the lift rule to recognize them. |
| 25 | `LiftCaptionsOutOfParagraph` | Lifts every `[caption …]…[/caption]` shortcode out of any wrapping `<p>`, splitting the paragraph around the captions and wrapping any prose chunks left behind in fresh `<p>`s. Walks the `<p>`'s children parsing them into caption chunks (text + element nodes spanning `[caption` opener → `[/caption]` closer) and prose chunks (everything else), with `<br>` and whitespace serving as chunk boundaries. Empty/whitespace-only prose chunks are dropped; non-empty prose chunks get a fresh `<p>` wrapper at the original `<p>`'s parent level. Inter-chunk separators: caption + caption → single `\n`; caption + prose or prose + caption → `\n\n`. `\n\n` padding before the first chunk and after the last. Only fires when the count of `[caption` openers equals the count of `[/caption]` closers and both are at least 1 — paragraphs with unbalanced markers are left alone. Runs after `UnwrapCaptionOnlyParagraph` (which handles the simple single-caption case) so this rule only sees multi-caption or mixed-content paragraphs. `CollapseExtraLineBreaks` (rule 44) collapses any 3+ consecutive `\n` runs back to 2. |
| 26 | `AddMissingCaptionAttributes` | Fills in canonical attribute defaults on `[caption …]` openers and on the `<img>` they wrap. Defaults applied to the opener token: `id=""` if no `id` attribute is present, `align="aligncenter"` if no `align` is present, `width="300"` if no `width` is present. Default applied to each `<img>` between a `[caption]` opener and its matching `[/caption]` closer: `width="300"` if the `<img>` has no `width` attribute (`height` is left alone — the importer derives it from the aspect ratio). Added attributes are prepended at the head of the opener's attribute list in canonical order (`id`, `align`, `width`); existing attributes are preserved verbatim in their original positions. Caption state is tracked depth-first across element boundaries via text-node shortcode markers (`[caption` vs `[/caption]` — unambiguous because the closer doesn't start with `[caption`). Runs late, after all structural caption rules (`UnwrapCaptionImageAnchors`, `UnwrapCaptionOnlyParagraph`, `RestoreSplitCaptionCloser`, `LiftCaptionsOutOfParagraph`), so they see the original opener tokens and don't accidentally key off attribute presence. |
| 27 | `UnwrapTableWrappers` | Lifts every `<table>` out of a wrapping `<p>`, `<strong>`, or `<em>` element, leaving a blank line before the opening `<table>` and after the closing `</table>`. `<p>` cannot legally contain a `<table>` per HTML5 — libxml's HTML parser already auto-closes the `<p>` before the `<table>` during ingest, so the `<p>` case rarely fires in practice; the rule handles it for completeness. `<strong>` and `<em>` are inline and similarly cannot contain block-level structure; when they do, the destination CMS reads the markup unpredictably. Nested wrappers (`<em><strong><table></strong></em>`) collapse all the way out. Mixed-content wrappers (`<strong>Lead<table></table>Trail</strong>`) keep the non-table content as siblings at the wrapper's parent level. Runs before `UnwrapWholeContentTable` so a body whose only meaningful child is a wrapped table becomes eligible for whole-content unwrapping once this rule has lifted the table out. |
| 28 | `UnwrapWholeContentTable` | When the body's sole meaningful child is a `<table>`, unwraps that table along with its `<tbody>`, `<tr>`, and `<td>` descendants — leaving a blank line before and after each unwrapped element. The legacy news.uams.edu exports used a top-level layout table whose single `<td>` cell holds the article markup; the table itself carries no semantic value in the destination CMS. Bodies with mixed content alongside the table (a caption shortcode + a table, or paragraphs alongside a table) leave the table alone. Nested `<table>` elements survive intact (only the outer table and its directly-owned structure unwrap). Same blank-line bookkeeping as `UnwrapRemainingDivs`/`UnwrapDefinitionLists`; `CollapseExtraLineBreaks` (rule 44) collapses any 3+ consecutive `\n` runs back to 2 so empty rows/cells contribute nothing beyond one normalized blank line. |
| 29 | `UnwrapSingleCellTable` | Unwraps every `<table>` whose structure contains exactly one `<tr>` and exactly one `<td>` — leaving a blank line before and after each unwrapped element. Targets the legacy "header banner" single-cell layout table (typically wrapping a centered `<p align="center">` title block at the top of an article). The qualifying shape is `<table>` → (`<tbody>` →)? single `<tr>` → single `<td>`; multi-row, multi-column, multi-`<tbody>`, or `<thead>`/`<tfoot>`-bearing tables are left alone. Nested tables inside the qualifying cell survive intact — only the outer table chain (and its directly-owned tbody/tr/td) unwraps. Same blank-line bookkeeping as `UnwrapWholeContentTable`/`UnwrapRemainingDivs`; `CollapseExtraLineBreaks` (rule 44) collapses stacked padding back to a single blank line. Runs after `UnwrapWholeContentTable` so the whole-content special case fires first when both rules would apply. |
| 30 | `UnwrapCaptionOnlyTable` | Unwraps every `<table>` whose `<td>` cells each contain exactly one `[caption]…[/caption]` shortcode — lifting the captions to the table's parent level and dropping the table / tbody / tr / td wrappers, with `\n` padding so consecutive captions sit on their own lines separated by a single blank line. Targets the legacy "caption gallery" layout table where each row carries one captioned photo. Match criteria: the table has at least one `<td>`, and every `<td>` under it has trimmed text content starting with `[caption`, ending with `[/caption]`, and containing exactly one opener and one closer. Tables that mix caption and non-caption cells, or that have cells with multiple captions or unbalanced markers, are left alone. Runs after `UnwrapWholeContentTable` and `UnwrapSingleCellTable` so those special cases fire first when overlapping. `CollapseExtraLineBreaks` (rule 44) collapses stacked padding. |
| 31 | `UnwrapEmbedOnlyParagraph` | Unwraps every `<p>` whose meaningful content is exactly one or more `<embed>` elements — leaving a blank line before and after each unwrapped embed. Multiple embeds inside the same `<p>` each get their own `\n` padding, so `<p><embed/><embed/></p>` unwraps to `\n<embed>\n\n<embed>\n` (one blank line between them). Match criteria: every child is an `<embed>`, a whitespace-only text node, or a `<br>` — and the `<p>` contains at least one `<embed>`. Mixed-content paragraphs (`<p>Lead. <embed /> Trail.</p>`) are left alone. `CollapseExtraLineBreaks` (rule 44) normalizes stacked padding. |
| 32 | `ConvertYoutubeEmbedToUrl` | Converts every `<embed>` whose `src` matches the legacy WordPress YouTube-embed shape (`http://www.youtube.com/v/{id}…` / `https://www.youtube.com/v/{id}…`) into a standalone YouTube watch URL (`https://www.youtube.com/watch?v={id}`) — leaving a blank line before and after the URL. Trailing `&hl=…`, `&fs=1`, `?rel=0`, etc. are dropped; the canonical watch URL contains only `v=<id>`. The destination CMS auto-converts a bare YouTube URL on its own line into an embed. Non-YouTube `<embed>` elements (other src patterns, no src attribute) are left alone. Runs after `UnwrapEmbedOnlyParagraph` so a YouTube embed lifted to body level becomes a standalone-URL line; YouTube embeds still trapped inside mixed-content paragraphs are converted in place. |
| 33 | `CollapseCaptionInternalWhitespace` | Replaces `<br>` tags and whitespace runs inside `[caption]…[/caption]` shortcodes with a single ASCII space — the destination CMS renders caption text as a single flowing line, and the legacy editor's `<br>` + newline layout inside caption text doesn't survive the transition. Algorithm: depth-first walk tracking `inCaption` via text-node shortcode markers; each `<br>` encountered while inside a caption is replaced with a single space; after a `$document->normalize()`, a second walk collapses runs of `\s` (whitespace including `\n`, `\r`, `\t`) inside caption spans to a single space. Img attribute values (notably `alt`) are never touched. Content outside any caption is left alone. |
| 34 | `StripOrdinalSupTags` | Unwraps every `<sup>` element that carries the typographic ordinal suffix `st`, `nd`, `rd`, or `th` (case-insensitive) immediately after an ASCII digit — `1<sup>st</sup>` → `1st`, `17<sup>th</sup>` → `17th`. The preceding-digit check guarantees only ordinal superscripts are flattened; chemical formulas (`O<sup>2+</sup>`), mathematical exponents (`x<sup>n</sup>`), footnote markers (`Footnote<sup>1</sup>`), and other legitimate `<sup>` uses are left alone. |
| 35 | `ReplaceBareBrWithBlankLine` | Replaces every `<br>` whose direct parent is `<body>` (i.e. not wrapped inside any other element) with two `\n` characters — one blank line of plain-text separation. Companion to `CollapseBareDoubleBr` (rule 17), which collapses runs of 2+ consecutive body-level `<br>`s into a single blank line; this rule handles the LONE body-level `<br>` case the run rule conservatively leaves alone. `<br>` inside a wrapping `<p>`, `<li>`, `<td>`, heading, or any other element is left alone — those carry meaningful inline line-break semantics. Runs after the structural unwrap rules so a `<br>` that emerges at the body level from one of those unwraps gets caught too. |
| 36 | `RenameBoldToStrong` | Renames every remaining inline `<b>` to its semantic equivalent `<strong>`, preserving children and attributes. |
| 37 | `RenameItalicsToEm` | Renames every `<i>` to its semantic equivalent `<em>`, preserving children and attributes. |
| 38 | `ReplaceCurlyQuotes` | Replaces MS-Word "smart" curly quotes with straight ASCII equivalents in every text node — `“`/`”` → `"`, `‘`/`’` → `'`. Attribute values are left alone. |
| 39 | `ConvertDoubleHyphenToEmDash` | Replaces the typographic shorthand ` -- ` (space + two hyphens + space) with ` — ` (space + em dash + space). Unspaced `--` (e.g. `--verbose`, `foo--bar`) is left alone. |
| 40 | `NormalizeUamsDomainAnchors` | Canonicalizes `<a>` tags whose visible text is one of `uams.edu`, `www.uams.edu`, `uamshealth.com`, or `www.uamshealth.com` (trimmed, case-insensitive). Both the `href` and the visible text are rewritten to the canonical form — `<a href="https://uams.edu/">uams.edu</a>` or `<a href="https://uamshealth.com/">uamshealth.com</a>`. The visible text is the authoritative signal of intent, so anchors that disagree with themselves (e.g. `<a href="https://news.uams.edu/some/page">uams.edu</a>`) are rewritten to match the text. Anchors containing nested elements (`<a><strong>uams.edu</strong></a>`) or anchors lacking an `href` attribute are left alone. |
| 41 | `TrimLineEdges` | Strips horizontal whitespace (ASCII space, tab, NBSP) that sits directly adjacent to a `\n` line break in every text node. NBSP is included so lone-NBSP leading-cruft lines (` &nbsp;Friday, April 25\n`) collapse; mid-line NBSPs are preserved by `CollapseHorizontalWhitespace`. Absolute text-node edges are left alone so inline flows like `…named <strong>X</strong> the…` keep their meaningful spaces. |
| 42 | `CollapseHorizontalWhitespace` | Collapses runs of 2+ horizontal whitespace (space, tab, NBSP) in every text node. Mixed or pure NBSP runs collapse to a single NBSP (preserving the non-breaking intent); pure breaking runs collapse to a single space. Newlines are left alone. |
| 43 | `StripWhitespaceBeforeBr` | Strips breaking whitespace (space, tab, newline, CR) at the end of any text node immediately followed by a `<br>` element. NBSP is preserved. |
| 44 | `CollapseExtraLineBreaks` | Collapses runs of 3+ consecutive `\n` characters down to exactly 2 (one blank line). Calls `$document->normalize()` first so a newline run split across adjacent text-node siblings (e.g. the `\n\n` text nodes `UnwrapRemainingDivs` and `UnwrapDefinitionLists` insert) is seen as a single run. |
| 45 | `StripLeadingBreaks` | Strips leading `<br>` elements and surrounding whitespace from the start of the body. A leading `<br>` inside a wrapping `<p>` is left alone (handled instead by `TrimBlockEdges`). |
| 46 | `StripLeadingDateLine` | Strips a leading press-release date stamp (`Nov. 12, 2002`, `September 12th, 2010 — `, `NOV. 7, 2002 \| `, …) — case-insensitive — from the start of the body. Handles dates as bare leading text nodes or as the leading content of a wrapping `<p>`. |
| 47 | `NormalizeCityDateline` | Normalizes an Arkansas-city dateline at the start of `<content:encoded>` to the canonical `CITY, Ark. — ` form. Matches a configurable list of UPPERCASE city names (default: 21 Arkansas cities including `LITTLE ROCK`, `EL DORADO`, `FORT SMITH`, `FORREST CITY`, `MAGNOLIA`, `MOUNTAIN HOME`, `PINE BLUFF`, `VAN BUREN`) followed by an optional `, ARK.`/`, Ark.` and a separator dash (hyphen, en dash, or em dash). City name stays uppercase; `Ark.` is title-cased; the separator unifies to a space + em dash + space. After `NormalizeCityDateline` runs, `Transform::processExcerpt` strips the canonical `CITY, Ark. — ` prefix from the resulting plain-text excerpt — the dateline belongs in the body, not the teaser. |

Additional rules (`UnwrapMsoTables`, `ConvertContactBlocks`, `PreserveDataTables`) are planned per the ticket's cleanup categories and will land in subsequent iterations.

## Tests

```
composer test
```

Every rule pairs with a corpus of `tests/fixtures/<rule>/*.before.xml` / `*.after.xml` files. The test for each rule runs the transform against every `*.before.xml` and asserts byte-equality with the corresponding `*.after.xml`. New observed dirty patterns turn into new fixture pairs.

### Adding a fixture

1. Author a `<id>.before.xml` containing the dirty WXR snippet you want to cover.
2. Run `php bin/wxr-cleanup tests/fixtures/<rule>/<id>.before.xml tests/fixtures/<rule>/<id>.after.xml`.
3. Read the captured `.after.xml` and verify the transform did what you expected.
4. Run `composer test` and confirm the new pair passes.

## Pipeline

Cleaned WXR lives in `uams-web/wordpress-importer-exports` alongside the raw exports. Initial workflow is manual — when a new raw export lands, an operator runs `php bin/wxr-cleanup` and commits the cleaned output alongside. A news.uams.edu export is cleaned with `--disposition=dispositions/news-uams-edu.json`, one export file at a time. See *Duplicate dispositions* for why a disposition is applied this way.

## Local CI

```bash
node .claude/skills/local-ci/local-ci.mjs --dry-run
node .claude/skills/local-ci/local-ci.mjs --all
```

The same checks run remotely on every pull request (`.github/workflows/ci.yml`). `main` requires three checks to pass before a merge: `CI` (the aggregate of the jobs in `ci.yml`), `Attribution footer` and `Closing keywords` (the two pull-request body checks). The local runner gives the same verdict before a push.

## License

Released under the [MIT License](LICENSE). Copyright (c) 2026 University of Arkansas for Medical Sciences.
