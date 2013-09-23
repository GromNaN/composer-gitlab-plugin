<?php

namespace spec\GitLab\Composer\Repository\Vcs;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class GitLabDriverSpec extends ObjectBehavior
{
    function it_is_initializable()
    {
        $this->shouldHaveType('GitLab\Composer\Repository\Vcs\GitLabDriver');
    }
}
