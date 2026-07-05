<?php
/**
 * Outgoing sales invoice processor — agent pipeline (no API key required).
 * Reads pre-extracted JSON and writes into FacturaScripts' ventas tables.
 *
 * Usage (via docker compose exec):
 *   docker compose exec app php /incoming/process-sale.php /incoming/pdfs/invoice.pdf \
 *     --json-file=/incoming/.agent-extract.json --empresa=B12345678 [--dry-run]
 *
 * JSON format for --json-file:
 * {
 *   "issuer":  { "nif": "B12345678", "name": "MI EMPRESA SL" },
 *   "client":  { "name": "ACME CORP", "nif": null,
 *                "address": "123 Main St", "city": "New York, NY 10001", "country_code": "US" },
 *   "invoice": { "number": "2026-A001", "date": "2026-01-29",
 *                "currency_orig": "USD", "exchange_rate": 1.1644,
 *                "total_orig": 3450.00, "due_date": null },
 *   "lines":   [{ "description": "Professional services Jan 2026", "quantity": 7.5,
 *                  "unit_price": 128.82, "iva_rate": 0.0, "irpf_rate": 0.0 }],
 *   "confidence": "high"
 * }
 *
 * unit_price is in EUR (already converted). total_orig is in the original currency.
 * The ECB rate and original USD total are stored in observaciones for traceability.
 */

const DEFAULT_SERIE  = 'A';
const DEFAULT_PAGO   = 'TRANS';
const MAX_FILE_BYTES = 32 * 1024 * 1024;

// ── CLI args ──────────────────────────────────────────────────────────────────
$dryRun     = false;
$empresaNif = null;
$jsonFile   = null;
$positional = [];

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--dry-run')                      { $dryRun = true; }
    elseif (str_starts_with($arg, '--empresa='))   { $empresaNif = strtoupper(trim(substr($arg, 10))); }
    elseif (str_starts_with($arg, '--json-file=')) { $jsonFile = substr($arg, 12); }
    elseif (!str_starts_with($arg, '-'))           { $positional[] = $arg; }
}

$singleFile = count($positional) === 1 ? $positional[0] : null;

if (!$singleFile) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php process-sale.php <file.pdf> --json-file=/incoming/.agent-extract.json --empresa=NIF [--dry-run]\n");
    exit(1);
}
if (!$jsonFile) {
    fwrite(STDERR, "ERROR: --json-file= is required.\n");
    exit(1);
}
if (!$empresaNif) {
    fwrite(STDERR, "ERROR: --empresa=NIF is required.\n");
    exit(1);
}

