<?php

declare(strict_types=1);

// AI-facing product record - GET /api/ai/products/{page-slug}.md or .json
//
// Generated on request from the same product row, techspec_json and FAQ the
// product page itself renders, so this can never drift from the page. There
// is no site guidance section yet - that content does not exist in the
// database, so nothing is emitted for it rather than a placeholder.
//
// Keyed by page slug, not the product SKU/reference: several Victron SKUs
// contain a literal "/" (e.g. VBS-MPPT75/10/BT), and IONOS's edge blocks the
// %2F that a URL-encoded slash requires before the request ever reaches this
// app - it falls through to a domain-parking placeholder, not a 404 from
// here. Page slugs are already unique and never need that encoding.

use Osmium\Core\Library\ResponseSentException;
use Osmium\Modules\Shop\Models\ShopProduct;

require_once __DIR__ . '/../models/ShopProduct.php';

$slug = $this->routeParams['slug'] ?? '';
$format = $this->routeParams['format'] ?? '';

$productModel = new ShopProduct(database: $this->osmium->dataSource, tableName: 'products');
$product = $productModel->getByPageUrl($slug);

$notFound = $product === false;
if ($notFound) {
    $this->osmium->jsonError("No product at '{$slug}'", 404);
}

$scheme = $this->osmium->request->isHTTPS() ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$productURL = "{$scheme}{$host}/{$product['page_url']}/";

// Turns block-level boundaries into paragraph breaks before stripping tags, so
// hard line-wraps inside a single paragraph collapse to spaces but distinct
// paragraphs/list items are kept apart.
$cleanText = function (?string $html): string {
    $unixLines = \str_replace("\r\n", "\n", (string)$html);
    $withBreaks = \preg_replace('#</p>|</div>|<br\s*/?>#i', "\n\n", $unixLines);
    $decoded = \html_entity_decode(\strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Treat any run of 2+ newlines (with only whitespace between) as a paragraph
    // break; a lone newline inside a paragraph is a hard wrap and joins with a
    // space. Splitting first avoids a single regex trying to tell the two apart.
    $paragraphs = \preg_split('/[ \t]*\n[ \t]*\n[ \t]*/', $decoded);
    $joined = \array_map(function ($paragraph) {
        $oneLine = \preg_replace('/[ \t]*\n[ \t]*/', ' ', $paragraph);
        return \trim(\preg_replace('/[ \t]+/', ' ', $oneLine));
    }, $paragraphs);

    return \trim(\implode("\n\n", \array_filter($joined, fn ($p) => $p !== '')));
};

// FAQ is <h5 class="accordion">question</h5><div class="panel">answer</div>
// pairs - the same structure parseFAQPairs() in product-full.php reads for the
// FAQPage schema. Duplicated rather than shared: that file is a page controller
// with its own side effects, not a library.
$parseFAQPairs = function (?string $faqHTML) use ($cleanText): array {
    $isEmpty = empty($faqHTML);
    if ($isEmpty) return [];

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
            'question' => $cleanText($questionNode->textContent),
            'answer' => $cleanText($answerNode->textContent),
        ];
    }

    return $pairs;
};

$specSections = [];
$hasTechspecJson = !empty($product['techspec_json']);
if ($hasTechspecJson) {
    $decoded = \json_decode($product['techspec_json'], true);
    $specSections = \is_array($decoded) ? $decoded : [];
}

$priceIncTax = \round((float)$product['price_exc_tax'] * 1.2, 2);

$record = [
    'sku' => $product['reference'],
    'gtin13' => $product['barcode'] ?: null,
    'name' => $product['model_name'],
    'brand' => $product['brand_name'],
    'url' => $productURL,
    'price_gbp_inc_vat' => $priceIncTax,
    'price_gbp_exc_vat' => (float)$product['price_exc_tax'],
    'availability' => $product['status_descr'],
    'description' => $cleanText($product['descr']),
    'specifications' => $specSections,
    'faq' => $parseFAQPairs($product['faq']),
    'guidance' => null, // Not yet written - see Known Issues
];

\header('X-Robots-Tag: noindex, nofollow'); // Duplicate of the product page, keep out of search results

$renderSpecSections = static function (array $sections): string
{
    $out = "## Technical specifications\n\n";

    foreach ($sections as $section) {
        $heading = $section['section'] ?? 'Specifications';
        $out .= "### {$heading}\n\n| Specification | Value |\n|---|---|\n";

        foreach ($section['specs'] as $spec) {
            $out .= "| {$spec['label']} | {$spec['value']} |\n";
        }

        foreach ($section['notes'] ?? [] as $note) {
            $out .= "\n_{$note}_\n";
        }

        $out .= "\n";
    }

    return $out;
};

$renderProductMarkdown = static function (array $record, string $siteName) use ($renderSpecSections): string
{
    $out = "# {$record['brand']} {$record['name']}\n\n";
    $out .= "- **SKU:** {$record['sku']}\n";

    $hasGtin = $record['gtin13'] !== null;
    if ($hasGtin) $out .= "- **GTIN-13:** {$record['gtin13']}\n";

    $out .= "- **Price:** £" . \number_format($record['price_gbp_inc_vat'], 2) . ' inc VAT (£'
        . \number_format($record['price_gbp_exc_vat'], 2) . " ex VAT)\n";
    $out .= "- **Availability:** {$record['availability']}\n";
    $out .= "- **URL:** {$record['url']}\n\n";

    $hasDescription = $record['description'] !== '';
    if ($hasDescription) $out .= "## Description\n\n{$record['description']}\n\n";

    $hasSpecs = $record['specifications'] !== [];
    if ($hasSpecs) $out .= $renderSpecSections($record['specifications']);

    $hasFAQ = $record['faq'] !== [];
    if ($hasFAQ) {
        $out .= "## FAQ\n\n";
        foreach ($record['faq'] as $pair) {
            $out .= "**{$pair['question']}**\n\n{$pair['answer']}\n\n";
        }
    }

    $out .= "---\n\n## {$siteName} guidance\n\n_Not yet available for this product._\n";

    return $out;
};

$isJson = $format === 'json';
if ($isJson) {
    \header('Content-Type: application/json; charset=utf-8');
    \header('Cache-Control: public, max-age=3600');
    echo \json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    throw new ResponseSentException();
}

\header('Content-Type: text/markdown; charset=utf-8');
\header('Cache-Control: public, max-age=3600');
echo $renderProductMarkdown($record, $this->osmium->config->site->name);
throw new ResponseSentException();
