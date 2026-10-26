<?php

declare(strict_types=1);
use UamsWeb\WxrCleanup\Rule;
use UamsWeb\WxrCleanup\Rules\AddMissingCaptionAttributes;
use UamsWeb\WxrCleanup\Rules\CaptionizeImageCutline;
use UamsWeb\WxrCleanup\Rules\CollapseBareDoubleBr;
use UamsWeb\WxrCleanup\Rules\CollapseCaptionInternalWhitespace;
use UamsWeb\WxrCleanup\Rules\CollapseExtraLineBreaks;
use UamsWeb\WxrCleanup\Rules\CollapseHorizontalWhitespace;
use UamsWeb\WxrCleanup\Rules\ConvertDoubleHyphenToEmDash;
use UamsWeb\WxrCleanup\Rules\ConvertYoutubeEmbedToUrl;
use UamsWeb\WxrCleanup\Rules\LiftCaptionsOutOfParagraph;
use UamsWeb\WxrCleanup\Rules\LiftTrailingBr;
use UamsWeb\WxrCleanup\Rules\MergeAdjacentLists;
use UamsWeb\WxrCleanup\Rules\NormalizeCityDateline;
use UamsWeb\WxrCleanup\Rules\NormalizeUamsDomainAnchors;
use UamsWeb\WxrCleanup\Rules\PromoteBoldToInnerStrong;
use UamsWeb\WxrCleanup\Rules\RemoveEmptyContainers;
use UamsWeb\WxrCleanup\Rules\RenameBoldToStrong;
use UamsWeb\WxrCleanup\Rules\RenameItalicsToEm;
use UamsWeb\WxrCleanup\Rules\ReplaceBareBrWithBlankLine;
use UamsWeb\WxrCleanup\Rules\ReplaceCurlyQuotes;
use UamsWeb\WxrCleanup\Rules\RestoreSplitCaptionCloser;
use UamsWeb\WxrCleanup\Rules\SplitParagraphsOnDoubleBr;
use UamsWeb\WxrCleanup\Rules\StripAlignLeft;
use UamsWeb\WxrCleanup\Rules\StripHtmlComments;
use UamsWeb\WxrCleanup\Rules\StripLeadingBreaks;
use UamsWeb\WxrCleanup\Rules\StripLeadingDateLine;
use UamsWeb\WxrCleanup\Rules\StripMsoNoise;
use UamsWeb\WxrCleanup\Rules\StripOfficeNamespaceTags;
use UamsWeb\WxrCleanup\Rules\StripOrdinalSupTags;
use UamsWeb\WxrCleanup\Rules\StripStyleTags;
use UamsWeb\WxrCleanup\Rules\StripTableAttributes;
use UamsWeb\WxrCleanup\Rules\StripWhitespaceBeforeBr;
use UamsWeb\WxrCleanup\Rules\TrimBlockEdges;
use UamsWeb\WxrCleanup\Rules\TrimLineEdges;
use UamsWeb\WxrCleanup\Rules\UnwrapBareDivs;
use UamsWeb\WxrCleanup\Rules\UnwrapCaptionImageAnchors;
use UamsWeb\WxrCleanup\Rules\UnwrapCaptionOnlyParagraph;
use UamsWeb\WxrCleanup\Rules\UnwrapCaptionOnlyTable;
use UamsWeb\WxrCleanup\Rules\UnwrapDefinitionLists;
use UamsWeb\WxrCleanup\Rules\UnwrapEmbedOnlyParagraph;
use UamsWeb\WxrCleanup\Rules\UnwrapEmptyBolds;
use UamsWeb\WxrCleanup\Rules\UnwrapRemainingDivs;
use UamsWeb\WxrCleanup\Rules\UnwrapSingleCellTable;
use UamsWeb\WxrCleanup\Rules\UnwrapSmartTags;
use UamsWeb\WxrCleanup\Rules\UnwrapSpans;
use UamsWeb\WxrCleanup\Rules\UnwrapTableWrappers;
use UamsWeb\WxrCleanup\Rules\UnwrapUnderlines;
use UamsWeb\WxrCleanup\Rules\UnwrapWholeContentTable;
use UamsWeb\WxrCleanup\Transform;

test('the default pipeline runs every rule once, in the documented order', function () {
    $order = array_map(fn (Rule $rule): string => $rule::class, Transform::defaultRules());

    expect($order)->toBe([
        StripHtmlComments::class,
        StripStyleTags::class,
        CaptionizeImageCutline::class,
        StripMsoNoise::class,
        StripTableAttributes::class,
        StripAlignLeft::class,
        UnwrapSpans::class,
        UnwrapSmartTags::class,
        StripOfficeNamespaceTags::class,
        UnwrapBareDivs::class,
        UnwrapUnderlines::class,
        MergeAdjacentLists::class,
        SplitParagraphsOnDoubleBr::class,
        PromoteBoldToInnerStrong::class,
        LiftTrailingBr::class,
        TrimBlockEdges::class,
        CollapseBareDoubleBr::class,
        UnwrapEmptyBolds::class,
        RemoveEmptyContainers::class,
        UnwrapRemainingDivs::class,
        UnwrapDefinitionLists::class,
        UnwrapCaptionImageAnchors::class,
        UnwrapCaptionOnlyParagraph::class,
        RestoreSplitCaptionCloser::class,
        LiftCaptionsOutOfParagraph::class,
        AddMissingCaptionAttributes::class,
        UnwrapTableWrappers::class,
        UnwrapWholeContentTable::class,
        UnwrapSingleCellTable::class,
        UnwrapCaptionOnlyTable::class,
        UnwrapEmbedOnlyParagraph::class,
        ConvertYoutubeEmbedToUrl::class,
        CollapseCaptionInternalWhitespace::class,
        StripOrdinalSupTags::class,
        ReplaceBareBrWithBlankLine::class,
        RenameBoldToStrong::class,
        RenameItalicsToEm::class,
        ReplaceCurlyQuotes::class,
        ConvertDoubleHyphenToEmDash::class,
        NormalizeUamsDomainAnchors::class,
        TrimLineEdges::class,
        CollapseHorizontalWhitespace::class,
        StripWhitespaceBeforeBr::class,
        CollapseExtraLineBreaks::class,
        StripLeadingBreaks::class,
        StripLeadingDateLine::class,
        NormalizeCityDateline::class,
    ]);
});

test('every rule class in src/Rules is in the default pipeline', function () {
    $files = glob(dirname(__DIR__).'/src/Rules/*.php');

    if ($files === false) {
        throw new RuntimeException('Could not list src/Rules.');
    }

    $declared = array_map(
        fn (string $file): string => 'UamsWeb\\WxrCleanup\\Rules\\'.basename($file, '.php'),
        $files,
    );
    $wired = array_map(fn (Rule $rule): string => $rule::class, Transform::defaultRules());

    sort($declared);
    sort($wired);

    expect($wired)->toBe($declared);
});

test('the CLI runs the default pipeline', function () {
    $bin = (string) file_get_contents(dirname(__DIR__).'/bin/wxr-cleanup');

    expect($bin)->toContain('Transform::withDefaultRules()')
        ->and($bin)->not->toContain('new Transform(');
});
