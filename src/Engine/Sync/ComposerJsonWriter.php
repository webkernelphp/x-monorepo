<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine\Sync;

use Webkernel\XMonorepo\Exceptions\XMonorepoException;

final readonly class ComposerJsonWriter
{
    /**
     * @param array<string, mixed> $data
     */
    public function encode(array $data): string
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded)) {
            throw new XMonorepoException('Failed to encode composer.json.');
        }

        return $encoded . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $path): array
    {
        if (!is_readable($path)) {
            throw new XMonorepoException("composer.json is not readable: {$path}");
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new XMonorepoException("Failed to read composer.json: {$path}");
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new XMonorepoException("Invalid composer.json at {$path}: {$e->getMessage()}", 0, $e);
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public function write(string $path, array $data): void
    {
        $written = file_put_contents($path, $this->encode($data));

        if ($written === false) {
            throw new XMonorepoException("Failed to write composer.json: {$path}");
        }
    }
}
