## 使用方法

```php
add_action('plugins_loaded', function () {
    $telemetry = new \Wenprise\TelemetryClient\Client('your-plugin-name', '1.0.0');

    // 添加插件特定的性能指标
    $telemetry->add_custom_metric('cache_hits_' . $plugin_slug, 150);
    $telemetry->add_custom_metric('api_response_time_' . $plugin_slug, 0.45);
});
```
