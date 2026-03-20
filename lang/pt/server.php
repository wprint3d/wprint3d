<?php

return [
    'auth' => [
        'invalid_credentials' => 'Essa combinação de nome de usuário ou endereço de e-mail e senha não corresponde aos nossos registros.',
    ],
    'materials' => [
        'duplicate_name' => 'Já existe outro material com o mesmo nome.',
        'not_found' => 'Não existe esse material.',
    ],
    'printers' => [
        'not_found' => 'Não existe essa impressora.',
        'not_selected' => 'Nenhuma impressora selecionada.',
        'not_connected' => 'Não foi possível concluir a ação: esta impressora não está conectada.',
        'connected' => 'Não foi possível concluir a ação: esta impressora está conectada.',
        'active_file_exists' => 'Não foi possível concluir a ação: há um arquivo ativo.',
        'no_active_file' => 'Não foi possível concluir a ação: não há nenhum arquivo ativo.',
        'active_file_already_present' => 'Não foi possível concluir a ação: já existe um arquivo ativo.',
        'mapper_running' => 'Não foi possível concluir a ação: o mapeador está em execução. Tente novamente em alguns segundos.',
        'unknown_node' => 'Não conhecemos o nó desta impressora. Desconecte o cabo USB e conecte-o novamente, aguarde alguns segundos e tente outra vez.',
        'failed_assert_absolute_position' => 'Não foi possível determinar a posição absoluta (contexto insuficiente no G-code).',
    ],
    'commands' => [
        'empty' => 'Não é possível enfileirar um comando vazio.',
        'distance_required' => 'Não é possível enfileirar um comando sem uma distância.',
        'feedrate_required' => 'Não é possível enfileirar um comando sem uma velocidade de avanço.',
        'extruder_required' => 'Não é possível enfileirar um comando sem um extrusor.',
        'temperature_required' => 'Não é possível enfileirar um comando sem uma temperatura.',
        'temperature_numeric' => 'A temperatura deve ser um número.',
        'not_connected' => 'Não foi possível enfileirar o comando: esta impressora não está conectada.',
    ],
    'directions' => [
        'empty' => 'Não é possível enfileirar uma direção vazia.',
        'invalid' => 'Direção inválida.',
    ],
    'files' => [
        'in_use' => 'O arquivo está em uso.',
        'already_exists' => 'O arquivo já existe.',
        'rename_failed' => 'Não foi possível renomear o arquivo.',
        'upload_already_exists' => 'O arquivo já existe.',
        'not_found' => 'Não existe esse arquivo.',
    ],
    'directories' => [
        'already_exists' => 'O diretório já existe.',
        'not_found' => 'O diretório não existe.',
        'not_empty' => 'O diretório não está vazio.',
    ],
    'password' => [
        'current_password_mismatch' => 'A senha atual não corresponde aos nossos registros.',
        'must_be_different' => 'A nova senha deve ser diferente da atual.',
    ],
    'notifications' => [
        'not_found' => 'Não existe essa notificação.',
    ],
    'cameras' => [
        'cannot_delete_connected' => 'Não é possível excluir uma câmera conectada.',
        'not_found' => 'Não existe essa câmera.',
        'unsupported_format' => 'A câmera não oferece suporte a este formato.',
    ],
    'recordings' => [
        'not_found' => 'Não existe essa gravação.',
    ],
    'users' => [
        'permission_denied' => 'Você não tem as permissões necessárias.',
        'not_found' => 'Não existe esse usuário.',
        'name_in_use' => 'O nome informado já está em uso.',
        'email_in_use' => 'O e-mail informado já está em uso.',
        'role_change_forbidden' => 'Você não pode alterar a função deste usuário.',
        'delete_forbidden' => 'Você não pode excluir este usuário.',
    ],
    'plugins' => [
        'invalid_upload' => 'O pacote enviado é inválido.',
        'install_source_required' => 'Forneça um pacote, uma URL, um unpackedPath ou um pluginId do registro.',
        'unpacked_install_development_only' => 'A instalação de plugins descompactados está disponível apenas no ambiente de desenvolvimento.',
    ],
    'validation' => [
        'hotend_required' => 'A temperatura do hotend é obrigatória.',
        'hotend_integer' => 'A temperatura do hotend deve ser um número inteiro.',
        'bed_required' => 'A temperatura da mesa é obrigatória.',
        'bed_integer' => 'A temperatura da mesa deve ser um número inteiro.',
    ],
];
