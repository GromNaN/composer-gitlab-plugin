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
    protected $namespace;
    protected $name;
    protected $tags;
    protected $branches;
    protected $rootIdentifier;
    protected $projectId;
    protected $infoCache = array();
    protected $scheme;
    protected $projectData;
    protected $identifierDate = array();

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        preg_match('#^/([\w-_]+)/([\w-_]+)(\.git)?$#', parse_url($this->url, PHP_URL_PATH), $match);

        $this->namespace = $match[1];
        $this->name = $match[2];
        $this->originUrl = parse_url($this->url, PHP_URL_HOST);
        $this->scheme = parse_url($this->url, PHP_URL_SCHEME);
        $this->cache = new Cache($this->io, $this->config->get('cache-repo-dir').'/'.$this->originUrl.'/'.$this->namespace.'/'.$this->name);

        $this->remoteFilesystem = new RemoteFilesystem($this->io, array(
            'http' => array(
                'header' => array(
                    'PRIVATE-TOKEN: '.'oppD2cvkY1DFZinyTdxs',
                ),
            ),
        ));

        $this->fetchRootIdentifier();
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        return $this->rootIdentifier;
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl()
    {
        return $this->projectData['ssh_url_to_repo'];
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        $url = $this->getUrl();

        return array('type' => 'git', 'url' => $url, 'reference' => $identifier);
    }

    /**
     * {@inheritDoc}
     */
    public function getDist($identifier)
    {
        $url = 'https://api.github.com/repos/'.$this->namespace.'/'.$this->name.'/zipball/'.$identifier;

        return array('type' => 'zip', 'url' => $url, 'reference' => $identifier, 'shasum' => '');
    }

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        if (preg_match('{[a-f0-9]{40}}i', $identifier) && $res = $this->cache->read($identifier)) {
            $this->infoCache[$identifier] = JsonFile::parseJson($res);
        }

        if (!isset($this->infoCache[$identifier])) {
            $resource = $this->getScheme().'://'.$this->originUrl.'/api/v3/projects/'.$this->projectId.'/repository/blobs/'.urlencode($identifier).'?filepath=composer.json';
            $composer = JsonFile::parseJson($this->getContents($resource), $resource);

            if ($composer && false) { // @TODO
                $composer = JsonFile::parseJson($composer, $resource);

                if (!isset($composer['time'])) {
                    $resource = 'https://api.github.com/repos/'.$this->namespace.'/'.$this->name.'/commits/'.urlencode($identifier);
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
        if (null === $this->tags) {
            $resource = $this->getScheme().'://'.$this->originUrl.'/api/v3/projects/'.$this->projectId.'/name/tags';
            $tagsData = JsonFile::parseJson($this->getContents($resource), $resource);
            $this->tags = array();
            foreach ($tagsData as $tag) {
                $this->tags[$tag['name']] = $tag['commit']['id'];
                $this->identifierDate[$tag['commit']['id']] = $tag['commit']['committed_date'];
            }
        }

        return $this->tags;
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        if (null === $this->branches) {
            $resource = $this->getScheme().'://'.$this->originUrl.'/api/v3/projects/'.$this->projectId.'/name/branches';
            $branchData = JsonFile::parseJson($this->getContents($resource), $resource);
            $this->branches = array();
            foreach ($branchData as $branch) {
                $this->branches[$branch['name']] = $branch['commit']['id'];
                $this->identifierDate[$branch['commit']['id']] = $branch['commit']['committed_date'];
            }
        }

        return $this->branches;
    }

    /**
     * GitLab cannot be detected automatically
     *
     * {@inheritDoc}
     */
    public static function supports(IOInterface $io, $url, $deep = false)
    {
        return true;
    }

    /**
     * Fetch root identifier from GitLab
     *
     * @throws TransportException
     */
    protected function fetchRootIdentifier()
    {
        $id = urlencode($this->namespace.'/'.$this->name);
        $id = 11;
        $projectDataUrl = $this->getScheme().'://'.$this->originUrl.'/api/v3/projects/'.$id;

        $content = $this->getContents($projectDataUrl, true);
        $this->projectData = JsonFile::parseJson($content, $projectDataUrl);

        if (null === $this->projectData) {
            throw new \RuntimeException('failed to laod project');
        }

        $this->projectId = $this->projectData['id'];

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
