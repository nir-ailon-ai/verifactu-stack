<?php
/**
 * Incoming invoice processor — uses Claude to extract data from a supplier
 * invoice PDF or scan and write it into FacturaScripts' supplier tables.
 *
 * Usage (via docker compose exec):
 *   docker compose exec app php /incoming/process-invoice.php /incoming/pdfs/factura.pdf
 *   docker compose exec app php /incoming/process-invoice.php /incoming/pdfs/factura.pdf --empresa=B12345678
 *   docker compose exec app php /incoming/process-invoice.php --dir=/incoming/pdfs [--empresa=NIF] [--dry-run]
 *
 * Requires ANTHROPIC_API_KEY in environment (add to .env, then docker compose restart app).
 * Supported formats: PDF, JPEG, PNG, WEBP.
 */

const CLAUDE_MODEL   = 'claude-sonnet-4-6';
const CLAUDE_API_URL = 'https://api.anthropic.com/v1/messages';
const CLAUDE_VERSION = '2023-06-01';
const MAX_FILE_BYTES = 32 * 1024 * 1024;
const DEFAULT_SERIE  = 'A';
const DEFAULT_PAGO   = 'TRANS';
const DEFAULT_DIV    = 'EUR';
const DEFAULT_PAIS   = 'ESP';
const SUPPORTED_EXTS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

// ─────────────────────────────────────── CLI args
$dryRun     = false;
$empresaNif = null;
$dirArg     = null;
$jsonFile   = null;   // path to a pre-extracted JSON file (agent pipeline — skips Claude API call)
$positional = [];

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--dry-run')                      { $dryRun = true; }
    elseif (str_starts_with($arg, '--empresa='))   { $empresaNif = strtoupper(trim(substr($arg, 10))); }
    elseif (str_starts_with($arg, '--dir='))       { $dirArg = rtrim(substr($arg, 6), '/'); }
    elseif (str_starts_with($arg, '--json-file=')) { $jsonFile = substr($arg, 12); }
    elseif (!str_starts_with($arg, '-'))           { $positional[] = $arg; }
}

$singleFile = count($positional) === 1 ? $positional[0] : null;

if (!$singleFile && !$dirArg) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php process-invoice.php <file.pdf|jpg|png> [--empresa=NIF] [--dry-run]\n");
    fwrite(STDERR, "  php process-invoice.php --dir=/incoming/pdfs [--empresa=NIF] [--dry-run]\n");
    fwrite(STDERR, "  php process-invoice.php <file> --json-file=/incoming/.agent-extract.json  # agent pipeline\n");
    exit(1);
}

// ─────────────────────────────────────── API key
// Not required when --json-file= is supplied (agent pipeline).
$apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
if (!$apiKey && !$jsonFile) {
    fwrite(STDERR, "ANTHROPIC_API_KEY is not set.\n");
    fwrite(STDERR, "Add it to .env and run: docker compose restart app\n");
    fwrite(STDERR, "Or use --json-file= to import pre-extracted JSON without an API key.\n");
    exit(1);
}

// ─────────────────────────────────────── Config
$configCandidates = ['/secrets/companies.php', __DIR__ . '/companies.php'];
$config = null;
foreach ($configCandidates as $path) {
    if (file_exists($path)) { $config = require $path; break; }
}
if ($config === null) {
    fwrite(STDERR, "Cannot find companies.php. Checked: " . implode(', ', $configCandidates) . "\n");
    exit(1);
}

