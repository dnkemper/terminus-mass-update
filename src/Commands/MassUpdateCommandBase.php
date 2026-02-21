<?php

namespace Pantheon\TerminusMassUpdate\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;

abstract class MassUpdateCommandBase extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;

    protected $command = '';

    /**
     * Get a list of the sites and updates with the given options.
     *
     * @return array
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    protected function getAllSitesAndUpdates($options)
    {
        $sites = $this->getAllSites($options);
        $this->log()->notice("Found {count} sites.", ['count' => count($sites)]);
        $this->log()->notice("Fetching the list of available updates for each site...");
        $updates = $this->getAllUpdates($sites);
        $this->log()->notice(
            "{sites} sites need updates.",
            ['sites' => count($updates)]
        );

        return $updates;
    }

    /**
     * Get a list of all sites via STDIN or org UUID.
     *
     * @param $options
     * @return array
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    protected function getAllSites($options)
    {
        // Try org-based lookup first
        if (!empty($options['org'])) {
            return $this->getSitesFromOrg($options['org'], $options);
        }

        // Fall back to STDIN
        $sites = $this->readSitesFromSTDIN();
        if (empty($sites)) {
            throw new TerminusException(
                'Provide a list of sites via STDIN or use --org=<uuid>. Try "terminus site:list | terminus {cmd}" or "terminus {cmd} --org=<uuid>".',
                ['cmd' => $this->command]
            );
        }

        // Filter by upstream
        if (!empty($options['upstream'])) {
            $sites = $this->filterByUpstream($sites, $options['upstream']);
        }

        return $sites;
    }

    /**
     * Get sites from an organization.
     *
     * @param string $org_id
     * @param array $options
     * @return array
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    protected function getSitesFromOrg($org_id, $options)
    {
        $this->log()->notice("Fetching sites from organization {org}...", ['org' => $org_id]);

        $org = $this->session()->getUser()->getOrganizationMemberships()->get($org_id);
        $site_memberships = $org->getOrganization()->getSiteMemberships()->all();

        $sites = [];
        foreach ($site_memberships as $membership) {
            $sites[] = $membership->getSite();
        }

        if (empty($sites)) {
            throw new TerminusException('No sites found in organization {org}.', ['org' => $org_id]);
        }

        // Filter by upstream
        if (!empty($options['upstream'])) {
            $sites = $this->filterByUpstream($sites, $options['upstream']);
        }

        return $sites;
    }

    /**
     * Filter sites by upstream ID.
     *
     * @param array $sites
     * @param string $upstream_id
     * @return array
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    protected function filterByUpstream($sites, $upstream_id)
    {
        $filtered = [];
        foreach ($sites as $site) {
            $upstream = $site->getUpstream();
            if ($upstream->id == $upstream_id) {
                $filtered[] = $site;
            }
        }
        if (empty($filtered)) {
            throw new TerminusException('None of the specified sites use the given upstream.');
        }
        return $filtered;
    }

    /**
     * Get the list of updates for all of the sites passed in.
     *
     * @param array $sites
     * @param string $env_id
     * @return array
     */
    protected function getAllUpdates($sites, $env_id = 'dev')
    {
        $out = [];
        foreach ($sites as $site) {
            try {
                $env = $site->getEnvironments()->get($env_id);
                $upstream_status = $env->getUpstreamStatus();
                $updates = $upstream_status->getUpdates();

                if (!empty($updates)) {
                    foreach ($updates as $commit) {
                        $out[$site->id]['updates'][] = [
                            'site' => $site->getName(),
                            'datetime' => $commit->datetime ?? '',
                            'message' => trim($commit->message ?? ''),
                            'author' => $commit->author ?? '',
                        ];
                    }
                    $out[$site->id]['site'] = $site;
                }
            } catch (\Exception $e) {
                $this->log()->warning(
                    'Could not check updates for {site}: {error}',
                    ['site' => $site->getName(), 'error' => $e->getMessage()]
                );
            }
        }
        return $out;
    }

    /**
     * Read a list of site ids passed through STDIN and load the sites.
     *
     * @return array
     */
    protected function readSitesFromStdin()
    {
        if (posix_isatty(STDIN)) {
            return [];
        }
        $sites = [];
        while ($line = trim(fgets(STDIN))) {
            try {
                $sites[] = $this->getSiteById($line);
            } catch (\Exception $e) {
                continue;
            }
        }
        return $sites;
    }

    /**
     * Process the workflow, compatible with Terminus 3 and 4.
     *
     * @param \Pantheon\Terminus\Models\Workflow $workflow
     */
    protected function processWorkflow($workflow)
    {
        // Terminus 4: use WorkflowProcessingTrait or processWorkflow
        if (method_exists($this, 'processWorkflow')) {
            // Already called via this method
        }

        // Terminus 3 fallback: checkProgress
        if (method_exists($workflow, 'checkProgress')) {
            while (!$workflow->checkProgress()) {
                // Wait for completion
            }
            return;
        }

        // Terminus 4: wait on workflow
        if (method_exists($workflow, 'fetch')) {
            $workflow->fetch();
            while (!$workflow->isFinished()) {
                sleep(1);
                $workflow->fetch();
            }
            return;
        }
    }
}
