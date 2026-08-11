<?php

namespace App\Services;

use App\Exceptions\PrinterSlicingRevisionConflict;
use App\Models\Plugin;
use App\Models\Printer;
use App\Models\User;
use App\Plugins\Exceptions\PluginRuntimeException;
use App\Plugins\Runtime\PluginRuntimeHttpClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;

class PrinterSlicingConfigurationService
{
    private const SCHEMA_VERSION = 1;

    private const PROVIDER = 'cura';

    public function __construct(
        private readonly PluginRuntimeHttpClient $runtimeHttpClient,
    ) {}

    public function configuration(Printer $printer, ?User $user = null, bool $includeProposal = true): array
    {
        $stored = $this->storedConfiguration($printer);
        if ($stored !== null) {
            return [
                'status' => $this->status($stored),
                'revision' => (int) ($stored['revision'] ?? 0),
                'configuration' => $stored,
                'proposal' => null,
            ];
        }

        $result = [
            'status' => 'missing',
            'revision' => 0,
            'configuration' => null,
            'proposal' => null,
        ];

        if (! $includeProposal) {
            return $result;
        }

        try {
            $candidate = collect($this->candidates($printer, null, $user)['items'])
                ->first(fn (array $item): bool => ($item['score'] ?? 0) >= 40);
            if (! $candidate) {
                return $result;
            }

            $resolved = $this->resolveMachine(
                $user,
                (string) $candidate['definitionId'],
                [],
            );
            $result['status'] = 'unconfirmed';
            $result['proposal'] = array_merge($candidate, [
                'snapshot' => $resolved['snapshot'],
                'snapshotHash' => $resolved['snapshotHash'],
            ]);
        } catch (\Throwable $exception) {
            $result['discoveryError'] = [
                'code' => 'slicer_catalog_unavailable',
                'message' => $exception->getMessage(),
            ];
        }

        return $result;
    }

    public function candidates(
        Printer $printer,
        ?string $query = null,
        ?User $user = null,
        ?string $selectedDefinitionId = null,
    ): array {
        $query = trim((string) $query);
        $path = '/api/v2/machines/catalog';
        if ($query !== '') {
            $path .= '?'.http_build_query(['q' => $query], '', '&', PHP_QUERY_RFC3986);
        }

        $response = $this->runtimeHttpClient
            ->get($this->runtimePlugin($user), $path)
            ->throw();
        $payload = $response->json();
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $items = array_values(array_filter(array_map(
            fn (mixed $item): ?array => $this->catalogCandidate($printer, $item),
            $items,
        )));

        if ($query === '') {
            $items = array_values(array_filter(
                $items,
                fn (array $item): bool => ($item['score'] ?? 0) > 0,
            ));
        }

        usort($items, function (array $left, array $right): int {
            $score = ($right['score'] ?? 0) <=> ($left['score'] ?? 0);

            return $score !== 0
                ? $score
                : strcasecmp((string) ($left['displayName'] ?? ''), (string) ($right['displayName'] ?? ''));
        });

        if ($selectedDefinitionId !== null) {
            foreach ($items as &$item) {
                if (($item['definitionId'] ?? null) !== $selectedDefinitionId) {
                    continue;
                }
                $resolved = $this->resolveMachine($user, $selectedDefinitionId, []);
                $item['snapshot'] = $resolved['snapshot'];
                $item['snapshotHash'] = $resolved['snapshotHash'];
                break;
            }
            unset($item);
        }

        return [
            'items' => $items,
            'resourceVersion' => (string) ($payload['resourceVersion'] ?? $this->resourceVersion()),
        ];
    }

