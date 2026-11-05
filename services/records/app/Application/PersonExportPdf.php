<?php

namespace App\Application;

use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

final class PersonExportPdf
{
    public static function render(array $headers, array $rows, string $date, string $language, ?string $logo): string
    {
        $merged = new Fpdi('L', 'mm', 'A3');
        $merged->SetAutoPageBreak(false);
        foreach (array_chunk($rows, 200) as $chunk) {
            $part = self::renderPart($headers, $chunk, count($rows), $date, $language, $logo);
            $pages = $merged->setSourceFile(StreamReader::createByString($part));
            for ($page = 1; $page <= $pages; $page++) {
                $template = $merged->importPage($page);
                $size = $merged->getTemplateSize($template);
                $merged->AddPage($size['orientation'], $size);
                $merged->useImportedPage($template);
                $merged->SetFont('Helvetica', '', 8);
                $merged->SetTextColor(76, 89, 102);
                $merged->SetXY($size['width'] - 31, $size['height'] - 11);
                $merged->Cell(24, 5, ($language === 'en' ? 'Page ' : 'Pag. ').$merged->PageNo(), 0, 0, 'R');
            }
            unset($part);
        }
        return $merged->Output('S');
    }

    private static function renderPart(array $headers, array $rows, int $total, string $date, string $language, ?string $logo): string
    {
        $english = $language === 'en';
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = $english ? 'People list' : 'Listado de personas';
        $caption = $english ? 'Date (Colombia): '.$date.' | People: '.$total
            : 'Fecha (Colombia): '.$date.' | Personas: '.$total;
        $image = '';
        if (is_string($logo) && preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/]+={0,2}$/D', $logo)
            && strlen($logo) <= 2_000_000) {
            $image = '<img class="logo" src="'.$logo.'" alt="">';
        }
        $head = implode('', array_map(static fn ($value) => '<th>'.$escape($value).'</th>', $headers));
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>'.implode('', array_map(static fn ($value) => '<td>'.$escape($value).'</td>', $row)).'</tr>';
        }
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'@page{margin:24px 28px 32px}body{font-family:"DejaVu Sans",sans-serif;color:#111827;font-size:7px}'
            .'.header{margin-bottom:14px;min-height:48px}.logo{float:left;width:48px;height:48px;object-fit:contain;margin-right:12px}'
            .'h1{font-size:16px;margin:0 0 5px}p{margin:0;font-size:9px}table{border-collapse:collapse;width:100%;table-layout:fixed}'
            .'thead{display:table-header-group}tr{page-break-inside:avoid}th,td{border:0.5px solid #b7c4d0;padding:4px 3px;overflow-wrap:break-word}'
            .'th{background:#edf4fa;text-align:left;font-weight:bold}</style></head><body>'
            .'<div class="header">'.$image.'<h1>'.$escape($title).'</h1><p>'.$escape($caption).'</p></div>'
            .'<table><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table></body></html>';
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A3', 'landscape');
        $pdf->render();
        return $pdf->output();
    }
}
