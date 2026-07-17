<?php
/**
 * Generate a Verifactu-compliant invoice PDF from a FacturaScripts invoice.
 *
 * Usage:
 *   cd ~/verifactu-poc
 *   php make-invoice-pdf.php INVOICE_CODE
 *
 * Output:
 *   ~/verifactu-poc/invoices/{NIF}_{CODE}.pdf
 *
 * Layout matches FacturaScripts' default PDF (Spanish), plus:
 *   - Empresa logo (loaded from FS's attached_files), if configured
 *   - Conditional Dto. and IRPF columns when applicable
 *   - Tax breakdown table (per tax type)
 *   - Receipts/payments table
 *   - Exemption block listing IVA exception reasons
 *   - QR code + "VERI*FACTU" label + AEAT CSV (when submission exists)
 */

require __DIR__ . '/vendor/autoload.php';

use Mpdf\Mpdf;

const FS_ROOT = '/var/www/html/facturas';

// ===== CLI args =====
array_shift($argv);
$invoiceCode = null;
$envArg      = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--env=')) {
        $envArg = strtolower(trim(substr($a, 6)));
    } elseif ($invoiceCode === null) {
        $invoiceCode = $a;
    }
}
if (!$invoiceCode) {
    fwrite(STDERR, "Usage: php make-invoice-pdf.php INVOICE_CODE [--env=preproduccion|produccion]\n");
    exit(1);
}
if ($envArg !== null && !in_array($envArg, ['preproduccion','produccion'], true)) {
    fwrite(STDERR, "Invalid --env value.\n"); exit(1);
}

