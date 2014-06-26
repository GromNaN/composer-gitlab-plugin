<?php

namespace GitLab\Composer\Repository;

use Composer\Repository\VcsRepository;
use Composer\IO\IOInterface;
use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;

/**
 * Same repository as Vcs with the specific driver for GitLab
 *
 * @author Jérôme Tamarelle <jerome@tamarelle.net>
 */
class GitLabRepository extends VcsRepository
{
    public function __construct(array $repoConfig, IOInterface $io, Config $config, EventDispatcher $dispatcher = null)
    {
        parent::__construct($repoConfig, $io, $config, $dispatcher, array(
            'gitlab' => 'GitLab\Composer\Repository\Vcs\GitLabDriver',
        ));
    }
}