// ─────────────────────────────────────── DB
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['database']['host'],
            (int)($config['database']['port'] ?? 3306),
            $config['database']['name']),
        $config['database']['user'],
        $config['database']['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// ─────────────────────────────────────── Empresa pre-check (if explicit)
// When --empresa= is given we validate it exists before touching any files.
// When omitted, resolution happens per-file after OCR (recipient NIF auto-match).
if ($empresaNif !== null) {
    $preCheck = lookupEmpresaByNif($pdo, $empresaNif);
    if (!$preCheck) {
        $rows = $pdo->query("SELECT cifnif, nombre FROM empresas ORDER BY idempresa")->fetchAll();
        fwrite(STDERR, "No empresa with NIF '$empresaNif' found. Known empresas:\n");
        foreach ($rows as $r) fwrite(STDERR, "  {$r['cifnif']}  {$r['nombre']}\n");
        exit(1);
    }
    echo "Empresa : {$preCheck['nombre']} ({$preCheck['cifnif']}) [explicit]\n";
} else {
    echo "Empresa : will be inferred from invoice recipient NIF\n";
}
if ($dryRun) echo "Mode    : DRY RUN — nothing will be written\n";
echo "\n";

// ─────────────────────────────────────── File list
if ($dirArg) {
    $files = [];
    foreach (SUPPORTED_EXTS as $ext) {
        $files = array_merge(
            $files,
            glob($dirArg . '/*.' . $ext)             ?: [],
            glob($dirArg . '/*.' . strtoupper($ext)) ?: []
        );
    }
    $files = array_values(array_unique($files));
    sort($files);
    if (!$files) { echo "No supported files found in $dirArg\n"; exit(0); }
} else {
    $files = [$singleFile];
}

printf("Processing %d file(s)...\n\n", count($files));

// ─────────────────────────────────────── Main loop
$counts = ['ok' => 0, 'dup' => 0, 'err' => 0];
foreach ($files as $filePath) {
    $counts[processFile($pdo, $filePath, $empresaNif, $apiKey, $dryRun, $jsonFile)]++;
}

echo "\n" . str_repeat('─', 44) . "\n";
printf("Imported : %d\n",   $counts['ok']);
printf("Skipped  : %d  (already imported)\n", $counts['dup']);
printf("Errors   : %d\n",   $counts['err']);
exit($counts['err'] > 0 ? 1 : 0);


// ═══════════════════════════════════════ FUNCTIONS

function lookupEmpresaByNif(PDO $db, string $nif): ?array
{
    $s = $db->prepare("SELECT idempresa, cifnif, nombre FROM empresas WHERE cifnif = ? LIMIT 1");
    $s->execute([normalizeNif($nif)]);
    $row = $s->fetch();
    return $row ?: null;
}

function processFile(PDO $db, string $filePath, ?string $empresaNifHint, string $apiKey, bool $dryRun, ?string $jsonFile = null): string
{
    $base = basename($filePath);
    printf('[%s] ', $base);

    if (!is_file($filePath) || !is_readable($filePath)) {
        echo "ERROR: not found or not readable\n";
        return 'err';
    }
    if (filesize($filePath) > MAX_FILE_BYTES) {
        echo "ERROR: file exceeds 32 MB\n";
        return 'err';
    }

    $hash = hash_file('sha256', $filePath);

    // Dedup
    $s = $db->prepare("SELECT status, idfactura FROM incoming_invoice_imports WHERE pdf_hash = ?");
    $s->execute([$hash]);
    $existing = $s->fetch();
    if ($existing && $existing['status'] === 'imported') {
        printf("SKIP (already imported, idfactura=%s)\n", $existing['idfactura']);
        return 'dup';
    }

    // Agent pipeline: use pre-extracted JSON from file, skip Claude API call
    if ($jsonFile !== null) {
        echo 'agent-extracted... ';
        $jsonStr = @file_get_contents($jsonFile);
        if ($jsonStr === false) {
            printf("ERROR: cannot read --json-file=%s\n", $jsonFile);
            return 'err';
        }
        $parsed      = json_decode($jsonStr, true);
        $rawResponse = $jsonStr;
        $claudeErr   = ($parsed === null) ? 'Invalid JSON in --json-file: ' . json_last_error_msg() : null;
    } else {
        echo 'extracting... ';
        [$parsed, $rawResponse, $claudeErr] = callClaude($filePath, $apiKey);
    }

    if ($claudeErr !== null) {
        printf("ERROR: %s\n", $claudeErr);
        recordImportRow($db, $filePath, $hash, null, $empresaNifHint ?? '?',
                        null, null, 'error', null, $claudeErr, $rawResponse);
        return 'err';
    }

    $validErr = validateExtracted($parsed);
    if ($validErr !== null) {
        printf("ERROR: %s\n", $validErr);
        recordImportRow($db, $filePath, $hash, null, $empresaNifHint ?? '?',
                        null, null, 'error', $parsed['confidence'] ?? null, $validErr, $rawResponse);
        return 'err';
    }

    $supplier            = $parsed['supplier'];
    $supplier['nif']     = normalizeNif($supplier['nif']);  // normalize before any use
    $invoice    = $parsed['invoice'];
    $lines      = $parsed['lines'];
    $confidence = $parsed['confidence'] ?? 'medium';
    $ejercicio  = substr($invoice['date'], 0, 4);

    // ── Resolve empresa ────────────────────────────────────────────────────────
    $recipientNif  = normalizeNif(trim((string)($parsed['recipient']['nif']  ?? '')));
    $recipientName = trim((string)($parsed['recipient']['name'] ?? ''));

    if ($empresaNifHint !== null) {
        $empresa = lookupEmpresaByNif($db, $empresaNifHint);
        if (!$empresa) {
            printf("ERROR: --empresa NIF '%s' not found in DB\n", $empresaNifHint);
            return 'err';
        }
        // Warn if the invoice's stated recipient doesn't match
        if ($recipientNif && $recipientNif !== normalizeNif($empresa['cifnif'])) {
            printf("  WARN  Invoice recipient NIF %s ≠ empresa NIF %s (%s)\n",
                   $recipientNif, $empresa['cifnif'], $empresa['nombre']);
            echo "         Import proceeding as instructed — verify this invoice is for {$empresa['nombre']}\n";
        }
    } else {
        // Auto-select empresa by recipient NIF extracted from the invoice
        if (!$recipientNif) {
            $msg = 'Could not extract recipient NIF from invoice — use --empresa=NIF to specify manually';
            printf("ERROR: %s\n", $msg);
            recordImportRow($db, $filePath, $hash, null, '?', $supplier['nif'], null,
                            'error', $confidence, $msg, $rawResponse);
            return 'err';
        }
        $empresa = lookupEmpresaByNif($db, $recipientNif);
        if (!$empresa) {
            $msg = sprintf(
                'Invoice addressed to NIF %s (%s) but no matching empresa found — use --empresa=NIF',
                $recipientNif, $recipientName ?: '?'
            );
            printf("ERROR: %s\n", $msg);
            recordImportRow($db, $filePath, $hash, null, $recipientNif, $supplier['nif'],
                            null, 'error', $confidence, $msg, $rawResponse);
            return 'err';
        }
        printf("  Empresa : %s (%s) [auto-matched recipient NIF]\n", $empresa['nombre'], $empresa['cifnif']);
    }
    $fecha      = $invoice['date'];
    $fechaVenc  = !empty($invoice['due_date']) ? $invoice['due_date'] : null;
    $numProv    = $invoice['number'] ?? '';

    // Compute totals from lines — do not trust Claude's totals
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

    if ($dryRun) {
        // Run supplier lookup inside a transaction we immediately roll back
        // so change-detection warnings surface without any DB writes escaping.
        $dryWarnings = [];
        $db->beginTransaction();
        try {
            [, $dryWarnings] = upsertSupplier($db, $supplier);
        } catch (Throwable) {
            // ignore — new supplier, no warnings to show
        } finally {
            $db->rollBack();
        }
        printf("OK [dry-run]\n");
        printf("  Empresa    : %s (%s)\n", $empresa['nombre'], $empresa['cifnif']);
        printf("  Recipient  : %s — %s\n", $recipientNif ?: '?', $recipientName ?: '?');
        printf("  Supplier   : %s — %s\n", $supplier['nif'], $supplier['name']);
        $addrParts = array_filter([
            $supplier['address']     ?? null,
            $supplier['postal_code'] ?? null,
            $supplier['city']        ?? null,
        ]);
        if ($addrParts) printf("  Address    : %s\n", implode('  ', $addrParts));
        printf("  Invoice    : %s  date=%s  due=%s\n", $numProv, $fecha, $fechaVenc ?? 'n/a');
        printf("  Lines      : %d  neto=%.2f  IVA=%.2f  IRPF=%.2f  total=%.2f\n",
               count($lines), $neto, $totalIva, $totalIrpf, $total);
        printf("  Confidence : %s\n", $confidence);
        foreach ($dryWarnings as $w) echo $w . "\n";
        return 'ok';
    }

    try {
        $db->beginTransaction();

        [$codProveedor, $supplierWarnings] = upsertSupplier($db, $supplier);
        $numero       = nextNumero($db, (int)$empresa['idempresa'], $ejercicio);
        $codigo       = sprintf('FP-%s-%05d', $ejercicio, $numero);
        $idEstado     = getDefaultEstado($db);

        $idfactura = insertFacturaProv($db, [
            'codigo'          => $codigo,
            'numero'          => (string)$numero,
            'codserie'        => DEFAULT_SERIE,
            'codejercicio'    => $ejercicio,
            'codproveedor' => $codProveedor,
            'cifnif'       => $supplier['nif'],
            'nombre'       => $supplier['name'],
            'numproveedor' => $numProv,
            'fecha'        => $fecha,
            'neto'            => $neto,
            'totaliva'        => $totalIva,
            'totalirpf'       => $totalIrpf,
            'total'           => $total,
            'idestado'        => $idEstado,
            'idempresa'       => (int)$empresa['idempresa'],
            'codpago'         => DEFAULT_PAGO,
            'coddivisa'       => DEFAULT_DIV,
            'observaciones'   => "Importada automáticamente desde: $base",
        ]);

        insertLineas($db, $idfactura, $lines);
        recordImportRow($db, $filePath, $hash, $idfactura, $empresa['cifnif'],
                        $supplier['nif'], $numProv, 'imported', $confidence, null, $rawResponse);

        $db->commit();
        printf("OK  idfactura=%-5d  %s  [%s]\n", $idfactura, $codigo, $confidence);
        foreach ($supplierWarnings as $w) echo $w . "\n";
        return 'ok';

    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $errMsg = $e->getMessage();
        printf("ERROR: %s\n", $errMsg);
        recordImportRow($db, $filePath, $hash, null, $empresa['cifnif'],
                        $supplier['nif'] ?? null, $numProv ?: null,
                        'error', $confidence, $errMsg, $rawResponse);
        return 'err';
    }
}

function callClaude(string $filePath, string $apiKey): array
{
    $ext      = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $isPdf    = ($ext === 'pdf');
    $mimeType = match($ext) {
        'pdf'         => 'application/pdf',
        'jpg','jpeg'  => 'image/jpeg',
        'png'         => 'image/png',
        'webp'        => 'image/webp',
        default       => 'application/octet-stream',
    };

    $encoded = base64_encode((string)file_get_contents($filePath));

    $fileBlock = $isPdf
        ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $encoded]]
        : ['type' => 'image',    'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $encoded]];

    $system = <<<'SYS'
You are an expert at reading Spanish supplier invoices (facturas de proveedor).
Extract the invoice data and return ONLY a valid JSON object — no markdown fences, no explanation.

Required structure:
{
  "recipient": {
    "nif":  "B98765432",
    "name": "EMPRESA COMPRADORA SL"
  },
  "supplier": {
    "nif":         "B12345678",
    "name":        "EMPRESA PROVEEDORA SL",
    "address":     "Calle Mayor 1, 2º",
    "postal_code": "28001",
    "city":        "Madrid",
    "email":       null,
    "phone":       null
  },
  "invoice": {
    "number":   "2024-001",
    "date":     "2024-01-15",
    "due_date": null
  },
  "lines": [
    {
      "description": "Consultoría mensual",
      "quantity":    1.0,
      "unit_price":  1000.00,
      "iva_rate":    21.0,
      "irpf_rate":   15.0
    }
  ],
  "confidence": "high"
}

Rules:
- All dates must be YYYY-MM-DD. Use null when a field is absent or unclear.
- recipient: the company or person the invoice is billed TO (the buyer / destinatario). This is NOT the issuer.
- recipient.nif and supplier.nif: digits and letters only, no spaces, dots, or hyphens (e.g. "B12345678"). Strip any country prefix (e.g. "ESB12345678" → "B12345678"). Use null if not visible.
- Supplier address fields: only populate when clearly legible; use null otherwise.
- irpf_rate: 0.0 when IRPF is not shown.
- confidence: "high" = all key fields clearly legible; "medium" = some fields uncertain; "low" = poor quality or major fields missing.
- Return ONLY the JSON object. Nothing else.
SYS;

    $payload = json_encode([
        'model'      => CLAUDE_MODEL,
        'max_tokens' => 1536,
        'system'     => $system,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                $fileBlock,
                ['type' => 'text', 'text' => 'Extract the invoice data.'],
            ],
        ]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(CLAUDE_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: '         . $apiKey,
            'anthropic-version: ' . CLAUDE_VERSION,
        ],
    ]);

    $body    = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr)    return [null, null, "Network error: $curlErr"];
    if (!is_string($body)) return [null, null, "Empty response from API"];

    $resp = json_decode($body, true);

    if ($code !== 200) {
        $msg = $resp['error']['message'] ?? "HTTP $code";
        return [null, $body, "Claude API error: $msg"];
    }

    $text = $resp['content'][0]['text'] ?? '';

    // Strip markdown fences Claude might add despite instructions
    $text = preg_replace('/^```[a-z]*\s*/i', '', trim($text));
    $text = preg_replace('/\s*```$/i', '',           $text);
    $text = trim($text);

    $parsed = json_decode($text, true);
    if ($parsed === null) {
        return [null, $text, 'Claude returned invalid JSON: ' . json_last_error_msg()];
    }

    return [$parsed, $text, null];
}

