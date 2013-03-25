<?php

/*
	[UCenter] (C)2001-2008 Comsenz Inc.
	This is NOT a freeware, use is subject to license terms

	$Id: client.php 12180 2008-01-17 05:56:43Z heyond $
*/

if(!defined('UC_API')) {
	exit('Access denied');
}

error_reporting(7);

define('IN_UC', TRUE);
define('UC_VERSION', '1.0.0');				//用户中心版本标识

define('UC_ROOT', substr(__FILE__, 0, -10));		// 用户中心客户端的根目录 UC_CLIENTROOT

define('UC_DATADIR', UC_ROOT.'./data/');		// 用户中心的数据缓存目录
define('UC_DATAURL', UC_API.'/data');			// 用户中心的数据 URL

define('UC_API_FUNC', UC_CONNECT == 'mysql' ? 'uc_api_mysql' : 'uc_api_post');
$GLOBALS['uc_controls'] = array();

function uc_addslashes($string, $force = 0, $strip = FALSE) {
	!defined('MAGIC_QUOTES_GPC') && define('MAGIC_QUOTES_GPC', get_magic_quotes_gpc());
	if(!MAGIC_QUOTES_GPC || $force) {
		if(is_array($string)) {
			foreach($string as $key => $val) {
				$string[$key] = uc_addslashes($val, $force, $strip);
			}
		} else {
			$string = addslashes($strip ? stripslashes($string) : $string);
		}
	}
	return $string;
}

function uc_stripslashes($string) {
	!defined('MAGIC_QUOTES_GPC') && define('MAGIC_QUOTES_GPC', get_magic_quotes_gpc());
	if(MAGIC_QUOTES_GPC) {
		return stripslashes($string);
	} else {
		return $string;
	}
}

function uc_api_post($module, $action, $arg = array()) {
	$s = $sep = '';
	foreach($arg as $k => $v) {
		if(is_array($v)) {
			$s2 = $sep2 = '';
			foreach($v as $k2=>$v2) {
				$s2 .= "$sep2{$k}[$k2]=".urlencode(uc_stripslashes($v2));
				$sep2 = '&';
			}
			$s .= $sep.$s2;
		} else {
			$s .= "$sep$k=".urlencode(uc_stripslashes($v));
		}
		$sep = '&';
	}
	$postdata = uc_api_requestdata($module, $action, $s);
	return uc_fopen(UC_API.'/index.php', 500000, $postdata, '', TRUE, UC_IP, 20);
}

function uc_api_requestdata($module, $action, $arg='', $extra='') {
	$input = uc_api_input($arg);
	$post = "m=$module&a=$action&inajax=2&input=$input&appid=".UC_APPID.$extra;
	return $post;
}

function uc_api_url($module, $action, $arg='', $extra='') {
	$url = UC_API.'/index.php?'.uc_api_requestdata($module, $action, $arg, $extra);
	return $url;
}

function uc_api_input($data) {
	$s = urlencode(uc_authcode($data.'&agent='.md5($_SERVER['HTTP_USER_AGENT'])."&time=".time(), 'ENCODE', UC_KEY));
	return $s;
}

function uc_api_mysql($model, $action, $args=array()) {
	global $uc_controls;
	if(empty($uc_controls[$model])) {
		include_once UC_ROOT.'./lib/db.class.php';
		include_once UC_ROOT.'./model/base.php';
		include_once UC_ROOT."./control/$model.php";
		eval("\$uc_controls['$model'] = new {$model}control();");
	}
	if($action{0} != '_') {
		$args = uc_addslashes($args, 1, TRUE);
		$action = 'on'.$action;
		return $uc_controls[$model]->$action($args);
	} else {
		return '';
	}
}

function uc_serialize($arr, $htmlon = 0) {
	include_once UC_ROOT.'./lib/xml.class.php';
	return xml_serialize($arr, $htmlon);
}

function uc_unserialize($s) {
	include_once UC_ROOT.'./lib/xml.class.php';
	return xml_unserialize($s);
}

