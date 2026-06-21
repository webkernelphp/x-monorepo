<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webkernel\XWebdev\XWebdev;

final class StatsCommand extends MonorepoCommand
{
    public function __construct(XWebdev $webdev)
    {
        parent::__construct($webdev, 'stats');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Display comprehensive statistics and metrics for all managed packages.')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Filter statistics by package type.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output metrics as a raw JSON payload.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $packages = $this->xMonorepo()->createDiscovery()->discover();

        if ($packages === []) {
            $output->writeln('<comment>No registered packages found to compile metrics from.</comment>');
            return Command::SUCCESS;
        }

        $totalPackages = count($packages);
        $typeDistribution = [];
        $processedPackages = [];

        foreach ($packages as $package) {
            $type = $package->getType();
            $typeDistribution[$type] = ($typeDistribution[$type] ?? 0) + 1;

            $processedPackages[] = [
                'name'          => $package->getName(),
                'type'          => $type,
                'relative_path' => $package->getRelativePath(),
                'repo_url'      => $package->getSplitRepoUrl(),
                'branch'        => $package->getDefaultBranch(),
            ];
        }

        $filterType = $input->getOption('type');
        if ($filterType !== null) {
            $filterType = strtolower((string) $filterType);
            $processedPackages = array_values(array_filter(
                $processedPackages,
                static fn (array $p): bool => strtolower((string) $p['type']) === $filterType
            ));
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'summary' => [
                    'total_packages'    => $totalPackages,
                    'filtered_packages' => count($processedPackages),
                    'type_distribution' => $typeDistribution,
                ],
                'packages' => $processedPackages,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $output->writeln([
            '',
            ' <bg=blue;fg=white> WEBKERNEL MONOREPO METRICS SYSTEM </>',
            ' <fg=gray>====================================</>',
            '',
            ' <info>Package Type Distribution:</info>',
        ]);

        foreach ($typeDistribution as $type => $count) {
            $output->writeln(sprintf(
                ' %6.2f %% <comment>%s</comment>   <fg=gray>....</fg=gray> %d package(s)',
                ($count / $totalPackages) * 100,
                ucfirst($type),
                $count
            ));
        }

        $output->writeln('');
        $output->writeln(sprintf(' <info>Detailed Package Analysis (%d matching):</info>', count($processedPackages)));

        $table = new Table($output);
        $table->setHeaders(['Package Name', 'Type', 'Relative Path', 'Target Remote URL', 'Default Branch']);

        foreach ($processedPackages as $p) {
            $table->addRow([
                sprintf('<fg=cyan>%s</>', $p['name']),
                $p['type'],
                $p['relative_path'],
                $p['repo_url'],
                $p['branch'],
            ]);
        }

        $table->render();
        $output->writeln('');

        return Command::SUCCESS;
    }
}
