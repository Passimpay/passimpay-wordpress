<?php
/*
 * Plugin Name: WooCommerce Passimpay Payment Gateway
 * Plugin URI: https://passimpay.io/
 * Description: Accept cryptocurrency payments via Passimpay.
 * Author: PassimPay
 * Version: 2.0.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }


add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );


add_filter( 'woocommerce_payment_gateways', function( $gateways ) {
    $gateways[] = 'WC_Passimpay_Gateway';
    return $gateways;
}, 10, 1 );


function passimpay_remote_post( $url, $body_query, $is_json = false, $headers = array() ) {
    if ( $is_json ) {
        $headers['Content-Type'] = 'application/json';
        $body = $body_query;
    } else {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        $body = $body_query;
    }
    
    $args = array(
        'timeout'     => 30,
        'body'        => $body,
        'headers'     => $headers,
        'redirection' => 5,
        'httpversion' => '1.1',
        'user-agent'  => 'WooCommerce/' . WC()->version . '; ' . get_site_url()
    );
    
    $response = wp_remote_post( $url, $args );
    
    return $response;
}

function passimpay_api_get_currencies( $platform_id, $secret_key ) {
    if ( empty( $platform_id ) || empty( $secret_key ) ) {
        return array( 'list' => array() );
    }
    $url       = 'https://api.passimpay.io/currencies';
    $payload   = http_build_query( array( 'platform_id' => $platform_id ) );
    $hash      = hash_hmac( 'sha256', $payload, $secret_key );
    $post_data = http_build_query( array( 'platform_id' => $platform_id, 'hash' => $hash ) );
    $result    = passimpay_remote_post( $url, $post_data );
    if ( is_wp_error( $result ) ) {
        return array( 'list' => array() );
    }
    $json = json_decode( wp_remote_retrieve_body( $result ), true );
    return is_array( $json ) ? $json : array( 'list' => array() );
}


add_action( 'plugins_loaded', function() {

    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Passimpay</strong> requires WooCommerce to be activated.</p></div>';
        } );
        return;
    }

    if ( class_exists( 'WC_Passimpay_Gateway' ) ) { return; }

    class WC_Passimpay_Gateway extends WC_Payment_Gateway {
        
        public $secret_key;
        public $platform_id;
        public $mode;

        public function __construct() {
            $this->id                 = 'passimpay';
            $this->icon               = plugins_url( 'settings_logo_en.svg', __FILE__ );
            $this->has_fields         = true;
            $this->method_title       = 'Passimpay Payment Gateway';
            $this->method_description = 'Accept 21+ cryptocurrencies via Passimpay.';
            $this->supports           = array( 'products' );

            $this->init_form_fields();
            $this->init_settings();

            $this->title        = $this->get_option( 'title', 'Passimpay (Pay with cryptocurrencies)' );
            $this->description  = $this->get_option( 'description', 'Pay with your preferred cryptocurrency via Passimpay.' );
            $this->enabled      = $this->get_option( 'enabled', 'no' );
            $this->secret_key   = $this->get_option( 'secret_key' );
            $this->platform_id  = $this->get_option( 'platform_id' );
            $this->mode         = intval( $this->get_option( 'mode', 1 ) );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_api_passimpay', array( $this, 'webhook' ) );
            add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ), 10, 1 );
            add_action( 'wp_ajax_set_passimpay_id', array( $this, 'ajax_set_passimpay_id' ) );
            add_action( 'wp_ajax_nopriv_set_passimpay_id', array( $this, 'ajax_set_passimpay_id' ) );
        }

        public function init_form_fields(){
            $webhook_url = add_query_arg('wc-api', 'passimpay', home_url('/'));
            
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'label' => 'Enable Passimpay Payment',
                    'type'  => 'checkbox',
                    'default' => 'no'
                ),
                'webhook_url' => array(
                    'title' => 'Notification URL',
                    'type'  => 'text',
                    'description' => 'Copy this URL to your Passimpay platform settings',
                    'default' => $webhook_url,
                    'custom_attributes' => array('readonly' => 'readonly'),
                    'css' => 'width: 100%; font-family: monospace; background: #f9f9f9;'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'Shown to the customer at checkout.',
                    'default'     => 'Passimpay (Pay with cryptocurrencies)',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'Shown to the customer at checkout.',
                    'default'     => 'Pay with your preferred cryptocurrency via our Passimpay gateway.',
                ),
                'secret_key' => array(
                    'title' => 'Secret Key',
                    'type'  => 'password'
                ),
                'platform_id' => array(
                    'title' => 'Platform Id',
                    'type'  => 'text'
                ),
                'mode' => array(
                    'title'       => 'Mode',
                    'type'        => 'select',
                    'description' => '1 — generate address and show on Thank You; 2 — redirect to Passimpay order page.',
                    'options'     => array( 1 => 'Obtain address for payment', 2 => 'Redirect to Passimpay order page' ),
                    'default'     => 1,
                ),
            );
        }

        
        public function payment_fields() {
            if ( intval( $this->mode ) !== 1 ) {
                echo wpautop( wp_kses_post( $this->description ) );
                return;
            }

            $list = passimpay_api_get_currencies( $this->platform_id, $this->secret_key );
            if ( empty( $list['list'] ) || ! is_array( $list['list'] ) ) {
                echo '<p>'.esc_html__( 'Unable to load currencies. Please try again later.', 'passimpay' ).'</p>';
                return;
            }

            $cart_total  = WC()->cart ? WC()->cart->total : 0;
            $current_id = WC()->session->get( 'passimpay_id' );

            echo '<fieldset id="wc-' . esc_attr( $this->id ) . '-form" class="wc-payment-form">';
            echo '<p>'.esc_html( $this->description ).'</p>';
            echo '<div class="form-row form-row-wide"><label>'.esc_html__( 'Choose currency / network', 'passimpay' ).' <span class="required">*</span></label>';
            echo '<select name="passimpay_id" onchange="jQuery(document.body).trigger(\'update_checkout\')">';

            foreach ( $list['list'] as $c ) {
                $cost  = $cart_total / floatval( $c['rate_usd'] ?: 1 );
                $label = sprintf( '%s %s — ~%s %s', $c['name'], $c['platform'], wc_clean( wc_format_decimal( $cost, 8 ) ), $c['currency'] );
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr( $c['id'] ),
                    selected( $current_id, $c['id'], false ),
                    esc_html( $label )
                );
            }
            echo '</select></div></fieldset>';
        }

        public function validate_fields() {
            $chosen_payment_method = WC()->session->get('chosen_payment_method');
            if ( $chosen_payment_method !== $this->id ) {
                return true;
            }
            
            if ( intval( $this->mode ) === 1 && empty( $_POST['passimpay_id'] ) && empty( WC()->session->get( 'passimpay_id' ) ) ) {
                wc_add_notice( __( 'Please choose a currency/network for Passimpay.', 'passimpay' ), 'error' );
                return false;
            }
            return true;
        }

        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );

            if ( intval( $this->mode ) === 1 ) {
                $shop_currency = get_woocommerce_currency();
                $order_total   = floatval( $order->get_total() );
                
                $amount_for_calc = $order_total;
                
                $list = passimpay_api_get_currencies( $this->platform_id, $this->secret_key );

                $payment_id = null;
                if ( ! empty( $_POST['passimpay_id'] ) ) {
                    $payment_id = sanitize_text_field( wp_unslash( $_POST['passimpay_id'] ) );
                }
                if ( ! $payment_id ) {
                    $payment_id = WC()->session->get( 'passimpay_id' );
                }
                if ( ! $payment_id ) {
                    wc_add_notice( __( 'Please choose a currency/network for Passimpay.', 'passimpay' ), 'error' );
                    return;
                }

                $c = null;
                if ( ! empty( $list['list'] ) ) {
                    foreach ( $list['list'] as $_c ) {
                        if ( strval( $_c['id'] ) === strval( $payment_id ) ) { $c = $_c; break; }
                    }
                }
                if ( ! $c ) {
                    wc_add_notice( __( 'Selected currency is not available. Try again.', 'passimpay' ), 'error' );
                    return;
                }

                $cost = $amount_for_calc / floatval( $c['rate_usd'] ?: 1 );

                $data = array(
                    'paymentId'  => $payment_id,
                    'platformId' => $this->platform_id,
                    'orderId'    => strval($order_id),
                );
                $payload       = http_build_query( $data );
                $data['hash']  = hash_hmac( 'sha256', $payload, $this->secret_key );
                $post_data     = http_build_query( $data );
                $result        = passimpay_remote_post( 'https://api.passimpay.io/getpaymentwallet', $post_data );

                if ( is_wp_error( $result ) ) {
                    wc_add_notice( 'Passimpay error: '.$result->get_error_message(), 'error' );
                    return;
                }
                $json = json_decode( wp_remote_retrieve_body( $result ), true );

                if ( ! empty( $json['result'] ) && ! empty( $json['address'] ) ) {
                    $meta = array(
                        'amount'      => $cost,
                        'amount_shop' => $order_total,
                        'currency'    => $shop_currency,
                        'address'     => $json['address'],
                        'paysys'      => $c,
                        'mode'        => 1,
                        'payment_id'  => $payment_id,
                    );
                    
                    update_post_meta( $order_id, '_passimpay', $meta );

                    $order->update_status( 'on-hold', 'Passimpay: awaiting crypto payment.' );
                    WC()->cart->empty_cart();

                    return array(
                        'result'   => 'success',
                        'redirect' => $this->get_return_url( $order ),
                    );
                } else {
                    $msg = isset( $json['message'] ) ? $json['message'] : 'Unknown error';
                    wc_add_notice( 'Error in payment process! '.$msg, 'error' );
                    return;
                }

            } else {
                $shop_currency = get_woocommerce_currency();
                $order_total   = floatval( $order->get_total() );
                
                $request_data = array(
                    'platformId' => intval($this->platform_id),
                    'orderId'    => strval($order_id),
                    'amount'     => number_format( $order_total, 2, '.', '' ),
                    'symbol'     => strtoupper($shop_currency),
                    'type'       => 1,
                );
                
                $payload = json_encode($request_data, JSON_UNESCAPED_SLASHES);
                
                $signature_string = intval($this->platform_id) . ';' . $payload . ';' . $this->secret_key;
                $signature = hash_hmac('sha256', $signature_string, $this->secret_key);
                
                $headers = array(
                    'x-signature' => $signature,
                    'Content-Type' => 'application/json'
                );
                
                $result = passimpay_remote_post(
                    'https://api.passimpay.io/v2/createorder',
                    $payload,
                    true,
                    $headers
                );
                
                if ( is_wp_error( $result ) ) {
                    $error_msg = 'Passimpay connection error: ' . $result->get_error_message();
                    wc_add_notice( $error_msg, 'error' );
                    return;
                }
                
                $response_code = wp_remote_retrieve_response_code( $result );
                $response_body = wp_remote_retrieve_body( $result );

                $json = json_decode( $response_body, true );
                
                if ( isset( $json['result'] ) && intval( $json['result'] ) === 1 && ! empty( $json['url'] ) ) {
                    $meta = array(
                        'url'      => esc_url_raw( $json['url'] ),
                        'mode'     => 2,
                        'amount'   => $order_total,
                        'currency' => $shop_currency,
                    );
                    update_post_meta( $order_id, '_passimpay', $meta );

                    $order->update_status( 'pending', 'Passimpay: redirecting to Passimpay payment page.' );
                    WC()->cart->empty_cart();

                    return array( 'result' => 'success', 'redirect' => $json['url'] );
                } else {
                    $msg = isset( $json['message'] ) ? $json['message'] : 'Unknown error';
                    $full_error = 'Error in payment process: ' . $msg;
                    
                    wc_add_notice( $full_error, 'error' );
                    return;
                }
            }
        }

        private function check_order_status( $woocommerce_order_id ) {
            $data = array(
                'platformId' => intval($this->platform_id),
                'orderId'    => strval($woocommerce_order_id),
            );
            
            $payload = json_encode($data, JSON_UNESCAPED_SLASHES);
            
            $signature_string = intval($this->platform_id) . ';' . $payload . ';' . $this->secret_key;
            $signature = hash_hmac('sha256', $signature_string, $this->secret_key);
            
            $response = wp_remote_post('https://api.passimpay.io/v2/orderstatus', array(
                'timeout'     => 20,
                'body'        => $payload,
                'headers'     => array(
                    'x-signature'  => $signature,
                    'Content-Type' => 'application/json',
                ),
                'redirection' => 5,
                'httpversion' => '1.1',
            ));
            
            if ( is_wp_error( $response ) ) {
                return false;
            }
            
            $response_body = wp_remote_retrieve_body($response);
            $json = json_decode($response_body, true);
            
            return $json;
        }

        
        public function webhook() {
            $hash = isset($_POST['hash']) ? sanitize_text_field(wp_unslash($_POST['hash'])) : '';
            $data = array(
                'platform_id'   => isset($_POST['platform_id']) ? intval($_POST['platform_id']) : 0,
                'payment_id'    => isset($_POST['payment_id']) ? intval($_POST['payment_id']) : 0,
                'order_id'      => isset($_POST['order_id']) ? intval($_POST['order_id']) : 0,
                'amount'        => isset($_POST['amount']) ? $_POST['amount'] : 0,
                'txhash'        => isset($_POST['txhash']) ? sanitize_text_field(wp_unslash($_POST['txhash'])) : '',
                'address_from'  => isset($_POST['address_from']) ? sanitize_text_field(wp_unslash($_POST['address_from'])) : '',
                'address_to'    => isset($_POST['address_to']) ? sanitize_text_field(wp_unslash($_POST['address_to'])) : '',
                'fee'           => isset($_POST['fee']) ? sanitize_text_field(wp_unslash($_POST['fee'])) : '',
            );
            
            if (isset($_POST['confirmations'])) {
                $data['confirmations'] = intval($_POST['confirmations']);
            }

            $order_id = $data['order_id'];
            if (!$order_id) {
                status_header(400);
                exit('Order ID missing');
            }
            
            $order = wc_get_order($order_id);
            if (!$order) {
                status_header(404);
                exit('Order not found');
            }

            $payload = http_build_query($data);
            $calc = hash_hmac('sha256', $payload, $this->secret_key);
            
            if (!$hash || $calc != $hash) {
                $error_msg = 'Passimpay: invalid webhook signature. Calculated: ' . $calc . ', Received: ' . $hash;
                $order->add_order_note($error_msg);
                status_header(403);
                exit('Invalid signature');
            }

            $transaction_msg = sprintf(
                'Passimpay transaction: %s, tx: %s, from: %s to: %s',
                $data['amount'],
                $data['txhash'],
                $data['address_from'],
                $data['address_to']
            );
            $order->add_order_note($transaction_msg);
            
            $status_response = $this->check_order_status($order_id);
            
            if ($status_response && isset($status_response['result']) && intval($status_response['result']) === 1) {
                $payment_status = isset($status_response['status']) ? $status_response['status'] : '';
                $amount_paid = isset($status_response['amountPaid']) ? floatval($status_response['amountPaid']) : 0;
                $currency = isset($status_response['currency']) ? $status_response['currency'] : 'USD';
                
                if ($payment_status === 'paid') {
                    if ($order->get_status() !== 'completed' && $order->get_status() !== 'processing') {
                        $order->payment_complete($data['txhash']);
                        $success_msg = sprintf(
                            'Passimpay: Payment completed (API confirmed status: paid). Total paid: %s %s. Transaction: %s',
                            $amount_paid,
                            $currency,
                            $data['txhash']
                        );
                        $order->add_order_note($success_msg);
                    }
                } else if ($payment_status === 'wait') {
                    $wait_msg = sprintf(
                        'Passimpay: Partial payment received (API status: wait). Paid so far: %s %s. Transaction: %s',
                        $amount_paid,
                        $currency,
                        $data['txhash']
                    );
                    $order->add_order_note($wait_msg);
                } else {
                    $unknown_msg = sprintf(
                        'Passimpay: Unknown payment status: %s, Amount paid: %s %s. Transaction: %s',
                        $payment_status,
                        $amount_paid,
                        $currency,
                        $data['txhash']
                    );
                    $order->add_order_note($unknown_msg);
                }
            } else {
                $error_msg = 'Passimpay: Unable to verify payment status via API. Transaction recorded: ' . $data['txhash'];
                $order->add_order_note($error_msg);
            }
            
            status_header(200);
            exit('OK');
        }

        public function thankyou_page( $order_id ) {
            if ( ! $order_id ) {
                return;
            }
            
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return;
            }
            
            $data = get_post_meta( $order_id, '_passimpay', true );
            
            if ( ! is_array( $data ) || empty( $data['address'] ) || empty( $data['paysys'] ) ) {
                return;
            }
            
            if ( ! isset( $data['paysys']['name'] ) ||
                 ! isset( $data['paysys']['currency'] ) ||
                 ! isset( $data['paysys']['platform'] ) ) {
                return;
            }
            
            $qr = 'https://payment.passimpay.io/qr-code/default/' . rawurlencode( $data['address'] );
            ?>
            <div style="display:flex;gap:20px;align-items:flex-start;margin:20px 0;padding:20px;border:2px solid #ddd;border-radius:8px;background:#f9f9f9;">
                <img src="<?php echo esc_url( $qr ); ?>" height="150" alt="QR Code" style="border:2px solid #fff;border-radius:4px;">
                <div>
                    <h3 style="margin-top:0;">Cryptocurrency Payment Details</h3>
                    <p style="margin:5px 0;"><strong>Payment Address:</strong><br><code style="background:#fff;padding:8px;display:inline-block;margin-top:5px;border-radius:4px;word-break:break-all;"><?php echo esc_html( $data['address'] ); ?></code></p>
                    <p style="margin:5px 0;"><strong>Payment System:</strong> <?php echo esc_html( $data['paysys']['name'] ); ?></p>
                    <p style="margin:5px 0;"><strong>Amount:</strong> <?php echo esc_html( wc_format_decimal( $data['amount'], 8 ) ); ?> <?php echo esc_html( $data['paysys']['currency'] ); ?></p>
                    <p style="margin:5px 0;"><strong>Network:</strong> <?php echo esc_html( $data['paysys']['platform'] ); ?></p>
                    <p style="margin:10px 0 0 0;color:#666;font-size:13px;"><em>Scan the QR code with your crypto wallet or copy the address above.</em></p>
                </div>
            </div>
            <?php
        }

        public function ajax_set_passimpay_id() {
            check_ajax_referer( 'wc-passimpay', 'nonce' );
            $val = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
            WC()->session->set( 'passimpay_id', $val );
            wp_send_json_success( array( 'stored' => $val ) );
        }
    }
} );


function passimpay_checkout_field_process( $serialized_post ) {
    if ( ! function_exists( 'WC' ) || ! WC()->session ) {
        return;
    }
    
    parse_str( $serialized_post, $data );
    
    if ( ! isset( $data['payment_method'] ) ) {
        return;
    }
    
    if ( $data['payment_method'] === 'passimpay' && ! empty( $data['passimpay_id'] ) ) {
        WC()->session->set( 'passimpay_id', sanitize_text_field( $data['passimpay_id'] ) );
    }
    elseif ( $data['payment_method'] !== 'passimpay' && WC()->session->get( 'passimpay_id' ) ) {
        WC()->session->set( 'passimpay_id', null );
    }
}

add_action( 'woocommerce_checkout_update_order_review', 'passimpay_checkout_field_process', 10 );


add_action( 'woocommerce_blocks_loaded', function () {

    if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }

    if ( ! class_exists( 'WC_Passimpay_Blocks_Integration' ) ) {
        class WC_Passimpay_Blocks_Integration extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
            protected $name = 'passimpay';
            /** @var WC_Passimpay_Gateway */
            private $gateway;

            public function initialize() {
                $this->settings = get_option( 'woocommerce_passimpay_settings', array() );
                
                if ( class_exists( 'WC_Passimpay_Gateway' ) ) {
                    $this->gateway = new \WC_Passimpay_Gateway();
                }
            }

            public function is_active() {
                return $this->gateway ? $this->gateway->is_available() : false;
            }

        
            public function get_payment_method_script_handles() {
                wp_register_script(
                    'wc-passimpay-blocks',
                    false,
                    array( 'wc-blocks-registry', 'wp-element', 'wp-i18n', 'wc-settings' ),
                    '1.2.1',
                    true
                );

                $inline = <<<'JS'
(function(){
    var reg = (window.wc && window.wc.wcBlocksRegistry) ? window.wc.wcBlocksRegistry : null;
    if (!reg || typeof reg.registerPaymentMethod !== 'function') { return; }

    var settings = (window.wc && window.wc.wcSettings && window.wc.wcSettings.getSetting) ? window.wc.wcSettings.getSetting('passimpay_data', {}) : {};
    if (!settings || !settings.gatewayId) { return; }

    function SelectField( props ) {
        var el = window.wp.element.createElement;
        var currencies = settings.currencies || [];
        var estUsd = Number(settings.estUsdTotal || 0);

        var _state = window.wp.element.useState('');
        var selected = _state[0];
        var setSelected = _state[1];

        function persist(value){
            if (!value || !settings.ajaxUrl) return;
            try {
                var form = new FormData();
                form.append('action','set_passimpay_id');
                form.append('value', value);
                form.append('nonce', settings.nonce || '');
                fetch(settings.ajaxUrl, {method:'POST', body: form, credentials:'same-origin'});
            } catch(e){}
        }

        function onChange(e){
            var value = e.target.value;
            setSelected(value);
            persist(value);
        }

        var options = currencies.map(function(c){
            var rate = Number(c.rate_usd || 1) || 1;
            var approx = rate ? (estUsd / rate) : 0;
            var label = c.name + " " + c.platform + (estUsd ? (" — ~" + approx.toFixed(8) + " " + c.currency) : "");
            return el('option', { key: String(c.id), value: String(c.id) }, label);
        });

        return el('div', { className: 'wc-passimpay-fields', style: { marginTop: '8px' } },
            settings.description ? el('p', null, settings.description) : null,
            Number(settings.mode) === 1
                ? el('label', null,
                    'Choose currency / network',
                    el('select', { onChange: onChange, required: true, style: { display:'block', marginTop:'6px', width: '100%' } },
                        el('option', { value: '' }, '— Select —'),
                        options
                    )
                  )
                : null
        );
    }

    var Label = (function(){
        var el = window.wp.element.createElement;
        var img = settings.icon ? el('img', { src: settings.icon, alt: '', style: { height: '18px', marginRight: '8px' } }) : null;
        return el('span', { style: { display: 'inline-flex', alignItems: 'center' } }, img, (settings.title || 'Passimpay'));
    })();

reg.registerPaymentMethod({
  name: settings.gatewayId,
  ariaLabel: settings.title || 'Passimpay',
  label: Label,
  content: window.wp.element.createElement( SelectField ),
  edit: window.wp.element.createElement( SelectField ),
  canMakePayment: function(){ return true; },
  supports: { features: (settings.supports && settings.supports.features) ? settings.supports.features : ['products'] }
});
})();
JS;
                wp_add_inline_script( 'wc-passimpay-blocks', $inline );

                return array( 'wc-passimpay-blocks' );
            }

            public function get_payment_method_script_handles_for_admin() {
                return $this->get_payment_method_script_handles();
            }

        
            public function get_payment_method_data() {
                $title       = $this->gateway ? $this->gateway->title       : 'Passimpay';
                $description = $this->gateway ? $this->gateway->description : '';
                $icon        = plugins_url( 'settings_logo_en.svg', __FILE__ );;
                $mode        = $this->gateway ? intval( $this->gateway->mode ) : 1;

                $platform_id = isset( $this->settings['platform_id'] ) ? $this->settings['platform_id'] : '';
                $secret_key  = isset( $this->settings['secret_key'] )  ? $this->settings['secret_key']  : '';

                $currencies  = passimpay_api_get_currencies( $platform_id, $secret_key );
                $list        = isset( $currencies['list'] ) ? $currencies['list'] : array();

                $cart_total = 0;
                if ( function_exists( 'WC' ) && WC()->cart ) {
                    $cart_total = WC()->cart->total;
                }

                return array(
                    'gatewayId'    => 'passimpay',
                    'title'        => wp_kses_post( $title ),
                    'description'  => wp_kses_post( $description ),
                    'icon'         => $icon,
                    'mode'         => $mode,
                    'supports'     => array( 'features' => array( 'products' ) ),
                    'currencies'   => $list,
                    'estUsdTotal'  => $cart_total,
                    'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                    'nonce'        => wp_create_nonce( 'wc-passimpay' ),
                );
            }
        }
    }

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            $payment_method_registry->register( new \WC_Passimpay_Blocks_Integration() );
        }
    );
} );
