<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webkernel\XMonorepo\XMonorepo;

/**
 * Display the current split operation state for a given tag.
 */
final class StatusCommand extends Command
{
    public function __construct(private readonly XMonorepo $xMonorepo)
    {
        parent::__construct('status');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Show the split status for a given tag.')
            ->addOption('tag', 't', InputOption::VALUE_REQUIRED, 'Version tag to inspect.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tag = (string) ($input->getOption('tag') ?? '');

        $stateManager = $this->xMonorepo->createStateManager();
        $entries      = $stateManager->listAll($tag !== '' ? $tag : null);

        if ($entries === []) {
            $output->writeln('No job records found.');
            return Command::SUCCESS;
        }

        if ($input->getOption('json')) {
            $data = array_map(static fn ($e) => [
                'id'      => $e->getId(),
                'package' => $e->getPackageName(),
                'tag'     => $e->getTag(),
                'status'  => $e->getStatus()->value,
            ], $entries);

            $output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        foreach ($entries as $entry) {
            $statusLabel = strtoupper($entry->getStatus()->value);
            $output->writeln(sprintf(
                '[%s] %s @ %s',
                $statusLabel,
                $entry->getPackageName(),
                $entry->getTag()
            ));
        }

        return Command::SUCCESS;
    }
}
