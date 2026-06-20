<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine\State;

use Webkernel\StdGit\Operations\OperationState;
use Webkernel\StdGit\Operations\OperationStatus;

/**
 * Thin domain wrapper around OperationState for a single package split job.
 *
 * Keeps XMonorepo-specific field names stable without re-implementing persistence.
 */
final readonly class PackageJobEntry
{
    private const string TYPE = 'package_split';

    private function __construct(private OperationState $state) {}

    public static function create(
        string $packageName,
        string $splitRepoUrl,
        string $branch,
        string $tag
    ): self {
        $id = self::idFor($packageName, $tag);

        $state = new OperationState(
            $id,
            self::TYPE,
            OperationStatus::Pending,
            [
                'package_name'   => $packageName,
                'package_repo_url' => $splitRepoUrl,
                'branch'         => $branch,
                'tag'            => $tag,
            ],
            new \DateTimeImmutable(),
            new \DateTimeImmutable()
        );

        return new self($state);
    }

    public static function fromOperationState(OperationState $state): self
    {
        return new self($state);
    }

    public function getOperationState(): OperationState
    {
        return $this->state;
    }

    public function getId(): string
    {
        return $this->state->getId();
    }

    public function getPackageName(): string
    {
        return (string) ($this->state->getPayload()['package_name'] ?? '');
    }

    public function getSplitRepoUrl(): string
    {
        return (string) ($this->state->getPayload()['package_repo_url'] ?? '');
    }

    public function getBranch(): string
    {
        return (string) ($this->state->getPayload()['branch'] ?? '');
    }

    public function getTag(): string
    {
        return (string) ($this->state->getPayload()['tag'] ?? '');
    }

    public function getStatus(): OperationStatus
    {
        return $this->state->getStatus();
    }

    public function withStatus(OperationStatus $status, ?string $error = null): self
    {
        return new self($this->state->withStatus($status, null, $error));
    }

    public static function idFor(string $packageName, string $tag): string
    {
        return 'split:' . $packageName . ':' . $tag;
    }

    public static function operationType(): string
    {
        return self::TYPE;
    }
}
