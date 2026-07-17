<?php
/**
 * Verifactu submitter — processes pending FacturaScripts invoices across
 * all empresas configured in companies.php. Supports both Spanish and
 * foreign recipients.
 *
 * Usage:
 *   cd ~/verifactu-poc
 *   php submit-pending.php                       # all configured empresas
 *   php submit-pending.php --empresa=B12345678   # one empresa only
 *   php submit-pending.php --dry-run             # show what would happen
 *   php submit-pending.php --limit=10            # cap how many to process
 */

require __DIR__ . '/vendor/autoload.php';

use josemmo\Verifactu\Models\ComputerSystem;
use josemmo\Verifactu\Models\Records\BreakdownDetails;
use josemmo\Verifactu\Models\Records\FiscalIdentifier;
use josemmo\Verifactu\Models\Records\ForeignFiscalIdentifier;
use josemmo\Verifactu\Models\Records\ForeignIdType;
use josemmo\Verifactu\Models\Records\InvoiceIdentifier;
use josemmo\Verifactu\Models\Records\InvoiceType;
use josemmo\Verifactu\Models\Records\OperationType;
use josemmo\Verifactu\Models\Records\RegimeType;
use josemmo\Verifactu\Models\Records\RegistrationRecord;
use josemmo\Verifactu\Models\Records\TaxType;
use josemmo\Verifactu\Models\Records\CorrectiveType;
use josemmo\Verifactu\Services\AeatClient;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\Common\EccLevel;

// ===== CLI args =====
$opts       = getopt('', ['dry-run', 'limit:', 'env:', 'empresa:']);
$dryRun     = isset($opts['dry-run']);
$limit      = isset($opts['limit']) ? max(1, (int)$opts['limit']) : 50;
$envArg     = isset($opts['env'])     ? strtolower(trim($opts['env']))     : null;
$empresaArg = isset($opts['empresa']) ? strtoupper(preg_replace('/[\s.\-]/u', '', trim($opts['empresa']))) : null;

// ===== Config =====
// Look in /secrets first (Docker layout), then next to the script (dev).
$configCandidates = [
    '/secrets/companies.php',
    __DIR__ . '/companies.php',
];
$configPath = null;
foreach ($configCandidates as $p) {
    if (file_exists($p)) { $configPath = $p; break; }
}
if ($configPath === null) {
    fwrite(STDERR, "Missing companies.php (checked: "
        . implode(', ', $configCandidates) . ")\n");
    exit(1);
}
$config = require $configPath;

// Resolve environment: CLI overrides config default.
$env = $envArg ?? strtolower($config['sif']['environment'] ?? 'preproduccion');
if (!in_array($env, ['preproduccion','produccion'], true)) {
    fwrite(STDERR, "Invalid environment '$env'. Use --env=preproduccion or --env=produccion\n");
    exit(1);
}
$isProduction = ($env === 'produccion');

echo "Environment: $env" . ($isProduction ? " (LIVE, real fiscal effect)" : " (sandbox)") . "\n";
if ($empresaArg) {
    echo "Empresa    : $empresaArg (filtered)\n";
}

$qrDir = __DIR__ . '/qr';
if (!is_dir($qrDir)) mkdir($qrDir, 0700, true);

// ===== DB =====
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
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

// ===== Pending invoices (now also pulling country + tipoidfiscal) =====
$empresaFilter = $empresaArg ? "AND e.cifnif = :empresa_nif" : "";
$sql = "
SELECT f.idfactura, f.codigo, f.codserie, f.fecha, f.total, f.totaliva,
       f.cifnif AS recipient_nif, f.nombrecliente, f.codigorect, f.idfacturarect,
       f.codpais AS recipient_country_raw,
       c.tipoidfiscal AS recipient_idtype,
       e.cifnif AS empresa_nif, e.nombre AS empresa_nombre,
       forig.codigo AS original_codigo, forig.fecha AS original_fecha
FROM facturascli f
INNER JOIN empresas e ON e.idempresa = f.idempresa
LEFT JOIN clientes c ON c.codcliente = f.codcliente
LEFT JOIN facturascli forig ON forig.idfactura = f.idfacturarect
LEFT JOIN verifactu_submissions vs
       ON vs.idfactura = f.idfactura
      AND vs.status IN ('submitted', 'non_applicable')
      AND vs.environment = :env_pending
WHERE vs.id IS NULL
$empresaFilter
ORDER BY e.cifnif, f.fecha, f.idfactura
LIMIT $limit
";
$pendingStmt = $db->prepare($sql);
$params = [':env_pending' => $env];
if ($empresaArg) $params[':empresa_nif'] = $empresaArg;
$pendingStmt->execute($params);
$pending = $pendingStmt->fetchAll();

