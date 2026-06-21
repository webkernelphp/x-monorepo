<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webkernel\XWebdev\XWebdev;

final class DiscoverCommand extends MonorepoCommand
{
    public function __construct(XWebdev $webdev)
    {
        parent::__construct($webdev, 'discover');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Scan the packages directory and list all packages eligible for splitting.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $packages = $this->xMonorepo()->createDiscovery()->discover();

        if ($packages === []) {
            $output->writeln('No eligible packages found.');
            return Command::SUCCESS;
        }

        if ($input->getOption('json')) {
            $data = array_map(static fn ($p): array => [
                'name'          => $p->getName(),
                'relative_path' => $p->getRelativePath(),
                'package_repo'  => $p->getSplitRepoUrl(),
                'branch'        => $p->getDefaultBranch(),
            ], $packages);

            $output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        foreach ($packages as $package) {
            $output->writeln(sprintf(
                '<info>%s</info> [%s] -> %s (%s)',
                $package->getName(),
                $package->getRelativePath(),
                $package->getSplitRepoUrl(),
                $package->getDefaultBranch()
            ));
        }

        return Command::SUCCESS;
    }
}