function validateExtracted(?array $p): ?string
{
    if (!is_array($p))                                                    return 'No JSON object returned';
    if (empty($p['supplier']['nif']))                                      return 'Missing supplier NIF';
    if (empty($p['supplier']['name']))                                     return 'Missing supplier name';
    if (empty($p['invoice']['date']))                                      return 'Missing invoice date';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['invoice']['date']))      return 'Invalid date format (expected YYYY-MM-DD)';
    if (empty($p['lines']))                                                return 'No line items extracted';
    return null;
}

function normalizeNif(string $nif): string
{
    // Strip spaces, dots, hyphens; uppercase. "B-12.345.678" → "B12345678"
    return strtoupper(preg_replace('/[\s.\-]/u', '', trim($nif)));
}

function normalizeForCompare(string $s): string
{
    // Lowercase + collapse non-alphanumeric runs to single space.
    // "EMPRESA, S.L." → "empresa sl",  "Calle Mayor, 1" → "calle mayor 1"
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

// Returns [string $codProveedor, string[] $warnings]
function upsertSupplier(PDO $db, array $supplier): array
{
    $cifnif = normalizeNif($supplier['nif']);
    $name   = trim($supplier['name']);

    $s = $db->prepare(
        "SELECT codproveedor, nombre, razonsocial FROM proveedores WHERE cifnif = ? LIMIT 1"
    );
    $s->execute([$cifnif]);
    $existing = $s->fetch();

    if ($existing) {
        // Compare name against stored data; warn on material differences.
        $warnings   = [];
        $storedName = $existing['razonsocial'] ?: $existing['nombre'];
        similar_text(normalizeForCompare($name), normalizeForCompare($storedName), $pct);
        if ($pct < 75) {
            $warnings[] = sprintf(
                '  WARN  Supplier name on invoice: "%s"  ≠  stored: "%s"',
                $name, $storedName
            );
        }

        // Address comparison via contactos (where FS stores supplier addresses)
        $c = $db->prepare(
            "SELECT direccion, codpostal, ciudad FROM contactos
             WHERE codproveedor = ? ORDER BY idcontacto LIMIT 1"
        );
        $c->execute([$existing['codproveedor']]);
        $contact = $c->fetch() ?: [];

        $invPost = trim((string)($supplier['postal_code'] ?? ''));
        $invCity = trim((string)($supplier['city']        ?? ''));
        $invAddr = trim((string)($supplier['address']     ?? ''));

        if ($invPost !== '' && $invPost !== ($contact['codpostal'] ?? '')) {
            $warnings[] = sprintf(
                '  WARN  Postal code on invoice: %s  ≠  stored: %s',
                $invPost, $contact['codpostal'] ?: '—'
            );
        }
        if ($invCity !== '' && normalizeForCompare($invCity) !== normalizeForCompare((string)($contact['ciudad'] ?? ''))) {
            $warnings[] = sprintf(
                '  WARN  City on invoice: "%s"  ≠  stored: "%s"',
                $invCity, $contact['ciudad'] ?: '—'
            );
        }
        if ($invAddr !== '' && normalizeForCompare($invAddr) !== normalizeForCompare((string)($contact['direccion'] ?? ''))) {
            $warnings[] = sprintf(
                '  WARN  Address on invoice: "%s"  ≠  stored: "%s"',
                $invAddr, $contact['direccion'] ?: '—'
            );
        }

        if ($warnings) {
            $warnings[] = '         Update in FacturaScripts › Compras › Proveedores if needed.';
        }

        return [$existing['codproveedor'], $warnings];
    }

    // New supplier — generate codproveedor from NIF chars, max 10, unique
    $base  = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $cifnif), 0, 10));
    $code  = $base;
    $n     = 1;
    $check = $db->prepare("SELECT 1 FROM proveedores WHERE codproveedor = ?");
    while (true) {
        $check->execute([$code]);
        if (!$check->fetch()) break;
        $code = substr($base, 0, 8) . $n++;
    }

    $db->prepare(
        "INSERT INTO proveedores (codproveedor, cifnif, nombre, razonsocial, codpago, fechaalta)
         VALUES (?, ?, ?, ?, ?, CURDATE())"
    )->execute([$code, $cifnif, $name, $name, DEFAULT_PAGO]);

    // Address and contact details go in the contactos table (FS convention)
    $db->prepare(
        "INSERT INTO contactos
            (codproveedor, cifnif, empresa, direccion, codpostal, ciudad,
             codpais, email, telefono1, fechaalta)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())"
    )->execute([
        $code, $cifnif, $name,
        $supplier['address']     ?: null,
        $supplier['postal_code'] ?: null,
        $supplier['city']        ?: null,
        DEFAULT_PAIS,
        $supplier['email']       ?: null,
        $supplier['phone']       ?: null,
    ]);

    return [$code, []];
}