echo "Pending invoices: " . count($pending) . "\n";
if (!$pending) exit(0);

// Group by empresa NIF for sequential per-empresa chain submission
$byEmpresa = [];
foreach ($pending as $row) $byEmpresa[$row['empresa_nif']][] = $row;

foreach ($byEmpresa as $nif => $invoices) {
    if (!isset($config['empresas'][$nif])) {
        echo "  [skip] no cert config for empresa $nif\n";
        continue;
    }
    $cfg = $config['empresas'][$nif];

    $system = new ComputerSystem();
    $system->vendorName = $cfg['name'];
    $system->vendorNif  = $nif;
    $system->name       = $config['sif']['name'];
    $system->id         = $config['sif']['id'];
    $system->version    = $config['sif']['version'];
    $system->installationNumber        = $config['sif']['installation_number'];
    $system->onlySupportsVerifactu     = true;
    $system->supportsMultipleTaxpayers = true;
    $system->hasMultipleTaxpayers      = true;
    $system->validate();

    $taxpayer = new FiscalIdentifier();
    $taxpayer->name = $cfg['name'];
    $taxpayer->nif  = $nif;
    $client   = new AeatClient($system, $taxpayer, $cfg['cert_path'], $cfg['cert_pass']);
    $client->setProduction($isProduction);

    // Chain tail for this empresa
    $tailStmt = $db->prepare("
        SELECT invoice_code, issue_date, hash
        FROM verifactu_submissions
        WHERE empresa_nif = :nif AND environment = :env AND status = 'submitted'
        ORDER BY submitted_at DESC, id DESC LIMIT 1
    ");
    $tailStmt->execute([':nif' => $nif, ':env' => $env]);
    $tail = $tailStmt->fetch();

    foreach ($invoices as $inv) {
        // Skip invoices whose series isn't allowed in this environment.
        $serie = $inv['codserie'];
        $seriesCfg = $config['series'] ?? [];
        if (isset($seriesCfg[$serie]) && !in_array($env, $seriesCfg[$serie], true)) {
            echo "\n[$nif] {$inv['codigo']}: [skip] series '$serie' not allowed in $env\n";
            continue;
        }

        echo "\n[$nif] {$inv['codigo']} ({$inv['fecha']}, total {$inv['total']})\n";

        $lstmt = $db->prepare("
            SELECT pvptotal, iva, excepcioniva
            FROM lineasfacturascli WHERE idfactura = :id
        ");
        $lstmt->execute([':id' => (int)$inv['idfactura']]);
        $lines = $lstmt->fetchAll();
        if (!$lines) {
            echo "  [skip] invoice has no lines\n";
            continue;
        }

        // Aggregate base + tax per (IVA rate, exemption code) combination.
        // Each combination becomes one breakdown row to AEAT carrying one OperationType.
        $buckets = [];
        foreach ($lines as $ln) {
            $rate = (float)$ln['iva'];
            $exc  = strtoupper(trim((string)($ln['excepcioniva'] ?? '')));
            $key  = sprintf('%.2f|%s', $rate, $exc);
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'rate'      => $rate,
                    'excepcion' => $exc,
                    'base'      => 0.0,
                    'tax'       => 0.0,
                ];
            }
            $base = (float)$ln['pvptotal'];
            $buckets[$key]['base'] += $base;
            $buckets[$key]['tax']  += $base * $rate / 100.0;
        }

        $record = new RegistrationRecord();
        $record->invoiceId = new InvoiceIdentifier();
        $record->invoiceId->issuerId      = $nif;
        $record->invoiceId->invoiceNumber = $inv['codigo'];
        $record->invoiceId->issueDate     = new DateTimeImmutable($inv['fecha']);
        $record->issuerName  = $cfg['name'];
        $isRectificativa     = !empty($inv['codigorect']);
        $record->invoiceType = $isRectificativa ? InvoiceType::R1 : InvoiceType::Factura;
        $record->description = "Factura {$inv['codigo']}";

        // Rectificativa mechanics.
        // We use "Differences" (por diferencias): the rectificativa carries the
        // delta amounts (positive or negative). The original invoice remains
        // valid; this one adjusts it.
        if ($isRectificativa) {
            $record->correctiveType = CorrectiveType::Differences;

            // Reference the corrected invoice — required by RD 1619/2012 art. 15.2.
            // AEAT preproducción sometimes accepts without it, but producción may not.
            if (!empty($inv['original_codigo']) && !empty($inv['original_fecha'])) {
                $origId = new InvoiceIdentifier();
                $origId->issuerId      = $nif;   // same issuer as us
                $origId->invoiceNumber = $inv['original_codigo'];
                $origId->issueDate     = new DateTimeImmutable($inv['original_fecha']);
                $record->correctedInvoices[] = $origId;
            } else {
                fwrite(STDERR, "  warning: rectificativa without idfacturarect resolution — FacturaRectificada block will be empty\n");
            }
        }

        $i = 0;
        foreach ($buckets as $bucket) {
            $op = operationTypeForExempt($bucket['excepcion']);

            $bd = new BreakdownDetails();
            $bd->taxType       = TaxType::IVA;
            $bd->regimeType    = regimeTypeForExempt($bucket['excepcion']);
            $bd->operationType = $op;
            $bd->baseAmount    = number_format($bucket['base'], 2, '.', '');

            // Only "subject" operations (S1, S2) carry tax rate + amount.
            // Exempt and non-subject must leave them null per AEAT spec.
            if ($op->isSubject()) {
                $bd->taxRate   = number_format($bucket['rate'], 2, '.', '');
                $bd->taxAmount = number_format($bucket['tax'], 2, '.', '');
            }

            $record->breakdown[$i++] = $bd;
        }
        $record->totalTaxAmount = number_format((float)$inv['totaliva'], 2, '.', '');
        $record->totalAmount    = number_format((float)$inv['total'], 2, '.', '');

        // Recipient — domestic vs foreign. Both models require property
        // assignment (no constructor args in the current library version).
        $recipientName = $inv['nombrecliente'] ?: 'CLIENTE';
        $iso2 = countryToIso2($inv['recipient_country_raw']);
        if ($iso2 === '' || $iso2 === 'ES') {
            $fi = new FiscalIdentifier();
            $fi->name = $recipientName;
            $fi->nif  = $inv['recipient_nif'];
            $record->recipients[] = $fi;
        } else {
            $fi = new ForeignFiscalIdentifier();
            $fi->name    = $recipientName;
            $fi->country = $iso2;
            $fi->type    = mapForeignIdType($inv['recipient_idtype']);
            $fi->value   = $inv['recipient_nif'];
            $record->recipients[] = $fi;
        }

        if ($tail) {
            $pid = new InvoiceIdentifier();
            $pid->issuerId      = $nif;
            $pid->invoiceNumber = $tail['invoice_code'];
            $pid->issueDate     = new DateTimeImmutable($tail['issue_date']);
            $record->previousInvoiceId = $pid;
            $record->previousHash      = $tail['hash'];
        } else {
            $record->previousInvoiceId = null;
            $record->previousHash      = null;
        }
        $record->hashedAt = new DateTimeImmutable();
        $record->hash     = $record->calculateHash();

        try {
            $record->validate();
        } catch (\Throwable $e) {
            echo "  VALIDATION: " . $e->getMessage() . "\n";
            persist($db, $inv, $nif, $env, $record, null, 'rejected', $e->getMessage(), null, null);
            continue;
        }

        if ($dryRun) {
            echo "  [dry-run] hash={$record->hash} (recipient: $iso2 {$inv['recipient_nif']})\n";
            continue;
        }

        try {
            $responseXml = $client->send([$record]);
        } catch (\Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n";
            persist($db, $inv, $nif, $env, $record, null, 'rejected', $e->getMessage(), null, null);
            continue;
        }

        // Parse AEAT SOAP response — namespace-agnostic XPath so we don't care
        // about the specific URIs the library or AEAT uses.
        $sxml = simplexml_load_string($responseXml->asXML());
        $statusNodes = $sxml->xpath('//*[local-name()="EstadoRegistro"]');
        $status = count($statusNodes) > 0 ? (string)$statusNodes[0] : 'Unknown';

        if ($status === 'Correcto' || $status === 'AceptadoConErrores') {
            $csvNodes = $sxml->xpath('//*[local-name()="CSV"]');
            $csvValue = count($csvNodes) > 0 ? (string)$csvNodes[0] : '';
            $qrBase = $isProduction
                ? 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR'
                : 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR';
            $qrUrl = $qrBase . '?' . http_build_query([
                'nif'      => $nif,
                'numserie' => $inv['codigo'],
                'fecha'    => $record->invoiceId->issueDate->format('d-m-Y'),
                'importe'  => $record->totalAmount,
            ]);
            $qrPng = (new QRCode(new QROptions([
                'outputType'   => QROutputInterface::GDIMAGE_PNG,
                'outputBase64' => false,
                'scale'        => 6,
                'eccLevel'     => EccLevel::M,
            ])))->render($qrUrl);
            $safe   = preg_replace('/[^A-Za-z0-9_-]/', '_', $inv['codigo']);
            $qrPath = $qrDir . "/{$nif}_{$safe}.png";
            file_put_contents($qrPath, $qrPng);

            persist($db, $inv, $nif, $env, $record, $csvValue, 'submitted', null, $qrUrl, $qrPath);

            echo "  ACCEPTED. CSV: {$csvValue}\n";

            $tail = [
                'invoice_code' => $inv['codigo'],
                'issue_date'   => $inv['fecha'],
                'hash'         => $record->hash,
            ];
        } else {
            $errNodes = $sxml->xpath('//*[local-name()="DescripcionErrorRegistro"]');
            $errs = [];
            foreach ($errNodes as $n) { $errs[] = (string)$n; }
            $errMsg = $errs ? implode(' | ', $errs) : "Response status: $status";
            echo "  REJECTED: $errMsg\n";
            persist($db, $inv, $nif, $env, $record, null, 'rejected', $errMsg, null, null);
        }
    }
}

