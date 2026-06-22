<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Commands;

use Webkernel\StdGit\StdGit;
use Webkernel\XMonorepo\XMonorepo;
use Webkernel\XWebdev\XCommand;
use Webkernel\XWebdev\XWebdev;

abstract class MonorepoCommand extends XCommand
{
    private ?XMonorepo $xMonorepo = null;

    protected function xMonorepo(): XMonorepo
    {
        if ($this->xMonorepo instanceof XMonorepo) {
            return $this->xMonorepo;
        }

        $configPath = webapp_path('x-monorepo.php');

        $this->xMonorepo = (new XMonorepo(new StdGit()))
            ->withConfig($configPath)
            ->dotGitRoot($this->webdev->monorepoRoot());

        return $this->xMonorepo;
    }
}
