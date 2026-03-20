<?php

return [
    'auth' => [
        'invalid_credentials' => 'Diese Kombination aus Benutzername oder E-Mail-Adresse und Passwort stimmt nicht mit unseren Aufzeichnungen überein.',
    ],
    'materials' => [
        'duplicate_name' => 'Es gibt bereits ein anderes Material mit demselben Namen.',
        'not_found' => 'Kein solches Material.',
    ],
    'printers' => [
        'not_found' => 'Kein solcher Drucker.',
        'not_selected' => 'Kein Drucker ausgewählt.',
        'not_connected' => 'Aktion konnte nicht abgeschlossen werden: Dieser Drucker ist nicht verbunden.',
        'connected' => 'Aktion konnte nicht abgeschlossen werden: Dieser Drucker ist verbunden.',
        'active_file_exists' => 'Aktion konnte nicht abgeschlossen werden: Es gibt eine aktive Datei.',
        'no_active_file' => 'Aktion konnte nicht abgeschlossen werden: Es gibt keine aktive Datei.',
        'active_file_already_present' => 'Aktion konnte nicht abgeschlossen werden: Es ist bereits eine aktive Datei vorhanden.',
        'mapper_running' => 'Aktion konnte nicht abgeschlossen werden: Der Mapper läuft. Bitte versuche es in ein paar Sekunden erneut.',
        'unknown_node' => 'Der Knoten dieses Druckers ist unbekannt. Ziehe das USB-Kabel ab, stecke es wieder ein, warte einige Sekunden und versuche es erneut.',
        'failed_assert_absolute_position' => 'Die absolute Position konnte nicht ermittelt werden (nicht genügend Kontext im G-Code).',
    ],
    'commands' => [
        'empty' => 'Ein leerer Befehl kann nicht in die Warteschlange gestellt werden.',
        'distance_required' => 'Ein Befehl ohne Distanz kann nicht in die Warteschlange gestellt werden.',
        'feedrate_required' => 'Ein Befehl ohne Vorschubgeschwindigkeit kann nicht in die Warteschlange gestellt werden.',
        'extruder_required' => 'Ein Befehl ohne Extruder kann nicht in die Warteschlange gestellt werden.',
        'temperature_required' => 'Ein Befehl ohne Temperatur kann nicht in die Warteschlange gestellt werden.',
        'temperature_numeric' => 'Die Temperatur muss eine Zahl sein.',
        'not_connected' => 'Befehl konnte nicht in die Warteschlange gestellt werden: Dieser Drucker ist nicht verbunden.',
    ],
    'directions' => [
        'empty' => 'Eine leere Richtung kann nicht in die Warteschlange gestellt werden.',
        'invalid' => 'Ungültige Richtung.',
    ],
    'files' => [
        'in_use' => 'Die Datei wird derzeit verwendet.',
        'already_exists' => 'Die Datei existiert bereits.',
        'rename_failed' => 'Die Datei konnte nicht umbenannt werden.',
        'upload_already_exists' => 'Die Datei existiert bereits.',
        'not_found' => 'Keine solche Datei.',
    ],
    'directories' => [
        'already_exists' => 'Das Verzeichnis existiert bereits.',
        'not_found' => 'Das Verzeichnis existiert nicht.',
        'not_empty' => 'Das Verzeichnis ist nicht leer.',
    ],
    'password' => [
        'current_password_mismatch' => 'Das aktuelle Passwort stimmt nicht mit unseren Aufzeichnungen überein.',
        'must_be_different' => 'Das neue Passwort muss sich vom aktuellen unterscheiden.',
    ],
    'notifications' => [
        'not_found' => 'Keine solche Benachrichtigung.',
    ],
    'cameras' => [
        'cannot_delete_connected' => 'Eine verbundene Kamera kann nicht gelöscht werden.',
        'not_found' => 'Keine solche Kamera.',
        'unsupported_format' => 'Die Kamera unterstützt dieses Format nicht.',
    ],
    'recordings' => [
        'not_found' => 'Keine solche Aufnahme.',
    ],
    'users' => [
        'permission_denied' => 'Du hast nicht die erforderlichen Berechtigungen.',
        'not_found' => 'Kein solcher Benutzer.',
        'name_in_use' => 'Der angegebene Name wird bereits verwendet.',
        'email_in_use' => 'Die angegebene E-Mail-Adresse wird bereits verwendet.',
        'role_change_forbidden' => 'Du kannst die Rolle dieses Benutzers nicht ändern.',
        'delete_forbidden' => 'Du kannst diesen Benutzer nicht löschen.',
    ],
    'plugins' => [
        'invalid_upload' => 'Das hochgeladene Paket ist ungültig.',
        'install_source_required' => 'Gib ein Paket, eine URL, einen unpackedPath oder eine Registry-pluginId an.',
        'unpacked_install_development_only' => 'Die Installation entpackter Plugins ist nur in der Entwicklungsumgebung verfügbar.',
    ],
    'validation' => [
        'hotend_required' => 'Die Hotend-Temperatur ist erforderlich.',
        'hotend_integer' => 'Die Hotend-Temperatur muss eine ganze Zahl sein.',
        'bed_required' => 'Die Betttemperatur ist erforderlich.',
        'bed_integer' => 'Die Betttemperatur muss eine ganze Zahl sein.',
    ],
];