echo "\nDone.\n";

// ===== Helpers =====

/**
 * Map FS's excepcioniva code to the AEAT ClaveRegimen.
 *
 * AEAT enforces that exports/import-related exemptions (E2 art. 21, E3 art. 22,
 * E4 arts. 23-24) require ClaveRegimen 02 (Exportación). Everything else is C01.
 */
function regimeTypeForExempt(string $excepcion): RegimeType {
    return match (strtoupper(trim($excepcion))) {
        'ES_21', 'ES_22',
        'ES_23_24', 'ES_23-24' => RegimeType::C02,
        default                => RegimeType::C01,
    };
}

/**
 * Map FS's excepcioniva code to the AEAT OperationType.
 *
 *   ''           -> S1 Subject (sujeto y no exento)
 *   ES_84        -> S2 PassiveSubject (sujeto, inversión del sujeto pasivo)
 *   ES_07/14/    -> N1 NonSubject (no sujeto art. 7, 14, etc.)
 *     NS_OTROS
 *   ES_68_70     -> N2 NonSubjectByLocation
 *   ES_20        -> E1 ExemptByArticle20
 *   ES_21        -> E2 ExemptByArticle21
 *   ES_22        -> E3 ExemptByArticle22
 *   ES_23_24     -> E4 ExemptByArticles23And24
 *   ES_25        -> E5 ExemptByArticle25
 *   ES_OTROS     -> E6 ExemptByOther
 *
 * Unknown codes default to E6 (otra exención) — safest fallback that AEAT accepts.
 */
