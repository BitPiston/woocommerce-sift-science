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

        // Output JavaScript snippet in the footer
        add_action('wp_footer', [$this, 'output_javascript_snippet']);

        // Save integration settings
        add_action('woocommerce_update_options_integration_' . $this->id, [$this, 'process_admin_options']);

        // Events / actions
        add_action('wp_login', [$this, 'login_action'], 10, 2);
        add_action('wp_login_failed', [$this, 'login_failed_action'], 10, 1);
        add_action('wp_logout', [$this, 'logout_action']);
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

    /**
     * Logging method.
     *
     * @param string $message
     * @return void
     */
    public function log($message)
    {
        if ( empty($this->log) ) {
            $this->log = new WC_Logger();
        }

        $this->log->add($this->id, $message);
    }

    /**
     * Get the user_id for sift science. We use the user's email address to support guest checkout and multiple sites.
     *
     * @param int|string|WP_User $user WP user ID, email or login to fetch by. Optional.
     * @return string User's email address or empty.
     */
    public function get_user_id($user = false)
    {
        if ( $user ) {
            if ( is_numeric($user) ) {
                $user = get_user_by('ID', $user);
            } elseif ( is_email($user) ) {
                $user = get_user_by('email', $user);
            } elseif ( ! is_object($user) ) {
                $user = get_user_by('login', $user);
            } elseif ( 'WP_User' != get_class($user) ) {
                $user = false;
            }
        } elseif ( is_user_logged_in() ) {
            $user = wp_get_current_user();
        }

        return false != $user ? strtolower($user->user_email) : '';
    }

    /**
     * Get the session_id for sift science. Uses WC_Session_Handler to inherit WooCommerce sessions or create them.
     *
     * @return string Prefixed session_key for the user.
     */
    public function get_session_id()
    {
        global $table_prefix;

        $session_prefix = is_multisite() ? get_current_blog_id() : $table_prefix;

        if ( ! WC()->session->has_session() ) {
            WC()->session->set_customer_session_cookie(true);
        }

        $session_key = WC()->session->get_customer_id();

        return $session_prefix . $session_key;
    }

    /**
     * Outputs the JavaScript snippet.
     *
     * @return void
     */
    public function output_javascript_snippet()
    {
        if ( is_admin() ) return;

        ?>
        <script>
          var _user_id = '<?php echo esc_js( $this->get_user_id() ); ?>';
          var _session_id = '<?php echo esc_js( $this->get_session_id() ); ?>';

          var _sift = window._sift = window._sift || [];
          _sift.push(['_setAccount', '<?php echo esc_js($this->js_key); ?>']);
          _sift.push(['_setUserId', _user_id]);
          _sift.push(['_setSessionId', _session_id]);
          _sift.push(['_trackPageview']);

          (function(d, s, id) {
            var e, f = d.getElementsByTagName(s)[0];
            if (d.getElementById(id)) {return;}
            e = d.createElement(s); e.id = id;
            e.src = 'https://cdn.siftscience.com/s.js';
            f.parentNode.insertBefore(e, f);
          })(document, 'script', 'sift-beacon');
        </script>
        <?php
    }

    /**
     * Performs a REST API call to Sift Science via SiftClient.
     *
     * @param string $event Event name, required.
     * @param array $properties Name-value pairs of event-specific attributes, required.
     * @param bool $return_action Return the score_response object with user score and labels, defaults to false.
     * @return null|SiftResponse
     */
    public function api_call($event, $properties, $return_action = false)
    {
        $client = new SiftClient($this->api_key);

        $response = $client->track($event, $properties, $client::DEFAULT_TIMEOUT, null, false, $return_action);

        if (0 != $response->apiErrorMessage) {
            $this->log($response->apiErrorMessage);
        }

        return $response;
    }

    /**
     * Helper to perform the login events.
     *
     * @param string $username '$success' or '$failure', required.
     * @param string|WP_User $user_or_name User login name or user object.
     * @return void
     */
    public function login_event($status, $user_or_name = null)
    {
        $this->api_call('$login', [
            '$user_id'      => $this->get_user_id($user_or_name),
            '$session_id'   => $this->get_session_id(),
            '$login_status' => $status
        ]);
    }

    /**
     * Calls the login event as successful at the wp_login action.
     *
     * @param string $username WP user login name.
     * @param WP_User $user WP user object.
     * @return void
     */
    public function login_action($username, $user)
    {
        $this->login_event('$success', $user);
    }

    /**
     * Calls the login event as failed at the wp_login_failed action.
     *
     * @param string $username WP user login name.
     * @return void
     */
    public function login_failed_action($username)
    {
        $this->login_event('$failure', $username);
    }

    /**
     * Calls the logout event at the wp_logout action.
     *
     * @return void
     */
    public function logout_action()
    {
        $this->api_call('$logout', [
            '$user_id' => $this->get_user_id(),
        ]);
    }
}
