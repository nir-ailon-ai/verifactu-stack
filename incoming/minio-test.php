<?php
/**
 * Smoke-test the MinIO connection from inside the app container.
 *
 * Usage:
 *   docker compose exec app php /incoming/minio-test.php
 */

require_once __DIR__ . '/MinioClient.php';

$minio  = MinioClient::fromEnv();
$bucket = 'incoming';
$key    = '_test/smoke-test.txt';
$body   = 'verifactu-stack minio smoke test ' . date('c');

echo "Endpoint : " . (getenv('MINIO_ENDPOINT') ?: 'http://minio:9000') . "\n";
echo "Bucket   : $bucket\n\n";

// 1. Create bucket (idempotent — 409 if already exists, we treat that as OK)
echo "1. createBucket ... ";
$ok = $minio->createBucket($bucket);
echo ($ok ? "OK" : "already exists / OK") . "\n";

// 2. Upload
echo "2. putObject    ... ";
$ok = $minio->putObject($bucket, $key, $body, 'text/plain');
echo ($ok ? "OK" : "FAILED") . "\n";

// 3. List
echo "3. listObjects  ... ";
$list = $minio->listObjects($bucket, '_test/');
$found = array_filter($list, fn($o) => $o['key'] === $key);
echo (count($found) ? "OK (found " . count($list) . " object(s) under _test/)" : "FAILED") . "\n";

// 4. Download and verify
echo "4. getObject    ... ";
$dl = $minio->getObject($bucket, $key);
echo ($dl === $body ? "OK (content matches)" : "FAILED") . "\n";

// 5. Pre-signed URL
echo "5. presignedUrl ... ";
$url = $minio->presignedUrl($bucket, $key, 300);
echo "OK\n   $url\n";

// 6. Delete
echo "6. deleteObject ... ";
$ok = $minio->deleteObject($bucket, $key);
echo ($ok ? "OK" : "FAILED") . "\n";

echo "\nDone.\n";
