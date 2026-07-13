<?php
/**
 * Import spotřeb z CSV (Techem export)
 * SVJ Rozhled – Od Vysoké
 *
 * Použití: spustit jednorázově jako superadmin přes prohlížeč (musíš být přihlášen)
 * Soubor nahrát do kořene portálu, spustit, pak smazat!
 *
 * URL: https://odvysoke.drymtym.cz/import_consumption.php
 */

require_once __DIR__ . '/includes/functions.php';
requireSuperAdmin();
$db = db();

$csvFile = __DIR__ . '/DB_export_spotreby_2025.csv';
if (!file_exists($csvFile)) { die('CSV soubor nenalezen: ' . $csvFile); }

$handle = fopen($csvFile, 'r');
$header = fgetcsv($handle, 0, ';');

// Normalizace hlavičky
$header = array_map('trim', $header);
// Očekávané sloupce:
// cislo_techem;byt_zakaznik;byt_techem;ulice;jmeno;rok;mesic;mesic_nazev;typ;jednotka;hodnota_zacatek;hodnota_konec;spotreba

$inserted = 0;
$skipped  = 0;
$errors   = [];

$stmt = $db->prepare("
    INSERT INTO consumption (unit_id, rok, mesic, typ, jednotka, hodnota_zacatek, hodnota_konec, spotreba)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        hodnota_zacatek = VALUES(hodnota_zacatek),
        hodnota_konec   = VALUES(hodnota_konec),
        spotreba        = VALUES(spotreba)
");

while (($row = fgetcsv($handle, 0, ';')) !== false) {
    if (count($row) < 13) { $skipped++; continue; }

    $data = array_combine($header, $row);

    $unit_id         = (int)   trim($data['byt_zakaznik']);
    $rok             = (int)   trim($data['rok']);
    $mesic           = (int)   trim($data['mesic']);
    $typ             = strtoupper(trim($data['typ']));
    $jednotka        = trim($data['jednotka']);
    $hodnota_zacatek = $data['hodnota_zacatek'] !== '' ? (float) str_replace(',', '.', $data['hodnota_zacatek']) : null;
    $hodnota_konec   = $data['hodnota_konec']   !== '' ? (float) str_replace(',', '.', $data['hodnota_konec'])   : null;
    $spotreba        = (float) str_replace(',', '.', $data['spotreba']);

    if (!in_array($typ, ['SV','TV','ITN'])) {
        $errors[] = "Neznámý typ '$typ' na řádku pro byt $unit_id";
        continue;
    }

    try {
        $stmt->execute([$unit_id, $rok, $mesic, $typ, $jednotka, $hodnota_zacatek, $hodnota_konec, $spotreba]);
        $inserted++;
    } catch (Exception $e) {
        $errors[] = "Byt $unit_id / $rok-$mesic / $typ: " . $e->getMessage();
    }
}

fclose($handle);

echo "<pre style='font-family:monospace;font-size:13px'>";
echo "✅ Importováno / aktualizováno: $inserted řádků\n";
echo "⏭️  Přeskočeno (prázdné): $skipped řádků\n";
if ($errors) {
    echo "\n❌ Chyby (" . count($errors) . "):\n";
    foreach ($errors as $e) echo "  - $e\n";
} else {
    echo "✅ Žádné chyby\n";
}
echo "\n⚠️  NEZAPOMEŇ SMAZAT tento soubor ze serveru!\n";
echo "</pre>";
