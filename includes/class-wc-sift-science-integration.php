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
        add_action('user_register', [$this, 'create_account_action'], 10, 1);
        add_action('profile_update', [$this, 'update_account_action'], 10, 2);
        add_action('woocommerce_add_to_cart', [$this, 'add_to_cart_action'], 10, 6);
        add_action('woocommerce_remove_cart_item', [$this, 'remove_from_cart_action'], 10, 2);
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
            } elseif ( is_string($user) ) {
                if ( is_email($user) ) {
                    $user = get_user_by('email', $user);
                } else {
                    $user = get_user_by('login', $user);
                }
            } elseif ( is_object($user) && 'WP_User' != get_class($user) ) {
                $user = false;
            }
        } elseif ( is_user_logged_in() ) {
            $user = wp_get_current_user();
        }

        return false !== $user ? strtolower($user->user_email) : '';
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

        if ( 0 != $response->apiErrorMessage ) {
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

    /**
     * Helper to set the conditional profile / billing fields.
     *
     * @param array $data Request attributes. Required.
     * @param array $meta User's meta data. Required.
     * @return array $data Updated request attributes.
     */
    public function set_conditional_fields($data, $meta)
    {
        if ( isset($meta['first_name']) && isset($meta['last_name']) ) {
            $data['$name']                          = $meta['first_name'] . ' ' . $meta['last_name'];
        }
        if ( isset($meta['billing_first_name']) && isset($meta['billing_last_name']) ) {
            $data['$billing_address']['$name']      = $meta['billing_first_name'] . ' ' . $meta['billing_last_name'];
        }
        if ( isset($meta['billing_phone']) ) {
            $data['$billing_address']['$phone']     = $meta['billing_phone'];
        }
        if ( isset($meta['billing_address_1']) ) {
            $data['$billing_address']['$address_1'] = $meta['billing_address_1'];
        }
        if ( isset($meta['billing_address_2']) ) {
            $data['$billing_address']['$address_2'] = $meta['billing_address_2'];
        }
        if ( isset($meta['billing_city']) ) {
            $data['$billing_address']['$city']      = $meta['billing_city'];
        }
        if ( isset($meta['billing_state']) ) {
            $data['$billing_address']['$region']    = $meta['billing_state'];
        }
        if ( isset($meta['billing_country']) ) {
            $data['$billing_address']['$country']   = $meta['billing_country'];
        }
        if ( isset($meta['billing_postcode']) ) {
            $data['$billing_address']['$zipcode']   = $meta['billing_postcode'];
        }

        return $data;
    }

    /**
     * Calls the create_account event at the user_register action.
     *
     * @param int $user_id WP user ID.
     * @return void
     */
    public function create_account_action($user_id)
    {
        $data = [
            '$user_id'    => $this->get_user_id($user_id),
            '$session_id' => $this->get_session_id()
        ];
        $data['$user_email'] = $data['$user_id'];

        $meta = get_user_meta($user_id);

        $data = $this->set_conditional_fields($data, $meta);

        $this->api_call('$create_account', $data);
    }

    /**
     * Calls the update_account event at the profile_update action.
     *
     * @param int $user_id WP user ID.
     * @param WP_User $old_user Old WP user object before changes.
     * @return void
     */
    public function update_account_action($user_id, $old_user_data)
    {
        $user = get_user_by('id', $user_id);

        $data = [
            '$user_id'          => $this->get_user_id($user),
            '$session_id'       => $this->get_session_id(),
            '$changed_password' => isset($old_user_data->user_pass) && $user->user_pass !== $old_user_data->user_pass
        ];
        $data['$user_email'] = $data['$user_id'];

        $meta = get_user_meta($user_id);

        $data = $this->set_conditional_fields($data, $meta);

        $this->api_call('$update_account', $data);
    }

    /**
     * Formats the price as micros.
     *
     * @param float $price Price as decimal.
     * @return int Price as micros.
     */
    public function price_to_micros($price)
    {
        return $price * 1000000;
    }

    /**
     * Helper to get the $item attributes.
     *
     * @param int $product_id Product or variation ID.
     * @return int Quantity, defaults to 1.
     */
    public function get_item_fields($product_id, $quantity = 1)
    {
        $product = wc_get_product($product_id);

        $item = [
            '$item_id'        => $product_id,
            '$product_title'  => $product->get_title(),
            '$price'          => $this->price_to_micros( $product->get_price() ),
            '$currency_code'  => get_woocommerce_currency(),
            '$quantity'       => $quantity
        ];

        if ( $sku = $product->get_sku() ) {
            $item['$sku']      = $sku;
        }
        // Sift only accepts a single category as a string unlike tags so use the formatted list minus HTML?
        if ( $categories = get_the_terms($product_id, 'product_cat') ) {
            $category_names = [];

            foreach ($categories as $category) {
                $category_names[] = $category->name;
            }

            $item['$category'] = implode(', ', $category_names);
        }
        if ( $tags = get_the_terms($product_id, 'product_tag') ) {
            $item['$tags']     = $tags;
        }

        return $item;
    }

    /**
     * Calls the add_item_to_cart event at the woocommerce_add_to_cart action.
     *
     * @param int $item_key Cart item key.
     * @param int $product_id Product ID.
     * @param int $quantity Quantity.
     * @param int $variation_id Product variation ID.
	 * @param array $variation Product variation attributes.
	 * @param array $item_data Cart item data.
     * @return void
     */
    public function add_to_cart_action($item_key, $product_id, $quantity, $variation_id, $variation, $item_data)
    {
        $this->api_call('$add_item_to_cart', [
            '$user_id'    => $this->get_user_id(),
            '$session_id' => $this->get_session_id(),
            '$item'       => $this->get_item_fields( $variation_id ? $variation_id : $product_id, $quantity )
        ]);
    }

    /**
     * Calls the remove_item_from_cart event at the woocommerce_remove_cart_item action.
     *
     * @param int $item_key Cart item key.
     * @param \WC_Cart $cart Cart instance.
     * @return void
     */
    public function remove_from_cart_action($item_key, $cart)
    {
        $product_id = $cart->cart_contents[ $item_key ]['product_id'];
        $quantity   = $cart->cart_contents[ $item_key ]['quantity'];

        $this->api_call('$remove_item_from_cart', [
            '$user_id'    => $this->get_user_id(),
            '$session_id' => $this->get_session_id(),
            '$item'       => $this->get_item_fields($product_id, $quantity)
        ]);
    }
}
