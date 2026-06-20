<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webkernel\XMonorepo\XMonorepo;

/**
 * Compiles and displays comprehensive metrics and statistics about monorepo packages.
 */
final class StatsCommand extends Command
{
    public function __construct(private readonly XMonorepo $xMonorepo)
    {
        parent::__construct('stats');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Display comprehensive statistics and metrics for all managed packages.')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Filter statistics by package type (e.g., component, engine, bundle).')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output metrics as a raw JSON payload.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $discovery = $this->xMonorepo->createDiscovery();
        $packages  = $discovery->discover();

        if ($packages === []) {
            $output->writeln('<comment>No registered packages found to compile metrics from.</comment>');
            return Command::SUCCESS;
        }

        // 1. Compile general metrics
        $totalPackages = count($packages);
        $typeDistribution = [];
        $processedPackages = [];

        foreach ($packages as $package) {
            // Resolving the type from config/definition mapping if available, falling back to 'unknown'
            $type = method_exists($package, 'getType') ? $package->getType() : 'unknown';
            $typeDistribution[$type] = ($typeDistribution[$type] ?? 0) + 1;

            $processedPackages[] = [
                'name'          => $package->getName(),
                'type'          => $type,
                'relative_path' => $package->getRelativePath(),
                'repo_url'      => $package->getSplitRepoUrl(),
                'branch'        => $package->getDefaultBranch(),
            ];
        }

        // 2. Apply type filter if requested
        $filterType = $input->getOption('type');
        if ($filterType !== null) {
            $filterType = strtolower((string) $filterType);
            $processedPackages = array_values(array_filter(
                $processedPackages,
                static fn (array $p) => strtolower($p['type']) === $filterType
            ));
        }

        // 3. Render JSON Payload if requested
        if ($input->getOption('json')) {
            $jsonPayload = [
                'summary' => [
                    'total_packages'    => $totalPackages,
                    'filtered_packages' => count($processedPackages),
                    'type_distribution' => $typeDistribution,
                ],
                'packages' => $processedPackages,
            ];

            $output->writeln((string) json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        // 4. Render Human-readable CLI Console Output
        $output->writeln([
            '',
            ' <bg=blue;fg=white> WEBKERNEL MONOREPO METRICS SYSTEM </>',
            ' <fg=gray>====================================</>',
            '',
        ]);

        // Distribution Section
        $output->writeln(' <info>Package Type Distribution:</info>');

        // Find the maximum length for dynamic padding alignment
        $maxLength = 0;
        foreach (array_keys($typeDistribution) as $type) {
            $maxLength = max($maxLength, strlen(ucfirst($type)));
        }
        $padding = $maxLength + 1;

        // Render distribution list
        foreach ($typeDistribution as $type => $count) {
            // Clean, direct percentage calculation
            $percentage = ($count / $totalPackages) * 100;

            // Format percentage to a clean 2 decimal places (or use %d for whole numbers)
            $formattedPercent = sprintf('%6.2f', $percentage);
            $paddedType       = sprintf('%-' . $padding . 's', ucfirst($type));

            $output->writeln(" {$formattedPercent} % <comment>{$paddedType}</comment>   <fg=gray>....</fg=gray> {$count} package(s)");        }
        $output->writeln('');

        // Packages Table Section
        $output->writeln(sprintf(' <info>Detailed Package Analysis (%d matching):</info>', count($processedPackages)));

        $table = new Table($output);
        $table->setHeaders(['Package Name', 'Type', 'Relative Path', 'Target Remote URL', 'Default Branch']);

        foreach ($processedPackages as $p) {
            $table->addRow([
                sprintf('<fg=cyan>%s</>', $p['name']),
                $p['type'],
                $p['relative_path'],
                $p['repo_url'],
                $p['branch']
            ]);
        }

        $table->render();
        $output->writeln('');

        return Command::SUCCESS;
    }
}
