<?php
namespace SrdFiles;

use DOMDocument;
use DOMXPath;
use ZipArchive;

/** Bounded excerpts of approved Office files; never render Office markup. */
final class OfficePreview
{
    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const WORD = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const SHEET = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const OFFICE_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const PACKAGE_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const MAX_XML = 2 * 1024 * 1024;
    private const MAX_CHARACTERS = 20000;

    public function __construct(private string $quarantine) {}

    public function extract(string $mime, string $bytes, int $sheetIndex = 1): array
    {
        abort_unless(in_array($mime, [self::DOCX, self::XLSX], true), 415);
        abort_unless($sheetIndex >= 1 && $sheetIndex <= 32 && ($mime === self::XLSX || $sheetIndex === 1), 422);
        abort_unless(is_dir($this->quarantine) && !is_link($this->quarantine), 503);
        $path = tempnam($this->quarantine, 'preview-');
        abort_unless($path !== false, 503);
        try {
            chmod($path, 0600);
            abort_unless(file_put_contents($path, $bytes, LOCK_EX) === strlen($bytes), 503);
            $zip = new ZipArchive;
            abort_unless($zip->open($path, ZipArchive::CHECKCONS) === true, 503);
            try { return $mime === self::DOCX ? $this->document($zip) : $this->spreadsheet($zip, $sheetIndex); }
            finally { $zip->close(); }
        } finally { if (is_file($path)) unlink($path); }
    }

    private function xml(ZipArchive $zip, string $name): DOMDocument
    {
        $stat = $zip->statName($name);
        abort_unless($stat && $stat['size'] <= self::MAX_XML, 503);
        $xml = $zip->getFromName($name);
        abort_unless(is_string($xml) && strlen($xml) <= self::MAX_XML
            && stripos($xml, '<!DOCTYPE') === false && stripos($xml, '<!ENTITY') === false, 503);
        $old = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            abort_unless($doc->loadXML($xml, LIBXML_NONET) && $doc->doctype === null, 503);
            return $doc;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    }

    private function document(ZipArchive $zip): array
    {
        $doc = $this->xml($zip, 'word/document.xml');
        abort_unless($doc->documentElement?->localName === 'document'
            && $doc->documentElement->namespaceURI === self::WORD, 503);
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('w', self::WORD);
        $paragraphs = $xpath->query('/w:document/w:body//w:p');
        $lines = []; $characters = 0; $truncated = false;
        foreach ($paragraphs as $paragraph) {
            $line = '';
            foreach ($xpath->query('.//w:t', $paragraph) as $node) $line .= $node->textContent;
            if (trim($line) === '') continue;
            $remaining = self::MAX_CHARACTERS - $characters;
            if ($remaining <= 0 || count($lines) >= 200) { $truncated = true; break; }
            if (mb_strlen($line) > $remaining) { $line = mb_substr($line, 0, $remaining); $truncated = true; }
            $lines[] = $line;
            $characters += mb_strlen($line);
            if ($truncated) break;
        }
        return ['text' => implode("\n", $lines), 'truncated' => $truncated, 'format' => 'plain_text'];
    }

