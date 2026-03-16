<?php

$input = json_decode(stream_get_contents(STDIN), true);

echo json_encode([
    'data' => [
        'message' => 'Hello World booted.',
        'input' => $input,
    ],
]);
