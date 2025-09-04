<?php
/**
 * Plugin Name: WP Performance Analyser
 * Plugin URI: https://github.com/andreas83/wp-performance-analyser
 * Description: Analyzes WordPress plugin execution times and database query performance
 * Version: 1.0.0
 * Author: Andreas Beder
 * Author URI: mailto:andreas@moving-bytes.at
 * Contributors: Claude AI Assistant (Anthropic)
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPPA_VERSION', '1.0.0');
define('WPPA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPPA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPPA_PLUGIN_BASENAME', plugin_basename(__FILE__));

class WP_Performance_Analyser {
    private static $instance = null;
    private $start_time;
    private $plugin_timings = [];
    private $query_timings = [];
    private $active_plugins = [];
    private $plugin_start_times = [];
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->start_time = microtime(true);
        $this->init();
    }
    
    private function init() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        add_action('plugins_loaded', [$this, 'setup_tracking'], -9999);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('shutdown', [$this, 'save_performance_data'], 9999);
        
        add_filter('query', [$this, 'log_query_start']);
        add_action('query_end', [$this, 'log_query_end']);
        
        add_action('pre_update_option_active_plugins', [$this, 'track_plugin_activation'], 10, 2);
        
        // Track plugin loading times using a different approach
        add_action('activated_plugin', [$this, 'track_activated_plugin']);
        add_action('plugins_loaded', [$this, 'calculate_plugin_load_times'], -10000);
        
        $this->setup_query_tracking();
    }
    
    public function activate() {
        $this->create_database_table();
        wp_schedule_event(time(), 'daily', 'wppa_cleanup_old_data');
    }
    
    public function deactivate() {
        wp_clear_scheduled_hook('wppa_cleanup_old_data');
    }
    
    private function create_database_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wppa_performance_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            page_url varchar(255) NOT NULL,
            plugin_name varchar(255) NOT NULL,
            execution_time float NOT NULL,
            memory_usage bigint(20) NOT NULL,
            query_count int(11) NOT NULL,
            query_time float NOT NULL,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY page_url (page_url),
            KEY plugin_name (plugin_name),
            KEY timestamp (timestamp)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function setup_tracking() {
        $this->active_plugins = get_option('active_plugins', []);
        
        foreach ($this->active_plugins as $plugin) {
            if ($plugin === WPPA_PLUGIN_BASENAME) {
                continue;
            }
            
            $plugin_file = WP_PLUGIN_DIR . '/' . $plugin;
            if (file_exists($plugin_file)) {
                $this->track_plugin_load($plugin);
            }
        }
    }
    
    private function track_plugin_load($plugin) {
        // This method is now simplified as we'll use a different tracking approach
        $plugin_file = WP_PLUGIN_DIR . '/' . $plugin;
        if (file_exists($plugin_file)) {
            $start_time = microtime(true);
            $start_memory = memory_get_usage(true);
            
            // Store the start time for this plugin
            $this->plugin_start_times[$plugin] = [
                'time' => $start_time,
                'memory' => $start_memory
            ];
        }
    }
    
    public function calculate_plugin_load_times() {
        // Calculate load times for all active plugins
        $this->active_plugins = get_option('active_plugins', []);
        
        foreach ($this->active_plugins as $plugin) {
            if ($plugin === WPPA_PLUGIN_BASENAME) {
                continue;
            }
            
            // Simple approach: track based on file include time
            $plugin_file = WP_PLUGIN_DIR . '/' . $plugin;
            if (file_exists($plugin_file)) {
                // Get plugin data
                $plugin_data = get_file_data($plugin_file, ['Name' => 'Plugin Name']);
                $plugin_name = $plugin_data['Name'] ?: basename($plugin, '.php');
                
                // For now, we can't accurately measure individual plugin load times
                // This would require deep WordPress core hooks that don't exist
                // We'll focus on overall page performance and query analysis instead
            }
        }
    }
    
    public function track_activated_plugin($plugin) {
        // Track when a plugin is activated
        $this->plugin_start_times[$plugin] = microtime(true);
    }
    
    private function setup_query_tracking() {
        add_filter('query_vars', function($vars) {
            global $wpdb;
            if (!isset($wpdb->wppa_query_start)) {
                $wpdb->wppa_query_start = [];
            }
            return $vars;
        });
    }
    
    public function log_query_start($query) {
        global $wpdb;
        $query_id = md5($query . microtime(true));
        $wpdb->wppa_query_start[$query_id] = microtime(true);
        $wpdb->wppa_current_query = $query_id;
        return $query;
    }
    
    public function log_query_end() {
        global $wpdb;
        if (isset($wpdb->wppa_current_query) && isset($wpdb->wppa_query_start[$wpdb->wppa_current_query])) {
            $duration = microtime(true) - $wpdb->wppa_query_start[$wpdb->wppa_current_query];
            $this->query_timings[] = [
                'query' => $wpdb->last_query,
                'time' => $duration,
                'caller' => $this->get_query_caller()
            ];
            unset($wpdb->wppa_query_start[$wpdb->wppa_current_query]);
        }
    }
    
    private function get_query_caller() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        foreach ($trace as $call) {
            if (isset($call['file']) && strpos($call['file'], WP_PLUGIN_DIR) !== false) {
                $plugin = str_replace(WP_PLUGIN_DIR . '/', '', $call['file']);
                $plugin = explode('/', $plugin)[0];
                return $plugin;
            }
        }
        return 'WordPress Core';
    }
    
    private function get_plugin_name($plugin_file) {
        $plugin_data = get_file_data(WP_PLUGIN_DIR . '/' . $plugin_file, ['Name' => 'Plugin Name']);
        return $plugin_data['Name'] ?: basename($plugin_file, '.php');
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'WP Performance Analyser',
            'Performance',
            'manage_options',
            'wp-performance-analyser',
            [$this, 'render_admin_page'],
            'dashicons-performance',
            100
        );
        
        add_submenu_page(
            'wp-performance-analyser',
            'Plugin Performance',
            'Plugin Performance',
            'manage_options',
            'wppa-plugin-performance',
            [$this, 'render_plugin_performance_page']
        );
        
        add_submenu_page(
            'wp-performance-analyser',
            'Query Analysis',
            'Query Analysis',
            'manage_options',
            'wppa-query-analysis',
            [$this, 'render_query_analysis_page']
        );
        
        add_submenu_page(
            'wp-performance-analyser',
            'Settings',
            'Settings',
            'manage_options',
            'wppa-settings',
            [$this, 'render_settings_page']
        );
        
    }
    
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'wp-performance-analyser') === false && strpos($hook, 'wppa-') === false) {
            return;
        }
        
        wp_enqueue_style('wppa-admin', WPPA_PLUGIN_URL . 'assets/admin.css', [], WPPA_VERSION);
        wp_enqueue_script('wppa-admin', WPPA_PLUGIN_URL . 'assets/admin.js', ['jquery', 'wp-element'], WPPA_VERSION, true);
        
        wp_localize_script('wppa-admin', 'wppa_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wppa_ajax_nonce')
        ]);
    }
    
    public function render_admin_page() {
        $current_data = $this->get_current_performance_data();
        $historical_data = $this->get_historical_data();
        ?>
        <div class="wrap">
            <h1>WP Performance Analyser</h1>
            
            <div class="wppa-dashboard">
                <div class="wppa-card">
                    <h2>Current Performance Overview</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Metric</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Page Load Time</td>
                                <td><?php echo number_format($current_data['total_time'] * 1000, 2); ?> ms</td>
                            </tr>
                            <tr>
                                <td>Active Plugins</td>
                                <td><?php echo count($this->active_plugins); ?></td>
                            </tr>
                            <tr>
                                <td>Total Queries</td>
                                <td><?php echo $current_data['query_count']; ?></td>
                            </tr>
                            <tr>
                                <td>Total Query Time</td>
                                <td><?php echo number_format($current_data['query_time'] * 1000, 2); ?> ms</td>
                            </tr>
                            <tr>
                                <td>Memory Usage</td>
                                <td><?php echo size_format($current_data['memory_usage']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="wppa-card">
                    <h2>Top 5 Slowest Plugins</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Plugin</th>
                                <th>Execution Time</th>
                                <th>% of Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($current_data['slowest_plugins'] as $plugin): ?>
                            <tr>
                                <td><?php echo esc_html($plugin['name']); ?></td>
                                <td><?php echo number_format($plugin['time'] * 1000, 2); ?> ms</td>
                                <td><?php echo number_format($plugin['percentage'], 1); ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="wppa-card">
                    <h2>Database Query Statistics</h2>
                    <canvas id="wppa-query-chart"></canvas>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function render_plugin_performance_page() {
        ?>
        <div class="wrap">
            <h1>Plugin Performance Analysis</h1>
            
            <div class="notice notice-info">
                <p><strong>Note:</strong> Individual plugin performance tracking is technically challenging in WordPress due to how plugins are loaded. 
                This feature focuses on overall page performance and database query analysis instead.</p>
                <p>For detailed performance analysis, please use the main Performance dashboard and Query Analysis pages.</p>
            </div>
            
            <h2>Alternative Performance Monitoring</h2>
            <p>Consider using these approaches for plugin performance analysis:</p>
            <ul>
                <li><strong>Query Analysis:</strong> Identify slow database queries and their sources</li>
                <li><strong>Page Load Times:</strong> Monitor overall page generation times</li>
                <li><strong>Memory Usage:</strong> Track memory consumption patterns</li>
                <li><strong>External Tools:</strong> Use profiling tools like Query Monitor, Debug Bar, or New Relic</li>
            </ul>
            
            <p>
                <a href="<?php echo admin_url('admin.php?page=wp-performance-analyser'); ?>" class="button button-primary">
                    View Performance Dashboard
                </a>
                <a href="<?php echo admin_url('admin.php?page=wppa-query-analysis'); ?>" class="button">
                    Analyze Database Queries
                </a>
            </p>
        </div>
        <?php
        return;
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wppa_performance_logs';
        
        $plugins = $wpdb->get_results("
            SELECT plugin_name, 
                   AVG(execution_time) as avg_time,
                   MAX(execution_time) as max_time,
                   MIN(execution_time) as min_time,
                   COUNT(*) as sample_count
            FROM $table_name
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY plugin_name
            ORDER BY avg_time DESC
        ");
        ?>
        <div class="wrap">
            <h1>Plugin Performance Analysis</h1>
            
            <div class="wppa-filters">
                <form method="get">
                    <input type="hidden" name="page" value="wppa-plugin-performance">
                    <label>Time Range:
                        <select name="range">
                            <option value="1">Last 24 Hours</option>
                            <option value="7" selected>Last 7 Days</option>
                            <option value="30">Last 30 Days</option>
                        </select>
                    </label>
                    <input type="submit" class="button" value="Filter">
                </form>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Plugin Name</th>
                        <th>Avg Execution Time</th>
                        <th>Max Time</th>
                        <th>Min Time</th>
                        <th>Samples</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plugins as $plugin): ?>
                    <tr>
                        <td><?php echo esc_html($plugin->plugin_name); ?></td>
                        <td><?php echo number_format($plugin->avg_time * 1000, 2); ?> ms</td>
                        <td><?php echo number_format($plugin->max_time * 1000, 2); ?> ms</td>
                        <td><?php echo number_format($plugin->min_time * 1000, 2); ?> ms</td>
                        <td><?php echo $plugin->sample_count; ?></td>
                        <td>
                            <a href="?page=wppa-plugin-details&plugin=<?php echo urlencode($plugin->plugin_name); ?>" 
                               class="button button-small">View Details</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    public function render_query_analysis_page() {
        global $wpdb;
        $queries = $wpdb->queries ?? [];
        
        $grouped_queries = [];
        foreach ($queries as $query_data) {
            $query = $query_data[0];
            $time = $query_data[1];
            $caller = $query_data[2] ?? 'Unknown';
            
            $type = $this->get_query_type($query);
            if (!isset($grouped_queries[$type])) {
                $grouped_queries[$type] = [
                    'count' => 0,
                    'total_time' => 0,
                    'queries' => []
                ];
            }
            
            $grouped_queries[$type]['count']++;
            $grouped_queries[$type]['total_time'] += $time;
            $grouped_queries[$type]['queries'][] = [
                'query' => $query,
                'time' => $time,
                'caller' => $caller
            ];
        }
        ?>
        <div class="wrap">
            <h1>Database Query Analysis</h1>
            
            <?php if (!defined('SAVEQUERIES') || !SAVEQUERIES): ?>
            <div class="notice notice-warning">
                <p>Query logging is not enabled. You can enable it from the <a href="<?php echo admin_url('admin.php?page=wppa-settings'); ?>">Settings page</a> or manually add <code>define('SAVEQUERIES', true);</code> to your wp-config.php file.</p>
            </div>
            <?php endif; ?>
            
            <div class="wppa-query-summary">
                <h2>Query Summary</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Query Type</th>
                            <th>Count</th>
                            <th>Total Time</th>
                            <th>Avg Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grouped_queries as $type => $data): ?>
                        <tr>
                            <td><?php echo esc_html($type); ?></td>
                            <td><?php echo $data['count']; ?></td>
                            <td><?php echo number_format($data['total_time'] * 1000, 2); ?> ms</td>
                            <td><?php echo number_format(($data['total_time'] / $data['count']) * 1000, 2); ?> ms</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="wppa-slow-queries">
                <h2>Slowest Queries</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Query</th>
                            <th>Time</th>
                            <th>Caller</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $all_queries = [];
                        foreach ($grouped_queries as $type => $data) {
                            $all_queries = array_merge($all_queries, $data['queries']);
                        }
                        usort($all_queries, function($a, $b) {
                            return $b['time'] <=> $a['time'];
                        });
                        $slow_queries = array_slice($all_queries, 0, 10);
                        
                        foreach ($slow_queries as $index => $query): ?>
                        <tr>
                            <td>
                                <code><?php echo esc_html(substr($query['query'], 0, 100)); ?></code>
                                <?php if (strlen($query['query']) > 100): ?>
                                    <a href="#" class="wppa-view-query-details" data-query-index="<?php echo $index; ?>" 
                                       data-query="<?php echo esc_attr(base64_encode($query['query'])); ?>"
                                       data-caller="<?php echo esc_attr($query['caller']); ?>"
                                       data-time="<?php echo esc_attr($query['time']); ?>">
                                        [View Full Query]
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format($query['time'] * 1000, 2); ?> ms</td>
                            <td>
                                <?php 
                                $caller_parts = explode(',', $query['caller']);
                                echo esc_html($caller_parts[0]); 
                                ?>
                                <?php if (count($caller_parts) > 1): ?>
                                    <a href="#" class="wppa-view-stack-trace" 
                                       data-trace="<?php echo esc_attr(base64_encode($query['caller'])); ?>">
                                        [View Stack]
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Modal for Query Details -->
            <div id="wppa-query-modal" class="wppa-modal" style="display: none;">
                <div class="wppa-modal-content">
                    <span class="wppa-modal-close">&times;</span>
                    <h2>Query Details</h2>
                    <div class="wppa-query-detail-content">
                        <h3>Execution Time</h3>
                        <p class="wppa-query-time"></p>
                        
                        <h3>Full Query</h3>
                        <pre class="wppa-query-full"></pre>
                        
                        <h3>Call Stack</h3>
                        <pre class="wppa-query-stack"></pre>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function render_settings_page() {
        if (isset($_POST['wppa_save_settings']) && wp_verify_nonce($_POST['wppa_settings_nonce'], 'wppa_settings')) {
            update_option('wppa_enable_tracking', isset($_POST['enable_tracking']));
            update_option('wppa_data_retention', intval($_POST['data_retention']));
            update_option('wppa_tracking_sample_rate', intval($_POST['sample_rate']));
            
            // Handle SAVEQUERIES setting
            if (isset($_POST['enable_query_tracking'])) {
                $this->update_savequeries_constant($_POST['enable_query_tracking'] === '1');
                update_option('wppa_savequeries_enabled', $_POST['enable_query_tracking'] === '1');
            }
            
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        $enable_tracking = get_option('wppa_enable_tracking', true);
        $data_retention = get_option('wppa_data_retention', 30);
        $sample_rate = get_option('wppa_tracking_sample_rate', 100);
        $savequeries_enabled = get_option('wppa_savequeries_enabled', defined('SAVEQUERIES') && SAVEQUERIES);
        ?>
        <div class="wrap">
            <h1>WP Performance Analyser Settings</h1>
            
            <form method="post">
                <?php wp_nonce_field('wppa_settings', 'wppa_settings_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="enable_tracking">Enable Tracking</label></th>
                        <td>
                            <input type="checkbox" id="enable_tracking" name="enable_tracking" 
                                   <?php checked($enable_tracking); ?>>
                            <p class="description">Enable performance data tracking</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="data_retention">Data Retention (days)</label></th>
                        <td>
                            <input type="number" id="data_retention" name="data_retention" 
                                   value="<?php echo $data_retention; ?>" min="1" max="365">
                            <p class="description">How long to keep performance data</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sample_rate">Sample Rate (%)</label></th>
                        <td>
                            <input type="number" id="sample_rate" name="sample_rate" 
                                   value="<?php echo $sample_rate; ?>" min="1" max="100">
                            <p class="description">Percentage of requests to track (1-100)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="enable_query_tracking">Query Tracking (SAVEQUERIES)</label></th>
                        <td>
                            <select id="enable_query_tracking" name="enable_query_tracking">
                                <option value="0" <?php selected(!$savequeries_enabled); ?>>Disabled</option>
                                <option value="1" <?php selected($savequeries_enabled); ?>>Enabled</option>
                            </select>
                            <p class="description">Enable detailed query tracking (SAVEQUERIES constant). This will modify wp-config.php if writable.</p>
                            <?php if (defined('SAVEQUERIES')): ?>
                                <p class="description" style="color: <?php echo SAVEQUERIES ? '#46b450' : '#dc3232'; ?>">
                                    Current status: SAVEQUERIES is <?php echo SAVEQUERIES ? 'ENABLED' : 'DISABLED'; ?>
                                </p>
                            <?php else: ?>
                                <p class="description" style="color: #666;">SAVEQUERIES is not defined in wp-config.php</p>
                            <?php endif; ?>
                            <?php if (!is_writable(ABSPATH . 'wp-config.php')): ?>
                                <p class="description" style="color: #dc3232;">
                                    Warning: wp-config.php is not writable. You'll need to manually add/modify:<br>
                                    <code>define('SAVEQUERIES', true);</code>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="wppa_save_settings" class="button-primary" value="Save Settings">
                </p>
            </form>
            
            <h2>Export Data</h2>
            <p>
                <a href="<?php echo admin_url('admin-ajax.php?action=wppa_export_data&nonce=' . wp_create_nonce('wppa_export')); ?>" 
                   class="button">Export Performance Data (CSV)</a>
            </p>
            
            <h2>Clear Data</h2>
            <p>
                <button class="button" onclick="if(confirm('Are you sure you want to clear all performance data?')) { window.location.href='<?php echo admin_url('admin-ajax.php?action=wppa_clear_data&nonce=' . wp_create_nonce('wppa_clear')); ?>'; }">
                    Clear All Performance Data
                </button>
            </p>
        </div>
        <?php
    }
    
    private function get_query_type($query) {
        $query = trim($query);
        if (stripos($query, 'SELECT') === 0) return 'SELECT';
        if (stripos($query, 'INSERT') === 0) return 'INSERT';
        if (stripos($query, 'UPDATE') === 0) return 'UPDATE';
        if (stripos($query, 'DELETE') === 0) return 'DELETE';
        if (stripos($query, 'SHOW') === 0) return 'SHOW';
        return 'OTHER';
    }
    
    private function get_current_performance_data() {
        $total_time = microtime(true) - $this->start_time;
        $query_count = get_num_queries();
        $memory_usage = memory_get_peak_usage(true);
        
        $slowest_plugins = [];
        if (!empty($this->plugin_timings)) {
            arsort($this->plugin_timings);
            $top_5 = array_slice($this->plugin_timings, 0, 5, true);
            foreach ($top_5 as $plugin => $data) {
                $time = is_array($data) ? $data['time'] : $data;
                $slowest_plugins[] = [
                    'name' => $this->get_plugin_name($plugin),
                    'time' => $time,
                    'percentage' => ($time / $total_time) * 100
                ];
            }
        }
        
        $query_time = 0;
        if (defined('SAVEQUERIES') && SAVEQUERIES) {
            global $wpdb;
            foreach ($wpdb->queries as $query) {
                $query_time += $query[1];
            }
        }
        
        return [
            'total_time' => $total_time,
            'query_count' => $query_count,
            'query_time' => $query_time,
            'memory_usage' => $memory_usage,
            'slowest_plugins' => $slowest_plugins
        ];
    }
    
    private function get_historical_data($days = 7) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wppa_performance_logs';
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT DATE(timestamp) as date,
                   AVG(execution_time) as avg_time,
                   AVG(query_count) as avg_queries,
                   AVG(memory_usage) as avg_memory
            FROM $table_name
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY DATE(timestamp)
            ORDER BY date DESC
        ", $days));
    }
    
    public function save_performance_data() {
        if (!get_option('wppa_enable_tracking', true)) {
            return;
        }
        
        $sample_rate = get_option('wppa_tracking_sample_rate', 100);
        if (rand(1, 100) > $sample_rate) {
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wppa_performance_logs';
        
        // Save overall page performance data instead of individual plugin data
        $total_time = microtime(true) - $this->start_time;
        $memory_usage = memory_get_peak_usage(true);
        $query_count = get_num_queries();
        $query_time = $this->get_total_query_time();
        
        $result = $wpdb->insert($table_name, [
            'page_url' => $_SERVER['REQUEST_URI'] ?? '/',
            'plugin_name' => 'Page Load', // Track overall page performance
            'execution_time' => $total_time,
            'memory_usage' => $memory_usage,
            'query_count' => $query_count,
            'query_time' => $query_time
        ]);
    }
    
    private function get_total_query_time() {
        $total = 0;
        if (defined('SAVEQUERIES') && SAVEQUERIES) {
            global $wpdb;
            foreach ($wpdb->queries as $query) {
                $total += $query[1];
            }
        }
        return $total;
    }
    
    private function update_savequeries_constant($enable) {
        $config_path = ABSPATH . 'wp-config.php';
        
        if (!file_exists($config_path)) {
            // Try one directory up
            $config_path = dirname(ABSPATH) . '/wp-config.php';
        }
        
        if (!file_exists($config_path) || !is_writable($config_path)) {
            return false;
        }
        
        $config_content = file_get_contents($config_path);
        
        // Pattern to match existing SAVEQUERIES definition
        $pattern = "/define\s*\(\s*['\"]SAVEQUERIES['\"]\s*,\s*(true|false|TRUE|FALSE)\s*\)\s*;/";
        
        if (preg_match($pattern, $config_content)) {
            // Replace existing definition
            $replacement = $enable ? "define('SAVEQUERIES', true);" : "define('SAVEQUERIES', false);";
            $config_content = preg_replace($pattern, $replacement, $config_content);
        } else {
            // Add new definition before "/* That's all, stop editing!" or at the end
            $insertion = "\n/* WP Performance Analyser Query Tracking */\ndefine('SAVEQUERIES', " . ($enable ? 'true' : 'false') . ");\n";
            
            if (strpos($config_content, "/* That's all, stop editing!") !== false) {
                $config_content = str_replace(
                    "/* That's all, stop editing!",
                    $insertion . "\n/* That's all, stop editing!",
                    $config_content
                );
            } else {
                // Find the last require_once or similar statement
                $lines = explode("\n", $config_content);
                $insert_at = count($lines) - 1;
                
                for ($i = count($lines) - 1; $i >= 0; $i--) {
                    if (strpos($lines[$i], 'require_once') !== false || 
                        strpos($lines[$i], 'require') !== false ||
                        strpos($lines[$i], 'include') !== false) {
                        $insert_at = $i;
                        break;
                    }
                }
                
                array_splice($lines, $insert_at, 0, explode("\n", $insertion));
                $config_content = implode("\n", $lines);
            }
        }
        
        // Create backup
        $backup_path = $config_path . '.wppa-backup-' . date('Y-m-d-His');
        copy($config_path, $backup_path);
        
        // Write the updated content
        if (file_put_contents($config_path, $config_content)) {
            // Clean up old backups (keep only last 5)
            $this->cleanup_old_backups(dirname($config_path));
            return true;
        }
        
        return false;
    }
    
    private function cleanup_old_backups($dir) {
        $pattern = $dir . '/wp-config.php.wppa-backup-*';
        $backups = glob($pattern);
        
        if (count($backups) > 5) {
            // Sort by modification time
            usort($backups, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Remove oldest backups
            $to_remove = count($backups) - 5;
            for ($i = 0; $i < $to_remove; $i++) {
                @unlink($backups[$i]);
            }
        }
    }
    
}

