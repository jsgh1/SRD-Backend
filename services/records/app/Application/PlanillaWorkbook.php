<?php

namespace App\Application;

final class PlanillaWorkbook
{
    private const LABELS = [
        'email' => 'Correo electrónico', 'phone' => 'Teléfono',
        'property_name' => 'Nombre del predio', 'zone' => 'Zona',
        'position_label' => 'Cargo', 'descriptive_role' => 'Rol descriptivo',
        'status' => 'Estado del registro', 'affiliated' => 'Afiliado',
    ];
    private const LABELS_EN = [
        'email' => 'Email', 'phone' => 'Phone',
        'property_name' => 'Property name', 'zone' => 'Area',
        'position_label' => 'Position', 'descriptive_role' => 'Descriptive role',
        'status' => 'Record status', 'affiliated' => 'Affiliated',
    ];

    private static function xml(string $value): string
    {
        $safe = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
        return htmlspecialchars($safe ?? '', ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function row(array $values, int $number): string
    {
        $cells = '';
        foreach ($values as $index => $value) {
            $column = chr(65 + $index);
            $cells .= '<c r="'.$column.$number.'" t="inlineStr"><is><t xml:space="preserve">'.self::xml((string) ($value ?? '')).'</t></is></c>';
        }
        return '<row r="'.$number.'">'.$cells.'</row>';
    }

    private static function value(object $person, string $column, string $language): string
    {
        if ($language === 'en') return match ($column) {
            'zone' => ['rural' => 'Rural', 'urban' => 'Urban'][$person->zone] ?? '',
            'status' => $person->status === 'complete' ? 'Complete' : 'Pending',
            'affiliated' => $person->affiliated === null ? 'No data' : ((int) $person->affiliated === 1 ? 'Yes' : 'No'),
            'descriptive_role' => ['admin' => 'Administrator', 'registrar' => 'Registrar',
                'treasurer' => 'Treasurer', 'auditor' => 'Auditor', 'viewer' => 'Viewer'][$person->descriptive_role] ?? '',
            'position_label' => (string) ($person->position_label_en ?: $person->position_label ?? ''),
            default => (string) ($person->{$column} ?? ''),
        };
        return match ($column) {
            'zone' => ['rural' => 'Rural', 'urban' => 'Urbana'][$person->zone] ?? '',
            'status' => $person->status === 'complete' ? 'Completado' : 'Pendiente',
            'affiliated' => $person->affiliated === null ? 'Sin dato' : ((int) $person->affiliated === 1 ? 'Sí' : 'No'),
            'descriptive_role' => ['admin' => 'Administrador', 'registrar' => 'Registrador',
                'treasurer' => 'Tesorero', 'auditor' => 'Auditor', 'viewer' => 'Consultor'][$person->descriptive_role] ?? '',
            default => (string) ($person->{$column} ?? ''),
        };
    }

    public static function table(iterable $persons, array $columns, string $language = 'es'): array
    {
        $header = $language === 'en' ? [
            'No.', 'Full name', 'Document type', 'Document number',
            ...array_map(fn ($key) => self::LABELS_EN[$key], $columns), 'Signature',
        ] : [
            'N.º', 'Nombres y apellidos', 'Tipo de documento', 'Número de documento',
            ...array_map(fn ($key) => self::LABELS[$key], $columns), 'Firma',
        ];
        $data = [];
        foreach ($persons as $person) {
            $values = [
                (string) (count($data) + 1), trim($person->first_names.' '.($person->last_names ?? '')),
                ($language === 'en' ? ['RC' => 'Birth certificate', 'TI' => 'Identity card',
                    'CC' => 'Citizenship card', 'CE' => 'Foreigner ID', 'NIT' => 'Tax ID'] :
                ['RC' => 'Registro Civil', 'TI' => 'Tarjeta de Identidad',
                    'CC' => 'Cédula de Ciudadanía', 'CE' => 'Cédula de Extranjería',
                    'NIT' => 'NIT'])[$person->document_type] ?? $person->document_type,
                $person->document_number,
            ];
            foreach ($columns as $column) $values[] = self::value($person, $column, $language);
            $values[] = ''; // The signature column is always empty and last.
            $data[] = $values;
        }
        return ['headers' => $header, 'rows' => $data];
    }

    public static function create(iterable $persons, array $columns, array $headings, string $language = 'es', ?string $logo = null): string
    {
        $date = now('America/Bogota');
        $table = self::table($persons, $columns, $language);
        $rows = self::row([$headings['h1']], 1)
            .self::row([$headings['h2']], 2)
            .self::row([$language === 'en'
                ? 'MONTH: '.$date->format('m').'     DAY: '.$date->format('d').'     YEAR: '.$date->format('Y')
                : 'MES: '.$date->format('m').'     DÍA: '.$date->format('d').'     AÑO: '.$date->format('Y')], 3)
            .self::row([$headings['h3']], 4)
            .self::row([], 5)
            .self::row($table['headers'], 6);
        $number = 7;
        foreach ($table['rows'] as $values) $rows .= self::row($values, $number++);
        $rows .= self::row([], $number++);
        $rows .= self::row(['________________________', '________________________'], $number++);
        $rows .= self::row($language === 'en' ? ['PRESIDENT', 'SECRETARY'] : ['PRESIDENTE', 'SECRETARIO'], $number);
        $last = chr(64 + count($table['headers']));
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $sheet = $xml.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            .'<cols><col min="1" max="1" width="8" customWidth="1"/><col min="2" max="2" width="32" customWidth="1"/>'
            .'<col min="3" max="'.$last.'" width="22" customWidth="1"/></cols>'
            .'<sheetData>'.$rows.'</sheetData>'
            .'<mergeCells count="4"><mergeCell ref="A1:'.$last.'1"/><mergeCell ref="A2:'.$last.'2"/>'
            .'<mergeCell ref="A3:'.$last.'3"/><mergeCell ref="A4:'.$last.'4"/></mergeCells>'
            .'<printOptions horizontalCentered="1"/><pageMargins left="0.25" right="0.25" top="0.4" bottom="0.4" header="0" footer="0"/>'
            .'<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            .($logo ? '<drawing r:id="rId1"/>' : '').'</worksheet>';
        $files = [
            '[Content_Types].xml' => $xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => $xml.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.($language === 'en' ? 'Signatures' : 'Planilla').'" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm.Print_Titles" localSheetId="0">'.($language === 'en' ? 'Signatures' : 'Planilla').'!$1:$6</definedName></definedNames></workbook>',
            'xl/_rels/workbook.xml.rels' => $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];
        if ($logo) {
            $imageBytes = base64_decode(substr($logo, 22), true);
            [$imageWidth, $imageHeight] = getimagesizefromstring($imageBytes);
            $scale = min(64 / $imageWidth, 64 / $imageHeight);
            $cx = (int) round($imageWidth * $scale * 9525);
            $cy = (int) round($imageHeight * $scale * 9525);
            $files['[Content_Types].xml'] = str_replace('</Types>', '<Default Extension="png" ContentType="image/png"/><Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/></Types>', $files['[Content_Types].xml']);
            $files['xl/worksheets/_rels/sheet1.xml.rels'] = $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>';
            $files['xl/drawings/drawing1.xml'] = $xml.'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
                .'<xdr:oneCellAnchor><xdr:from><xdr:col>0</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from><xdr:ext cx="'.$cx.'" cy="'.$cy.'"/>'
                .'<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="1" name="Logo de la junta"/><xdr:cNvPicPr/></xdr:nvPicPr><xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill><xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>';
            $files['xl/drawings/_rels/drawing1.xml.rels'] = $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo.png"/></Relationships>';
            $files['xl/media/logo.png'] = $imageBytes;
        }
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
