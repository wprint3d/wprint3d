<?php

$autoloadPath = getenv('WPRINT3D_VENDOR_AUTOLOAD');

if ($autoloadPath && is_file($autoloadPath)) {
    require_once $autoloadPath;
}

$reader = new \App\Plugins\Support\HostMetricsReader();

echo json_encode([
    'data' => $reader->fromHost(),
]);
