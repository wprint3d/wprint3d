export const systemSettingsTranslationExtensions = {
    en: {
        settings: {
            systemSections: {
                system: "System",
                connection: "Connection",
                limits: "Limits",
                miscellaneous: "Miscellaneous",
                advancedSettings: "Advanced settings",
            },
            systemEnums: {
                BackupInterval: {
                    everySecond: "Every second",
                    everyFiveMinutes: "Every 5 minutes",
                    never: "Never",
                },
            },
            systemConfig: {
                machineUUID: {
                    hint: "Machine UUID",
                    description: "The unique identifier for the machine.",
                },
                renderFileBlockingSecs: {
                    hint: "Render file blocking seconds",
                    description: "The time in seconds to block rendering recorded video files.",
                },
                showFirstLoginHints: {
                    hint: "Show first login hints",
                    description: "Whether to show first login hints.",
                },
                checkForUpdates: {
                    hint: "Check for updates",
                    description: "Whether to check for updates on startup.",
                },
                streamMaxLengthBytes: {
                    hint: "Stream max length bytes",
                    description: "The maximum amount of ASCII characters in a valid G-code command.",
                },
                negotiationWaitSecs: {
                    hint: "Negotiation delay",
                    description: "The time in seconds to wait for the printer to boot before trying to negotiate a connection.",
                },
                negotiationTimeoutSecs: {
                    hint: "Negotiation timeout",
                    description: "The maximum time in seconds to spend setting up a printer before it is invalidated and disabled.",
                },
                negotiationMaxRetries: {
                    hint: "Negotiation retry limit",
                    description: "The maximum number of setup attempts before the printer is invalidated and disabled.",
                },
                commandTimeoutSecs: {
                    hint: "Command timeout",
                    description: "The maximum time in seconds to wait for a printer response before repeated failures abort the job.",
                },
                runningTimeoutSecs: {
                    hint: "Busy check timeout",
                    description: "The maximum idle time in seconds after a busy state before forcing another command to the printer.",
                },
                lastSeenThresholdSecs: {
                    hint: "Last seen threshold",
                    description: "How long in seconds a printer can stay silent before it is marked offline.",
                },
                lastSeenPollIntervalSecs: {
                    hint: "Last seen poll interval",
                    description: "The interval in seconds used to ask the printer for a response.",
                },
                autoSerialIntervalSecs: {
                    hint: "Automatic poll interval",
                    description: "The interval in seconds used to poll idle printers for automated status updates such as temperature and power information.",
                },
                controlDistanceDefault: {
                    hint: "Default travel distance",
                    description: "The default distance in millimeters used to move each axis from the Control tab.",
                },
                controlDistanceMin: {
                    hint: "Minimum travel distance",
                    description: "The minimum distance in millimeters allowed when moving each axis from the Control tab.",
                },
                controlDistanceMax: {
                    hint: "Maximum travel distance",
                    description: "The maximum distance in millimeters allowed when moving each axis from the Control tab.",
                },
                controlFeedrateDefault: {
                    hint: "Default feedrate speed",
                    description: "The default speed in millimeters per second used to move each axis from the Control tab.",
                },
                controlFeedrateMin: {
                    hint: "Minimum feedrate speed",
                    description: "The minimum speed in millimeters per second allowed when moving each axis from the Control tab.",
                },
                controlFeedrateMax: {
                    hint: "Maximum feedrate speed",
                    description: "The maximum speed in millimeters per second allowed when moving each axis from the Control tab.",
                },
                controlExtrusionFeedrate: {
                    hint: "Extrusion feedrate",
                    description: "The absolute speed used to extrude material from the Control tab.",
                },
                controlExtrusionMinTemp: {
                    hint: "Minimum temperature to extrude",
                    description: "The minimum temperature required before allowing extrusion from the Control tab to avoid cold extrusion damage.",
                },
                jobBackupInterval: {
                    hint: "Backup interval",
                    description: "How often the currently active print job should be backed up.",
                },
                jobStatisticsQueryIntervalSecs: {
                    hint: "Statistics query interval",
                    description: "The interval in seconds used to query printer statistics during an active print job.",
                },
                terminalMaxLines: {
                    hint: "Maximum terminal lines",
                    description: "The maximum number of lines shown in the Terminal tab before the oldest entries are removed.",
                },
                enableHaptics: {
                    hint: "Haptic feedback",
                    description: "Whether to enable haptic feedback across the application. Mobile devices may still require disabling Do Not Disturb mode.",
                },
                debugSerial: {
                    hint: "Debug serial transactions",
                    description: "Whether to log every serial protocol transaction. This can heavily impact I/O performance on slower systems.",
                },
                enableLibCamera: {
                    hint: "Libcamera support",
                    description: "Whether to enable libcamera support for compatible cameras. Disable it if your hardware handles USB cameras better.",
                },
                jobRestorationHomingTemperature: {
                    hint: "Job restore homing temperature",
                    description: "The temperature in celsius the printer must cool down to before homing during job recovery.",
                },
                developerMode: {
                    hint: "Enable developer mode",
                    description: "Whether to enable the developer mode which shows a Development tab with tools for core and plugin developers. A page reload is required to apply changes to this setting.",
                },
                fakeSerialEnabled: {
                    hint: "Enable FakeSerial printer",
                    description: "Whether to plug in the development-only FakeSerial printer.",
                },
                fakeSerialBaudRate: {
                    hint: "FakeSerial baud rate",
                    description: "The baud rate exposed by the development-only FakeSerial printer.",
                },
                fakeSerialNode: {
                    hint: "FakeSerial node",
                    description: "The virtual serial node name used by the development-only FakeSerial printer.",
                },
            },
        },
    },
    es: {
        settings: {
            systemSections: {
                system: "Sistema",
                connection: "Conexión",
                limits: "Límites",
                miscellaneous: "Miscelánea",
                advancedSettings: "Configuración avanzada",
            },
            systemEnums: {
                BackupInterval: {
                    everySecond: "Cada segundo",
                    everyFiveMinutes: "Cada 5 minutos",
                    never: "Nunca",
                },
            },
            systemConfig: {
                machineUUID: {
                    hint: "UUID de la máquina",
                    description: "El identificador único de la máquina.",
                },
                renderFileBlockingSecs: {
                    hint: "Segundos de bloqueo del renderizado de archivos",
                    description: "El tiempo en segundos durante el cual se bloquea el renderizado de videos grabados.",
                },
                showFirstLoginHints: {
                    hint: "Mostrar consejos del primer inicio de sesión",
                    description: "Indica si se deben mostrar los consejos del primer inicio de sesión.",
                },
                checkForUpdates: {
                    hint: "Buscar actualizaciones",
                    description: "Indica si se deben buscar actualizaciones al iniciar.",
                },
                streamMaxLengthBytes: {
                    hint: "Longitud máxima del flujo en bytes",
                    description: "La cantidad máxima de caracteres ASCII permitidos en un comando G-code válido.",
                },
                negotiationWaitSecs: {
                    hint: "Retraso de negociación",
                    description: "El tiempo en segundos que se espera a que la impresora termine de arrancar antes de intentar negociar una conexión.",
                },
                negotiationTimeoutSecs: {
                    hint: "Tiempo límite de negociación",
                    description: "El tiempo máximo en segundos dedicado a configurar una impresora antes de invalidarla y deshabilitarla.",
                },
                negotiationMaxRetries: {
                    hint: "Límite de reintentos de negociación",
                    description: "La cantidad máxima de intentos de configuración antes de invalidar y deshabilitar la impresora.",
                },
                commandTimeoutSecs: {
                    hint: "Tiempo límite de comando",
                    description: "El tiempo máximo en segundos para esperar una respuesta de la impresora antes de que fallos repetidos aborten el trabajo.",
                },
                runningTimeoutSecs: {
                    hint: "Tiempo límite de verificación de ocupado",
                    description: "El tiempo máximo en segundos que la impresora puede quedar inactiva después de salir del estado ocupado antes de forzar otro comando.",
                },
                lastSeenThresholdSecs: {
                    hint: "Umbral de última actividad",
                    description: "Cuánto tiempo en segundos puede permanecer en silencio una impresora antes de marcarse como desconectada.",
                },
                lastSeenPollIntervalSecs: {
                    hint: "Intervalo de consulta de última actividad",
                    description: "El intervalo en segundos con el que se le solicita una respuesta a la impresora.",
                },
                autoSerialIntervalSecs: {
                    hint: "Intervalo de sondeo automático",
                    description: "El intervalo en segundos usado para consultar impresoras inactivas y obtener actualizaciones automáticas como temperatura y estado de energía.",
                },
                controlDistanceDefault: {
                    hint: "Distancia de desplazamiento predeterminada",
                    description: "La distancia predeterminada en milímetros usada para mover cada eje desde la pestaña Control.",
                },
                controlDistanceMin: {
                    hint: "Distancia mínima de desplazamiento",
                    description: "La distancia mínima en milímetros permitida al mover cada eje desde la pestaña Control.",
                },
                controlDistanceMax: {
                    hint: "Distancia máxima de desplazamiento",
                    description: "La distancia máxima en milímetros permitida al mover cada eje desde la pestaña Control.",
                },
                controlFeedrateDefault: {
                    hint: "Velocidad de avance predeterminada",
                    description: "La velocidad predeterminada en milímetros por segundo usada para mover cada eje desde la pestaña Control.",
                },
                controlFeedrateMin: {
                    hint: "Velocidad mínima de avance",
                    description: "La velocidad mínima en milímetros por segundo permitida al mover cada eje desde la pestaña Control.",
                },
                controlFeedrateMax: {
                    hint: "Velocidad máxima de avance",
                    description: "La velocidad máxima en milímetros por segundo permitida al mover cada eje desde la pestaña Control.",
                },
                controlExtrusionFeedrate: {
                    hint: "Velocidad de extrusión",
                    description: "La velocidad absoluta usada para extruir material desde la pestaña Control.",
                },
                controlExtrusionMinTemp: {
                    hint: "Temperatura mínima para extruir",
                    description: "La temperatura mínima requerida antes de permitir la extrusión desde la pestaña Control para evitar daños por extrusión en frío.",
                },
                jobBackupInterval: {
                    hint: "Intervalo de respaldo",
                    description: "Con qué frecuencia se debe respaldar el trabajo de impresión activo.",
                },
                jobStatisticsQueryIntervalSecs: {
                    hint: "Intervalo de consulta de estadísticas",
                    description: "El intervalo en segundos usado para consultar estadísticas de la impresora durante un trabajo activo.",
                },
                terminalMaxLines: {
                    hint: "Máximo de líneas del terminal",
                    description: "La cantidad máxima de líneas mostradas en la pestaña Terminal antes de eliminar las entradas más antiguas.",
                },
                enableHaptics: {
                    hint: "Respuesta háptica",
                    description: "Indica si se debe habilitar la respuesta háptica en toda la aplicación. En dispositivos móviles puede ser necesario desactivar No molestar.",
                },
                debugSerial: {
                    hint: "Depurar transacciones seriales",
                    description: "Indica si se deben registrar todas las transacciones del protocolo serial. Esto puede afectar seriamente el rendimiento de E/S en sistemas lentos.",
                },
                enableLibCamera: {
                    hint: "Compatibilidad con libcamera",
                    description: "Indica si se debe habilitar la compatibilidad con libcamera para cámaras compatibles. Desactívala si tu hardware funciona mejor con cámaras USB.",
                },
                jobRestorationHomingTemperature: {
                    hint: "Temperatura de homing para restauración de trabajos",
                    description: "La temperatura en grados celsius a la que la impresora debe enfriarse antes de hacer homing durante la recuperación de un trabajo.",
                },
                developerMode: {
                    hint: "Habilitar modo desarrollador",
                    description: "Activa el modo desarrollador, que muestra una pestaña de Desarrollo con herramientas para desarrolladores del núcleo y de plugins. Es necesario recargar la página para aplicar este cambio.",
                },
                fakeSerialEnabled: {
                    hint: "Habilitar impresora FakeSerial",
                    description: "Indica si se debe conectar la impresora FakeSerial disponible solo para desarrollo.",
                },
                fakeSerialBaudRate: {
                    hint: "Baud rate de FakeSerial",
                    description: "El baud rate expuesto por la impresora FakeSerial disponible solo para desarrollo.",
                },
                fakeSerialNode: {
                    hint: "Nodo de FakeSerial",
                    description: "El nombre del nodo serial virtual usado por la impresora FakeSerial disponible solo para desarrollo.",
                },
            },
        },
    },
    es_AR: null,
    fr: {
        settings: {
            systemSections: {
                system: "Système",
                connection: "Connexion",
                limits: "Limites",
                miscellaneous: "Divers",
                advancedSettings: "Paramètres avancés",
            },
            systemEnums: {
                BackupInterval: {
                    everySecond: "Chaque seconde",
                    everyFiveMinutes: "Toutes les 5 minutes",
                    never: "Jamais",
                },
            },
            systemConfig: {
                machineUUID: {
                    hint: "UUID de la machine",
                    description: "L'identifiant unique de la machine.",
                },
                renderFileBlockingSecs: {
                    hint: "Secondes de blocage du rendu des fichiers",
                    description: "Le temps, en secondes, pendant lequel le rendu des vidéos enregistrées est bloqué.",
                },
                showFirstLoginHints: {
                    hint: "Afficher les conseils de première connexion",
                    description: "Indique s'il faut afficher les conseils de première connexion.",
                },
                checkForUpdates: {
                    hint: "Rechercher des mises à jour",
                    description: "Indique s'il faut rechercher des mises à jour au démarrage.",
                },
                streamMaxLengthBytes: {
                    hint: "Longueur maximale du flux en octets",
                    description: "Le nombre maximal de caractères ASCII autorisés dans une commande G-code valide.",
                },
                negotiationWaitSecs: {
                    hint: "Délai de négociation",
                    description: "Le temps en secondes à attendre que l'imprimante démarre avant d'essayer de négocier une connexion.",
                },
                negotiationTimeoutSecs: {
                    hint: "Délai d'expiration de la négociation",
                    description: "Le temps maximal, en secondes, consacré à la configuration d'une imprimante avant de l'invalider et de la désactiver.",
                },
                negotiationMaxRetries: {
                    hint: "Limite de tentatives de négociation",
                    description: "Le nombre maximal de tentatives de configuration avant d'invalider et de désactiver l'imprimante.",
                },
                commandTimeoutSecs: {
                    hint: "Délai d'expiration des commandes",
                    description: "Le temps maximal, en secondes, pour attendre une réponse de l'imprimante avant que des échecs répétés n'annulent la tâche.",
                },
                runningTimeoutSecs: {
                    hint: "Délai de vérification d'occupation",
                    description: "Le temps maximal, en secondes, pendant lequel l'imprimante peut rester inactive après un état occupé avant de forcer une nouvelle commande.",
                },
                lastSeenThresholdSecs: {
                    hint: "Seuil de dernière activité",
                    description: "Combien de temps, en secondes, une imprimante peut rester silencieuse avant d'être marquée hors ligne.",
                },
                lastSeenPollIntervalSecs: {
                    hint: "Intervalle de sondage de dernière activité",
                    description: "L'intervalle, en secondes, utilisé pour demander une réponse à l'imprimante.",
                },
                autoSerialIntervalSecs: {
                    hint: "Intervalle de sondage automatique",
                    description: "L'intervalle, en secondes, utilisé pour interroger les imprimantes inactives et récupérer des mises à jour automatiques comme la température et l'alimentation.",
                },
                controlDistanceDefault: {
                    hint: "Distance de déplacement par défaut",
                    description: "La distance par défaut, en millimètres, utilisée pour déplacer chaque axe depuis l'onglet Contrôle.",
                },
                controlDistanceMin: {
                    hint: "Distance minimale de déplacement",
                    description: "La distance minimale, en millimètres, autorisée lors du déplacement de chaque axe depuis l'onglet Contrôle.",
                },
                controlDistanceMax: {
                    hint: "Distance maximale de déplacement",
                    description: "La distance maximale, en millimètres, autorisée lors du déplacement de chaque axe depuis l'onglet Contrôle.",
                },
                controlFeedrateDefault: {
                    hint: "Vitesse d'avance par défaut",
                    description: "La vitesse par défaut, en millimètres par seconde, utilisée pour déplacer chaque axe depuis l'onglet Contrôle.",
                },
                controlFeedrateMin: {
                    hint: "Vitesse d'avance minimale",
                    description: "La vitesse minimale, en millimètres par seconde, autorisée lors du déplacement de chaque axe depuis l'onglet Contrôle.",
                },
                controlFeedrateMax: {
                    hint: "Vitesse d'avance maximale",
                    description: "La vitesse maximale, en millimètres par seconde, autorisée lors du déplacement de chaque axe depuis l'onglet Contrôle.",
                },
                controlExtrusionFeedrate: {
                    hint: "Vitesse d'extrusion",
                    description: "La vitesse absolue utilisée pour extruder le matériau depuis l'onglet Contrôle.",
                },
                controlExtrusionMinTemp: {
                    hint: "Température minimale pour extruder",
                    description: "La température minimale requise avant d'autoriser l'extrusion depuis l'onglet Contrôle afin d'éviter les dégâts dus à une extrusion à froid.",
                },
                jobBackupInterval: {
                    hint: "Intervalle de sauvegarde",
                    description: "La fréquence à laquelle la tâche d'impression active doit être sauvegardée.",
                },
                jobStatisticsQueryIntervalSecs: {
                    hint: "Intervalle de requête des statistiques",
                    description: "L'intervalle, en secondes, utilisé pour interroger les statistiques de l'imprimante pendant une impression active.",
                },
                terminalMaxLines: {
                    hint: "Nombre maximal de lignes du terminal",
                    description: "Le nombre maximal de lignes affichées dans l'onglet Terminal avant la suppression des entrées les plus anciennes.",
                },
                enableHaptics: {
                    hint: "Retour haptique",
                    description: "Indique s'il faut activer le retour haptique dans toute l'application. Sur mobile, il peut être nécessaire de désactiver le mode Ne pas déranger.",
                },
                debugSerial: {
                    hint: "Déboguer les transactions série",
                    description: "Indique s'il faut journaliser toutes les transactions du protocole série. Cela peut fortement dégrader les performances d'E/S sur les systèmes plus lents.",
                },
                enableLibCamera: {
                    hint: "Prise en charge de libcamera",
                    description: "Indique s'il faut activer la prise en charge de libcamera pour les caméras compatibles. Désactivez-la si votre matériel gère mieux les caméras USB.",
                },
                jobRestorationHomingTemperature: {
                    hint: "Température de homing pour la restauration de tâche",
                    description: "La température, en degrés Celsius, à laquelle l'imprimante doit redescendre avant de refaire un homing pendant la récupération d'une tâche.",
                },
                developerMode: {
                    hint: "Activer le mode développeur",
                    description: "Active le mode développeur, qui affiche un onglet Développement avec des outils pour les développeurs du noyau et des plugins. Un rechargement de la page est nécessaire pour appliquer ce changement.",
                },
                fakeSerialEnabled: {
                    hint: "Activer l'imprimante FakeSerial",
                    description: "Indique s'il faut brancher l'imprimante FakeSerial réservée au développement.",
                },
                fakeSerialBaudRate: {
                    hint: "Débit en bauds de FakeSerial",
                    description: "Le débit en bauds exposé par l'imprimante FakeSerial réservée au développement.",
                },
                fakeSerialNode: {
                    hint: "Nœud FakeSerial",
                    description: "Le nom du nœud série virtuel utilisé par l'imprimante FakeSerial réservée au développement.",
                },
            },
        },
    },
    pt: {
        settings: {
            systemSections: {
                system: "Sistema",
                connection: "Conexao",
                limits: "Limites",
                miscellaneous: "Diversos",
                advancedSettings: "Configuracoes avancadas",
            },
            systemEnums: {
                BackupInterval: {
                    everySecond: "A cada segundo",
                    everyFiveMinutes: "A cada 5 minutos",
                    never: "Nunca",
                },
            },
            systemConfig: {
                machineUUID: {
                    hint: "UUID da maquina",
                    description: "O identificador unico da maquina.",
                },
                renderFileBlockingSecs: {
                    hint: "Segundos de bloqueio da renderizacao de arquivos",
                    description: "O tempo, em segundos, durante o qual a renderizacao de videos gravados fica bloqueada.",
                },
                showFirstLoginHints: {
                    hint: "Mostrar dicas do primeiro login",
                    description: "Define se as dicas do primeiro login devem ser exibidas.",
                },
                checkForUpdates: {
                    hint: "Verificar atualizacoes",
                    description: "Define se atualizacoes devem ser verificadas na inicializacao.",
                },
                streamMaxLengthBytes: {
                    hint: "Comprimento maximo do fluxo em bytes",
                    description: "A quantidade maxima de caracteres ASCII permitidos em um comando G-code valido.",
                },
                negotiationWaitSecs: {
                    hint: "Atraso da negociacao",
                    description: "O tempo, em segundos, para esperar a impressora iniciar antes de tentar negociar uma conexao.",
                },
                negotiationTimeoutSecs: {
                    hint: "Tempo limite da negociacao",
                    description: "O tempo maximo, em segundos, gasto na configuracao de uma impressora antes de invalida-la e desativa-la.",
                },
                negotiationMaxRetries: {
                    hint: "Limite de tentativas da negociacao",
                    description: "A quantidade maxima de tentativas de configuracao antes de invalidar e desativar a impressora.",
                },
                commandTimeoutSecs: {
                    hint: "Tempo limite do comando",
                    description: "O tempo maximo, em segundos, para esperar uma resposta da impressora antes que falhas repetidas cancelem o trabalho.",
                },
                runningTimeoutSecs: {
                    hint: "Tempo limite da verificacao de ocupado",
                    description: "O tempo maximo, em segundos, em que a impressora pode ficar ociosa apos um estado ocupado antes de forcar um novo comando.",
                },
                lastSeenThresholdSecs: {
                    hint: "Limite de ultima atividade",
                    description: "Por quanto tempo, em segundos, a impressora pode ficar silenciosa antes de ser marcada como offline.",
                },
                lastSeenPollIntervalSecs: {
                    hint: "Intervalo de consulta da ultima atividade",
                    description: "O intervalo, em segundos, usado para solicitar uma resposta da impressora.",
                },
                autoSerialIntervalSecs: {
                    hint: "Intervalo de sondagem automatica",
                    description: "O intervalo, em segundos, usado para consultar impressoras ociosas e obter atualizacoes automaticas, como temperatura e energia.",
                },
                controlDistanceDefault: {
                    hint: "Distancia de deslocamento padrao",
                    description: "A distancia padrao, em milimetros, usada para mover cada eixo na aba Controle.",
                },
                controlDistanceMin: {
                    hint: "Distancia minima de deslocamento",
                    description: "A distancia minima, em milimetros, permitida ao mover cada eixo na aba Controle.",
                },
                controlDistanceMax: {
                    hint: "Distancia maxima de deslocamento",
                    description: "A distancia maxima, em milimetros, permitida ao mover cada eixo na aba Controle.",
                },
                controlFeedrateDefault: {
                    hint: "Velocidade de avanco padrao",
                    description: "A velocidade padrao, em milimetros por segundo, usada para mover cada eixo na aba Controle.",
                },
                controlFeedrateMin: {
                    hint: "Velocidade minima de avanco",
                    description: "A velocidade minima, em milimetros por segundo, permitida ao mover cada eixo na aba Controle.",
                },
                controlFeedrateMax: {
                    hint: "Velocidade maxima de avanco",
                    description: "A velocidade maxima, em milimetros por segundo, permitida ao mover cada eixo na aba Controle.",
                },
                controlExtrusionFeedrate: {
                    hint: "Velocidade de extrusao",
                    description: "A velocidade absoluta usada para extrudar material na aba Controle.",
                },
                controlExtrusionMinTemp: {
                    hint: "Temperatura minima para extrusar",
                    description: "A temperatura minima exigida antes de permitir a extrusao na aba Controle para evitar danos por extrusao fria.",
                },
                jobBackupInterval: {
                    hint: "Intervalo de backup",
                    description: "Com que frequencia o trabalho de impressao ativo deve ser salvo em backup.",
                },
                jobStatisticsQueryIntervalSecs: {
                    hint: "Intervalo de consulta de estatisticas",
                    description: "O intervalo, em segundos, usado para consultar as estatisticas da impressora durante um trabalho ativo.",
                },
                terminalMaxLines: {
                    hint: "Maximo de linhas do terminal",
                    description: "A quantidade maxima de linhas exibidas na aba Terminal antes de remover as entradas mais antigas.",
                },
                enableHaptics: {
                    hint: "Feedback haptico",
                    description: "Define se o feedback haptico deve ser ativado em toda a aplicacao. Em dispositivos moveis pode ser necessario desativar o modo Nao perturbe.",
                },
                debugSerial: {
                    hint: "Depurar transacoes seriais",
                    description: "Define se todas as transacoes do protocolo serial devem ser registradas. Isso pode afetar fortemente o desempenho de E/S em sistemas lentos.",
                },
                enableLibCamera: {
                    hint: "Suporte ao libcamera",
                    description: "Define se o suporte ao libcamera deve ser ativado para cameras compativeis. Desative se o seu hardware funcionar melhor com cameras USB.",
                },
                jobRestorationHomingTemperature: {
                    hint: "Temperatura de homing para restauracao do trabalho",
                    description: "A temperatura, em graus Celsius, para a qual a impressora deve esfriar antes de fazer homing durante a recuperacao de um trabalho.",
                },
                developerMode: {
                    hint: "Ativar modo desenvolvedor",
                    description: "Ativa o modo desenvolvedor, que mostra uma aba de Desenvolvimento com ferramentas para desenvolvedores do nucleo e de plugins. E necessario recarregar a pagina para aplicar essa alteracao.",
                },
                fakeSerialEnabled: {
                    hint: "Ativar impressora FakeSerial",
                    description: "Define se a impressora FakeSerial, disponivel apenas para desenvolvimento, deve ser conectada.",
                },
                fakeSerialBaudRate: {
                    hint: "Baud rate da FakeSerial",
                    description: "O baud rate exposto pela impressora FakeSerial, disponivel apenas para desenvolvimento.",
                },
                fakeSerialNode: {
                    hint: "No da FakeSerial",
                    description: "O nome do no serial virtual usado pela impressora FakeSerial, disponivel apenas para desenvolvimento.",
                },
            },
        },
    },
    it: {
        settings: {
            systemSections: {
                system: "Sistema",
                connection: "Connessione",
                limits: "Limiti",
                miscellaneous: "Varie",
                advancedSettings: "Impostazioni avanzate",
            },
            systemEnums: {
                BackupInterval: {
                    everySecond: "Ogni secondo",
                    everyFiveMinutes: "Ogni 5 minuti",
                    never: "Mai",
                },
            },
            systemConfig: {
                machineUUID: {
                    hint: "UUID della macchina",
                    description: "L'identificatore univoco della macchina.",
                },
                renderFileBlockingSecs: {
                    hint: "Secondi di blocco del rendering dei file",
                    description: "Il tempo, in secondi, durante il quale il rendering dei video registrati viene bloccato.",
                },
                showFirstLoginHints: {
                    hint: "Mostra i suggerimenti del primo accesso",
                    description: "Indica se mostrare i suggerimenti del primo accesso.",
                },
                checkForUpdates: {
                    hint: "Controlla aggiornamenti",
                    description: "Indica se controllare la disponibilita di aggiornamenti all'avvio.",
                },
                streamMaxLengthBytes: {
                    hint: "Lunghezza massima del flusso in byte",
                    description: "Il numero massimo di caratteri ASCII consentiti in un comando G-code valido.",
                },
                negotiationWaitSecs: {
                    hint: "Ritardo di negoziazione",
                    description: "Il tempo, in secondi, da attendere per l'avvio della stampante prima di tentare la negoziazione della connessione.",
                },
                negotiationTimeoutSecs: {
                    hint: "Timeout di negoziazione",
                    description: "Il tempo massimo, in secondi, dedicato alla configurazione di una stampante prima di invalidarla e disabilitarla.",
                },
                negotiationMaxRetries: {
                    hint: "Limite di tentativi di negoziazione",
                    description: "Il numero massimo di tentativi di configurazione prima di invalidare e disabilitare la stampante.",
                },
                commandTimeoutSecs: {
                    hint: "Timeout del comando",
                    description: "Il tempo massimo, in secondi, per attendere una risposta della stampante prima che fallimenti ripetuti annullino il lavoro.",
                },
                runningTimeoutSecs: {
                    hint: "Timeout del controllo di occupazione",
                    description: "Il tempo massimo, in secondi, in cui la stampante puo restare inattiva dopo uno stato occupato prima di forzare un nuovo comando.",
                },
                lastSeenThresholdSecs: {
                    hint: "Soglia ultima attivita",
                    description: "Per quanto tempo, in secondi, una stampante puo restare silenziosa prima di essere segnata come offline.",
                },
                lastSeenPollIntervalSecs: {
                    hint: "Intervallo di controllo ultima attivita",
                    description: "L'intervallo, in secondi, usato per richiedere una risposta alla stampante.",
                },
                autoSerialIntervalSecs: {
                    hint: "Intervallo di polling automatico",
                    description: "L'intervallo, in secondi, usato per interrogare le stampanti inattive e ottenere aggiornamenti automatici, come temperatura e alimentazione.",
                },
                controlDistanceDefault: {
                    hint: "Distanza di spostamento predefinita",
                    description: "La distanza predefinita, in millimetri, usata per spostare ogni asse dalla scheda Controllo.",
                },
                controlDistanceMin: {
                    hint: "Distanza minima di spostamento",
                    description: "La distanza minima, in millimetri, consentita quando si sposta ogni asse dalla scheda Controllo.",
                },
                controlDistanceMax: {
                    hint: "Distanza massima di spostamento",
                    description: "La distanza massima, in millimetri, consentita quando si sposta ogni asse dalla scheda Controllo.",
                },
                controlFeedrateDefault: {
                    hint: "Velocita di avanzamento predefinita",
                    description: "La velocita predefinita, in millimetri al secondo, usata per spostare ogni asse dalla scheda Controllo.",
                },
                controlFeedrateMin: {
                    hint: "Velocita minima di avanzamento",
                    description: "La velocita minima, in millimetri al secondo, consentita quando si sposta ogni asse dalla scheda Controllo.",
                },
                controlFeedrateMax: {
                    hint: "Velocita massima di avanzamento",
                    description: "La velocita massima, in millimetri al secondo, consentita quando si sposta ogni asse dalla scheda Controllo.",
                },
                controlExtrusionFeedrate: {
                    hint: "Velocita di estrusione",
                    description: "La velocita assoluta usata per estrudere materiale dalla scheda Controllo.",
                },
                controlExtrusionMinTemp: {
                    hint: "Temperatura minima per estrudere",
                    description: "La temperatura minima richiesta prima di consentire l'estrusione dalla scheda Controllo per evitare danni da estrusione a freddo.",
                },
                jobBackupInterval: {
                    hint: "Intervallo di backup",
                    description: "Con quale frequenza deve essere eseguito il backup del lavoro di stampa attivo.",
                },
                jobStatisticsQueryIntervalSecs: {
                    hint: "Intervallo di interrogazione statistiche",
                    description: "L'intervallo, in secondi, usato per interrogare le statistiche della stampante durante un lavoro attivo.",
                },
                terminalMaxLines: {
                    hint: "Numero massimo di righe del terminale",
                    description: "Il numero massimo di righe mostrate nella scheda Terminale prima di rimuovere le voci piu vecchie.",
                },
                enableHaptics: {
                    hint: "Feedback aptico",
                    description: "Indica se attivare il feedback aptico in tutta l'applicazione. Sui dispositivi mobili potrebbe essere necessario disattivare la modalita Non disturbare.",
                },
                debugSerial: {
                    hint: "Debug delle transazioni seriali",
                    description: "Indica se registrare tutte le transazioni del protocollo seriale. Questo puo influire pesantemente sulle prestazioni di I/O nei sistemi piu lenti.",
                },
                enableLibCamera: {
                    hint: "Supporto libcamera",
                    description: "Indica se attivare il supporto libcamera per le fotocamere compatibili. Disattivalo se il tuo hardware funziona meglio con fotocamere USB.",
                },
                jobRestorationHomingTemperature: {
                    hint: "Temperatura di homing per il ripristino del lavoro",
                    description: "La temperatura, in gradi Celsius, a cui la stampante deve raffreddarsi prima di eseguire l'homing durante il recupero di un lavoro.",
                },
                developerMode: {
                    hint: "Abilita modalita sviluppatore",
                    description: "Attiva la modalita sviluppatore, che mostra una scheda Sviluppo con strumenti per sviluppatori del core e dei plugin. Per applicare la modifica e necessario ricaricare la pagina.",
                },
                fakeSerialEnabled: {
                    hint: "Abilita stampante FakeSerial",
                    description: "Indica se collegare la stampante FakeSerial disponibile solo per lo sviluppo.",
                },
                fakeSerialBaudRate: {
                    hint: "Baud rate di FakeSerial",
                    description: "Il baud rate esposto dalla stampante FakeSerial disponibile solo per lo sviluppo.",
                },
                fakeSerialNode: {
                    hint: "Nodo FakeSerial",
                    description: "Il nome del nodo seriale virtuale usato dalla stampante FakeSerial disponibile solo per lo sviluppo.",
                },
            },
        },
    },
    de: {
        settings: {
            systemSections: {
                system: "System",
                connection: "Verbindung",
                limits: "Grenzwerte",
                miscellaneous: "Verschiedenes",
                advancedSettings: "Erweiterte Einstellungen",
            },
            systemEnums: {
                BackupInterval: {
                    everySecond: "Jede Sekunde",
                    everyFiveMinutes: "Alle 5 Minuten",
                    never: "Nie",
                },
            },
            systemConfig: {
                machineUUID: {
                    hint: "Maschinen-UUID",
                    description: "Die eindeutige Kennung der Maschine.",
                },
                renderFileBlockingSecs: {
                    hint: "Blockierungssekunden fuer Dateirendering",
                    description: "Die Zeit in Sekunden, fuer die das Rendern aufgezeichneter Videos blockiert wird.",
                },
                showFirstLoginHints: {
                    hint: "Hinweise beim ersten Login anzeigen",
                    description: "Legt fest, ob Hinweise beim ersten Login angezeigt werden.",
                },
                checkForUpdates: {
                    hint: "Nach Updates suchen",
                    description: "Legt fest, ob beim Start nach Updates gesucht wird.",
                },
                streamMaxLengthBytes: {
                    hint: "Maximale Stream-Laenge in Bytes",
                    description: "Die maximale Anzahl an ASCII-Zeichen, die in einem gueltigen G-code-Befehl erlaubt ist.",
                },
                negotiationWaitSecs: {
                    hint: "Verbindungsverzoegerung",
                    description: "Die Zeit in Sekunden, die gewartet wird, bis der Drucker hochgefahren ist, bevor eine Verbindung ausgehandelt wird.",
                },
                negotiationTimeoutSecs: {
                    hint: "Zeitlimit fuer Verhandlung",
                    description: "Die maximale Zeit in Sekunden fuer die Einrichtung eines Druckers, bevor er invalidiert und deaktiviert wird.",
                },
                negotiationMaxRetries: {
                    hint: "Maximale Verhandlungsversuche",
                    description: "Die maximale Anzahl an Einrichtungsversuchen, bevor der Drucker invalidiert und deaktiviert wird.",
                },
                commandTimeoutSecs: {
                    hint: "Befehls-Timeout",
                    description: "Die maximale Zeit in Sekunden, um auf eine Antwort des Druckers zu warten, bevor wiederholte Fehler den Auftrag abbrechen.",
                },
                runningTimeoutSecs: {
                    hint: "Zeitlimit fuer Beschaeftigt-Pruefung",
                    description: "Die maximale Leerlaufzeit in Sekunden nach einem Busy-Zustand, bevor ein neuer Befehl an den Drucker erzwungen wird.",
                },
                lastSeenThresholdSecs: {
                    hint: "Schwelle fuer letzte Aktivitaet",
                    description: "Wie lange ein Drucker in Sekunden still sein darf, bevor er als offline markiert wird.",
                },
                lastSeenPollIntervalSecs: {
                    hint: "Abfrageintervall fuer letzte Aktivitaet",
                    description: "Das Intervall in Sekunden, in dem der Drucker nach einer Antwort gefragt wird.",
                },
                autoSerialIntervalSecs: {
                    hint: "Automatisches Polling-Intervall",
                    description: "Das Intervall in Sekunden, mit dem inaktive Drucker fuer automatische Statusaktualisierungen wie Temperatur und Stromversorgung abgefragt werden.",
                },
                controlDistanceDefault: {
                    hint: "Standardfahrstrecke",
                    description: "Die Standarddistanz in Millimetern, die zum Bewegen jeder Achse im Steuerungs-Tab verwendet wird.",
                },
                controlDistanceMin: {
                    hint: "Minimale Fahrstrecke",
                    description: "Die minimale Distanz in Millimetern, die beim Bewegen jeder Achse im Steuerungs-Tab erlaubt ist.",
                },
                controlDistanceMax: {
                    hint: "Maximale Fahrstrecke",
                    description: "Die maximale Distanz in Millimetern, die beim Bewegen jeder Achse im Steuerungs-Tab erlaubt ist.",
                },
                controlFeedrateDefault: {
                    hint: "Standard-Vorschubgeschwindigkeit",
                    description: "Die Standardgeschwindigkeit in Millimetern pro Sekunde, die zum Bewegen jeder Achse im Steuerungs-Tab verwendet wird.",
                },
                controlFeedrateMin: {
                    hint: "Minimale Vorschubgeschwindigkeit",
                    description: "Die minimale Geschwindigkeit in Millimetern pro Sekunde, die beim Bewegen jeder Achse im Steuerungs-Tab erlaubt ist.",
                },
                controlFeedrateMax: {
                    hint: "Maximale Vorschubgeschwindigkeit",
                    description: "Die maximale Geschwindigkeit in Millimetern pro Sekunde, die beim Bewegen jeder Achse im Steuerungs-Tab erlaubt ist.",
                },
                controlExtrusionFeedrate: {
                    hint: "Extrusionsvorschub",
                    description: "Die absolute Geschwindigkeit, die zum Extrudieren von Material im Steuerungs-Tab verwendet wird.",
                },
                controlExtrusionMinTemp: {
                    hint: "Minimale Temperatur zum Extrudieren",
                    description: "Die minimale Temperatur, die vor dem Extrudieren im Steuerungs-Tab erreicht sein muss, um Schaeden durch Kaltextrusion zu vermeiden.",
                },
                jobBackupInterval: {
                    hint: "Backup-Intervall",
                    description: "Wie haeufig der aktuell aktive Druckauftrag gesichert werden soll.",
                },
                jobStatisticsQueryIntervalSecs: {
                    hint: "Abfrageintervall fuer Statistiken",
                    description: "Das Intervall in Sekunden, in dem Druckerstatistiken waehrend eines aktiven Druckauftrags abgefragt werden.",
                },
                terminalMaxLines: {
                    hint: "Maximale Terminalzeilen",
                    description: "Die maximale Anzahl an Zeilen im Terminal-Tab, bevor die aeltesten Eintraege entfernt werden.",
                },
                enableHaptics: {
                    hint: "Haptisches Feedback",
                    description: "Legt fest, ob haptisches Feedback in der gesamten Anwendung aktiviert wird. Auf Mobilgeraeten muss moeglicherweise Nicht stoeren deaktiviert werden.",
                },
                debugSerial: {
                    hint: "Serielle Transaktionen debuggen",
                    description: "Legt fest, ob alle Transaktionen des seriellen Protokolls protokolliert werden. Das kann die I/O-Leistung auf langsameren Systemen stark beeintraechtigen.",
                },
                enableLibCamera: {
                    hint: "Libcamera-Unterstuetzung",
                    description: "Legt fest, ob die Libcamera-Unterstuetzung fuer kompatible Kameras aktiviert wird. Deaktiviere sie, wenn deine Hardware mit USB-Kameras besser arbeitet.",
                },
                jobRestorationHomingTemperature: {
                    hint: "Homing-Temperatur fuer Wiederherstellung",
                    description: "Die Temperatur in Grad Celsius, auf die der Drucker vor dem Homing waehrend der Auftragswiederherstellung abkuehlen muss.",
                },
                developerMode: {
                    hint: "Entwicklermodus aktivieren",
                    description: "Aktiviert den Entwicklermodus, der einen Entwicklungs-Tab mit Werkzeugen fuer Core- und Plugin-Entwickler anzeigt. Zum Anwenden der Aenderung ist ein Neuladen der Seite erforderlich.",
                },
                fakeSerialEnabled: {
                    hint: "FakeSerial-Drucker aktivieren",
                    description: "Legt fest, ob der nur fuer die Entwicklung gedachte FakeSerial-Drucker eingebunden werden soll.",
                },
                fakeSerialBaudRate: {
                    hint: "FakeSerial-Baudrate",
                    description: "Die Baudrate, die vom nur fuer die Entwicklung gedachten FakeSerial-Drucker bereitgestellt wird.",
                },
                fakeSerialNode: {
                    hint: "FakeSerial-Knoten",
                    description: "Der virtuelle serielle Knotenname, der vom nur fuer die Entwicklung gedachten FakeSerial-Drucker verwendet wird.",
                },
            },
        },
    },
};

systemSettingsTranslationExtensions.es_AR = systemSettingsTranslationExtensions.es;
