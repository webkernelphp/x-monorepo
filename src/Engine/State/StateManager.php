<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine\State;

use Webkernel\StdGit\Operations\IOperationStateStore;
use Webkernel\StdGit\Operations\OperationStatus;
use Webkernel\XMonorepo\Exceptions\StateException;

/**
 * Manages PackageJobEntry lifecycle through the StdGit operation state store.
 */
final class StateManager
{
    public function __construct(private readonly IOperationStateStore $store) {}

    public function initJob(PackageJobEntry $entry): void
    {
        try {
            $this->store->save($entry->getOperationState());
        } catch (\Throwable $e) {
            throw new StateException("Cannot initialise job '{$entry->getId()}': {$e->getMessage()}", 0, $e);
        }
    }

    public function markInProgress(PackageJobEntry $entry): PackageJobEntry
    {
        return $this->transition($entry, OperationStatus::InProgress);
    }

    public function markCompleted(PackageJobEntry $entry): PackageJobEntry
    {
        return $this->transition($entry, OperationStatus::Completed);
    }

    public function markFailed(PackageJobEntry $entry, string $error): PackageJobEntry
    {
        $updated = $entry->withStatus(OperationStatus::Failed, $error);
        try {
            $this->store->save($updated->getOperationState());
        } catch (\Throwable $e) {
            throw new StateException("Cannot save failed state for '{$entry->getId()}': {$e->getMessage()}", 0, $e);
        }
        return $updated;
    }

    /**
     * Persist the monorepo HEAD hash into the entry payload.
     * Used by snapshot mode so the next run can skip unchanged packages.
     */
    public function recordSyncedHead(PackageJobEntry $entry, string $head): PackageJobEntry
    {
        $newPayload = array_merge($entry->getOperationState()->getPayload(), [
            'synced_head' => $head,
        ]);

        // Rebuild the OperationState with the updated payload, keeping status.
        $updatedState = $entry->getOperationState()->withStatus(
            $entry->getStatus(),
            $newPayload
        );

        try {
            $this->store->save($updatedState);
        } catch (\Throwable $e) {
            throw new StateException(
                "Cannot record synced HEAD for '{$entry->getId()}': {$e->getMessage()}", 0, $e
            );
        }

        return PackageJobEntry::fromOperationState($updatedState);
    }

    public function load(string $packageName, string $tag): ?PackageJobEntry
    {
        $id = PackageJobEntry::idFor($packageName, $tag);

        if (!$this->store->exists($id)) {
            return null;
        }

        try {
            $state = $this->store->load($id);
            return PackageJobEntry::fromOperationState($state);
        } catch (\Throwable $e) {
            throw new StateException("Cannot load job '$id': {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * @return PackageJobEntry[]
     */
    public function listAll(?string $tag = null): array
    {
        try {
            $states = $this->store->listAll(PackageJobEntry::operationType());
        } catch (\Throwable $e) {
            throw new StateException("Cannot list jobs: {$e->getMessage()}", 0, $e);
        }

        $entries = array_map(
            static fn ($s) => PackageJobEntry::fromOperationState($s),
            $states
        );

        if ($tag !== null) {
            $entries = array_filter(
                $entries,
                static fn (PackageJobEntry $e) => $e->getTag() === $tag
            );
        }

        return array_values($entries);
    }

    private function transition(PackageJobEntry $entry, OperationStatus $status): PackageJobEntry
    {
        $updated = $entry->withStatus($status);
        try {
            $this->store->save($updated->getOperationState());
        } catch (\Throwable $e) {
            throw new StateException(
                "Cannot transition job '{$entry->getId()}' to {$status->value}: {$e->getMessage()}",
                0,
                $e
            );
        }
        return $updated;
    }
}
