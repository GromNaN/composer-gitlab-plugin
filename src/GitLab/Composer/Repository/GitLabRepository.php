<?php

namespace GitLab\Composer\Repository;

use Composer\Repository\ArrayRepository;
use Composer\IO\IOInterface;
use Composer\Package\Version\VersionParser;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Util\RemoteFilesystem;
use Composer\Config;
use Composer\Json\JsonFile;
use Composer\Cache;
use Composer\Package\Loader\ArrayLoader;

class GitLabRepository extends ArrayRepository
{
    private $url;
    private $io;
    private $rfs;
    private $versionParser;
    private $config;

    /** @var string vendor makes additional alias for each channel as {prefix}/{packagename}. It allows smoother
     * package transition to composer-like repositories.
     */
    private $vendorAlias;

    public function __construct(array $repoConfig, IOInterface $io, Config $config, RemoteFilesystem $rfs = null)
    {
        if (!preg_match('{^https?://}', $repoConfig['url'])) {
            $repoConfig['url'] = 'http://'.$repoConfig['url'];
        }

        $urlBits = parse_url($repoConfig['url']);
        if (empty($urlBits['scheme']) || empty($urlBits['host'])) {
            throw new \UnexpectedValueException('Invalid url given for GitLab repository: '.$repoConfig['url']);
        }

        $this->url = rtrim($repoConfig['url'], '/');
        $this->io = $io;
        $this->rfs = $rfs ?: new RemoteFilesystem($this->io);
        $this->vendorAlias = isset($repoConfig['vendor-alias']) ? $repoConfig['vendor-alias'] : null;
        $this->versionParser = new VersionParser();
        $this->config = $config;
        $this->cache = new Cache($io, $config->get('cache-dir').'/gitlab/'.md5($this->url)); // @TODO
    }

    protected function initialize()
    {
        parent::initialize();

        $this->io->write('Initializing GitLab repository '.$this->url);

        $page = 0;
        while(true) {
            try {
                $projects = $this->getContents('/projects?per_page=10&page='.(++$page));
            } catch (\Exception $e) {
                $this->io->write('<warning>GitLab repository from '.$this->url.' could not be loaded. '.$e->getMessage().'</warning>');

                return;
            }

            if (empty($projects)) {
                break;
            }

            $this->buildComposerPackages($projects);
        }
        var_dump($this->packages);
    }

    /**
     * Builds CompletePackages from PEAR package definition data.
     *
     * @param  array     $projects
     * @return CompletePackage
     */
    private function buildComposerPackages(array $projects)
    {
        $result = array();
        $versionParser = new VersionParser();
        $loader = new ArrayLoader($versionParser);

        foreach ($projects as $project) {
            if ($this->io->isVerbose()) {
                $this->io->write('Loading project '.$project['path_with_namespace']);
            }
            $cache = new Cache($this->io, $this->config->get('cache-repo-dir').'/'.$this->url.'/'.$project['id']);

            // Download branches and tags
            $branches = $this->getContents('/projects/'.$project['id'].'/repository/branches?per_page=100');

            // Find root identifier from the default branch
            $rootIdentifier = null;
            foreach ($branches as $branch) {
                if ($branch['name'] === $project['default_branch']) {
                    $rootIdentifier = $branch['commit']['id'];
                    break;
                }
            }

            if (null === $rootIdentifier) {
                throw new \RuntimeException('Root identifier not found from branch: '.$project['default_branch']);
            }

            # Find the package name
            try {
                $data = $this->getContents('/projects/'.$project['id'].'/repository/commits/'.$rootIdentifier.'/blob?filepath=composer.json', true);
                $packageName = !empty($data['name']) ? $data['name'] : null;
            } catch (\Exception $e) {
                if ($this->io->isVerbose()) {
                    $this->io->write('<error>Skipped parsing '.$rootIdentifier.', '.$e->getMessage().'</error>');
                }
                $packageName = null;
            }

            if (null === $packageName) {
                continue;
            }


            foreach ($branches as $branch) {
                // Validate branch name
                try {
                    $parsedBranch = $versionParser->normalizeBranch($branch['name']);
                } catch (Exception $e) {
                    if ($this->io->isVerbose()) {
                        $this->io->write('<warning>Skipped branch '.$branch['name'].', invalid name</warning>');
                    }
                    continue;
                }

                try {
                    $data = $this->getContents('/projects/'.$project['id'].'/repository/commits/'.$branch['commit']['id'].'/blob?filepath=composer.json', true);

                    $data['name'] = $packageName;
                    // $data['dist'] = array(
                    //     'type'      => 'zip',
                    //     'url'       => $this->url.'/',
                    //     'reference' => $branch['commit']['id'],
                    //     'shasum'    => '',
                    // );
                    $data['source'] = array(
                        'type'      => 'git',
                        'url'       => $project['path_with_namespace'].'.git',
                        'reference' => $branch['commit']['id'],
                    );

                    $data['version'] = $branch['name'];
                    $data['version_normalized'] = $parsedBranch;
                    // make sure branch packages have a dev flag
                    if ('dev-' === substr($parsedBranch, 0, 4) || '9999999-dev' === $parsedBranch) {
                        $data['version'] = 'dev-' . $data['version'];
                    } else {
                        $data['version'] = preg_replace('{(\.9{7})+}', '.x', $parsedBranch);
                    }

                    if ($this->io->isVerbose()) {
                        $this->io->write('Importing branch '.$branch['name'].' ('.$data['version'].')');
                    }

                    $this->addPackage($loader->load($data));
                } catch (\Exception $e) {
                    if ($this->io->isVerbose()) {
                        $this->io->write('<error>Skipped invalid composer.json for branch '.$branch['name'].', '.$e->getMessage().'</error>');
                    }
                }
            }

            // Load tags
            $tags = $this->getContents('/projects/'.$project['id'].'/repository/tags?per_page=100');
            continue;


            // foreach ($tags as $tag) {
            //     if ($this->io->isVerbose()) {
            //         $this->io->write('Tag '.$tag['name']);
            //     }
            //     try {
            //         $this->getContents('/project/'.$project['id'].'/repository/blob/'.$tag['commit']['id'].'/composer.json');
            //     } catch (\Exception $e) {

            //     }
            // }
        }

        return $result;
    }

