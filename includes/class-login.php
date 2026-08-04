<?php
/**
 * Login Class
 *
 * Customizes the WordPress login page with site branding and UX improvements.
 *
 * @package CDG_Core
 * @since 1.7.0
 */

declare(strict_types=1);

class CDG_Core_Login
{
  private CDG_Core $plugin;

  public function __construct(CDG_Core $plugin)
  {
    $this->plugin = $plugin;
    $this->setup_hooks();
  }

  private function setup_hooks(): void
  {
    if ($this->plugin->get_setting("enable_custom_login")) {
      add_action("login_enqueue_scripts", [$this, "enqueue_styles"]);
      add_filter("login_headerurl", [$this, "logo_url"]);
      add_filter("login_headertext", [$this, "logo_text"]);

      if ($this->plugin->get_setting("login_hide_language_switcher")) {
        add_filter("login_display_language_dropdown", "__return_false");
      }
    }

    if ($this->plugin->get_setting("login_generic_errors")) {
      add_filter("login_errors", [$this, "generic_error_message"]);
    }
  }

  public function enqueue_styles(): void
  {
    $css = $this->build_css();
    wp_register_style("cdg-login", false);
    wp_enqueue_style("cdg-login");
    wp_add_inline_style("cdg-login", $css);
  }

  public function logo_url(): string
  {
    return esc_url(home_url("/"));
  }

  public function logo_text(): string
  {
    return esc_attr(get_bloginfo("name"));
  }

  public function generic_error_message(): string
  {
    return "<strong>" .
      esc_html__("Error", "cdg-core") .
      ":</strong> " .
      esc_html__("Incorrect username or password.", "cdg-core");
  }

  private function build_css(): string
  {
    $accent = $this->resolve_accent_color();
    $logo_url = $this->get_logo_url();

    $logo_css = "";
    if ($logo_url) {
      $logo_css =
        'body.login #login h1 a {
        background-image: url(' .
        esc_url($logo_url) .
        ");
        background-size: contain;
        background-repeat: no-repeat;
        background-position: center;
        width: 100%;
        height: 80px;
      }";
    }

    $accent_css = "";
    if ($accent) {
      $accent_dark = $this->darken_hex($accent, 12);
      $accent_css =
        "body.login.wp-core-ui .button-primary,
      body.login.wp-core-ui .button-primary:visited {
        background-color: " .
        esc_attr($accent) .
        " !important;
        border-color: " .
        esc_attr($accent_dark) .
        " !important;
        color: #fff !important;
      }
      body.login.wp-core-ui .button-primary:hover,
      body.login.wp-core-ui .button-primary:focus {
        background-color: " .
        esc_attr($accent_dark) .
        " !important;
        border-color: " .
        esc_attr($accent_dark) .
        " !important;
      }
      body.login input[type='text']:focus,
      body.login input[type='password']:focus {
        border-color: " .
        esc_attr($accent) .
        ";
        box-shadow: 0 0 0 1px " .
        esc_attr($accent) .
        ";
        outline: none;
      }";
    }

    $hide_css = "";
    if ($this->plugin->get_setting("login_hide_backtoblog")) {
      $hide_css .= "#backtoblog { display: none; }";
    }
    if ($this->plugin->get_setting("login_hide_register_link")) {
      $hide_css .= "#nav { display: none; }";
    }

    return ":root {
      --cdg-surface: #ffffff;
      --cdg-border: #e4e4e7;
      --cdg-radius: 6px;
      --cdg-radius-lg: 10px;
    }
    body.login {
      background-color: #f0f0f1;
    }
    body.login #login {
      background: var(--cdg-surface);
      border: 1px solid var(--cdg-border);
      border-radius: var(--cdg-radius-lg);
      padding: 32px;
      margin-top: 80px;
      width: 320px;
    }
    body.login #loginform {
      border: none !important;
      box-shadow: none !important;
      background: transparent;
      padding: 0;
      margin-top: 20px;
    }
    body.login #login h1 a {
      margin-bottom: 8px;
    }
    body.login input[type='text'],
    body.login input[type='password'] {
      border-radius: 6px;
      border-color: #dcdcde;
      box-shadow: none;
    }
    body.login .wp-core-ui .button-primary {
      border-radius: 6px;
      width: 100%;
      display: flex;
      justify-content: center;
      height: 38px;
      transition: background-color 0.15s ease, border-color 0.15s ease;
    }
    body.login #nav,
    body.login #backtoblog {
      text-align: center;
      margin-top: 16px;
    }
    body.login #nav a,
    body.login #backtoblog a {
      font-size: 12px;
    }
    " .
      $logo_css .
      $accent_css .
      $hide_css;
  }

  private function get_logo_url(): string
  {
    // Dedicated login logo takes priority
    $logo_id = absint($this->plugin->get_setting("login_logo_id"));

    // Fall back to the site's Customizer logo
    if (!$logo_id) {
      $logo_id = (int) get_theme_mod("custom_logo");
    }

    if (!$logo_id) {
      return "";
    }

    return wp_get_attachment_image_url($logo_id, "full") ?: "";
  }

  private function resolve_accent_color(): string
  {
    return $this->plugin->resolve_accent_color();
  }

  private function darken_hex(string $hex, int $percent): string
  {
    $hex = ltrim($hex, "#");

    if (strlen($hex) === 3) {
      $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    $factor = 1 - $percent / 100;
    $r = max(0, (int) round(hexdec(substr($hex, 0, 2)) * $factor));
    $g = max(0, (int) round(hexdec(substr($hex, 2, 2)) * $factor));
    $b = max(0, (int) round(hexdec(substr($hex, 4, 2)) * $factor));

    return sprintf("#%02x%02x%02x", $r, $g, $b);
  }
}