// ── Config / DB ───────────────────────────────────────────────────────────────
$configCandidates = ['/secrets/companies.php', __DIR__ . '/companies.php'];
$config = null;
foreach ($configCandidates as $path) {
    if (file_exists($path)) { $config = require $path; break; }
}
if ($config === null) {
    fwrite(STDERR, "Cannot find companies.php. Checked: " . implode(', ', $configCandidates) . "\n");
    exit(1);
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['database']['host'],
            (int)($config['database']['port'] ?? 3306),
            $config['database']['name']),
        $config['database']['user'],
        $config['database']['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Audit table (idempotent)
$pdo->exec("CREATE TABLE IF NOT EXISTS outgoing_invoice_exports (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    pdf_path       VARCHAR(500) NOT NULL,
    pdf_hash       CHAR(64) NOT NULL,
    idfactura      INT DEFAULT NULL,
    empresa_nif    VARCHAR(30) NOT NULL,
    client_nif     VARCHAR(30) DEFAULT NULL,
    invoice_number VARCHAR(100) DEFAULT NULL,
    status         VARCHAR(20) NOT NULL DEFAULT 'pending',
    confidence     VARCHAR(10) DEFAULT NULL,
    error_message  TEXT DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hash (pdf_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Empresa check ─────────────────────────────────────────────────────────────
$empresa = lookupEmpresaByNif($pdo, $empresaNif);
if (!$empresa) {
    $rows = $pdo->query("SELECT cifnif, nombre FROM empresas ORDER BY idempresa")->fetchAll();
    fwrite(STDERR, "No empresa with NIF '$empresaNif'. Known:\n");
    foreach ($rows as $r) fwrite(STDERR, "  {$r['cifnif']}  {$r['nombre']}\n");
    exit(1);
}
echo "Empresa : {$empresa['nombre']} ({$empresa['cifnif']})\n";
if ($dryRun) echo "Mode    : DRY RUN — nothing will be written\n";
echo "\n";

$result = processFile($pdo, $singleFile, $empresa, $jsonFile, $dryRun);
exit($result === 'err' ? 1 : 0);


// ═════════════════════════════════════════════════════════════ FUNCTIONS

function lookupEmpresaByNif(PDO $db, string $nif): ?array
{
    $s = $db->prepare("SELECT idempresa, cifnif, nombre FROM empresas WHERE cifnif = ? LIMIT 1");
    $s->execute([normalizeNif($nif)]);
    return $s->fetch() ?: null;
}

function processFile(PDO $db, string $filePath, array $empresa, string $jsonFile, bool $dryRun): string
{
    $base = basename($filePath);
    printf('[%s] ', $base);

    if (!is_file($filePath) || !is_readable($filePath)) { echo "ERROR: not found or not readable\n"; return 'err'; }
    if (filesize($filePath) > MAX_FILE_BYTES)           { echo "ERROR: file exceeds 32 MB\n";        return 'err'; }

    $hash = hash_file('sha256', $filePath);

    $s = $db->prepare("SELECT status, idfactura FROM outgoing_invoice_exports WHERE pdf_hash = ?");
    $s->execute([$hash]);
    $existing = $s->fetch();
    if ($existing && $existing['status'] === 'imported') {
        printf("SKIP (already imported, idfactura=%s)\n", $existing['idfactura']);
        return 'dup';
    }

    echo 'agent-extracted... ';
    $jsonStr = @file_get_contents($jsonFile);
    if ($jsonStr === false) { printf("ERROR: cannot read %s\n", $jsonFile); return 'err'; }
    $parsed = json_decode($jsonStr, true);
    if ($parsed === null) { printf("ERROR: invalid JSON: %s\n", json_last_error_msg()); return 'err'; }

    $validErr = validateExtracted($parsed);
    if ($validErr !== null) {
        printf("ERROR: %s\n", $validErr);
        recordExport($db, $filePath, $hash, null, $empresa['cifnif'], null, null, 'error', null, $validErr);
        return 'err';
    }

    $client     = $parsed['client'];
    $inv        = $parsed['invoice'];
    $lines      = $parsed['lines'];
    $confidence = $parsed['confidence'] ?? 'medium';
    $ejercicio  = substr($inv['date'], 0, 4);

    // Compute EUR totals from lines (unit_price is already in EUR)
    $neto = $totalIva = $totalIrpf = 0.0;
    foreach ($lines as $line) {
        $lineNet    = round((float)($line['quantity'] ?? 1) * (float)($line['unit_price'] ?? 0), 6);
        $neto      += $lineNet;
        $totalIva  += round($lineNet * (float)($line['iva_rate']  ?? 0) / 100, 6);
        $totalIrpf += round($lineNet * (float)($line['irpf_rate'] ?? 0) / 100, 6);
    }
    $neto      = round($neto,      2);
    $totalIva  = round($totalIva,  2);
    $totalIrpf = round($totalIrpf, 2);
    $total     = round($neto + $totalIva - $totalIrpf, 2);

    $origCurrency = strtoupper(trim((string)($inv['currency_orig'] ?? '')));
    $exchangeRate = (float)($inv['exchange_rate'] ?? 0);
    $totalOrig    = (float)($inv['total_orig'] ?? 0);
    $numOrig      = trim((string)($inv['number'] ?? ''));

    if ($origCurrency && $origCurrency !== 'EUR' && $totalOrig > 0 && $exchangeRate > 0) {
        $obs = sprintf(
            "%s %.2f converted to EUR at ECB rate %.4f %s/EUR (%s) | Ref. cliente: %s",
            $origCurrency, $totalOrig, $exchangeRate, $origCurrency, $inv['date'], $numOrig
        );
    } else {
        $obs = "Ref. cliente: $numOrig";
    }

    if ($dryRun) {
        printf("OK [dry-run]\n");
        printf("  Empresa : %s (%s)\n",  $empresa['nombre'], $empresa['cifnif']);
        printf("  Client  : %s  NIF=%s\n", $client['name'], $client['nif'] ?? '—');
        printf("  Invoice : %s  date=%s\n", $numOrig, $inv['date']);
        printf("  Lines   : %d  neto=%.2f EUR  IVA=%.2f  IRPF=%.2f  total=%.2f EUR\n",
               count($lines), $neto, $totalIva, $totalIrpf, $total);
        if ($origCurrency && $origCurrency !== 'EUR' && $totalOrig > 0)
            printf("  Orig    : %s %.2f  @ %.4f USD/EUR\n", $origCurrency, $totalOrig, $exchangeRate);
        printf("  Confidence: %s\n", $confidence);
        return 'ok';
    }

    try {
        $db->beginTransaction();

        $codCliente       = upsertCliente($db, $client);
        [$numero, $codigo] = nextCodigo($db, (int)$empresa['idempresa'], DEFAULT_SERIE, $ejercicio);
        $idEstado         = getDefaultEstado($db);

        $idfactura = insertFacturaCli($db, [
            'codigo'        => $codigo,
            'numero'        => $numero,
            'codserie'      => DEFAULT_SERIE,
            'codejercicio'  => $ejercicio,
            'codcliente'    => $codCliente,
            'cifnif'        => normalizeNif((string)($client['nif'] ?? '')),
            'nombrecliente' => trim($client['name']),
            'numero2'       => $numOrig,
            'fecha'         => $inv['date'],
            'neto'          => $neto,
            'totaliva'      => $totalIva,
            'totalirpf'     => $totalIrpf,
            'total'         => $total,
            'totaleuros'    => $total,
            'coddivisa'     => 'EUR',
            'tasaconv'      => 1.0,
            'codpago'       => DEFAULT_PAGO,
            'idestado'      => $idEstado,
            'idempresa'     => (int)$empresa['idempresa'],
            'codpais'       => strtoupper((string)($client['country_code'] ?? 'US')),
            'ciudad'        => trim((string)($client['city'] ?? '')),
            'direccion'     => trim((string)($client['address'] ?? '')),
            'observaciones' => $obs,
        ]);

        insertLineas($db, $idfactura, $lines);
        recordExport($db, $filePath, $hash, $idfactura, $empresa['cifnif'],
                     $client['nif'] ?? null, $numOrig, 'imported', $confidence, null);

        $db->commit();
        printf("OK  idfactura=%-5d  %s  [%s]\n", $idfactura, $codigo, $confidence);
        return 'ok';

    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $errMsg = $e->getMessage();
        printf("ERROR: %s\n", $errMsg);
        recordExport($db, $filePath, $hash, null, $empresa['cifnif'],
                     $client['nif'] ?? null, $numOrig ?: null, 'error', $confidence, $errMsg);
        return 'err';
    }
}

function validateExtracted(?array $p): ?string
{
    if (!is_array($p))                                                  return 'No JSON object';
    if (empty($p['client']['name']))                                    return 'Missing client name';
    if (empty($p['invoice']['date']))                                   return 'Missing invoice date';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['invoice']['date']))  return 'Invalid date (expected YYYY-MM-DD)';
    if (empty($p['lines']))                                             return 'No line items';
    return null;
}

function normalizeNif(string $nif): string
{
    return strtoupper(preg_replace('/[\s.\-]/u', '', trim($nif)));
}

function upsertCliente(PDO $db, array $client): string
{
    $name   = trim($client['name']);
    $cifnif = normalizeNif((string)($client['nif'] ?? ''));

    if ($cifnif) {
        $s = $db->prepare("SELECT codcliente FROM clientes WHERE cifnif = ? LIMIT 1");
        $s->execute([$cifnif]);
        if ($row = $s->fetch()) return $row['codcliente'];
    }

    $s = $db->prepare("SELECT codcliente FROM clientes WHERE nombre = ? LIMIT 1");
    $s->execute([$name]);
    if ($row = $s->fetch()) return $row['codcliente'];

    // New client — slug from name
    $slug  = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $name), 0, 10));
    $code  = $slug;
    $n     = 1;
    $check = $db->prepare("SELECT 1 FROM clientes WHERE codcliente = ?");
    while (true) {
        $check->execute([$code]);
        if (!$check->fetch()) break;
        $code = substr($slug, 0, 8) . $n++;
    }

    $db->prepare(
        "INSERT INTO clientes (codcliente, cifnif, nombre, razonsocial, codpago, fechaalta)
         VALUES (?, ?, ?, ?, ?, CURDATE())"
    )->execute([$code, $cifnif ?: '', $name, $name, DEFAULT_PAGO]);

    return $code;
}

