<?php
require_once(__DIR__.'/lib/Rcon.php');
define('CFG_FILE', __DIR__ . '/config.json');
define('TMP_FILE', __DIR__ . '/runtime/tmp.txt');
define('LOG_FILE', __DIR__ . '/runtime/log.txt');
define('PARSER_FILE', __DIR__ . '/dataParser.js');
ini_set('memory_limit', "2280M");
set_time_limit(0);

if(!file_exists(TMP_FILE)){
	if(file_put_contents(TMP_FILE, '') === false)
		die('error write to file: '.TMP_FILE);
}

$cfg = [
	'apiUrl' => 'http://94.228.113.214:85/api',
	'allowedRconCommands' => [
		'saveworld',
		'broadcast',
		'addpoints',
		'pvpve',
		'pvetime',
		'pvetime',
		'permissions',
	]
];

if($savedCfg = @json_decode(file_get_contents(CFG_FILE), true))
	$cfg = array_merge($cfg, $savedCfg);
else
	toLog('error parse config.json file', true, true);

$tmp = readTmp();
$rConnects = [];

$tasks = [
	'rcon' => [
		'interval' => 3,
		'lastStart' => 0,
	],
	'checkServers' => [
		'interval' => 60,
		'lastStart' => 0,
	],
];

$startTime = time();

$playersLocal = [];
$tribesLocal = [];

while(true){
	
	foreach ($tasks as $name=> &$task){
		$now = time();
		
		if($now - $task['lastStart'] >= $task['interval']){
			$task['lastStart'] = $now;
			$funcName = "{$name}Task";
			$funcName();
		}
	}
	
	sleep(1);
}

function rconTask(){
	global $cfg;
	
	$data = [
		'method' => 'getRconCommands',
	];
	
	$commandPull = httpRequest($data);
	
	if($commandPull === null){
		toLog('error content in rconTask', false, true);
		return;
	}
	
	foreach ($commandPull as $commandId=>$pull) {
		
		$response = [];
		
		foreach ($pull as $serverId=>$command){
			
			$allowed = false;
			foreach ($cfg['allowedRconCommands'] as $allowedCmd){
				
				if(mb_strpos(mb_strtolower($command, 'utf-8'), $allowedCmd, 0, 'utf-8')===0){
					$allowed = true;
					break;
				}
			}
			
			if(!$allowed){
				toLog('disallowed command '.$command, false, true);
				$response[$serverId] = 'denied';
				continue;
			}
			
			$index = array_search($serverId, array_column($cfg['servers'], 'id'));
			
			if($index===false or !$server = $cfg['servers'][$index]){
				$response[$serverId] = 'not found';
				continue;
			}
			
			$rcon = new Rcon($server['ip'], $server['rconPort'], $server['rconPass'], 5);
			
			$commandResult = $rcon->sendCommand($command);
			
			if($commandResult === null){
				$response[$serverId] = 'error';
				toLog('failed to execute Rcon command: '.$command.' on server '.$serverId, false, true);
				continue;
			}
			
			toLog('executed command: '.$command.' on server '.$serverId, false, true);
			
			if(preg_match('!Server received, But no response!', $commandResult))
				$commandResult = 'success';
			
			$response[$serverId] = $commandResult;
			
			//sleep(1);
		}
		
		$data = [
			'method' => 'setRconResponse',
			'command' =>[
				'id' => $commandId,
				'response' => $response,
			]
		];
		
		$result = httpRequest($data);
		
		//todo: write to tmp file executed commands to prevent repeating
		
		if($result['result'] !== 'success'){
			toLog('error setRconResponse: '.json_encode($result), false, true);
		}
	}
}

function checkServersTask(){
	global $cfg, $playersLocal, $tribesLocal;
	$startTime = time();
	$serversUpdated = 0;
	
	foreach ($cfg['servers'] as $server){
		toLog("checking server: {$server['id']}", false, true);
		
		//todo: parser only last modified files, save date to tmp file
		//local data from files
		$playersLocal = execCommand("node " . PARSER_FILE . " " . $server['saveDir'] . " players");
		if($playersLocal === null) {
			toLog("error parse players files on serer {$server['id']}", false, true);
			continue;
		}
		
		$tribesLocal = execCommand("node " . PARSER_FILE . " " . $server['saveDir'] . " tribes");
		if($tribesLocal === null){
			toLog("error parse tribes files on serer {$server['id']}");
			continue;
		}
		
		$rcon = new Rcon($server['ip'], $server['rconPort'], $server['rconPass'], 5);
		
		$rPlayers = $rcon->getPlayers();
		
		if($rPlayers === null){
			toLog("error getPlayers on {$server['id']}, skip");
			continue;
		}
		
		$players = [];
		$tribes = [];
		
		foreach ($rPlayers as $steamId => $rPlayer) {
			$profile = getProfile($steamId);
			
			//debug
			if(!$profile){
				toLog("profile for $steamId not found", false, true);
				continue;
			}
			
			unset($profile['steamName']);
			
			$players[$steamId] = $profile;
			
			
			if($profile['tribeId']){
				if(!isset($tribes[$profile['tribeId']])){
					$tribes[$profile['tribeId']] = getTribe($profile['tribeId']);
					
					$logs = $rcon->getTribeLogs($profile['tribeId']);
					
					if($logs === null){
						toLog("error getTribeLogs on {$server['id']}, tribe: {$profile['tribeId']} skip");
						continue 2;
					}
					
					toLog("logs count: ".count($logs), false, false);
					$tribes[$profile['tribeId']]['logs'] = $logs;
				}
			}
			
		}
		
		$data = [
			'method' => 'updateServerInfo',
			'serverId' => $server['id'],
			'players' => $players,
			'tribes' => $tribes,
		];
		//$rcon->disconnect();
		$result = httpRequest($data);
		
		
		if($result['result'] === 'success'){
			$serversUpdated++;
			toLog("server updated", false, true);
		}else{
			toLog('httpRequest error '.$server['id'].': '.json_encode($result), false, true);
		}
		
		unset($rcon);
	}
	toLog("updated: $serversUpdated servers, spent: ".(time() - $startTime)." sec");
}

