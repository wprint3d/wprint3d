<?php

namespace App\Plugins;

use App\Enums\ToastMessageType;
use App\Events\ToastMessage;
use App\Models\Printer;
use Illuminate\Support\Facades\Log;

class PluginEffectExecutor
{
    public function __construct(
        private PluginStateStore $pluginStateStore,
    ) {}

    public function execute(array $plugin, array $effects): void
    {
        foreach ($effects as $effect) {
            $type = $effect['type'] ?? null;

            if ($type === 'queue_printer_command') {
                if (!in_array('printer.command.queue', $plugin['permissions'] ?? [], true)) {
                    continue;
                }

                $printer = Printer::find($effect['printerId'] ?? null);

                if ($printer && !empty($effect['command'])) {
                    $printer->queueCommand($effect['command']);
                }

                continue;
            }

            if ($type === 'toast_message') {
                if (!empty($effect['userId']) && !empty($effect['message'])) {
                    ToastMessage::dispatch(
                        $effect['userId'],
                        $effect['toastType'] ?? ToastMessageType::INFO,
                        $effect['message']
                    );
                }

                continue;
            }

            if (in_array($type, ['publish_state', 'send_plugin_message'], true)) {
                $data = $effect['data'] ?? [];

                if (is_array($data) && ! empty($plugin['id'])) {
                    $this->pluginStateStore->publish(
                        $plugin['id'],
                        $data,
                        (bool) ($effect['merge'] ?? true),
                    );
                }

                continue;
            }

            if ($type === 'log') {
                Log::info('Plugin effect log', [
                    'plugin' => $plugin['id'] ?? null,
                    'message' => $effect['message'] ?? '',
                    'context' => $effect['context'] ?? [],
                ]);
            }
        }
    }
}
