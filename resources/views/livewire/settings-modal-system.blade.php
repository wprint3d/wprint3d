<div>
    <form class="row g-3 text-start">
        <h5> Connection </h5>
        <hr class="m-2">

        @livewire('system-configuration', [
            'key'     => 'negotiationTimeoutSecs',
            'type'    => DataType::INTEGER,
            'label'   => 'Negotiation timeout',
            'hint'    => 'The maximum time (<b>in seconds</b>) that will be spent setting up a printer, exceeding this time will get the printer invalidated and disabled.',
            'default' => env('PRINTER_NEGOTIATION_TIMEOUT_SECS')
        ])

        @livewire('system-configuration', [
            'key'     => 'negotiationMaxRetries',
            'type'    => DataType::INTEGER,
            'label'   => 'Negotiation retry limit',
            'hint'    => 'The maximum <b>amount</b> of times that we\'ll try to set up a printer, exceeding this value will get the printer invalidated and disabled.',
            'default' => env('PRINTER_NEGOTIATION_MAX_RETRIES')
        ])

        @livewire('system-configuration', [
            'key'     => 'commandTimeoutSecs',
            'type'    => DataType::INTEGER,
            'label'   => 'Command timeout',
            'hint'    => 'The maximum time (in seconds) that will be spent waiting for a response from the printer. Exceeding this time <b>twice</b> will cause the print job to be aborted.',
            'default' => env('PRINTER_COMMAND_TIMEOUT_SECS')
        ])

        @livewire('system-configuration', [
            'key'     => 'runningTimeoutSecs',
            'type'    => DataType::INTEGER,
            'label'   => 'Busy check timeout',
            'hint'    => 'Some printers will stop responding after leaving the <b>busy</b> state. This is the maximum time (<b>in seconds</b>) that the printer would remain idle before we try forcing a command into it.',
            'default' => env('PRINTER_RUNNING_TIMEOUT_SECS')
        ])

        @livewire('system-configuration', [
            'key'     => 'lastSeenThresholdSecs',
            'type'    => DataType::INTEGER,
            'label'   => 'Last seen threshold',
            'hint'    => 'This is the interval (<b>in seconds</b>) in that the printer should produce some kind of output. If exceeded, it\'ll be marked as <b>offline</b>.',
            'default' => env('PRINTER_LAST_SEEN_ONLINE_THRESHOLD_SECS')
        ])

        @livewire('system-configuration', [
            'key'     => 'lastSeenPollIntervalSecs',
            'type'    => DataType::INTEGER,
            'label'   => 'Last seen poll interval',
            'hint'    => 'This is the interval (<b>in seconds</b>) in which the printer will be queried for a response. Typically the same as the <b>last seen threshold</b> or slightly lower.',
            'default' => env('PRINTER_LAST_SEEN_POLL_INTERVAL_SECS')
        ])

        @livewire('system-configuration', [
            'key'     => 'autoSerialIntervalsecs',
            'type'    => DataType::INTEGER,
            'label'   => 'Automatic poll interval',
            'hint'    => 'This is the interval (<b>in seconds</b>) in which idling printers will be reached for various automated polling operations, such as temperature, power supply status, firmware details, etc.',
            'default' => env('PRINTER_AUTO_SERIAL_INTERVAL_SECS')
        ])

        <h5 class="pt-3"> Limits </h5>
        <hr class="m-2">

        @livewire('system-configuration', [
            'key'     => 'controlDistanceDefault',
            'type'    => DataType::INTEGER,
            'label'   => 'Default travel distance',
            'hint'    => 'This is the <b>default</b> distance to move each axis (<b>in mm</b>) that the printer will move from the <b>Control</b> tab.',
            'default' => env('PRINTER_CONTROL_DISTANCE_DEFAULT')
        ])

        @livewire('system-configuration', [
            'key'     => 'controlDistanceMin',
            'type'    => DataType::INTEGER,
            'label'   => 'Minimum travel distance',
            'hint'    => 'This is the <b>minimum</b> distance to move each axis (<b>in mm</b>) that the printer will be allowed to move from the <b>Control</b> tab.',
            'default' => env('PRINTER_CONTROL_DISTANCE_MIN')
        ])

        @livewire('system-configuration', [
            'key'     => 'controlDistanceMax',
            'type'    => DataType::INTEGER,
            'label'   => 'Maximum travel distance',
            'hint'    => 'This is the <b>maximum</b> distance to move each axis (<b>in mm</b>) that the printer will be allowed to move from the <b>Control</b> tab.',
            'default' => env('PRINTER_CONTROL_DISTANCE_MAX')
        ])

        @livewire('system-configuration', [
            'key'     => 'controlFeedrateDefault',
            'type'    => DataType::INTEGER,
            'label'   => 'Default feedrate speed',
            'hint'    => 'This is the <b>default</b> speed to move each axis (<b>in mm/s</b>) that the printer will move from the <b>Control</b> tab.',
            'default' => env('PRINTER_CONTROL_FEEDRATE_DEFAULT')
        ])

        @livewire('system-configuration', [
            'key'     => 'controlFeedrateMin',
            'type'    => DataType::INTEGER,
            'label'   => 'Minimum feedrate speed',
            'hint'    => 'This is the <b>minimum</b> speed to move each axis (<b>in mm/s</b>) that the printer will be allowed to move from the <b>Control</b> tab.',
            'default' => env('PRINTER_CONTROL_FEEDRATE_MIN')
        ])

        @livewire('system-configuration', [
            'key'     => 'controlFeedrateMax',
            'type'    => DataType::INTEGER,
            'label'   => 'Maximum feedrate speed',
            'hint'    => 'This is the <b>maximum</b> speed to move each axis (<b>in mm/s</b>) that the printer will be allowed to move from the <b>Control</b> tab.',
            'default' => env('PRINTER_CONTROL_FEEDRATE_MAX')
        ])

        @livewire('system-configuration', [
            'key'     => 'controlExtrusionFeedrate',
            'type'    => DataType::INTEGER,
            'label'   => 'Extrusion feedrate',
            'hint'    => 'This is the <b>absolute</b> speed to extrude material when requested from the <b>Control</b> tab.',
            'default' => env('PRINTER_CONTROL_EXTRUSION_FEEDRATE')
        ])

        @livewire('system-configuration', [
            'key'     => 'controlExtrusionMinTemp',
            'type'    => DataType::INTEGER,
            'label'   => 'Minimum temperature to extrude',
            'hint'    => 'This is the <b>minimum</b> temperature required before being able to extrude material from the <b>Control</b> tab (avoids physical damage to the printer due to cold extrusion).',
            'default' => env('PRINTER_CONTROL_EXTRUSION_MIN_TEMP')
        ])

        <h5 class="pt-3"> Miscelaneous </h5>
        <hr class="m-2">

        @livewire('system-configuration', [
            'key'     => 'jobStatisticsQueryIntervalSecs',
            'type'    => DataType::INTEGER,
            'label'   => 'Statistics query interval',
            'hint'    => 'This is the <b>absolute</b> interval (<b>in seconds</b>) in which the printer statistics will be queried <b>throughout an active print job</b>.',
            'default' => env('PRINTING_STATISTICS_QUERY_INTERVAL_SECS')
        ])

        @livewire('system-configuration', [
            'key'     => 'terminalMaxLines',
            'type'    => DataType::INTEGER,
            'label'   => 'Maximum terminal lines',
            'hint'    => 'This is the <b>maximum</b> amount of lines that can be shown in the <b>Terminal</b> tab. If exceeded, extra lines are removed from oldest to newest.',
            'default' => env('TERMINAL_MAX_LINES')
        ])

        @livewire('system-configuration', [
            'key'     => 'enableHaptics',
            'type'    => DataType::BOOLEAN,
            'label'   => 'Haptic feedback',
            'hint'    => 'Whether to enable <b>haptic feedback</b> throughout the entire system. Please note that, on mobile devices, it\'ll be necessary to <b>disable the do not disturb mode</b> before this settings has any effect.',
            'default' => env('HAPTICS_ENABLED')
        ])

        <h5 class="pt-3"> Advanced settings </h5>
        <hr class="m-2">

        @livewire('system-configuration', [
            'key'     => 'debugSerial',
            'type'    => DataType::BOOLEAN,
            'label'   => 'Debug serial transactions',
            'hint'    => 'Whether to enable debug logging of all kinds of transactions running through the serial protocol. This is <b><u>EXTREMELY</u></b> taxing for your system\'s I/O throughput and has the potential to cause slowdowns, crashes and timeouts of your print jobs on slower systems such as <b>single-board computers</b>.',
            'default' => env('SERIAL_DEBUG')
        ])

        @livewire('system-configuration', [
            'key'     => 'enableLibCamera',
            'type'    => DataType::BOOLEAN,
            'label'   => 'Libcamera support',
            'hint'    => 'Whether to enable <b>libcamera support</b>. Generally, you\'ll want this setting <b>enabled</b>, however, some <b>single-board computers</b>\' kernels are broken and make the streaming process relatively taxing on the already scarce system resources. This setting lets you <b>disable</b> this feature in favor of using a <b>USB camera</b> or <b>no camera at all</b> instead.',
            'default' => env('LIB_CAMERA_ENABLED')
        ])
    </form>
</div>