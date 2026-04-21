<?php

/**
 * Debug instrumentation - appends NDJSON to .cursor/debug.log
 * Remove after debugging is complete.
 */
if (!function_exists('debug_log')) {
    function debug_log(string $message, array $data = [], ?string $hypothesisId = null): void
    {
        $payload = [
            'location' => $data['_loc'] ?? 'unknown',
            'message' => $message,
            'data' => array_diff_key($data, ['_loc' => 1]),
            'timestamp' => (int) (microtime(true) * 1000),
        ];
        if ($hypothesisId !== null) {
            $payload['hypothesisId'] = $hypothesisId;
        }
        $root = function_exists('base_path') ? dirname(base_path()) : dirname(__DIR__, 3);
        $dir = $root . DIRECTORY_SEPARATOR . '.cursor';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . 'debug.log';
        @file_put_contents(
            $path,
            json_encode($payload) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