    public function confirm(Printer $printer, User $administrator, array $input): array
    {
        $expectedRevision = (int) $input['expectedRevision'];
        $currentRevision = $this->revision($printer);
        if ($currentRevision !== $expectedRevision) {
            throw new PrinterSlicingRevisionConflict($expectedRevision, $currentRevision);
        }

        $definitionId = (string) $input['definitionId'];
        $overrides = $this->normalizeOverrides(
            is_array($input['overrides'] ?? null) ? $input['overrides'] : [],
        );
        $resolved = $this->resolveMachine($administrator, $definitionId, $overrides);
        $nextRevision = $expectedRevision + 1;
        $configuration = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'provider' => self::PROVIDER,
            'resourceVersion' => $this->resourceVersion(),
            'definitionId' => $definitionId,
            'revision' => $nextRevision,
            'confirmedAt' => now()->toAtomString(),
            'confirmedBy' => (string) $administrator->getKey(),
            'overrides' => $overrides,
            'snapshot' => $resolved['snapshot'],
            'snapshotHash' => $resolved['snapshotHash'],
        ];

        $query = Printer::query()->whereKey($printer->getKey());
        if ($expectedRevision === 0) {
            $query->whereRaw([
                '$or' => [
                    ['slicing' => ['$exists' => false]],
                    ['slicing' => null],
                    ['slicing.revision' => 0],
                ],
            ]);
        } else {
            $query->where('slicing.revision', $expectedRevision);
        }

        if ((int) $query->update(['slicing' => $configuration]) !== 1) {
            $fresh = Printer::find($printer->getKey());
            throw new PrinterSlicingRevisionConflict(
                $expectedRevision,
                $fresh ? $this->revision($fresh) : $currentRevision,
            );
        }

        $printer->refresh();

