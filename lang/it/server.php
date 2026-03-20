<?php

return [
    'auth' => [
        'invalid_credentials' => 'Questa combinazione di nome utente o indirizzo e-mail e password non corrisponde ai nostri dati.',
    ],
    'materials' => [
        'duplicate_name' => 'Esiste già un altro materiale con lo stesso nome.',
        'not_found' => 'Materiale inesistente.',
    ],
    'printers' => [
        'not_found' => 'Stampante inesistente.',
        'not_selected' => 'Nessuna stampante selezionata.',
        'not_connected' => 'Impossibile completare l’azione: questa stampante non è connessa.',
        'connected' => 'Impossibile completare l’azione: questa stampante è connessa.',
        'active_file_exists' => 'Impossibile completare l’azione: è presente un file attivo.',
        'no_active_file' => 'Impossibile completare l’azione: non c’è alcun file attivo.',
        'active_file_already_present' => 'Impossibile completare l’azione: è già presente un file attivo.',
        'mapper_running' => 'Impossibile completare l’azione: il mapper è in esecuzione. Riprova tra qualche secondo.',
        'unknown_node' => 'Il nodo di questa stampante non è noto. Scollega e ricollega il cavo USB, attendi qualche secondo e riprova.',
        'failed_assert_absolute_position' => 'Impossibile determinare la posizione assoluta (contesto G-code insufficiente).',
    ],
    'commands' => [
        'empty' => 'Non è possibile mettere in coda un comando vuoto.',
        'distance_required' => 'Non è possibile mettere in coda un comando senza una distanza.',
        'feedrate_required' => 'Non è possibile mettere in coda un comando senza una velocità di avanzamento.',
        'extruder_required' => 'Non è possibile mettere in coda un comando senza un estrusore.',
        'temperature_required' => 'Non è possibile mettere in coda un comando senza una temperatura.',
        'temperature_numeric' => 'La temperatura deve essere un numero.',
        'not_connected' => 'Impossibile mettere in coda il comando: questa stampante non è connessa.',
    ],
    'directions' => [
        'empty' => 'Non è possibile mettere in coda una direzione vuota.',
        'invalid' => 'Direzione non valida.',
    ],
    'files' => [
        'in_use' => 'Il file è attualmente in uso.',
        'already_exists' => 'Il file esiste già.',
        'rename_failed' => 'Impossibile rinominare il file.',
        'upload_already_exists' => 'Il file esiste già.',
        'not_found' => 'File inesistente.',
    ],
    'directories' => [
        'already_exists' => 'La directory esiste già.',
        'not_found' => 'La directory non esiste.',
        'not_empty' => 'La directory non è vuota.',
    ],
    'password' => [
        'current_password_mismatch' => 'La password attuale non corrisponde ai nostri dati.',
        'must_be_different' => 'La nuova password deve essere diversa da quella attuale.',
    ],
    'notifications' => [
        'not_found' => 'Notifica inesistente.',
    ],
    'cameras' => [
        'cannot_delete_connected' => 'Non è possibile eliminare una fotocamera connessa.',
        'not_found' => 'Fotocamera inesistente.',
        'unsupported_format' => 'La fotocamera non supporta questo formato.',
    ],
    'recordings' => [
        'not_found' => 'Registrazione inesistente.',
    ],
    'users' => [
        'permission_denied' => 'Non disponi delle autorizzazioni necessarie.',
        'not_found' => 'Utente inesistente.',
        'name_in_use' => 'Il nome specificato è già in uso.',
        'email_in_use' => 'L’indirizzo e-mail specificato è già in uso.',
        'role_change_forbidden' => 'Non puoi modificare il ruolo di questo utente.',
        'delete_forbidden' => 'Non puoi eliminare questo utente.',
    ],
    'plugins' => [
        'invalid_upload' => 'Il pacchetto caricato non è valido.',
        'install_source_required' => 'Fornisci un pacchetto, un URL, un unpackedPath o un pluginId del registro.',
        'unpacked_install_development_only' => 'L’installazione di plugin non compressi è disponibile solo nell’ambiente di sviluppo.',
    ],
    'validation' => [
        'hotend_required' => 'La temperatura dell’hotend è obbligatoria.',
        'hotend_integer' => 'La temperatura dell’hotend deve essere un numero intero.',
        'bed_required' => 'La temperatura del piano è obbligatoria.',
        'bed_integer' => 'La temperatura del piano deve essere un numero intero.',
    ],
];
