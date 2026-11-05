<?php
namespace SrdFiles;

use DOMDocument;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/** Initial policy: passive DOCX/XLSX packages only; never extract ZIP entries. */
final class OfficeDocumentGate
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    public function __construct(private Scanner $scanner, private string $quarantine) {}
    public function inspect(string $name, string $bytes): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['docx','xlsx'], true) || strlen($bytes) < 22 || strlen($bytes) > self::MAX_BYTES
            || !str_starts_with($bytes, "PK\x03\x04")) $this->reject();
        if (!is_dir($this->quarantine) || is_link($this->quarantine)) throw new ScannerUnavailable;
        $path = tempnam($this->quarantine, 'office-');
        if ($path === false) throw new ScannerUnavailable;
        chmod($path, 0600);
        try {
            if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) throw new ScannerUnavailable;
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CHECKCONS) !== true) $this->reject();
            try { $this->package($zip, $ext); } finally { $zip->close(); }
            $this->scanner->assertClean($path);
        } finally { if (is_file($path)) unlink($path); }
        return ['mime' => $ext === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'sha256' => hash('sha256', $bytes)];
    }
    private function reject(): never
    {
        throw ValidationException::withMessages(['content' => 'Elige un DOCX o XLSX de hasta 20 MB, sin macros, fórmulas, campos activos ni enlaces externos. El archivo debe tener una estructura Office válida.']);
    }
    private function xml(string $bytes): DOMDocument
    {
        if (strlen($bytes) > 2 * 1024 * 1024 || stripos($bytes, '<!DOCTYPE') !== false || stripos($bytes, '<!ENTITY') !== false) $this->reject();
        $old = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            if (!$doc->loadXML($bytes, LIBXML_NONET) || $doc->doctype !== null) $this->reject();
            return $doc;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    }
    private function package(ZipArchive $zip, string $ext): void
    {
        if ($zip->numFiles < 3 || $zip->numFiles > 512) $this->reject();
        $names = []; $total = 0; $relations = []; $main = null; $type = false; $office = false;
        $prefix = $ext === 'docx' ? 'word' : 'xl';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $s = $zip->statIndex($i); $name = $s['name'];
            if (!preg_match('#^[A-Za-z0-9_\[\]./-]+$#D', $name) || str_contains($name, '..') || str_starts_with($name, '/')
                || isset($names[strtolower($name)]) || ($s['encryption_method'] ?? 0) !== 0) $this->reject();
            $names[strtolower($name)] = true;
            if (str_ends_with($name, '/')) continue;
            if ($name !== '[Content_Types].xml' && !str_starts_with($name, '_rels/') && !str_starts_with($name, 'docProps/') && !str_starts_with($name, $prefix.'/')) $this->reject();
            $total += $s['size'];
            if ($s['size'] > 8 * 1024 * 1024 || $total > 80 * 1024 * 1024
                || $s['size'] > max(1024 * 1024, ($s['comp_size'] ?? 0) * 200)) $this->reject();
            $content = $zip->getFromIndex($i);
            if (!is_string($content) || strlen($content) !== $s['size'] || hash('crc32b', $content) !== sprintf('%08x', $s['crc'])) $this->reject();
            if (preg_match('/\.(png|jpe?g)$/iD', $name)) {
                $info = @getimagesizefromstring($content);
                if (!$info || !in_array($info['mime'], ['image/png','image/jpeg'], true) || $info[0] * $info[1] > 20000000) $this->reject();
                continue;
            }
            if (!preg_match('/\.(xml|rels)$/D', $name)) $this->reject();
            $doc = $this->xml($content);
            if ($name === '[Content_Types].xml' && ($doc->documentElement->localName !== 'Types'
                || $doc->documentElement->namespaceURI !== 'http://schemas.openxmlformats.org/package/2006/content-types')) $this->reject();
            if (str_ends_with($name, '.rels') && ($doc->documentElement->localName !== 'Relationships'
                || $doc->documentElement->namespaceURI !== 'http://schemas.openxmlformats.org/package/2006/relationships')) $this->reject();
            foreach ($doc->getElementsByTagName('*') as $node) {
                if (in_array($node->localName, ['instrText','fldSimple','altChunk','object','control','f','formula','definedName'], true)) $this->reject();
                if ($node->localName === 'Relationship') {
                    if (strtolower($node->getAttribute('TargetMode')) === 'external') $this->reject();
                    $target = $node->getAttribute('Target');
                    if ($target === '' || preg_match('/[:%\\\\\x00-\x20]/', $target) || str_starts_with($target, '/')) $this->reject();
                    $base = $name === '_rels/.rels' ? '' : preg_replace('#/_rels/[^/]+\.rels$#', '', $name);
                    $parts = $base === '' ? [] : explode('/', $base);
                    foreach (explode('/', $target) as $part) {
                        if ($part === '..') { if (!$parts) $this->reject(); array_pop($parts); }
                        elseif ($part !== '.' && $part !== '') $parts[] = $part;
                    }
                    $relations[] = implode('/', $parts);
                    if ($name === '_rels/.rels' && implode('/', $parts) === ($ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml')
                        && $node->getAttribute('Type') === 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument') $office = true;
                }
                $ct = strtolower($node->getAttribute('ContentType'));
                if (preg_match('/macro|vba|activex|oleobject|externallink/', $ct)) $this->reject();
                if ($name === '[Content_Types].xml' && $node->getAttribute('PartName') === ($ext === 'docx' ? '/word/document.xml' : '/xl/workbook.xml')
                    && $ct === ($ext === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml')) $type = true;
            }
            if ($name === ($ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml')) $main = $doc->documentElement;
        }
        $ns = $ext === 'docx' ? 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' : 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        if (!$office || !$type || !$main || $main->namespaceURI !== $ns || $main->localName !== ($ext === 'docx' ? 'document' : 'workbook') || !isset($names['_rels/.rels'])) $this->reject();
        foreach ($relations as $target) if (!isset($names[strtolower($target)])) $this->reject();
    }
}