function uc_authcode($string, $operation = 'DECODE', $key = '', $expiry = 0) {

	$ckey_length = 4;	// 随机密钥长度 取值 0-32;
				// 加入随机密钥，可以令密文无任何规律，即便是原文和密钥完全相同，加密结果也会每次不同，增大破解难度。
				// 取值越大，密文变动规律越大，密文变化 = 16 的 $ckey_length 次方
				// 当此值为 0 时，则不产生随机密钥

	$key = md5($key ? $key : UC_KEY);
	$keya = md5(substr($key, 0, 16));
	$keyb = md5(substr($key, 16, 16));
	$keyc = $ckey_length ? ($operation == 'DECODE' ? substr($string, 0, $ckey_length): substr(md5(microtime()), -$ckey_length)) : '';

	$cryptkey = $keya.md5($keya.$keyc);
	$key_length = strlen($cryptkey);

	$string = $operation == 'DECODE' ? base64_decode(substr($string, $ckey_length)) : sprintf('%010d', $expiry ? $expiry + time() : 0).substr(md5($string.$keyb), 0, 16).$string;
	$string_length = strlen($string);

	$result = '';
	$box = range(0, 255);

	$rndkey = array();
	for($i = 0; $i <= 255; $i++) {
		$rndkey[$i] = ord($cryptkey[$i % $key_length]);
	}

	for($j = $i = 0; $i < 256; $i++) {
		$j = ($j + $box[$i] + $rndkey[$i]) % 256;
		$tmp = $box[$i];
		$box[$i] = $box[$j];
		$box[$j] = $tmp;
	}

	for($a = $j = $i = 0; $i < $string_length; $i++) {
		$a = ($a + 1) % 256;
		$j = ($j + $box[$a]) % 256;
		$tmp = $box[$a];
		$box[$a] = $box[$j];
		$box[$j] = $tmp;
		$result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
	}

	if($operation == 'DECODE') {
		if((substr($result, 0, 10) == 0 || substr($result, 0, 10) - time() > 0) && substr($result, 10, 16) == substr(md5(substr($result, 26).$keyb), 0, 16)) {
			return substr($result, 26);
		} else {
			return '';
		}
	} else {
		return $keyc.str_replace('=', '', base64_encode($result));
	}

}

function uc_fopen($url, $limit = 500000, $post = '', $cookie = '', $bysocket = FALSE, $ip = '', $timeout = 15, $block = TRUE) {

	$return = '';
	if(ini_get('allow_url_fopen') && !$bysocket && !$post) {
		if($fp = @fopen($url, 'r')) {
			$return = @fread($fp, $limit);
			@fclose($fp);
			return $return;
		}
	}

	$matches = parse_url($url);
	$host = $matches['host'];
	$path = $matches['path'] ? $matches['path'].'?'.$matches['query'].'#'.$matches['fragment'] : '/';
	$port = !empty($matches['port']) ? $matches['port'] : 80;

	if($post) {
		$out = "POST $path HTTP/1.0\r\n";
		$out .= "Accept: */*\r\n";
		//$out .= "Referer: $boardurl\r\n";
		$out .= "Accept-Language: zh-cn\r\n";
		$out .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$out .= "User-Agent: $_SERVER[HTTP_USER_AGENT]\r\n";
		$out .= "Host: $host\r\n";
		$out .= 'Content-Length: '.strlen($post)."\r\n";
		$out .= "Connection: Close\r\n";
		$out .= "Cache-Control: no-cache\r\n";
		$out .= "Cookie: $cookie\r\n\r\n";
		$out .= $post;
	} else {
		$out = "GET $path HTTP/1.0\r\n";
		$out .= "Accept: */*\r\n";
		//$out .= "Referer: $boardurl\r\n";
		$out .= "Accept-Language: zh-cn\r\n";
		$out .= "User-Agent: $_SERVER[HTTP_USER_AGENT]\r\n";
		$out .= "Host: $host\r\n";
		$out .= "Connection: Close\r\n";
		$out .= "Cookie: $cookie\r\n\r\n";
	}

	$fp = @fsockopen(($ip ? $ip : $host), $port, $errno, $errstr, $timeout);
	if(!$fp) {
		return '';
	} else {
		@stream_set_blocking($fp, $block);
		@stream_set_timeout($fp, $timeout);
		@fwrite($fp, $out);
		$status = stream_get_meta_data($fp);
		if($status['timed_out']) {
			return '';
		}
		$return = fread($fp, 524);
		$limit -= strlen($return);
		while(!feof($fp) && $limit > -1) {
			$limit -= 100524;
			$return .= @fread($fp, 100524);
		}
		@fclose($fp);
		$return = preg_replace("/\r\n\r\n/", "\n\n", $return, 1);
		$strpos = strpos($return, "\n\n");
		$strpos = $strpos !== FALSE ? $strpos + 2 : 0;
		$return = substr($return, $strpos);
		return $return;
	}
}

