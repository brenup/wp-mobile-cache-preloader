<?php
/**
 * Plugin Name: WP Rocket Mobile Cache Preloader
 * Description: Crawls sitemap URLs with a mobile user-agent to trigger mobile cache creation. Runs up to three times daily at admin-defined times via WP-Cron or system cron.
 * Version: 2.0
 * Author: ICSD
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) exit;

define('MCP_USER_AGENT', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile');
define('MCP_SECRET_KEY', 'replace_with_your_secret_key');

// Explicitly define the log file
define('MCP_LOG_FILE', WP_CONTENT_DIR . '/debug.log');

function mcp_get_sitemaps(): array {
    return get_option('mcp_sitemaps', []);
}
function mcp_get_run_times(): array {
    return get_option('mcp_run_times', ['06:00', '18:00']);
}
function mcp_get_fallback_time(): string {
    return get_option('mcp_fallback_time', '23:59');
}
function mcp_fallback_enabled(): bool {
    return get_option('mcp_fallback_enabled', '0') === '1';
}

add_filter('cron_schedules', function ($schedules) {
    $schedules['every_minute'] = [
        'interval' => 60,
        'display'  => 'Every Minute'
    ];
    return $schedules;
});

register_activation_hook(__FILE__, 'mcp_schedule_crons');
function mcp_schedule_crons(): void {
    mcp_clear_crons();

    foreach (mcp_get_run_times() as $key => $time) {
        $hook = "mcp_run_mobile_cache_crawler_$key";
        $timestamp = mcp_next_timestamp($time);
        wp_schedule_event($timestamp, 'daily', $hook);
    }

    if (mcp_fallback_enabled()) {
        $timestamp = mcp_today_timestamp(mcp_get_fallback_time());
        error_log("[MCP] Scheduling fallback run for today at " . date('Y-m-d H:i:s', $timestamp));
        wp_schedule_single_event($timestamp, 'mcp_run_mobile_cache_crawler_2');
    }
}

register_deactivation_hook(__FILE__, 'mcp_clear_crons');
function mcp_clear_crons(): void {
    foreach (['0', '1', '2'] as $key) {
        wp_clear_scheduled_hook("mcp_run_mobile_cache_crawler_$key");
    }
}

function mcp_next_timestamp(string $hhmm): int {
    [$hour, $minute] = explode(':', $hhmm);
    $now = current_time('timestamp');
    $next = mktime((int)$hour, (int)$minute, 0, date('n', $now), date('j', $now), date('Y', $now));
    return ($next <= $now) ? strtotime('+1 day', $next) : $next;
}

function mcp_today_timestamp(string $hhmm): int {
    [$hour, $minute] = explode(':', $hhmm);
    return mktime((int)$hour, (int)$minute, 0, date('n'), date('j'), date('Y'));
}

foreach (['0', '1', '2'] as $key) {
    add_action("mcp_run_mobile_cache_crawler_$key", function () use ($key) {
        mcp_execute_crawler(false, $key);
    });
}

function mcp_execute_crawler(bool $echo = false, string $hook_id = 'manual'): void {
    $start = microtime(true);
    $sitemaps = mcp_get_sitemaps();
    $notify = get_option('mcp_notify_on_start', '0') === '1';
    $admin_email = get_option('admin_email');

    if (empty($sitemaps)) {
        error_log("[MCP][$hook_id] No sitemaps found. Skipping crawl.");
        if ($notify) {
            wp_mail($admin_email, 'Mobile Cache Preload Failed to Start', "[$hook_id] No sitemap URLs are defined.");
        }
        return;
    }

    if ($notify) {
        wp_mail($admin_email, 'Mobile Cache Preload Started', "[$hook_id] The crawl started at " . date('Y-m-d H:i:s') . ".");
    }

    error_log("[MCP][$hook_id] Starting crawl at " . date('Y-m-d H:i:s'));
    $delay = intval(get_option('mcp_delay', 1));
    $success_count = 0;
    $fail_count = 0;

    foreach ($sitemaps as $sitemap_url) {
        $sitemap = wp_remote_get($sitemap_url, ['timeout' => 15]);
        if (is_wp_error($sitemap)) {
            error_log("[MCP][$hook_id] Failed to load sitemap: $sitemap_url");
            $fail_count++;
            continue;
        }

        $xml = simplexml_load_string(wp_remote_retrieve_body($sitemap));
        if (!$xml) {
            error_log("[MCP][$hook_id] Invalid XML: $sitemap_url");
            $fail_count++;
            continue;
        }

        foreach ($xml->url as $url_entry) {
            $url = (string)$url_entry->loc;
            mcp_fetch_as_mobile($url, $hook_id);
            $success_count++;
            sleep($delay);
        }
    }

    $duration = round(microtime(true) - $start, 2);
    $summary = "[MCP][$hook_id] Run complete at " . date('Y-m-d H:i:s') . "\nDuration: {$duration}s\nFetched: $success_count\nErrors: $fail_count";
    error_log($summary);

    wp_mail($admin_email, 'Mobile Cache Preload Completed', $summary);
}

function mcp_fetch_as_mobile(string $url, string $hook_id = 'manual'): void {
    $response = wp_remote_get($url, [
        'headers' => ['User-Agent' => MCP_USER_AGENT],
        'timeout' => 15
    ]);
    $status = is_wp_error($response)
        ? '[ERROR] ' . $response->get_error_message()
        : 'Fetched (Status: ' . wp_remote_retrieve_response_code($response) . ')';
    error_log("[MCP][$hook_id] $url - $status");
}

add_action('init', function () {
    if (isset($_GET['mcp_cron']) && $_GET['mcp_cron'] === MCP_SECRET_KEY) {
        if (!defined('DOING_CRON')) define('DOING_CRON', true);
        mcp_execute_crawler(false, 'secret');
        exit('MCP executed.');
    }

    // Log download handler
    if (current_user_can('manage_options') && isset($_GET['mcp_download_log'])) {
        if (file_exists(MCP_LOG_FILE) && is_readable(MCP_LOG_FILE)) {
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="mcp_debug.log"');
            readfile(MCP_LOG_FILE);
            exit;
        } else {
            wp_die('Log file not found or not readable.');
        }
    }

    // Clear log handler
    if (current_user_can('manage_options') && isset($_POST['mcp_clear_log']) && check_admin_referer('mcp_clear_log')) {
        if (file_exists(MCP_LOG_FILE) && is_writable(MCP_LOG_FILE)) {
            file_put_contents(MCP_LOG_FILE, '');
        }
    }
});

add_action('admin_menu', function () {
    add_menu_page('Mobile Cache Settings', 'Mobile Cache', 'manage_options', 'mcp-settings', 'mcp_settings_page');
});

function mcp_settings_page(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['mcp_clear_log'])) {
        check_admin_referer('mcp_save_sitemaps');

        $sitemaps = array_filter(array_map('esc_url_raw', explode("\n", $_POST['mcp_sitemaps'] ?? '')));
        update_option('mcp_sitemaps', $sitemaps);

        $delay = max(0, min(10, intval($_POST['mcp_delay'] ?? 1)));
        update_option('mcp_delay', $delay);

        $notify = isset($_POST['mcp_notify_on_start']) ? '1' : '0';
        update_option('mcp_notify_on_start', $notify);

        $times = [
            sanitize_text_field($_POST['mcp_time_1'] ?? '06:00'),
            sanitize_text_field($_POST['mcp_time_2'] ?? '18:00')
        ];
        update_option('mcp_run_times', $times);

        $fallback_time = sanitize_text_field($_POST['mcp_fallback_time'] ?? '23:59');
        update_option('mcp_fallback_time', $fallback_time);

        $fallback_enabled = isset($_POST['mcp_fallback_enabled']) ? '1' : '0';
        update_option('mcp_fallback_enabled', $fallback_enabled);

        mcp_schedule_crons();
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $sitemaps = implode("\n", mcp_get_sitemaps());
    $delay = get_option('mcp_delay', 1);
    $notify_on_start = get_option('mcp_notify_on_start', '0') === '1';
    [$time1, $time2] = mcp_get_run_times();
    $fallback_time = mcp_get_fallback_time();
    $fallback_enabled = mcp_fallback_enabled();

    $cron_url = esc_url(site_url('?mcp_cron=' . MCP_SECRET_KEY));
    $server_time = date('Y-m-d H:i:s T');
    $next_run_1 = date('Y-m-d H:i:s T', mcp_next_timestamp($time1));
    $next_run_2 = date('Y-m-d H:i:s T', mcp_next_timestamp($time2));
    $next_fallback = $fallback_enabled ? date('Y-m-d H:i:s T', mcp_today_timestamp($fallback_time)) : 'Disabled';

    $log_contents = 'Log file not found or not readable.';
    if (file_exists(MCP_LOG_FILE) && is_readable(MCP_LOG_FILE)) {
        $lines = array_reverse(file(MCP_LOG_FILE));
        $mcp_lines = array_filter($lines, fn($line) => str_contains($line, '[MCP]'));
        $log_contents = implode("", array_slice($mcp_lines, 0, 30));
    }

    ?>
    <div class="wrap">
        <h1>Mobile Cache Preloader Settings</h1>
        <form method="post">
            <?php wp_nonce_field('mcp_save_sitemaps'); ?>

            <p><strong>🕒 Current Server Time:</strong> <?php echo esc_html($server_time); ?></p>
            <p><strong>📅 Next Scheduled Run 1:</strong> <?php echo esc_html($next_run_1); ?></p>
            <p><strong>📅 Next Scheduled Run 2:</strong> <?php echo esc_html($next_run_2); ?></p>
            <p><strong>📅 Next Fallback Run:</strong> <?php echo esc_html($next_fallback); ?></p>

            <p><strong>Enter sitemap URLs (one per line):</strong></p>
            <textarea name="mcp_sitemaps" rows="10" style="width:100%;"><?php echo esc_textarea($sitemaps); ?></textarea>

            <p><strong>Request Delay (seconds):</strong><br>
                <input type="number" name="mcp_delay" value="<?php echo esc_attr($delay); ?>" min="0" max="10" /></p>

            <p><label>
                <input type="checkbox" name="mcp_notify_on_start" value="1" <?php checked($notify_on_start); ?> />
                Email me when the crawl starts and if it fails to start
            </label></p>

            <p><strong>Cron Run Time #1 (HH:MM):</strong><br>
                <input type="time" name="mcp_time_1" value="<?php echo esc_attr($time1); ?>" /></p>

            <p><strong>Cron Run Time #2 (HH:MM):</strong><br>
                <input type="time" name="mcp_time_2" value="<?php echo esc_attr($time2); ?>" /></p>

            <p><label>
                <input type="checkbox" name="mcp_fallback_enabled" value="1" <?php checked($fallback_enabled); ?> />
                Enable fallback run
            </label></p>

            <p><strong>Fallback Run Time (HH:MM):</strong><br>
                <input type="time" name="mcp_fallback_time" value="<?php echo esc_attr($fallback_time); ?>" /></p>

            <p><input type="submit" class="button button-primary" value="Save Settings"></p>
        </form>

        <h2>Cron Job Info</h2>
        <p><strong>WP-Cron:</strong> Runs at your selected times daily.</p>
        <p><strong>System Cron:</strong> Use this to trigger plugin from server cron:</p>
        <code>wget -q -O - <?php echo $cron_url; ?> >/dev/null 2>&1</code>

        <h2>Recent MCP Log Output</h2>
        <details><summary>Click to view latest log entries (last 30 lines)</summary>
            <pre style="background:#fff; padding:1em; max-height:400px; overflow:auto;"><?php echo esc_html($log_contents); ?></pre>
        </details>

        <form method="post" style="margin-top:1em;">
            <?php wp_nonce_field('mcp_clear_log'); ?>
            <p>
                <input type="submit" name="mcp_clear_log" value="Clear MCP Log" class="button button-secondary" />
                <a href="<?php echo esc_url(add_query_arg('mcp_download_log', '1')); ?>" class="button">Download MCP Log</a>
            </p>
        </form>
    </div>
    <?php
}

