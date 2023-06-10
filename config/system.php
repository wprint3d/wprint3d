<?php

use App\Enums\BackupInterval;

return [
    'defaults' => [
        'machineUUID'               => null, // string
        'renderFileBlockingSecs'    => 60,   // seconds

        // Connection
        'streamMaxLengthBytes'      => 256, // bytes (maximum amount of ASCII characters in a valid G-code command)
        'negotiationWaitSecs'       => 7,   // seconds
        'negotiationTimeoutSecs'    => 3,   // seconds
        'negotiationMaxRetries'     => 3,   // times
        'commandTimeoutSecs'        => 15,  // seconds
        'runningTimeoutSecs'        => 10,  // seconds
        'lastSeenThresholdSecs'     => 7,   // seconds
        'lastSeenPollIntervalSecs'  => 5,   // seconds
        'autoSerialIntervalSecs'    => 2,   // seconds

        // Limits - 1 travel unit == 1 mm or 1 in (as configured in the Printer's firmware)
        'controlDistanceDefault'    => 10,      // travel units 
        'controlDistanceMin'        => 1,       // travel units
        'controlDistanceMax'        => 100,     // travel units
        'controlFeedrateDefault'    => 1500,    // travel units per second
        'controlFeedrateMin'        => 500,     // travel units per second
        'controlFeedrateMax'        => 10000,   // travel units per second
        'controlExtrusionFeedrate'  => 50,      // travel units per second
        'controlExtrusionMinTemp'   => 170,     // celsius degress

        // Miscelaneous
        'jobBackupInterval'              => BackupInterval::NEVER,
        'jobStatisticsQueryIntervalSecs' => 5,                      // seconds
        'terminalMaxLines'               => 512,                    // lines
        'enableHaptics'                  => true,                   // boolean

        // Advanced settings
        'debugSerial'                     => false,   // boolean
        'enableLibCamera'                 => true,    // boolean
        'jobRestorationTimeoutSecs'       => 30,      // seconds
        'jobRestorationHomingTemperature' => 180      // celsius degrees

    ]
];