<?php

namespace App\Support\FakeSerial;

class FakeSerialEmulator
{
    private const DEFAULT_STATE = [
        'bootMessagePending' => true,
        'halted' => false,
        'absoluteMode' => true,
        'extruderAbsoluteMode' => true,
        'feedrate' => 1500.0,
        'selectedTool' => 0,
        'keepaliveIntervalSecs' => 2,
        'temperatureAutoReportIntervalSecs' => 0,
        'position' => [
            'x' => 0.0,
            'y' => 0.0,
            'z' => 0.0,
            'e' => 0.0,
        ],
        'hotend' => [
            'temperature' => 24.0,
            'target' => 0.0,
        ],
        'bed' => [
            'temperature' => 24.0,
            'target' => 0.0,
        ],
        'sd' => [
            'files' => [
                ['name' => 'FAKE_BENCHY.GCO', 'size' => 184320],
                ['name' => 'CALIBRATION_CUBE.GCO', 'size' => 92160],
            ],
            'printing' => false,
            'progress' => 0,
            'size' => 184320,
        ],
    ];

    private const MACHINE_INFO = [
        'firmwareName' => 'Marlin FAKE_SERIAL_SIM',
        'sourceCodeUrl' => 'https://github.com/MarlinFirmware/Marlin',
        'protocolVersion' => '1.0',
        'machineType' => 'FakeSerial Dev Printer',
        'extruderCount' => 1,
        'axisCount' => 4,
        'uuid' => 'FAKESERIAL-DEV-PRINTER',
        'capabilities' => [
            'EEPROM' => 1,
            'AUTOREPORT_TEMP' => 1,
            'PROGRESS' => 0,
            'PRINT_JOB' => 1,
            'AUTOLEVEL' => 0,
            'RUNOUT' => 0,
            'Z_PROBE' => 0,
            'LEVELING_DATA' => 0,
            'BUILD_PERCENT' => 0,
            'SOFTWARE_POWER' => 0,
            'TOGGLE_LIGHTS' => 0,
            'CASE_LIGHT_BRIGHTNESS' => 0,
            'EMERGENCY_PARSER' => 1,
            'HOST_ACTION_COMMANDS' => 0,
            'PROMPT_SUPPORT' => 0,
            'SDCARD' => 1,
            'REPEAT' => 0,
            'SD_WRITE' => 0,
            'AUTOREPORT_SD_STATUS' => 0,
            'LONG_FILENAME' => 0,
            'LFN_WRITE' => 0,
            'CUSTOM_FIRMWARE_UPLOAD' => 0,
            'EXTENDED_M20' => 0,
            'THERMAL_PROTECTION' => 1,
            'MOTION_MODES' => 1,
            'ARCS' => 0,
            'BABYSTEPPING' => 0,
            'CHAMBER_TEMPERATURE' => 0,
            'COOLER_TEMPERATURE' => 0,
            'MEATPACK' => 0,
            'CONFIG_EXPORT' => 0,
        ],
    ];

