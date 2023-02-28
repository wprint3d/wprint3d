<?php

namespace App\Libraries;

use finfo;

class Convert {

    public static function toDataURI(string $binaryString) : string {
        return
            'data:' .
            (new finfo(FILEINFO_MIME_TYPE))->buffer($binaryString) .
            ';base64,' .
            base64_encode($binaryString);
    }

}

?>