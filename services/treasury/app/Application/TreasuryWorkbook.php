<?php

namespace App\Application;

final class TreasuryWorkbook
{
    private static function label(string $value, string $language): string
    {
        if ($language !== 'en') return $value;
        return [
            'Comprobante' => 'Receipt', 'Fecha efectiva' => 'Effective date', 'Tipo' => 'Type',
            'Signo' => 'Sign', 'Importe COP' => 'Amount COP', 'Saldo tras asiento COP' => 'Balance after entry COP',
            'Concepto' => 'Concept', 'Referencia del soporte' => 'Supporting reference',
            'Responsable' => 'Recorded by', 'Reverso de ID' => 'Reversal of ID',
            'Creado UTC' => 'Created UTC', 'Junta ID' => 'Council ID', 'Registro UTC' => 'Recorded UTC',
            'Revierte el asiento' => 'Reverses entry', 'Revertido por el asiento' => 'Reversed by entry',
            'Campo' => 'Field', 'Valor' => 'Value',
            'Apertura' => 'Opening balance', 'Ingreso' => 'Income', 'Egreso' => 'Expense', 'Reverso' => 'Reversal',
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

    public static function table(iterable $movements, string $language = 'es'): array
    {
        $headers = [
            'Comprobante', 'Fecha efectiva', 'Tipo', 'Signo', 'Importe COP', 'Saldo tras asiento COP',
            'Concepto', 'Referencia del soporte', 'Responsable', 'Reverso de ID', 'Creado UTC', 'ID',
        ];
        $headers = array_map(fn (string $header) => self::label($header, $language), $headers);
        $rows = [];
        foreach ($movements as $movement) {
            $rows[] = array_map(fn ($value) => (string) ($value ?? ''), [
                'TES-'.str_pad((string) $movement->number, 6, '0', STR_PAD_LEFT),
                $movement->effective_date,
                self::label(['opening' => 'Apertura', 'income' => 'Ingreso', 'expense' => 'Egreso', 'reversal' => 'Reverso'][$movement->kind], $language),
                (int) $movement->sign === -1 ? '-' : '+',
                self::money((int) $movement->amount_cents),
                self::money((int) $movement->balance_after_cents),
                $movement->concept, $movement->support_note, $movement->actor_name,
                $movement->reverses_id, $movement->created_at, $movement->id,
            ]);
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    public static function create(iterable $movements, string $language = 'es'): string
    {
        $table = self::table($movements, $language);
        $rows = self::row($table['headers'], 1);
        foreach ($table['rows'] as $index => $row) $rows .= self::row($row, $index + 2);
        return self::pack($rows, false, $language);
    }

    public static function receipt(array $receipt, string $language = 'es'): string
    {
        $values = [
            ['Comprobante', $receipt['receipt']], ['Junta ID', $receipt['organization_id'] ?? ''],
            ['Tipo', ['opening' => 'Apertura', 'income' => 'Ingreso', 'expense' => 'Egreso', 'reversal' => 'Reverso'][$receipt['kind']]],
            ['Fecha efectiva', $receipt['effective_date']], ['Signo', $receipt['sign'] === -1 ? '-' : '+'],
            ['Importe COP', $receipt['amount']], ['Saldo tras asiento COP', $receipt['balance_after']],
            ['Concepto', $receipt['concept']], ['Referencia del soporte', $receipt['support_note']],
            ['Responsable', $receipt['actor_name']], ['Registro UTC', $receipt['created_at']],
            ['ID', $receipt['id']], ['Revierte el asiento', $receipt['reverses_id']],
            ['Revertido por el asiento', $receipt['reversed_by_id'] ?? null],
        ];
        $rows = self::row([self::label('Campo', $language), self::label('Valor', $language)], 1);
        foreach ($values as $index => $value) {
            $value[0] = self::label($value[0], $language);
            if ($value[0] === self::label('Tipo', $language)) $value[1] = self::label($value[1], $language);
            $rows .= self::row($value, $index + 2);
        }
        return self::pack($rows, true, $language);
    }

    private static function pack(string $rows, bool $receipt = false, string $language = 'es'): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $columns = $receipt ? '<cols><col min="1" max="1" width="35" customWidth="1"/><col min="2" max="2" width="95" customWidth="1"/></cols>' : '';
        $sheet = $xml.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$columns.'<sheetData>'.$rows.'</sheetData></worksheet>';
        $files = [
            '[Content_Types].xml' => $xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => $xml.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.($language === 'en' ? 'Treasury' : 'Tesorería').'" sheetId="1" r:id="rId1"/></sheets></workbook>',
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

    private static function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
