<?php

// some code to link the woocommerce install with the vending machine.


class VendingWoo {

	public $woocommerce_key='';
	public $woocommerce_secret='';

	private static $instance = null;

	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	public function init() {
		$settings = json_decode(file_get_contents(_DOOR_PATH.'settings.json'),true);
		if(!empty($settings['woocommerce_key'])){
			$this->woocommerce_key = $settings['woocommerce_key'];
		}
		if(!empty($settings['woocommerce_secret'])){
			$this->woocommerce_secret = $settings['woocommerce_secret'];
		}
	}

	public function get_member_orders( $member ){
		$purchased_items = array();
		if(!empty($member['member_email'])){
			$response = $this->api('/wc-api/v3/customers/email/' . $member['member_email']);
			$woomember = json_decode($response,true);
			if( $woomember && $woomember['customer'] && $woomember['customer']['id']){
				echo "\n\n";
				echo "Got member: \n";
				print_r($woomember['customer']);
				echo "\n\n";
				// Get orders for this customer.
				$response = $this->api('/wc-api/v3/customers/' . $woomember['customer']['id'] . '/orders?filter[expand]=products');
				$wooorders = json_decode($response,true);
				if($wooorders && !empty($wooorders['orders'])){
					foreach($wooorders['orders'] as $order){
						if($order['status'] == 'processing'){ // pending cancelled
							// mark the order as
							$mark_order_finished = false;
							if($order['line_items']){
								foreach($order['line_items'] as $line_item){
									if(!empty($line_item['product_data']) && !empty($line_item['product_data']['attributes'])){
										foreach($line_item['product_data']['attributes'] as $attribute){
											if(!empty($attribute['name']) && strtolower($attribute['name']) === 'code' && !empty($attribute['options'])){
												$vending_code = trim(current($attribute['options']));
												if($vending_code){
													$mark_order_finished = true;
													$purchased_items[] = $vending_code;
												}
											}
										}
									}
								}
							}
							echo "Order id: ".$order['id']."\n";
							if($mark_order_finished && $order['id'] != 487242 && $order['id'] != 487246){
								$response = $this->api('/wc-api/v3/orders/' . $order['id'], array(
									'order' => array(
										'status' => 'completed',
									)
								));
							}
						}
					}
					if($purchased_items){
						echo "User has purchased these items:\n";
						print_r($purchased_items);
					}
				}
			}

		}
		return $purchased_items;
	}

	public function api($endpoint, $data = false){
		$ch = curl_init('https://gctechspace.org' . $endpoint);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_USERPWD, $this->woocommerce_key . ":" . $this->woocommerce_secret);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		if($data){
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
		}
		$return = curl_exec($ch);
		curl_close($ch);
		return $return;
	}
}