// ===== Config + DB =====
$configCandidates = ['/secrets/companies.php', __DIR__ . '/companies.php'];
$configPath = null;
foreach ($configCandidates as $p) {
    if (file_exists($p)) { $configPath = $p; break; }
}
if ($configPath === null) {
    fwrite(STDERR, "Missing companies.php\n"); exit(1);
}
$config = require $configPath;
$db = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['database']['host'],
        (int)$config['database']['port'],
        $config['database']['name']),
    $config['database']['user'],
    $config['database']['pass'],
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// ===== Load invoice + empresa + logo =====
$stmt = $db->prepare("
    SELECT f.*,
           e.cifnif       AS empresa_nif,
           e.nombre       AS empresa_nombre,
           e.direccion    AS empresa_direccion,
           e.codpostal    AS empresa_codpostal,
           e.ciudad       AS empresa_ciudad,
           e.provincia    AS empresa_provincia,
           e.email        AS empresa_email,
           e.telefono1    AS empresa_telefono,
           e.idlogo       AS empresa_idlogo,
           af.filename    AS logo_filename,
           af.mimetype    AS logo_mimetype,
           af.path        AS logo_path,
           c.tipoidfiscal AS recipient_idtype,
           s.descripcion  AS serie_descripcion
    FROM facturascli f
    INNER JOIN empresas e ON e.idempresa = f.idempresa
    LEFT JOIN attached_files af ON af.idfile = e.idlogo
    LEFT JOIN clientes c ON c.codcliente = f.codcliente
    LEFT JOIN series s    ON s.codserie  = f.codserie
    WHERE f.codigo = :code
    LIMIT 1
");
$stmt->execute([':code' => $invoiceCode]);
$inv = $stmt->fetch();
if (!$inv) {
    fwrite(STDERR, "Invoice $invoiceCode not found in FacturaScripts.\n");
    exit(1);
}

// ===== Lines =====
$lstmt = $db->prepare("
    SELECT * FROM lineasfacturascli
    WHERE idfactura = :id ORDER BY orden, idlinea
");
$lstmt->execute([':id' => (int)$inv['idfactura']]);
$lines = $lstmt->fetchAll();

// ===== Bank accounts of the issuing empresa =====
$bstmt = $db->prepare("
    SELECT codcuenta, descripcion, iban, swift
    FROM cuentasbanco
    WHERE idempresa = (
        SELECT idempresa FROM facturascli WHERE codigo = :code LIMIT 1
    )
    AND activa = 1 AND iban IS NOT NULL AND iban <> ''
    ORDER BY codcuenta
");
$bstmt->execute([':code' => $invoiceCode]);
$bankAccounts = $bstmt->fetchAll();

// ===== Verifactu submission =====
// Selection rule:
//  - --env=X → that specific environment
//  - no flag → prefer producción, fall back to preproducción
if ($envArg !== null) {
    $vstmt = $db->prepare("
        SELECT * FROM verifactu_submissions
        WHERE empresa_nif = :nif AND invoice_code = :code
          AND status = 'submitted' AND environment = :env
        ORDER BY id DESC LIMIT 1
    ");
    $vstmt->execute([':nif' => $inv['empresa_nif'], ':code' => $invoiceCode, ':env' => $envArg]);
} else {
    $vstmt = $db->prepare("
        SELECT * FROM verifactu_submissions
        WHERE empresa_nif = :nif AND invoice_code = :code AND status = 'submitted'
        ORDER BY (environment = 'produccion') DESC, id DESC
        LIMIT 1
    ");
    $vstmt->execute([':nif' => $inv['empresa_nif'], ':code' => $invoiceCode]);
}
$vf = $vstmt->fetch() ?: null;
if ($vf) {
    fwrite(STDERR, "  Verifactu row: environment={$vf['environment']}, csv={$vf['csv']}\n");
} else {
    fwrite(STDERR, "  No matching Verifactu submission — PDF will show 'Sin envío Verifactu'.\n");
}

// ===== Logo file → data URI =====
$logoDataUri = null;
if (!empty($inv['logo_path'])) {
    $candidates = [
        $inv['logo_path'],
        FS_ROOT . '/' . ltrim($inv['logo_path'], '/'),
        FS_ROOT . '/MyFiles/' . ltrim($inv['logo_path'], '/'),
    ];
    foreach ($candidates as $path) {
        if (is_file($path) && is_readable($path)) {
            $mime = $inv['logo_mimetype'] ?: 'image/png';
            $logoDataUri = 'data:' . $mime . ';base64,'
                . base64_encode(file_get_contents($path));
            break;
        }
    }
    if (!$logoDataUri) {
        fwrite(STDERR, "  warning: logo file not found at: " . $inv['logo_path'] . "\n");
    }
}

// ===== QR PNG → data URI =====
$qrDataUri = null;
if ($vf && $vf['qr_png_path'] && is_file($vf['qr_png_path'])) {
    $qrDataUri = 'data:image/png;base64,'
        . base64_encode(file_get_contents($vf['qr_png_path']));
}

// ===== Render HTML & generate PDF =====
$html = renderHtml($inv, $lines, $bankAccounts ?? [], $logoDataUri, $qrDataUri, $vf);

$mpdf = new Mpdf([
    'format'        => 'A4',
    'margin_left'   => 15,
    'margin_right'  => 15,
    'margin_top'    => 15,
    'margin_bottom' => 18,
    'default_font'  => 'sans-serif',
]);
$mpdf->WriteHTML($html);

$outDir = __DIR__ . '/invoices';
if (!is_dir($outDir)) mkdir($outDir, 0700, true);
$safe    = preg_replace('/[^A-Za-z0-9_-]/', '_', $invoiceCode);
$outPath = $outDir . "/{$inv['empresa_nif']}_{$safe}.pdf";
$mpdf->Output($outPath, \Mpdf\Output\Destination::FILE);
echo "PDF written: $outPath\n";

// Upload to MinIO under docs/{nif}/{year}/T{q}/emitidas/{filename}
if (file_exists('/incoming/MinioClient.php')) {
    require_once '/incoming/MinioClient.php';
    try {
        $minio   = MinioClient::fromEnv();
        $fecha   = $inv['fecha'] ?? date('Y-m-d'); // YYYY-MM-DD
        $year    = substr($fecha, 0, 4);
        $month   = (int)substr($fecha, 5, 2);
        $quarter = 'T' . ceil($month / 3);
        $nif     = $inv['empresa_nif'];
        $bucket  = 'docs';
        $key     = "$nif/$year/$quarter/emitidas/{$nif}_{$safe}.pdf";
        $minio->createBucket($bucket);
        $ok = $minio->putObject($bucket, $key, file_get_contents($outPath), 'application/pdf');
        echo "MinIO: " . ($ok ? "uploaded → docs/$key" : "upload FAILED") . "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "MinIO upload skipped: " . $e->getMessage() . "\n");
    }
}

// ===== Helpers =====
function fmt(float $n): string  { return number_format($n, 2, ',', '.'); }
function pct(float $n): string  { return rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',') . ' %'; }
function esc(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function exemptionLabel(?string $code): ?string {
    $key = strtoupper(trim((string)$code));
    if ($key === '') return null;
    return match ($key) {
        'ES_07'                    => 'No sujeta art. 7 LIVA — Operaciones no sujetas (autoconsumo, muestras, etc.)',
        'ES_14'                    => 'No sujeta art. 14 LIVA — Regímenes aduaneros, zonas francas, tránsito',
        'ES_68_70', 'ES_68-70'     => 'No sujeta arts. 68–70 LIVA — Reglas de localización (B2B intracomunitario o exterior)',
        'ES_NS_OTROS', 'ES_NSO'    => 'No sujeta — Otros supuestos (NATO, convenios internacionales, fuerzas armadas)',
        'ES_20'                    => 'Exenta art. 20 LIVA — Exenciones interiores (sanidad, educación, seguros, financieras, etc.)',
        'ES_21'                    => 'Exenta art. 21 LIVA — Exportaciones a países terceros',
        'ES_22'                    => 'Exenta art. 22 LIVA — Operaciones asimiladas a las exportaciones',
        'ES_23_24', 'ES_23-24'     => 'Exenta arts. 23–24 LIVA — Zonas francas y depósitos aduaneros',
        'ES_25'                    => 'Exenta art. 25 LIVA — Entregas intracomunitarias',
        'ES_OTROS', 'ES_OTHER'     => 'Exenta — Otras exenciones (oro de inversión, regímenes especiales, organismos internacionales)',
        'ES_84'                    => 'Sujeta con inversión del sujeto pasivo — art. 84 LIVA',
        default => "Operación exenta o especial ($code)",
    };
}

/**
 * For Spanish customers we use the tipoidfiscal mapping (NIF, NIF-IVA, ...).
 * For foreign customers FS prints the country code as the label (e.g. "ISR"),
 * so we mirror that.
 */
function recipientIdLabel(?string $codpais, ?string $tipoidfiscal): string {
    $code = strtoupper(trim((string)$codpais));
    if ($code !== '' && $code !== 'ESP' && $code !== 'ES') {
        return $code;
    }
    return idLabel($tipoidfiscal);
}

function countryName(?string $code): string {
    $key = strtoupper(trim((string)$code));
    return match ($key) {
        '', 'ESP', 'ES' => 'España',
        'ISR', 'IL'     => 'Israel',
        'USA', 'US'     => 'Estados Unidos',
        'GBR', 'GB'     => 'Reino Unido',
        'FRA', 'FR'     => 'Francia',
        'DEU', 'DE'     => 'Alemania',
        'ITA', 'IT'     => 'Italia',
        'PRT', 'PT'     => 'Portugal',
        'AND', 'AD'     => 'Andorra',
        'CHE', 'CH'     => 'Suiza',
        'AUT', 'AT'     => 'Austria',
        'BEL', 'BE'     => 'Bélgica',
        'NLD', 'NL'     => 'Países Bajos',
        'IRL', 'IE'     => 'Irlanda',
        'LUX', 'LU'     => 'Luxemburgo',
        'POL', 'PL'     => 'Polonia',
        'CZE', 'CZ'     => 'Chequia',
        'CAN', 'CA'     => 'Canadá',
        'MEX', 'MX'     => 'México',
        'BRA', 'BR'     => 'Brasil',
        'ARG', 'AR'     => 'Argentina',
        default         => $key,
    };
}

function idLabel(?string $tipoidfiscal): string {
    $key = strtoupper(trim((string)$tipoidfiscal));
    return match (true) {
        $key === '' || $key === 'NIF'                                       => 'NIF',
        str_contains($key, 'NIF-IVA') || str_contains($key, 'VAT')          => 'NIF-IVA',
        str_contains($key, 'PASAPORTE') || str_contains($key, 'PASSPORT')   => 'Pasaporte',
        str_contains($key, 'OFICIAL') || str_contains($key, 'NATIONAL')     => 'Documento oficial',
        str_contains($key, 'RESIDENCIA') || str_contains($key, 'RESIDENCE') => 'Cert. residencia',
        str_contains($key, 'OTRO') || str_contains($key, 'OTHER')           => 'ID fiscal',
        default                                                              => $tipoidfiscal,
    };
}

function renderHtml(array $inv, array $lines, array $bankAccounts,
                   ?string $logoDataUri, ?string $qrDataUri, ?array $vf): string {

    // Detect which optional columns to show
    $hasDescription = false;
    $hasDiscount    = false;
    $hasIrpf        = false;
    $hasIva         = false;
    foreach ($lines as $ln) {
        $d = trim((string)$ln['descripcion']);
        $r = trim((string)($ln['referencia'] ?? ''));
        if ($d !== '' || $r !== '')   $hasDescription = true;
        if ((float)$ln['dtopor'] > 0) $hasDiscount    = true;
        if ((float)$ln['irpf']   > 0) $hasIrpf        = true;
        if ((float)$ln['iva']    > 0) $hasIva         = true;
    }

    // ----- Lines table -----
    $linesHtml = '';
    foreach ($lines as $ln) {
        $desc = trim((string)$ln['descripcion']);
        $ref  = trim((string)($ln['referencia'] ?? ''));
        if ($ref !== '' && $desc !== '') $desc = "$ref — $desc";
        elseif ($ref !== '')             $desc = $ref;

        $linesHtml .= '<tr>';
        if ($hasDescription) {
            $linesHtml .= '<td>' . esc($desc) . '</td>';
        }
        $linesHtml .= '<td class="r">' . fmt((float)$ln['cantidad']) . '</td>'
                    . '<td class="r">' . fmt((float)$ln['pvpunitario']) . '</td>';
        if ($hasDiscount) {
            $linesHtml .= '<td class="r">'
                . ((float)$ln['dtopor'] > 0 ? pct((float)$ln['dtopor']) : '—')
                . '</td>';
        }
        $linesHtml .= '<td class="r">' . fmt((float)$ln['pvptotal']) . '</td>';
        if ($hasIva) {
            $linesHtml .= '<td class="r">' . pct((float)$ln['iva']) . '</td>';
        }
        if ($hasIrpf) {
            $linesHtml .= '<td class="r">'
                . ((float)$ln['irpf'] > 0 ? pct((float)$ln['irpf']) : '—')
                . '</td>';
        }
        $linesHtml .= '</tr>';
    }

    $linesHeader = '<tr>'
        . ($hasDescription ? '<th>Descripción</th>' : '')
        . '<th class="r">Cantidad</th>'
        . '<th class="r">Precio</th>'
        . ($hasDiscount ? '<th class="r">Dto.</th>' : '')
        . '<th class="r">Neto</th>'
        . ($hasIva  ? '<th class="r">IVA</th>'  : '')
        . ($hasIrpf ? '<th class="r">IRPF</th>' : '')
        . '</tr>';

    // ----- Tax breakdown (per tax type/rate) -----
    $ivaBuckets = [];   // rate => ['base','tax']
    $irpfBuckets = [];  // rate => ['base','tax']
    foreach ($lines as $ln) {
        $base = (float)$ln['pvptotal'];
        $iv   = sprintf('%.2f', (float)$ln['iva']);
        if (!isset($ivaBuckets[$iv])) $ivaBuckets[$iv] = ['base'=>0,'tax'=>0];
        $ivaBuckets[$iv]['base'] += $base;
        $ivaBuckets[$iv]['tax']  += $base * ((float)$ln['iva']) / 100.0;

        if ((float)$ln['irpf'] > 0) {
            $ir = sprintf('%.2f', (float)$ln['irpf']);
            if (!isset($irpfBuckets[$ir])) $irpfBuckets[$ir] = ['base'=>0,'tax'=>0];
            $irpfBuckets[$ir]['base'] += $base;
            $irpfBuckets[$ir]['tax']  += $base * ((float)$ln['irpf']) / 100.0;
        }
    }

    $taxRows = '';
    foreach ($ivaBuckets as $rateStr => $sums) {
        $taxRows .= '<tr>'
            . '<td>IVA ' . pct((float)$rateStr) . '</td>'
            . '<td class="r">' . fmt($sums['base']) . '</td>'
            . '<td class="r">' . pct((float)$rateStr) . '</td>'
            . '<td class="r">' . fmt($sums['tax']) . '</td>'
            . '</tr>';
    }
    foreach ($irpfBuckets as $rateStr => $sums) {
        $taxRows .= '<tr>'
            . '<td>IRPF ' . pct((float)$rateStr) . '</td>'
            . '<td class="r">' . fmt($sums['base']) . '</td>'
            . '<td class="r">' . pct((float)$rateStr) . '</td>'
            . '<td class="r">-' . fmt($sums['tax']) . '</td>'
            . '</tr>';
    }

    // ----- Final totals (horizontal row, FS style) -----
    $hasRet      = (float)$inv['totalirpf'] > 0;
    $currency    = $inv['coddivisa'] ?: 'EUR';
    $currencyLbl = strtoupper($currency) === 'EUR' ? 'EURO' : strtoupper($currency);
    $totalCols   = 2 + ($hasRet ? 1 : 0); // Net + Total + maybe Retention

    // ----- Exemptions block -----
    $exempt = [];
    foreach ($lines as $ln) {
        $label = exemptionLabel($ln['excepcioniva'] ?? null);
        if ($label !== null) $exempt[$label] = true;
    }
    $exemptionsHtml = '';
    if ($exempt) {
        $items = '';
        foreach (array_keys($exempt) as $text) $items .= '<div>· ' . esc($text) . '</div>';
        $exemptionsHtml = '<div class="exempt">' . $items . '</div>';
    }

    // ----- Bank accounts block -----
    // Observaciones / notes
    $notesHtml = '';
    $notes = trim((string)($inv['observaciones'] ?? ''));
    if ($notes !== '') {
        $notesHtml = '<div class="lbl-block">Observaciones</div>'
            . '<div class="notes">' . nl2br(esc($notes)) . '</div>';
    }

    $bankHtml = '';
    if ($bankAccounts) {
        $rows = '';
        foreach ($bankAccounts as $b) {
            $iban = chunk_split(str_replace(' ', '', (string)$b['iban']), 4, ' ');
            $rows .= '<tr>'
                . '<td>' . esc($b['descripcion'] ?: '—') . '</td>'
                . '<td><span class="iban">' . esc(trim($iban)) . '</span></td>'
                . '<td>' . esc($b['swift'] ?: '') . '</td>'
                . '</tr>';
        }
        $bankHtml = '<div class="lbl-block">Datos bancarios</div>'
            . '<p class="pay-note">Le rogamos efectúe el pago, a la mayor brevedad posible, en la cuenta bancaria indicada a continuación.</p>'
            . '<table class="bank"><thead><tr>'
            . '<th>Banco</th>'
            . '<th>IBAN</th>'
            . '<th>SWIFT/BIC</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    // ----- Header & QR -----
    $logoBlock = $logoDataUri
        ? '<img src="' . $logoDataUri . '" style="max-width: 55mm; max-height: 22mm; margin-bottom: 6px">'
        : '';

    $qrBlock = $qrDataUri
        ? '<div class="qr">'
            . '<img src="' . $qrDataUri . '" style="width:32mm;height:32mm">'
            . '<div class="vf">VERI*FACTU</div>'
            . ($vf['csv'] ? '<div class="csv">' . esc($vf['csv']) . '</div>' : '')
            . '</div>'
        : '<div class="qr no-qr">Sin envío Verifactu</div>';

    return '<!doctype html><html><head><style>
        body { font-family: sans-serif; font-size: 10pt; color: #1a1a1a; }
        h1 { font-size: 14pt; margin: 0 0 4px; color: #1f3a5f; }
        .header { width: 100%; margin-bottom: 16px; }
        .header td { vertical-align: top; }
        .header .right { text-align: right; }
        .qr { text-align: center; }
        .qr .vf { font-weight: bold; margin-top: 3px; font-size: 9pt; letter-spacing: 1px; }
        .qr .csv { font-family: monospace; font-size: 8pt; color: #555; margin-top: 2px; }
        .qr.no-qr { padding: 18mm 0; color: #999; font-size: 8pt; border: 1px dashed #ccc; }
        .meta { width: 100%; margin: 4px 0 14px; }
        .meta td { padding: 2px 0; vertical-align: top; }
        .meta .lbl { color: #666; font-size: 9pt; }
        .meta strong { font-size: 11pt; }
        .lbl-block { color: #666; font-size: 9pt; margin: 16px 0 4px; }
        table.lines, table.tax, table.bank, table.totals-row { width: 100%; border-collapse: collapse; }
        table.lines th, table.tax th, table.bank th, table.totals-row th { background: #1f3a5f; color: #fff; padding: 6px 8px; text-align: left; font-size: 9pt; font-weight: 500; }
        table.lines th.r, table.tax th.r, table.bank th.r, table.totals-row th.r { text-align: right; }
        table.lines td, table.tax td, table.bank td, table.totals-row td { padding: 6px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
        table.lines td.r, table.tax td.r, table.bank td.r, table.totals-row td.r { text-align: right; }
        table.totals-row td { font-size: 11pt; }
        .iban { font-family: monospace; letter-spacing: 0.5px; }
        .pay-note { margin: 4px 0 8px; font-size: 9.5pt; color: #1a1a1a; }
        .exempt { margin-top: 14px; padding: 8px 12px; background: #f7f4ee; border-left: 3px solid #d97757; font-size: 9pt; color: #1a1a1a; }
        .exempt div { padding: 1px 0; }
        .notes { font-size: 9.5pt; color: #333; padding: 6px 0; white-space: pre-wrap; }
        .footer { margin-top: 24px; font-size: 8pt; color: #888; text-align: center; }
    </style></head><body>

    <table class="header"><tr>
        <td style="width: 62%">
            ' . $logoBlock . '
            <h1>' . esc($inv['empresa_nombre']) . '</h1>
            <div>NIF: ' . esc($inv['empresa_nif']) . '</div>
            <div>' . esc($inv['empresa_direccion']) . '</div>
            <div>' . esc(trim(($inv['empresa_codpostal'] ?? '') . ' ' . ($inv['empresa_ciudad'] ?? '') . ', ' . ($inv['empresa_provincia'] ?? ''), ', ')) . '</div>
            ' . ($inv['empresa_email'] ? '<div>' . esc($inv['empresa_email']) . '</div>' : '') . '
        </td>
        <td class="right" style="width: 38%">' . $qrBlock . '</td>
    </tr></table>

    <table class="meta"><tr>
        <td style="width: 62%">
            <div><strong>Cliente:</strong> ' . esc($inv['nombrecliente']) . '</div>
            <div><strong>Dirección:</strong> ' . esc(trim(
                ($inv['direccion'] ?? '') .
                (trim(($inv['codpostal'] ?? '') . ' ' . ($inv['ciudad'] ?? '')) ? ', ' . trim(($inv['codpostal'] ?? '') . ' ' . ($inv['ciudad'] ?? '')) : '') .
                (($inv['provincia'] ?? '') ? ', ' . $inv['provincia'] : '') .
                ', ' . countryName($inv['codpais']),
                ', '
            )) . '</div>
            <div><strong>' . esc(recipientIdLabel($inv['codpais'], $inv['recipient_idtype'])) . ':</strong> ' . esc($inv['cifnif']) . '</div>
        </td>
        <td class="right" style="width: 38%">
            <div><strong>Fecha:</strong> ' . esc($inv['fecha']) . '</div>
            <div><strong>Serie:</strong> ' . esc($inv['serie_descripcion'] ?: $inv['codserie']) . '</div>
            <div><strong>Número:</strong> ' . esc((string)$inv['numero']) . '</div>
        </td>
    </tr></table>

    <h2 style="margin: 8px 0 0; font-size: 13pt; color: #1f3a5f;">Factura: ' . esc($inv['codigo']) . '</h2>

    <table class="lines">
        <thead>' . $linesHeader . '</thead>
        <tbody>' . $linesHtml . '</tbody>
    </table>

    ' . ($taxRows ? '<table class="tax" style="margin-top:14px"><thead><tr>
            <th>Impuesto</th>
            <th class="r">Base Imponible</th>
            <th class="r">Porcentaje</th>
            <th class="r">Importe</th>
        </tr></thead><tbody>' . $taxRows . '</tbody></table>' : '') . '

    ' . $exemptionsHtml . '

    <table class="totals-row" style="margin-top:14px"><thead><tr>
        <th>Divisa</th>
        <th class="r">Neto</th>
        ' . ($hasRet ? '<th class="r">Retención</th>' : '') . '
        <th class="r">Total</th>
    </tr></thead><tbody><tr>
        <td>' . esc($currencyLbl) . '</td>
        <td class="r">' . fmt((float)$inv['neto']) . '</td>
        ' . ($hasRet ? '<td class="r">-' . fmt((float)$inv['totalirpf']) . '</td>' : '') . '
        <td class="r">' . fmt((float)$inv['total']) . '</td>
    </tr></tbody></table>

    ' . $notesHtml . '

    ' . $bankHtml . '

    <div class="footer">' .
        ($vf
            ? 'Factura registrada en VERI*FACTU. Hash: ' . esc(substr($vf['hash'], 0, 16)) . '...'
            : 'Aún no enviada a VERI*FACTU.'
        ) .
    '</div>

    </body></html>';
}
