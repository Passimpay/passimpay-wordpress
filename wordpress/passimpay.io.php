<?php
/*
 * Plugin Name: WooCommerce Passimpay Payment Gateway
 * Plugin URI: https://passimpay.io/
 * Description: Take cryptocurrencies payments on your store.
 * Author: Dependab1e
 * Version: 1.0.0
 */
 add_filter( 'woocommerce_payment_gateways', function( $gateways ) {
	$gateways[] = 'WC_Passimpay_io'; // your class name is here
	return $gateways;
});

add_action( 'plugins_loaded', 'passimpay_init_gateway_class', 999 );
function passimpay_init_gateway_class() {

	class WC_Passimpay_io extends WC_Payment_Gateway {
 		public function __construct() {
			$this->id = 'passimpay';
			$this->icon = '/wp-content/plugins/passimpay.io/logo_en.svg';
			$this->has_fields = true;
			$this->method_title = 'Passimpay Payment Gateway';
			$this->method_description = '21 cryptocurrencies';
			$this->supports = [ 'products' ];
			$this->init_form_fields();
			$this->init_settings();
			$this->title = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			$this->enabled = $this->get_option( 'enabled' );
			$this->secret_key = $this->get_option( 'secret_key' );
			$this->platform_id = $this->get_option( 'platform_id' );			
			$this->rateusd = $this->get_option( 'rateusd' );			
			$this->mode    = $this->get_option( 'mode' );
			$this->instructions = '<h1>sdvdfvf svsdf vdfvsdf vsdfvsdfvsdfvsfd vsdfsv</h1>';
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_api_passimpay', array( $this, 'webhook' ) ); ### https://yourblogcoach.com/create-a-woocommerce-payment-gateway-plugin/#9_Payment_Gateway_Webhooks
			add_filter('woocommerce_thankyou_order_received_text', [ $this, 'thank_you_msg' ], 99 );
			add_action( 'woocommerce_thankyou_custom', array( $this, 'thankyou_page' ) );		
		}

 		public function init_form_fields(){
			$this->form_fields = array(
				'enabled' => array(
					'title'       => 'Enable/Disable',
					'label'       => 'Enable Passimpay Payment',
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				),
				'rateusd' => array(
					'title'       => 'USD rate',
					'type'        => 'number',
					'custom_attributes' => array( 'step' => 'any', 'min' => '0' ),
					'description' => 'Rate your main currency to USD',
					'default'     => 1,
					'desc_tip'    => true,
				),
				'title' => array(
					'title'       => 'Title',
					'type'        => 'text',
					'description' => 'This controls the title which the user sees during checkout.',
					'default'     => 'Passimpay Payment',
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => 'Description',
					'type'        => 'textarea',
					'description' => 'This controls the description which the user sees during checkout.',
					'default'     => 'Pay with your prefered cryptocurrency via our payment gateway.',
				),
				'secret_key'      => array(
					'title'       => 'Secret Key',
					'type'        => 'password'
				),
				'platform_id' => array(
					'title'       => 'Platform Id',
					'type'        => 'text'
				),
				'mode' => array(
					'title'       => 'Mode',
					'type'        => 'select',
					'description' => '',
					'options'     => [ 1 => 'obtain address for payment', 2 => 'redirect to order page'  ],
                    'required'    => true,
					'default'     => 1,
				),
			);
	 	}

		public function payment_fields() {
				 
			echo '<fieldset id="wc-' . esc_attr( $this->id ) . '-cc-form" class="wc-credit-card-form wc-payment-form">';
			do_action( 'woocommerce_credit_card_form_start', $this->id );
			if( 1==$this->mode ) {
				$ttlusd = $this->get_order_total() / $this->rateusd;
				$list = $this->getCurList();
				echo '<div class="form-row form-row-wide">
					<label>', _('Choose your type'), '<span class="required">*</span></label>
						<select name="passimpay_id" onchange="jQuery(document.body).trigger(\'update_checkout\')">';
				@session_start();
				foreach($list['list'] as $c) {
					$active = $_SESSION['passimpay_id'] == $c['id'];
					$cost = $ttlusd / $c['rate_usd'];
					echo '<option value="' .$c['id']. '"'.($active?'selected':'').'>' .$c['name']. ' ' . $c['platform'] . ' - ' . $cost . ' ' .$c['currency']. '</option>';
				}
				echo '</select>
					</div>
					<div class="clear"></div><pre>';

				do_action( 'woocommerce_credit_card_form_end', $this->id );
				echo '<div class="clear"></div>';
			}
			echo '</fieldset>';
		}

		public function validate_fields() {

		}

		public function process_payment( $order_id ) 
		{
			global $woocommerce;
			$order = wc_get_order( $order_id );
			$ttlusd = $this->get_order_total() / $this->rateusd;
			if( 1==$this->mode )
			{
				#https://passimpay.io/developers/getaddress
				@session_start();
				$payment_id = $_POST['passimpay_id']??$_SESSION['passimpay_id'];
				$list = $this->getCurList();
				foreach($list['list'] as $c) {
					if ($payment_id == $c['id']) { $cost = $ttlusd / $c['rate_usd']; break; }
				}
				$data = [
					'payment_id' => $payment_id,
					'platform_id' => $this->platform_id,
					'order_id' => $order_id,
				];
				$payload = http_build_query($data);
				$data['hash'] = hash_hmac('sha256', $payload, $this->secret_key);
				$post_data = http_build_query($data);
				$curl = curl_init();
				curl_setopt($curl, CURLOPT_HEADER, false);
				curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
				curl_setopt($curl, CURLOPT_URL, 'https://passimpay.io/api/getpaymentwallet');
				curl_setopt($curl, CURLOPT_POST, true);
				curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
				curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
				$result = curl_exec($curl);
				curl_close( $curl );
				$result = json_decode($result, true);
				$data['amount'] 	= $cost;
				$data['amount_usd'] = $ttlusd;
				$data['address']    = $result['address'];
				$data['paysys'] 	= $c;
				$data['mode']		= 1;
				
				if($result['result']) {
					update_post_meta( $order_id, '_passimpay', $data );
					$order->payment_complete();
					$order->reduce_order_stock();
					$order->add_order_note( 'Use this address for a payment: ' . $data['address'], true );
					$woocommerce->cart->empty_cart();
					return [ 'result' => 'success', 'redirect' => $this->get_return_url( $order ) ];
				} else {
					wc_add_notice(  'Error in payment process! Try again later.', 'error' );
					return;
				}
			}
			elseif( 2==$this->mode )
			{
				# https://passimpay.io/developers/createorder
				$data = [
					'platform_id' => $this->platform_id,
					'order_id' => $order_id,
					'amount' => number_format($ttlusd, 2, '.', ''),
				];
				$payload = http_build_query($data);
				$data['hash'] = hash_hmac('sha256', $payload, $this->secret_key);
				$post_data = http_build_query($data);
				$curl = curl_init();
				curl_setopt($curl, CURLOPT_HEADER, false);
				curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
				curl_setopt($curl, CURLOPT_URL, 'https://passimpay.io/api/createorder');
				curl_setopt($curl, CURLOPT_POST, true);
				curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
				curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
				$result = curl_exec($curl);
				curl_close( $curl );
				$result = json_decode($result, true);
				
				if (isset($result['result']) && $result['result'] == 1)
				{
					$data['url']  = $result['url'];
					$data['mode'] = 2;
					$order->add_order_note( 'Use this url for a payment: ' . $data['url'], true );
					update_post_meta( $order_id, '_passimpay', $data );
					$woocommerce->cart->empty_cart();
					return [ 'result' => 'success', 'redirect' => $data['url'] ];
				} else {
					wc_add_notice(  'Error in payment process: ' . $result['message'], 'error' );
					return;
				}
			}
		}
		/*
		 * In case you need a webhook, like PayPal IPN etc
		 */
		public function webhook() {
			
			$hash = $_POST['hash'];
			$data = [
				'platform_id' => (int) $_POST['platform_id'], // Platform ID
				'payment_id' => (int) $_POST['payment_id'], // currency ID
				'order_id' => (int) $_POST['order_id'], // Payment ID of your platform
				'amount' => $_POST['amount'], // transaction amount
				'txhash' => $_POST['txhash'], // transaction ID in the cryptocurrency network
				'address_from' => $_POST['address_from'], // sender address
				'address_to' => $_POST['address_to'], // recipient address
				'fee' => $_POST['fee'], // network fee
			];
			if (isset($_POST['confirmations'])) $data['confirmations'] = $_POST['confirmations']; // number of network confirmations (Bitcoin, Litecoin, Dogecoin, Bitcoin Cash)
			$payload = http_build_query($data);
			if (!isset($hash) || hash_hmac('sha256', $payload, $this->secret_key) != $hash)
				$order->add_order_note( 'Passimpay: Hash broken for order '.$_REQUEST['order_id'], true );
			else {
				global $woocommerce;
				$order = wc_get_order( $_REQUEST['order_id'] );
	  			$props = get_post_meta( $order_id, '_passimpay', 1 );
				if( $props['passimpay']['amount'] > $_REQUEST['amount'] )
					$order->add_order_note( 'Passimpay: Payed only part of order #' .$_REQUEST['order_id']. ': '. $_REQUEST['amount'] .' '. $props['passimpay']['paysys']['currency'] );
		        else {
					$order->payment_complete( $_POST['xhash'] );
				}
			}
	 	}

		private function getCurList()
		{
			$url = 'https://passimpay.io/api/currencies';
			$payload = http_build_query(['platform_id' => $this->platform_id ]);
			$hash = hash_hmac('sha256', $payload, $this->secret_key);
			$data = [ 'platform_id' => $this->platform_id, 'hash' => $hash,];
			$post_data = http_build_query($data);
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_HEADER, false);
			curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
			curl_setopt($curl, CURLOPT_URL, $url);
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
			curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
			$result = curl_exec($curl);
			curl_close( $curl );
			$result = json_decode($result, true);
			#echo '<pre>', print_r($result,1); exit;
			return $result;
		}

		function thank_you_msg( $thank_you_msg ) {
			$order_id = wc_get_order_id_by_order_key($_GET['key']);
			$data = get_post_meta( $order_id, '_passimpay', 1 );
			$thank_you_msg = '<div><img src="' . 'https://chart.googleapis.com/chart?cht=qr&chld=H|1&chs=120&chl='.urlencode($data['address']). '" height="120" style="float:left; margin-right:30px">';
			$thank_you_msg.= '<p>Adress for a payment: <strong>' . $data['address'] . '</strong><br>Payment system: '.$data['paysys']['name'].'<br>
				Total: '.$data['amount'].' '.$data['paysys']['currency'].' / '. $data['paysys']['platform'] .'</p></div><div style="clear:both;height:10px"></div>';
			return $thank_you_msg;
		}

 	}
};


add_action('woocommerce_checkout_update_order_review', 'passimpay_checkout_field_process', 0);
function passimpay_checkout_field_process($a)
{
	parse_str($a, $b);
	
	if($b['payment_method'] == 'passimpay') {
		@session_start();
		$_SESSION['passimpay_id'] = $b['passimpay_id'];
	}
}