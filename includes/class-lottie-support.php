<?php
/**
 * Lottie Support Class
 *
 * Handles Lottie/JSON file upload support for WordPress.
 *
 * @package CDG_Core
 * @since 1.3.1
 */

declare(strict_types=1);

class CDG_Core_Lottie_Support
{
  /**
   * Plugin instance
   *
   * @var CDG_Core
   */
  private CDG_Core $plugin;

  /**
   * Supported Lottie mime types
   *
   * @var array<string, string>
   */
  private const LOTTIE_MIMES = [
    "json" => "application/json",
    "lottie" => "application/json",
  ];

  /**
   * Constructor
   *
   * @param CDG_Core $plugin Plugin instance
   */
  public function __construct(CDG_Core $plugin)
  {
    $this->plugin = $plugin;

    if ($this->plugin->get_setting("enable_lottie_uploads")) {
      $this->setup_hooks();
    }
  }

  /**
   * Required top-level keys that identify a valid Lottie animation
   *
   * @var array<int, string>
   */
  private const LOTTIE_REQUIRED_KEYS = ['v', 'fr', 'ip', 'op', 'layers'];

  /**
   * Setup hooks
   *
   * @return void
   */
  private function setup_hooks(): void
  {
    // Add Lottie/JSON to allowed mime types
    add_filter("upload_mimes", [$this, "allow_lottie_upload"], 20);

    // Fix mime type detection
    add_filter(
      "wp_check_filetype_and_ext",
      [$this, "fix_lottie_mime_type"],
      10,
      5
    );

    // Validate JSON uploads are actually Lottie files
    add_filter("wp_handle_upload_prefilter", [$this, "validate_lottie_upload"]);
  }

  /**
   * Allow Lottie/JSON file uploads
   *
   * @param array<string, string> $mimes Allowed mime types
   * @return array<string, string>
   */
  public function allow_lottie_upload(array $mimes): array
  {
    if (!current_user_can("upload_files")) {
      return $mimes;
    }

    if (
      $this->plugin->get_setting("lottie_admin_only") &&
      !current_user_can("manage_options")
    ) {
      return $mimes;
    }

    foreach (self::LOTTIE_MIMES as $ext => $mime) {
      $mimes[$ext] = $mime;
    }

    return $mimes;
  }

  /**
   * Validate that uploaded JSON files are Lottie animations
   *
   * Checks for required top-level keys (v, fr, ip, op, layers) that
   * every valid Lottie animation must contain.
   *
   * @param array<string, mixed> $file Upload file data
   * @return array<string, mixed>
   */
  public function validate_lottie_upload(array $file): array
  {
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));

    if (!in_array($ext, ['json', 'lottie'], true)) {
      return $file;
    }

    $file_path = $file['tmp_name'] ?? '';

    if (empty($file_path) || !file_exists($file_path)) {
      return $file;
    }

    $content = file_get_contents($file_path);

    if ($content === false) {
      $file['error'] = __('Could not read JSON file for validation.', 'cdg-core');
      return $file;
    }

    $data = json_decode($content, true);

    if (!is_array($data)) {
      $file['error'] = __('File is not valid JSON.', 'cdg-core');
      return $file;
    }

    // Check for required Lottie keys
    foreach (self::LOTTIE_REQUIRED_KEYS as $key) {
      if (!array_key_exists($key, $data)) {
        $file['error'] = sprintf(
          /* translators: %s: missing key name */
          __('JSON file does not appear to be a Lottie animation (missing "%s" key). Only Lottie animation files are allowed.', 'cdg-core'),
          $key
        );
        return $file;
      }
    }

    return $file;
  }

  /**
   * Fix Lottie/JSON mime type detection
   *
   * @param array<string, mixed> $data File data
   * @param string $file File path
   * @param string $filename File name
   * @param array<string, string>|null $mimes Allowed mime types
   * @param string|false $real_mime Real mime type
   * @return array<string, mixed>
   */
  public function fix_lottie_mime_type(
    array $data,
    string $file,
    string $filename,
    ?array $mimes,
    $real_mime
  ): array {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (array_key_exists($ext, self::LOTTIE_MIMES)) {
      $data["ext"] = $ext;
      $data["type"] = self::LOTTIE_MIMES[$ext];
    }

    return $data;
  }

  /**
   * Check if Lottie uploads are enabled
   *
   * @return bool
   */
  public static function is_enabled(): bool
  {
    if (!function_exists("cdg_core")) {
      return false;
    }

    return (bool) cdg_core()->get_setting("enable_lottie_uploads");
  }
}