function operationTypeForExempt(string $excepcion): OperationType {
    return match (strtoupper(trim($excepcion))) {
        ''                          => OperationType::Subject,
        'ES_84'                     => OperationType::PassiveSubject,
        'ES_07', 'ES_14',
        'ES_NS_OTROS', 'ES_NSO'     => OperationType::NonSubject,
        'ES_68_70', 'ES_68-70'      => OperationType::NonSubjectByLocation,
        'ES_20'                     => OperationType::ExemptByArticle20,
        'ES_21'                     => OperationType::ExemptByArticle21,
        'ES_22'                     => OperationType::ExemptByArticle22,
        'ES_23_24', 'ES_23-24'      => OperationType::ExemptByArticles23And24,
        'ES_25'                     => OperationType::ExemptByArticle25,
        'ES_OTROS', 'ES_OTHER'      => OperationType::ExemptByOther,
        default                     => OperationType::ExemptByOther,
    };
}

/**
 * Convert FS's country code to ISO 3166-1 alpha-2 (what AEAT expects).
 * FS stores 3-letter codes by default; we map common ones and fall through.
 */
function countryToIso2(?string $code): string {
    if ($code === null || $code === '') return '';
    $code = strtoupper(trim($code));
    if (strlen($code) === 2) return $code;
    static $map = [
        'ESP' => 'ES', 'AND' => 'AD', 'PRT' => 'PT', 'FRA' => 'FR', 'DEU' => 'DE',
        'ITA' => 'IT', 'GBR' => 'GB', 'IRL' => 'IE', 'NLD' => 'NL', 'BEL' => 'BE',
        'LUX' => 'LU', 'CHE' => 'CH', 'AUT' => 'AT', 'POL' => 'PL', 'CZE' => 'CZ',
        'HUN' => 'HU', 'GRC' => 'EL', 'SVK' => 'SK', 'SVN' => 'SI', 'HRV' => 'HR',
        'ROU' => 'RO', 'BGR' => 'BG', 'SWE' => 'SE', 'NOR' => 'NO', 'DNK' => 'DK',
        'FIN' => 'FI', 'EST' => 'EE', 'LVA' => 'LV', 'LTU' => 'LT', 'CYP' => 'CY',
        'MLT' => 'MT', 'ISL' => 'IS', 'TUR' => 'TR', 'RUS' => 'RU', 'UKR' => 'UA',
        'USA' => 'US', 'CAN' => 'CA', 'MEX' => 'MX', 'BRA' => 'BR', 'ARG' => 'AR',
        'CHL' => 'CL', 'COL' => 'CO', 'PER' => 'PE', 'URY' => 'UY', 'VEN' => 'VE',
        'CHN' => 'CN', 'JPN' => 'JP', 'KOR' => 'KR', 'IND' => 'IN', 'AUS' => 'AU',
        'NZL' => 'NZ', 'ZAF' => 'ZA', 'EGY' => 'EG', 'MAR' => 'MA', 'ISR' => 'IL',
        'ARE' => 'AE', 'SAU' => 'SA', 'TUN' => 'TN', 'DZA' => 'DZ', 'NGA' => 'NG',
    ];
    return $map[$code] ?? $code;
}

