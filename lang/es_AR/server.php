<?php

return [
    'auth' => [
        'invalid_credentials' => 'Esa combinación de nombre de usuario o correo electrónico y contraseña no coincide con nuestros registros.',
    ],
    'materials' => [
        'duplicate_name' => 'Ya existe otro material con el mismo nombre.',
        'not_found' => 'No existe ese material.',
    ],
    'printers' => [
        'not_found' => 'No existe esa impresora.',
        'not_selected' => 'No hay ninguna impresora seleccionada.',
        'not_connected' => 'No se pudo completar la acción: esta impresora no está conectada.',
        'connected' => 'No se pudo completar la acción: esta impresora está conectada.',
        'active_file_exists' => 'No se pudo completar la acción: hay un archivo activo.',
        'no_active_file' => 'No se pudo completar la acción: no hay ningún archivo activo.',
        'active_file_already_present' => 'No se pudo completar la acción: ya hay un archivo activo.',
        'mapper_running' => 'No se pudo completar la acción: el mapeador está en ejecución. Volvé a intentarlo en unos segundos.',
        'unknown_node' => 'No conocemos el nodo de esta impresora. Desconectá el cable USB y volvé a conectarlo, esperá unos segundos y volvé a intentarlo.',
        'failed_assert_absolute_position' => 'No se pudo determinar la posición absoluta (no hay suficiente contexto en el G-code).',
    ],
    'commands' => [
        'empty' => 'No se puede encolar un comando vacío.',
        'distance_required' => 'No se puede encolar un comando sin una distancia.',
        'feedrate_required' => 'No se puede encolar un comando sin una velocidad de avance.',
        'extruder_required' => 'No se puede encolar un comando sin un extrusor.',
        'temperature_required' => 'No se puede encolar un comando sin una temperatura.',
        'temperature_numeric' => 'La temperatura debe ser un número.',
        'not_connected' => 'No se pudo encolar el comando: esta impresora no está conectada.',
    ],
    'directions' => [
        'empty' => 'No se puede encolar una dirección vacía.',
        'invalid' => 'Dirección no válida.',
    ],
    'files' => [
        'in_use' => 'El archivo está en uso.',
        'already_exists' => 'El archivo ya existe.',
        'rename_failed' => 'No se pudo renombrar el archivo.',
        'upload_already_exists' => 'El archivo ya existe.',
        'not_found' => 'No existe ese archivo.',
    ],
    'directories' => [
        'already_exists' => 'El directorio ya existe.',
        'not_found' => 'El directorio no existe.',
        'not_empty' => 'El directorio no está vacío.',
    ],
    'password' => [
        'current_password_mismatch' => 'La contraseña actual no coincide con nuestros registros.',
        'must_be_different' => 'La nueva contraseña debe ser diferente de la actual.',
    ],
    'notifications' => [
        'not_found' => 'No existe esa notificación.',
    ],
    'cameras' => [
        'cannot_delete_connected' => 'No se puede eliminar una cámara conectada.',
        'not_found' => 'No existe esa cámara.',
        'unsupported_format' => 'La cámara no admite este formato.',
    ],
    'recordings' => [
        'not_found' => 'No existe esa grabación.',
    ],
    'users' => [
        'permission_denied' => 'No tenés los permisos necesarios.',
        'not_found' => 'No existe ese usuario.',
        'name_in_use' => 'El nombre indicado ya está en uso.',
        'email_in_use' => 'El correo electrónico indicado ya está en uso.',
        'role_change_forbidden' => 'No podés cambiar el rol de este usuario.',
        'delete_forbidden' => 'No podés eliminar a este usuario.',
    ],
    'plugins' => [
        'invalid_upload' => 'El paquete subido no es válido.',
        'install_source_required' => 'Proporcioná un paquete, una URL, un unpackedPath o un pluginId del registro.',
        'unpacked_install_development_only' => 'La instalación de plugins desempaquetados solo está disponible en el entorno de desarrollo.',
    ],
    'validation' => [
        'hotend_required' => 'La temperatura del hotend es obligatoria.',
        'hotend_integer' => 'La temperatura del hotend debe ser un número entero.',
        'bed_required' => 'La temperatura de la cama es obligatoria.',
        'bed_integer' => 'La temperatura de la cama debe ser un número entero.',
    ],
];
