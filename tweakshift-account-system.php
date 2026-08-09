<?php
/**
 * Plugin Name: TweakShift Account System
 * Plugin URI: https://tweakshift.com/
 * Description: Premium login, Google/Discord social sign-in, registration, dashboard, protected downloads, and Freemius auto-sync for TweakShift.
 * Version: 1.3.2
 * Author: TweakShift
 * Author URI: https://tweakshift.com/
 * Text Domain: tweakshift-account-system
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TweakShift_Account_System {
    const VERSION = '1.3.2';
    const OPTION_KEY = 'tas_settings';
    const COOKIE_NAME = 'tas_auth_token';

    private static $instance = null;
    private static $assets_rendered = false;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'handle_admin_save'));
        add_action('init', array($this, 'maybe_upgrade_settings'), 1);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('template_redirect', array($this, 'maybe_redirect_purchase_return_alias'));
        add_action('template_redirect', array($this, 'maybe_handle_oauth_callback_redirect'), 1);

        add_action('wp_ajax_tas_header_status', array($this, 'ajax_header_status'));
        add_action('wp_ajax_nopriv_tas_header_status', array($this, 'ajax_header_status'));
        add_action('wp_ajax_tas_header_logout', array($this, 'ajax_header_logout'));
        add_action('wp_ajax_nopriv_tas_header_logout', array($this, 'ajax_header_logout'));

        add_shortcode('tweakshift_login', array($this, 'shortcode_login'));
        add_shortcode('tweakshift_register', array($this, 'shortcode_register'));
        add_shortcode('tweakshift_forgot_password', array($this, 'shortcode_forgot_password'));
        add_shortcode('tweakshift_reset_password', array($this, 'shortcode_reset_password'));
        add_shortcode('tweakshift_dashboard', array($this, 'shortcode_dashboard'));
        add_shortcode('tweakshift_purchase_success', array($this, 'shortcode_purchase_success'));
        add_shortcode('tweakshift_oauth_callback', array($this, 'shortcode_oauth_callback'));

        add_action('admin_post_nopriv_tas_login', array($this, 'handle_login'));
        add_action('admin_post_tas_login', array($this, 'handle_login'));
        add_action('admin_post_nopriv_tas_register', array($this, 'handle_register'));
        add_action('admin_post_tas_register', array($this, 'handle_register'));
        add_action('admin_post_nopriv_tas_forgot_password', array($this, 'handle_forgot_password'));
        add_action('admin_post_tas_forgot_password', array($this, 'handle_forgot_password'));
        add_action('admin_post_nopriv_tas_reset_password', array($this, 'handle_reset_password'));
        add_action('admin_post_tas_reset_password', array($this, 'handle_reset_password'));
        add_action('admin_post_nopriv_tas_logout', array($this, 'handle_logout'));
        add_action('admin_post_tas_logout', array($this, 'handle_logout'));
        add_action('admin_post_nopriv_tas_claim_license', array($this, 'handle_claim_license'));
        add_action('admin_post_tas_claim_license', array($this, 'handle_claim_license'));
        add_action('admin_post_nopriv_tas_sync_purchase', array($this, 'handle_sync_purchase'));
        add_action('admin_post_tas_sync_purchase', array($this, 'handle_sync_purchase'));
        add_action('admin_post_nopriv_tas_logout_device', array($this, 'handle_logout_device'));
        add_action('admin_post_tas_logout_device', array($this, 'handle_logout_device'));
        add_action('admin_post_nopriv_tas_checkout', array($this, 'handle_checkout'));
        add_action('admin_post_tas_checkout', array($this, 'handle_checkout'));
        add_action('admin_post_nopriv_tas_download', array($this, 'handle_download'));
        add_action('admin_post_tas_download', array($this, 'handle_download'));
    }

    public static function activate() {
        $existing = get_option(self::OPTION_KEY, array());
        $settings = wp_parse_args(is_array($existing) ? $existing : array(), self::default_settings());
        update_option(self::OPTION_KEY, $settings);
        self::maybe_create_pages();
    }

    public static function default_settings() {
        return array(
            'api_url' => 'https://tweakshift-auth-api.onrender.com',
            'login_url' => home_url('/login/'),
            'register_url' => home_url('/register/'),
            'forgot_url' => home_url('/forgot-password/'),
            'reset_url' => home_url('/reset-password/'),
            'dashboard_url' => home_url('/dashboard/'),
            'purchase_success_url' => home_url('/purchase-success/'),
            'oauth_callback_url' => home_url('/auth-callback/'),
            'purchase_return_aliases' => '',
            'classic_buy_url' => home_url('/#tsx-pricing'),
            'ai_buy_url' => home_url('/#tsx-pricing'),
            'classic_download_url' => 'https://tweakshift.com/69fe57d6-ec70-83e8-9dea-707a240612db/',
            'ai_download_url' => 'https://tweakshift.com/69fe57d6-ec70-83e8-9dea-707a240613db/',
            'classic_name' => 'TweakShift Classic',
            'ai_name' => 'TweakShift AI Engine',
            'logo_url' => 'https://tweakshift.com/wp-content/uploads/2026/06/ChatGPT_Image_Jun_16__2026__12_26_30_AM-removebg-preview-e1781551776292.png',
            'support_email' => 'support@tweakshift.com',
        );
    }

    public static function maybe_create_pages() {
        $pages = array(
            array('title' => 'Login', 'slug' => 'login', 'shortcode' => '[tweakshift_login]', 'setting' => 'login_url'),
            array('title' => 'Register', 'slug' => 'register', 'shortcode' => '[tweakshift_register]', 'setting' => 'register_url'),
            array('title' => 'Forgot Password', 'slug' => 'forgot-password', 'shortcode' => '[tweakshift_forgot_password]', 'setting' => 'forgot_url'),
            array('title' => 'Reset Password', 'slug' => 'reset-password', 'shortcode' => '[tweakshift_reset_password]', 'setting' => 'reset_url'),
            array('title' => 'Dashboard', 'slug' => 'dashboard', 'shortcode' => '[tweakshift_dashboard]', 'setting' => 'dashboard_url'),
            array('title' => 'Purchase Success', 'slug' => 'purchase-success', 'shortcode' => '[tweakshift_purchase_success]', 'setting' => 'purchase_success_url'),
            array('title' => 'Auth Callback', 'slug' => 'auth-callback', 'shortcode' => '[tweakshift_oauth_callback]', 'setting' => 'oauth_callback_url'),
        );

        $settings = wp_parse_args(get_option(self::OPTION_KEY, array()), self::default_settings());

        foreach ($pages as $page) {
            $existing = get_page_by_path($page['slug']);
            if ($existing && !is_wp_error($existing)) {
                $settings[$page['setting']] = get_permalink($existing->ID);
                continue;
            }

            $page_id = wp_insert_post(array(
                'post_title' => $page['title'],
                'post_name' => $page['slug'],
                'post_content' => $page['shortcode'],
                'post_status' => 'publish',
                'post_type' => 'page',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ));

            if ($page_id && !is_wp_error($page_id)) {
                $settings[$page['setting']] = get_permalink($page_id);
            }
        }

        update_option(self::OPTION_KEY, $settings);
    }

    public function maybe_upgrade_settings() {
        $settings = wp_parse_args(get_option(self::OPTION_KEY, array()), self::default_settings());
        $stored_version = isset($settings['_version']) ? (string) $settings['_version'] : '0.0.0';

        if (version_compare($stored_version, self::VERSION, '>=')) {
            return;
        }

        $old_classic = array(
            'https://tweakshift.com/tweakshift-windows-optimaization/',
            home_url('/tweakshift-windows-optimaization/'),
            '',
        );
        $old_ai = array(
            'https://tweakshift.com/tweakshift-ai-engine/',
            home_url('/tweakshift-ai-engine/'),
            '',
        );

        if (in_array(isset($settings['classic_download_url']) ? $settings['classic_download_url'] : '', $old_classic, true)) {
            $settings['classic_download_url'] = 'https://tweakshift.com/69fe57d6-ec70-83e8-9dea-707a240612db/';
        }

        if (in_array(isset($settings['ai_download_url']) ? $settings['ai_download_url'] : '', $old_ai, true)) {
            $settings['ai_download_url'] = 'https://tweakshift.com/69fe57d6-ec70-83e8-9dea-707a240613db/';
        }

        // These hidden pages are now protected download targets, not checkout success targets.
        if (!empty($settings['purchase_return_aliases'])) {
            $settings['purchase_return_aliases'] = '';
        }

        $settings['_version'] = self::VERSION;
        update_option(self::OPTION_KEY, $settings);
        self::maybe_create_pages();
    }

    public function admin_menu() {
        add_menu_page(
            'TweakShift Account',
            'TweakShift Account',
            'manage_options',
            'tweakshift-account',
            array($this, 'render_admin_page'),
            'dashicons-shield-alt',
            58
        );
    }

    public function handle_admin_save() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (!isset($_POST['tas_save_settings'])) {
            return;
        }

        check_admin_referer('tas_save_settings_action', 'tas_save_settings_nonce');

        $current = $this->settings();
        $url_fields = array('api_url', 'login_url', 'register_url', 'forgot_url', 'reset_url', 'dashboard_url', 'purchase_success_url', 'oauth_callback_url', 'classic_buy_url', 'ai_buy_url', 'classic_download_url', 'ai_download_url', 'logo_url');
        foreach ($url_fields as $field) {
            $current[$field] = isset($_POST[$field]) ? esc_url_raw(trim(wp_unslash($_POST[$field]))) : '';
        }

        $text_fields = array('classic_name', 'ai_name', 'support_email', 'purchase_return_aliases');
        foreach ($text_fields as $field) {
            $current[$field] = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
        }

        update_option(self::OPTION_KEY, $current);
        wp_safe_redirect(add_query_arg('tas_saved', '1', menu_page_url('tweakshift-account', false)));
        exit;
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = $this->settings();
        ?>
        <div class="wrap tas-admin-wrap">
            <h1>TweakShift Account System</h1>
            <?php if (isset($_GET['tas_saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>
            <div class="tas-admin-card" style="max-width:980px;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:22px;margin-top:18px;box-shadow:0 8px 24px rgba(0,0,0,.06);">
                <h2 style="margin-top:0;">Connection Settings</h2>
                <p>Connect the WordPress forms and dashboard to your Render Auth API. Do not add database passwords here.</p>
                <form method="post" action="">
                    <?php wp_nonce_field('tas_save_settings_action', 'tas_save_settings_nonce'); ?>
                    <input type="hidden" name="tas_save_settings" value="1">
                    <table class="form-table" role="presentation">
                        <?php
                        $fields = array(
                            'api_url' => 'Render API URL',
                            'login_url' => 'Login Page URL',
                            'register_url' => 'Register Page URL',
                            'forgot_url' => 'Forgot Password Page URL',
                            'reset_url' => 'Reset Password Page URL',
                            'dashboard_url' => 'Dashboard Page URL',
                            'purchase_success_url' => 'Purchase Success Page URL',
                            'oauth_callback_url' => 'OAuth Callback Page URL',
                            'purchase_return_aliases' => 'Freemius Return Page Alias(es)',
                            'classic_buy_url' => 'Classic Buy Link',
                            'ai_buy_url' => 'AI Engine Buy Link',
                            'classic_download_url' => 'Classic Download Link',
                            'ai_download_url' => 'AI Engine Download Link',
                            'logo_url' => 'Logo URL',
                            'classic_name' => 'Classic Product Name',
                            'ai_name' => 'AI Product Name',
                            'support_email' => 'Support Email',
                        );
                        foreach ($fields as $key => $label) :
                        ?>
                            <tr>
                                <th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                                <td>
                                    <input name="<?php echo esc_attr($key); ?>" id="<?php echo esc_attr($key); ?>" type="text" class="regular-text" style="width:100%;max-width:720px;" value="<?php echo esc_attr(isset($s[$key]) ? $s[$key] : ''); ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    <?php submit_button('Save Settings'); ?>
                </form>
            </div>
            <div class="tas-admin-card" style="max-width:980px;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:22px;margin-top:18px;box-shadow:0 8px 24px rgba(0,0,0,.06);">
                <h2 style="margin-top:0;">Shortcodes</h2>
                <p>Place these shortcodes inside Elementor Shortcode widgets or normal WordPress pages.</p>
                <code>[tweakshift_login]</code><br>
                <code>[tweakshift_register]</code><br>
                <code>[tweakshift_forgot_password]</code><br>
                <code>[tweakshift_reset_password]</code><br>
                <code>[tweakshift_dashboard]</code><br>
                <code>[tweakshift_purchase_success]</code><br>
                <code>[tweakshift_oauth_callback]</code>
            </div>
        </div>
        <?php
    }

    public function enqueue_assets() {
        wp_enqueue_style('tas-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Inter+Tight:wght@500;600;700;800;900&display=swap', array(), null);
        wp_enqueue_style('tas-frontend', plugin_dir_url(__FILE__) . 'assets/css/tas-frontend.css', array(), self::VERSION);
        wp_enqueue_script('tas-frontend', plugin_dir_url(__FILE__) . 'assets/js/tas-frontend.js', array(), self::VERSION, true);
        wp_localize_script('tas-frontend', 'tasFrontend', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'logoutNonce' => wp_create_nonce('tas_header_logout_action'),
            'homeUrl' => home_url('/'),
            'loginUrl' => $this->setting('login_url'),
        ));
    }

    private function settings() {
        return wp_parse_args(get_option(self::OPTION_KEY, array()), self::default_settings());
    }

    private function setting($key) {
        $s = $this->settings();
        return isset($s[$key]) ? $s[$key] : '';
    }

    public function maybe_redirect_purchase_return_alias() {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ($request_uri === '') {
            return;
        }

        $request_path = parse_url($request_uri, PHP_URL_PATH);
        $request_path = '/' . trim((string) $request_path, '/') . '/';

        $aliases_raw = trim((string) $this->setting('purchase_return_aliases'));
        if ($aliases_raw === '') {
            return;
        }

        $aliases = preg_split('/[\r\n,]+/', $aliases_raw);

        foreach ($aliases as $alias) {
            $alias = trim((string) $alias);
            if ($alias === '') {
                continue;
            }

            $alias_path = parse_url($alias, PHP_URL_PATH);
            $alias_path = '/' . trim((string) $alias_path, '/') . '/';

            if ($alias_path === $request_path) {
                $target = $this->setting('purchase_success_url');
                if (!$target) {
                    $target = home_url('/purchase-success/');
                }

                $query = parse_url($request_uri, PHP_URL_QUERY);
                if ($query) {
                    $target = add_query_arg(array('freemius_return' => '1'), $target);
                }

                wp_safe_redirect($target, 302);
                exit;
            }
        }
    }

    private function token() {
        return isset($_COOKIE[self::COOKIE_NAME]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME])) : '';
    }

    private function is_logged_in() {
        return (bool) $this->token();
    }

    private function set_auth_cookie($token) {
        $token = sanitize_text_field($token);
        $expire = time() + (7 * DAY_IN_SECONDS);
        $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        $secure = is_ssl();

        if (PHP_VERSION_ID >= 70300) {
            setcookie(self::COOKIE_NAME, $token, array(
                'expires' => $expire,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        } else {
            setcookie(self::COOKIE_NAME, $token, $expire, $path, $domain, $secure, true);
        }

        $_COOKIE[self::COOKIE_NAME] = $token;
    }

    private function clear_auth_cookie() {
        $secure = is_ssl();
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        $paths = array('/', defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/', defined('SITECOOKIEPATH') && SITECOOKIEPATH ? SITECOOKIEPATH : '/');
        $paths = array_unique(array_filter($paths));

        foreach ($paths as $path) {
            if (PHP_VERSION_ID >= 70300) {
                setcookie(self::COOKIE_NAME, '', array(
                    'expires' => time() - HOUR_IN_SECONDS,
                    'path' => $path,
                    'domain' => $domain,
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ));
            } else {
                setcookie(self::COOKIE_NAME, '', time() - HOUR_IN_SECONDS, $path, $domain, $secure, true);
            }
        }

        unset($_COOKIE[self::COOKIE_NAME]);
    }

    private function safe_same_site_redirect($fallback = '') {
        $fallback = $fallback ? $fallback : home_url('/');
        $target = '';

        if (isset($_GET['tas_redirect'])) {
            $target = esc_url_raw(wp_unslash($_GET['tas_redirect']));
        }

        if (!$target && wp_get_referer()) {
            $target = esc_url_raw(wp_get_referer());
        }

        if (!$target) {
            return $fallback;
        }

        $target_host = wp_parse_url($target, PHP_URL_HOST);
        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);

        if (!$target_host || !$home_host || strtolower($target_host) !== strtolower($home_host)) {
            return $fallback;
        }

        if (strpos($target, 'admin-post.php') !== false || strpos($target, 'admin-ajax.php') !== false) {
            return $fallback;
        }

        return $target;
    }

    private function redirect_url($key, $args = array()) {
        $url = $this->setting($key);
        if (!$url) {
            $url = home_url('/');
        }
        if (!empty($args)) {
            $url = add_query_arg($args, $url);
        }
        return $url;
    }

    private function social_login_url($provider) {
        $provider = sanitize_key($provider);
        if (!in_array($provider, array('google', 'discord'), true)) {
            return '#';
        }

        $api_url = untrailingslashit($this->setting('api_url'));
        if (!$api_url) {
            return '#';
        }

        $callback_url = $this->setting('oauth_callback_url');
        if (!$callback_url) {
            $callback_url = home_url('/auth-callback/');
        }

        return esc_url($api_url . '/api/auth/' . $provider . '?redirect=' . rawurlencode($callback_url));
    }

    private function social_icon($provider) {
        if ($provider === 'google') {
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09Z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.24 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.15v2.84C3.96 20.53 7.68 23 12 23Z"/><path fill="#FBBC05" d="M5.84 14.1c-.22-.66-.35-1.36-.35-2.1s.13-1.44.35-2.1V7.06H2.15A10.97 10.97 0 0 0 1 12c0 1.77.42 3.45 1.15 4.94l3.69-2.84Z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.68 1 3.96 3.47 2.15 7.06L5.84 9.9C6.71 7.31 9.14 5.38 12 5.38Z"/></svg>';
        }
        return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.32 4.37A18.15 18.15 0 0 0 15.77 3c-.2.36-.43.85-.58 1.23a16.83 16.83 0 0 0-5.04 0A12.4 12.4 0 0 0 9.56 3c-1.6.27-3.12.74-4.55 1.37C2.13 8.73 1.35 12.98 1.74 17.17A18.32 18.32 0 0 0 7.31 20c.45-.61.85-1.26 1.19-1.95-.65-.25-1.27-.55-1.86-.9.16-.12.31-.24.46-.37a13.05 13.05 0 0 0 11.12 0l.46.37c-.59.35-1.21.65-1.86.9.34.69.74 1.34 1.19 1.95a18.27 18.27 0 0 0 5.57-2.83c.46-4.86-.78-9.07-3.26-12.8ZM8.68 14.6c-1.08 0-1.96-.99-1.96-2.2 0-1.22.87-2.2 1.96-2.2 1.1 0 1.98.99 1.96 2.2 0 1.21-.87 2.2-1.96 2.2Zm6.98 0c-1.08 0-1.96-.99-1.96-2.2 0-1.22.87-2.2 1.96-2.2 1.1 0 1.98.99 1.96 2.2 0 1.21-.87 2.2-1.96 2.2Z"/></svg>';
    }

    private function social_buttons($mode = 'login') {
        $verb = $mode === 'register' ? 'Continue' : 'Continue';
        ob_start();
        ?>
        <div class="tas-social-wrap" aria-label="Social sign in options">
            <a class="tas-social-btn tas-social-google" href="<?php echo $this->social_login_url('google'); ?>">
                <span class="tas-social-icon"><?php echo $this->social_icon('google'); ?></span>
                <span><?php echo esc_html($verb); ?> with Google</span>
            </a>
            <a class="tas-social-btn tas-social-discord" href="<?php echo $this->social_login_url('discord'); ?>">
                <span class="tas-social-icon"><?php echo $this->social_icon('discord'); ?></span>
                <span><?php echo esc_html($verb); ?> with Discord</span>
            </a>
        </div>
        <div class="tas-divider"><span>or use email</span></div>
        <?php
        return ob_get_clean();
    }

    private function is_oauth_callback_request() {
        $callback = $this->setting('oauth_callback_url');
        if (!$callback) {
            $callback = home_url('/auth-callback/');
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $request_path = '/' . trim((string) parse_url($request_uri, PHP_URL_PATH), '/') . '/';
        $callback_path = '/' . trim((string) parse_url($callback, PHP_URL_PATH), '/') . '/';
        return $request_path === $callback_path;
    }

    /**
     * Desktop OAuth bridge helpers.
     *
     * The Render auth API already redirects Google/Discord back to this website
     * callback. For a desktop-tool login, we must NOT exchange that code into a
     * WordPress website session. Instead, send the untouched one-time code back
     * to Electron's localhost callback (or its registered custom protocol).
     */
    private function is_tool_oauth_bridge_request() {
        return isset($_GET['ts_tool']) && (string) wp_unslash($_GET['ts_tool']) === '1';
    }

    private function tool_oauth_callback_target() {
        if (!isset($_GET['ts_app_callback'])) {
            return '';
        }

        $raw = trim((string) wp_unslash($_GET['ts_app_callback']));
        if (!$raw) {
            return '';
        }

        // Preferred development/production handoff: one-time localhost listener.
        $parts = wp_parse_url($raw);
        if (is_array($parts) && isset($parts['scheme']) && in_array(strtolower((string) $parts['scheme']), array('http', 'https'), true)) {
            $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
            $path = isset($parts['path']) ? (string) $parts['path'] : '';
            if (in_array($host, array('127.0.0.1', 'localhost'), true) && strpos($path, '/auth-callback/') === 0) {
                return esc_url_raw($raw);
            }
        }

        // Packaged-app fallback. Keep this exact protocol locked down.
        if (stripos($raw, 'tweakshift-classic://auth-callback') === 0) {
            return $raw;
        }

        return '';
    }

    private function append_tool_oauth_query($target, $args) {
        $separator = strpos($target, '?') === false ? '?' : '&';
        $pairs = array();
        foreach ($args as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }
        return $target . ($pairs ? $separator . implode('&', $pairs) : '');
    }

    private function render_tool_oauth_bridge($target, $success = true, $message = '') {
        nocache_headers();
        status_header(200);
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));

        $safe_target = esc_js($target);
        $safe_href = esc_attr($target);
        $title = $success ? 'TweakShift sign-in complete' : 'TweakShift sign-in could not be completed';
        $copy = $message ? $message : ($success ? 'Returning you to TweakShift Classic…' : 'Return to TweakShift and try again.');
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title><?php echo esc_html($title); ?></title>
            <style>
                html,body{margin:0;min-height:100%;background:#07090d;color:#f4f6f8;font-family:Inter,Arial,sans-serif}body{min-height:100vh;display:grid;place-items:center;padding:24px;box-sizing:border-box}.ts-tool-bridge{width:min(100%,520px);padding:34px;border:1px solid rgba(255,72,94,.18);border-radius:20px;background:linear-gradient(180deg,#0d1118,#090c11);box-shadow:0 28px 80px rgba(0,0,0,.42);text-align:center}.ts-tool-mark{width:54px;height:54px;margin:0 auto 18px;display:grid;place-items:center;border-radius:16px;background:rgba(255,48,75,.09);border:1px solid rgba(255,48,75,.20);color:#ff4059;font-size:24px}.ts-tool-bridge h1{margin:0 0 9px;font-size:24px;font-weight:650}.ts-tool-bridge p{margin:0;color:#9ba5b4;font-size:14px;line-height:1.6}.ts-tool-bridge a{height:42px;margin-top:22px;padding:0 18px;display:inline-flex;align-items:center;justify-content:center;border-radius:11px;border:1px solid rgba(255,68,91,.34);background:linear-gradient(180deg,#df203c,#ad102b);color:#fff;text-decoration:none;font-size:13px;font-weight:600}
            </style>
        </head>
        <body>
            <main class="ts-tool-bridge">
                <div class="ts-tool-mark">TS</div>
                <h1><?php echo esc_html($title); ?></h1>
                <p><?php echo esc_html($copy); ?></p>
                <a href="<?php echo $safe_href; ?>">Return to TweakShift Classic</a>
            </main>
            <script>
                (function(){
                    var target = '<?php echo $safe_target; ?>';
                    if (!target) return;
                    setTimeout(function(){ window.location.href = target; }, 120);
                })();
            </script>
        </body>
        </html>
        <?php
        exit;
    }

    public function maybe_handle_oauth_callback_redirect() {
        if (is_admin() || wp_doing_ajax() || !$this->is_oauth_callback_request()) {
            return;
        }

        $tool_bridge = $this->is_tool_oauth_bridge_request();
        $tool_target = $tool_bridge ? $this->tool_oauth_callback_target() : '';
        $provider = isset($_GET['ts_provider']) ? sanitize_key(wp_unslash($_GET['ts_provider'])) : 'social';

        if (isset($_GET['oauth']) && $_GET['oauth'] === 'failed') {
            $reason = isset($_GET['reason']) ? sanitize_text_field(wp_unslash($_GET['reason'])) : 'Social login failed. Please try again.';
            if ($tool_bridge && $tool_target) {
                $target = $this->append_tool_oauth_query($tool_target, array('error' => $reason, 'provider' => $provider));
                $this->render_tool_oauth_bridge($target, false, $reason);
            }
            wp_safe_redirect($this->redirect_url('login_url', array('tas_error' => rawurlencode($reason))));
            exit;
        }

        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        if (!$code) {
            return;
        }

        // DESKTOP TOOL FLOW: leave the one-time authorization code untouched.
        // Electron exchanges it through /api/tool/oauth-login and creates the
        // device-bound TweakShift Classic session. Do not create a website cookie.
        if ($tool_bridge && $tool_target) {
            $target = $this->append_tool_oauth_query($tool_target, array(
                'code' => $code,
                'provider' => $provider,
            ));
            $this->render_tool_oauth_bridge($target, true, 'Discord/Google authorization was accepted. Returning you to TweakShift Classic…');
        }

        // NORMAL WEBSITE FLOW remains unchanged.
        $result = $this->api_request('POST', '/api/auth/oauth/exchange', array('code' => $code));
        if (empty($result['success']) || empty($result['token'])) {
            $message = isset($result['message']) ? $result['message'] : 'Social login failed. Please try again.';
            wp_safe_redirect($this->redirect_url('login_url', array('tas_error' => rawurlencode($message))));
            exit;
        }

        $provider = isset($result['provider']) ? sanitize_key($result['provider']) : 'social';
        $this->set_auth_cookie($result['token']);
        wp_safe_redirect($this->redirect_url('dashboard_url', array('tas_msg' => rawurlencode(ucfirst($provider) . ' login successful. Purchases are checked automatically.'))));
        exit;
    }

    private function api_request($method, $endpoint, $body = array(), $token = '') {
        $api_url = untrailingslashit($this->setting('api_url'));
        if (!$api_url) {
            return array('success' => false, 'message' => 'Render API URL is missing in plugin settings.');
        }

        $url = $api_url . '/' . ltrim($endpoint, '/');
        $args = array(
            'method' => strtoupper($method),
            'timeout' => 25,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        );

        if ($token) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        if (strtoupper($method) !== 'GET') {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return array('success' => false, 'message' => 'Invalid API response from Render.');
        }

        if ($code < 200 || $code >= 300) {
            $message = isset($data['message']) ? $data['message'] : 'API request failed.';
            return array('success' => false, 'message' => $message, 'code' => $code, 'data' => $data);
        }

        return $data;
    }

    private function mark_dynamic_page() {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('DONOTCACHEOBJECT')) {
            define('DONOTCACHEOBJECT', true);
        }
        if (!headers_sent()) {
            nocache_headers();
        }
    }

    private function verify_frontend_nonce_or_redirect($action, $redirect_key) {
        $nonce = '';
        if (isset($_REQUEST['tas_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_REQUEST['tas_nonce']));
        }

        if (!$nonce || !wp_verify_nonce($nonce, $action)) {
            wp_safe_redirect($this->redirect_url($redirect_key, array(
                'tas_error' => rawurlencode('Security check failed. Please refresh the page and try again.')
            )));
            exit;
        }
    }

    private function is_allowed_external_checkout($url) {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        $host = strtolower($host);
        return $host === 'checkout.freemius.com' || $host === 'freemius.com' || substr($host, -13) === '.freemius.com';
    }

    private function notice() {
        $html = '';
        if (isset($_GET['tas_msg']) && $_GET['tas_msg'] !== '') {
            $html .= '<div class="tas-notice tas-notice-success">' . esc_html(wp_unslash($_GET['tas_msg'])) . '</div>';
        }
        if (isset($_GET['tas_error']) && $_GET['tas_error'] !== '') {
            $html .= '<div class="tas-notice tas-notice-error">' . esc_html(wp_unslash($_GET['tas_error'])) . '</div>';
        }
        return $html;
    }

    private function shell_open($mode = 'auth') {
        $logo = esc_url($this->setting('logo_url'));
        $mode_class = $mode === 'dashboard' ? 'tas-shell-dashboard' : 'tas-shell-auth';
        ob_start();
        ?>
        <section class="tas-root <?php echo esc_attr($mode_class); ?>">
            <div class="tas-bg-grid" aria-hidden="true"></div>
            <div class="tas-glow tas-glow-one" aria-hidden="true"></div>
            <div class="tas-glow tas-glow-two" aria-hidden="true"></div>
            <div class="tas-wrap">
                <div class="tas-brand">
                    <?php if ($logo) : ?>
                        <a href="<?php echo esc_url(home_url('/')); ?>" class="tas-logo-link" aria-label="TweakShift Home"><img src="<?php echo $logo; ?>" alt="TweakShift"></a>
                    <?php else : ?>
                        <a href="<?php echo esc_url(home_url('/')); ?>" class="tas-logo-text">TweakShift</a>
                    <?php endif; ?>
                </div>
        <?php
        return ob_get_clean();
    }

    private function shell_close() {
        return '</div></section>';
    }

    private function password_eye_icon() {
        return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2.7 12s3.4-6.2 9.3-6.2S21.3 12 21.3 12 17.9 18.2 12 18.2 2.7 12 2.7 12Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 14.7a2.7 2.7 0 1 0 0-5.4 2.7 2.7 0 0 0 0 5.4Z" stroke="currentColor" stroke-width="1.7"/><path class="tas-eye-slash" d="M4.5 4.5 19.5 19.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>';
    }

    private function password_field($label, $name, $autocomplete, $placeholder, $extra_attrs = '', $link_html = '') {
        $html  = '<label>' . esc_html($label) . $link_html;
        $html .= '<span class="tas-password-field">';
        $html .= '<input type="password" name="' . esc_attr($name) . '" autocomplete="' . esc_attr($autocomplete) . '" required ' . $extra_attrs . ' placeholder="' . esc_attr($placeholder) . '">';
        $html .= '<button type="button" class="tas-password-toggle" aria-label="Show password" aria-pressed="false">' . $this->password_eye_icon() . '</button>';
        $html .= '</span></label>';
        return $html;
    }


    public function ajax_header_status() {
        nocache_headers();

        $token = $this->token();
        if (!$token) {
            wp_send_json(array(
                'success' => true,
                'logged_in' => false,
                'login_url' => $this->setting('login_url'),
                'register_url' => $this->setting('register_url'),
            ));
        }

        $result = $this->api_request('GET', '/api/dashboard', array(), $token);
        if (empty($result['success'])) {
            // If Render says this token is invalid/expired, clear the stale auth cookie.
            $this->clear_auth_cookie();
            wp_send_json(array(
                'success' => true,
                'logged_in' => false,
                'login_url' => $this->setting('login_url'),
                'register_url' => $this->setting('register_url'),
            ));
        }

        $dashboard = isset($result['dashboard']) && is_array($result['dashboard']) ? $result['dashboard'] : array();
        $user = isset($dashboard['user']) && is_array($dashboard['user']) ? $dashboard['user'] : array();
        $name = isset($user['name']) ? $user['name'] : (isset($dashboard['name']) ? $dashboard['name'] : 'TweakShift User');
        $email = isset($user['email']) ? $user['email'] : (isset($dashboard['email']) ? $dashboard['email'] : '');

        wp_send_json(array(
            'success' => true,
            'logged_in' => true,
            'user' => array(
                'name' => $name,
                'email' => $email,
            ),
            'dashboard_url' => $this->setting('dashboard_url'),
            'logout_url' => wp_nonce_url(admin_url('admin-post.php?action=tas_logout'), 'tas_logout_action', 'tas_nonce'),
            'logout_ajax_url' => wp_nonce_url(admin_url('admin-ajax.php?action=tas_header_logout'), 'tas_header_logout_action', 'tas_nonce'),
        ));
    }

    public function ajax_header_logout() {
        // Header logout should never send the user to the login/dashboard pages.
        // It only clears the TweakShift auth cookie and lets the header UI update in-place.
        $nonce = '';
        if (isset($_REQUEST['tas_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_REQUEST['tas_nonce']));
        }
        // Nonce is accepted when present, but logout remains safe even if a cached header link has an old nonce.
        $this->clear_auth_cookie();
        nocache_headers();
        wp_send_json(array(
            'success' => true,
            'logged_in' => false,
            'message' => 'Logged out successfully.',
        ));
    }

    public function shortcode_login() {
        $this->mark_dynamic_page();
        if ($this->is_logged_in()) {
            return $this->shell_open() . '<div class="tas-card tas-card-small"><div class="tas-card-inner"><span class="tas-kicker">Already signed in</span><h1>You are already logged in.</h1><p class="tas-copy">Open your dashboard to manage your TweakShift account.</p><a class="tas-btn tas-btn-primary" href="' . esc_url($this->setting('dashboard_url')) . '">Open Dashboard</a></div></div>' . $this->shell_close();
        }

        ob_start();
        echo $this->shell_open();
        ?>
        <div class="tas-card tas-card-auth">
            <div class="tas-card-inner">
                <?php echo $this->notice(); ?>
                <span class="tas-kicker">TweakShift Account</span>
                <h1>Sign in to your account</h1>
                <p class="tas-copy">Access your dashboard, downloads, and synced licenses from one clean account.</p>
                <?php echo $this->social_buttons('login'); ?>
                <form class="tas-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('tas_login_action', 'tas_nonce'); ?>
                    <input type="hidden" name="action" value="tas_login">
                    <label>Email address<input type="email" name="email" autocomplete="email" required placeholder="you@example.com"></label>
                    <?php echo $this->password_field('Password', 'password', 'current-password', 'Enter your password', '', '<span class="tas-field-link"><a href="' . esc_url($this->setting('forgot_url')) . '">Forgot?</a></span>'); ?>
                    <button class="tas-btn tas-btn-primary" type="submit">Sign In</button>
                </form>
                <p class="tas-switch">New to TweakShift? <a href="<?php echo esc_url($this->setting('register_url')); ?>">Create an account</a></p>
            </div>
        </div>
        <?php
        echo $this->shell_close();
        return ob_get_clean();
    }

    public function shortcode_register() {
        $this->mark_dynamic_page();
        if ($this->is_logged_in()) {
            return $this->shell_open() . '<div class="tas-card tas-card-small"><div class="tas-card-inner"><span class="tas-kicker">Account active</span><h1>You are already signed in.</h1><p class="tas-copy">Open your dashboard to continue.</p><a class="tas-btn tas-btn-primary" href="' . esc_url($this->setting('dashboard_url')) . '">Open Dashboard</a></div></div>' . $this->shell_close();
        }

        ob_start();
        echo $this->shell_open();
        ?>
        <div class="tas-card tas-card-auth">
            <div class="tas-card-inner">
                <?php echo $this->notice(); ?>
                <span class="tas-kicker">Create Account</span>
                <h1>Start your TweakShift account</h1>
                <p class="tas-copy">Create one account now. Use the same email you used at checkout. Purchases will attach automatically to your account.</p>
                <?php echo $this->social_buttons('register'); ?>
                <form class="tas-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('tas_register_action', 'tas_nonce'); ?>
                    <input type="hidden" name="action" value="tas_register">
                    <label>Full name<input type="text" name="name" autocomplete="name" required placeholder="Your name"></label>
                    <label>Email address<input type="email" name="email" autocomplete="email" required placeholder="you@example.com"></label>
                    <?php echo $this->password_field('Password', 'password', 'new-password', 'Minimum 8 characters', 'minlength="8"'); ?>
                    <?php echo $this->password_field('Confirm password', 'confirm_password', 'new-password', 'Repeat your password', 'minlength="8"'); ?>
                    <button class="tas-btn tas-btn-primary" type="submit">Create Account</button>
                </form>
                <p class="tas-switch">Already have an account? <a href="<?php echo esc_url($this->setting('login_url')); ?>">Sign in</a></p>
            </div>
        </div>
        <?php
        echo $this->shell_close();
        return ob_get_clean();
    }

    public function shortcode_forgot_password() {
        $this->mark_dynamic_page();
        ob_start();
        echo $this->shell_open();
        ?>
        <div class="tas-card tas-card-auth">
            <div class="tas-card-inner">
                <?php echo $this->notice(); ?>
                <span class="tas-kicker">Password Reset</span>
                <h1>Reset your password</h1>
                <p class="tas-copy">Enter your account email. We’ll send a secure reset link if the account exists.</p>
                <form class="tas-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('tas_forgot_password_action', 'tas_nonce'); ?>
                    <input type="hidden" name="action" value="tas_forgot_password">
                    <label>Email address<input type="email" name="email" autocomplete="email" required placeholder="you@example.com"></label>
                    <button class="tas-btn tas-btn-primary" type="submit">Send Reset Link</button>
                </form>
                <p class="tas-switch">Remember your password? <a href="<?php echo esc_url($this->setting('login_url')); ?>">Back to sign in</a></p>
            </div>
        </div>
        <?php
        echo $this->shell_close();
        return ob_get_clean();
    }

    public function shortcode_reset_password() {
        $this->mark_dynamic_page();
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        ob_start();
        echo $this->shell_open();
        ?>
        <div class="tas-card tas-card-auth">
            <div class="tas-card-inner">
                <?php echo $this->notice(); ?>
                <span class="tas-kicker">Secure Reset</span>
                <h1>Create a new password</h1>
                <?php if (!$token) : ?>
                    <p class="tas-copy">The reset token is missing. Request a new password reset link.</p>
                    <a class="tas-btn tas-btn-primary" href="<?php echo esc_url($this->setting('forgot_url')); ?>">Request New Link</a>
                <?php else : ?>
                    <p class="tas-copy">Choose a strong new password for your TweakShift account.</p>
                    <form class="tas-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('tas_reset_password_action', 'tas_nonce'); ?>
                        <input type="hidden" name="action" value="tas_reset_password">
                        <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
                        <?php echo $this->password_field('New password', 'password', 'new-password', 'Minimum 8 characters', 'minlength="8"'); ?>
                        <?php echo $this->password_field('Confirm password', 'confirm_password', 'new-password', 'Repeat your new password', 'minlength="8"'); ?>
                        <button class="tas-btn tas-btn-primary" type="submit">Update Password</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
        echo $this->shell_close();
        return ob_get_clean();
    }

    private function product_key_options($selected = '') {
        $products = array(
            'tweakshift_classic' => $this->setting('classic_name'),
            'tweakshift_ai_engine' => $this->setting('ai_name'),
        );
        $html = '';
        foreach ($products as $key => $label) {
            $html .= '<option value="' . esc_attr($key) . '" ' . selected($selected, $key, false) . '>' . esc_html($label) . '</option>';
        }
        return $html;
    }

    private function status_label($status) {
        $status = sanitize_key($status ?: 'not_linked');
        if ($status === 'active') return 'Active';
        if ($status === 'inactive') return 'Inactive';
        if ($status === 'expired') return 'Expired';
        if ($status === 'cancelled') return 'Cancelled';
        return 'Not linked';
    }

    private function product_download_url($product_key) {
        return $product_key === 'tweakshift_classic' ? $this->setting('classic_download_url') : $this->setting('ai_download_url');
    }

    private function protected_download_url($product_key) {
        return wp_nonce_url(
            admin_url('admin-post.php?action=tas_download&product_key=' . rawurlencode($product_key)),
            'tas_download_action',
            'tas_nonce'
        );
    }

    public function shortcode_dashboard() {
        $this->mark_dynamic_page();
        $token = $this->token();
        if (!$token) {
            return $this->shell_open('dashboard') . '<div class="tas-dashboard-card tas-empty-state"><span class="tas-kicker">Login required</span><h1>Sign in to open your dashboard.</h1><p class="tas-copy">Your dashboard is protected. Sign in or create a new account to continue.</p><div class="tas-actions"><a class="tas-btn tas-btn-primary" href="' . esc_url($this->setting('login_url')) . '">Sign In</a><a class="tas-btn tas-btn-outline" href="' . esc_url($this->setting('register_url')) . '">Create Account</a></div></div>' . $this->shell_close();
        }

        $result = $this->api_request('GET', '/api/dashboard', array(), $token);
        if (empty($result['success'])) {
            $this->clear_auth_cookie();
            $message = isset($result['message']) ? $result['message'] : 'Your session expired. Please sign in again.';
            return $this->shell_open('dashboard') . '<div class="tas-dashboard-card tas-empty-state"><span class="tas-kicker">Session expired</span><h1>Please sign in again.</h1><p class="tas-copy">' . esc_html($message) . '</p><a class="tas-btn tas-btn-primary" href="' . esc_url($this->setting('login_url')) . '">Sign In</a></div>' . $this->shell_close();
        }

        $dashboard = isset($result['dashboard']) && is_array($result['dashboard']) ? $result['dashboard'] : array();
        $user = isset($dashboard['user']) && is_array($dashboard['user']) ? $dashboard['user'] : array();
        $name = isset($user['name']) ? $user['name'] : (isset($dashboard['name']) ? $dashboard['name'] : 'TweakShift User');
        $email = isset($user['email']) ? $user['email'] : (isset($dashboard['email']) ? $dashboard['email'] : '');
        $message = isset($dashboard['message']) ? $dashboard['message'] : 'Purchases made with this email are checked automatically. If you bought with another email, use Claim Existing License.';
        $products = isset($dashboard['products']) && is_array($dashboard['products']) ? $dashboard['products'] : array();
        $licenses = isset($dashboard['licenses']) && is_array($dashboard['licenses']) ? $dashboard['licenses'] : array();
        $sessions_result = $this->api_request('GET', '/api/sessions/my', array(), $token);
        $sessions = !empty($sessions_result['success']) && isset($sessions_result['sessions']) && is_array($sessions_result['sessions']) ? $sessions_result['sessions'] : array();
        $logout_url = wp_nonce_url(admin_url('admin-post.php?action=tas_logout'), 'tas_logout_action', 'tas_nonce');

        if (empty($products)) {
            $products = array(
                array('product_key' => 'tweakshift_classic', 'name' => $this->setting('classic_name'), 'status' => 'not_linked', 'buy_url' => $this->setting('classic_buy_url'), 'download_url' => $this->setting('classic_download_url')),
                array('product_key' => 'tweakshift_ai_engine', 'name' => $this->setting('ai_name'), 'status' => 'not_linked', 'buy_url' => $this->setting('ai_buy_url'), 'download_url' => $this->setting('ai_download_url')),
            );
        }

        ob_start();
        echo $this->shell_open('dashboard');
        ?>
        <div class="tas-dashboard tas-dashboard-advanced">
            <?php echo $this->notice(); ?>
            <div class="tas-dashboard-hero">
                <div>
                    <span class="tas-kicker">Account Dashboard</span>
                    <h1>Welcome, <?php echo esc_html($name); ?></h1>
                    <p class="tas-copy"><?php echo esc_html($message); ?></p>
                </div>
                <a class="tas-btn tas-btn-outline tas-logout-trigger" href="<?php echo esc_url($logout_url); ?>">Logout</a>
            </div>

            <div class="tas-dashboard-grid tas-dashboard-grid-three">
                <div class="tas-dashboard-card tas-account-card">
                    <span class="tas-card-label">Account</span>
                    <h2><?php echo esc_html($name); ?></h2>
                    <p><?php echo esc_html($email); ?></p>
                    <div class="tas-status-pill">Active Account</div>
                </div>
                <div class="tas-dashboard-card tas-sync-card">
                    <span class="tas-card-label">Licenses</span>
                    <h2><?php echo esc_html(count($licenses)); ?> Linked</h2>
                    <p>Purchases made with this email are checked automatically. Manual sync is no longer required.</p>
                </div>
                <div class="tas-dashboard-card tas-sync-card">
                    <span class="tas-card-label">Tool Login</span>
                    <h2>Ready Base</h2>
                    <p>Active sessions will appear here after the next tool update.</p>
                </div>
            </div>

            <div class="tas-products tas-products-advanced">
                <?php foreach ($products as $product) :
                    $product_key = isset($product['product_key']) ? $product['product_key'] : '';
                    $status = isset($product['status']) ? $product['status'] : 'not_linked';
                    $is_active = $status === 'active';
                    $download = !empty($product['download_url']) ? $product['download_url'] : $this->product_download_url($product_key);
                    $buy = !empty($product['buy_url']) ? $product['buy_url'] : ($product_key === 'tweakshift_classic' ? $this->setting('classic_buy_url') : $this->setting('ai_buy_url'));
                ?>
                    <div class="tas-product-card <?php echo $is_active ? 'is-active' : ''; ?>">
                        <div class="tas-product-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none"><path d="M12 2.8 19 5.6v5.6c0 4.7-2.8 8.1-7 10-4.2-1.9-7-5.3-7-10V5.6L12 2.8Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="m9.2 12 1.8 1.8 3.9-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </div>
                        <div class="tas-product-content">
                            <span class="tas-card-label">Product</span>
                            <h2><?php echo esc_html(isset($product['name']) ? $product['name'] : 'TweakShift Product'); ?></h2>
                            <p><?php echo $is_active ? esc_html('Plan: ' . (isset($product['plan']) && $product['plan'] ? $product['plan'] : 'Premium')) : 'No license attached yet.'; ?></p>
                            <div class="tas-status-pill <?php echo $is_active ? 'tas-status-active' : 'tas-status-muted'; ?>"><?php echo esc_html($this->status_label($status)); ?></div>
                            <div class="tas-product-actions">
                                <?php if ($is_active) : ?>
                                    <a class="tas-btn tas-btn-primary" href="<?php echo esc_url($this->protected_download_url($product_key)); ?>">Download</a>
                                    <a class="tas-btn tas-btn-outline" href="#tas-claim-license">Manage License</a>
                                <?php else : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tas-inline-form tas-buy-tool-form">
                                        <?php wp_nonce_field('tas_checkout_action', 'tas_nonce'); ?>
                                        <input type="hidden" name="action" value="tas_checkout">
                                        <input type="hidden" name="product_key" value="<?php echo esc_attr($product_key); ?>">
                                        <button class="tas-btn tas-btn-primary" type="submit">Buy This Tool</button>
                                    </form>
                                    <p class="tas-product-note">Your license will attach automatically to this account after checkout.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="tas-dashboard-grid tas-dashboard-grid-two">
                <div class="tas-dashboard-card tas-license-table-card">
                    <span class="tas-card-label">My Licenses</span>
                    <h2>Linked licenses</h2>
                    <?php if (empty($licenses)) : ?>
                        <p>No license is attached yet. Purchases made with this email are checked automatically. If you bought with another email, use the claim box.</p>
                    <?php else : ?>
                        <div class="tas-license-list">
                            <?php foreach ($licenses as $license) : ?>
                                <div class="tas-license-row">
                                    <div>
                                        <strong><?php echo esc_html(isset($license['product_name']) ? $license['product_name'] : 'TweakShift Product'); ?></strong>
                                        <span><?php echo esc_html(isset($license['plan_name']) ? $license['plan_name'] : 'Premium'); ?></span>
                                    </div>
                                    <div>
                                        <strong><?php echo esc_html(isset($license['license']) ? $license['license'] : 'Masked License'); ?></strong>
                                        <span><?php echo esc_html($this->status_label(isset($license['status']) ? $license['status'] : 'unknown')); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="tas-dashboard-card tas-claim-card" id="tas-claim-license">
                    <span class="tas-card-label">Claim Existing License</span>
                    <h2>Attach old license</h2>
                    <p>For existing users or different checkout emails: enter your Freemius license key and purchase email. It will attach after validation.</p>
                    <form class="tas-form tas-form-compact" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('tas_claim_license_action', 'tas_nonce'); ?>
                        <input type="hidden" name="action" value="tas_claim_license">
                        <label>Product<select name="product_key" required><?php echo $this->product_key_options(); ?></select></label>
                        <label>Purchase email<input type="email" name="purchase_email" value="<?php echo esc_attr($email); ?>" required placeholder="purchase@email.com"></label>
                        <label>License key<input type="text" name="license_key" required placeholder="XXXX-XXXX-XXXX-XXXX"></label>
                        <button class="tas-btn tas-btn-primary" type="submit">Claim License</button>
                    </form>
                </div>
            </div>

            <div class="tas-dashboard-grid tas-dashboard-grid-two">
                <div class="tas-dashboard-card tas-sync-card tas-auto-sync-card">
                    <span class="tas-card-label">Automatic Sync</span>
                    <h2>Purchase sync is automatic</h2>
                    <p>When a purchase is made with <strong><?php echo esc_html($email); ?></strong>, TweakShift checks Freemius automatically and attaches the license to this account. If you used a different checkout email, use the claim box above.</p>
                </div>

                <div class="tas-dashboard-card tas-sessions-card">
                    <span class="tas-card-label">Active Sessions</span>
                    <h2>Tool devices</h2>
                    <?php if (empty($sessions)) : ?>
                        <p>No active tool session yet. Device/session control will start when the tool login update goes live.</p>
                    <?php else : ?>
                        <div class="tas-session-list">
                            <?php foreach ($sessions as $session) : ?>
                                <div class="tas-session-row">
                                    <div>
                                        <strong><?php echo esc_html(isset($session['device_name']) ? $session['device_name'] : 'Unknown Device'); ?></strong>
                                        <span><?php echo esc_html(isset($session['product_name']) ? $session['product_name'] : 'TweakShift'); ?></span>
                                    </div>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('tas_logout_device_action', 'tas_nonce'); ?>
                                        <input type="hidden" name="action" value="tas_logout_device">
                                        <input type="hidden" name="session_id" value="<?php echo esc_attr(isset($session['id']) ? $session['id'] : ''); ?>">
                                        <button class="tas-link-button" type="submit">Logout</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        echo $this->shell_close();
        return ob_get_clean();
    }

    public function shortcode_purchase_success() {
        $this->mark_dynamic_page();
        $token = $this->token();
        $auto_message = '';
        $synced_count = 0;

        if ($token) {
            $sync_result = $this->api_request('POST', '/api/licenses/auto-sync', array('force' => true), $token);
            if (!empty($sync_result['success'])) {
                $synced_count = isset($sync_result['synced']) && is_array($sync_result['synced']) ? count($sync_result['synced']) : 0;
                $auto_message = $synced_count > 0
                    ? $synced_count . ' license(s) were attached to your account automatically.'
                    : 'We checked your account email automatically. If the license does not appear yet, open your dashboard in a few seconds or claim it with your license key.';
            } else {
                $auto_message = isset($sync_result['message']) ? $sync_result['message'] : 'Auto-sync could not complete yet. Open your dashboard and try again shortly.';
            }
        }

        ob_start();
        echo $this->shell_open('dashboard');
        ?>
        <div class="tas-dashboard-card tas-empty-state tas-purchase-success">
            <?php echo $this->notice(); ?>
            <span class="tas-kicker">Purchase Complete</span>
            <h1>Thanks for your purchase.</h1>
            <?php if ($token) : ?>
                <p class="tas-copy"><?php echo esc_html($auto_message ?: 'Your purchase is being checked automatically.'); ?></p>
                <div class="tas-actions">
                    <a class="tas-btn tas-btn-primary" href="<?php echo esc_url($this->setting('dashboard_url')); ?>">Open Dashboard</a>
                </div>
            <?php else : ?>
                <p class="tas-copy">Create an account or sign in using the same email you used at checkout. Your license will attach automatically.</p>
                <div class="tas-actions">
                    <a class="tas-btn tas-btn-primary" href="<?php echo esc_url($this->setting('register_url')); ?>">Create Account</a>
                    <a class="tas-btn tas-btn-outline" href="<?php echo esc_url($this->setting('login_url')); ?>">Sign In</a>
                </div>
                <div class="tas-purchase-social">
                    <?php echo $this->social_buttons('register'); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        echo $this->shell_close();
        return ob_get_clean();
    }

    public function shortcode_oauth_callback() {
        $this->mark_dynamic_page();
        ob_start();
        echo $this->shell_open();
        ?>
        <div class="tas-card tas-card-small">
            <div class="tas-card-inner">
                <?php echo $this->notice(); ?>
                <span class="tas-kicker">Social Login</span>
                <h1>Connecting your account…</h1>
                <p class="tas-copy">If you are not redirected automatically, open the login page and try again.</p>
                <div class="tas-actions">
                    <a class="tas-btn tas-btn-primary" href="<?php echo esc_url($this->setting('login_url')); ?>">Back to Sign In</a>
                </div>
            </div>
        </div>
        <?php
        echo $this->shell_close();
        return ob_get_clean();
    }

    public function handle_login() {
        $this->verify_frontend_nonce_or_redirect('tas_login_action', 'login_url');
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';

        if (!$email || !$password) {
            wp_safe_redirect($this->redirect_url('login_url', array('tas_error' => rawurlencode('Email and password are required.'))));
            exit;
        }

        $result = $this->api_request('POST', '/api/auth/login', array('email' => $email, 'password' => $password));
        if (empty($result['success']) || empty($result['token'])) {
            $message = isset($result['message']) ? $result['message'] : 'Login failed.';
            wp_safe_redirect($this->redirect_url('login_url', array('tas_error' => rawurlencode($message))));
            exit;
        }

        $this->set_auth_cookie($result['token']);
        wp_safe_redirect($this->redirect_url('dashboard_url', array('tas_msg' => rawurlencode('Login successful. Purchases are checked automatically.'))));
        exit;
    }

    public function handle_register() {
        $this->verify_frontend_nonce_or_redirect('tas_register_action', 'register_url');
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        $confirm = isset($_POST['confirm_password']) ? (string) wp_unslash($_POST['confirm_password']) : '';

        if (!$name || !$email || !$password) {
            wp_safe_redirect($this->redirect_url('register_url', array('tas_error' => rawurlencode('All fields are required.'))));
            exit;
        }

        if (strlen($password) < 8) {
            wp_safe_redirect($this->redirect_url('register_url', array('tas_error' => rawurlencode('Password must be at least 8 characters.'))));
            exit;
        }

        if ($password !== $confirm) {
            wp_safe_redirect($this->redirect_url('register_url', array('tas_error' => rawurlencode('Passwords do not match.'))));
            exit;
        }

        $result = $this->api_request('POST', '/api/auth/register', array('name' => $name, 'email' => $email, 'password' => $password));
        if (empty($result['success']) || empty($result['token'])) {
            $message = isset($result['message']) ? $result['message'] : 'Registration failed.';
            wp_safe_redirect($this->redirect_url('register_url', array('tas_error' => rawurlencode($message))));
            exit;
        }

        $this->set_auth_cookie($result['token']);
        wp_safe_redirect($this->redirect_url('dashboard_url', array('tas_msg' => rawurlencode('Account created successfully. Purchases are checked automatically.'))));
        exit;
    }

    public function handle_forgot_password() {
        $this->verify_frontend_nonce_or_redirect('tas_forgot_password_action', 'forgot_url');
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';

        if (!$email) {
            wp_safe_redirect($this->redirect_url('forgot_url', array('tas_error' => rawurlencode('Email address is required.'))));
            exit;
        }

        $result = $this->api_request('POST', '/api/auth/forgot-password', array('email' => $email, 'reset_url' => $this->setting('reset_url')));
        if (empty($result['success'])) {
            $message = isset($result['message']) ? $result['message'] : 'Could not send reset link.';
            wp_safe_redirect($this->redirect_url('forgot_url', array('tas_error' => rawurlencode($message))));
            exit;
        }

        $message = isset($result['message']) ? $result['message'] : 'Password reset link sent if the email exists.';
        wp_safe_redirect($this->redirect_url('forgot_url', array('tas_msg' => rawurlencode($message))));
        exit;
    }

    public function handle_reset_password() {
        $this->verify_frontend_nonce_or_redirect('tas_reset_password_action', 'reset_url');
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        $confirm = isset($_POST['confirm_password']) ? (string) wp_unslash($_POST['confirm_password']) : '';

        if (!$token || !$password) {
            wp_safe_redirect($this->redirect_url('forgot_url', array('tas_error' => rawurlencode('Reset token or password is missing.'))));
            exit;
        }

        if (strlen($password) < 8) {
            wp_safe_redirect($this->redirect_url('reset_url', array('token' => $token, 'tas_error' => rawurlencode('Password must be at least 8 characters.'))));
            exit;
        }

        if ($password !== $confirm) {
            wp_safe_redirect($this->redirect_url('reset_url', array('token' => $token, 'tas_error' => rawurlencode('Passwords do not match.'))));
            exit;
        }

        $result = $this->api_request('POST', '/api/auth/reset-password', array('token' => $token, 'password' => $password));
        if (empty($result['success'])) {
            $message = isset($result['message']) ? $result['message'] : 'Password reset failed.';
            wp_safe_redirect($this->redirect_url('reset_url', array('token' => $token, 'tas_error' => rawurlencode($message))));
            exit;
        }

        wp_safe_redirect($this->redirect_url('login_url', array('tas_msg' => rawurlencode('Password updated successfully. Please sign in.'))));
        exit;
    }

    public function handle_claim_license() {
        $this->verify_frontend_nonce_or_redirect('tas_claim_license_action', 'dashboard_url');
        $token = $this->token();
        if (!$token) {
            wp_safe_redirect($this->redirect_url('login_url', array('tas_error' => rawurlencode('Please sign in first.'))));
            exit;
        }
        $product_key = isset($_POST['product_key']) ? sanitize_text_field(wp_unslash($_POST['product_key'])) : '';
        $purchase_email = isset($_POST['purchase_email']) ? sanitize_email(wp_unslash($_POST['purchase_email'])) : '';
        $license_key = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
        if (!$product_key || !$purchase_email || !$license_key) {
            wp_safe_redirect($this->redirect_url('dashboard_url', array('tas_error' => rawurlencode('Product, purchase email, and license key are required.'))));
            exit;
        }
        $result = $this->api_request('POST', '/api/licenses/claim', array(
            'product_key' => $product_key,
            'purchase_email' => $purchase_email,
            'license_key' => $license_key,
        ), $token);
        $message = isset($result['message']) ? $result['message'] : (empty($result['success']) ? 'License claim failed.' : 'License attached successfully.');
        wp_safe_redirect($this->redirect_url('dashboard_url', empty($result['success']) ? array('tas_error' => rawurlencode($message)) : array('tas_msg' => rawurlencode($message))));
        exit;
    }

    public function handle_sync_purchase() {
        $this->verify_frontend_nonce_or_redirect('tas_sync_purchase_action', 'dashboard_url');
        $token = $this->token();
        if (!$token) {
            wp_safe_redirect($this->redirect_url('login_url', array('tas_error' => rawurlencode('Please sign in first.'))));
            exit;
        }
        $result = $this->api_request('POST', '/api/licenses/sync', array(), $token);
        $message = isset($result['message']) ? $result['message'] : (empty($result['success']) ? 'Sync failed.' : 'Purchase sync completed.');
        wp_safe_redirect($this->redirect_url('dashboard_url', empty($result['success']) ? array('tas_error' => rawurlencode($message)) : array('tas_msg' => rawurlencode($message))));
        exit;
    }

    public function handle_logout_device() {
        $this->verify_frontend_nonce_or_redirect('tas_logout_device_action', 'dashboard_url');
        $token = $this->token();
        if (!$token) {
            wp_safe_redirect($this->redirect_url('login_url', array('tas_error' => rawurlencode('Please sign in first.'))));
            exit;
        }
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        $result = $this->api_request('POST', '/api/sessions/logout-device', array('session_id' => $session_id), $token);
        $message = isset($result['message']) ? $result['message'] : (empty($result['success']) ? 'Could not logout device.' : 'Device logged out.');
        wp_safe_redirect($this->redirect_url('dashboard_url', empty($result['success']) ? array('tas_error' => rawurlencode($message)) : array('tas_msg' => rawurlencode($message))));
        exit;
    }

    public function handle_download() {
        $this->verify_frontend_nonce_or_redirect('tas_download_action', 'dashboard_url');
        $token = $this->token();
        if (!$token) {
            wp_safe_redirect($this->redirect_url('login_url', array('tas_error' => rawurlencode('Please sign in to access your download.'))));
            exit;
        }

        $product_key = isset($_GET['product_key']) ? sanitize_key(wp_unslash($_GET['product_key'])) : '';
        if (!in_array($product_key, array('tweakshift_classic', 'tweakshift_ai_engine'), true)) {
            wp_safe_redirect($this->redirect_url('dashboard_url', array('tas_error' => rawurlencode('Invalid product selected.'))));
            exit;
        }

        $result = $this->api_request('GET', '/api/dashboard', array(), $token);
        if (empty($result['success']) || empty($result['dashboard']) || !is_array($result['dashboard'])) {
            wp_safe_redirect($this->redirect_url('dashboard_url', array('tas_error' => rawurlencode('Could not verify your license. Please try again.'))));
            exit;
        }

        $products = isset($result['dashboard']['products']) && is_array($result['dashboard']['products']) ? $result['dashboard']['products'] : array();
        $allowed = false;
        foreach ($products as $product) {
            $key = isset($product['product_key']) ? sanitize_key($product['product_key']) : '';
            $status = isset($product['status']) ? sanitize_key($product['status']) : 'not_linked';
            if ($key === $product_key && $status === 'active') {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            wp_safe_redirect($this->redirect_url('dashboard_url', array('tas_error' => rawurlencode('This download is locked. Please purchase or claim a valid license first.'))));
            exit;
        }

        $download_url = esc_url_raw($this->product_download_url($product_key));
        if (!$download_url) {
            wp_safe_redirect($this->redirect_url('dashboard_url', array('tas_error' => rawurlencode('Download link is not configured yet.'))));
            exit;
        }

        wp_safe_redirect($download_url, 302);
        exit;
    }

    public function handle_checkout() {
        $this->verify_frontend_nonce_or_redirect('tas_checkout_action', 'dashboard_url');
        $token = $this->token();
        if (!$token) {
            wp_safe_redirect($this->redirect_url('login_url', array('tas_error' => rawurlencode('Please sign in before buying with your account.'))));
            exit;
        }
        $product_key = isset($_POST['product_key']) ? sanitize_text_field(wp_unslash($_POST['product_key'])) : '';
        $result = $this->api_request('POST', '/api/billing/create-checkout-session', array(
            'product_key' => $product_key,
            'success_url' => $this->setting('purchase_success_url'),
            'cancel_url' => home_url('/#tsx-pricing'),
        ), $token);
        if (empty($result['success']) || empty($result['checkout_url'])) {
            $message = isset($result['message']) ? $result['message'] : 'Could not create checkout session.';
            wp_safe_redirect($this->redirect_url('dashboard_url', array('tas_error' => rawurlencode($message))));
            exit;
        }
        $checkout_url = esc_url_raw($result['checkout_url']);
        if (!$checkout_url || !$this->is_allowed_external_checkout($checkout_url)) {
            wp_safe_redirect($this->redirect_url('dashboard_url', array('tas_error' => rawurlencode('Checkout URL is invalid. Please contact support or try again in a moment.'))));
            exit;
        }
        wp_redirect($checkout_url, 302);
        exit;
    }

    public function handle_logout() {
        // Logout is intentionally forgiving because header HTML can be cached.
        // Even if the nonce is old, the only action is clearing this user's auth cookie.
        $this->clear_auth_cookie();
        nocache_headers();

        $redirect = $this->safe_same_site_redirect(home_url('/'));
        if (strpos($redirect, '/login') !== false || strpos($redirect, '/dashboard') !== false) {
            $redirect = home_url('/');
        }
        $redirect = add_query_arg(array('tas_msg' => rawurlencode('You have been logged out.')), $redirect);

        wp_safe_redirect($redirect);
        exit;
    }
}

register_activation_hook(__FILE__, array('TweakShift_Account_System', 'activate'));
TweakShift_Account_System::instance();