function nextCodigo(PDO $db, int $idEmpresa, string $serie, string $ejercicio): array
{
    // Prefer year-specific sequence; fall back to NULL (year-agnostic). Lock for atomicity.
    $s = $db->prepare(
        "SELECT idsecuencia, numero, patron, longnumero
         FROM secuencias_documentos
         WHERE idempresa = ? AND codserie = ? AND tipodoc = 'FacturaCliente'
           AND (codejercicio = ? OR codejercicio IS NULL)
         ORDER BY codejercicio DESC LIMIT 1 FOR UPDATE"
    );
    $s->execute([$idEmpresa, $serie, $ejercicio]);
    $seq = $s->fetch();

    if (!$seq) {
        throw new RuntimeException(
            "No FacturaCliente sequence for empresa=$idEmpresa serie=$serie. " .
            "Create it in FS › Contabilidad › Series de facturación."
        );
    }

    $numero  = (int)$seq['numero'];
    $patron  = $seq['patron'] ?: '{EJE}-{SERIE}{0NUM}';
    $padding = max(1, (int)$seq['longnumero']);

    $codigo = $patron;
    $codigo = str_replace('{EJE}',   $ejercicio,                                             $codigo);
    $codigo = str_replace('{SERIE}', $serie,                                                 $codigo);
    $codigo = str_replace('{0NUM}',  str_pad((string)$numero, $padding, '0', STR_PAD_LEFT),  $codigo);
    $codigo = str_replace('{NUM}',   (string)$numero,                                        $codigo);

    $db->prepare("UPDATE secuencias_documentos SET numero = numero + 1 WHERE idsecuencia = ?")
       ->execute([$seq['idsecuencia']]);

    return [(string)$numero, $codigo];
}