function uc_app_ls() {
	$return = call_user_func(UC_API_FUNC, 'app', 'ls', array());
	return UC_CONNECT == 'mysql' ? $return : uc_unserialize($return);
}

function uc_feed_add($icon, $uid, $username, $title_template='', $title_data='', $body_template='', $body_data='', $body_general='', $target_ids='', $images = array()) {
	call_user_func(UC_API_FUNC, 'feed', 'add',
		array(  'icon'=>$icon,
			'appid'=>UC_APPID,
			'uid'=>$uid,
			'username'=>$username,
			'title_template'=>$title_template,
			'title_data'=>$title_data,
			'body_template'=>$body_template,
			'body_data'=>$body_data,
			'body_general'=>$body_general,
			'target_ids'=>$target_ids,
			'image_1'=>$images[0]['url'],
			'image_1_link'=>$images[0]['link'],
			'image_2'=>$images[1]['url'],
			'image_2_link'=>$images[1]['link'],
			'image_3'=>$images[2]['url'],
			'image_3_link'=>$images[2]['link'],
			'image_4'=>$images[3]['url'],
			'image_4_link'=>$images[3]['link']
		)
	);
}

function uc_feed_get($limit = 100) {
	$return = call_user_func(UC_API_FUNC, 'feed', 'get', array('limit'=>$limit));
	return UC_CONNECT == 'mysql' ? $return : uc_unserialize($return);
}

function uc_friend_add($uid, $friendid, $comment='') {
	return call_user_func(UC_API_FUNC, 'friend', 'add', array('uid'=>$uid, 'friendid'=>$friendid, 'comment'=>$comment));
}

function uc_friend_delete($uid, $friendids) {
	return call_user_func(UC_API_FUNC, 'friend', 'delete', array('uid'=>$uid, 'friendids'=>$friendids));
}

function uc_friend_totalnum($uid, $direction = 0) {
	return call_user_func(UC_API_FUNC, 'friend', 'totalnum', array('uid'=>$uid, 'direction'=>$direction));
}

function uc_friend_ls($uid, $page = 1, $pagesize = 10, $totalnum = 10, $direction = 0) {
	$return = call_user_func(UC_API_FUNC, 'friend', 'ls', array('uid'=>$uid, 'page'=>$page, 'pagesize'=>$pagesize, 'totalnum'=>$totalnum, 'direction'=>$direction));
	return UC_CONNECT == 'mysql' ? $return : uc_unserialize($return);
}

function uc_user_register($username, $password, $email) {
	return call_user_func(UC_API_FUNC, 'user', 'register', array('username'=>$username, 'password'=>$password, 'email'=>$email));
}

function uc_user_login($username, $password, $isuid=0) {
	$isuid = intval($isuid);
	$return = call_user_func(UC_API_FUNC, 'user', 'login', array('username'=>$username, 'password'=>$password, 'isuid'=>$isuid));
	return UC_CONNECT == 'mysql' ? $return : uc_unserialize($return);
}

