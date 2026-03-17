<?php
/**
 * Test script to verify OPcache configuration
 * Run with: php internal/test-opcache.php
 */

echo "=== OPcache Configuration Test ===\n";

if (!extension_loaded('Zend OPcache')) {
    echo "FAIL: OPcache extension is not loaded\n";
    exit(1);
}

echo "PASS: OPcache extension is loaded\n";

$config = opcache_get_configuration();
$status = opcache_get_status();

$tests = [
    'opcache.enable' => ['expected' => true, 'actual' => $config['directives']['opcache.enable']],
    'opcache.enable_cli' => ['expected' => true, 'actual' => $config['directives']['opcache.enable_cli']],
    'opcache.memory_consumption' => ['expected' => 134217728, 'actual' => $config['directives']['opcache.memory_consumption']],
    'opcache.jit_buffer_size' => ['expected' => 67108864, 'actual' => $config['directives']['opcache.jit_buffer_size']],
];

$failed = false;
foreach ($tests as $key => $test) {
    if ($test['actual'] !== $test['expected']) {
        echo sprintf(
            "WARN: %s is %s (expected %s)\n",
            $key,
            var_export($test['actual'], true),
            var_export($test['expected'], true)
        );
        $failed = true;
    }
}

if (!$failed) {
    echo "PASS: All OPcache settings are correct\n";
}

echo "\n=== OPcache Status ===\n";
echo sprintf("Memory used: %s / %s\n",
    formatBytes($status['memory_usage']['used_memory']),
    formatBytes($status['memory_usage']['used_memory'] + $status['memory_usage']['free_memory'])
);
echo sprintf("Cached scripts: %d / %d\n",
    $status['opcache_statistics']['num_cached_scripts'],
    $config['directives']['opcache.max_accelerated_files']
);

function formatBytes($size, $precision = 2) {
    $base = log($size, 1024);
    $suffixes = ['B', 'KB', 'MB', 'GB'];
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
}

exit(0);
