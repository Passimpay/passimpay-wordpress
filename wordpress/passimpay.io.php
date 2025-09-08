<?php
/*
 * Plugin Name: WooCommerce Passimpay Payment Gateway
 * Plugin URI: https://passimpay.io/
 * Description: Accept cryptocurrency payments via Passimpay. Supports Classic Checkout and WooCommerce Blocks.
 * Author: Dependab1e
 * Version: 1.2.1
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


function passimpay_remote_post( $url, $body_query ) {
    return wp_remote_post( $url, array(
        'timeout'     => 20,
        'body'        => $body_query,
        'headers'     => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
        'redirection' => 5,
        'httpversion' => '1.1',
    ) );
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

        public function __construct() {
            $this->id                 = 'passimpay';
            $this->icon               = '';
            $this->has_fields         = true;
            $this->method_title       = 'Passimpay Payment Gateway';
            $this->method_description = 'Accept 21+ cryptocurrencies via Passimpay.';
            $this->supports           = array( 'products' );

            $this->init_form_fields();
            $this->init_settings();

            $this->title        = $this->get_option( 'title', 'Passimpay' );
            $this->description  = $this->get_option( 'description', 'Pay with your preferred cryptocurrency via Passimpay.' );
            $this->enabled      = $this->get_option( 'enabled', 'no' );
            $this->secret_key   = $this->get_option( 'secret_key' );
            $this->platform_id  = $this->get_option( 'platform_id' );
            $this->rateusd      = floatval( $this->get_option( 'rateusd', 1 ) );
            $this->mode         = intval( $this->get_option( 'mode', 1 ) ); // 1=address, 2=redirect

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_api_passimpay', array( $this, 'webhook' ) );
            add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'thank_you_msg' ), 99, 2 );

            
            add_action( 'woocommerce_checkout_update_order_review', 'passimpay_checkout_field_process', 0 );

        
            add_action( 'wp_ajax_set_passimpay_id', array( $this, 'ajax_set_passimpay_id' ) );
            add_action( 'wp_ajax_nopriv_set_passimpay_id', array( $this, 'ajax_set_passimpay_id' ) );
        }

        public function init_form_fields(){
            // Генерируем реальный webhook URL для этого сайта
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
                'rateusd' => array(
                    'title' => 'USD rate',
                    'type'  => 'number',
                    'custom_attributes' => array( 'step' => 'any', 'min' => '0' ),
                    'description' => 'Rate of your shop currency to USD (e.g. if shop in EUR, put EUR→USD).',
                    'default'     => 1,
                    'desc_tip'    => true,
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'Shown to the customer at checkout.',
                    'default'     => 'Passimpay (Crypto)',
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

            $ttlusd     = WC()->cart ? ( WC()->cart->total / max( 0.0000001, $this->rateusd ) ) : 0;
            $current_id = WC()->session->get( 'passimpay_id' );

            echo '<fieldset id="wc-' . esc_attr( $this->id ) . '-form" class="wc-payment-form">';
            echo '<p>'.esc_html( $this->description ).'</p>';
            echo '<div class="form-row form-row-wide"><label>'.esc_html__( 'Choose currency / network', 'passimpay' ).' <span class="required">*</span></label>';
            echo '<select name="passimpay_id" onchange="jQuery(document.body).trigger(\'update_checkout\')">';

            foreach ( $list['list'] as $c ) {
                $cost  = $ttlusd / floatval( $c['rate_usd'] ?: 1 );
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
            if ( intval( $this->mode ) === 1 && empty( $_POST['passimpay_id'] ) && empty( WC()->session->get( 'passimpay_id' ) ) ) {
                wc_add_notice( __( 'Please choose a currency/network for Passimpay.', 'passimpay' ), 'error' );
                return false;
            }
            return true;
        }

        public function process_payment( $order_id ) {
            $order  = wc_get_order( $order_id );
            $ttlusd = floatval( $order->get_total() ) / max( 0.0000001, $this->rateusd );

            if ( intval( $this->mode ) === 1 ) {
                // Режим 1: получение адреса - НЕ создает order в Passimpay
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

                $cost = $ttlusd / floatval( $c['rate_usd'] ?: 1 );

                $data = array(
                    'payment_id'  => $payment_id,
                    'platform_id' => $this->platform_id,
                    'order_id'    => $order_id,
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
                        'amount_usd'  => $ttlusd,
                        'address'     => $json['address'],
                        'paysys'      => $c,
                        'mode'        => 1,
                        'payment_id'  => $payment_id,
                    );
                    
                    error_log('Passimpay Mode 1 - Saving Meta Data: ' . print_r($meta, true));
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
                // Режим 2: редирект - создает order в Passimpay
                $data = array(
                    'platform_id' => $this->platform_id,
                    'order_id'    => $order_id,
                    'amount'      => number_format( $ttlusd, 2, '.', '' ),
                );
                $payload       = http_build_query( $data );
                $data['hash']  = hash_hmac( 'sha256', $payload, $this->secret_key );
                $post_data     = http_build_query( $data );
                $result        = passimpay_remote_post( 'https://api.passimpay.io/createorder', $post_data );

                if ( is_wp_error( $result ) ) {
                    wc_add_notice( 'Passimpay error: '.$result->get_error_message(), 'error' );
                    return;
                }
                $json = json_decode( wp_remote_retrieve_body( $result ), true );

                if ( isset( $json['result'] ) && intval( $json['result'] ) === 1 && ! empty( $json['url'] ) ) {
                    $meta = array(
                        'url'  => esc_url_raw( $json['url'] ),
                        'mode' => 2,
                        'amount_usd' => $ttlusd,
                    );
                    
                    error_log('Passimpay Mode 2 - Saving Meta Data: ' . print_r($meta, true));
                    error_log('Passimpay Mode 2 - Full API Response: ' . print_r($json, true));
                    update_post_meta( $order_id, '_passimpay', $meta );

                    $order->update_status( 'pending', 'Passimpay: redirecting to Passimpay payment page.' );
                    WC()->cart->empty_cart();

                    return array( 'result' => 'success', 'redirect' => $json['url'] );
                } else {
                    $msg = isset( $json['message'] ) ? $json['message'] : 'Unknown error';
                    wc_add_notice( 'Error in payment process: ' . $msg, 'error' );
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
            
            // ИСПРАВЛЯЕМ: подпись формируется из строки platformId;JSON_BODY;secret
            $signature_string = intval($this->platform_id) . ';' . $payload . ';' . $this->secret_key;
            $signature = hash_hmac('sha256', $signature_string, $this->secret_key);
            
            error_log('Passimpay Check Order Request: ' . print_r($data, true));
            error_log('Passimpay Check Order Payload: ' . $payload);
            error_log('Passimpay Check Order Signature String: ' . $signature_string);
            error_log('Passimpay Check Order Signature: ' . $signature);
            
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
                error_log('Passimpay Check Order Status Error: ' . $response->get_error_message());
                return false;
            }
            
            $response_body = wp_remote_retrieve_body($response);
            $response_code = wp_remote_retrieve_response_code($response);
            
            error_log('Passimpay Check Order Response Code: ' . $response_code);
            error_log('Passimpay Check Order Response Body: ' . $response_body);
            
            $json = json_decode($response_body, true);
            error_log('Passimpay Status Response: ' . print_r($json, true));
            
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
                error_log('Passimpay Error: Order ID missing');
                status_header(400);
                exit('Order ID missing');
            }
            
            $order = wc_get_order($order_id);
            if (!$order) {
                error_log('Passimpay Error: Order not found - ' . $order_id);
                status_header(404);
                exit('Order not found');
            }

            // Проверяем подпись
            $payload = http_build_query($data);
            $calc = hash_hmac('sha256', $payload, $this->secret_key);
            
            if (!$hash || $calc != $hash) {
                $error_msg = 'Passimpay: invalid webhook signature. Calculated: ' . $calc . ', Received: ' . $hash;
                $order->add_order_note($error_msg);
                error_log($error_msg);
                status_header(403);
                exit('Invalid signature');
            }

            // Добавляем информацию о транзакции в заметки к заказу
            $transaction_msg = sprintf(
                'Passimpay transaction: %s, tx: %s, from: %s to: %s',
                $data['amount'],
                $data['txhash'],
                $data['address_from'],
                $data['address_to']
            );
            $order->add_order_note($transaction_msg);
            
            // Проверяем статус заказа через API Passimpay
            $status_response = $this->check_order_status($order_id);
            error_log('Passimpay API Status Response: ' . print_r($status_response, true));
            
            if ($status_response && isset($status_response['result']) && intval($status_response['result']) === 1) {
                // API ответил корректно - используем только его статус
                $payment_status = isset($status_response['status']) ? $status_response['status'] : '';
                $amount_paid = isset($status_response['amountPaid']) ? floatval($status_response['amountPaid']) : 0;
                $currency = isset($status_response['currency']) ? $status_response['currency'] : 'USD';
                
                error_log('Passimpay: API status: ' . $payment_status . ', Amount paid: ' . $amount_paid . ' ' . $currency);
                
                if ($payment_status === 'paid') {
                    // API подтверждает полную оплату
                    if ($order->get_status() !== 'completed' && $order->get_status() !== 'processing') {
                        $order->payment_complete($data['txhash']);
                        $success_msg = sprintf(
                            'Passimpay: Payment completed (API confirmed status: paid). Total paid: %s %s. Transaction: %s',
                            $amount_paid,
                            $currency,
                            $data['txhash']
                        );
                        $order->add_order_note($success_msg);
                        error_log($success_msg);
                    } else {
                        error_log('Passimpay: Order already completed, skipping payment_complete()');
                    }
                } else if ($payment_status === 'wait') {
                    // API показывает что еще ждем доплаты - только заметка, статус не меняем
                    $wait_msg = sprintf(
                        'Passimpay: Partial payment received (API status: wait). Paid so far: %s %s. Transaction: %s',
                        $amount_paid,
                        $currency,
                        $data['txhash']
                    );
                    $order->add_order_note($wait_msg);
                    error_log($wait_msg);
                } else {
                    // Неизвестный статус от API
                    $unknown_msg = sprintf(
                        'Passimpay: Unknown payment status: %s, Amount paid: %s %s. Transaction: %s',
                        $payment_status,
                        $amount_paid,
                        $currency,
                        $data['txhash']
                    );
                    $order->add_order_note($unknown_msg);
                    error_log($unknown_msg);
                }
            } else {
                // API не ответил или вернул ошибку
                $error_msg = 'Passimpay: Unable to verify payment status via API. Transaction recorded: ' . $data['txhash'];
                $order->add_order_note($error_msg);
                error_log($error_msg);
                
                // Не меняем статус заказа - пусть остается как есть до следующей проверки
            }
            
            status_header(200);
            exit('OK');
        }

        public function thank_you_msg( $text, $order ) {
            if ( ! $order || $order->get_payment_method() !== $this->id ) {
                return $text;
            }
            $data = get_post_meta( $order->get_id(), '_passimpay', true );
            if ( empty( $data['address'] ) || empty( $data['paysys'] ) ) {
                return $text;
            }
            $qr = 'https://payment.passimpay.io/qr-code/default/' . rawurlencode( $data['address'] );
            $html  = '<div style="display:flex;gap:20px;align-items:flex-start;margin:10px 0;">';
            $html .= '<img src="'.esc_url( $qr ).'" height="120" alt="QR">';
            $html .= '<p style="margin:0;">';
            $html .= 'Address for payment: <strong>'.esc_html( $data['address'] ).'</strong><br>';
            $html .= 'Payment system: '.esc_html( $data['paysys']['name'] ).'<br>';
            $html .= 'Total: '.esc_html( wc_format_decimal( $data['amount'], 8 ) ).' '.esc_html( $data['paysys']['currency'] ).' / '.esc_html( $data['paysys']['platform'] );
            $html .= '</p></div>';
            return $html;
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
    parse_str( $serialized_post, $data );
    if ( isset( $data['payment_method'] ) && $data['payment_method'] === 'passimpay' && ! empty( $data['passimpay_id'] ) ) {
        WC()->session->set( 'passimpay_id', sanitize_text_field( $data['passimpay_id'] ) );
    }
}


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
                $icon        = '';
                $mode        = $this->gateway ? intval( $this->gateway->mode ) : 1;

                $platform_id = isset( $this->settings['platform_id'] ) ? $this->settings['platform_id'] : '';
                $secret_key  = isset( $this->settings['secret_key'] )  ? $this->settings['secret_key']  : '';
                $rateusd     = isset( $this->settings['rateusd'] )     ? floatval( $this->settings['rateusd'] ) : 1;

                $currencies  = passimpay_api_get_currencies( $platform_id, $secret_key );
                $list        = isset( $currencies['list'] ) ? $currencies['list'] : array();

                $estUsdTotal = 0;
                if ( function_exists( 'WC' ) && WC()->cart ) {
                    $total       = WC()->cart->total;
                    $estUsdTotal = $rateusd > 0 ? ( $total / $rateusd ) : 0;
                }

                return array(
                    'gatewayId'    => 'passimpay',
                    'title'        => wp_kses_post( $title ),
                    'description'  => wp_kses_post( $description ),
                    'icon'         => $icon,
                    'mode'         => $mode,
                    'supports'     => array( 'features' => array( 'products' ) ),
                    'currencies'   => $list,
                    'estUsdTotal'  => $estUsdTotal,
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
