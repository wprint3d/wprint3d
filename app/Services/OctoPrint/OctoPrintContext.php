<?php

namespace App\Services\OctoPrint;

use App\Models\Printer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use MongoDB\BSON\Regex;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OctoPrintContext
{
    private const SESSION_PRINTER_UUID = 'octoprint.printer_uuid';

    public function printer(Request $request): Printer
    {
        $token = $request->user()?->currentAccessToken();
        $tokenUuid = $token?->printer_uuid;
        $headerUuid = $request->header('X-WPrint3D-Printer-UUID');

        if ($tokenUuid && $headerUuid && ! hash_equals($tokenUuid, $headerUuid)) {
            throw new HttpException(403, 'This API key is bound to a different printer.');
        }

        $uuid = $tokenUuid ?: $headerUuid ?: $request->session()->get(self::SESSION_PRINTER_UUID);

        if (! $uuid) {
            $printers = Printer::whereNotNull('machine.uuid')->get();

            if ($printers->count() === 1) {
                $uuid = data_get($printers->first(), 'machine.uuid');
                $request->session()->put(self::SESSION_PRINTER_UUID, $uuid);
            }
        }

        if (! is_string($uuid) || $uuid === '') {
            throw new HttpException(409, 'Select a WPrint 3D printer first.');
        }

        $printer = $this->findPrinter($uuid);

        if (! $printer) {
            throw new HttpException(409, 'The selected printer is no longer available.');
        }

        if (! $tokenUuid) {
            $request->session()->put(self::SESSION_PRINTER_UUID, $uuid);
        }

        return $printer;
    }

    public function selectPrinter(Request $request, string $uuid): Printer
    {
        $token = $request->user()?->currentAccessToken();

        if ($token?->printer_uuid && ! hash_equals($token->printer_uuid, $uuid)) {
            throw new HttpException(403, 'API keys cannot be moved to another printer.');
        }

        $printer = $this->findPrinter($uuid);

        if (! $printer) {
            throw new HttpException(404, 'Printer not found.');
        }

        Cache::store('redis')->lock('printer-selection:'.$uuid, 10)->block(3, function () use ($request, $uuid) {
            $request->session()->put(self::SESSION_PRINTER_UUID, $uuid);
        });

        return $printer;
    }

    public function selectedPrinterUuid(Request $request): ?string
    {
        return $request->user()?->currentAccessToken()?->printer_uuid
            ?: $request->session()->get(self::SESSION_PRINTER_UUID);
    }

    public function selectedFile(Request $request): ?string
    {
        return Cache::get($this->selectedFileKey($request));
    }

    public function setSelectedFile(Request $request, ?string $path): void
    {
        $key = $this->selectedFileKey($request);

        if ($path === null) {
            Cache::forget($key);

            return;
        }

        Cache::put($key, $path, now()->addDays(30));
    }

    private function selectedFileKey(Request $request): string
    {
        $token = $request->user()?->currentAccessToken();
        $identity = $token ? 'token:'.$token->_id : 'session:'.$request->session()->getId();
        $uuid = $token?->printer_uuid ?: $request->session()->get(self::SESSION_PRINTER_UUID, 'none');

        return 'octoprint:selected-file:'.$identity.':'.$uuid;
    }

    private function findPrinter(string $uuid): ?Printer
    {
        $printer = Printer::where('machine.uuid', $uuid)
            ->orderByDesc('connected')
            ->orderByDesc('updated_at')
            ->first();

        if ($printer && ($printer->connected || data_get($printer, 'machine.connectionType') !== 'fakeSerial')) {
            return $printer;
        }

        $baseUuid = Str::before($uuid, '/');

        return Printer::where('connected', true)
            ->where('machine.connectionType', 'fakeSerial')
            ->whereRaw([
                'machine.uuid' => new Regex('^'.preg_quote($baseUuid, '/').'(/.*)?$', 'i'),
            ])
            ->orderByDesc('updated_at')
            ->first() ?: $printer;
    }
}
