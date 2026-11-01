<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Cleanup\Rules;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;

/**
 * Normalize an Arkansas-city dateline at the very start of the body to
 * the canonical `CITY, Ark. — ` form.
 *
 * Legacy press releases opened with one of many variants:
 *
 *     LITTLE ROCK - The foo...
 *     LITTLE ROCK – The foo...
 *     LITTLE ROCK — The foo...
 *     LITTLE ROCK, ARK. - The foo...
 *     LITTLE ROCK, ARK. — The foo...
 *     LITTLE ROCK , ARK. - The foo...
 *     LITTLE ROCK , ARK. – The foo...
 *     LITTLE ROCK , ARK. — The foo...
 *     LITTLE ROCK, Ark. – The foo...
 *
 * All of these collapse to `LITTLE ROCK, Ark. — The foo...`. City name
 * stays uppercase; `Ark.` is title-cased; the separator is unified to a
 * space + em dash + space.
 *
 * Matches at the start of the first text node of the body envelope, or
 * — if the body opens with a wrapping `<p>`/heading — at the start of
 * that wrapper's first text child. Other locations are left alone.
 */
final class NormalizeCityDateline implements Rule
{
    private const array DEFAULT_CITIES = [
        'BATESVILLE',
        'CLARENDON',
        'EL DORADO',
        'FAYETTEVILLE',
        'FORREST CITY',
        'FORT SMITH',
        'GRAVETTE',
        'HELENA',
        'JACKSONVILLE',
        'JONESBORO',
        'LITTLE ROCK',
        'MAGNOLIA',
        'MALVERN',
        'MARIANNA',
        'MARVELL',
        'MOUNTAIN HOME',
        'PINE BLUFF',
        'SPRINGDALE',
        'TEXARKANA',
        'VAN BUREN',
        'WARREN',
    ];

    /**
     * @param  list<string>  $cities
     */
    public function __construct(
        private readonly array $cities = self::DEFAULT_CITIES,
    ) {}

    public function apply(DOMDocument $document): void
    {
        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body instanceof DOMElement) {
            return;
        }

        $textNode = $this->findLeadingTextNode($body);
        if (! $textNode instanceof DOMText) {
            return;
        }

        $pattern = $this->buildPattern();
        $textNode->nodeValue = (string) preg_replace_callback(
            $pattern,
            static fn (array $match): string => $match['city'].", Ark. \u{2014} ",
            $textNode->nodeValue ?? '',
            1,
        );
    }

    private function findLeadingTextNode(DOMNode $container): ?DOMText
    {
        $first = $container->firstChild;

        if ($first instanceof DOMText) {
            return $first;
        }

        if ($first instanceof DOMElement
            && in_array($first->nodeName, ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            return $this->findLeadingTextNode($first);
        }

        return null;
    }

    private function buildPattern(): string
    {
        $alternatives = array_map(
            static fn (string $city): string => preg_quote($city, '/'),
            $this->cities,
        );
        $cityAlt = implode('|', $alternatives);

        return '/^(?<city>'.$cityAlt.')(?:\s*,\s*(?:ARK|Ark)\.)?\s+[-\x{2013}\x{2014}]\s+/u';
    }
}
