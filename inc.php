<?php


define('_DOOR_PATH','/opt/php-door/');

function wordpress_api($endpoint, $log_data = array()){
	// we grab the latest list of members from the techspace wordpress api.
	// cache these into the members.json file
	$ch = curl_init("https://gctechspace.org/api/rfid/".$endpoint);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_HEADER, false);
	curl_setopt($ch, CURLOPT_POST, 1);
	$settings = json_decode(file_get_contents(_DOOR_PATH.'settings.json'),true);
	$post_data = array(
		'secret' => $settings['api_secret'],
	);
	$post_data = array_merge($post_data, $log_data);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
	$data = curl_exec($ch);
	return $data;
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

function get_member_by_email($email){
	if(!$email)return false;
	$email = strtolower($email);
	$members = json_decode(file_get_contents(_DOOR_PATH.'members.json'), true);
	foreach($members as $member){
		if(!empty($member['member_email']) && $email == strtolower($member['member_email'])){
			return $member;
		}
	}
	return false;
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