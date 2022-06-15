<?php

class Rcon
{
    private $host;
    private $port;
    private $password;
    private $timeout;

    private $socket;
    private $packetSize;

    private $authorized = false;
    private $lastResponse = '';

    const PACKET_AUTHORIZE = 5;
    const PACKET_COMMAND = 6;

    const SERVERDATA_AUTH = 3;
    const SERVERDATA_AUTH_RESPONSE = 2;
    const SERVERDATA_EXECCOMMAND = 2;
    const SERVERDATA_RESPONSE_VALUE = 0;

    /**
     * Create a new instance of the Rcon class.
     *
     * @param string $host
     * @param integer $port
     * @param string $password
     * @param integer $timeout
     */
    public function __construct($host, $port, $password, $timeout)
    {
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
        $this->timeout = $timeout;
    }

    /**
     * Get the latest response from the server.
     *
     * @return string
     */
    public function getResponse(){
        return $this->lastResponse;
    }

    /**
     * Connect to a server.
     *
     * @return boolean
     */
    public function connect()
    {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);

        if (!$this->socket) {
            $this->lastResponse = $errstr;
            return false;
        }

        //set timeout
        stream_set_timeout($this->socket, 3, 0);

        // check authorization
        return $this->authorize();
    }

    /**
     * Disconnect from server.
     *
     * @return void
     */
    public function disconnect()
    {
        if ($this->socket) {
        	fclose($this->socket);
        }
    }

    /**
     * True if socket is connected and authorized.
     *
     * @return boolean
     */
    public function isConnected()
    {
        return $this->authorized;
    }

    /**
     * Send a command to the connected server.
     *
     * @param string $command
     *
     * @return null|string
     */
    public function sendCommand($command)
    {
	    $this->disconnect();
	    
	    if(!$this->connect())
	    	return null;
	    
        // send command packet
        $this->writePacket(self::PACKET_COMMAND, self::SERVERDATA_EXECCOMMAND, $command);
        
        if(stripos($command, '4getTribeLog') !== false){
        	
	        $response = '';
        	$this->packetSize = 40960;
        	
        	for($i=1; $i<=10; $i++){
		        // get response
		        $response_packet = $this->readPacket();
		        
		        if(!isset($response_packet['body']))
		        	return $response;
		
		        $response .= $response_packet['body'];
		        
		        $pos = stripos($response_packet['body'], 'Server received');
		        
		        if($pos!==false and $i > 1){
			        break;
		        }
	        }
        	
	        return $response;
        }elseif(stripos($command, 'getTribeIdOfPlayer') !== false){
		    $this->packetSize = 4096;
	        $response_packet = $this->readPacket();
	        return $response_packet['body'];
        }else{
	        $this->packetSize = 0;
	        // get response
	        $response_packet = $this->readPacket();
	        
	        if(!$response_packet){
		        toLog("\nerror: $command");
		        return null;
	        }
	        
	        if ($response_packet['id'] == self::PACKET_COMMAND) {
		        if ($response_packet['type'] == self::SERVERDATA_RESPONSE_VALUE) {
			        $this->lastResponse = $response_packet['body'];
			
			        return $response_packet['body'];
		        }
	        }
        }
        
        return null;
    }

    /**
     * Log into the server with the given credentials.
     *
     * @return boolean
     */
    private function authorize()
    {
        $this->writePacket(self::PACKET_AUTHORIZE, self::SERVERDATA_AUTH, $this->password);
        $response_packet = $this->readPacket();

        if ($response_packet['type'] == self::SERVERDATA_AUTH_RESPONSE) {
            if ($response_packet['id'] == self::PACKET_AUTHORIZE) {
                $this->authorized = true;
                return true;
            }
        }

        $this->disconnect();
        return false;
    }

    /**
     * Writes a packet to the socket stream.
     *
     * @param $packetId
     * @param $packetType
     * @param string $packetBody
     *
     * @return void
     */
    private function writePacket($packetId, $packetType, $packetBody)
    {
        /*
		Size			32-bit little-endian Signed Integer	 	Varies, see below.
		ID				32-bit little-endian Signed Integer		Varies, see below.
		Type	        32-bit little-endian Signed Integer		Varies, see below.
		Body		    Null-terminated ASCII String			Varies, see below.
		Empty String    Null-terminated ASCII String			0x00
		*/

        //create packet
        $packet = pack('VV', $packetId, $packetType);
        $packet = $packet.$packetBody."\x00";
        $packet = $packet."\x00";

        // get packet size.
        $packet_size = strlen($packet);

        // attach size to packet.
        $packet = pack('V', $packet_size).$packet;

        // write packet.
        fwrite($this->socket, $packet, strlen($packet));
    }

    /**
     * Read a packet from the socket stream.
     *
     * @return array|null
     */
    private function readPacket()
    {
        //get packet size.
        $size_data = @fread($this->socket, 4);
        
        if(!$size_data)
        	return null;
        
        $size_pack = @unpack('V1size', $size_data);
        
        if(!is_array($size_pack))
            return null;
        
        $size = $size_pack['size'];
        
//        if($size > 4096){
//	        //prrd($size_pack);
//        }
        
        // if size is > 4096, the response will be in multiple packets.
        // this needs to be address. get more info about multi-packet responses
        // from the RCON protocol specification at
        // https://developer.valvesoftware.com/wiki/Source_RCON_Protocol
        // currently, this script does not support multi-packet responses.

	    //kos fix
	    if($this->packetSize)
	        $size = $this->packetSize;
	    
        $packet_data = fread($this->socket, $size);
        $packet_pack = unpack('V1id/V1type/a*body', $packet_data);
		
        return $packet_pack;
    }
	
	/**
	 * @return null|array ['7872538213681236213' => ['steamId'=>'', 'characterName'=>'', 'tribeName'=''], ...]
	 */
	public function getPlayers(){
		
		if(!$content = $this->sendCommand('ListAllPlayerSteamID'))
			return null;
		
		$result = [];
		
		if(!preg_match_all('!(.*?) \[(.*?)\]: (\d{10,25}+)!', $content, $matches))
			return $result;
		
		foreach ($matches[1] as $key=>$playerName){
			$result[$matches[3][$key]] = [
				'steamId' => $matches[3][$key],
				'characterName' => utf8_encode($playerName),
				'tribeName' => utf8_encode($matches[2][$key]),
			];
		}
		
		return $result;
	}
	
	public function getTribeLogs($tribeId){
		$response = $this->sendCommand("getTribeLog $tribeId");
		
		if($response === null)
			return null;
		
		$result = [];
		
		if(!preg_match_all('!(Day \d+\, .+?): (.+)!iu', $response, $matches))
			return $result;
		
		foreach ($matches[1] as $key=>$date){
			$result[] = [
				'date' => trim(utf8_encode($date)),
				'msg' => trim(strip_tags(utf8_encode($matches[2][$key]))),
			];
		}
		
		return $result;
	}
}
