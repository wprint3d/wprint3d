<?php

use Illuminate\Support\Str;

function millis() : float {
    return floor(microtime(true) * 1000);
}

function nowHuman() : string {
    return now()->format('Y-m-d H:i:s');
}

function containsUTF8(string $string) : bool {
    for ($index = 0; $index < strlen($string); $index++) {
        if (mb_check_encoding($string[ $index ], 'UTF-8')) {
            return true;
        }
    }

    return false;
}

?>