<?php

namespace App\Application;

final class AuditWorkbook
{
    private const SERVICES = [
        'identity' => ['Identidad', 'Identity'], 'configuration' => ['Configuración', 'Settings'],
        'records' => ['Registros', 'Records'], 'files' => ['Archivos', 'Files'],
        'calendar' => ['Calendario', 'Calendar'], 'notifications' => ['Notificaciones', 'Notifications'],
        'treasury' => ['Tesorería', 'Treasury'], 'inventory' => ['Inventario', 'Inventory'],
        'chat' => ['Chat', 'Chat'],
    ];

    private const RESULTS = [
        'success' => ['Correcto', 'Success'], 'rejected' => ['Rechazado', 'Rejected'],
        'failed' => ['Fallido', 'Failed'],
    ];

    private static function value(array $labels, string $code, string $language): string
    {
        return $labels[$code][$language === 'en' ? 1 : 0] ?? $code;
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function row(array $values, int $number): string
    {
        $cells = '';
        foreach ($values as $index => $value) {
            // Inline strings keep identifiers, dates and formula-looking actions as text.
            $column = chr(65 + $index);
            $cells .= '<c r="'.$column.$number.'" t="inlineStr"><is><t xml:space="preserve">'.self::xml((string) ($value ?? '')).'</t></is></c>';
        }

        return '<row r="'.$number.'">'.$cells.'</row>';
    }

    public static function table(iterable $events, string $language = 'es'): array
    {
        $headers = ['Fecha UTC', 'Módulo', 'Acción', 'Resultado', 'Actor', 'Recurso', 'Evento', 'Correlación'];
        if ($language === 'en') $headers = ['Date UTC', 'Module', 'Action', 'Result', 'Actor', 'Resource', 'Event', 'Correlation'];
        $rows = [];
        foreach ($events as $event) {
            $rows[] = array_map(fn ($value) => (string) ($value ?? ''), [
                $event->occurred_at, self::value(self::SERVICES, $event->service, $language), $event->action,
                self::value(self::RESULTS, $event->result, $language),
                $event->actor_id, $event->resource_id, $event->id, $event->correlation_id,
            ]);
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    public static function create(iterable $events, string $language = 'es'): string
    {
        $table = self::table($events, $language);
        $rows = self::row($table['headers'], 1);
        foreach ($table['rows'] as $index => $row) $rows .= self::row($row, $index + 2);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $sheet = $xml.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>';
        $files = [
            '[Content_Types].xml' => $xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => $xml.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.($language === 'en' ? 'Audit' : 'Auditoría').'" sheetId="1" r:id="rId1"/></sheets></workbook>',
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
