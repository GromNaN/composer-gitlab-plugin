<?php

namespace spec\GitLab\Composer\Plugin;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class GitLabPluginSpec extends ObjectBehavior
{
    function it_is_initializable()
    {
        $this->shouldHaveType('GitLab\Composer\Plugin\GitLabPlugin');
    }
}
