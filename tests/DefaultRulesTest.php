<?php

declare(strict_types=1);
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;
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
use UAMSWeb\Aether\WordPressImporter\Cleanup\Transform;

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
        fn (string $file): string => 'UAMSWeb\\Aether\\WordPressImporter\\Cleanup\\Rules\\'.basename($file, '.php'),
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
