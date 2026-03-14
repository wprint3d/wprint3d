<?php

namespace App\Http\Controllers;

use App\Models\Printer;
use App\Support\FakeSerial\FakeSerialManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;

class DeveloperFakeSerialController extends Controller
{
    public function show(FakeSerialManager $fakeSerialManager): array
    {
        return $this->buildPayload($fakeSerialManager);
    }

    public function update(Request $request, FakeSerialManager $fakeSerialManager): array
    {
        $supportedBaudRates = $fakeSerialManager->getDeveloperState()['supportedBaudRates'];

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'baudRate' => ['required', 'integer', Rule::in($supportedBaudRates)],
        ]);

        $fakeSerialManager->updateSettings(
            enabled: $validated['enabled'],
            baudRate: $validated['baudRate']
        );

        Artisan::call('map:serial-printers');

        return $this->buildPayload($fakeSerialManager);
    }

    private function buildPayload(FakeSerialManager $fakeSerialManager): array
    {
        $state = $fakeSerialManager->getDeveloperState();
        $printer = Printer::select('_id', 'connected', 'node', 'machine.machineType', 'machine.uuid', 'machine.connectionType')
            ->where('node', $state['node'])
            ->first();

        $state['printer'] = $printer;

        return $state;
    }
}