/**
 * Map FacturaScripts' tipoidfiscal field to a ForeignIdType enum case.
 */
function mapForeignIdType(?string $fsType): ForeignIdType {
    $key = strtoupper(trim((string)$fsType));
    return match (true) {
        str_contains($key, 'NIF-IVA'),
        str_contains($key, 'NIFIVA'),
        str_contains($key, 'VAT')                       => ForeignIdType::VAT,
        str_contains($key, 'PASAPORTE'),
        str_contains($key, 'PASSPORT')                  => ForeignIdType::Passport,
        str_contains($key, 'OFICIAL'),
        str_contains($key, 'NATIONAL')                  => ForeignIdType::NationalId,
        str_contains($key, 'RESIDENCIA'),
        str_contains($key, 'RESIDENCE')                 => ForeignIdType::Residence,
        str_contains($key, 'OTRO'),
        str_contains($key, 'OTHER')                     => ForeignIdType::Other,
        default                                         => ForeignIdType::NationalId,
    };
}

function persist(PDO $db, array $inv, string $nif, string $env, RegistrationRecord $record,
                 ?string $csv, string $status, ?string $error,
                 ?string $qrUrl, ?string $qrPath): void
{
    $stmt = $db->prepare("
        INSERT INTO verifactu_submissions
            (empresa_nif, environment, idfactura, invoice_code, issue_date, total_amount,
             status, csv, hash, hashed_at, qr_url, qr_png_path,
             prev_invoice_code, prev_invoice_date, prev_hash, error_message, submitted_at)
        VALUES (:nif, :env, :idfactura, :code, :date, :total,
                :status, :csv, :hash, :hashed_at, :qr_url, :qr_png_path,
                :prev_code, :prev_date, :prev_hash, :error, NOW())
        ON DUPLICATE KEY UPDATE
            status        = VALUES(status),
            csv           = VALUES(csv),
            hash          = VALUES(hash),
            hashed_at     = VALUES(hashed_at),
            qr_url        = VALUES(qr_url),
            qr_png_path   = VALUES(qr_png_path),
            prev_invoice_code = VALUES(prev_invoice_code),
            prev_invoice_date = VALUES(prev_invoice_date),
            prev_hash     = VALUES(prev_hash),
            error_message = VALUES(error_message),
            submitted_at  = NOW()
    ");

    $prevId   = (new ReflectionProperty($record, 'previousInvoiceId'))->isInitialized($record)
                ? $record->previousInvoiceId : null;
    $prevHash = (new ReflectionProperty($record, 'previousHash'))->isInitialized($record)
                ? $record->previousHash : null;
    $prevCode = $prevId?->invoiceNumber;
    $prevDate = $prevId?->issueDate->format('Y-m-d');

    $stmt->execute([
        ':nif'          => $nif,
        ':env'          => $env,
        ':idfactura'    => (int)$inv['idfactura'],
        ':code'         => $inv['codigo'],
        ':date'         => $inv['fecha'],
        ':total'        => (float)$inv['total'],
        ':status'       => $status,
        ':csv'          => $csv,
        ':hash'         => $record->hash,
        ':hashed_at'    => $record->hashedAt->format('Y-m-d H:i:s'),
        ':qr_url'       => $qrUrl,
        ':qr_png_path'  => $qrPath,
        ':prev_code'    => $prevCode,
        ':prev_date'    => $prevDate,
        ':prev_hash'    => $prevHash,
        ':error'        => $error,
    ]);
}