    public function transact(string $command, array $state = []): array
    {
        $state = $this->normalizeState($state);
        $command = trim(explode(';', trim($command), 2)[0] ?? '');
        $lines = [];

        if ($state['bootMessagePending']) {
            $lines[] = $this->line('start');
            $state['bootMessagePending'] = false;
        }

        if ($command === '') {
            $lines[] = $this->line('ok');

            return $this->result($state, $lines);
        }

        if ($state['halted'] && ! in_array($this->commandName($command), ['M105', 'M112', 'M115', 'M999'], true)) {
            $lines[] = $this->line('Error:Printer halted. kill() called!');

            return $this->result($state, $lines);
        }

        $commandName = $this->commandName($command);

        switch ($commandName) {
            case 'M105':
                $lines[] = $this->line($this->temperatureReport($state));
                break;

            case 'M115':
                $lines = array_merge($lines, $this->firmwareInformationLines(), [$this->line('ok')]);
                break;

            case 'M114':
                $lines[] = $this->line(sprintf(
                    'X:%.2F Y:%.2F Z:%.2F E:%.2F Count X:0 Y:0 Z:0',
                    $state['position']['x'],
                    $state['position']['y'],
                    $state['position']['z'],
                    $state['position']['e']
                ));
                $lines[] = $this->line('ok');
                break;

            case 'M113':
                $state['keepaliveIntervalSecs'] = max(0, (int) ($this->parameterValue($command, 'S') ?? $state['keepaliveIntervalSecs']));
                $lines[] = $this->line('ok');
                break;

            case 'M155':
                $state['temperatureAutoReportIntervalSecs'] = max(0, (int) ($this->parameterValue($command, 'S') ?? 0));
                $lines[] = $this->line('ok');
                break;

            case 'M20':
                $lines[] = $this->line('Begin file list');

                foreach ($state['sd']['files'] as $file) {
                    $lines[] = $this->line(sprintf('%s %d', $file['name'], $file['size']));
                }

                $lines[] = $this->line('End file list');
                $lines[] = $this->line('ok');
                break;

            case 'M27':
                if ($state['sd']['printing']) {
                    $lines[] = $this->line(sprintf(
                        'SD printing byte %d/%d',
                        $state['sd']['progress'],
                        $state['sd']['size']
                    ));
                } else {
                    $lines[] = $this->line('Not SD printing');
                }

                $lines[] = $this->line('ok');
                break;

            case 'G90':
                $state['absoluteMode'] = true;
                $lines[] = $this->line('ok');
                break;

            case 'G91':
                $state['absoluteMode'] = false;
                $lines[] = $this->line('ok');
                break;

            case 'M82':
                $state['extruderAbsoluteMode'] = true;
                $lines[] = $this->line('ok');
                break;

            case 'M83':
                $state['extruderAbsoluteMode'] = false;
                $lines[] = $this->line('ok');
                break;

            case 'T0':
                $state['selectedTool'] = 0;
                $lines[] = $this->line('ok');
                break;

            case 'G0':
            case 'G1':
                $state = $this->applyMotion($state, $command);
                $lines[] = $this->line('ok');
                break;

            case 'G28':
                $lines = array_merge($lines, $this->busySequence($state['keepaliveIntervalSecs'], 2));
                $state['position']['x'] = 0.0;
                $state['position']['y'] = 0.0;
                $state['position']['z'] = 0.0;
                $lines[] = $this->line('ok');
                break;

            case 'M104':
                $state['hotend']['target'] = (float) ($this->parameterValue($command, 'S') ?? $state['hotend']['target']);
                $lines[] = $this->line('ok');
                break;

            case 'M140':
                $state['bed']['target'] = (float) ($this->parameterValue($command, 'S') ?? $state['bed']['target']);
                $lines[] = $this->line('ok');
                break;

            case 'M109':
                $state['hotend']['target'] = (float) ($this->parameterValue($command, 'S') ?? $state['hotend']['target']);
                $lines = array_merge($lines, $this->busyTemperatureSequence($state, 'hotend'));
                $state['hotend']['temperature'] = $state['hotend']['target'];
                $lines[] = $this->line($this->temperatureReport($state));
                break;

            case 'M190':
                $state['bed']['target'] = (float) ($this->parameterValue($command, 'S') ?? $state['bed']['target']);
                $lines = array_merge($lines, $this->busyTemperatureSequence($state, 'bed'));
                $state['bed']['temperature'] = $state['bed']['target'];
                $lines[] = $this->line($this->temperatureReport($state));
                break;

            case 'M400':
                $lines[] = $this->line('busy: processing', 150);
                $lines[] = $this->line('ok');
                break;

            case 'M112':
                $state['halted'] = true;
                $lines[] = $this->line('Error:Printer halted. kill() called!');
                break;

            case 'M999':
                $state['halted'] = false;
                $lines[] = $this->line('ok');
                break;

            default:
                $lines[] = $this->line(sprintf('Error:Unknown command: "%s"', $command));
                break;
        }

        return $this->result($state, $lines);
    }