        return $this->configuration($printer, $administrator, false);
    }

    public function hostPrinterContext(Printer $printer): array
    {
        $stored = $this->storedConfiguration($printer);

        return [
            'id' => (string) $printer->getKey(),
            'displayName' => $this->printerDisplayName($printer, $stored),
            'slicingStatus' => $stored === null ? 'missing' : $this->status($stored),
            'slicingRevision' => $stored === null ? 0 : (int) ($stored['revision'] ?? 0),
            'machineSnapshot' => $stored !== null && $this->status($stored) === 'configured'
                ? ($stored['snapshot'] ?? null)
                : null,
        ];
    }

    private function runtimePlugin(?User $user): array
    {
        $pluginId = (string) config('plugins.integrations.slicer_plugin_id', 'cura-web-ui');
        $plugin = Plugin::query()->where('plugin_id', $pluginId)->first();
        if (! $plugin || ! $plugin->enabled || $plugin->load_status !== 'ready') {
            throw new PluginRuntimeException('The managed Cura slicing runtime is not ready.');
        }

        $payload = $plugin->toArray();
        $payload['runtime']['userId'] = $user ? (string) $user->getKey() : 'wprint-system';

        return $payload;
    }

    private function resolveMachine(?User $user, string $definitionId, array $overrides): array
    {
        $response = $this->runtimeHttpClient->post(
            $this->runtimePlugin($user),
            '/api/v2/machines/resolve',
            [
                'definitionId' => $definitionId,
                'resourceVersion' => $this->resourceVersion(),
                // An empty PHP array is encoded as JSON `[]`, while the v2
                // gateway contract requires an object. Preserve associative
                // override maps and emit `{}` when no overrides were supplied.
                'overrides' => $overrides === [] ? new \stdClass : $overrides,
            ],
        )->throw();

        return $this->validatedResolution($response, $definitionId);
    }

    private function normalizeOverrides(array $overrides): array
    {
        foreach (['startGcode', 'endGcode'] as $key) {
            if (array_key_exists($key, $overrides) && $overrides[$key] === null) {
                $overrides[$key] = '';
            }
        }

        if (is_array($overrides['extruders'] ?? null)) {
            $overrides['extruders'] = array_map(function (mixed $extruder): mixed {
                if (! is_array($extruder)) {
                    return $extruder;
                }

                foreach (['startGcode', 'endGcode'] as $key) {
                    if (array_key_exists($key, $extruder) && $extruder[$key] === null) {
                        $extruder[$key] = '';
                    }
                }

                return $extruder;
            }, $overrides['extruders']);
        }

        return $overrides;
    }

    private function validatedResolution(Response $response, string $definitionId): array
    {
        $payload = $response->json();
        $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : null;
        $snapshotHash = $payload['snapshotHash'] ?? null;
        $volume = is_array($snapshot['buildVolume'] ?? null) ? $snapshot['buildVolume'] : null;
        $extruders = $snapshot['extruders'] ?? null;
        $validDimensions = $volume !== null
            && $this->positiveFiniteNumber($volume['width'] ?? null)
            && $this->positiveFiniteNumber($volume['depth'] ?? null)
            && $this->positiveFiniteNumber($volume['height'] ?? null);

        if (
            $snapshot === null
            || ($snapshot['definitionId'] ?? null) !== $definitionId
            || ! is_string($snapshot['displayName'] ?? null)
            || ! $validDimensions
            || ! is_array($extruders)
            || $extruders === []
            || ! is_string($snapshotHash)
            || preg_match('/^[a-f0-9]{64}$/', $snapshotHash) !== 1
        ) {
            throw new PluginRuntimeException('The Cura runtime returned an invalid machine snapshot.');
        }

        foreach ($extruders as $extruder) {
            if (
                ! is_array($extruder)
                || ! $this->positiveFiniteNumber($extruder['nozzleDiameter'] ?? null)
                || ! $this->positiveFiniteNumber($extruder['filamentDiameter'] ?? null)
            ) {
                throw new PluginRuntimeException('The Cura runtime returned an invalid extruder snapshot.');
            }
        }

        return [
            'snapshot' => $snapshot,
            'snapshotHash' => $snapshotHash,
        ];
    }

    private function catalogCandidate(Printer $printer, mixed $candidate): ?array
    {
        if (! is_array($candidate) || ! is_string($candidate['definitionId'] ?? null) || ! is_string($candidate['displayName'] ?? null)) {
            return null;
        }

        $machine = is_array($printer->machine ?? null) ? $printer->machine : [];
        $haystack = $this->normalized(implode(' ', array_filter([
            $candidate['definitionId'],
            $candidate['displayName'],
            $candidate['manufacturer'] ?? null,
            $candidate['machineType'] ?? null,
        ])));
        $signals = array_values(array_filter([
            $machine['manufacturer'] ?? null,
            $machine['model'] ?? null,
            $machine['machineType'] ?? null,
            $machine['firmwareName'] ?? null,
        ], fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
        $score = 0;
        $reasons = [];
        foreach ($signals as $index => $signal) {
            $normalized = $this->normalized($signal);
            if ($normalized === '' || mb_strlen($normalized) < 3) {
                continue;
            }
            if (str_contains($haystack, $normalized)) {
                $weight = $index <= 1 ? 60 : 35;
                $score += $weight;
                $reasons[] = $index <= 1 ? 'manufacturer_or_model' : 'machine_type';
            }
        }

        $printerExtruders = max(1, (int) ($machine['extruderCount'] ?? 1));
        if ((int) ($candidate['extruderCount'] ?? 1) === $printerExtruders) {
            $score += 10;
            $reasons[] = 'extruder_count';
        }

        return array_merge($candidate, [
            'score' => min(100, $score),
            'matchReasons' => array_values(array_unique($reasons)),
        ]);
    }

    private function storedConfiguration(Printer $printer): ?array
    {
        $stored = $printer->slicing ?? null;

        return is_array($stored) && $stored !== [] ? $stored : null;
    }

    private function status(array $stored): string
    {
        return isset($stored['snapshot'], $stored['confirmedAt']) ? 'configured' : 'unconfirmed';
    }

    private function revision(Printer $printer): int
    {
        return (int) Arr::get($this->storedConfiguration($printer) ?? [], 'revision', 0);
    }

    private function printerDisplayName(Printer $printer, ?array $stored): string
    {
        return (string) (
            data_get($stored, 'snapshot.displayName')
            ?? data_get($printer->machine, 'displayName')
            ?? data_get($printer->machine, 'machineType')
            ?? $printer->node
            ?? 'Printer'
        );
    }

    private function resourceVersion(): string
    {
        return (string) config('plugins.integrations.slicer_resource_version', '5.12.1');
    }

    private function positiveFiniteNumber(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value > 0;
    }

    private function normalized(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? ''));
    }
}
