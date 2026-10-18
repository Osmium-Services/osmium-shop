<?php

declare(strict_types=1);

namespace Osmium\Core\Library;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Parses the Summernote-authored <table class="techspec"> stored in
 * sg2_products.techspec into a section / key / label / value structure.
 *
 * Structure only. Values cross over verbatim as strings - "23.76v DC" stays
 * "23.76v DC", no number is extracted and no unit is inferred - so a spec that
 * is wrong or ambiguous on the page is wrong here in exactly the same way.
 * techspec remains authoritative; this is a derived rendering of it.
 */
class TechspecParser
{
    /**
     * @return array<int, array{section: ?string, specs: array<int, array{key: string, label: string, value: string}>, notes?: array<int, string>}>
     */
    public function parse(string $html): array
    {
        $hasTable = \str_contains(haystack: $html, needle: '<table');
        if (!$hasTable) return []; // Placeholder copy or an empty editor, nothing to parse

        $rows = $this->loadRows($html);
        $sections = [];
        $current = $this->newSection(label: null);

        foreach ($rows as $row) {
            $cells = $this->cellsOf($row);
            $isSectionHeading = $this->isSectionHeading(row: $row, cells: $cells);

            if ($isSectionHeading) {
                $sections = $this->pushSection(sections: $sections, section: $current);
                $current = $this->newSection(label: $this->textOf($cells[0]));
                continue;
            }

            $current = $this->addRow(section: $current, cells: $cells);
        }

        return $this->pushSection(sections: $sections, section: $current);
    }

    /** @return array<int, DOMElement> */
    private function loadRows(string $html): array
    {
        $document = new DOMDocument();
        $previousErrorState = \libxml_use_internal_errors(true); // Summernote output is not guaranteed well-formed

        $wrapped = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>';
        $document->loadHTML($wrapped, LIBXML_NONET);

        \libxml_clear_errors();
        \libxml_use_internal_errors($previousErrorState);

        $xpath = new DOMXPath($document);
        $found = $xpath->query('//tr');
        $rows = [];

        foreach ($found as $row) {
            $isElement = $row instanceof DOMElement;
            if ($isElement) $rows[] = $row;
        }

        return $rows;
    }

    /** @return array<int, DOMElement> */
    private function cellsOf(DOMElement $row): array
    {
        $cells = [];

        foreach ($row->childNodes as $child) {
            $isCell = $child instanceof DOMElement && \in_array($child->tagName, ['td', 'th'], true);
            if (!$isCell) continue;
            $cells[] = $child;
        }

        return $cells;
    }

    /** @param array<int, DOMElement> $cells */
    private function isSectionHeading(DOMElement $row, array $cells): bool
    {
        $hasCells = $cells !== [];
        if (!$hasCells) return false;

        $class = $row->getAttribute('class');
        $isMarkedSection = \str_contains(haystack: $class, needle: 'section');

        return $isMarkedSection;
    }

    /**
     * @param array{section: ?string, specs: array, notes: array} $section
     * @param array<int, DOMElement> $cells
     */
    private function addRow(array $section, array $cells): array
    {
        $cellCount = \count($cells);

        // A lone full-width cell that is not a section heading is a footnote -
        // the "Above Specification at standard test conditions (STC)" line that
        // sits under the electrical block on every panel.
        $isNote = $cellCount === 1;
        if ($isNote) {
            $note = $this->textOf($cells[0]);
            $hasNote = $note !== '';
            if ($hasNote) $section['notes'][] = $note;
            return $section;
        }

        $isPair = $cellCount >= 2;
        if (!$isPair) return $section;

        $label = $this->textOf($cells[0]);
        $value = $this->textOf($cells[1]);

        // An unlabelled row cannot be keyed or displayed meaningfully. Dropping it
        // loses nothing: techspec keeps the original markup either way.
        $hasLabel = $label !== '';
        if (!$hasLabel) return $section;

        $section['specs'][] = [
            'key' => $this->keyFor($label),
            'label' => $label,
            'value' => $value,
        ];

        return $section;
    }

    private function textOf(DOMNode $node): string
    {
        $text = $node->textContent;
        $normalised = \preg_replace('/\s+/u', ' ', $text); // Editor indentation and stray newlines

        return \trim($normalised);
    }

    /**
     * Deterministic slug of the label. Deliberately not a controlled vocabulary:
     * keys emerge from what has actually been typed, and the admin offers the
     * ones already in use rather than constraining what a spec may be.
     */
    private function keyFor(string $label): string
    {
        $lower = \mb_strtolower($label, 'UTF-8');
        $ascii = \preg_replace('/[^a-z0-9]+/', '_', $lower);

        return \trim($ascii, '_');
    }

    private function newSection(?string $label): array
    {
        return ['section' => $label, 'specs' => [], 'notes' => []];
    }

    /**
     * Sections with no rows at all are dropped - an editor artefact, not content.
     * The leading unlabelled section only survives if the table opened with rows
     * before its first heading.
     */
    private function pushSection(array $sections, array $section): array
    {
        $isEmpty = $section['specs'] === [] && $section['notes'] === [];
        if ($isEmpty) return $sections;

        $hasNotes = $section['notes'] !== [];
        if (!$hasNotes) unset($section['notes']);

        $sections[] = $section;

        return $sections;
    }
}
