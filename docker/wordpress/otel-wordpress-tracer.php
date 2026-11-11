<?php
/**
 * Lightweight W3C OpenTelemetry Tracer for WordPress & MariaDB ($wpdb)
 * Propagates traceparent from Nginx (ngx_otel_module), records SQL query spans,
 * and exports OTLP/HTTP JSON asynchronously after fastcgi_finish_request().
 */
if (php_sapi_name() === 'cli') {
    return;
}

// Enable WordPress SQL query timing collection so each DB query becomes a child span
if (!defined('SAVEQUERIES')) {
    define('SAVEQUERIES', true);
}

$GLOBALS['__otel_req_start_ns'] = (int)(microtime(true) * 1e9);

register_shutdown_function(static function (): void {
    $endNs = (int)(microtime(true) * 1e9);
    $startNs = $GLOBALS['__otel_req_start_ns'] ?? ($endNs - 1000000);

    // Flush response to client first so OTLP export adds 0ms latency
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }

    $endpoint = getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://alloy:4318';
    $traceparent = $_SERVER['HTTP_TRACEPARENT'] ?? '';

    $traceId = '';
    $parentSpanId = '';
    if (preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/i', $traceparent, $matches)) {
        $traceId = strtolower($matches[1]);
        $parentSpanId = strtolower($matches[2]);
    } else {
        try {
            $traceId = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            return;
        }
    }

    try {
        $wpSpanId = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
    $statusCode = http_response_code() ?: 200;

    $wpSpan = [
        'traceId' => $traceId,
        'spanId' => $wpSpanId,
        'name' => $method . ' ' . $uri,
        'kind' => 2, // SPAN_KIND_SERVER
        'startTimeUnixNano' => (string)$startNs,
        'endTimeUnixNano' => (string)$endNs,
        'attributes' => [
            ['key' => 'http.request.method', 'value' => ['stringValue' => $method]],
            ['key' => 'url.path', 'value' => ['stringValue' => $uri]],
            ['key' => 'http.response.status_code', 'value' => ['intValue' => (string)$statusCode]],
            ['key' => 'php.version', 'value' => ['stringValue' => PHP_VERSION]],
        ],
        'status' => [
            'code' => $statusCode >= 500 ? 2 : 1,
        ],
    ];
    if ($parentSpanId !== '') {
        $wpSpan['parentSpanId'] = $parentSpanId;
    }

    // Collect MariaDB SQL query child spans captured by WordPress $wpdb
    $dbSpans = [];
    if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb']) && !empty($GLOBALS['wpdb']->queries) && is_array($GLOBALS['wpdb']->queries)) {
        $cursorNs = $startNs + 500000;
        $maxQueries = 25;
        $count = 0;
        foreach ($GLOBALS['wpdb']->queries as $q) {
            if ($count++ >= $maxQueries || !is_array($q) || empty($q[0])) {
                break;
            }
            $sql = preg_replace('/\s+/', ' ', trim((string)$q[0]));
            $durationSec = isset($q[1]) ? (float)$q[1] : 0.0005;
            $durationNs = max(10000, (int)($durationSec * 1e9));
            $qStartNs = isset($q[3]) && $q[3] > 0 ? (int)($q[3] * 1e9) : $cursorNs;
            $qEndNs = min($endNs, $qStartNs + $durationNs);
            $cursorNs = $qEndNs + 10000;

            $op = strtoupper(strtok($sql, ' ') ?: 'QUERY');
            try {
                $dbSpanId = bin2hex(random_bytes(8));
            } catch (Throwable $e) {
                continue;
            }

            $dbSpans[] = [
                'traceId' => $traceId,
                'spanId' => $dbSpanId,
                'parentSpanId' => $wpSpanId,
                'name' => 'SQL ' . $op,
                'kind' => 3, // SPAN_KIND_CLIENT
                'startTimeUnixNano' => (string)$qStartNs,
                'endTimeUnixNano' => (string)$qEndNs,
                'attributes' => [
                    ['key' => 'db.system', 'value' => ['stringValue' => 'mariadb']],
                    ['key' => 'db.name', 'value' => ['stringValue' => getenv('WORDPRESS_DB_NAME') ?: 'wordpress']],
                    ['key' => 'db.operation', 'value' => ['stringValue' => $op]],
                    ['key' => 'db.statement', 'value' => ['stringValue' => substr($sql, 0, 240)]],
                ],
                'status' => ['code' => 1],
            ];
        }
    }

    $resourceSpans = [
        [
            'resource' => [
                'attributes' => [
                    ['key' => 'service.name', 'value' => ['stringValue' => 'wordpress-php-fpm']],
                    ['key' => 'service.namespace', 'value' => ['stringValue' => 'wordpress-production']],
                ],
            ],
            'scopeSpans' => [
                [
                    'scope' => ['name' => 'platform.wordpress.otel.tracer', 'version' => '1.0.0'],
                    'spans' => [$wpSpan],
                ],
            ],
        ],
    ];

    if (!empty($dbSpans)) {
        $resourceSpans[] = [
            'resource' => [
                'attributes' => [
                    ['key' => 'service.name', 'value' => ['stringValue' => 'mariadb-database']],
                    ['key' => 'service.namespace', 'value' => ['stringValue' => 'wordpress-production']],
                ],
            ],
            'scopeSpans' => [
                [
                    'scope' => ['name' => 'platform.wordpress.wpdb.tracer', 'version' => '1.0.0'],
                    'spans' => $dbSpans,
                ],
            ],
        ];
    }

    $payload = json_encode(['resourceSpans' => $resourceSpans]);
    if ($payload === false) {
        return;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
            'content' => $payload,
            'timeout' => 0.4,
            'ignore_errors' => true,
        ],
    ]);
    @file_get_contents(rtrim($endpoint, '/') . '/v1/traces', false, $ctx);
});
