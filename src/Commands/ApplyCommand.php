<?php

namespace Pantheon\TerminusMassUpdate\Commands;

require_once "MassUpdateCommandBase.php";

class ApplyCommand extends MassUpdateCommandBase
{
  protected $command = "site:mass-update:apply";

  /**
   * Apply all available upstream updates to all sites.
   *
   * @authorize
   *
   * @command site:mass-update:apply
   * @aliases mass-update
   *
   * @param array $options
   *
   * @throws \Pantheon\Terminus\Exceptions\TerminusException
   * @option upstream Update only sites using the given upstream
   * @option org Fetch sites from a specific organization UUID
   * @option boolean $updatedb Run update.php after updating (Drupal only)
   * @option boolean $accept-upstream Attempt to automatically resolve conflicts in favor of the upstream
   * @option boolean $config-import Run drush config:import after updating
   * @option boolean $cache-clear Run drush cache:rebuild after updating
   * @option dry-run Don't actually apply the updates
   * @option boolean $skip-frozen Skip frozen sites without error
   */
  public function applyAllUpdates(
    $options = [
      "upstream" => "",
      "org" => "",
      "updatedb" => false,
      "accept-upstream" => false,
      "config-import" => false,
      "cache-clear" => false,
      "dry-run" => false,
      "skip-frozen" => true,
    ]
  ) {
    $site_updates = $this->getAllSitesAndUpdates($options);

    $total = count($site_updates);
    $success = 0;
    $failed = 0;
    $skipped = 0;
    $current = 0;

    foreach ($site_updates as $info) {
      $site = $info["site"];
      $updates = $info["updates"];
      $site_name = $site->getName();
      $current++;

      $this->log()->notice("[{current}/{total}] Processing {site}...", [
        "current" => $current,
        "total" => $total,
        "site" => $site_name,
      ]);

      // Check if site is frozen
      if ($options["skip-frozen"]) {
        try {
          $is_frozen = $site->get("frozen");
          if ($is_frozen) {
            $this->log()->warning("Skipping {site} - site is frozen.", [
              "site" => $site_name,
            ]);
            $skipped++;
            continue;
          }
        } catch (\Exception $e) {
          // If we can't check, proceed anyway
        }
      }

      $env = $site->getEnvironments()->get("dev");

      if ($env->get("connection_mode") !== "git") {
        $this->log()->warning(
          "Skipping {site} - dev environment is not in git mode.",
          ["site" => $site_name]
        );
        $skipped++;
        continue;
      }

      $logname = $options["dry-run"] ? "DRY RUN" : "notice";
      $this->log()->notice("{logname}: Applying {updates} updates to {site}", [
        "site" => $site_name,
        "updates" => count($updates),
        "logname" => $logname,
      ]);

      if (!$options["dry-run"]) {
        try {
          $workflow = $env->applyUpstreamUpdates(
            isset($options["updatedb"]) ? $options["updatedb"] : false,
            isset($options["accept-upstream"])
              ? $options["accept-upstream"]
              : false
          );

          $this->processWorkflow($workflow);
          $this->log()->notice("Upstream updates applied to {site}.", [
            "site" => $site_name,
          ]);

          // Post-deploy tasks
          $this->runPostDeployTasks($site, "dev", $options);

          $success++;
        } catch (\Exception $e) {
          $this->log()->error("Failed to update {site}: {error}", [
            "site" => $site_name,
            "error" => $e->getMessage(),
          ]);
          $failed++;
        }
      } else {
        $success++;
      }
    }

    // Print summary
    $this->log()->notice("");
    $this->log()->notice("===============================");
    $this->log()->notice("  MASS UPDATE SUMMARY");
    $this->log()->notice("===============================");
    $this->log()->notice("Total sites with updates: {total}", [
      "total" => $total,
    ]);
    $this->log()->notice("Succeeded:    {success}", ["success" => $success]);
    $this->log()->notice("Failed:       {failed}", ["failed" => $failed]);
    $this->log()->notice("Skipped:      {skipped}", ["skipped" => $skipped]);

    if ($failed > 0) {
      throw new \Pantheon\Terminus\Exceptions\TerminusException(
        "{failed} site(s) failed to update. Review the logs above for details.",
        ["failed" => $failed]
      );
    }
  }
  /**
   * Run post-deploy tasks (drush updb, cim, cr) on a site environment.
   *
   * @param object $site
   * @param string $env_id
   * @param array $options
   */
  protected function runPostDeployTasks($site, $env_id, $options)
  {
    $site_name = $site->getName();
    $site_env = "{$site_name}.{$env_id}";
    $terminus = $_SERVER["argv"][0] ?? "terminus";

    if (!empty($options["updatedb"])) {
      $this->log()->notice("  Running database updates on {site}...", [
        "site" => $site_name,
      ]);
      passthru("$terminus drush $site_env -- updb -y 2>&1", $exit);
      if ($exit !== 0) {
        $this->log()->warning("  Database updates failed on {site}.", [
          "site" => $site_name,
        ]);
      }
    }

    if (!empty($options["config-import"])) {
      $this->log()->notice("  Importing configuration on {site}...", [
        "site" => $site_name,
      ]);
      passthru("$terminus drush $site_env -- cim -y 2>&1", $exit);
      if ($exit !== 0) {
        $this->log()->warning("  Config import failed on {site}.", [
          "site" => $site_name,
        ]);
      }
    }

    if (!empty($options["cache-clear"])) {
      $this->log()->notice("  Clearing caches on {site}...", [
        "site" => $site_name,
      ]);
      passthru("$terminus drush $site_env -- cr 2>&1", $exit);
      if ($exit !== 0) {
        $this->log()->warning("  Cache clear failed on {site}.", [
          "site" => $site_name,
        ]);
      }
    }
  }
}
