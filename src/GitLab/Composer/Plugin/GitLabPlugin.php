<?php

namespace GitLab\Composer\Plugin;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class GitLabPlugin implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        $composer->getRepositoryManager()->setRepositoryClass('gitlab', 'GitLab\Composer\Repository\GitLabRepository');
    }
}
