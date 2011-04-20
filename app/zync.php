<?php

ignore_user_abort(true);
set_time_limit(0);

$root = dirname(dirname(__FILE__));

require_once($root . '/app/kirby.php');
require_once($root . '/app/dropbox.php');

c::set('zync.version', '0.1');
c::set('root', $root);

class zync {

	private static $output = array();
	private static $token  = false;
	private static $secret = false;

	function auth() {
	
		self::$token  = db::field('brain', 'content', array('name' => 'token'));
		self::$secret = db::field('brain', 'content', array('name' => 'secret'));
		
		if(empty(self::$token) || empty(self::$secret)) {
			
			// try to authenticate
			$dropbox = new Dropbox(c::get('dropbox.key'), c::get('dropbox.secret'));
			$stuff   = $dropbox->token(c::get('dropbox.email'), c::get('dropbox.password')); 
			
			// authorization failed
			if(empty($stuff['token']) || empty($stuff['secret'])) return array(
				'status' => 'error',
				'msg'    => 'dropbox authentication failed'
			);
			
			// store the token and secret						
			self::$token  = a::get($stuff, 'token');
			self::$secret = a::get($stuff, 'secret');

			// store the stuff in the db for the next call
			db::insert('brain', array('name' => 'token', 'content' => self::$token), true);
			db::insert('brain', array('name' => 'secret', 'content' => self::$secret), true);
		
		} 

		// check the account
		$dropbox = new Dropbox(c::get('dropbox.key'), c::get('dropbox.secret'));
		$dropbox->setOAuthToken(self::$token);
		$dropbox->setOAuthTokenSecret(self::$secret);
		$result = $dropbox->accountInfo();

		if(empty($result['quota_info'])) die('Dropbox authentication failed');

		// find out how much space is left on the account		
		$quota   = $result['quota_info']; 
		$total   = $quota['quota'];
		$used    = ($quota['shared'] + $quota['normal']);
		$percent = round((100/$total) * $used);

		// if too few space is left stop this
		if($percent > 95) die('Exceeding dropbox quota');
				
	}
	
	function upload($file, $name=false) {
		
		if(empty(self::$token) || empty(self::$secret)) return array(
			'status' => 'error',
			'msg'    => 'authentication faild'
		);
				
		if(!$name) $name = basename($file);
		
		$dropbox = new Dropbox(c::get('dropbox.key'), c::get('dropbox.secret'));
		$dropbox->setOAuthToken(self::$token);
		$dropbox->setOAuthTokenSecret(self::$secret);
		
		try {		
			$result = $dropbox->filesPost(c::get('dropbox.folder') . '/' . $name, $file);
			return array(
				'status' => 'success',
				'msg'    => 'the file has been uploaded'
			);
		} catch(Exception $e) {
			return array(
				'status' => 'error',
				'msg'    => 'the file could not be uploaded'
			);
		}
	
	}

	function offset() {
		$offset = db::field('brain', 'content', array('name' => 'offset'));
		if(empty($offset)) {
			db::insert('brain', array('name' => 'offset', 'content' => 0), true);
			$offset = 0;
		}
		return $offset;
	}

	function sync() {

		// create the tables
		self::db();

		// authenticate dropbox
		$auth = self::auth();
		if(error($auth)) die(msg($auth));

		// check for a writable dir
		$root = c::get('zync.dir', c::get('root') . '/images');
		if(!is_writable($root)) die('Please make sure that ' . c::get('root') . ' is writable');
		
		// setup the rest				
		$limit  = 20;
		$offset = self::offset();
		$result = self::call('users/items', array(
			'username' => c::get('zootool.username'), 
			'type'     => 'images', 
			'limit'    => $limit, 
			'sort'     => 'asc',
			'offset'   => ($offset*$limit)
		));
						
		foreach($result AS $item) {
			
			// create the full file name
			$file = $root . '/' . date('Ymd', $item['added']) . '-' . $item['uid'];

			// this file has already been created
			if(file_exists($file)) continue;

			$image = @file_get_contents($item['url']);
			
			if(!$image) $image = @file_get_contents($item['image']);
			if(!$image) {
				self::mark($item, 'y');				
				continue;			
			}
					
			f::write($file, $image);
			
			$info = @getimagesize($file);			
			
			if(!$info) {
				self::mark($item, 'y');				
				f::remove($file);
				continue;
			}
			
			switch($info['mime']) {
				case 'image/jpeg':
					$ext = 'jpg';
					break;
				case 'image/gif':
					$ext = 'jpg';
					break;
				case 'image/png':
					$ext = 'jpg';
					break;
				default:
					$ext = 'jpg';
			}			

			$new = $file . '.' . $ext;
			
			// rename the file						
			f::move($file, $new);			
			
			// check if that worked
			if(!file_exists($new)) {
				self::mark($item, 'y');
				f::remove($file);
				continue;
			}
						
			// upload to dropbox
			self::upload($new);
			
			// if the user does not want to keep the files on the server
			if(c::get('zync.keepfiles') == false) {
				f::remove($new);
			}
			
			self::mark($item);
			
		}
				
		// move to the next offset
		if(count($result) == $limit) {
			$next = $offset++;
			db::update('brain', 'content = content+1', array('name' => 'offset'));
		}
	
		// uncomment this for debugging
		//a::show(self::$output);
				
	}
	
	function mark($item, $failed='n') {
				
		$input = array(
			'uid' => $item['uid'],
			'url' => $item['url'],
			'failed' => $failed,
			'title' => $item['title'],
			'added' => $item['added'],
			'synced' => time(),
		);
		
		self::$output[] = $input;
		db::insert('items', $input, true);
				
	}

	function call($method, $data) {

		$data['login']  = true;				
		$data['format'] = 'json';
		$data['apikey'] = c::get('zootool.key');
		
		$apiurl = 'http://zootool.com/api/' . $method . '/?' . http_build_query($data);
		$ch = curl_init();

		// HTTP Digest Authentication
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
		curl_setopt($ch, CURLOPT_USERPWD, str::lower(c::get('zootool.username')) . ':' . sha1(c::get('zootool.password')));
		curl_setopt($ch, CURLOPT_URL, $apiurl);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Zootool API Call');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
		
		$result  = curl_exec($ch);	
		$error 	 = curl_errno($ch);
		$message = curl_error($ch);

		if($error > 0) return array(
			'status' => 'error',
			'msg' => $message
		);
									
		$info = curl_getinfo($ch);
		curl_close($ch);
					
		return str::parse($result);
	
	}

	function db() {
		
		$items = db::query('CREATE TABLE IF NOT EXISTS `items` (
			`id` int(11) NOT NULL AUTO_INCREMENT,
			`uid` varchar(255) DEFAULT NULL,
			`title` varchar(255) DEFAULT NULL,
			`url` text,
			`failed` enum(\'y\',\'n\') DEFAULT \'n\',
			`added` int(11) DEFAULT NULL,
			`synced` int(11) DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `uid` (`uid`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8');

		$brain = db::query('CREATE TABLE IF NOT EXISTS `brain` (
			`name` varchar(255) NOT NULL DEFAULT \'\',
			`content` varchar(255) DEFAULT NULL,
			UNIQUE KEY `key` (`name`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8');
		
	}

}

?>