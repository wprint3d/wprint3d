<?php

namespace App\Support\FakeSerial;

use App\Enums\Marlin;
use ReflectionClass;

class FakeSerialEmulator
{
    private const DEFAULT_STATE = [
        'bootMessagePending' => true,
        'halted' => false,
        'waitingForUser' => false,
        'absoluteMode' => true,
        'extruderAbsoluteMode' => true,
        'lengthUnit' => 'mm',
        'temperatureUnit' => 'C',
        'feedrate' => 1500.0,
        'feedratePercentage' => 100,
        'flowPercentage' => 100,
        'selectedTool' => 0,
        'lineNumber' => 0,
        'debugFlags' => 0,
        'keepaliveIntervalSecs' => 2,
        'temperatureAutoReportIntervalSecs' => 0,
        'positionAutoReportIntervalSecs' => 0,
        'lastAutoReportAt' => [
            'temperature' => null,
            'position' => null,
        ],
        'powerOn' => true,
        'steppersEnabled' => true,
        'endstopsEnabled' => true,
        'lcdMessage' => '',
        'serialMessages' => [],
        'position' => [
            'x' => 0.0,
            'y' => 0.0,
            'z' => 0.0,
            'e' => 0.0,
        ],
        'homeOffset' => [
            'x' => 0.0,
            'y' => 0.0,
            'z' => 0.0,
        ],
        'hotend' => [
            'temperature' => 24.0,
            'target' => 0.0,
        ],
        'bed' => [
            'temperature' => 24.0,
            'target' => 0.0,
        ],
        'chamber' => [
            'temperature' => 24.0,
            'target' => 0.0,
        ],
        'fans' => [
            [
                'speed' => 0,
            ],
        ],
        'lights' => [
            'r' => 0,
            'g' => 0,
            'b' => 0,
            'w' => 0,
            'brightness' => 0,
        ],
        'settings' => [
            'stepsPerUnit' => [
                'x' => 80.0,
                'y' => 80.0,
                'z' => 400.0,
                'e' => 93.0,
            ],
            'maxFeedrate' => [
                'x' => 200.0,
                'y' => 200.0,
                'z' => 12.0,
                'e' => 25.0,
            ],
            'acceleration' => [
                'print' => 500.0,
                'retract' => 5000.0,
                'travel' => 1000.0,
            ],
            'advanced' => [
                'minimumFeedrate' => 0.0,
                'minimumTravelFeedrate' => 0.0,
                'minimumSegmentTimeUs' => 20000.0,
                'xJerk' => 10.0,
                'yJerk' => 10.0,
                'zJerk' => 0.4,
                'eJerk' => 5.0,
            ],
        ],
        'sd' => [
            'mounted' => true,
            'selectedFile' => null,
            'files' => [
                ['name' => 'FAKE_BENCHY.GCO', 'size' => 184320],
                ['name' => 'CALIBRATION_CUBE.GCO', 'size' => 92160],
            ],
            'printing' => false,
            'progress' => 0,
            'size' => 184320,
            'elapsedSeconds' => 0,
        ],
        'savedSettings' => null,
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
            'PROGRESS' => 1,
            'PRINT_JOB' => 1,
            'AUTOLEVEL' => 0,
            'RUNOUT' => 0,
            'Z_PROBE' => 0,
            'LEVELING_DATA' => 0,
            'BUILD_PERCENT' => 1,
            'SOFTWARE_POWER' => 1,
            'TOGGLE_LIGHTS' => 1,
            'CASE_LIGHT_BRIGHTNESS' => 1,
            'EMERGENCY_PARSER' => 1,
            'HOST_ACTION_COMMANDS' => 1,
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

    private const HALT_WHITELIST = ['M105', 'M112', 'M115', 'M119', 'M999'];

    private static ?array $knownCommands = null;

    public function transact(string $command, array $state = []): array
    {
        $state = $this->normalizeState($state);
        $command = trim(explode(';', trim($command), 2)[0] ?? '');
        $lines = [];

        if ($state['bootMessagePending']) {
            $lines[] = $this->line('start');
            $state['bootMessagePending'] = false;
        }

        $lines = array_merge($lines, $this->autoReportLines($state));

        if ($command === '') {
            $lines[] = $this->line('ok');

            return $this->result($state, $lines);
        }

        $commandName = $this->commandName($command);

        if ($state['halted'] && ! in_array($commandName, self::HALT_WHITELIST, true)) {
            $lines[] = $this->line('Error:Printer halted. kill() called!');

            return $this->result($state, $lines);
        }

        switch ($commandName) {
            case 'G0':
            case 'G1':
                $state = $this->applyMotion($state, $command);
                $lines[] = $this->line('ok');
                break;

            case 'G4':
                $delayMs = $this->dwellDelayMs($command);
                if ($delayMs > 0) {
                    $lines[] = $this->line('busy: processing', $delayMs);
                }
                $lines[] = $this->line('ok');
                break;

            case 'G20':
                $state['lengthUnit'] = 'in';
                $lines[] = $this->line('ok');
                break;

            case 'G21':
                $state['lengthUnit'] = 'mm';
                $lines[] = $this->line('ok');
                break;

            case 'G28':
                $lines = array_merge($lines, $this->busySequence((int) $state['keepaliveIntervalSecs'], 2));
                $state = $this->applyHoming($state, $command);
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

            case 'G92':
                $state = $this->applySetPosition($state, $command);
                $lines[] = $this->line('ok');
                break;

            case 'M17':
                $state['steppersEnabled'] = true;
                $lines[] = $this->line('ok');
                break;

            case 'M18':
            case 'M84':
                $state['steppersEnabled'] = false;
                $lines[] = $this->line('ok');
                break;

            case 'M20':
                if (! $state['sd']['mounted']) {
                    $lines[] = $this->line('Error:No SD card');
                    break;
                }

                $lines[] = $this->line('Begin file list');

                foreach ($state['sd']['files'] as $file) {
                    $lines[] = $this->line(sprintf('%s %d', $file['name'], $file['size']));
                }

                $lines[] = $this->line('End file list');
                $lines[] = $this->line('ok');
                break;

            case 'M21':
                $state['sd']['mounted'] = true;
                $lines[] = $this->line('SD card ok');
                $lines[] = $this->line('ok');
                break;

            case 'M22':
                $state['sd']['mounted'] = false;
                $state['sd']['selectedFile'] = null;
                $state['sd']['printing'] = false;
                $state['sd']['progress'] = 0;
                $lines[] = $this->line('ok');
                break;

            case 'M23':
                $selected = $this->selectSdFile($state, $command);

                if ($selected === null) {
                    $lines[] = $this->line('Error:open failed, File not found');
                    break;
                }

                $state['sd']['selectedFile'] = $selected['name'];
                $state['sd']['size'] = $selected['size'];
                $state['sd']['progress'] = 0;
                $lines[] = $this->line(sprintf('File opened:%s Size:%d', $selected['name'], $selected['size']));
                $lines[] = $this->line('File selected');
                $lines[] = $this->line('ok');
                break;

            case 'M24':
                if ($state['sd']['selectedFile'] === null) {
                    $lines[] = $this->line('Error:No file selected');
                    break;
                }

                $state['sd']['printing'] = true;
                $lines[] = $this->line('ok');
                break;

            case 'M25':
                $state['sd']['printing'] = false;
                $lines[] = $this->line('ok');
                break;

            case 'M26':
                $state['sd']['progress'] = max(0, (int) ($this->parameterValue($command, 'S') ?? $state['sd']['progress']));
                $lines[] = $this->line('ok');
                break;

            case 'M27':
                $state = $this->tickSdProgress($state);

                if ($state['sd']['printing']) {
                    $lines[] = $this->line(sprintf('SD printing byte %d/%d', $state['sd']['progress'], $state['sd']['size']));
                } else {
                    $lines[] = $this->line('Not SD printing');
                }

                $lines[] = $this->line('ok');
                break;

            case 'M31':
                $lines[] = $this->line(sprintf('%d:%02d', intdiv((int) $state['sd']['elapsedSeconds'], 60), $state['sd']['elapsedSeconds'] % 60));
                $lines[] = $this->line('ok');
                break;

            case 'M75':
                $state['sd']['printing'] = true;
                $lines[] = $this->line('ok');
                break;

            case 'M76':
                $state['sd']['printing'] = false;
                $lines[] = $this->line('ok');
                break;

            case 'M77':
                $state['sd']['printing'] = false;
                $state['sd']['elapsedSeconds'] = 0;
                $lines[] = $this->line('ok');
                break;

            case 'M78':
                $lines[] = $this->line(sprintf('Prints:1 Finished:1 Failed:0 Total print time:%d', $state['sd']['elapsedSeconds']));
                $lines[] = $this->line('ok');
                break;

            case 'M80':
                $state['powerOn'] = true;
                $lines[] = $this->line('ok');
                break;

            case 'M81':
                $state['powerOn'] = false;
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

            case 'M92':
                $state = $this->applyAxisSettings($state, $command, 'stepsPerUnit');
                $lines[] = $this->line('ok');
                break;

            case 'M104':
                $state['hotend']['target'] = (float) ($this->parameterValue($command, 'S') ?? $state['hotend']['target']);
                $lines[] = $this->line('ok');
                break;

            case 'M105':
                $lines[] = $this->line($this->temperatureReport($state));
                break;

            case 'M106':
                $state = $this->setFanState($state, $command, false);
                $lines[] = $this->line('ok');
                break;

            case 'M107':
                $state = $this->setFanState($state, $command, true);
                $lines[] = $this->line('ok');
                break;

            case 'M108':
                $state['waitingForUser'] = false;
                $lines[] = $this->line('ok');
                break;

            case 'M109':
                $state = $this->applyTargetTemperature($state, $command, 'hotend');
                $lines = array_merge($lines, $this->blockingTemperatureSequence($state, $command, 'hotend'));
                break;

            case 'M110':
                $state['lineNumber'] = max(0, (int) ($this->parameterValue($command, 'N') ?? $state['lineNumber']));
                $lines[] = $this->line('ok');
                break;

            case 'M111':
                $state['debugFlags'] = max(0, (int) ($this->parameterValue($command, 'S') ?? $state['debugFlags']));
                $lines[] = $this->line(sprintf('echo:DEBUG:%d', $state['debugFlags']));
                $lines[] = $this->line('ok');
                break;

            case 'M112':
                $state['halted'] = true;
                $lines[] = $this->line('Error:Printer halted. kill() called!');
                break;

            case 'M113':
                $state['keepaliveIntervalSecs'] = max(0, (int) ($this->parameterValue($command, 'S') ?? $state['keepaliveIntervalSecs']));
                $lines[] = $this->line('ok');
                break;

            case 'M114':
                $lines[] = $this->line($this->positionReport($state));
                $lines[] = $this->line('ok');
                break;

            case 'M115':
                $lines = array_merge($lines, $this->firmwareInformationLines(), [$this->line('ok')]);
                break;

            case 'M117':
                $state['lcdMessage'] = $this->messageAfterCommand($command);
                $lines[] = $this->line('ok');
                break;

            case 'M118':
                $message = $this->hostMessage($command);
                $state['serialMessages'][] = $message;
                $lines[] = $this->line($message);
                $lines[] = $this->line('ok');
                break;

            case 'M119':
                $lines[] = $this->line('Reporting endstop status');
                $lines[] = $this->line('x_min: open');
                $lines[] = $this->line('y_min: open');
                $lines[] = $this->line('z_min: open');
                $lines[] = $this->line('ok');
                break;

            case 'M120':
                $state['endstopsEnabled'] = true;
                $lines[] = $this->line('ok');
                break;

            case 'M121':
                $state['endstopsEnabled'] = false;
                $lines[] = $this->line('ok');
                break;

            case 'M123':
                $lines[] = $this->line(sprintf('E0:%d RPM', (int) round(($state['fans'][0]['speed'] / 255) * 4800)));
                $lines[] = $this->line('ok');
                break;

            case 'M140':
                $state['bed']['target'] = (float) ($this->parameterValue($command, 'S') ?? $state['bed']['target']);
                $lines[] = $this->line('ok');
                break;

            case 'M149':
                $state['temperatureUnit'] = $this->temperatureUnitFromCommand($command, $state['temperatureUnit']);
                $lines[] = $this->line('ok');
                break;

            case 'M150':
                $state['lights'] = [
                    'r' => (int) ($this->parameterValue($command, 'R') ?? $state['lights']['r']),
                    'g' => (int) ($this->parameterValue($command, 'U') ?? $this->parameterValue($command, 'G') ?? $state['lights']['g']),
                    'b' => (int) ($this->parameterValue($command, 'B') ?? $state['lights']['b']),
                    'w' => (int) ($this->parameterValue($command, 'W') ?? $state['lights']['w']),
                    'brightness' => (int) ($this->parameterValue($command, 'P') ?? $state['lights']['brightness']),
                ];
                $lines[] = $this->line('ok');
                break;

            case 'M154':
                $state['positionAutoReportIntervalSecs'] = max(0, (int) ($this->parameterValue($command, 'S') ?? 0));
                $state['lastAutoReportAt']['position'] = microtime(true);
                $lines[] = $this->line('ok');
                break;

            case 'M155':
                $state['temperatureAutoReportIntervalSecs'] = max(0, (int) ($this->parameterValue($command, 'S') ?? 0));
                $state['lastAutoReportAt']['temperature'] = microtime(true);
                $lines[] = $this->line('ok');
                break;

            case 'M190':
                $state = $this->applyTargetTemperature($state, $command, 'bed');
                $lines = array_merge($lines, $this->blockingTemperatureSequence($state, $command, 'bed'));
                break;

            case 'M191':
                $state = $this->applyTargetTemperature($state, $command, 'chamber');
                $lines = array_merge($lines, $this->blockingTemperatureSequence($state, $command, 'chamber'));
                break;

            case 'M203':
                $state = $this->applyAxisSettings($state, $command, 'maxFeedrate');
                $lines[] = $this->line('ok');
                break;

            case 'M204':
                $state['settings']['acceleration']['print'] = (float) ($this->parameterValue($command, 'P') ?? $state['settings']['acceleration']['print']);
                $state['settings']['acceleration']['retract'] = (float) ($this->parameterValue($command, 'R') ?? $state['settings']['acceleration']['retract']);
                $state['settings']['acceleration']['travel'] = (float) ($this->parameterValue($command, 'T') ?? $state['settings']['acceleration']['travel']);
                $lines[] = $this->line('ok');
                break;

            case 'M205':
                $state['settings']['advanced']['minimumFeedrate'] = (float) ($this->parameterValue($command, 'S') ?? $state['settings']['advanced']['minimumFeedrate']);
                $state['settings']['advanced']['minimumTravelFeedrate'] = (float) ($this->parameterValue($command, 'T') ?? $state['settings']['advanced']['minimumTravelFeedrate']);
                $state['settings']['advanced']['minimumSegmentTimeUs'] = (float) ($this->parameterValue($command, 'B') ?? $state['settings']['advanced']['minimumSegmentTimeUs']);
                $state['settings']['advanced']['xJerk'] = (float) ($this->parameterValue($command, 'X') ?? $state['settings']['advanced']['xJerk']);
                $state['settings']['advanced']['yJerk'] = (float) ($this->parameterValue($command, 'Y') ?? $state['settings']['advanced']['yJerk']);
                $state['settings']['advanced']['zJerk'] = (float) ($this->parameterValue($command, 'Z') ?? $state['settings']['advanced']['zJerk']);
                $state['settings']['advanced']['eJerk'] = (float) ($this->parameterValue($command, 'E') ?? $state['settings']['advanced']['eJerk']);
                $lines[] = $this->line('ok');
                break;

            case 'M206':
                foreach (['X', 'Y', 'Z'] as $axis) {
                    $value = $this->parameterValue($command, $axis);

                    if ($value === null) {
                        continue;
                    }

                    $state['homeOffset'][strtolower($axis)] = $this->normalizeLength((float) $value, $state['lengthUnit']);
                }
                $lines[] = $this->line('ok');
                break;

            case 'M220':
                $state['feedratePercentage'] = max(1, (int) ($this->parameterValue($command, 'S') ?? $state['feedratePercentage']));
                $lines[] = $this->line('ok');
                break;

            case 'M221':
                $state['flowPercentage'] = max(1, (int) ($this->parameterValue($command, 'S') ?? $state['flowPercentage']));
                $lines[] = $this->line('ok');
                break;

            case 'M400':
                $lines[] = $this->line('busy: processing', 150);
                $lines[] = $this->line('ok');
                break;

            case 'M500':
                $state['savedSettings'] = [
                    'lengthUnit' => $state['lengthUnit'],
                    'temperatureUnit' => $state['temperatureUnit'],
                    'settings' => $state['settings'],
                    'homeOffset' => $state['homeOffset'],
                    'feedratePercentage' => $state['feedratePercentage'],
                    'flowPercentage' => $state['flowPercentage'],
                ];
                $lines[] = $this->line('Settings Stored');
                $lines[] = $this->line('ok');
                break;

            case 'M501':
                if ($state['savedSettings'] !== null) {
                    $state['lengthUnit'] = $state['savedSettings']['lengthUnit'];
                    $state['temperatureUnit'] = $state['savedSettings']['temperatureUnit'];
                    $state['settings'] = $state['savedSettings']['settings'];
                    $state['homeOffset'] = $state['savedSettings']['homeOffset'];
                    $state['feedratePercentage'] = $state['savedSettings']['feedratePercentage'];
                    $state['flowPercentage'] = $state['savedSettings']['flowPercentage'];
                }
                $lines[] = $this->line('echo:Settings Loaded');
                $lines[] = $this->line('ok');
                break;

            case 'M502':
                $state['settings'] = self::DEFAULT_STATE['settings'];
                $state['homeOffset'] = self::DEFAULT_STATE['homeOffset'];
                $state['lengthUnit'] = self::DEFAULT_STATE['lengthUnit'];
                $state['temperatureUnit'] = self::DEFAULT_STATE['temperatureUnit'];
                $state['feedratePercentage'] = self::DEFAULT_STATE['feedratePercentage'];
                $state['flowPercentage'] = self::DEFAULT_STATE['flowPercentage'];
                $lines[] = $this->line('echo:Hardcoded Default Settings Loaded');
                $lines[] = $this->line('ok');
                break;

            case 'M503':
                $lines = array_merge($lines, $this->reportSettingsLines($state), [$this->line('ok')]);
                break;

            case 'M504':
                $lines[] = $this->line('echo:EEPROM OK');
                $lines[] = $this->line('ok');
                break;

            case 'M550':
                $lines[] = $this->line(sprintf('echo:Machine Name: %s', self::MACHINE_INFO['machineType']));
                $lines[] = $this->line('ok');
                break;

            case 'M575':
                $lines[] = $this->line('echo:Baud rate management unavailable in FakeSerial');
                $lines[] = $this->line('ok');
                break;

            case 'M999':
                $state['halted'] = false;
                $state['waitingForUser'] = false;
                $lines[] = $this->line('ok');
                break;

            case 'T0':
                $state['selectedTool'] = 0;
                $lines[] = $this->line('ok');
                break;

            default:
                if ($this->handleExtendedCommand($commandName, $command, $state, $lines)) {
                    break;
                }

                if ($this->isKnownMarlinCommand($commandName)) {
                    $lines[] = $this->line(sprintf(
                        'Error:Unsupported FakeSerial command: "%s" (%s)',
                        $commandName,
                        Marlin::getLabel($commandName)
                    ));
                } else {
                    $lines[] = $this->line(sprintf('Error:Unknown command: "%s"', $command));
                }
                break;
        }

        return $this->result($state, $lines);
    }

    private function normalizeState(array $state): array
    {
        $state = array_replace_recursive(self::DEFAULT_STATE, $state);

        $state['lastAutoReportAt']['temperature'] ??= null;
        $state['lastAutoReportAt']['position'] ??= null;
        $state['savedSettings'] ??= null;

        return $state;
    }

    private function handleExtendedCommand(string $commandName, string $command, array &$state, array &$lines): bool
    {
        switch ($commandName) {
            case 'G2':
            case 'G3':
            case 'G5':
            case 'G6':
                $state['motion']['lastComplexMove'] = $commandName;
                $state = $this->applyMotion($state, $command);
                $lines[] = $this->line('ok');
                return true;

            case 'G10':
                $state['retraction']['length'] ??= 1.0;
                $state['retraction']['active'] = true;
                $state['position']['e'] -= (float) $state['retraction']['length'];
                $lines[] = $this->line('ok');
                return true;

            case 'G11':
                $state['retraction']['length'] ??= 1.0;
                $state['retraction']['active'] = false;
                $state['position']['e'] += (float) $state['retraction']['length'];
                $lines[] = $this->line('ok');
                return true;

            case 'G12':
                $state['maintenance']['lastNozzleCleanAt'] = microtime(true);
                $lines = array_merge($lines, $this->busySequence((int) $state['keepaliveIntervalSecs'], 2));
                $lines[] = $this->line('ok');
                return true;

            case 'G17':
                $state['workspace']['plane'] = 'XY';
                $lines[] = $this->line('ok');
                return true;

            case 'G19':
                $state['workspace']['plane'] = 'YZ';
                $lines[] = $this->line('ok');
                return true;

            case 'G26':
                $state['leveling']['meshValidationActive'] = true;
                $lines = array_merge($lines, $this->busySequence((int) $state['keepaliveIntervalSecs'], 2));
                $lines[] = $this->line('ok');
                return true;

            case 'G27':
                $state['motion']['parked'] = true;
                $state['position']['x'] = 0.0;
                $state['position']['y'] = 0.0;
                $state['position']['z'] = max($state['position']['z'], 5.0);
                $lines[] = $this->line('ok');
                return true;

            case 'G29':
                $state['leveling']['enabled'] = true;
                $state['leveling']['meshLoaded'] = true;
                $state['leveling']['mesh'] = [[0.0, 0.0], [0.0, 0.0]];
                $lines = array_merge($lines, $this->busySequence((int) $state['keepaliveIntervalSecs'], 3));
                $lines[] = $this->line('ok');
                return true;

            case 'G30':
                $state['probe']['lastProbe'] = [
                    'x' => $state['position']['x'],
                    'y' => $state['position']['y'],
                    'z' => 0.0,
                ];
                $lines[] = $this->line(sprintf('Bed X:%.2F Y:%.2F Z:%.2F', $state['position']['x'], $state['position']['y'], 0.0));
                $lines[] = $this->line('ok');
                return true;

            case 'G31':
                $state['probe']['docked'] = true;
                $lines[] = $this->line('ok');
                return true;

            case 'G32':
                $state['probe']['docked'] = false;
                $lines[] = $this->line('ok');
                return true;

            case 'G33':
            case 'G34':
            case 'G35':
            case 'G76':
            case 'G425':
                $state['calibration']['lastRoutine'] = $commandName;
                $lines = array_merge($lines, $this->busySequence((int) $state['keepaliveIntervalSecs'], 3));
                $lines[] = $this->line('ok');
                return true;

            case 'G38':
                $state['probe']['lastProbeTarget'] = $command;
                $state = $this->applyMotion($state, $command);
                $lines[] = $this->line('ok');
                return true;

            case 'G42':
                $state['leveling']['lastMeshMove'] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'G53':
                $state['workspace']['machineCoordinates'] = true;
                $lines[] = $this->line('ok');
                return true;

            case 'G54':
            case 'G59':
                $state['workspace']['active'] = $commandName;
                $lines[] = $this->line('ok');
                return true;

            case 'G60':
                $slot = (int) ($this->parameterValue($command, 'S') ?? 0);
                $state['workspace']['storedPositions'][$slot] = $state['position'];
                $lines[] = $this->line('ok');
                return true;

            case 'G61':
                $slot = (int) ($this->parameterValue($command, 'S') ?? 0);
                if (isset($state['workspace']['storedPositions'][$slot])) {
                    $state['position'] = $state['workspace']['storedPositions'][$slot];
                }
                $lines[] = $this->line('ok');
                return true;

            case 'G80':
                $state['motion']['lastComplexMove'] = null;
                $lines[] = $this->line('ok');
                return true;

            case 'M0':
            case 'M1':
                $state['waitingForUser'] = true;
                $lines[] = $this->line('echo:busy: paused for user');
                $lines[] = $this->line('ok');
                return true;

            case 'M3':
            case 'M4':
            case 'M5':
                $state['spindle']['mode'] = $commandName === 'M3' ? 'cw' : ($commandName === 'M4' ? 'ccw' : 'off');
                $state['spindle']['power'] = (int) ($this->parameterValue($command, 'S') ?? $state['spindle']['power'] ?? 0);
                $lines[] = $this->line('ok');
                return true;

            case 'M7':
            case 'M9':
                $state['coolant']['mist'] = $commandName === 'M7';
                if ($commandName === 'M9') {
                    $state['coolant']['mist'] = false;
                    $state['coolant']['flood'] = false;
                }
                $lines[] = $this->line('ok');
                return true;

            case 'M10':
            case 'M11':
                $state['blower']['enabled'] = $commandName === 'M10';
                $lines[] = $this->line('ok');
                return true;

            case 'M16':
                $lines[] = $this->line(sprintf('echo:Printer check passed for %s', self::MACHINE_INFO['machineType']));
                $lines[] = $this->line('ok');
                return true;

            case 'M28':
            case 'M928':
                $state['sd']['writeFile'] = $this->messageAfterCommand($command);
                $lines[] = $this->line('Writing to file');
                $lines[] = $this->line('ok');
                return true;

            case 'M29':
                $state['sd']['writeFile'] = null;
                $lines[] = $this->line('Done saving file.');
                $lines[] = $this->line('ok');
                return true;

            case 'M30':
                $fileName = strtoupper(trim(substr($command, strlen('M30'))));
                $state['sd']['files'] = array_values(array_filter(
                    $state['sd']['files'],
                    static fn (array $file): bool => $file['name'] !== $fileName
                ));
                $lines[] = $this->line('ok');
                return true;

            case 'M32':
                $selected = $this->selectSdFile($state, preg_replace('/^M32/i', 'M23', $command) ?? $command);
                if ($selected === null) {
                    $lines[] = $this->line('Error:open failed, File not found');
                    return true;
                }
                $state['sd']['selectedFile'] = $selected['name'];
                $state['sd']['size'] = $selected['size'];
                $state['sd']['progress'] = 0;
                $state['sd']['printing'] = true;
                $lines[] = $this->line('ok');
                return true;

            case 'M33':
                $lines[] = $this->line(sprintf('LONG NAME:%s', strtoupper($this->messageAfterCommand($command))));
                $lines[] = $this->line('ok');
                return true;

            case 'M34':
                $state['sd']['sorting'] = (int) ($this->parameterValue($command, 'S') ?? 0);
                $lines[] = $this->line('ok');
                return true;

            case 'M42':
                $pin = (int) ($this->parameterValue($command, 'P') ?? 0);
                $state['pins'][$pin] = (int) ($this->parameterValue($command, 'S') ?? 0);
                $lines[] = $this->line('ok');
                return true;

            case 'M43':
                $lines[] = $this->line('echo:Pins Debugging: none active');
                $lines[] = $this->line('ok');
                return true;

            case 'M48':
                $lines = array_merge($lines, $this->busySequence((int) $state['keepaliveIntervalSecs'], 2));
                $lines[] = $this->line('Mean: 0.015 Min: 0.010 Max: 0.020 Range: 0.010');
                $lines[] = $this->line('ok');
                return true;

            case 'M73':
                $state['sd']['progressPercent'] = (int) ($this->parameterValue($command, 'P') ?? $state['sd']['progressPercent'] ?? 0);
                $state['sd']['remainingMinutes'] = (int) ($this->parameterValue($command, 'R') ?? $state['sd']['remainingMinutes'] ?? 0);
                $lines[] = $this->line('ok');
                return true;

            case 'M85':
                $state['timeouts']['inactivitySecs'] = (int) ($this->parameterValue($command, 'S') ?? $state['timeouts']['inactivitySecs'] ?? 0);
                $lines[] = $this->line('ok');
                return true;

            case 'M86':
                $state['timeouts']['hotendIdleSecs'] = (int) ($this->parameterValue($command, 'S') ?? $state['timeouts']['hotendIdleSecs'] ?? 0);
                $lines[] = $this->line('ok');
                return true;

            case 'M87':
                $state['timeouts']['hotendIdleSecs'] = 0;
                $lines[] = $this->line('ok');
                return true;

            case 'M100':
                $lines[] = $this->line('Free memory: 32768 PlannerBufferBytes: 1232');
                $lines[] = $this->line('ok');
                return true;

            case 'M102':
                $state['bedDistanceSensor']['lastConfig'] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M122':
                $lines[] = $this->line('echo:Driver diagnostics unavailable in FakeSerial');
                $lines[] = $this->line('ok');
                return true;

            case 'M125':
                $state['motion']['parked'] = true;
                $lines[] = $this->line('ok');
                return true;

            case 'M126':
            case 'M127':
            case 'M128':
            case 'M129':
                $state['baricuda'][$commandName] = $commandName === 'M126' || $commandName === 'M128';
                $lines[] = $this->line('ok');
                return true;

            case 'M141':
                $state['chamber']['target'] = (float) ($this->parameterValue($command, 'S') ?? $state['chamber']['target'] ?? 0.0);
                $lines[] = $this->line('ok');
                return true;

            case 'M143':
                $state['cooler']['target'] = (float) ($this->parameterValue($command, 'S') ?? $state['cooler']['target'] ?? 0.0);
                $lines[] = $this->line('ok');
                return true;

            case 'M145':
                $preset = (int) ($this->parameterValue($command, 'S') ?? 0);
                $state['materials']['preset'] = $preset;
                $lines[] = $this->line('ok');
                return true;

            case 'M163':
            case 'M164':
            case 'M165':
            case 'M166':
                $state['mixing'][$commandName] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M192':
            case 'M193':
                $device = $commandName === 'M192' ? 'probe' : 'cooler';
                $state[$device]['temperature'] ??= 24.0;
                $state[$device]['target'] ??= 0.0;
                $state = $this->applyTargetTemperature($state, $command, $device);
                $lines = array_merge($lines, $this->blockingTemperatureSequence($state, $command, $device));
                return true;

            case 'M200':
                $state['extrusion']['diameter'] = (float) ($this->parameterValue($command, 'D') ?? $state['extrusion']['diameter'] ?? 1.75);
                $lines[] = $this->line('ok');
                return true;

            case 'M201':
                $state['limits']['acceleration'] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M207':
            case 'M208':
            case 'M209':
                $state['retraction'][$commandName] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M210':
                $state['homing']['feedrate'] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M211':
                $state['endstops']['software'] = $this->parameterValue($command, 'S') !== 0;
                $lines[] = $this->line('ok');
                return true;

            case 'M217':
                $state['filamentSwap']['settings'] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M218':
                $state['hotendOffsets'] ??= [];
                $tool = (int) ($this->parameterValue($command, 'T') ?? 0);
                $state['hotendOffsets'][$tool] = [
                    'x' => (float) ($this->parameterValue($command, 'X') ?? 0.0),
                    'y' => (float) ($this->parameterValue($command, 'Y') ?? 0.0),
                    'z' => (float) ($this->parameterValue($command, 'Z') ?? 0.0),
                ];
                $lines[] = $this->line('ok');
                return true;

            case 'M226':
                $state['pins']['waitCondition'] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M240':
                $state['camera']['lastTriggerAt'] = microtime(true);
                $lines[] = $this->line('ok');
                return true;

            case 'M250':
            case 'M255':
            case 'M256':
                $state['lcd'][$commandName] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M260':
            case 'M261':
            case 'M265':
                $state['i2c'][$commandName] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M280':
            case 'M281':
            case 'M282':
                $state['servo'][$commandName] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M290':
                $state['babystep']['last'] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M300':
                $state['tone']['last'] = [
                    'frequency' => (int) ($this->parameterValue($command, 'S') ?? 440),
                    'duration' => (int) ($this->parameterValue($command, 'P') ?? 250),
                ];
                $lines[] = $this->line('ok');
                return true;

            case 'M301':
            case 'M304':
            case 'M305':
            case 'M306':
            case 'M309':
                $state['temperatureControl'][$commandName] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M302':
                $state['extrusion']['coldExtrudeEnabled'] = $this->parameterValue($command, 'S') !== 0;
                $lines[] = $this->line('ok');
                return true;

            case 'M303':
                $state['temperatureControl']['autotune'] = $command;
                $lines = array_merge($lines, $this->busySequence((int) $state['keepaliveIntervalSecs'], 3));
                $lines[] = $this->line('PID Autotune finished! Put the Kp, Ki, and Kd constants into Configuration.h');
                $lines[] = $this->line('ok');
                return true;

            case 'M350':
            case 'M351':
                $state['steppers'][$commandName] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M355':
                $state['lights']['enabled'] = $this->parameterValue($command, 'S') !== 0;
                $state['lights']['brightness'] = (int) ($this->parameterValue($command, 'P') ?? $state['lights']['brightness'] ?? 255);
                $lines[] = $this->line('ok');
                return true;

            case 'M360':
            case 'M361':
            case 'M362':
            case 'M363':
            case 'M364':
                $state['scara'][$commandName] = microtime(true);
                $lines[] = $this->line('ok');
                return true;

            case 'M380':
            case 'M381':
                $state['solenoid']['active'] = $commandName === 'M380';
                $lines[] = $this->line('ok');
                return true;

            case 'M401':
                $state['probe']['deployed'] = true;
                $lines[] = $this->line('ok');
                return true;

            case 'M402':
                $state['probe']['deployed'] = false;
                $lines[] = $this->line('ok');
                return true;

            case 'M403':
            case 'M404':
            case 'M405':
            case 'M406':
            case 'M407':
                $state['filamentWidth'][$commandName] = $command;
                if ($commandName === 'M407') {
                    $lines[] = $this->line('Filament dia (measured mm): 1.75');
                    $lines[] = $this->line('ok');
                    return true;
                }
                $lines[] = $this->line('ok');
                return true;

            case 'M410':
                $state['motion']['quickstopped'] = true;
                $lines[] = $this->line('ok');
                return true;

            case 'M412':
                $state['filamentRunout']['enabled'] = $this->parameterValue($command, 'S') !== 0;
                $lines[] = $this->line('ok');
                return true;

            case 'M413':
                $state['recovery']['powerLossEnabled'] = $this->parameterValue($command, 'S') !== 0;
                $lines[] = $this->line('ok');
                return true;

            case 'M414':
                $state['lcd']['language'] = $this->messageAfterCommand($command);
                $lines[] = $this->line('ok');
                return true;

            case 'M420':
                $state['leveling']['enabled'] = $this->parameterValue($command, 'S') !== 0;
                $lines[] = $this->line(sprintf('echo:Bed Leveling %s', $state['leveling']['enabled'] ? 'ON' : 'OFF'));
                $lines[] = $this->line('ok');
                return true;

            case 'M421':
            case 'M422':
            case 'M423':
            case 'M425':
                $state['leveling'][$commandName] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M428':
                $state['homeOffset'] = [
                    'x' => $state['position']['x'],
                    'y' => $state['position']['y'],
                    'z' => $state['position']['z'],
                ];
                $lines[] = $this->line('ok');
                return true;

            case 'M430':
                $lines[] = $this->line('Power: 24.0V Current: 2.1A');
                $lines[] = $this->line('ok');
                return true;

            case 'M486':
                $state['objects']['last'] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M493':
            case 'M494':
                $state['motionControl'][$commandName] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M510':
                $state['security']['locked'] = true;
                $lines[] = $this->line('ok');
                return true;

            case 'M511':
                $state['security']['locked'] = false;
                $lines[] = $this->line('ok');
                return true;

            case 'M512':
                $state['security']['passcode'] = $this->messageAfterCommand($command);
                $lines[] = $this->line('ok');
                return true;

            case 'M524':
                $state['sd']['printing'] = false;
                $state['sd']['progress'] = 0;
                $lines[] = $this->line('ok');
                return true;

            case 'M540':
                $state['sd']['abortOnEndstop'] = $this->parameterValue($command, 'S') !== 0;
                $lines[] = $this->line('ok');
                return true;

            case 'M552':
            case 'M553':
            case 'M554':
                $state['network'][$commandName] = $this->messageAfterCommand($command);
                $lines[] = $this->line('ok');
                return true;

            case 'M569':
            case 'M592':
            case 'M593':
                $state['steppers'][$commandName] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M600':
            case 'M603':
                $state['filamentChange'][$commandName] = $command;
                $state['waitingForUser'] = true;
                $lines[] = $this->line('echo:busy: filament change requested');
                $lines[] = $this->line('ok');
                return true;

            case 'M605':
                $state['multiNozzle']['mode'] = (int) ($this->parameterValue($command, 'S') ?? 0);
                $lines[] = $this->line('ok');
                return true;

            case 'M665':
            case 'M666':
            case 'M672':
                $state['kinematics'][$commandName] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M701':
                $state['filament']['loaded'] = true;
                $state['position']['e'] += 5.0;
                $lines[] = $this->line('ok');
                return true;

            case 'M702':
                $state['filament']['loaded'] = false;
                $state['position']['e'] -= 5.0;
                $lines[] = $this->line('ok');
                return true;

            case 'M710':
                $state['controllerFan']['settings'] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M7219':
                $state['displayMatrix']['last'] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M808':
                $state['macros']['repeatMarker'] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M810':
            case 'M819':
                $state['macros'][$commandName] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M820':
                $lines[] = $this->line('echo:No macros configured');
                $lines[] = $this->line('ok');
                return true;

            case 'M851':
            case 'M852':
            case 'M860':
            case 'M869':
            case 'M871':
                $state['probe'][$commandName] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M876':
                $state['prompts']['lastResponse'] = $command;
                $state['waitingForUser'] = false;
                $lines[] = $this->line('ok');
                return true;

            case 'M900':
                $state['extrusion']['linearAdvance'] = (float) ($this->parameterValue($command, 'K') ?? $state['extrusion']['linearAdvance'] ?? 0.0);
                $lines[] = $this->line('ok');
                return true;

            case 'M906':
            case 'M907':
            case 'M908':
            case 'M909':
            case 'M910':
            case 'M911':
            case 'M912':
            case 'M913':
            case 'M914':
            case 'M915':
            case 'M916':
            case 'M917':
            case 'M918':
            case 'M919':
            case 'M920':
                $state['drivers'][$commandName] = $command;
                if (in_array($commandName, ['M909', 'M911'], true)) {
                    $lines[] = $this->line(sprintf('echo:%s status simulated', $commandName));
                    $lines[] = $this->line('ok');
                    return true;
                }
                $lines[] = $this->line('ok');
                return true;

            case 'M951':
                $state['magneticParking']['last'] = $command;
                $lines[] = $this->line('ok');
                return true;

            case 'M993':
            case 'M994':
            case 'M995':
            case 'M997':
                $state['maintenance'][$commandName] = microtime(true);
                $lines[] = $this->line('ok');
                return true;
        }

        if (preg_match('/^T(\d+)$/', $commandName, $matches)) {
            $tool = (int) $matches[1];

            if ($tool >= self::MACHINE_INFO['extruderCount']) {
                $lines[] = $this->line(sprintf('Error:Tool %d unavailable', $tool));
                return true;
            }

            $state['selectedTool'] = $tool;
            $lines[] = $this->line('ok');
            return true;
        }

        if ($commandName === 'TX') {
            $lines[] = $this->line('Error:MMU2 special commands unavailable on this FakeSerial printer');
            return true;
        }

        return false;
    }

    private function commandName(string $command): string
    {
        if (preg_match('/^\s*([GMT]\d+|T\d+|TX)\b/i', $command, $matches)) {
            return strtoupper($matches[1]);
        }

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

    private function autoReportLines(array &$state): array
    {
        $lines = [];
        $now = microtime(true);

        if (
            $state['temperatureAutoReportIntervalSecs'] > 0
            &&
            $this->autoReportIsDue($state['lastAutoReportAt']['temperature'], $state['temperatureAutoReportIntervalSecs'], $now)
        ) {
            $lines[] = $this->line($this->temperatureReport($state));
            $state['lastAutoReportAt']['temperature'] = $now;
        }

        if (
            $state['positionAutoReportIntervalSecs'] > 0
            &&
            $this->autoReportIsDue($state['lastAutoReportAt']['position'], $state['positionAutoReportIntervalSecs'], $now)
        ) {
            $lines[] = $this->line($this->positionReport($state));
            $state['lastAutoReportAt']['position'] = $now;
        }

        return $lines;
    }

    private function autoReportIsDue(mixed $lastReportedAt, int $intervalSecs, float $now): bool
    {
        if ($lastReportedAt === null) {
            return false;
        }

        return ($now - (float) $lastReportedAt) >= $intervalSecs;
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
            $normalized = $this->normalizeLength((float) $value, $state['lengthUnit']);

            $state['position'][$key] = $isAbsolute
                ? $normalized
                : (float) $state['position'][$key] + $normalized;
        }

        $feedrate = $this->parameterValue($command, 'F');

        if ($feedrate !== null) {
            $state['feedrate'] = $this->normalizeFeedrate((float) $feedrate, $state['lengthUnit']);
        }

        return $state;
    }

    private function applySetPosition(array $state, string $command): array
    {
        foreach (['X', 'Y', 'Z', 'E'] as $axis) {
            $value = $this->parameterValue($command, $axis);

            if ($value === null) {
                continue;
            }

            $state['position'][strtolower($axis)] = $this->normalizeLength((float) $value, $state['lengthUnit']);
        }

        return $state;
    }

    private function applyHoming(array $state, string $command): array
    {
        $homeAll = ! preg_match('/\s[XYZ]\b/i', $command);

        foreach (['X', 'Y', 'Z'] as $axis) {
            if (! $homeAll && ! preg_match('/\s'.preg_quote($axis, '/').'\b/i', $command)) {
                continue;
            }

            $state['position'][strtolower($axis)] = 0.0;
        }

        return $state;
    }

    private function applyAxisSettings(array $state, string $command, string $settingKey): array
    {
        foreach (['X', 'Y', 'Z', 'E'] as $axis) {
            $value = $this->parameterValue($command, $axis);

            if ($value === null) {
                continue;
            }

            $normalized = $settingKey === 'maxFeedrate'
                ? $this->normalizeSpeedPerSecond((float) $value, $state['lengthUnit'])
                : (float) $value;

            $state['settings'][$settingKey][strtolower($axis)] = $normalized;
        }

        return $state;
    }

    private function setFanState(array $state, string $command, bool $turnOff): array
    {
        $index = (int) ($this->parameterValue($command, 'P') ?? 0);

        while (! isset($state['fans'][$index])) {
            $state['fans'][] = ['speed' => 0];
        }

        $speed = $turnOff ? 0 : (int) ($this->parameterValue($command, 'S') ?? 255);
        $state['fans'][$index]['speed'] = max(0, min(255, $speed));

        return $state;
    }

    private function applyTargetTemperature(array $state, string $command, string $device): array
    {
        $value = $this->parameterValue($command, 'S') ?? $this->parameterValue($command, 'R');

        if ($value !== null) {
            $state[$device]['target'] = (float) $value;
        }

        return $state;
    }

    private function blockingTemperatureSequence(array &$state, string $command, string $device): array
    {
        $current = (float) $state[$device]['temperature'];
        $target = (float) $state[$device]['target'];
        $shouldWait = $this->parameterValue($command, 'R') !== null
            ? $target !== $current
            : $target > $current;

        if (! $shouldWait) {
            return [$this->line($this->temperatureReport($state))];
        }

        $lines = $this->busySequence((int) $state['keepaliveIntervalSecs'], 2);
        $state[$device]['temperature'] = $current + (($target - $current) / 2);
        $lines[] = $this->line($this->temperatureReport($state), 200);
        $state[$device]['temperature'] = $target;
        $lines[] = $this->line($this->temperatureReport($state));

        return $lines;
    }

    private function selectSdFile(array $state, string $command): ?array
    {
        if (! $state['sd']['mounted']) {
            return null;
        }

        $fileName = strtoupper(trim(substr($command, strlen('M23'))));

        foreach ($state['sd']['files'] as $file) {
            if ($file['name'] === $fileName) {
                return $file;
            }
        }

        return null;
    }

    private function tickSdProgress(array $state): array
    {
        if (! $state['sd']['printing']) {
            return $state;
        }

        $state['sd']['progress'] = min(
            $state['sd']['size'],
            (int) $state['sd']['progress'] + 1024
        );
        $state['sd']['elapsedSeconds'] += 2;

        if ($state['sd']['progress'] >= $state['sd']['size']) {
            $state['sd']['printing'] = false;
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

    private function reportSettingsLines(array $state): array
    {
        $steps = $state['settings']['stepsPerUnit'];
        $maxFeedrate = $state['settings']['maxFeedrate'];
        $acceleration = $state['settings']['acceleration'];
        $advanced = $state['settings']['advanced'];
        $homeOffset = $state['homeOffset'];

        return [
            $this->line('echo:  M503 Report Settings'),
            $this->line(sprintf('echo:  %s', $state['lengthUnit'] === 'in' ? 'G20' : 'G21')),
            $this->line(sprintf('echo:  M149 %s', $state['temperatureUnit'])),
            $this->line(sprintf('echo:  M92 X%.2F Y%.2F Z%.2F E%.2F', $steps['x'], $steps['y'], $steps['z'], $steps['e'])),
            $this->line(sprintf('echo:  M203 X%.2F Y%.2F Z%.2F E%.2F', $maxFeedrate['x'], $maxFeedrate['y'], $maxFeedrate['z'], $maxFeedrate['e'])),
            $this->line(sprintf('echo:  M204 P%.2F R%.2F T%.2F', $acceleration['print'], $acceleration['retract'], $acceleration['travel'])),
            $this->line(sprintf(
                'echo:  M205 S%.2F T%.2F B%.0F X%.2F Y%.2F Z%.2F E%.2F',
                $advanced['minimumFeedrate'],
                $advanced['minimumTravelFeedrate'],
                $advanced['minimumSegmentTimeUs'],
                $advanced['xJerk'],
                $advanced['yJerk'],
                $advanced['zJerk'],
                $advanced['eJerk']
            )),
            $this->line(sprintf('echo:  M206 X%.2F Y%.2F Z%.2F', $homeOffset['x'], $homeOffset['y'], $homeOffset['z'])),
            $this->line(sprintf('echo:  M220 S%d', $state['feedratePercentage'])),
            $this->line(sprintf('echo:  M221 S%d', $state['flowPercentage'])),
        ];
    }

    private function temperatureReport(array $state): string
    {
        return sprintf(
            'ok T:%.2F /%.2F B:%.2F /%.2F T0:%.2F /%.2F @:%d B@:%d',
            $this->displayTemperature($state['hotend']['temperature'], $state['temperatureUnit']),
            $this->displayTemperature($state['hotend']['target'], $state['temperatureUnit']),
            $this->displayTemperature($state['bed']['temperature'], $state['temperatureUnit']),
            $this->displayTemperature($state['bed']['target'], $state['temperatureUnit']),
            $this->displayTemperature($state['hotend']['temperature'], $state['temperatureUnit']),
            $this->displayTemperature($state['hotend']['target'], $state['temperatureUnit']),
            $state['hotend']['target'] > 0 ? 127 : 0,
            $state['bed']['target'] > 0 ? 127 : 0
        );
    }

    private function positionReport(array $state): string
    {
        return sprintf(
            'X:%.2F Y:%.2F Z:%.2F E:%.2F Count X:0 Y:0 Z:0',
            $state['position']['x'],
            $state['position']['y'],
            $state['position']['z'],
            $state['position']['e']
        );
    }

    private function displayTemperature(float $temperatureCelsius, string $unit): float
    {
        return match ($unit) {
            'F' => ($temperatureCelsius * 9 / 5) + 32,
            'K' => $temperatureCelsius + 273.15,
            default => $temperatureCelsius,
        };
    }

    private function temperatureUnitFromCommand(string $command, string $fallback): string
    {
        foreach (['C', 'F', 'K'] as $unit) {
            if (preg_match('/(?:^|\s)'.preg_quote($unit, '/').'(?:\s|$)/i', $command)) {
                return $unit;
            }
        }

        return $fallback;
    }

    private function hostMessage(string $command): string
    {
        $message = preg_replace('/^\s*M118\s*/i', '', $command) ?? '';
        $prefix = '';
        $tokens = preg_split('/\s+/', trim($message)) ?: [];
        $payload = [];

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if (preg_match('/^A1$/i', $token)) {
                $prefix = '//';
                continue;
            }

            if (preg_match('/^E1$/i', $token)) {
                $prefix = 'echo:';
                continue;
            }

            if (preg_match('/^P\d+$/i', $token)) {
                continue;
            }

            $payload[] = $token;
        }

        return $prefix.implode(' ', $payload);
    }

    private function messageAfterCommand(string $command): string
    {
        return trim((string) preg_replace('/^\s*[A-Z]\d+\s*/i', '', $command));
    }

    private function dwellDelayMs(string $command): int
    {
        $milliseconds = $this->parameterValue($command, 'P');

        if ($milliseconds !== null) {
            return max(0, (int) $milliseconds);
        }

        $seconds = $this->parameterValue($command, 'S');

        return $seconds === null ? 0 : max(0, (int) $seconds * 1000);
    }

    private function normalizeLength(float $value, string $unit): float
    {
        return $unit === 'in' ? $value * 25.4 : $value;
    }

    private function normalizeFeedrate(float $value, string $unit): float
    {
        return $unit === 'in' ? $value * 25.4 : $value;
    }

    private function normalizeSpeedPerSecond(float $value, string $unit): float
    {
        return $unit === 'in' ? $value * 25.4 : $value;
    }

    private function isKnownMarlinCommand(string $commandName): bool
    {
        return in_array($commandName, self::knownCommands(), true);
    }

    private static function knownCommands(): array
    {
        if (self::$knownCommands !== null) {
            return self::$knownCommands;
        }

        $constants = (new ReflectionClass(Marlin::class))->getConstants();
        $commands = [];

        foreach (array_keys($constants) as $command) {
            $command = strtoupper($command);

            if (preg_match('/^(?:[GM]\d+|T\d+|TX)$/', $command)) {
                $commands[] = $command;
            }
        }

        self::$knownCommands = $commands;

        return self::$knownCommands;
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
