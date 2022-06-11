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
];

if($savedCfg = @json_decode(file_get_contents(CFG_FILE), true))
	$cfg = array_merge($cfg, $savedCfg);
else
	toLog('error parse config.json file', true, true);

$tmp = readTmp();
$rConnects = [];


while(true){
	$serversUpdated = 0;
	
	$startTime = time();
	
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
					
					toLog("logs count: ".count($logs), false, true);
					$tribes[$profile['tribeId']]['logs'] = $logs;
				}
			}
			
		}
		
		$data = [
			'publicKey' => $cfg['publicKey'],
			'privateKey' => $cfg['privateKey'],
			'clusterId' => $cfg['clusterId'],
			'serverId' => $server['id'],
			'players' => $players,
			'tribes' => $tribes,
		];
		//$rcon->disconnect();
		
		$content = httpRequest($data);
		
		if($content === true){
			$serversUpdated++;
			toLog("server updated", false, true);
		}else{
			toLog('httpRequest error: '.$content);
		}
		
		unset($rcon);
	}
	
	toLog("updated: $serversUpdated servers, spent: ".(time() - $startTime)." sec");
	sleep(120);
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
	
	if(!$result = @json_decode($response, true))
		return null;
	
	return $result;
}

/**
 * @param string $url
 * @param array $data
 * @param string $publicKey
 * @param string $privateKey
 * @return bool
 */
function httpRequest($data){
	$cfg = $GLOBALS['cfg'];
	
	$data['method'] = 'updateServerInfo';
	$data = gzencode(json_encode($data), 9);
	$data = encrypt($data, $cfg['privateKey']);
	//echo "\n strlen: ".strlen($data);die;
	//echo "\n strlen: ".strlen($data);
//	$data = decrypt($data, $privateKey);
//	$data = gzdecode($data);
//
//	die;
	//prrd(json_decode($data, true));
	//die;
	
	$options = [
		'http' => [
			'header'  => "Content-type:application/x-www-form-urlencoded\r\nkey: {$cfg['publicKey']}\r\n",
			'method'  => 'POST',
			'content' => $data,
		],
	];
	
	$context  = stream_context_create($options);
	$result = file_get_contents($cfg['apiUrl'], false, $context);
	
	if ($result === false) {
		return false;
	}
	
	if($result === 'ok')
		return true;
	else{
		return $result;
	}
}

function prrd($data){
	print_r($data);die;
}

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