    private function spreadsheet(ZipArchive $zip, int $sheetIndex): array
    {
        $book = $this->xml($zip, 'xl/workbook.xml');
        abort_unless($book->documentElement?->localName === 'workbook'
            && $book->documentElement->namespaceURI === self::SHEET, 503);
        $xpath = new DOMXPath($book);
        $xpath->registerNamespace('s', self::SHEET);
        $nodes = $xpath->query('/s:workbook/s:sheets/s:sheet');
        $sheets = []; $selected = null;
        foreach ($nodes as $node) {
            if (count($sheets) >= 32) break;
            $number = count($sheets) + 1;
            $sheets[] = ['index' => $number, 'name' => mb_substr($node->getAttribute('name'), 0, 80)];
            if ($number === $sheetIndex) $selected = $node;
        }
        abort_unless($selected || ($nodes->length === 0 && $sheetIndex === 1), 422);
        if (!$selected) return ['format' => 'grid', 'sheet' => null, 'sheet_index' => null,
            'sheets' => [], 'sheets_truncated' => false, 'columns' => 1, 'rows' => [], 'truncated' => false];
        $sheetName = $sheets[$sheetIndex - 1]['name'];
        $relationshipId = $selected->getAttributeNS(self::OFFICE_REL, 'id');
        abort_unless($relationshipId !== '', 503);
        $relations = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
        abort_unless($relations->documentElement?->localName === 'Relationships'
            && $relations->documentElement->namespaceURI === self::PACKAGE_REL, 503);
        $target = null;
        foreach ($relations->documentElement->childNodes as $node) {
            if ($node->localName === 'Relationship' && $node->namespaceURI === self::PACKAGE_REL
                && $node->getAttribute('Id') === $relationshipId
                && $node->getAttribute('Type') === self::OFFICE_REL.'/worksheet'
                && strtolower($node->getAttribute('TargetMode')) !== 'external') {
                $target = $node->getAttribute('Target'); break;
            }
        }
        abort_unless(is_string($target) && preg_match('#^worksheets/[A-Za-z0-9_-]+\.xml$#D', $target), 503);
        $sheet = $this->xml($zip, 'xl/'.$target);
        abort_unless($sheet->documentElement?->localName === 'worksheet'
            && $sheet->documentElement->namespaceURI === self::SHEET, 503);
        $shared = $this->sharedStrings($zip);
        $cells = new DOMXPath($sheet);
        $cells->registerNamespace('s', self::SHEET);
        $rows = []; $columns = 1; $characters = 0; $truncated = false;
        foreach ($cells->query('/s:worksheet/s:sheetData/s:row') as $row) {
            if (count($rows) >= 50) { $truncated = true; break; }
            $number = filter_var($row->getAttribute('r'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>1048576]]) ?: count($rows)+1;
            $values = array_fill(0, 12, ''); $next = 0;
            foreach ($cells->query('./s:c', $row) as $cell) {
                $reference = $cell->getAttribute('r');
                if (preg_match('/^([A-Z]{1,3})[0-9]{1,7}$/D', $reference, $matches)) {
                    $index = 0;
                    foreach (str_split($matches[1]) as $letter) $index = $index * 26 + ord($letter) - 64;
                    $index--;
                } else $index = $next;
                $next = $index + 1;
                if ($index >= 12) { $truncated = true; continue; }
                abort_unless($cells->query('.//s:f', $cell)->length === 0, 503);
                $raw = $this->cellText($cells, $cell, $shared);
                $limit = min(400, self::MAX_CHARACTERS - $characters);
                if ($limit <= 0) { $truncated = true; break; }
                if (mb_strlen($raw) > $limit) { $raw = mb_substr($raw, 0, $limit); $truncated = true; }
                $values[$index] = $raw;
                $characters += mb_strlen($raw);
                $columns = max($columns, $index + 1);
            }
            $rows[] = ['number' => $number, 'cells' => $values];
            if ($characters >= self::MAX_CHARACTERS) { $truncated = true; break; }
        }
        foreach ($rows as &$row) $row['cells'] = array_slice($row['cells'], 0, $columns);
        return ['format' => 'grid', 'sheet' => $sheetName, 'sheet_index' => $sheetIndex,
            'sheets' => $sheets, 'sheets_truncated' => $nodes->length > 32,
            'columns' => $columns, 'rows' => $rows, 'truncated' => $truncated];
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->statName('xl/sharedStrings.xml') === false) return [];
        $doc = $this->xml($zip, 'xl/sharedStrings.xml');
        abort_unless($doc->documentElement?->localName === 'sst'
            && $doc->documentElement->namespaceURI === self::SHEET, 503);
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('s', self::SHEET);
        $values = [];
        foreach ($xpath->query('/s:sst/s:si') as $item) {
            $text = '';
            foreach ($xpath->query('.//s:t', $item) as $node) $text .= $node->textContent;
            $values[] = $text;
        }
        return $values;
    }

    private function cellText(DOMXPath $xpath, \DOMNode $cell, array $shared): string
    {
        $type = $cell->attributes?->getNamedItem('t')?->nodeValue;
        if ($type === 'inlineStr') {
            $text = '';
            foreach ($xpath->query('./s:is//s:t', $cell) as $node) $text .= $node->textContent;
            return $text;
        }
        $value = $xpath->evaluate('string(./s:v)', $cell);
        if ($type === 's') {
            abort_unless(ctype_digit($value) && array_key_exists((int)$value, $shared), 503);
            return $shared[(int)$value];
        }
        if ($type === 'b') return $value === '1' ? 'VERDADERO' : 'FALSO';
        return $value;
    }
}
