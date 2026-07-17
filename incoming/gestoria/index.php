<?php
/**
 * Gestoria document upload UI.
 * Served at http://localhost/gestoria/
 *
 * Key convention:  docs/{nif}/{year}/T{q}/{type}/{filename}
 *                  docs/{nif}/{year}/eoy/{filename}
 *
 * GET  /gestoria/           → HTML page
 * GET  /gestoria/?api=list  → JSON list of objects for a prefix
 * POST /gestoria/?api=upload→ upload file to MinIO, return JSON
 */

require_once dirname(__DIR__) . '/MinioClient.php';

const BUCKET = 'docs';
const DOC_TYPES = ['recibidas', 'emitidas', 'declaraciones', 'eoy'];
const QUARTERS  = ['T1', 'T2', 'T3', 'T4'];

$minio = MinioClient::fromEnv();

// ── API handlers ──────────────────────────────────────────────────────────────

if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $minio->createBucket(BUCKET); // idempotent

    match ($_GET['api']) {
        'list'      => apiList($minio),
        'upload'    => apiUpload($minio),
        'delete'    => apiDelete($minio),
        'companies' => apiCompanies($minio),
        'download'  => apiDownload($minio),
        default     => jsonError('unknown api'),
    };
    exit;
}

function apiList(MinioClient $minio): void
{
    $prefix = $_GET['prefix'] ?? '';
    $objects = $minio->listObjects(BUCKET, $prefix);
    echo json_encode(['ok' => true, 'objects' => $objects]);
}

function apiCompanies(MinioClient $minio): void
{
    try {
        $pdo = new PDO(
            'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
            getenv('DB_USER'),
            getenv('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $rows = $pdo->query('SELECT cifnif, nombre FROM empresas ORDER BY idempresa')
                    ->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'companies' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'companies' => [], 'error' => $e->getMessage()]);
    }
}

function apiUpload(MinioClient $minio): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('POST required'); return;
    }

    $nif     = trim($_POST['nif']     ?? '');
    $year    = trim($_POST['year']    ?? '');
    $quarter = trim($_POST['quarter'] ?? '');
    $type    = trim($_POST['type']    ?? '');

    if (!$nif || !$year || !$type) {
        jsonError('nif, year, type required'); return;
    }
    if (!preg_match('/^\d{4}$/', $year)) {
        jsonError('invalid year'); return;
    }
    if ($type === 'eoy') {
        $prefix = "$nif/$year/eoy/";
    } else {
        if (!$quarter || !in_array($quarter, QUARTERS, true)) {
            jsonError('quarter required for this type'); return;
        }
        if (!in_array($type, DOC_TYPES, true)) {
            jsonError('invalid type'); return;
        }
        $prefix = "$nif/$year/$quarter/$type/";
    }

    if (empty($_FILES['files']['name'][0])) {
        jsonError('no files'); return;
    }

    $uploaded = [];
    $errors   = [];

    foreach ($_FILES['files']['name'] as $i => $name) {
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "$name: upload error " . $_FILES['files']['error'][$i];
            continue;
        }

        $safeName = preg_replace('/[^A-Za-z0-9._\-() ]/', '_', $name);
        $key      = $prefix . $safeName;
        $mime     = $_FILES['files']['type'][$i] ?: 'application/octet-stream';
        $body     = file_get_contents($_FILES['files']['tmp_name'][$i]);

        if ($minio->putObject(BUCKET, $key, $body, $mime)) {
            $uploaded[] = ['key' => $key, 'size' => strlen($body)];
        } else {
            $errors[] = "$name: MinIO write failed";
        }
    }

    echo json_encode(['ok' => empty($errors), 'uploaded' => $uploaded, 'errors' => $errors]);
}