function nextNumero(PDO $db, int $idEmpresa, string $ejercicio): int
{
    $s = $db->prepare(
        "SELECT COALESCE(MAX(CAST(numero AS SIGNED)), 0) + 1 AS next_num
         FROM facturasprov
         WHERE idempresa = ? AND codejercicio = ?"
    );
    $s->execute([$idEmpresa, $ejercicio]);
    return (int)$s->fetch()['next_num'];
}

function getDefaultEstado(PDO $db): int
{
    $s = $db->query(
        "SELECT idestado FROM estados_documentos
         WHERE tipodoc = 'FacturaProveedor'
         ORDER BY idestado LIMIT 1"
    );
    $row = $s->fetch();
    return $row ? (int)$row['idestado'] : 0;
}

function insertFacturaProv(PDO $db, array $d): int
{
    $db->prepare(
        "INSERT INTO facturasprov
            (codigo, numero, codserie, codejercicio,
             codproveedor, cifnif, nombre, numproveedor,
             fecha,
             neto, totaliva, totalirpf, total,
             idestado, idempresa, codpago, coddivisa, observaciones)
         VALUES
            (:codigo, :numero, :codserie, :codejercicio,
             :codproveedor, :cifnif, :nombre, :numproveedor,
             :fecha,
             :neto, :totaliva, :totalirpf, :total,
             :idestado, :idempresa, :codpago, :coddivisa, :observaciones)"
    )->execute([
        ':codigo'       => $d['codigo'],
        ':numero'       => $d['numero'],
        ':codserie'     => $d['codserie'],
        ':codejercicio' => $d['codejercicio'],
        ':codproveedor' => $d['codproveedor'],
        ':cifnif'       => $d['cifnif'],
        ':nombre'       => $d['nombre'],
        ':numproveedor' => $d['numproveedor'],
        ':fecha'        => $d['fecha'],
        ':neto'         => $d['neto'],
        ':totaliva'     => $d['totaliva'],
        ':totalirpf'    => $d['totalirpf'],
        ':total'        => $d['total'],
        ':idestado'     => $d['idestado'],
        ':idempresa'    => $d['idempresa'],
        ':codpago'      => $d['codpago'],
        ':coddivisa'    => $d['coddivisa'],
        ':observaciones'=> $d['observaciones'],
    ]);
    return (int)$db->lastInsertId();
}

