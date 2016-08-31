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

define('_DOOR_PATH','/opt/php-door/');

require(_DOOR_PATH."phpMQTT.php");

global $mqtt;
$mqtt = new phpMQTT("localhost", 1883, "PHPServer");
if(!$mqtt->connect()){
	echo "Unable to connect to mosquito.";
	exit(1);
}

function process_message($topic, $message){
	echo "Got topic: $topic \n";
	switch($topic){
		case 'techspace/checkin/rfid':
			// push this checkin straight up to the wordpress api.
			// exepct the message to be the RFID key used for checkin.
			$result = wordpress_api($message.'/checkin');
			break;
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

function get_member_by_rfid($rfid){
	if(!$rfid)return false;
	$members = json_decode(file_get_contents(_DOOR_PATH.'members.json'), true);
	foreach($members as $member){
		if(!empty($member['rfid']) && is_array($member['rfid'])){
			foreach($member['rfid'] as $member_rfid){
				if($member_rfid == $rfid){
					return $member;
				}
			}
		}
	}
	return false;
}

function wordpress_api($endpoint){
	// we grab the latest list of members from the techspace wordpress api.
	// cache these into the members.json file
	$ch = curl_init("https://gctechspace.org/api/rfid/".$endpoint);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HEADER, false);
	curl_setopt($ch, CURLOPT_POST, 1);
	$settings = json_decode(file_get_contents(_DOOR_PATH.'settings.json'),true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, array(
		'secret' => $settings['api_secret'],
	));
	return curl_exec($ch);
}

function update_member_db(){
	$data = wordpress_api('all');
	if($data){
		// test to ensure it's valid json
		$foo = json_decode($data,true);
		if(is_array($foo) && count($foo) > 1){
			// all good. write this json data to cache.
			file_put_contents(_DOOR_PATH.'members.json', $data);
		}
	}
}
$topics['techspace/checkin/rfid'] = array("qos"=>0, "function"=>"process_message");
$topics['techspace/devices'] = array("qos"=>0, "function"=>"process_message");
$mqtt->subscribe($topics,0);

echo "Starting...\n";
while(true) {
	echo "Loop...\n";
	$run_until = time() + 60; // run for 1 min at a time. updating member details in between.
	update_member_db();
	while ( $run_until > time() && $mqtt->proc() ) {
		// naasty. but it works.
	}
}
$mqtt->close();
echo "All done";

