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
            $data = self::load($metricname);
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
        if ($this->timestamp) {
            $value = $value . " " . $this->timestamp;
        }
        if (! $this->labels) {
            $this->save($this->name, $value);
        } else {
            $state = $this->load($this->name);
            $state[$this->labels] = $value;
            $this->save($this->name, $state);
        }
    }

    static private function load ($key)
    {
        $optname = self::option_name($key);
        if ( self::is_network_version() ) {
            return get_site_option( $optname );
        } else {
            return get_option( $optname );
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
     * @returns Whether this plugin is currently network activated
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
