<?php

namespace App\Application;

final class PersonWorkbook
{
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

    public static function table(iterable $persons, string $language = 'es'): array
    {
        $english = $language === 'en';
        $headers = $english ? [
            'No.', 'First names', 'Last names', 'Document type', 'Document number',
            'Status', 'Membership', 'Area', 'Birth date', 'Position', 'Registration UTC',
        ] : [
            'N.º', 'Nombres', 'Apellidos', 'Tipo de documento', 'Número de documento',
            'Estado', 'Afiliación', 'Zona', 'Fecha de nacimiento', 'Cargo', 'Registro UTC',
        ];
        $rows = [];
        $number = 1;
        foreach ($persons as $person) {
            $rows[] = array_map(fn ($value) => (string) ($value ?? ''), [
                $number++, $person->first_names, $person->last_names,
                ($english ? ['RC' => 'Civil Registry', 'TI' => 'Identity Card', 'CC' => 'Citizenship Card',
                    'CE' => 'Foreigner ID', 'NIT' => 'NIT'] : ['RC' => 'Registro Civil', 'TI' => 'Tarjeta de Identidad',
                    'CC' => 'Cédula de Ciudadanía', 'CE' => 'Cédula de Extranjería', 'NIT' => 'NIT'])[$person->document_type] ?? $person->document_type,
                $person->document_number, $person->status === 'complete' ? ($english ? 'Complete' : 'Completado') : ($english ? 'Pending' : 'Pendiente'),
                $person->affiliated === null ? ($english ? 'Not specified' : 'Sin dato') : ((int) $person->affiliated === 1 ? ($english ? 'Yes' : 'Sí') : ($english ? 'No' : 'No')),
                ($english ? ['rural' => 'Rural', 'urban' => 'Urban'] : ['rural' => 'Rural', 'urban' => 'Urbana'])[$person->zone] ?? '',
                $person->birth_date, $english ? (($person->position_label_en ?? null) ?: $person->position_label) : $person->position_label, $person->created_at,
            ]);
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    public static function create(iterable $persons, string $language = 'es'): string
    {
        $table = self::table($persons, $language);
        $rows = self::row($table['headers'], 1);
        foreach ($table['rows'] as $index => $values) {
            $rows .= self::row($values, $index + 2);
        }
        return self::pack($rows, false, $language);
    }

    public static function individual(array $person, array $additional, string $language = 'es'): string
    {
        $table = self::individualTable($person, $additional, $language);
        $rows = self::row($table['headers'], 1);
        foreach ($table['rows'] as $index => $values) $rows .= self::row($values, $index + 2);
        return self::pack($rows, true, $language);
    }

    public static function individualTable(array $person, array $additional, string $language = 'es'): array
    {
        $english = $language === 'en';
        $labels = ['first_names' => 'Nombres',
            'last_names' => 'Apellidos', 'document_type' => 'Tipo de documento', 'document_number' => 'Número de documento',
            'status' => 'Estado', 'affiliated' => 'Afiliación', 'zone' => 'Zona', 'gender' => 'Género',
            'birth_date' => 'Fecha de nacimiento', 'phone' => 'Teléfono', 'email' => 'Correo electrónico',
            'position_label' => 'Cargo', 'descriptive_role' => 'Rol descriptivo', 'property_name' => 'Predio',
            'address' => 'Dirección', 'neighborhood' => 'Barrio',
            'created_at' => 'Registro UTC', 'updated_at' => 'Actualización UTC'];
        if ($english) $labels = array_replace($labels, [
            'first_names' => 'First names', 'last_names' => 'Last names',
            'document_type' => 'Document type', 'document_number' => 'Document number', 'status' => 'Status',
            'affiliated' => 'Membership', 'zone' => 'Area', 'gender' => 'Gender', 'birth_date' => 'Birth date',
            'phone' => 'Phone', 'email' => 'Email address', 'position_label' => 'Position',
            'descriptive_role' => 'Descriptive role', 'property_name' => 'Property', 'address' => 'Address',
            'neighborhood' => 'Neighborhood', 'created_at' => 'Registration UTC',
            'updated_at' => 'Updated UTC',
        ]);
        $maps = ['document_type' => ['RC' => 'Registro Civil', 'TI' => 'Tarjeta de Identidad', 'CC' => 'Cédula de Ciudadanía', 'CE' => 'Cédula de Extranjería', 'NIT' => 'NIT'],
            'status' => ['pending' => 'Pendiente', 'complete' => 'Completado'], 'zone' => ['urban' => 'Urbana', 'rural' => 'Rural'],
            'gender' => ['male' => 'Masculino', 'female' => 'Femenino', 'other' => 'Otro'],
            'descriptive_role' => ['admin' => 'Administrador', 'registrar' => 'Registrador', 'treasurer' => 'Tesorero', 'auditor' => 'Auditor', 'viewer' => 'Consultor']];
        if ($english) $maps = [
            'document_type' => ['RC' => 'Civil Registry', 'TI' => 'Identity Card', 'CC' => 'Citizenship Card', 'CE' => 'Foreigner ID', 'NIT' => 'NIT'],
            'status' => ['pending' => 'Pending', 'complete' => 'Complete'], 'zone' => ['urban' => 'Urban', 'rural' => 'Rural'],
            'gender' => ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'],
            'descriptive_role' => ['admin' => 'Administrator', 'registrar' => 'Registrar', 'treasurer' => 'Treasurer', 'auditor' => 'Auditor', 'viewer' => 'Viewer'],
        ];
        $rows = [];
        foreach ($labels as $key => $label) {
            $value = $person[$key] ?? '';
            if ($key === 'affiliated') $value = $value === '' ? ($english ? 'Not specified' : 'Sin dato') : ((int) $value === 1 ? ($english ? 'Yes' : 'Sí') : 'No');
            else if ($key === 'position_label' && $english && !empty($person['position_label_en'])) $value = $person['position_label_en'];
            else $value = $maps[$key][$value] ?? $value;
            $rows[] = [$label, (string) $value];
        }
        foreach ($additional as $field) {
            $rows[] = [($english ? 'Additional field: ' : 'Campo adicional: ').($english ? ($field['label_en'] ?? $field['label']) : $field['label']),
                (string) ($english ? ($field['display_en'] ?? $field['display']) : $field['display'])];
        }
        return ['headers' => $english ? ['Field', 'Value'] : ['Campo', 'Valor'], 'rows' => $rows];
    }

    private static function pack(string $rows, bool $individual = false, string $language = 'es'): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $columns = $individual ? '<cols><col min="1" max="1" width="35" customWidth="1"/><col min="2" max="2" width="95" customWidth="1"/></cols>' : '';
        $sheet = $xml.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$columns.'<sheetData>'.$rows.'</sheetData></worksheet>';
        $files = [
            '[Content_Types].xml' => $xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => $xml.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.($language === 'en' ? 'People' : 'Personas').'" sheetId="1" r:id="rId1"/></sheets></workbook>',
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
