<?php
/**
 * Minimal S3/MinIO client — no external dependencies.
 *
 * Implements AWS Signature V4 over cURL. Suitable for invoice-sized payloads
 * (< ~50 MB). For multipart uploads, extend with the S3 multipart API.
 *
 * Usage:
 *   require_once __DIR__ . '/MinioClient.php';
 *   $minio = MinioClient::fromEnv();
 *
 *   $minio->createBucket('incoming');
 *   $minio->putObject('incoming', 'ailon-sl/2026-T2/factura.pdf', file_get_contents($path), 'application/pdf');
 *   $url  = $minio->presignedUrl('incoming', 'ailon-sl/2026-T2/factura.pdf', 3600);
 *   $list = $minio->listObjects('incoming', 'ailon-sl/2026-T2/');
 *
 * Environment variables (set in docker-compose.yml → app service):
 *   MINIO_ENDPOINT   http://minio:9000
 *   MINIO_ACCESS_KEY minioadmin
 *   MINIO_SECRET_KEY your-password
 */
class MinioClient
{
    private string $endpoint;
    private string $accessKey;
    private string $secretKey;
    private string $region;

    public function __construct(
        string $endpoint,
        string $accessKey,
        string $secretKey,
        string $region = 'us-east-1'
    ) {
        $this->endpoint  = rtrim($endpoint, '/');
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->region    = $region;
    }

    public static function fromEnv(): self
    {
        return new self(
            getenv('MINIO_ENDPOINT')   ?: 'http://minio:9000',
            getenv('MINIO_ACCESS_KEY') ?: '',
            getenv('MINIO_SECRET_KEY') ?: ''
        );
    }

    // ── Public API ──────────────────────────────────────────────────────────

    public function createBucket(string $bucket): bool
    {
        [$status] = $this->s3request('PUT', "/$bucket");
        return $status === 200;
    }

    public function bucketExists(string $bucket): bool
    {
        [$status] = $this->s3request('HEAD', "/$bucket");
        return $status === 200;
    }

    /**
     * Upload an object. $body is the raw file contents as a string.
     */
    public function putObject(
        string $bucket,
        string $key,
        string $body,
        string $contentType = 'application/octet-stream'
    ): bool {
        $path = $this->path($bucket, $key);
        [$status] = $this->s3request('PUT', $path, [], ['Content-Type' => $contentType], $body);
        return $status === 200;
    }

    /**
     * Download an object. Returns raw bytes or false on failure.
     */
    public function getObject(string $bucket, string $key): string|false
    {
        [$status, $body] = $this->s3request('GET', $this->path($bucket, $key));
        return $status === 200 ? $body : false;
    }

    public function deleteObject(string $bucket, string $key): bool
    {
        [$status] = $this->s3request('DELETE', $this->path($bucket, $key));
        return $status === 204;
    }

    /**
     * List objects in a bucket, optionally filtered by prefix.
     *
     * @return array<array{key: string, size: int, last_modified: string}>
     */
    public function listObjects(string $bucket, string $prefix = ''): array
    {
        $query = ['list-type' => '2'];
        if ($prefix !== '') {
            $query['prefix'] = $prefix;
        }

        [$status, $body] = $this->s3request('GET', "/$bucket", $query);
        if ($status !== 200) {
            return [];
        }

        $xml = simplexml_load_string($body);
        $out = [];
        foreach ($xml->Contents ?? [] as $item) {
            $out[] = [
                'key'           => (string)$item->Key,
                'size'          => (int)$item->Size,
                'last_modified' => (string)$item->LastModified,
            ];
        }
        return $out;
    }

    /**
     * Generate a pre-signed download URL valid for $expires seconds.
     * The URL works without credentials — share it with clients.
     */
    public function presignedUrl(string $bucket, string $key, int $expires = 3600): string
    {
        $path  = $this->path($bucket, $key);
        $now   = gmdate('Ymd\THis\Z');
        $date  = gmdate('Ymd');
        $scope = "$date/{$this->region}/s3/aws4_request";
        $host  = $this->host();

        $query = [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => "{$this->accessKey}/$scope",
            'X-Amz-Date'          => $now,
            'X-Amz-Expires'       => (string)$expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($query);
        $qStr = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $encPath = $this->encodedPath($path);

        $canonical = implode("\n", [
            'GET',
            $encPath,
            $qStr,
            "host:$host\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $strToSign = implode("\n", [
            'AWS4-HMAC-SHA256', $now, $scope, hash('sha256', $canonical),
        ]);

        $sig = hash_hmac('sha256', $strToSign, $this->signingKey($date));
        return $this->endpoint . $encPath . '?' . $qStr . '&X-Amz-Signature=' . $sig;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function path(string $bucket, string $key): string
    {
        return '/' . $bucket . '/' . ltrim($key, '/');
    }

    /** URI-encode each path segment but preserve slashes (for Sig V4 canonical URI). */
    private function encodedPath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function host(): string
    {
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        $port = parse_url($this->endpoint, PHP_URL_PORT);
        return $port ? "$host:$port" : $host;
    }

    /** @return array{int, string} [statusCode, body] */
    private function s3request(
        string $method,
        string $path,
        array  $query        = [],
        array  $extraHeaders = [],
        string $body         = ''
    ): array {
        $now  = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $hash = hash('sha256', $body);
        $host = $this->host();

        $headers = array_merge($extraHeaders, [
            'Host'                  => $host,
            'X-Amz-Date'           => $now,
            'X-Amz-Content-Sha256' => $hash,
        ]);
        if ($body !== '') {
            $headers['Content-Length'] = (string)strlen($body);
        }

        uksort($headers, fn($a, $b) => strcasecmp($a, $b));

        $signedKeys = implode(';', array_map('strtolower', array_keys($headers)));
        $canonHdrs  = implode('', array_map(
            fn($k, $v) => strtolower($k) . ':' . trim($v) . "\n",
            array_keys($headers),
            $headers
        ));

        ksort($query);
        $qStr = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $encPath = $this->encodedPath($path);

        $canonical = implode("\n", [
            $method, $encPath, $qStr, $canonHdrs, $signedKeys, $hash,
        ]);

        $scope     = "$date/{$this->region}/s3/aws4_request";
        $strToSign = implode("\n", ['AWS4-HMAC-SHA256', $now, $scope, hash('sha256', $canonical)]);
        $sig       = hash_hmac('sha256', $strToSign, $this->signingKey($date));

        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/$scope,"
            . " SignedHeaders=$signedKeys, Signature=$sig";

        $url = $this->endpoint . $encPath . ($qStr ? "?$qStr" : '');
        return $this->curl($method, $url, $headers, $body);
    }

    private function signingKey(string $date): string
    {
        $k1 = hash_hmac('sha256', $date,           'AWS4' . $this->secretKey, true);
        $k2 = hash_hmac('sha256', $this->region,   $k1, true);
        $k3 = hash_hmac('sha256', 's3',             $k2, true);
        return  hash_hmac('sha256', 'aws4_request', $k3, true);
    }

    /** @return array{int, string} */
    private function curl(string $method, string $url, array $headers, string $body): array
    {
        $ch   = curl_init($url);
        $flat = array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $flat,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if ($body !== '' || in_array($method, ['PUT', 'DELETE'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, is_string($resp) ? $resp : ''];
    }
}