function getDefaultEstado(PDO $db): int
{
    $s = $db->query(
        "SELECT idestado FROM estados_documentos
         WHERE tipodoc = 'FacturaCliente' ORDER BY idestado LIMIT 1"
    );
    $row = $s->fetch();
    return $row ? (int)$row['idestado'] : 0;
}

function insertFacturaCli(PDO $db, array $d): int
{
    $db->prepare(
        "INSERT INTO facturascli
            (codigo, numero, codserie, codejercicio,
             codcliente, cifnif, nombrecliente, numero2,
             fecha, neto, totaliva, totalirpf, total, totaleuros,
             coddivisa, tasaconv, codpago, idestado, idempresa,
             codpais, ciudad, direccion, observaciones)
         VALUES
            (:codigo, :numero, :codserie, :codejercicio,
             :codcliente, :cifnif, :nombrecliente, :numero2,
             :fecha, :neto, :totaliva, :totalirpf, :total, :totaleuros,
             :coddivisa, :tasaconv, :codpago, :idestado, :idempresa,
             :codpais, :ciudad, :direccion, :observaciones)"
    )->execute([
        ':codigo'        => $d['codigo'],
        ':numero'        => $d['numero'],
        ':codserie'      => $d['codserie'],
        ':codejercicio'  => $d['codejercicio'],
        ':codcliente'    => $d['codcliente'],
        ':cifnif'        => $d['cifnif'],
        ':nombrecliente' => $d['nombrecliente'],
        ':numero2'       => $d['numero2'],
        ':fecha'         => $d['fecha'],
        ':neto'          => $d['neto'],
        ':totaliva'      => $d['totaliva'],
        ':totalirpf'     => $d['totalirpf'],
        ':total'         => $d['total'],
        ':totaleuros'    => $d['totaleuros'],
        ':coddivisa'     => $d['coddivisa'],
        ':tasaconv'      => $d['tasaconv'],
        ':codpago'       => $d['codpago'],
        ':idestado'      => $d['idestado'],
        ':idempresa'     => $d['idempresa'],
        ':codpais'       => $d['codpais'],
        ':ciudad'        => $d['ciudad'],
        ':direccion'     => $d['direccion'],
        ':observaciones' => $d['observaciones'],
    ]);
    return (int)$db->lastInsertId();
}

function insertLineas(PDO $db, int $idfactura, array $lines): void
{
    $stmt = $db->prepare(
        "INSERT INTO lineasfacturascli
            (idfactura, descripcion, cantidad, pvpunitario, pvpsindto, dtopor, pvptotal, iva, irpf, recargo, excepcioniva)
         VALUES
            (:idfactura, :desc, :qty, :unit, :sinDto, 0, :total, :iva, :irpf, 0, :excepcioniva)"
    );
    foreach ($lines as $line) {
        $qty    = (float)($line['quantity']   ?? 1);
        $unit   = (float)($line['unit_price'] ?? 0);
        $sinDto = round($qty * $unit, 6);
        $stmt->execute([
            ':idfactura'   => $idfactura,
            ':desc'        => trim((string)($line['description'] ?? '')),
            ':qty'         => $qty,
            ':unit'        => $unit,
            ':sinDto'      => $sinDto,
            ':total'       => $sinDto,
            ':iva'         => (float)($line['iva_rate']  ?? 0),
            ':irpf'        => (float)($line['irpf_rate'] ?? 0),
            ':excepcioniva'=> $line['excepcioniva'] ?? null,
        ]);
    }
}

function recordExport(
    PDO $db, string $pdfPath, string $pdfHash,
    ?int $idfactura, string $empresaNif, ?string $clientNif,
    ?string $invoiceNumber, string $status,
    ?string $confidence, ?string $errorMsg
): void {
    try {
        $db->prepare(
            "INSERT INTO outgoing_invoice_exports
                (pdf_path, pdf_hash, idfactura, empresa_nif, client_nif, invoice_number,
                 status, confidence, error_message)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                status        = VALUES(status),
                idfactura     = VALUES(idfactura),
                confidence    = VALUES(confidence),
                error_message = VALUES(error_message)"
        )->execute([
            $pdfPath, $pdfHash, $idfactura, $empresaNif, $clientNif,
            $invoiceNumber, $status, $confidence, $errorMsg,
        ]);
    } catch (Throwable) {}
}
