<?php

defined('ABSPATH') || exit;

class WBPI_Admin
{
    public function __construct()
    {

        add_action('admin_menu', [$this, 'register_log_page']);

        add_action('admin_bar_menu', [$this, 'admin_bar_log_notifier'], 100);

        add_action('admin_init', [$this, 'handle_clear_logs']);

        add_action(
            'admin_bar_menu',
            [$this, 'render_import_progress_bar'],
            999
        );

        add_action(
            'admin_enqueue_scripts',
            [$this, 'enqueue_admin_bar_assets']
        );

        add_action(
            'wp_ajax_wbpi_get_live_import_stats',
            [$this, 'ajax_live_import_stats']
        );
    }

    public function enqueue_admin_bar_assets()
    {

        wp_enqueue_style(
            'wbpi-admin-bar',
            WBPI_URL . 'assets/css/admin-bar.css',
            [],
            time()
        );

        wp_enqueue_script(
            'wbpi-admin-bar',
            WBPI_URL . 'assets/js/admin-bar.js',
            ['jquery'],
            time(),
            true
        );

        wp_localize_script(
            'wbpi-admin-bar',
            'wbpi_admin_bar',
            [
                'ajax_url' => admin_url(
                    'admin-ajax.php'
                ),
            ]
        );
    }

