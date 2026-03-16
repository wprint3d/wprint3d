<?php

namespace App\Support;

class ContainerRuntime
{
    public function __construct(
        private readonly ?string $composeDir,
        private readonly string $containerCli,
        private readonly array $composeCommand,
    ) {}

    public static function fromConfig(?array $config): self
    {
        $config ??= [];

        $containerCli = trim((string) ($config['container_cli'] ?? 'docker'));
        $composeCommand = self::parseCommand(
            $config['compose_command'] ?? null,
            ['docker-compose'],
        );

        return new self(
            composeDir: self::nullableTrim($config['compose_dir'] ?? null),
            containerCli: $containerCli !== '' ? $containerCli : 'docker',
            composeCommand: $composeCommand,
        );
    }

    public function composeDir(): ?string
    {
        return $this->composeDir;
    }

    public function containerCli(): string
    {
        return $this->containerCli;
    }

    public function composeCommand(array $arguments = []): array
    {
        return [
            ...$this->composeCommand,
            ...$arguments,
        ];
    }

    public function imageInspectCommand(string $image): array
    {
        return [
            $this->containerCli,
            'image',
            'inspect',
            $image,
        ];
    }

    private static function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param  array<int, string>  $fallback
     * @return array<int, string>
     */
    private static function parseCommand(mixed $value, array $fallback): array
    {
        if (! is_string($value)) {
            return $fallback;
        }

        $parts = preg_split('/\s+/', trim($value));

        if ($parts === false) {
            return $fallback;
        }

        $parts = array_values(
            array_filter($parts, static fn (string $part): bool => $part !== '')
        );

        return $parts !== [] ? $parts : $fallback;
    }
}
