<?php

// Quick and dirty server that listens for MQTT messages. RFID entry, Door status changes, RFID checkin etc..
// Very quick code. i.e. just get it working!!
// Author: dtbaker
// Date: 2016-08-30

// Door is expecting these results via MQTT:
define('_DOOR_INVALID_DEVICE', 0);
define('_DOOR_INVALID_KEY', 1);
define('_DOOR_EXPIRED_KEY', 2);
define('_DOOR_EXPIRING_KEY', 3);
define('_DOOR_VALID_KEY', 4);

define('_VENDING_INVALID_KEY', 1);

require 'inc.php';

require(_DOOR_PATH."phpMQTT.php");

global $mqtt;
$mqtt = new phpMQTT("localhost", 1883, "PHPServer");
if(!$mqtt->connect()){
	echo "Unable to connect to mosquito.";
	exit(1);
}

//$reply = wordpress_api('1111111/ci');

$device_history = []; // so we don't flood the things.

function process_message($topic, $message){
	echo "Got topic: $topic \n";
	switch($topic){
		case 'techspace/devices':
			$bits = explode(";",$message); // e.g. room-3;121323123123
			if(count($bits)==2){
				$member = get_member_by_rfid($bits[1]);
				if($member) {
					// success! found a cached member detail.
					// see if the member has access to this device.
					$has_access = false;
					if(!empty($member['access'])){
						foreach($member['access'] as $access_key => $access_name){
							if($bits[0] && $access_key && $access_key == $bits[0]){
								$has_access = true;
							}
						}
					}
					if($has_access) {
						// see if they are within expiry.
						if ( (int) $member['membership_expiry_days'] > 7 ) {
							// membership all good.
							mqtt_device_reply( $bits[0], _DOOR_VALID_KEY );
						} else if ( (int) $member['membership_expiry_days'] > 1 ) {
							// about to expire
							mqtt_device_reply( $bits[0], _DOOR_EXPIRING_KEY );
						} else {
							// membership expired.
							mqtt_device_reply( $bits[0], _DOOR_EXPIRED_KEY );
						}
					}else{
						// member doesn't have access to this device.
						mqtt_device_reply( $bits[0], _DOOR_INVALID_DEVICE );
					}
				}else{
					// unknown key, not linked to a current member.
					mqtt_device_reply( $bits[0], _DOOR_INVALID_KEY );
				}

				// now we have to push this up to the server so we get a log of it.
				// gctechspace.org/api/rfid/RFID_KEY/DEVICE_NAME
				$reply = wordpress_api($bits[1].'/'.$bits[0]);

			}else{
				echo "Inavlid message\n";
			}
			break;
		case 'alive':
			if(preg_match('#(.*)-(\d+\.\d+\.\d+\.\d+)#', $message, $alive)){

				$door_name = $alive[1];
				$door_ip_address = $alive[2];
				mqtt_device_reply( $door_name, "Received $door_name at $door_ip_address" );
				$reply = wordpress_api_door_status($door_name, $door_ip_address);

			}
			break;
		case 'techspace/doors':
			$bits = explode(";",$message); // e.g. book;open
			if(count($bits)==2){
				$door_name = $bits[0];
				if(empty($device_history[$door_name])){
					$device_history[$door_name] = array();
				}
				$now = time();
				if(isset($device_history[$door_name][$now])){
					$device_history[$door_name][$now]++;
					echo 'Spamming: ' . $device_history[$door_name][$now] . "\n";
				}else {
					$device_history[$door_name][$now] = 0;
					$door_status = $bits[1];
					mqtt_device_reply( $door_name, "Received $door_status" );
					$reply = wordpress_api_door_status( $door_name, $door_status );
				}

			}else{
				echo "Inavlid message\n";
			}
			break;
		case 'techspace/vending/rfid':
			echo "GOT RFID CODE $message \n";
			$rfid_code = $message;
			if($rfid_code) {
				$member = get_member_by_rfid( $rfid_code );
				if ( $member ) {
					// valid membership found linked to RFID key.
					// check if they have any orders in wordpress.
					require_once 'vending.php';
					$VendingWoo = VendingWoo::get_instance();
					$VendingWoo->init();
					$member_orders = $VendingWoo->get_member_orders($member);

					if($member_orders){
						mqtt_reply( 'techspace/vending/dispatch', implode('',$member_orders) );
					}else{
						mqtt_reply( 'techspace/vending/dispatch', 0 );
					}

				}else{
					mqtt_reply( 'techspace/vending/dispatch', 1 );
				}
			}
			break;
		case 'techspace/vending/getproduct':
			// web url
			// cost
			// quantity remaining
			// item name.
			echo "GOT REQUEST FOR PRODUCT: $message \n";
			if($message){
				require_once 'vending.php';
				$VendingWoo = VendingWoo::get_instance();
				$VendingWoo->init();
				$product_details = $VendingWoo->get_product_at_location($message);
				if($product_details){
					mqtt_reply( 'techspace/vending/product', $message.'.1.' );
				}else{
					mqtt_reply( 'techspace/vending/product', $message.'.0.not found' );

				}
			}
			break;
		case 'techspace/devices/status':

			break;
		default:
			echo "Unknown topic $topic \n";
	}
}

function mqtt_device_reply( $device_name, $message ){
	global $mqtt;
	echo "Sending "."techspace/devices/".$device_name." message of: $message \n";
	$mqtt->publish("techspace/devices/".$device_name,$message,0);
}
function mqtt_reply( $topic, $message ){
	global $mqtt;
	echo "Sending $topic message of: $message \n";
	$mqtt->publish($topic,$message,0);
}
function mqtt_door_reply( $door_name, $message ){
	global $mqtt;
	echo "Sending "."techspace/doors/".$door_name." message of: $message \n";
	$mqtt->publish("techspace/doors/".$door_name,$message,0);
}


$topics['techspace/checkin/rfid'] = array("qos"=>0, "function"=>"process_message");
$topics['techspace/devices'] = array("qos"=>0, "function"=>"process_message");
$topics['techspace/doors'] = array("qos"=>0, "function"=>"process_message");
$topics['techspace/vending/rfid'] = array("qos"=>0, "function"=>"process_message");
$topics['techspace/lights'] = array("qos"=>0, "function"=>"process_message");
$topics['alive'] = array("qos"=>0, "function"=>"process_message");
$mqtt->subscribe($topics,0);

echo "Starting...\n";
while(true) {
	echo "Loop...\n";
	$run_until = time() + ( 60 * 5 ); // run for 1 min at a time. updating member details in between.
	update_member_db();
	while ( $run_until > time() && $mqtt->proc() ) {
		// naasty. but it works.
	}
}
$mqtt->close();
echo "All done";