function apiDownload(MinioClient $minio): void
{
    $key = $_GET['key'] ?? '';
    if (!$key) { jsonError('key required'); return; }
    $body = $minio->getObject(BUCKET, $key);
    if ($body === false) { http_response_code(404); echo 'Not found'; return; }
    $filename = basename($key);
    $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'pdf'  => 'application/pdf',
        'jpg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'xlsx', 'xls' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip'  => 'application/zip',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Content-Length: ' . strlen($body));
    echo $body;
}

function apiDelete(MinioClient $minio): void
{
    $key = $_POST['key'] ?? '';
    if (!$key) { jsonError('key required'); return; }
    $ok = $minio->deleteObject(BUCKET, $key);
    echo json_encode(['ok' => $ok]);
}

function jsonError(string $msg): void
{
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $msg]);
}

// ── HTML page ─────────────────────────────────────────────────────────────────
$currentYear = date('Y');
$years = range($currentYear, 2025);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gestoria — Subir documentos</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg: #f8f9fa;
  --surface: #fff;
  --border: #dee2e6;
  --text: #212529;
  --muted: #6c757d;
  --accent: #0d6efd;
  --accent-hover: #0b5ed7;
  --success: #198754;
  --danger: #dc3545;
  --radius: 8px;
  --shadow: 0 1px 4px rgba(0,0,0,.08);
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #1a1d20;
    --surface: #25282c;
    --border: #3d4144;
    --text: #e9ecef;
    --muted: #868e96;
    --accent: #4dabf7;
    --accent-hover: #74c0fc;
    --success: #51cf66;
    --danger: #ff6b6b;
    --shadow: 0 1px 4px rgba(0,0,0,.3);
  }
}
:root[data-theme="dark"]  { --bg:#1a1d20;--surface:#25282c;--border:#3d4144;--text:#e9ecef;--muted:#868e96;--accent:#4dabf7;--accent-hover:#74c0fc;--success:#51cf66;--danger:#ff6b6b;--shadow:0 1px 4px rgba(0,0,0,.3); }
:root[data-theme="light"] { --bg:#f8f9fa;--surface:#fff;--border:#dee2e6;--text:#212529;--muted:#6c757d;--accent:#0d6efd;--accent-hover:#0b5ed7;--success:#198754;--danger:#dc3545;--shadow:0 1px 4px rgba(0,0,0,.08); }

body { background: var(--bg); color: var(--text); font: 14px/1.5 system-ui, sans-serif; min-height: 100vh; }

.layout { display: grid; grid-template-columns: 280px 1fr; min-height: 100vh; }

/* ── Sidebar ── */
.sidebar {
  background: var(--surface);
  border-right: 1px solid var(--border);
  padding: 24px 16px;
  display: flex; flex-direction: column; gap: 20px;
}
.logo { font-size: 18px; font-weight: 700; color: var(--text); }
.logo span { color: var(--accent); }

.field { display: flex; flex-direction: column; gap: 6px; }
.field label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
.field select, .field input[type=text] {
  padding: 8px 10px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg);
  color: var(--text);
  font-size: 14px;
}
.field select:focus, .field input:focus { outline: 2px solid var(--accent); outline-offset: -1px; }

.key-preview {
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 10px 12px;
  font-family: monospace;
  font-size: 12px;
  color: var(--muted);
  word-break: break-all;
  min-height: 40px;
}

.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  padding: 8px 16px;
  border: none; border-radius: var(--radius);
  font-size: 14px; font-weight: 500; cursor: pointer;
  transition: background .15s;
}
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: var(--accent-hover); }
.btn-sm { padding: 4px 10px; font-size: 12px; }
.btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--text); }
.btn-ghost:hover { background: var(--border); }

/* ── Main ── */
.main { display: flex; flex-direction: column; gap: 0; }

.topbar {
  padding: 16px 24px;
  border-bottom: 1px solid var(--border);
  background: var(--surface);
  font-weight: 600;
  font-size: 15px;
  display: flex; align-items: center; gap: 8px;
}

.content { padding: 24px; display: flex; flex-direction: column; gap: 20px; }