function wppa_init() {
    WP_Performance_Analyser::get_instance();
}
add_action('plugins_loaded', 'wppa_init', -10000);

if (file_exists(WPPA_PLUGIN_DIR . 'includes/ajax-handlers.php')) {
    require_once WPPA_PLUGIN_DIR . 'includes/ajax-handlers.php';
}

add_action('wp_ajax_wppa_export_data', function() {
    if (!wp_verify_nonce($_GET['nonce'], 'wppa_export')) {
        wp_die('Invalid nonce');
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'wppa_performance_logs';
    $data = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="wppa-export-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    if (!empty($data)) {
        fputcsv($output, array_keys($data[0]));
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
    }
    fclose($output);
    exit;
});

add_action('wp_ajax_wppa_clear_data', function() {
    if (!wp_verify_nonce($_GET['nonce'], 'wppa_clear')) {
        wp_die('Invalid nonce');
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'wppa_performance_logs';
    $wpdb->query("TRUNCATE TABLE $table_name");
    
    wp_redirect(admin_url('admin.php?page=wppa-settings&cleared=1'));
    exit;
});

add_action('wppa_cleanup_old_data', function() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wppa_performance_logs';
    $retention_days = get_option('wppa_data_retention', 30);
    
    $wpdb->query($wpdb->prepare(
        "DELETE FROM $table_name WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
        $retention_days
    ));
});