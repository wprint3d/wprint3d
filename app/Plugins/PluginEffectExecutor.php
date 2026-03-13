<?php

namespace App\Plugins;

use App\Enums\ToastMessageType;
use App\Events\ToastMessage;
use App\Models\Printer;
use Illuminate\Support\Facades\Log;

class PluginEffectExecutor
{
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
