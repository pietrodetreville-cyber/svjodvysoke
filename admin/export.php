<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAdmin();

$format = $_GET['format'] ?? 'csv';
$db = db();

$rows = $db->query(
    "SELECT 
        u.label AS jednotka,
        u.type AS typ,
        CASE WHEN u.share_numerator IS NOT NULL AND u.share_denominator > 0
             THEN CONCAT(u.share_numerator, '/', u.share_denominator)
             ELSE '' END AS podil,
        CASE WHEN u.share_numerator IS NOT NULL AND u.share_denominator > 0
             THEN ROUND(u.share_numerator / u.share_denominator * 100, 4)
             ELSE '' END AS podil_pct,
        o.full_name AS vlastnik,
        o.email,
        o.phone AS telefon,
        o.address AS adresa,
        o.residence AS uzivani,
        o.status,
        o.board_note AS poznamka_vyboru,
        CASE o.vote_stance WHEN 'pro' THEN 'Pro' WHEN 'proti' THEN 'Proti' ELSE '' END AS postoj,
        CASE WHEN o.gdpr_consent=1 THEN 'Ano' ELSE 'Ne' END AS gdpr,
        DATE_FORMAT(o.updated_at, '%d.%m.%Y') AS aktualizovano
     FROM units u
     LEFT JOIN owners o ON o.unit_id = u.id
     ORDER BY u.label, o.full_name"
)->fetchAll();

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kartoteka_svj_' . date('Y-m-d') . '.csv"');
    
    $out = fopen('php://output', 'w');
    // BOM pro správné zobrazení češtiny v Excelu
    fputs($out, "\xEF\xBB\xBF");
    
    fputcsv($out, [
        'Jednotka', 'Typ', 'Podíl', 'Podíl %', 'Vlastník',
        'E-mail', 'Telefon', 'Adresa', 'Užívání', 'Stav karty',
        'Poznámka výboru', 'Postoj', 'GDPR', 'Aktualizováno'
    ], ';');
    
    foreach ($rows as $r) {
        fputcsv($out, array_values($r), ';');
    }
    fclose($out);
    exit;
}

if ($format === 'xlsx') {
    // Použijeme PhpSpreadsheet pokud je dostupný, jinak fallback na CSV
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (file_exists($autoload)) {
        require $autoload;
        // PhpSpreadsheet verze
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Kartotéka SVJ');
        
        $headers = [
            'Jednotka', 'Typ', 'Podíl', 'Podíl %', 'Vlastník',
            'E-mail', 'Telefon', 'Adresa', 'Užívání', 'Stav karty',
            'Poznámka výboru', 'Postoj', 'GDPR', 'Aktualizováno'
        ];
        
        $cols = range('A', 'N');
        foreach ($headers as $i => $h) {
            $sheet->setCellValue($cols[$i].'1', $h);
            $sheet->getStyle($cols[$i].'1')->getFont()->setBold(true);
        }
        
        $row = 2;
        foreach ($rows as $r) {
            $vals = array_values($r);
            foreach ($vals as $i => $v) {
                $sheet->setCellValue($cols[$i].$row, $v);
            }
            // Barevné řádky podle stavu
            $color = match($r['status']) {
                'úplná'   => 'EAF3DE',
                'neúplná' => 'FAEEDA',
                'chybí'   => 'FCEBEB',
                default   => 'FFFFFF',
            };
            $sheet->getStyle('A'.$row.':N'.$row)
                  ->getFill()
                  ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                  ->getStartColor()->setRGB($color);
            $row++;
        }
        
        foreach ($cols as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="kartoteka_svj_' . date('Y-m-d') . '.xlsx"');
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    } else {
        // Fallback – CSV s xlsx příponou (Excel to otevře)
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="kartoteka_svj_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'Jednotka', 'Typ', 'Podíl', 'Podíl %', 'Vlastník',
            'E-mail', 'Telefon', 'Adresa', 'Užívání', 'Stav karty',
            'Poznámka výboru', 'Postoj', 'GDPR', 'Aktualizováno'
        ], ';');
        foreach ($rows as $r) {
            fputcsv($out, array_values($r), ';');
        }
        fclose($out);
        exit;
    }
}

header('Location: /admin/owners.php');
exit;
