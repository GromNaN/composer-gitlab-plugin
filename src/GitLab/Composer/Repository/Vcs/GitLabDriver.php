<?php

namespace GitLab\Composer\Repository\Vcs;

use Composer\Repository\Vcs\VcsDriver;
use Composer\Downloader\TransportException;
use Composer\Json\JsonFile;
use Composer\Cache;
use Composer\IO\IOInterface;
use Composer\Util\RemoteFilesystem;

class GitLabDriver extends VcsDriver
{
    protected $cache;
    protected $owner;
    protected $repository;
    protected $tags;
    protected $branches;
    protected $rootIdentifier;
    protected $projectId;
    protected $infoCache = array();
    protected $scheme;
    protected $projectData;

    /**
     * Git Driver
     *
     * @var GitDriver
     */
    protected $gitDriver;

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        preg_match('#^/([\w-_]+)/([\w-_]+)(\.git)?$#', parse_url($this->url, PHP_URL_PATH), $match);

        $this->owner = $match[1];
        $this->repository = $match[2];
        $this->originUrl = parse_url($this->url, PHP_URL_HOST);
        $this->scheme = parse_url($this->url, PHP_URL_SCHEME);
        $this->cache = new Cache($this->io, $this->config->get('cache-repo-dir').'/'.$this->originUrl.'/'.$this->owner.'/'.$this->repository);

        $this->remoteFilesystem = new RemoteFilesystem($this->io, array(
            'http' => array(
                'header' => array(
                    'PRIVATE-TOKEN: '.'oppD2cvkY1DFZinyTdxs',
                ),
            ),
        ));

        $this->fetchProjectId();
        $this->fetchRootIdentifier();
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getRootIdentifier();
        }

        return $this->rootIdentifier;
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl()
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getUrl();
        }

        return $this->projectData['ssh_url_to_repo'];
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getSource($identifier);
        }

        $url = $this->getUrl();

        return array('type' => 'git', 'url' => $url, 'reference' => $identifier);
    }

    /**
     * {@inheritDoc}
     */
    public function getDist($identifier)
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getDist($identifier);
        }
        $url = 'https://api.github.com/repos/'.$this->owner.'/'.$this->repository.'/zipball/'.$identifier;

        return array('type' => 'zip', 'url' => $url, 'reference' => $identifier, 'shasum' => '');
    }

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getComposerInformation($identifier);
        }

        if (preg_match('{[a-f0-9]{40}}i', $identifier) && $res = $this->cache->read($identifier)) {
            $this->infoCache[$identifier] = JsonFile::parseJson($res);
        }

        if (!isset($this->infoCache[$identifier])) {
            $resource = $this->getScheme().'://'.$this->originUrl.'/api/v3/projects/'.$this->projectId.'/repository/commits/'.urlencode($identifier).'/blob?filepath=composer.json';
            $composer = JsonFile::parseJson($this->getContents($resource));
            if (empty($composer)) {
                throw new \RuntimeException('Could not retrieve composer.json from '.$resource);
            }

            if ($composer && false) { // @TODO
                $composer = JsonFile::parseJson($composer, $resource);

                if (!isset($composer['time'])) {
                    $resource = 'https://api.github.com/repos/'.$this->owner.'/'.$this->repository.'/commits/'.urlencode($identifier);
                    $commit = JsonFile::parseJson($this->getContents($resource), $resource);
                    $composer['time'] = $commit['commit']['committer']['date'];
                }
            }

            if (preg_match('{[a-f0-9]{40}}i', $identifier)) {
                $this->cache->write($identifier, json_encode($composer));
            }

            $this->infoCache[$identifier] = $composer;
        }

        return $this->infoCache[$identifier];
    }

    /**
     * {@inheritDoc}
     */
    public function getTags()
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getTags();
        }
        if (null === $this->tags) {
            $resource = $this->getScheme().'://'.$this->originUrl.'/api/v3/projects/'.$this->projectId.'/repository/tags';
            $tagsData = JsonFile::parseJson($this->getContents($resource), $resource);
            $this->tags = array();
            foreach ($tagsData as $tag) {
                $this->tags[$tag['name']] = $tag['commit']['id'];
            }
        }

        return $this->tags;
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getBranches();
        }
        if (null === $this->branches) {
            $resource = $this->getScheme().'://'.$this->originUrl.'/api/v3/projects/'.$this->projectId.'/repository/branches';
            $branchData = JsonFile::parseJson($this->getContents($resource), $resource);
            $this->branches = array();
            foreach ($branchData as $branch) {
                $this->branches[$branch['name']] = $branch['commit']['id'];
            }
        }

        return $this->branches;
    }

    /**
     * {@inheritDoc}
     */
    public static function supports(IOInterface $io, $url, $deep = false)
    {
        // GitLab cannot be detected automatically
        return false;
    }

    /**
     * @TODO
     */
    protected function fetchProjectId()
    {
        $this->projectId = 12;
    }

    /**
     * Fetch root identifier from GitLab
     *
     * @throws TransportException
     */
    protected function fetchRootIdentifier()
    {
        $projectDataUrl = $this->getScheme().'://'.$this->originUrl.'/api/v3/projects/'.$this->projectId;

        $content = $this->getContents($projectDataUrl, true);
        $this->projectData = JsonFile::parseJson($content, $projectDataUrl);
        if (null === $this->projectData && null !== $this->gitDriver) {
            return;
        }

        if (isset($this->projectData['default_branch'])) {
            $this->rootIdentifier = $this->projectData['default_branch'];
        } else {
            $this->rootIdentifier = 'master';
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function getScheme()
    {
        return $this->scheme;
    }
}
