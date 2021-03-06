<?php
/*
 * Plugin Name: Prometheus exporter for WordPress
 * Plugin URI:  https://github.com/epfl-sti/wordpress.plugin.prometheus
 * Description: Integrate WordPress with Prometheus (https://prometheus.io/)

 * Version:     0.10
 * Author:      STI-IT Web
 * Author URI:  mailto:stiitweb@groupes.epfl.ch
 * License:     MIT License / Copyright (c) 2017-2018 EPFL ⋅ STI ⋅ IT
 */

if (! defined('ABSPATH')) {
    die('Access denied.');
}

class WPPrometheusExporter
{
    static function hook ()
    {
        $thisclass = get_called_class();
        add_action('template_redirect', array($thisclass, "do_template_redirect"));
    }

    static function do_template_redirect () {
        if (preg_match('@/metrics/?$@', $_SERVER['REQUEST_URI'])) {
            self::serve_metrics();
        }
    }

    static private $_metrics = array();
    /**
     * Register a metric.
     *
     * Note: it is typical to register a metric once, but to call (new
     * WPPrometheusExporter($name, $labels))->update() several time
     * with different $labels.
     *
     * @param $name The Prometheus name of the metric
     *
     * @param $opts['help'] The text that appears after "# HELP " in the
     *                      text/plain Prometheus output
     *
     * @param $opts['type'] The text that appears after "# TYPE " in the
     *                      text/plain Prometheus output
     *
     * @param $opts['has_timestamp'] Whether this time series automatically
     *                               adds a timestamp upon calling @link update.
     *                               Note that the timestamp value will be the
     *                               time at which the WPPrometheusExporter instance
     *                               was constructed, not the time at which the update
     *                               method was called.
     *
     * @param $opts['data_cb'] The function to call to return the data (for all
     *                         labels at once). Will be called with ($name)
     *                         as the parameter; should return an associative array
     *                         whose keys are strings of the form label1=value1,...
     *                         and whose values are what the text/plain Prometheus
     *                         output expects.
     */
    static function register_metric ($name, $opts) {
        self::$_metrics[$name] = $opts;
    }

    static private function serve_metrics ()
    {
        // https://prometheus.io/docs/instrumenting/exposition_formats/#text-format-details
        http_response_code(200);
        header("Content-Type: text/plain; version=0.0.4");
        foreach (self::$_metrics as $metricname => $info) {
            if ($info['help']) {
                printf("# HELP %s %s\n", $metricname, $info['help']);
            }
            if ($info['type']) {
                printf("# TYPE %s %s\n", $metricname, $info['type']);
            }
            if ($info['data_cb']) {
                $data = call_user_func($info['data_cb'], $metricname);
            } else {
                $data = self::load($metricname);
            }
            if (is_array($data)) {
                foreach ($data as $k => $v) {
                    echo sprintf("%s{%s} %s\n", $metricname, $k, $v);
                }
            } elseif ($data !== false) {
                echo sprintf("%s %s\n", $metricname, $data);
            } else {
                echo "# No data\n";
            }
            echo "\n";
        }
        die();
    }

    function __construct ($name, $labels = null)
    {
        $this->name = $name;
        $this->opts = self::$_metrics[$name];
        if (! $this->opts) {
            throw new Error("Attempt to access unregistered metric $name");
        }

        if ($this->opts['has_timestamp']) {
            $this->timestamp = time() . "000";  // We could do microtime(), but it's late.
        }

        if ($labels === null) return;
        ksort($labels);
        $label_keys = array();
        foreach ($labels as $k => $v) {
            array_push($label_keys, "$k=\"$v\"");
        }
        $this->labels = implode(",", $label_keys);
    }

    function fetch ()
    {
        $state = $this->load($this->name);
        if ($this->labels) {
            $value_and_timestamp = $state[$this->labels];
        } else {
            $value_and_timestamp = $state;
        }
        $tokens = explode(" ", $value_and_timestamp);
        return $tokens[0];
    }

    function update ($value)
    {
        if ($this->opts['data_cb']) {
            throw new Error(sprintf(
                "Cannot ->update() metric `%s' that has a data_cb",
                $this->name));
        }

        if ($this->timestamp) {
            $value = $value . " " . $this->timestamp;
        }
        if (! $this->labels) {
            $this->save($this->name, $value);
        } else {
            global $wpdb;
            $wpdb->query("BEGIN WORK");
            $state = $this->load($this->name);
            $state[$this->labels] = $value;
            $this->save($this->name, $state);
            $wpdb->query("COMMIT WORK");
        }
    }

    static private function load ($key)
    {
        // Always bypass the cache, lest we lose updates from
        // concurrent processes on map-valued variables.
        $cache_orig = $GLOBALS['wp_object_cache'];
        $GLOBALS['wp_object_cache'] = new \WP_Object_Cache();
        try {
            $optname = self::option_name($key);
            if ( self::is_network_version() ) {
                return get_site_option( $optname );
            } else {
                return get_option( $optname );
            }
        } finally {
            $GLOBALS['wp_object_cache'] = $cache_orig;
        }
    }

    static private function save ($key, $value)
    {
        $optname = self::option_name($key);
        if ( self::is_network_version() ) {
            return update_site_option($optname, $value);
        } else {
            return update_option($optname, $value);
        }
    }

    const SLUG = "prometheus_exporter";

    static private function option_name ($key)
    {
        if (self::is_network_version()) {
            return "plugin:" . self::SLUG . ":network:" . $key;
        } else {
            return "plugin:" . self::SLUG . ":" . $key;
        }
    }

    /**
     * @return Whether this plugin is currently network activated
     */
    static private $_is_network_version = null;
    static private function is_network_version()
    {
        if (self::$_is_network_version === null) {
            if (! function_exists('is_plugin_active_for_network')) {
                require_once(ABSPATH . '/wp-admin/includes/plugin.php');
            }

            self::$_is_network_version = (bool) is_plugin_active_for_network(plugin_basename(__FILE__));
        }

        return self::$_is_network_version;
    }
}

WPPrometheusExporter::hook();

/**
 * "Demo" metrics
 */
WPPrometheusExporter::register_metric(
    'wordpress_post_count',
    array(
        'help'    => 'Number of posts per post type',
        'type'    => 'gauge',
        'data_cb' => '_wp_prometheus_exporter_get_post_counts'
    ));

function _wp_prometheus_exporter_get_post_counts ($unused_metric_name)
{
    $data = array();
    foreach (get_post_types(null, 'names') as $post_type) {
        foreach (wp_count_posts($post_type) as $key => $val) {
            $data[sprintf('posttype="%s",status="%s"', $post_type, $key)] = $val;
        }
    }
    return $data;
}