    public function ajax_live_import_stats()
    {

        global $wpdb;

        $row = $wpdb->get_row("
        SELECT option_name, option_value
        FROM {$wpdb->options}
        WHERE option_name LIKE 'wbpi_import_stats_%'
        ORDER BY option_id DESC
        LIMIT 1
    ");

        if (! $row) {

            wp_send_json_error();
        }

        $stats = maybe_unserialize(
            $row->option_value
        );

        if (empty($stats['total'])) {

            wp_send_json_error();
        }

        $total = (int) $stats['total'];

        $processed = (int) $stats['processed'];

        $failed = (int) $stats['failed'];

        $completed = ! empty($stats['completed']);

        /*
    |--------------------------------------------------------------------------
    | Hide completed after 20 sec
    |--------------------------------------------------------------------------
    */

        if (
            $completed &&
            ! empty($stats['completed_at'])
        ) {

            if (
                (time() - $stats['completed_at']) > 10
            ) {

                delete_option(
                    $row->option_name
                );

                wp_send_json_success([
                    'completed' => true,
                    'hide' => true,
                ]);
            }
        }

        $percent = min(
            100,
            round(
                ($processed / $total) * 100
            )
        );

        $elapsed = $completed
            ? (
                (int) $stats['completed_at'] -
                (int) $stats['started_at']
            )
            : (
                time() -
                (int) $stats['started_at']
            );

        $remaining = max(
            0,
            $total - $processed
        );

        $eta = (
            ! $completed &&
            $processed > 0
        )
            ? round(
                ($elapsed / $processed) *
                    $remaining
            )
            : 0;

        $status = $completed
            ? 'Completed'
            : 'Uploading';

        if ($completed) {

            $final_time = ! empty($stats['final_time_taken'])
                ? $stats['final_time_taken']
                : gmdate('i:s', $elapsed);

            $time_text = '';
        } else {

            $time_text = $processed > 0
                ? 'ETA ' . gmdate('i:s', $eta)
                : 'Preparing...';
        }

        ob_start();

?>

        <div class="wbpi-loader-wrap <?php echo $completed ? 'completed' : 'processing'; ?>">

            <div class="wbpi-loader-top">

                <span class="wbpi-loader-status">
                    <?php echo esc_html($status); ?>
                </span>

                <span class="wbpi-loader-percent">
                    <?php echo esc_html($percent); ?>%
                </span>

            </div>

            <div class="wbpi-loader-bar">

                <div
                    class="wbpi-loader-progress"
                    style="width:<?php echo esc_attr($percent); ?>%;"></div>

            </div>

            <div class="wbpi-loader-text">

                <span>
                    <?php echo esc_html(
                        $processed . '/' . $total . ' Products'
                    ); ?>
                </span>

                <span>
                    <?php echo esc_html(
                        $time_text
                    ); ?>
                </span>

            </div>

            <?php if ($failed > 0) : ?>

                <div class="wbpi-loader-failed">

                    Failed:
                    <?php echo esc_html($failed); ?>

                </div>

            <?php endif; ?>

        </div>

    <?php

        wp_send_json_success([
            'html' => ob_get_clean(),
            'processed' => $processed,
            'completed' => $completed,
        ]);
    }

    public function render_import_progress_bar(
        $admin_bar
    ) {

        global $wpdb;

        $row = $wpdb->get_row("
        SELECT option_name, option_value
        FROM {$wpdb->options}
        WHERE option_name LIKE 'wbpi_import_stats_%'
        ORDER BY option_id DESC
        LIMIT 1
    ");

        if (! $row) {
            return;
        }

        $stats = maybe_unserialize(
            $row->option_value
        );

        if (empty($stats['total'])) {
            return;
        }

        $total = (int) $stats['total'];

        $processed = (int) $stats['processed'];

        $failed = (int) $stats['failed'];

        $completed = ! empty($stats['completed']);

        $percent = min(
            100,
            round(
                ($processed / $total) * 100
            )
        );

        $remaining = max(
            0,
            $total - $processed
        );

        $elapsed = time() -
            (int) $stats['started_at'];

        $eta = $processed > 0
            ? round(
                ($elapsed / $processed) *
                    $remaining
            )
            : 0;

        /*
    |--------------------------------------------------------------------------
    | Completed State
    |--------------------------------------------------------------------------
    */

        $status_class = $completed
            ? 'completed'
            : 'processing';

        $status_text = $completed
            ? 'Completed'
            : 'Uploading';

        $time_taken = gmdate(
            'i:s',
            $elapsed
        );

        $eta_text = $completed
            ? ''
            : 'ETA ' . gmdate('i:s', $eta);

        /*
    |--------------------------------------------------------------------------
    | Title
    |--------------------------------------------------------------------------
    */

        $title = '
    <div class="wbpi-loader-wrap ' .
            $status_class .
            '">

        <div class="wbpi-loader-top">

            <span class="wbpi-loader-status">
                ' . $status_text . '
            </span>

            <span class="wbpi-loader-percent">
                ' . $percent . '%
            </span>

        </div>

        <div class="wbpi-loader-bar">
            <span style="width:' .
            $percent .
            '%;"></span>
        </div>

        <div class="wbpi-loader-text">

            <span>
                ' .
            $processed .
            '/' .
            $total .
            ' Products
            </span>

            <span>
                ' . $eta_text . '
            </span>

        </div>

        ' .

            (
                $failed > 0
                ? '<div class="wbpi-loader-failed">
                Failed: ' .
                $failed .
                '</div>'
                : ''
            )

            . '

    </div>';

        $admin_bar->add_node([
            'id' => 'wbpi-import-progress',
            'title' => $title,
            'href' => admin_url(
                'admin.php?page=wbpi-logs'
            ),
            'meta' => [
                'class' => 'wbpi-adminbar-live'
            ]
        ]);
    }

    public function register_log_page()
    {

        add_submenu_page(
            'woocommerce',
            'WBPI Logs',
            'WBPI Logs',
            'manage_woocommerce',
            'wbpi-logs',
            [$this, 'render_log_page']
        );
    }

    public function get_log_file_path()
    {

        if (! is_dir(WBPI_PATH . 'logs')) {
            wp_mkdir_p(WBPI_PATH . 'logs');
        }

        return WBPI_PATH . 'logs/import-errors.log';
    }

    public function handle_clear_logs()
    {

        if (
            ! isset($_GET['wbpi_clear_logs']) ||
            ! current_user_can('manage_woocommerce')
        ) {
            return;
        }

        check_admin_referer('wbpi_clear_logs');

        file_put_contents($this->get_log_file_path(), '');

        wp_safe_redirect(
            admin_url('admin.php?page=wbpi-logs&cleared=1')
        );

        exit;
    }

    public function get_log_stats()
    {

        $file = $this->get_log_file_path();

        if (! file_exists($file)) {
            return [
                'total'   => 0,
                'fatal'   => 0,
                'warning' => 0,
                'info'    => 0,
            ];
        }

        $content = file_get_contents($file);

        return [
            'total'   => substr_count($content, '['),
            'fatal'   => preg_match_all('/fatal/i', $content),
            'warning' => preg_match_all('/warning/i', $content),
            'info'    => preg_match_all('/info/i', $content),
        ];
    }

    public function admin_bar_log_notifier($admin_bar)
    {

        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $stats = $this->get_log_stats();

        if (empty($stats['total'])) {
            return;
        }

        $color = '#ffb900';

        if ($stats['fatal'] > 0) {
            $color = '#dc3232';
        }

        $title = sprintf(
            '<span style="color:%s;font-weight:700;">WBPI Logs (%d)</span>',
            esc_attr($color),
            (int) $stats['total']
        );

        $admin_bar->add_node([
            'id'    => 'wbpi-logs',
            'title' => $title,
            'href'  => admin_url('admin.php?page=wbpi-logs'),
        ]);
    }

    public function render_log_page()
    {

        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $file = $this->get_log_file_path();

        $logs = [];

        if (file_exists($file)) {

            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            $logs = array_reverse($lines);
        }

        $stats = $this->get_log_stats();

    ?>

        <div class="wrap">

            <h1 style="margin-bottom:20px;">
                WBPI Import Logs
            </h1>

            <div style="display:flex;gap:15px;margin-bottom:20px;">

                <div style="background:#fff;padding:15px 20px;border-left:4px solid #2271b1;">
                    <strong>Total:</strong>
                    <?php echo (int) $stats['total']; ?>
                </div>

                <div style="background:#fff;padding:15px 20px;border-left:4px solid #dc3232;">
                    <strong>Fatal:</strong>
                    <?php echo (int) $stats['fatal']; ?>
                </div>

                <div style="background:#fff;padding:15px 20px;border-left:4px solid #ffb900;">
                    <strong>Warning:</strong>
                    <?php echo (int) $stats['warning']; ?>
                </div>

                <div style="background:#fff;padding:15px 20px;border-left:4px solid #46b450;">
                    <strong>Info:</strong>
                    <?php echo (int) $stats['info']; ?>
                </div>

            </div>

            <p>

                <a
                    href="<?php echo esc_url(
                                wp_nonce_url(
                                    admin_url('admin.php?page=wbpi-logs&wbpi_clear_logs=1'),
                                    'wbpi_clear_logs'
                                )
                            ); ?>"
                    class="button button-secondary"
                    onclick="return confirm('Clear all logs?');">
                    Clear Logs
                </a>

            </p>

            <div style="
            background:#111;
            padding:20px;
            border-radius:8px;
            max-height:700px;
            overflow:auto;
            font-family:monospace;
        ">

                <?php if (empty($logs)) : ?>

                    <div style="color:#46b450;">
                        No logs found.
                    </div>

                <?php else : ?>

                    <?php foreach ($logs as $log) : ?>

                        <?php

                        $bg    = '#1e1e1e';
                        $color = '#dcdcde';

                        if (stripos($log, 'fatal') !== false) {
                            $bg    = '#3a0d0d';
                            $color = '#ff6b6b';
                        } elseif (stripos($log, 'warning') !== false) {
                            $bg    = '#3a2c00';
                            $color = '#ffd166';
                        } elseif (stripos($log, 'info') !== false) {
                            $bg    = '#0d2d3a';
                            $color = '#72ddf7';
                        }

                        ?>

                        <div style="
                        background:<?php echo esc_attr($bg); ?>;
                        color:<?php echo esc_attr($color); ?>;
                        padding:12px 15px;
                        margin-bottom:10px;
                        border-radius:6px;
                        line-height:1.6;
                        word-break:break-word;
                    ">

                            <?php echo esc_html($log); ?>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </div>

<?php
    }
}