function uc_user_synlogin($uid) {
    return  uc_api_post('user', 'synlogin', array('uid'=>$uid));
}

function uc_user_synlogout() {
	return  uc_api_post('user', 'synlogout', array('uid'=>$uid));
}

function uc_user_edit($username, $oldpw, $newpw, $email, $ignoreoldpw = 0) {
	return call_user_func(UC_API_FUNC, 'user', 'edit', array('username'=>$username, 'oldpw'=>$oldpw, 'newpw'=>$newpw, 'email'=>$email, 'ignoreoldpw'=>$ignoreoldpw));
}

function uc_user_delete($username) {
	return call_user_func(UC_API_FUNC, 'user', 'delete', array('username'=>$username));
}

function uc_user_checkname($username) {
	return call_user_func(UC_API_FUNC, 'user', 'check_username', array('username'=>$username));
}

function uc_user_checkemail($email) {
	return call_user_func(UC_API_FUNC, 'user', 'check_email', array('email'=>$email));
}

function uc_user_addprotected($username, $admin='') {
	return call_user_func(UC_API_FUNC, 'user', 'addprotected', array('username'=>$username, 'admin'=>$admin));
}

function uc_user_deleteprotected($username) {
	return call_user_func(UC_API_FUNC, 'user', 'deleteprotected', array('username'=>$username));
}

function uc_user_getprotected() {
	$return = call_user_func(UC_API_FUNC, 'user', 'getprotected', array('1'=>1));
	return UC_CONNECT == 'mysql' ? $return : uc_unserialize($return);
}

function uc_get_user($username) {
	$return = call_user_func(UC_API_FUNC, 'user', 'get_user', array('username'=>$username));
	return UC_CONNECT == 'mysql' ? $return : uc_unserialize($return);
}

function uc_user_merge($oldusername, $newusername, $uid, $password, $email) {
	return call_user_func(UC_API_FUNC, 'user', 'merge', array('oldusername'=>$oldusername, 'newusername'=>$newusername, 'uid'=>$uid, 'password'=>$password, 'email'=>$email));
}

function uc_pm_location($uid, $newpm = 0) {
	$apiurl = uc_api_url('pm_client', 'ls', "uid=$uid", ($newpm ? '&folder=newbox' : ''));
	@header("Expires: 0");
	@header("Cache-Control: private, post-check=0, pre-check=0, max-age=0", FALSE);
	@header("Pragma: no-cache");
	@header("location: $apiurl");
}

function uc_pm_checknew($uid) {
	return call_user_func(UC_API_FUNC, 'pm', 'check_newpm', array('uid'=>$uid));
	}

function uc_pm_send($fromuid, $msgto, $subject, $message, $instantly = 1, $replypmid = 0, $isusername = 0) {
	if($instantly) {
		$replypmid = @is_numeric($replypmid) ? $replypmid : 0;
		return call_user_func(UC_API_FUNC, 'pm', 'sendpm', array('fromuid'=>$fromuid, 'msgto'=>$msgto, 'subject'=>$subject, 'message'=>$message, 'replypmid'=>$replypmid, 'isusername'=>$isusername));
	} else {
		$fromuid = intval($fromuid);
		$subject = urlencode($subject);
		$msgto = urlencode($msgto);
		$message = urlencode($message);
		$replypmid = @is_numeric($replypmid) ? $replypmid : 0;
		$replyadd = $replypmid ? "&pmid=$replypmid&do=reply" : '';
		$apiurl = uc_api_url('pm_client', 'send', "uid=$fromuid", "&msgto=$msgto&subject=$subject&message=$message$replyadd");
		@header("Expires: 0");
		@header("Cache-Control: private, post-check=0, pre-check=0, max-age=0", FALSE);
		@header("Pragma: no-cache");
		@header("location: ".$apiurl);
	}
}