/* ── Drop zone ── */
.dropzone {
  border: 2px dashed var(--border);
  border-radius: var(--radius);
  padding: 48px 24px;
  text-align: center;
  cursor: pointer;
  transition: border-color .2s, background .2s;
  position: relative;
}
.dropzone:hover, .dropzone.drag-over {
  border-color: var(--accent);
  background: color-mix(in srgb, var(--accent) 6%, transparent);
}
.dropzone input[type=file] {
  position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.dropzone-icon { font-size: 36px; margin-bottom: 12px; }
.dropzone-text { color: var(--muted); font-size: 14px; }
.dropzone-text strong { color: var(--accent); }

/* ── Queue ── */
.queue { display: flex; flex-direction: column; gap: 8px; }
.queue-item {
  display: grid; grid-template-columns: 1fr auto auto;
  align-items: center; gap: 12px;
  padding: 10px 14px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
}
.queue-item-name { font-size: 13px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.queue-item-size { font-size: 11px; color: var(--muted); white-space: nowrap; }
.status-badge {
  font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 99px;
  white-space: nowrap;
}
.status-pending  { background: color-mix(in srgb, var(--muted) 15%, transparent); color: var(--muted); }
.status-uploading{ background: color-mix(in srgb, var(--accent) 15%, transparent); color: var(--accent); }
.status-done     { background: color-mix(in srgb, var(--success) 15%, transparent); color: var(--success); }
.status-error    { background: color-mix(in srgb, var(--danger) 15%, transparent); color: var(--danger); }

.upload-actions { display: flex; gap: 8px; }

/* ── File browser ── */
.browser-header {
  display: flex; align-items: center; justify-content: space-between;
  font-size: 13px; font-weight: 600; color: var(--muted); margin-bottom: 8px;
}
.file-list { display: flex; flex-direction: column; gap: 4px; }
.file-row {
  display: grid; grid-template-columns: 24px 1fr auto auto auto auto;
  align-items: center; gap: 8px;
  padding: 7px 10px;
  border-radius: 6px;
  font-size: 13px;
  transition: background .1s;
}
.file-row:hover { background: var(--border); }
.file-row-icon { text-align: center; font-size: 15px; }
.file-row-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.file-row-size { font-size: 11px; color: var(--muted); white-space: nowrap; }
.file-row-date { font-size: 11px; color: var(--muted); white-space: nowrap; }
.file-row-dl   { opacity: 0; cursor: pointer; color: var(--accent); font-size: 15px; line-height: 1; background: none; border: none; padding: 2px 4px; text-decoration: none; }
.file-row-del  { opacity: 0; cursor: pointer; color: var(--danger); font-size: 16px; line-height: 1; background: none; border: none; padding: 2px 4px; }
.file-row:hover .file-row-dl  { opacity: 1; }
.file-row:hover .file-row-del { opacity: 1; }

.empty-state { text-align: center; color: var(--muted); padding: 32px; font-size: 13px; }

.toast-stack { position: fixed; bottom: 24px; right: 24px; display: flex; flex-direction: column-reverse; gap: 8px; z-index: 99; }
.toast {
  padding: 10px 16px; border-radius: var(--radius);
  font-size: 13px; font-weight: 500;
  box-shadow: 0 2px 8px rgba(0,0,0,.2);
  animation: slideIn .2s ease;
}
.toast-success { background: var(--success); color: #fff; }
.toast-error   { background: var(--danger);  color: #fff; }
@keyframes slideIn { from { transform: translateY(12px); opacity: 0; } to { transform: none; opacity: 1; } }

@media (max-width: 700px) {
  .layout { grid-template-columns: 1fr; }
  .sidebar { border-right: none; border-bottom: 1px solid var(--border); }
}
</style>
</head>
<body>

<div class="layout">

<!-- ── SIDEBAR ── -->
<aside class="sidebar">
  <div class="logo">gestoria<span>.</span></div>

  <div class="field">
    <label>Empresa (NIF)</label>
    <select id="nif" onchange="onNifChange()">
      <option value="">— Seleccionar —</option>
    </select>
  </div>
  <div class="field" id="nif-custom-field" style="display:none">
    <label>NIF de la nueva empresa</label>
    <input type="text" id="nif-custom" placeholder="B12345678" maxlength="9" autocomplete="off" spellcheck="false">
  </div>

  <div class="field">
    <label>Año</label>
    <select id="year">
      <?php foreach ($years as $y): ?>
        <option value="<?= $y ?>" <?= $y == $currentYear ? 'selected' : '' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field" id="quarter-field">
    <label>Trimestre</label>
    <select id="quarter">
      <?php foreach (QUARTERS as $q): ?>
        <option value="<?= $q ?>"><?= $q ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label>Tipo</label>
    <select id="type">
      <option value="recibidas">Facturas recibidas</option>
      <option value="emitidas">Facturas emitidas</option>
      <option value="declaraciones">Declaraciones AEAT</option>
      <option value="eoy">Cierre anual (EOY)</option>
    </select>
  </div>

  <div class="field">
    <label>Ruta en MinIO</label>
    <div class="key-preview" id="key-preview">—</div>
  </div>

  <button class="btn btn-primary" onclick="browseFolder()">Ver archivos</button>
</aside>

<!-- ── MAIN ── -->
<main class="main">
  <div class="topbar">
    <span id="topbar-title">Subir documentos</span>
  </div>

  <div class="content">

    <!-- Drop zone -->
    <div class="dropzone" id="dropzone">
      <input type="file" id="file-input" multiple accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls,.zip">
      <div class="dropzone-icon">📄</div>
      <div class="dropzone-text">
        <strong>Arrastra archivos aquí</strong> o haz clic para seleccionar<br>
        <small>PDF, imágenes, Excel, ZIP</small>
      </div>
    </div>

    <!-- Upload queue -->
    <div id="queue-section" style="display:none">
      <div class="browser-header">
        <span id="queue-label">Archivos seleccionados</span>
        <div class="upload-actions">
          <button class="btn btn-ghost btn-sm" onclick="clearQueue()">Limpiar</button>
          <button class="btn btn-primary btn-sm" id="upload-btn" onclick="uploadAll()">Subir todo</button>
        </div>
      </div>
      <div class="queue" id="queue"></div>
    </div>

    <!-- File browser -->
    <div id="browser-section" style="display:none">
      <div class="browser-header">
        <span id="browser-prefix-label"></span>
        <button class="btn btn-ghost btn-sm" onclick="browseFolder()">↺ Actualizar</button>
      </div>
      <div id="file-list" class="file-list"></div>
    </div>

  </div>
</main>

</div>

<div class="toast-stack" id="toasts"></div>

<script>
const $ = id => document.getElementById(id);

// ── State ────────────────────────────────────────────────────────────────────
let queue = []; // {file, status, error}

// ── Companies ────────────────────────────────────────────────────────────────
async function loadCompanies() {
  const sel = $('nif');
  try {
    const res  = await fetch('?api=companies');
    const data = await res.json();
    (data.companies || []).forEach(c => {
      const opt = document.createElement('option');
      opt.value = c.cifnif;
      opt.textContent = `${c.cifnif} — ${c.nombre}`;
      sel.appendChild(opt);
    });
    // auto-select if only one company
    if ((data.companies || []).length === 1) {
      sel.value = data.companies[0].cifnif;
      updatePreview();
    }
  } catch (e) { /* fall through to manual option */ }
  const add = document.createElement('option');
  add.value = '__new__';
  add.textContent = '+ Añadir empresa…';
  sel.appendChild(add);
}

function onNifChange() {
  const isNew = $('nif').value === '__new__';
  $('nif-custom-field').style.display = isNew ? '' : 'none';
  if (!isNew) { $('nif-custom').value = ''; }
  updatePreview();
}

// ── Selectors ────────────────────────────────────────────────────────────────
['year','quarter','type'].forEach(id => $(id).addEventListener('change', updatePreview));
$('nif-custom').addEventListener('input', updatePreview);

function currentParams() {
  let nif = $('nif').value.trim().toUpperCase();
  if (nif === '__NEW__') nif = $('nif-custom').value.trim().toUpperCase();
  const year = $('year').value;
  const type = $('type').value;
  const q    = $('quarter').value;
  return { nif, year, type, quarter: q };
}

function currentPrefix() {
  const { nif, year, type, quarter } = currentParams();
  if (!nif || !year) return '';
  if (type === 'eoy') return `${nif}/${year}/eoy/`;
  return `${nif}/${year}/${quarter}/${type}/`;
}

function updatePreview() {
  const p = currentPrefix();
  $('key-preview').textContent = p ? `docs/${p}` : '—';
  // show/hide quarter selector
  $('quarter-field').style.display = $('type').value === 'eoy' ? 'none' : '';
  // update topbar
  $('topbar-title').textContent = p ? `docs/${p}` : 'Subir documentos';
  // auto-refresh browser if visible
  if ($('browser-section').style.display !== 'none') browseFolder();
}
$('type').addEventListener('change', updatePreview);
updatePreview();
loadCompanies();

// ── Drop zone ────────────────────────────────────────────────────────────────
const dropzone = $('dropzone');
dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('drag-over'); });
dropzone.addEventListener('dragleave', () => dropzone.classList.remove('drag-over'));
dropzone.addEventListener('drop', e => {
  e.preventDefault();
  dropzone.classList.remove('drag-over');
  addFiles([...e.dataTransfer.files]);
});
$('file-input').addEventListener('change', e => { addFiles([...e.target.files]); e.target.value = ''; });

function addFiles(files) {
  files.forEach(f => queue.push({ file: f, status: 'pending', error: null }));
  renderQueue();
}

// ── Queue ────────────────────────────────────────────────────────────────────
function renderQueue() {
  if (queue.length === 0) { $('queue-section').style.display = 'none'; return; }
  $('queue-section').style.display = '';
  $('queue-label').textContent = `${queue.length} archivo${queue.length > 1 ? 's' : ''} seleccionado${queue.length > 1 ? 's' : ''}`;
  $('queue').innerHTML = queue.map((item, i) => `
    <div class="queue-item" id="qi-${i}">
      <div class="queue-item-name" title="${esc(item.file.name)}">${esc(item.file.name)}</div>
      <div class="queue-item-size">${fmtSize(item.file.size)}</div>
      <span class="status-badge status-${item.status}">${statusLabel(item)}</span>
    </div>
  `).join('');
}

function statusLabel(item) {
  if (item.status === 'pending')   return 'Pendiente';
  if (item.status === 'uploading') return 'Subiendo…';
  if (item.status === 'done')      return '✓ Subido';
  if (item.status === 'error')     return item.error || 'Error';
  return item.status;
}

function clearQueue() {
  queue = queue.filter(i => i.status === 'uploading');
  renderQueue();
}

// ── Upload ───────────────────────────────────────────────────────────────────
async function uploadAll() {
  const { nif, year, type, quarter } = currentParams();
  if (!nif) { toast('Introduce el NIF de la empresa', 'error'); return; }

  const pending = queue.filter(i => i.status === 'pending');
  if (!pending.length) { toast('No hay archivos pendientes', 'error'); return; }

  $('upload-btn').disabled = true;

  const form = new FormData();
  form.append('nif', nif);
  form.append('year', year);
  form.append('type', type);
  if (type !== 'eoy') form.append('quarter', quarter);
  pending.forEach(item => { item.status = 'uploading'; form.append('files[]', item.file); });
  renderQueue();

  try {
    const res  = await fetch('?api=upload', { method: 'POST', body: form });
    const data = await res.json();

    // mark statuses
    let ui = 0;
    queue.forEach(item => {
      if (item.status !== 'uploading') return;
      const uploaded = data.uploaded || [];
      const matched  = uploaded.find(u => u.key.endsWith(item.file.name.replace(/[^A-Za-z0-9._\-() ]/g, '_')));
      if (matched) { item.status = 'done'; }
      else {
        item.status = 'error';
        item.error  = data.errors?.[ui] || 'Error';
        ui++;
      }
    });

    if (data.ok) {
      toast(`${data.uploaded.length} archivo${data.uploaded.length > 1 ? 's' : ''} subido${data.uploaded.length > 1 ? 's' : ''} correctamente`, 'success');
      browseFolder();
    } else {
      toast((data.errors || ['Error desconocido']).join('; '), 'error');
    }
  } catch (e) {
    queue.filter(i => i.status === 'uploading').forEach(i => { i.status = 'error'; i.error = 'Error de red'; });
    toast('Error de red', 'error');
  }

  renderQueue();
  $('upload-btn').disabled = false;
}

// ── File browser ─────────────────────────────────────────────────────────────
async function browseFolder() {
  const prefix = currentPrefix();
  if (!prefix) { toast('Selecciona empresa y año primero', 'error'); return; }

  $('browser-section').style.display = '';
  $('browser-prefix-label').textContent = `docs/${prefix}`;
  $('file-list').innerHTML = '<div class="empty-state">Cargando…</div>';

  try {
    const res  = await fetch(`?api=list&prefix=${encodeURIComponent(prefix)}`);
    const data = await res.json();
    renderFileList(data.objects || []);
  } catch (e) {
    $('file-list').innerHTML = '<div class="empty-state">Error al cargar la lista</div>';
  }
}

function renderFileList(objects) {
  if (!objects.length) {
    $('file-list').innerHTML = '<div class="empty-state">Esta carpeta está vacía</div>';
    return;
  }
  $('file-list').innerHTML = objects.map(o => {
    const name = o.key.split('/').pop();
    const ext  = name.split('.').pop().toLowerCase();
    const icon = { pdf: '📄', jpg: '🖼', jpeg: '🖼', png: '🖼', xlsx: '📊', xls: '📊', zip: '🗜' }[ext] || '📎';
    const date = o.last_modified ? new Date(o.last_modified).toLocaleDateString('es-ES') : '';
    return `
      <div class="file-row">
        <span class="file-row-icon">${icon}</span>
        <span class="file-row-name" title="${esc(o.key)}">${esc(name)}</span>
        <span class="file-row-size">${fmtSize(o.size)}</span>
        <span class="file-row-date">${date}</span>
        <a class="file-row-dl" title="Descargar" href="?api=download&key=${encodeURIComponent(o.key)}" download="${esc(name)}">⬇</a>
        <button class="file-row-del" title="Eliminar" onclick="deleteFile(${JSON.stringify(o.key)})">✕</button>
      </div>
    `;
  }).join('');
}

async function deleteFile(key) {
  if (!confirm(`¿Eliminar ${key.split('/').pop()}?`)) return;
  try {
    const form = new FormData();
    form.append('key', key);
    const res  = await fetch('?api=delete', { method: 'POST', body: form });
    const data = await res.json();
    if (data.ok) { toast('Archivo eliminado', 'success'); browseFolder(); }
    else toast('Error al eliminar', 'error');
  } catch (e) { toast('Error de red', 'error'); }
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function fmtSize(bytes) {
  if (bytes < 1024)       return bytes + ' B';
  if (bytes < 1048576)    return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / 1048576).toFixed(1) + ' MB';
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function toast(msg, type = 'success') {
  const el = document.createElement('div');
  el.className = `toast toast-${type}`;
  el.textContent = msg;
  $('toasts').appendChild(el);
  setTimeout(() => el.remove(), 3500);
}

</script>
</body>
</html>
