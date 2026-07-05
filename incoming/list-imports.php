<?php
/**
 * List incoming invoice import history from the audit log.
 *
 * Usage:
 *   docker compose exec app php /incoming/list-imports.php
 *   docker compose exec app php /incoming/list-imports.php --status=error
 *   docker compose exec app php /incoming/list-imports.php --status=imported --limit=20
 */

$status = null;
$limit  = 50;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (str_starts_with($arg, '--status=')) $status = strtolower(trim(substr($arg, 9)));
    if (str_starts_with($arg, '--limit='))  $limit  = max(1, (int)substr($arg, 8));
}

$configCandidates = ['/secrets/companies.php', __DIR__ . '/companies.php'];
$config = null;
foreach ($configCandidates as $path) {
    if (file_exists($path)) { $config = require $path; break; }
}
if ($config === null) {
    fwrite(STDERR, "Cannot find companies.php\n"); exit(1);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['database']['host'],
        (int)($config['database']['port'] ?? 3306),
        $config['database']['name']),
    $config['database']['user'],
    $config['database']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$where  = $status ? "WHERE status = " . $pdo->quote($status) : '';
$rows   = $pdo->query(
    "SELECT id, created_at, status, confidence, supplier_nif, invoice_number,
            idfactura, empresa_nif, error_message,
            LEFT(pdf_path, 60) AS short_path
     FROM incoming_invoice_imports
     $where
     ORDER BY id DESC
     LIMIT $limit"
)->fetchAll();

if (!$rows) {
    echo "No import records found" . ($status ? " with status=$status" : "") . ".\n";
    exit(0);
}

// Totals
$total   = $pdo->query("SELECT COUNT(*) FROM incoming_invoice_imports")->fetchColumn();
$imported = $pdo->query("SELECT COUNT(*) FROM incoming_invoice_imports WHERE status='imported'")->fetchColumn();
$errors  = $pdo->query("SELECT COUNT(*) FROM incoming_invoice_imports WHERE status='error'")->fetchColumn();

printf("Import log  —  total: %d  imported: %d  errors: %d\n\n", $total, $imported, $errors);

$fmt = "%-4s  %-19s  %-8s  %-6s  %-15s  %-15s  %-8s  %s\n";
printf($fmt, 'ID', 'Date', 'Status', 'Conf', 'Supplier NIF', 'Inv Number', 'FS id', 'File / Error');
echo str_repeat('─', 110) . "\n";

foreach ($rows as $r) {
    $detail = ($r['status'] === 'error')
        ? 'ERR: ' . substr($r['error_message'] ?? '', 0, 40)
        : $r['short_path'];

    printf($fmt,
        $r['id'],
        $r['created_at'],
        $r['status'],
        $r['confidence'] ?? '—',
        $r['supplier_nif'] ?? '—',
        $r['invoice_number'] ?? '—',
        $r['idfactura'] ?? '—',
        $detail
    );
}
