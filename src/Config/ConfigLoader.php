<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Config;

use Webkernel\XMonorepo\Exceptions\ConfigException;

/**
 * Loads and validates the x-monorepo configuration array.
 *
 * Accepts either a file path to a PHP config file that returns an array,
 * or a pre-built array (useful in tests or framework integrations that
 * inject config directly).
 */
final readonly class ConfigLoader
{
    /** @var array<string, mixed> */
    private array $config;
    private ?string $path;

    /**
     * @param  string|array<string, mixed> $source  Path to PHP config file or a config array.
     * @throws ConfigException
     */
    public function __construct(string|array $source)
    {
        if (is_array($source)) {
            $this->config = $source;
            $this->path = null;
            return;
        }

        $path = realpath($source);

        if ($path === false || !file_exists($path)) {
            throw new ConfigException("Config file not found: '$source'.");
        }

        $loaded = require $path;

        if (!is_array($loaded)) {
            throw new ConfigException("Config file '$path' must return an array.");
        }

        $this->config = $loaded;
        $this->path = $path;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->config;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function getBaseDirectory(): ?string
    {
        return $this->path !== null ? dirname($this->path) : null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function require(string $key): mixed
    {
        if (!array_key_exists($key, $this->config)) {
            throw new ConfigException("Required config key '$key' is missing.");
        }

        return $this->config[$key];
    }

    public function getString(string $key, ?string $default = null): string
    {
        $value = $this->get($key, $default);

        if (!is_string($value)) {
            throw new ConfigException("Config key '$key' must be a string.");
        }

        return $value;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        if (!is_int($value)) {
            throw new ConfigException("Config key '$key' must be an integer.");
        }

        return $value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        if (!is_bool($value)) {
            throw new ConfigException("Config key '$key' must be a boolean.");
        }

        return $value;
    }

    /** @return string[] */
    public function getStringArray(string $key): array
    {
        $value = $this->get($key, []);

        if (!is_array($value)) {
            throw new ConfigException("Config key '$key' must be an array.");
        }

        return array_values(array_map(strval(...), $value));
    }

    /** @return array<string, mixed> */
    public function getArray(string $key): array
    {
        $value = $this->get($key, []);

        if (!is_array($value)) {
            throw new ConfigException("Config key '$key' must be an array.");
        }

        return $value;
    }
}