    private function normalizeState(array $state): array
    {
        return array_replace_recursive(self::DEFAULT_STATE, $state);
    }

    private function commandName(string $command): string
    {
        $parts = preg_split('/\s+/', strtoupper(trim($command))) ?: [];

        return $parts[0] ?? '';
    }

    private function parameterValue(string $command, string $parameter): float|int|null
    {
        if (! preg_match('/(?:^|\s)'.preg_quote($parameter, '/').'(-?\d+(?:\.\d+)?)/i', $command, $matches)) {
            return null;
        }

        $value = $matches[1];

        return str_contains($value, '.') ? (float) $value : (int) $value;
    }

    private function applyMotion(array $state, string $command): array
    {
        foreach (['X', 'Y', 'Z', 'E'] as $axis) {
            $value = $this->parameterValue($command, $axis);

            if ($value === null) {
                continue;
            }

            $key = strtolower($axis);
            $isAbsolute = $axis === 'E'
                ? $state['extruderAbsoluteMode']
                : $state['absoluteMode'];

            $state['position'][$key] = $isAbsolute
                ? (float) $value
                : (float) $state['position'][$key] + (float) $value;
        }

        $feedrate = $this->parameterValue($command, 'F');

        if ($feedrate !== null) {
            $state['feedrate'] = (float) $feedrate;
        }

        return $state;
    }

    private function busySequence(int $keepaliveIntervalSecs, int $count = 2): array
    {
        $delayMs = max(250, $keepaliveIntervalSecs * 250);
        $lines = [];

        for ($index = 0; $index < $count; $index++) {
            $lines[] = $this->line('busy: processing', $delayMs);
        }

        return $lines;
    }

    private function busyTemperatureSequence(array $state, string $device): array
    {
        $lines = $this->busySequence((int) $state['keepaliveIntervalSecs'], 2);
        $temperatureKey = $device === 'hotend' ? 'hotend' : 'bed';
        $current = (float) $state[$temperatureKey]['temperature'];
        $target = (float) $state[$temperatureKey]['target'];
        $state[$temperatureKey]['temperature'] = $current + (($target - $current) / 2);
        $lines[] = $this->line($this->temperatureReport($state), 200);

        return $lines;
    }

    private function firmwareInformationLines(): array
    {
        $lines = [
            $this->line(sprintf(
                'FIRMWARE_NAME:%s SOURCE_CODE_URL:%s PROTOCOL_VERSION:%s MACHINE_TYPE:%s EXTRUDER_COUNT:%d UUID:%s',
                self::MACHINE_INFO['firmwareName'],
                self::MACHINE_INFO['sourceCodeUrl'],
                self::MACHINE_INFO['protocolVersion'],
                self::MACHINE_INFO['machineType'],
                self::MACHINE_INFO['extruderCount'],
                self::MACHINE_INFO['uuid']
            )),
        ];

        foreach (self::MACHINE_INFO['capabilities'] as $capability => $value) {
            $lines[] = $this->line("Cap:{$capability}:{$value}");
        }

        return $lines;
    }

    private function temperatureReport(array $state): string
    {
        return sprintf(
            'ok T:%.2F /%.2F B:%.2F /%.2F T0:%.2F /%.2F @:%d B@:%d',
            $state['hotend']['temperature'],
            $state['hotend']['target'],
            $state['bed']['temperature'],
            $state['bed']['target'],
            $state['hotend']['temperature'],
            $state['hotend']['target'],
            $state['hotend']['target'] > 0 ? 127 : 0,
            $state['bed']['target'] > 0 ? 127 : 0
        );
    }

    private function line(string $text, int $delayMs = 0): array
    {
        return [
            'text' => $text,
            'delayMs' => $delayMs,
        ];
    }

    private function result(array $state, array $lines): array
    {
        return [
            'state' => $state,
            'lines' => $lines,
            'response' => implode(PHP_EOL, array_map(
                static fn (array $line): string => $line['text'],
                $lines
            )),
        ];
    }
}
