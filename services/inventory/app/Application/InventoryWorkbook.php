<?php

namespace App\Application;

final class InventoryWorkbook
{
    public static function localized(object $row, string $field, string $language): ?string
    {
        $english = $row->{$field.'_en'} ?? null;
        return $language === 'en' && is_string($english) && trim($english) !== '' ? $english : ($row->{$field} ?? null);
    }

    public static function label(string $value, string $language): string
    {
        if ($language !== 'en') return $value;
        return [
            'Código' => 'Code', 'Tipo' => 'Type', 'Nombre' => 'Name', 'Categoría' => 'Category',
            'Unidad' => 'Unit', 'Existencia' => 'Quantity on hand', 'Ubicación' => 'Location',
            'Condición' => 'Condition', 'Responsable' => 'Recorded by', 'Estado' => 'Status',
            'Descripción' => 'Description', 'Creado UTC' => 'Created UTC', 'Baja UTC' => 'Retired UTC',
            'Mueble' => 'Movable asset', 'Inmueble' => 'Real estate asset', 'Activo' => 'Active',
            'De baja' => 'Retired', 'Código del bien' => 'Asset code', 'Nombre del bien' => 'Asset name',
            'N.º' => 'No.', 'Fecha UTC' => 'Date UTC', 'Operación' => 'Operation',
            'Cambio' => 'Change', 'Existencia anterior' => 'Previous quantity',
            'Existencia posterior' => 'Quantity after movement', 'Motivo' => 'Reason',
            'ID del movimiento' => 'Movement ID', 'Registro inicial' => 'Initial record',
            'Entrada' => 'Stock in', 'Salida' => 'Stock out', 'Ajuste' => 'Adjustment',
            'Baja' => 'Retirement',
        ][$value] ?? $value;
    }

    private static function xml(string $value): string
    {
        $safe = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
        return htmlspecialchars($safe ?? '', ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function row(array $values, int $number): string
    {
        $cells = '';
        foreach ($values as $index => $value) {
            // Every cell is text, including numeric codes and formula-looking names.
            $column = chr(65 + $index);
            $cells .= '<c r="'.$column.$number.'" t="inlineStr"><is><t xml:space="preserve">'.self::xml((string) ($value ?? '')).'</t></is></c>';
        }
        return '<row r="'.$number.'">'.$cells.'</row>';
    }

    public static function table(iterable $assets, string $language = 'es'): array
    {
        $headers = [
            'Código', 'Tipo', 'Nombre', 'Categoría', 'Unidad', 'Existencia', 'Ubicación',
            'Condición', 'Responsable', 'Estado', 'Descripción', 'Creado UTC', 'Baja UTC', 'ID',
        ];
        $headers = array_map(fn (string $header) => self::label($header, $language), $headers);
        $rows = [];
        foreach ($assets as $asset) {
            $rows[] = array_map(fn ($value) => (string) ($value ?? ''), [
                $asset->code, self::label($asset->type === 'movable' ? 'Mueble' : 'Inmueble', $language), self::localized($asset, 'name', $language),
                self::localized($asset, 'category', $language), self::localized($asset, 'unit', $language), $asset->quantity, self::localized($asset, 'location', $language),
                self::localized($asset, 'condition', $language), $asset->responsible_name,
                self::label($asset->status === 'active' ? 'Activo' : 'De baja', $language), self::localized($asset, 'description', $language),
                $asset->created_at, $asset->retired_at, $asset->id,
            ]);
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    public static function create(iterable $assets, string $language = 'es'): string
    {
        return self::fromTable(self::table($assets, $language), $language);
    }

    public static function fromTable(array $table, string $language = 'es'): string
    {
        $rows = self::row($table['headers'], 1);
        foreach ($table['rows'] as $index => $values) $rows .= self::row($values, $index + 2);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $sheet = $xml.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>';
        $files = [
            '[Content_Types].xml' => $xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => $xml.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.($language === 'en' ? 'Inventory' : 'Inventario').'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];
        $body = '';
        $directory = '';
        $offset = 0;
        foreach ($files as $name => $content) {
            $length = strlen($content);
            $crc = crc32($content);
            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $length, $length, strlen($name), 0).$name.$content;
            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $length, $length, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
            $body .= $local;
            $offset += strlen($local);
        }
        return $body.$directory.pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($directory), $offset, 0);
    }
}