function uc_pm_delete($uid, $folder, $pmids) {
	return call_user_func(UC_API_FUNC, 'pm', 'delete', array('uid'=>$uid, 'folder'=>$folder, 'pmids'=>$pmids));
}

function uc_pm_list($uid, $page = 1, $pagesize = 10, $folder = 'inbox', $filter = 'newpm', $msglen = 0) {
	$uid = intval($uid);
	$page = intval($page);
	$pagesize = intval($pagesize);
	$return = call_user_func(UC_API_FUNC, 'pm', 'ls', array('uid'=>$uid, 'page'=>$page, 'pagesize'=>$pagesize, 'folder'=>$folder, 'filter'=>$filter, 'msglen'=>$msglen));
	return UC_CONNECT == 'mysql' ? $return : uc_unserialize($return);
}

function uc_pm_ignore($uid) {
	$uid = intval($uid);
	return call_user_func(UC_API_FUNC, 'pm', 'ignore', array('uid'=>$uid));
}

function uc_pm_view($uid, $pmid) {
	$uid = intval($uid);
	$pmid = @is_numeric($pmid) ? $pmid : 0;
	$return = call_user_func(UC_API_FUNC, 'pm', 'view', array('uid'=>$uid, 'pmid'=>$pmid));
	return UC_CONNECT == 'mysql' ? $return : uc_unserialize($return);
}

function uc_pm_viewnode($uid, $type = 0, $pmid = 0) {
	$uid = intval($uid);
	$pmid = @is_numeric($pmid) ? $pmid : 0;
	$return = call_user_func(UC_API_FUNC, 'pm', 'viewnode', array('uid'=>$uid, 'pmid'=>$pmid, 'type'=>$type));
	return UC_CONNECT == 'mysql' ? $return : uc_unserialize($return);
}

function uc_pm_blackls_get($uid) {
	$uid = intval($uid);
	return call_user_func(UC_API_FUNC, 'pm', 'blackls_get', array('uid'=>$uid));
}

function uc_pm_blackls_set($uid, $blackls) {
	$uid = intval($uid);
	return call_user_func(UC_API_FUNC, 'pm', 'uc_pm_blackls_set', array('uid'=>$uid, 'blackls'=>$blackls));
}

function uc_domain_ls() {
	$return = call_user_func(UC_API_FUNC, 'domain', 'ls', array('1'=>1));
	return UC_CONNECT == 'mysql' ? $return : uc_unserialize($return);
}

function uc_credit_exchange_request($uid, $from, $to, $toappid, $amount) {
	$uid = intval($uid);
	$from = intval($from);
	$toappid = intval($toappid);
	$to = intval($to);
	$amount = intval($amount);
	return uc_api_post('credit', 'request', array('uid'=>$uid, 'from'=>$from, 'to'=>$to, 'toappid'=>$toappid, 'amount'=>$amount));
}

function uc_tag_get($tagname, $nums = 0) {
	$return = call_user_func(UC_API_FUNC, 'tag', 'gettag', array('tagname'=>$tagname, 'nums'=>$nums));
	return UC_CONNECT == 'mysql' ? $return : uc_unserialize($return);
}

function uc_avatar($uid) {
	$uid = intval($uid);
	$uc_input = uc_api_input("uid=$uid");
	$uc_avatarflash = UC_API.'/images/camera.swf?inajax=1&appid='.UC_APPID.'&input='.$uc_input.'&agent='.md5($_SERVER['HTTP_USER_AGENT']).'&ucapi='.urlencode(UC_API);
	return '<object classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" codebase="http://download.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=9" width=447 height=477 id="mycamera"><param name="movie" value="'.$uc_avatarflash.'"><param name="quality" value="high"><param name="menu" value="false"><embed src="'.$uc_avatarflash.'" quality="high" menu="false"  width="447" height="477" type="application/x-shockwave-flash" pluginspage="http://www.macromedia.com/shockwave/download/index.cgi?P1_Prod_Version=ShockwaveFlash" name="mycamera" swLiveConnect="true"></embed></object>';
}

?>