/**
 * @param string $url
 * @param array $data
 * @param string $publicKey
 * @param string $privateKey
 * @return mixed|null
 */
function httpRequest($data){
	$cfg = $GLOBALS['cfg'];
	
	$data['publicKey'] = $cfg['publicKey'];
	$data['privateKey'] = $cfg['privateKey'];
	$data['clusterId'] = $cfg['clusterId'];
	
	$json = json_encode($data);
	
	if(!$json){
		toLog('error json encode data for '.$data['serverId'].', '.json_last_error_msg());
		return null;
	}
	
	$encoded = gzencode($json, 9);
	$encoded = encrypt($encoded, $cfg['privateKey']);
	
	$options = [
		'http' => [
			'header'  => "Content-type:application/x-www-form-urlencoded\r\nkey: {$cfg['publicKey']}\r\n",
			'method'  => 'POST',
			'content' => $encoded,
		],
	];
	
	$context  = stream_context_create($options);
	$result = file_get_contents($cfg['apiUrl'], false, $context);
	
	if ($result === false) {
		toLog('request error');
		return null;
	}
	
	$decode = @json_decode($result, true);
	
	if(json_last_error()){
		toLog('decode error: '.json_last_error_msg());
		return null;
	}
	
	return $decode;
}

function getProfile($steamId){
	$key = array_search($steamId, array_column($GLOBALS['playersLocal'], 'steamId'));
	
	if($key !== false){
		$result = $GLOBALS['playersLocal'][$key];
		$result['created'] = strtotime($result['createdDate']);
		$result['updated'] = strtotime($result['updatedDate']);
		unset($result['createdDate'], $result['updatedDate']);
		return $result;
	}
	else{
		return null;
	}
}

function getTribe($tribeId){
	$key = array_search($tribeId, array_column($GLOBALS['tribesLocal'], 'id'));
	
	if($key !== false) {
		$result = $GLOBALS['tribesLocal'][$key];
		$result['created'] = strtotime($result['createdDate']);
		$result['updated'] = strtotime($result['updatedDate']);
		unset($result['createdDate'], $result['updatedDate']);
		return $result;
	}
	else{
		return null;
	}
}



/**
 * @return array
 */
function readTmp(){
	return unserialize(file_get_contents(TMP_FILE)) ?? [];
}

/**
 * @param array $data
 * @return bool
 */
function writeTmp($data){
	return file_put_contents(TMP_FILE, serialize($data));
}

/**
 * @param string $command
 * @return array|null
 */
function execCommand($command){
	$response = shell_exec($command);
	
	$response = trim($response);
	$response = trim($response, "`'");
	$response = str_replace('\\\"', '\\"', $response);  //node bug
	
	$result = @json_decode($response, true);
	
	if(json_last_error())
		return null;
	
	return $result;
}

function prrd($data){print_r($data);die;}

function encrypt($data, $key, $method = 'AES-256-CBC', $blockSize = 16){
	return openssl_encrypt($data, $method, $key, 0, substr(md5($key), 0, $blockSize));
}

function decrypt($data, $key, $method = 'AES-256-CBC', $blockSize = 16){
	return openssl_decrypt($data, $method, $key, 0, substr(md5($key), 0, $blockSize));
}

/**
 * define LOG_FILE
 * @param string $msg
 * @param bool $die
 * @param bool $echoMsg
 * @return bool
 */
function toLog($msg, $die = false, $echoMsg = false){
	
	$fp = fopen(LOG_FILE, 'a');
	
	$failCount = 100;
	
	$lock = false;
	
	for($i=1; $i<=$failCount; $i++){
		if($lock = flock($fp, LOCK_EX))
			break;
		
		usleep(100);
	}
	
	if(!$lock){
		fclose($fp);
		if($die) die;
		return false;
	}
	
	if(!fwrite($fp, "\n".date('d.m.Y H:i:s').": $msg")){
		if($die) die;
		return false;
	}
	
	
	fclose($fp);
	
	if(filesize(LOG_FILE) > 2000000) file_put_contents(LOG_FILE, '');
	if($echoMsg) echo "\n$msg\n";
	if($die) die;
	
	return true;
}