function insertLineas(PDO $db, int $idfactura, array $lines): void
{
    $stmt = $db->prepare(
        "INSERT INTO lineasfacturasprov
            (idfactura, descripcion, cantidad, pvpunitario, pvpsindto, dtopor, pvptotal, iva, irpf, recargo)
         VALUES
            (:idfactura, :desc, :qty, :unit, :sinDto, 0, :total, :iva, :irpf, 0)"
    );
    foreach ($lines as $line) {
        $qty    = (float)($line['quantity']   ?? 1);
        $unit   = (float)($line['unit_price'] ?? 0);
        $sinDto = round($qty * $unit, 6);
        $stmt->execute([
            ':idfactura' => $idfactura,
            ':desc'      => trim((string)($line['description'] ?? '')),
            ':qty'       => $qty,
            ':unit'      => $unit,
            ':sinDto'    => $sinDto,
            ':total'     => $sinDto,  // no discount support yet
            ':iva'       => (float)($line['iva_rate']  ?? 0),
            ':irpf'      => (float)($line['irpf_rate'] ?? 0),
        ]);
    }
}

function recordImportRow(
    PDO     $db,
    string  $pdfPath,
    string  $pdfHash,
    ?int    $idfactura,
    string  $empresaNif,
    ?string $supplierNif,
    ?string $invoiceNumber,
    string  $status,
    ?string $confidence,
    ?string $errorMsg,
    ?string $rawResponse
): void {
    try {
        $db->prepare(
            "INSERT INTO incoming_invoice_imports
                (pdf_path, pdf_hash, idfactura, empresa_nif, supplier_nif, invoice_number,
                 status, confidence, error_message, claude_raw)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                status        = VALUES(status),
                idfactura     = VALUES(idfactura),
                confidence    = VALUES(confidence),
                error_message = VALUES(error_message),
                claude_raw    = VALUES(claude_raw)"
        )->execute([
            $pdfPath, $pdfHash, $idfactura, $empresaNif, $supplierNif,
            $invoiceNumber, $status, $confidence, $errorMsg, $rawResponse,
        ]);
    } catch (Throwable) {
        // best-effort — don't mask the original error
    }
}
