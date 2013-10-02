<?php

namespace spec\GitLab\Composer\Plugin;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class GitLabPluginSpec extends ObjectBehavior
{
    function it_should_be_a_composer_plugin()
    {
        $this->shouldImplement('Composer\Plugin\PluginInterface');
    }

    function it_is_initializable()
    {
        $this->shouldHaveType('GitLab\Composer\Plugin\GitLabPlugin');
    }

    function it_should_register_the_gitlab_repository()
    {

    }

    function is_should_register_the_gitlab_driver()
    {

    }
}
