<?php
/**
 * Code Snippets Class
 *
 * Injects admin-managed CSS, JS, HTML, and PHP snippets into the site.
 *
 * @package CDG_Core
 * @since 1.7.0
 */

declare(strict_types=1);

class CDG_Core_Code_Snippets
{
  private CDG_Core $plugin;

  public function __construct(CDG_Core $plugin)
  {
    $this->plugin = $plugin;
    add_action("wp_head",   [$this, "inject_head"],   999);
    add_action("wp_footer", [$this, "inject_footer"], 999);
    add_action("init",      [$this, "run_php"],       1);
  }

  private function active(string $type, string $location = ""): array
  {
    $out = [];
    foreach ((array) ($this->plugin->get_settings()["code_snippets"] ?? []) as $s) {
      if (empty($s["active"]) || ($s["type"] ?? "") !== $type) {
        continue;
      }
      if ($location !== "" && ($s["location"] ?? "head") !== $location) {
        continue;
      }
      $out[] = $s;
    }
    return $out;
  }

  public function inject_head(): void
  {
    foreach ($this->active("css", "head") as $s) {
      echo "\n<style>\n" . $s["code"] . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }
    foreach ($this->active("js", "head") as $s) {
      echo "\n<script>\n" . $s["code"] . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }
    foreach ($this->active("html", "head") as $s) {
      echo "\n" . $s["code"] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }
  }

  public function inject_footer(): void
  {
    foreach ($this->active("css", "footer") as $s) {
      echo "\n<style>\n" . $s["code"] . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }
    foreach ($this->active("js", "footer") as $s) {
      echo "\n<script>\n" . $s["code"] . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }
    foreach ($this->active("html", "footer") as $s) {
      echo "\n" . $s["code"] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }
  }

  public function run_php(): void
  {
    // Do not run PHP snippets during AJAX or REST API calls — output or header()
    // calls inside a snippet would corrupt the JSON response.
    if (wp_doing_ajax() || (defined("REST_REQUEST") && REST_REQUEST)) {
      return;
    }

    foreach ($this->active("php") as $s) {
      try {
        eval($s["code"]); // phpcs:ignore Squiz.PHP.Eval.Discouraged
      } catch (\Throwable $e) {
        // Swallow errors to prevent site lockout — admins should test PHP carefully.
      }
    }
  }
}
