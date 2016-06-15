<?php
/*
 * Plugin Name: WooCommerce Sift Science
 * Plugin URI:  https://github.com/bitpiston/woocommerce-sift-science
 * Description: Sift Science anti-fraud / chargeback prevention plugin for WooCommerce.
 * Version:     0.1.0
 * Author:      BitPiston Studios
 * Author URI:  http://bitpiston.com/
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * WC_Sift_Science class
 */
class WC_Sift_Science
{
    /**
     * @var WC_Sift_Science Single instance of this class
     */
    protected static $instance;

    /**
     * Bootstraps the class and hooks required actions & filters.
     *
     * @return void
     */
    public function __construct() {
        $active_plugins = apply_filters( 'active_plugins', get_option('active_plugins') );

        if ( in_array('woocommerce/woocommerce.php', $active_plugins) && class_exists('WC_Integration') ) {

            // Our classes and depdencies if not using composer
            if ( ! class_exists('WC_Sift_Science_Integration') && is_file( dirname(__FILE__) . '/vendor/autoload.php' ) ) {
                require_once('vendor/autoload.php');
            }

            // Register the integration
            add_filter('woocommerce_integrations', [$this, 'add_integration']);
        }
    }

    /**
     * Main plugin instance, ensures only one instance is/can be loaded.
     *
     * @return WC_Sift_Science
     */
    public static function get_instance()
    {
        if ( is_null(self::$instance) ) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Add a new integration to WooCommerce.
     *
     * @param array $integrations WooCommerce integrations
     * @return array $integrations WooCommerce integrations
     */
    public function add_integration($integrations)
    {
        $integrations[] = 'WC_Sift_Science_Integration';

        return $integrations;
    }
}

add_action('plugins_loaded', ['WC_Sift_Science', 'get_instance'], 0);
