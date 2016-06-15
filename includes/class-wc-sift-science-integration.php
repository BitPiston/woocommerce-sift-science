<?php

if ( ! defined('ABSPATH') ) exit;

/**
 * Sift Science integration class
 *
 * @extends WC_Integration
 */
class WC_Sift_Science_Integration extends WC_Integration
{
    /**
     * @var string Plugin basename.
     */
    protected $plugin_basename = 'woocommerce-sift-science';

    /**
     * @var string Sift Science JavaScript snippet key.
     */
    protected $js_key;

    /**
     * @var string Sift Science REST API key.
     */
    protected $api_key;

    /**
     * Initialize the integration.
     *
     * @return void
     */
    public function __construct()
    {
        $this->id                 = 'sift-science';
        $this->method_title       = __('Sift Science', $this->plugin_basename);
        $this->method_description = __('Sift Science anti-fraud / chargeback prevention plugin for WooCommerce.', $this->plugin_basename);

        $this->init_form_fields();
        $this->init_settings();
        $this->init_hooks();

        $this->js_key  = defined('SIFT_JS_KEY')  ? SIFT_JS_KEY  : $this->get_option('sift_js_key');
        $this->api_key = defined('SIFT_API_KEY') ? SIFT_API_KEY : $this->get_option('sift_api_key');
    }

    /**
     * Hook our actions into WooCommerce.
     *
     * @return void
     */
    public function init_hooks()
    {
        // Enqueue admin scripts and styles
        //add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_assets']);

        // Save integration settings
        add_action('woocommerce_update_options_integration_' . $this->id, [$this, 'process_admin_options']);
    }

    /**
     * Enqueue admin scripts and styles.
     *
     * @return void
     */
    public function admin_enqueue_assets()
    {
        if ( ! is_admin() ) return;
    }

    /**
     * Initialize settings form fields.
     *
     * @return void
     */
    public function init_form_fields()
    {
        $key_description = __('You can find your key from: <a href="https://siftscience.com/console/developer/api-keys" target="_blank">Sift Science > Console > Developer > API Keys</a>.', $this->plugin_basename);

        $sift_js_key_field = [
            'title'       => __('Javascript Snippet Key:', $this->plugin_basename),
            'description' => $key_description,
            'type'        => 'text'
        ];

        if ( defined('SIFT_JS_KEY') ) {
            $sift_js_key_field['disabled'] = true;
        }

        $sift_api_key_field = [
            'title'       => __('REST API Key:', $this->plugin_basename),
            'description' => $key_description,
            'type'        => 'text'
        ];

        if ( defined('SIFT_API_KEY') ) {
            $sift_api_key_field['disabled'] = true;
        }

        $this->form_fields = [
            'sift_js_key'  => $sift_js_key_field,
            'sift_api_key' => $sift_api_key_field
        ];
    }
}