    private function buildComposerPackageName($channelName, $packageName)
    {
        if ('php' === $channelName) {
            return "php";
        }
        if ('ext' === $channelName) {
            return "ext-{$packageName}";
        }

        return "pear-{$channelName}/{$packageName}";
    }

    protected function getContents($uri, $cache = false)
    {
        if ($cache && $content = $this->cache->read($uri)) {
            return json_decode($content, true);
        }

        $url = $this->url.'/api/v3'.$uri.'&private_token=oppD2cvkY1DFZinyTdxs';
        $content = $this->rfs->getContents($this->url, $url, false);

        $data = json_decode($content, true);

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON response from: '.$url);
        }

        if ($cache) {
            $this->cache->write($uri, $content);
        }

        return $data;
    }

    public function getComposerInformation($cache, $projectId, $identifier)
    {
        if (preg_match('{[a-f0-9]{40}}i', $identifier) && $res = $cache->read($identifier)) {
            $this->infoCache[$identifier] = JsonFile::parseJson($res);
        }

        if (!isset($this->infoCache[$identifier])) {
            $notFoundRetries = 2;
            while ($notFoundRetries) {
                try {
                    $resource = 'https://api.github.com/repos/'.$this->owner.'/'.$this->repository.'/contents/composer.json?ref='.urlencode($identifier);
                    $composer = JsonFile::parseJson($this->getContents($resource, true));
                    if (empty($composer['content']) || $composer['encoding'] !== 'base64' || !($composer = base64_decode($composer['content']))) {
                        throw new \RuntimeException('Could not retrieve composer.json from '.$resource);
                    }
                    break;
                } catch (TransportException $e) {
                    if (404 !== $e->getCode()) {
                        throw $e;
                    }

                    // TODO should be removed when possible
                    // retry fetching if github returns a 404 since they happen randomly
                    $notFoundRetries--;
                    $composer = false;
                }
            }

            if ($composer) {
                $composer = JsonFile::parseJson($composer, $resource);

                if (!isset($composer['time'])) {
                    $resource = 'https://api.github.com/repos/'.$this->owner.'/'.$this->repository.'/commits/'.urlencode($identifier);
                    '/projects/'.$project['id'].'/repository/commits/blobs/'.$rootIdentifier.'/composer.json?a=b';
                    $commit = JsonFile::parseJson($this->getContents($resource), $resource);
                    $composer['time'] = $commit['commit']['committer']['date'];
                }
                if (!isset($composer['support']['source'])) {
                    $label = array_search($identifier, $this->getTags()) ?: array_search($identifier, $this->getBranches()) ?: $identifier;
                    $composer['support']['source'] = sprintf('https://github.com/%s/%s/tree/%s', $this->owner, $this->repository, $label);
                }
                if (!isset($composer['support']['issues']) && $this->hasIssues) {
                    $composer['support']['issues'] = sprintf('https://github.com/%s/%s/issues', $this->owner, $this->repository);
                }
            }

            if (preg_match('{[a-f0-9]{40}}i', $identifier)) {
                $cache->write($identifier, json_encode($composer));
            }

            $this->infoCache[$identifier] = $composer;
        }

        return $this->infoCache[$identifier];
    }
}
