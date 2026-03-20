<?php

return [
    'auth' => [
        'invalid_credentials' => 'Cette combinaison de nom d’utilisateur ou d’adresse e-mail et de mot de passe ne correspond pas à nos enregistrements.',
    ],
    'materials' => [
        'duplicate_name' => 'Un autre matériau portant le même nom existe déjà.',
        'not_found' => 'Aucun matériau correspondant.',
    ],
    'printers' => [
        'not_found' => 'Aucune imprimante correspondante.',
        'not_selected' => 'Aucune imprimante n’est sélectionnée.',
        'not_connected' => 'Impossible d’effectuer l’action : cette imprimante n’est pas connectée.',
        'connected' => 'Impossible d’effectuer l’action : cette imprimante est connectée.',
        'active_file_exists' => 'Impossible d’effectuer l’action : un fichier actif est présent.',
        'no_active_file' => 'Impossible d’effectuer l’action : aucun fichier actif.',
        'active_file_already_present' => 'Impossible d’effectuer l’action : un fichier actif est déjà présent.',
        'mapper_running' => 'Impossible d’effectuer l’action : le mappeur est en cours d’exécution. Réessayez dans quelques secondes.',
        'unknown_node' => 'Le nœud de cette imprimante est inconnu. Débranchez puis rebranchez le câble USB, attendez quelques secondes et réessayez.',
        'failed_assert_absolute_position' => 'Impossible de déterminer la position absolue (contexte G-code insuffisant).',
    ],
    'commands' => [
        'empty' => 'Impossible de mettre en file d’attente une commande vide.',
        'distance_required' => 'Impossible de mettre en file d’attente une commande sans distance.',
        'feedrate_required' => 'Impossible de mettre en file d’attente une commande sans vitesse d’avance.',
        'extruder_required' => 'Impossible de mettre en file d’attente une commande sans extrudeur.',
        'temperature_required' => 'Impossible de mettre en file d’attente une commande sans température.',
        'temperature_numeric' => 'La température doit être un nombre.',
        'not_connected' => 'Impossible de mettre la commande en file d’attente : cette imprimante n’est pas connectée.',
    ],
    'directions' => [
        'empty' => 'Impossible de mettre en file d’attente une direction vide.',
        'invalid' => 'Direction invalide.',
    ],
    'files' => [
        'in_use' => 'Le fichier est actuellement utilisé.',
        'already_exists' => 'Le fichier existe déjà.',
        'rename_failed' => 'Impossible de renommer le fichier.',
        'upload_already_exists' => 'Le fichier existe déjà.',
        'not_found' => 'Aucun fichier correspondant.',
    ],
    'directories' => [
        'already_exists' => 'Le répertoire existe déjà.',
        'not_found' => 'Le répertoire n’existe pas.',
        'not_empty' => 'Le répertoire n’est pas vide.',
    ],
    'password' => [
        'current_password_mismatch' => 'Le mot de passe actuel ne correspond pas à nos enregistrements.',
        'must_be_different' => 'Le nouveau mot de passe doit être différent de l’actuel.',
    ],
    'notifications' => [
        'not_found' => 'Aucune notification correspondante.',
    ],
    'cameras' => [
        'cannot_delete_connected' => 'Impossible de supprimer une caméra connectée.',
        'not_found' => 'Aucune caméra correspondante.',
        'unsupported_format' => 'Cette caméra ne prend pas en charge ce format.',
    ],
    'recordings' => [
        'not_found' => 'Aucun enregistrement correspondant.',
    ],
    'users' => [
        'permission_denied' => 'Vous n’avez pas les autorisations requises.',
        'not_found' => 'Aucun utilisateur correspondant.',
        'name_in_use' => 'Le nom indiqué est déjà utilisé.',
        'email_in_use' => 'L’adresse e-mail indiquée est déjà utilisée.',
        'role_change_forbidden' => 'Vous ne pouvez pas modifier le rôle de cet utilisateur.',
        'delete_forbidden' => 'Vous ne pouvez pas supprimer cet utilisateur.',
    ],
    'plugins' => [
        'invalid_upload' => 'Le paquet téléversé n’est pas valide.',
        'install_source_required' => 'Fournissez un paquet, une URL, un unpackedPath ou un pluginId du registre.',
        'unpacked_install_development_only' => 'L’installation de plugins décompressés n’est disponible qu’en environnement de développement.',
    ],
    'validation' => [
        'hotend_required' => 'La température de la buse est obligatoire.',
        'hotend_integer' => 'La température de la buse doit être un entier.',
        'bed_required' => 'La température du plateau est obligatoire.',
        'bed_integer' => 'La température du plateau doit être un entier.',
    ],
];
