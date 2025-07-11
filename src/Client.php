<?php

namespace Wenprise\TelemetryClient;

/**
 * 插件遥测功能类
 */
class Client {
    private $plugin_name;
    private $version;
    private $performance_metrics = array();
    private $error_logs = array();
    
    // 定义性能指标阈值
    const PERFORMANCE_THRESHOLDS = array(
        'execution_time' => 1.0, // 秒
        'memory_usage' => 32 * 1024 * 1024, // 32MB
        'db_queries' => 100
    );
    
    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        
        // 设置错误处理器，确保捕获所有需要的错误类型
        $error_types = E_ALL & ~E_DEPRECATED & ~E_STRICT;

        if (!WP_DEBUG) {
            // 在非调试模式下只捕获重要错误
            $error_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
        }

        set_error_handler(array($this, 'error_handler'), $error_types);
        register_shutdown_function(array($this, 'fatal_error_handler'));
        
        // 启动性能监控
        add_action('init', array($this, 'start_performance_monitoring'));
        add_action('shutdown', array($this, 'end_performance_monitoring'));
        
        // 添加定时任务
        add_action('wp_schedule_event', array($this, 'schedule_telemetry_event'));
        // 处理遥测数据发送
        add_action('telemetry_cron_hook', array($this, 'send_telemetry_data'));
    }
    
    
    /**
     * 设置定时任务
     */
    public function schedule_telemetry_event() {
        if (!wp_next_scheduled('telemetry_cron_hook')) {
            wp_schedule_event(time(), 'daily', 'telemetry_cron_hook');
        }
    }
    
    /**
     * 收集遥测数据
     */
    private function collect_telemetry_data() {
        // 获取所有已安装的插件
        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', array());
        
        $plugins = array();
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $plugin_slug = dirname($plugin_file);
            if ($plugin_slug === '.') {
                $plugin_slug = basename($plugin_file, '.php');
            }
            
            $plugins[] = array(
                'slug' => $plugin_slug,
                'name' => $plugin_data['Name'],
                'version' => $plugin_data['Version'],
                'is_active' => in_array($plugin_file, $active_plugins)
            );
        }
        
        // 获取所有已安装的主题
        $all_themes = wp_get_themes();
        $active_theme = wp_get_theme();
        
        $themes = array();
        foreach ($all_themes as $theme_slug => $theme_data) {
            $themes[] = array(
                'slug' => $theme_slug,
                'name' => $theme_data->get('Name'),
                'version' => $theme_data->get('Version'),
                'is_active' => ($theme_slug === $active_theme->get_stylesheet())
            );
        }
        
        $data = array(
            'site_url' => home_url(),
            'wp_version' => get_bloginfo('version'),
            'php_version' => phpversion(),
            'is_multisite' => is_multisite(),
            'locale' => get_locale(),
            'plugins' => $plugins,
            'themes' => $themes,
            'performance_metrics' => $this->performance_metrics,
            'error_logs' => array_slice($this->error_logs, -50), // 最近50条错误记录
        );
        
        return $data;
    }
    
    /**
     * 发送遥测数据
     */
    public function send_telemetry_data() {
        $data = $this->collect_telemetry_data();
        
        // 发送数据到遥测服务器
        $response = wp_remote_post('https://api.wpcio.com.com/telemetry/v1/collect', array(
            'body' => json_encode($data),
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($response)) {
            error_log('遥测数据发送失败: ' . $response->get_error_message());
        }
    }
    
    /**
     * 错误处理器
     */
    public function error_handler($errno, $errstr, $errfile, $errline) {
        // 检查是否应该记录此错误
        if (!(error_reporting() & $errno)) {
            // 如果当前的error_reporting设置不包含这个错误级别，则忽略
            return false;
        }
        
        $error_type_map = array(
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED'
        );

        $error = array(
            'type' => isset($error_type_map[$errno]) ? $error_type_map[$errno] : $errno,
            'message' => $errstr,
            'file' => str_replace(ABSPATH, '', $errfile),
            'line' => $errline,
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'url' => $_SERVER['REQUEST_URI'],
            'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'error_reporting_level' => error_reporting()
        );
        
        $this->error_logs[] = $error;
        
        // 立即发送严重错误
        if (in_array($errno, array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
            $this->send_telemetry_data();
        }
        
        return false; // 继续执行默认的错误处理
    }
    
    /**
     * 致命错误处理器
     */
    public function fatal_error_handler() {
        $error = error_get_last();
        if ($error !== NULL && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
            $this->error_handler($error['type'], $error['message'], $error['file'], $error['line']);
        }
    }
    
    /**
     * 开始性能监控
     */
    public function start_performance_monitoring() {
        $this->performance_metrics['start_time'] = microtime(true);
        $this->performance_metrics['start_memory'] = memory_get_usage();
        
        // 开始数据库查询统计
        global $wpdb;
        $this->performance_metrics['start_queries'] = $wpdb->num_queries;
    }
    
    /**
     * 结束性能监控
     */
    public function end_performance_monitoring() {
        global $wpdb;
        
        // 计算执行时间
        $execution_time = microtime(true) - $this->performance_metrics['start_time'];
        
        // 计算内存使用
        $memory_usage = memory_get_usage() - $this->performance_metrics['start_memory'];
        
        // 计算数据库查询数
        $db_queries = $wpdb->num_queries - $this->performance_metrics['start_queries'];
        
        $this->performance_metrics['metrics'] = array(
            'execution_time' => round($execution_time, 4),
            'memory_usage' => $memory_usage,
            'db_queries' => $db_queries,
            'peak_memory_usage' => memory_get_peak_usage(),
            'exceeded_thresholds' => array()
        );
        
        // 检查是否超过阈值
        if ($execution_time > self::PERFORMANCE_THRESHOLDS['execution_time']) {
            $this->performance_metrics['metrics']['exceeded_thresholds'][] = 'execution_time';
        }
        if ($memory_usage > self::PERFORMANCE_THRESHOLDS['memory_usage']) {
            $this->performance_metrics['metrics']['exceeded_thresholds'][] = 'memory_usage';
        }
        if ($db_queries > self::PERFORMANCE_THRESHOLDS['db_queries']) {
            $this->performance_metrics['metrics']['exceeded_thresholds'][] = 'db_queries';
        }
        
        // 如果超过任何阈值，立即发送遥测数据
        if (!empty($this->performance_metrics['metrics']['exceeded_thresholds'])) {
            $this->send_telemetry_data();
        }
    }
    
    /**
     * 检测错误来源（插件或主题）
     */
    private function detect_error_source($file) {
        $file = wp_normalize_path($file);
        $wp_content_dir = wp_normalize_path(WP_CONTENT_DIR);
        
        $relative_path = str_replace($wp_content_dir, '', $file);
        
        // 默认返回值
        $result = array(
            'type' => 'core',
            'slug' => 'wordpress'
        );
        
        // 检查是否是插件错误
        if (strpos($relative_path, '/plugins/') !== false) {
            $parts = explode('/plugins/', $relative_path);
            if (isset($parts[1])) {
                $plugin_path = explode('/', $parts[1]);
                $result['type'] = 'plugin';
                $result['slug'] = $plugin_path[0];
            }
        }
        // 检查是否是主题错误
        elseif (strpos($relative_path, '/themes/') !== false) {
            $parts = explode('/themes/', $relative_path);
            if (isset($parts[1])) {
                $theme_path = explode('/', $parts[1]);
                $result['type'] = 'theme';
                $result['slug'] = $theme_path[0];
            }
        }
        
        return $result;
    }
    
    /**
     * 添加自定义性能指标
     */
    public function add_custom_metric($key, $value) {
        if (!isset($this->performance_metrics['custom_metrics'])) {
            $this->performance_metrics['custom_metrics'] = array();
        }
        $this->performance_metrics['custom_metrics'][$key] = $value;
    }
}