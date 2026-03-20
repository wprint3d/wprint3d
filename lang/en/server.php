<?php

return [
    'auth' => [
        'invalid_credentials' => 'That combination of username or email address and password doesn\'t match our records.',
    ],
    'materials' => [
        'duplicate_name' => 'Another material with the same name already exists.',
        'not_found' => 'No such material.',
    ],
    'printers' => [
        'not_found' => 'No such printer.',
        'not_selected' => 'No printer selected.',
        'not_connected' => 'Couldn\'t complete action: this printer is not connected.',
        'connected' => 'Couldn\'t complete action: this printer is connected.',
        'active_file_exists' => 'Couldn\'t complete action: there\'s an active file.',
        'no_active_file' => 'Couldn\'t complete action: there\'s no active file.',
        'active_file_already_present' => 'Couldn\'t complete action: an active file is already present.',
        'mapper_running' => 'Couldn\'t complete action: the mapper is running, please try again in a few seconds.',
        'unknown_node' => 'We don\'t know about this printer\'s node. Please unplug the USB cable and plug it back in, wait a few seconds, and try again.',
        'failed_assert_absolute_position' => 'Failed to assert absolute position (not enough context in G-code).',
    ],
    'commands' => [
        'empty' => 'Can\'t queue an empty command.',
        'distance_required' => 'Can\'t queue a command without a distance.',
        'feedrate_required' => 'Can\'t queue a command without a feedrate.',
        'extruder_required' => 'Can\'t queue a command without an extruder.',
        'temperature_required' => 'Can\'t queue a command without a temperature.',
        'temperature_numeric' => 'Temperature must be a number.',
        'not_connected' => 'Couldn\'t queue command: this printer is not connected.',
    ],
    'directions' => [
        'empty' => 'Can\'t queue an empty direction.',
        'invalid' => 'Invalid direction.',
    ],
    'files' => [
        'in_use' => 'The file is currently in use.',
        'already_exists' => 'File already exists.',
        'rename_failed' => 'Couldn\'t rename file.',
        'upload_already_exists' => 'The file already exists.',
        'not_found' => 'No such file.',
    ],
    'directories' => [
        'already_exists' => 'The directory already exists.',
        'not_found' => 'The directory doesn\'t exist.',
        'not_empty' => 'The directory isn\'t empty.',
    ],
    'password' => [
        'current_password_mismatch' => 'The current password doesn\'t match our records.',
        'must_be_different' => 'The new password must be different from the current one.',
    ],
    'notifications' => [
        'not_found' => 'No such notification.',
    ],
    'cameras' => [
        'cannot_delete_connected' => 'Cannot delete a connected camera.',
        'not_found' => 'No such camera.',
        'unsupported_format' => 'The camera doesn\'t support this format.',
    ],
    'recordings' => [
        'not_found' => 'No such recording.',
    ],
    'users' => [
        'permission_denied' => 'You don\'t have the required permissions.',
        'not_found' => 'No such user.',
        'name_in_use' => 'The specified name is already in use.',
        'email_in_use' => 'The specified email is already in use.',
        'role_change_forbidden' => 'You can\'t change the role of this user.',
        'delete_forbidden' => 'You can\'t delete this user.',
    ],
    'plugins' => [
        'invalid_upload' => 'The uploaded package is invalid.',
        'install_source_required' => 'Provide a package upload, URL, unpackedPath, or registry pluginId.',
        'unpacked_install_development_only' => 'Unpacked plugin installs are only available in the development environment.',
    ],
    'validation' => [
        'hotend_required' => 'The hotend temperature is required.',
        'hotend_integer' => 'The hotend temperature must be an integer.',
        'bed_required' => 'The bed temperature is required.',
        'bed_integer' => 'The bed temperature must be an integer.',
    ],
];
