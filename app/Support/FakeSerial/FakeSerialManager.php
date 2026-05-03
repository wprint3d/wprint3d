<?php

namespace App\Support\FakeSerial;

use App\Exceptions\InitializationException;
use App\Exceptions\TimedOutException;
use App\Models\Configuration;
use Illuminate\Cache\Repository;

class FakeSerialManager
{
    private const DEFAULT_SETTINGS = [
        'enabled' => false,
        'node' => 'FAKE0',
        'baudRate' => 115200,
        'supportedBaudRates' => [115200, 250000],
        'logMaxEntries' => 250,
    ];

    public function __construct(
        private Repository $cache,
        private ?FakeSerialEmulator $emulator = null,
        private ?array $settings = null,
    ) {
        $this->emulator ??= new FakeSerialEmulator;
    }

    public function listVirtualNodes(): array
    {
        $settings = $this->resolveSettings();

        if (! $settings['enabled']) {
            return [];
        }

        return [$settings['node']];
    }

    public function nodeExists(string $node): bool
    {
        return in_array($node, $this->listVirtualNodes(), true);
    }

    public function connect(string $node, int $baudRate): string
    {
        if (! $this->nodeExists($node)) {
            throw new InitializationException("No such fake serial node: {$node}");
        }

        if ($this->cache->has($this->connectionKey($node))) {
            throw new InitializationException("The fake serial node {$node} is already in use.");
        }

        $token = bin2hex(random_bytes(16));

        $this->cache->put($this->connectionKey($node), [
            'token' => $token,
            'baudRate' => $baudRate,
            'connectedAt' => microtime(true),
            'refreshedAt' => microtime(true),
        ], 60);

        $this->appendLog('status', "{$node} connected at {$baudRate} baud");

        return $token;
    }

    public function disconnect(string $node, ?string $token = null): void
    {
        $connection = $this->cache->get($this->connectionKey($node));

        if ($connection === null) {
            return;
        }

        if ($token !== null && ($connection['token'] ?? null) !== $token) {
            return;
        }

        $this->cache->forget($this->connectionKey($node));
        $this->appendLog('status', "{$node} disconnected");
    }

    public function transact(string $node, int $baudRate, string $token, string $command, ?int $timeout = null): array
    {
        $connection = $this->cache->get($this->connectionKey($node));

        if (($connection['token'] ?? null) !== $token) {
            throw new InitializationException("The fake serial node {$node} is not connected.");
        }

        $settings = $this->resolveSettings();

        if ($settings['baudRate'] !== $baudRate) {
            throw new TimedOutException("No response at {$baudRate} bps.");
        }

        $state = $this->cache->get($this->stateKey($node), []);
        $result = $this->emulator->transact($command, $state);

        $this->cache->put($this->stateKey($node), $result['state'], 60 * 60);

        $now = microtime(true);

        if (($connection['refreshedAt'] ?? 0) <= ($now - 30)) {
            $connection['refreshedAt'] = $now;

            $this->cache->put($this->connectionKey($node), $connection, 60);
        }

        $logEntries = [
            $this->logEntry('input', $command),
        ];

        foreach ($result['lines'] as $line) {
            $logEntries[] = $this->logEntry('output', $line['text']);
        }

        $this->appendLogEntries($logEntries);

        return $result;
    }

    public function getLog(): array
    {
        return $this->cache->get($this->logKey(), []);
    }

    public function clear(): void
    {
        $settings = $this->resolveSettings();

        $this->disconnect($settings['node']);
        $this->cache->forget($this->stateKey($settings['node']));
        $this->cache->forget($this->logKey());
    }

    public function updateSettings(bool $enabled, int $baudRate): array
    {
        if ($this->settings !== null) {
            $this->settings['enabled'] = $enabled;
            $this->settings['baudRate'] = $baudRate;
        } else {
            Configuration::set('fakeSerialEnabled', $enabled);
            Configuration::set('fakeSerialBaudRate', $baudRate);
        }

        $settings = $this->resolveSettings();

        if (! $enabled) {
            $this->clear();
        } else {
            $this->cache->forget($this->stateKey($settings['node']));
            $this->appendLog('status', sprintf('%s enabled at %d baud', $settings['node'], $baudRate));
        }

        return $this->getDeveloperState();
    }

    public function getDeveloperState(): array
    {
        $settings = $this->resolveSettings();

        return [
            'enabled' => $settings['enabled'],
            'node' => $settings['node'],
            'baudRate' => $settings['baudRate'],
            'supportedBaudRates' => $settings['supportedBaudRates'],
            'connected' => $this->cache->has($this->connectionKey($settings['node'])),
            'log' => $this->getLog(),
        ];
    }

    private function resolveSettings(): array
    {
        if ($this->settings !== null) {
            return array_replace(self::DEFAULT_SETTINGS, $this->settings);
        }

        return array_replace(self::DEFAULT_SETTINGS, [
            'enabled' => (bool) Configuration::get('fakeSerialEnabled', self::DEFAULT_SETTINGS['enabled']),
            'node' => (string) Configuration::get('fakeSerialNode', self::DEFAULT_SETTINGS['node']),
            'baudRate' => (int) Configuration::get('fakeSerialBaudRate', self::DEFAULT_SETTINGS['baudRate']),
        ]);
    }

    private function appendLog(string $direction, string $message): void
    {
        $this->appendLogEntries([
            $this->logEntry($direction, $message),
        ]);
    }

    private function logEntry(string $direction, string $message): array
    {
        return [
            'direction' => $direction,
            'message' => $message,
            'timestamp' => microtime(true),
        ];
    }

    private function appendLogEntries(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $settings = $this->resolveSettings();
        $maxEntries = (int) $settings['logMaxEntries'];

        if ($maxEntries <= 0) {
            $this->cache->put($this->logKey(), [], 60 * 60);

            return;
        }

        $log = $this->cache->get($this->logKey(), []);
        array_push($log, ...$entries);

        if (count($log) > $maxEntries) {
            $log = array_slice($log, -1 * $maxEntries);
        }

        $this->cache->put($this->logKey(), $log, 60 * 60);
    }

    private function connectionKey(string $node): string
    {
        return "fake-serial:{$node}:connection";
    }

    private function stateKey(string $node): string
    {
        return "fake-serial:{$node}:state";
    }

    private function logKey(): string
    {
        return 'fake-serial:log';
    }
}
