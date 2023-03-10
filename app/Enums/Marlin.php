<?php

declare(strict_types=1);

namespace App\Enums;

use BenSampo\Enum\Enum;

/*
 * This file has been auto-generated by running the "make:marlin-labels" Artisan
 * command. DO NOT edit it manually, if you've got an updated list of commands,
 * simply run the generator again.
 */

final class Marlin extends Enum
{
	const G0 = 'Linear Move';
	const G1 = 'Linear Move';
	const G2 = 'Arc or Circle Move';
	const G3 = 'Arc or Circle Move';
	const G4 = 'Dwell';
	const G5 = 'BÃ©zier cubic spline';
	const G6 = 'Direct Stepper Move';
	const G10 = 'Retract';
	const G11 = 'Recover';
	const G12 = 'Clean the Nozzle';
	const G17 = 'CNC Workspace Planes';
	const G19 = 'CNC Workspace Planes';
	const G20 = 'Inch Units';
	const G21 = 'Millimeter Units';
	const G26 = 'Mesh Validation Pattern';
	const G27 = 'Park toolhead';
	const G28 = 'Auto Home';
	const G29 = 'Bed Leveling (Unified)';
	const G30 = 'Single Z-Probe';
	const G31 = 'Dock Sled';
	const G32 = 'Undock Sled';
	const G33 = 'Delta Auto Calibration';
	const G34 = 'Mechanical Gantry Calibration';
	const G35 = 'Tramming Assistant';
	const G38 = 'Probe target';
	const G42 = 'Move to mesh coordinate';
	const G53 = 'Move in Machine Coordinates';
	const G54 = 'Workspace Coordinate System';
	const G59 = 'Workspace Coordinate System';
	const G60 = 'Save Current Position';
	const G61 = 'Return to Saved Position';
	const G76 = 'Probe temperature calibration';
	const G80 = 'Cancel Current Motion Mode';
	const G90 = 'Absolute Positioning';
	const G91 = 'Relative Positioning';
	const G92 = 'Set Position';
	const G425 = 'Backlash Calibration';
	const M0 = 'Unconditional stop';
	const M1 = 'Unconditional stop';
	const M3 = 'Spindle CW / Laser On';
	const M4 = 'Spindle CCW / Laser On';
	const M5 = 'Spindle / Laser Off';
	const M7 = 'Coolant Controls';
	const M9 = 'Coolant Controls';
	const M10 = 'Vacuum / Blower Control';
	const M11 = 'Vacuum / Blower Control';
	const M16 = 'Expected Printer Check';
	const M17 = 'Enable Steppers';
	const M18 = 'Disable steppers';
	const M84 = 'Disable steppers';
	const M20 = 'List SD Card';
	const M21 = 'Init SD card';
	const M22 = 'Release SD card';
	const M23 = 'Select SD file';
	const M24 = 'Start or Resume SD print';
	const M25 = 'Pause SD print';
	const M26 = 'Set SD position';
	const M27 = 'Report SD print status';
	const M28 = 'Start SD write';
	const M29 = 'Stop SD write';
	const M30 = 'Delete SD file';
	const M31 = 'Print time';
	const M32 = 'Select and Start';
	const M33 = 'Get Long Path';
	const M34 = 'SDCard Sorting';
	const M42 = 'Set Pin State';
	const M43 = 'Debug Pins';
	const M43_T = 'Toggle Pins';
	const M48 = 'Probe Repeatability Test';
	const M73 = 'Set Print Progress';
	const M75 = 'Start Print Job Timer';
	const M76 = 'Pause Print Job Timer';
	const M77 = 'Stop Print Job Timer';
	const M78 = 'Print Job Stats';
	const M80 = 'Power On';
	const M81 = 'Power Off';
	const M82 = 'E Absolute';
	const M83 = 'E Relative';
	const M85 = 'Inactivity Shutdown';
	const M92 = 'Set Axis Steps-per-unit';
	const M100 = 'Free Memory';
	const M102 = 'Configure Bed Distance Sensor';
	const M104 = 'Set Hotend Temperature';
	const M105 = 'Report Temperatures';
	const M106 = 'Set Fan Speed';
	const M107 = 'Fan Off';
	const M108 = 'Break and Continue';
	const M109 = 'Wait for Hotend Temperature';
	const M110 = 'Set Line Number';
	const M111 = 'Debug Level';
	const M112 = 'Emergency Stop';
	const M113 = 'Host Keepalive';
	const M114 = 'Get Current Position';
	const M115 = 'Firmware Info';
	const M117 = 'Set LCD Message';
	const M118 = 'Serial print';
	const M119 = 'Endstop States';
	const M120 = 'Enable Endstops';
	const M121 = 'Disable Endstops';
	const M122 = 'TMC Debugging';
	const M123 = 'Fan Tachometers';
	const M125 = 'Park Head';
	const M126 = 'Baricuda 1 Open';
	const M127 = 'Baricuda 1 Close';
	const M128 = 'Baricuda 2 Open';
	const M129 = 'Baricuda 2 Close';
	const M140 = 'Set Bed Temperature';
	const M141 = 'Set Chamber Temperature';
	const M143 = 'Set Laser Cooler Temperature';
	const M145 = 'Set Material Preset';
	const M149 = 'Set Temperature Units';
	const M150 = 'Set RGB(W) Color';
	const M154 = 'Position Auto-Report';
	const M155 = 'Temperature Auto-Report';
	const M163 = 'Set Mix Factor';
	const M164 = 'Save Mix';
	const M165 = 'Set Mix';
	const M166 = 'Gradient Mix';
	const M190 = 'Wait for Bed Temperature';
	const M191 = 'Wait for Chamber Temperature';
	const M192 = 'Wait for Probe temperature';
	const M193 = 'Set Laser Cooler Temperature';
	const M200 = 'Set Filament Diameter';
	const M201 = 'Print Move Limits';
	const M203 = 'Set Max Feedrate';
	const M204 = 'Set Starting Acceleration';
	const M205 = 'Set Advanced Settings';
	const M206 = 'Set Home Offsets';
	const M207 = 'Set Firmware Retraction';
	const M208 = 'Firmware Recover';
	const M209 = 'Set Auto Retract';
	const M211 = 'Software Endstops';
	const M217 = 'Filament swap parameters';
	const M218 = 'Set Hotend Offset';
	const M220 = 'Set Feedrate Percentage';
	const M221 = 'Set Flow Percentage';
	const M226 = 'Wait for Pin State';
	const M240 = 'Trigger Camera';
	const M250 = 'LCD Contrast';
	const M255 = 'LCD Sleep/Backlight Timeout';
	const M256 = 'LCD Brightness';
	const M260 = 'I2C Send';
	const M261 = 'I2C Request';
	const M280 = 'Servo Position';
	const M281 = 'Edit Servo Angles';
	const M282 = 'Detach Servo';
	const M290 = 'Babystep';
	const M300 = 'Play Tone';
	const M301 = 'Set Hotend PID';
	const M302 = 'Cold Extrude';
	const M303 = 'PID autotune';
	const M304 = 'Set Bed PID';
	const M305 = 'User Thermistor Parameters';
	const M306 = 'Model predictive temperature control';
	const M350 = 'Set micro-stepping';
	const M351 = 'Set Microstep Pins';
	const M355 = 'Case Light Control';
	const M360 = 'SCARA Theta A';
	const M361 = 'SCARA Theta-B';
	const M362 = 'SCARA Psi-A';
	const M363 = 'SCARA Psi-B';
	const M364 = 'SCARA Psi-C';
	const M380 = 'Activate Solenoid';
	const M381 = 'Deactivate Solenoids';
	const M400 = 'Finish Moves';
	const M401 = 'Deploy Probe';
	const M402 = 'Stow Probe';
	const M403 = 'MMU2 Filament Type';
	const M404 = 'Set Filament Diameter';
	const M405 = 'Filament Width Sensor On';
	const M406 = 'Filament Width Sensor Off';
	const M407 = 'Filament Width';
	const M410 = 'Quickstop';
	const M412 = 'Filament Runout';
	const M413 = 'Power-loss Recovery';
	const M420 = 'Bed Leveling State';
	const M421 = 'Set Mesh Value';
	const M422 = 'Set Z Motor XY';
	const M423 = 'X Twist Compensation';
	const M425 = 'Backlash compensation';
	const M428 = 'Home Offsets Here';
	const M430 = 'Power Monitor';
	const M486 = 'Cancel Objects';
	const M500 = 'Save Settings';
	const M501 = 'Restore Settings';
	const M502 = 'Factory Reset';
	const M503 = 'Report Settings';
	const M504 = 'Validate EEPROM contents';
	const M510 = 'Lock Machine';
	const M511 = 'Unlock Machine';
	const M512 = 'Set Passcode';
	const M524 = 'Abort SD print';
	const M540 = 'Endstops Abort SD';
	const M569 = 'Set TMC stepping mode';
	const M575 = 'Serial baud rate';
	const M593 = 'Input Shaping';
	const M600 = 'Filament Change';
	const M603 = 'Configure Filament Change';
	const M605 = 'Multi Nozzle Mode';
	const M665 = 'SCARA Configuration';
	const M666 = 'Set dual endstop offsets';
	const M672 = 'Duet Smart Effector sensitivity';
	const M701 = 'Load filament';
	const M702 = 'Unload filament';
	const M710 = 'Controller Fan settings';
	const M808 = 'Repeat Marker';
	const M810 = 'G-code macros';
	const M819 = 'G-code macros';
	const M851 = 'XYZ Probe Offset';
	const M852 = 'Bed Skew Compensation';
	const M860 = 'I2C Position Encoders';
	const M869 = 'I2C Position Encoders';
	const M871 = 'Probe temperature config';
	const M876 = 'Handle Prompt Response';
	const M900 = 'Linear Advance Factor';
	const M906 = 'Stepper Motor Current';
	const M907 = 'Set Motor Current';
	const M908 = 'Set Trimpot Pins';
	const M909 = 'DAC Print Values';
	const M910 = 'Commit DAC to EEPROM';
	const M911 = 'TMC OT Pre-Warn Condition';
	const M912 = 'Clear TMC OT Pre-Warn';
	const M913 = 'Set Hybrid Threshold Speed';
	const M914 = 'TMC Bump Sensitivity';
	const M915 = 'TMC Z axis calibration';
	const M916 = 'L6474 Thermal Warning Test';
	const M917 = 'L6474 Overcurrent Warning Test';
	const M918 = 'L6474 Speed Warning Test';
	const M919 = 'TMC Chopper Timing';
	const M928 = 'Start SD Logging';
	const M951 = 'Magnetic Parking Extruder';
	const M993 = 'SD / SPI Flash';
	const M994 = 'SD / SPI Flash';
	const M995 = 'Touch Screen Calibration';
	const M997 = 'Firmware update';
	const M999 = 'STOP Restart';
	const M7219 = 'MAX7219 Control';
	const T0 = 'Select Tool';
	const T6 = 'Select Tool';

	public static function getLabel(string $gcodeLine) {
		$command = preg_replace('/ ;.*/', '', $gcodeLine);
		$command = explode(' ', $command, 2);

		if (!$command || !$command[0]) return ': Unknown';

		if (self::hasKey( $command[0] )) return $command[0] . ': ' . ($command[1] ?? '') . ' (' . self::getValue( $command[0] ) . ')';

		return $command[0] . ': ' . ($command[1] ?? '') . ' (Unknown)';
	}
}

?>