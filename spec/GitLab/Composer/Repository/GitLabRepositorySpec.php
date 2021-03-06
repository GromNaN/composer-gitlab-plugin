<?php

namespace spec\GitLab\Composer\Repository;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class GitLabRepositorySpec extends ObjectBehavior
{
    function it_is_a_composer_repository()
    {
        $this->shouldImplement('Composer\Repository\RepositoryInterface');
    }

    function it_is_initializable()
    {
        $this->shouldHaveType('GitLab\Composer\Repository\GitLabRepository');
    }
}
