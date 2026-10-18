<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Services;

/**
 * Turns stored product content into the structured data the product page
 * prints (schema.org properties, FAQ pairs, document links, meta description).
 */
class ShopProductPageData
{
    private const META_DESCRIPTION_LENGTH = 160;

    /**
     * Flatten the parsed techspec_json into name/value pairs for the Product
     * schema's additionalProperty. Purely additive - the visible spec table is
     * still rendered from the original techspec HTML, untouched.
     *
     * Values stay exactly as stored ("23.76v DC"), because techspec is the single
     * source and nothing here re-interprets it. Empty when a product has no parsed
     * JSON yet, which simply omits additionalProperty from the schema.
     */
    public static function specProperties(?string $techspecJson): array
    {
        $jsonEmpty = empty($techspecJson);
        if ($jsonEmpty) return [];

        $sections = \json_decode($techspecJson, true);
        $decodeFailed = !\is_array($sections);
        if ($decodeFailed) return [];

        $properties = [];
        foreach ($sections as $section) {
            $specs = $section['specs'] ?? [];
            foreach ($specs as $spec) {
                $label = \trim($spec['label'] ?? '');
                $value = \trim($spec['value'] ?? '');

                $incomplete = $label === '' || $value === '';
                if ($incomplete) continue;

                $properties[] = ['name' => $label, 'value' => $value];
            }
        }

        return $properties;
    }

    /**
     * Parse product FAQ HTML (h5.accordion questions + following element
     * answers) into question/answer pairs for FAQPage schema.
     */
    public static function faqPairs(?string $faqHTML): array
    {
        $faqHTMLEmpty = empty($faqHTML);
        if ($faqHTMLEmpty) return [];

        $dom = new \DOMDocument();
        \libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $faqHTML . '</div>');
        \libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $questionNodes = $xpath->query('//h5[contains(@class, "accordion")]');

        $pairs = [];
        foreach ($questionNodes as $questionNode) {
            $answerNode = $questionNode->nextSibling;
            while ($answerNode && $answerNode->nodeType !== \XML_ELEMENT_NODE) {
                $answerNode = $answerNode->nextSibling;
            }

            $answerNodeMissing = !$answerNode;
            if ($answerNodeMissing) continue;

            $pairs[] = [
                'question' => \trim(\preg_replace('/\s+/', ' ', $questionNode->textContent)),
                'answer' => \trim(\preg_replace('/\s+/', ' ', $answerNode->textContent)),
            ];
        }

        return $pairs;
    }

    /**
     * Return the document path, or null when imagePath() substituted its
     * missing-file placeholder.
     */
    public static function pdfPathOrNull(string $path): ?string
    {
        return \str_contains(haystack: $path, needle: 'data-error') ? null : $path;
    }

    /**
     * Meta description taken from the start of the product description:
     * markup removed, whitespace collapsed, cut to 160 characters.
     */
    public static function metaDescription(?string $descriptionHtml): string
    {
        $plainText = \strip_tags((string) $descriptionHtml);
        $plainText = \preg_replace(pattern: '/\s+/', replacement: ' ', subject: \trim($plainText));

        return \mb_substr(string: $plainText, start: 0, length: self::META_DESCRIPTION_LENGTH);
    }

    /**
     * "3 products and 2 subcategories" line for a category heading.
     */
    public static function categorySummary(int $productCount, int $subCategoryCount): string
    {
        $products = $productCount ? $productCount . ' product' . ($productCount > 1 ? 's' : '') : '';
        $subcategories = $subCategoryCount ? $subCategoryCount . ' subcategor' . ($subCategoryCount > 1 ? 'ies' : 'y') : '';

        $hasBoth = $productCount && $subCategoryCount;
        if ($hasBoth) return $products . ' and ' . $subcategories;

        return \trim($products . ' ' . $subcategories);
    }

    /**
     * Add what services returned from the product.page.data hook to the product's page data. Each
     * result is an array with named keys. Core's own keys are never replaced, and when two services
     * set the same key the first one wins. Anything that is not a named array is ignored.
     *
     * @param array<string, mixed> $product
     * @param array<int, mixed> $results
     * @return array<string, mixed>
     */
    public static function withServiceData(array $product, array $results): array
    {
        foreach ($results as $result) {
            $product = self::addNamedKeys(product: $product, result: $result);
        }

        return $product;
    }

    private static function addNamedKeys(array $product, mixed $result): array
    {
        $notAnArray = !\is_array($result);
        if ($notAnArray) return $product;

        foreach ($result as $key => $value) {
            $isNamed = \is_string($key);
            $isTaken = \array_key_exists($key, $product);
            if ($isNamed && !$isTaken) $product[$key] = $value;
        }

        return $product;
    }
}
