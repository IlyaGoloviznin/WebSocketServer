<?php
namespace MyApp;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use \PDO;

class WS implements MessageComponentInterface {
    protected $clients;
    #protected $connects;
    protected $deviceAndroid   = array();
    protected $deviceDoor      = array();
    protected $deviceGroup     = array();
    protected $deviceQr        = array();
    protected $accept_android  = array();
    protected $accept_door     = array();
    protected $imageDoor       = array();
    protected $admin           = '';
    protected $StatusServer    = '1';
    protected $OldStatusServer = '0';

    public function __construct() {
        $this->clients         = new \SplObjectStorage;
        #$this->$deviceAndroid  = new \SplObjectStorage;
	    #$this->$deviceDoor     = new \SplObjectStorage;
	    #$this->$deviceGroup    = new \SplObjectStorage;
	    #$this->$deviceQr       = new \SplObjectStorage;
	    #$this->$accept_android = new \SplObjectStorage;
	    #$this->$accept_door    = new \SplObjectStorage;
	    #$this->$imageDoor      = new \SplObjectStorage;
    }

    public function onOpen(ConnectionInterface $conn) {
        // Store the new connection to send messages to later
        $this->clients->attach($conn);

        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $message) {
        #$numRecv = count($this->clients) - 1;
        #echo sprintf('Connection %d sending message "%s" to %d other connection%s' . "\n", $from->resourceId, $msg, $numRecv, $numRecv == 1 ? '' : 's');
        $this->CheckStatusServer();
        $this->CheckDevices();
        $msg = json_decode($message, true);

        if ($msg && is_array($msg)) {
            echo "Message: ";
            echo $message . "\n";
   
            if (array_key_exists('i_am_alive', $msg)) {
                $this->InitUser($from, $msg);
            }
            elseif (array_key_exists('message', $msg)) {
                if ($this->POST()) {
                    if ($msg['message'] == 'Call_to_you')   $this->SendCall($from, $msg['unix_time']); //from - door
                    if ($msg['message'] == 'accept_by_me')  $this->SendAnswer($from); //from - Android
                    if ($msg['message'] == '30sec_timeout') $this->SendTimeOut($from); //from - door
                    if ($msg['message'] == 'webRTC')        $this->SendWebRTC($from, $message); //from - door
                    if ($msg['message'] == 'getImage')      $this->getImage($from, $msg['nameDoor']); //from - admin
                    if ($msg['message'] == 'image')         $this->SendImage($from, $msg); //from - door
                    if ($msg['message'] == 'passage')       $this->GetPassage($from, $msg['data']);

                }
                else
                    $from->send(json_encode(array('WebRTC server is down' => 'Service unavailable. Call to sysadmin')));
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        unset($this->deviceAndroid[  array_search($conn, $this->deviceAndroid) ]); 
        unset($this->deviceDoor[     array_search($conn, $this->deviceDoor)]); 
        unset($this->deviceGroup[    array_search($conn, $this->deviceGroup)]); 
        unset($this->deviceQr[       array_search($conn, $this->deviceQr)]); 
        unset($this->accept_android[ array_search($conn, $this->accept_android)]); 
        unset($this->accept_door[    array_search($conn, $this->accept_door)]); 
        unset($this->imageDoor[      array_search($conn, $this->imageDoor)]);
        // The connection is closed, remove it, as we can no longer send it messages
        $this->clients->detach($conn);

        echo "Connection {$conn->resourceId} has disconnected\n"; 
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        unset($this->deviceAndroid[  array_search($conn, $this->deviceAndroid) ]); 
        unset($this->deviceDoor[     array_search($conn, $this->deviceDoor)]); 
        unset($this->deviceGroup[    array_search($conn, $this->deviceGroup)]); 
        unset($this->deviceQr[       array_search($conn, $this->deviceQr)]); 
        unset($this->accept_android[ array_search($conn, $this->accept_android)]); 
        unset($this->accept_door[    array_search($conn, $this->accept_door)]); 
        unset($this->imageDoor[      array_search($conn, $this->imageDoor)]);

        echo "An error has occurred: {$e->getMessage()}\n";

        $conn->close();
    }

    protected function InitUser($from, $msg) {
        $pdo = new PDO('mysql:host=localhost;dbname=android_devices_rtc', 'root', 'root');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("SELECT Type FROM users WHERE GUID=?");
        $stmt->execute(array($msg['i_am_alive']));
        $deviceType = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT device_group FROM users WHERE GUID=?");
        $stmt->execute(array($msg['i_am_alive']));
        $dGroup = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT username FROM users WHERE GUID=?");
        $stmt->execute(array($msg['i_am_alive']));
        $dName = $stmt->fetchColumn();

        if ($deviceType) {
            if ($deviceType == 'Android') {
                $this->deviceAndroid[$msg['i_am_alive']] = $from;
                $this->deviceGroup[$msg['i_am_alive']] = $dGroup;
                if (array_key_exists('fcm_token', $msg))
                    $this->UpdateToken($msg['i_am_alive'], $msg['fcm_token']);
            } elseif ($deviceType == 'Door'){
                $this->deviceDoor[$msg['i_am_alive']] = $from;
                $this->deviceGroup[$msg['i_am_alive']] = $dGroup;
            } elseif ($deviceType == "qr") {
                $this->deviceQr[$msg['i_am_alive']] = $from;
                $this->deviceGroup[$msg['i_am_alive']] = $dGroup;
                $this->SendDataBase($from);
            } else {
                $from->send(json_encode(array('authorization' => 0, 'error' => 'Undefined_device_Type')));
                echo "User: " . $msg['i_am_alive'] . " not authorized. Status 0\n";
                return false;
            }
            $from->send(json_encode(array('authorization' => 1,
            							  'your_name' => $dName,
            							  'webrtc_status' => $this->ServerStatus()
            							  )));
            echo "User: " . $msg['i_am_alive'] . " is authorized. Status 1\n";
            return true;
        }
        else {
            $from->send(json_encode(array('authorization' => 0, 'error' => 'Undefined_GUID')));
            echo "User: " . $msg['i_am_alive'] . " not authorized. Status 0\n";
            return false;
        }
    }

    protected function UpdateToken($GUID, $Token) {
        $pdo = new PDO('mysql:host=localhost;dbname=android_devices_rtc', 'root', 'root');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare('UPDATE users SET fcm_token=? WHERE GUID=?');
        $stmt->execute(array($Token, $GUID));
    }

    protected function SendDataBase($from) { 
        $pdo = new PDO('mysql:host=localhost;dbname=android_devices_rtc', 'root', 'root');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("SELECT * FROM workers");
        $stmt->execute();

        $data = array(); 
        $count = 0;
        while ($record = $stmt->fetch()) {  
            $data = array_merge($data, array(
                                            $count++ => array(
                                                             'id' => $record['id'],
                                                             'workerName' => $record['workerName'],
                                                             'workerPhoto' => $record['workerPhoto'],
                                                             'workerStatus' => $record['workerStatus']
                                                             )));
        }
        $from->send(json_encode($data));
        echo "Database send.\n";
    }

    protected function SendCall($from, $unix_time) {
        $nameDoor = $this->GetNameDoor($from, $this->deviceDoor);

        if ($this->DeviceCheck($from, $this->deviceDoor)) {
            $group_connect = $this->GetGroup($from, $this->deviceDoor);
            $this->accept_door[$group_connect] = $from;
            foreach ($this->deviceAndroid as $i) {
                $group_device = $this->GetGroup($i, $this->deviceAndroid);
                if ($group_device == $group_connect) {
                    $i->send(json_encode(array(
                                                'message' => 'Call_to_you',
                                                'door_name' => $nameDoor,
                                                'unix_time' => (string)$unix_time 
                                                )));
                }
            }
        }

        $this->CheckOnline($nameDoor, $group_connect, $unix_time);
    }

    protected function DeviceCheck($from, $device) {
        if (in_array($from, $device))
            return true;
        else
            return false;
    }

    protected function GetGroup($from, $device) {
        $key = array_search($from, $device);
        $gD = $this->deviceGroup[$key];
        return $gD;
    }

    protected function GetNameDoor($from, $deviceDoor) {
        $pdo = new PDO('mysql:host=localhost;dbname=android_devices_rtc', 'root', 'root');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $KeyDoor = array_search($from, $deviceDoor);

        $stmt = $pdo->prepare("SELECT username FROM users WHERE GUID=?");
        $stmt->execute(array($KeyDoor));
        $nameDoor = $stmt->fetchColumn();

        return $nameDoor;
    }

    protected function SendAnswer($from) {
        if ($this->DeviceCheck($from, $this->deviceAndroid)) {
            $group_connect = $this->GetGroup($from, $this->deviceAndroid);
            $this->accept_android[$group_connect] = $from;
            $this->accept_door[$group_connect]->send(json_encode(array('message' => 'accept_by_me')));

            $nameDoor = $this->GetNameDoor($this->accept_door[$group_connect], $this->deviceDoor);

            foreach ($this->deviceAndroid as $i) {
                $group_device = $this->GetGroup($i, $this->deviceAndroid);
                if ($group_device == $group_connect)
                    if ($i != $from) {
                        $i->send(json_encode(array('message' => 'accept_by_not_you', 'door_name' => $nameDoor)));
                    }
            }
            $this->CheckAnswer($nameDoor, $group_connect, $this->deviceAndroid);
        }
    }

    protected function SendTimeOut($from) {
        if ($this->DeviceCheck($from, $this->deviceDoor)) {
            $nameDoor = $this->GetNameDoor($from, $this->deviceDoor);
            $group_connect = $this->GetGroup($from, $this->deviceDoor);
            foreach ($this->deviceAndroid as $i) {
                $group_device = $this->GetGroup($i, $this->deviceAndroid);
                if ($group_device == $group_connect) {
                    $i->send(json_encode(array('message' => '30sec_timeout', 'door_name' => $nameDoor)));
                }
            }
            $this->CheckTimeOut($nameDoor, $group_connect, $this->deviceAndroid);
        }
    }

    protected function SendWebRTC($from, $data) {
        if ($this->DeviceCheck($from, $this->deviceDoor)) {
            $group_connect = $this->GetGroup($from, $this->deviceDoor);
            $this->accept_android[$group_connect]->send($data);
        }
    }

    protected function getImage($from, $data) {
        if ($from == $this->admin || true) {
            $pdo = new PDO('mysql:host=localhost;dbname=android_devices_rtc', 'root', 'root');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare("SELECT GUID FROM users WHERE username=?");
            $stmt->execute(array($data));
            $key = $stmt->fetchColumn();

            $this->deviceDoor[$key]->send(json_encode(array('message' => 'getImage')));
        }    
    }

    protected function SendImage($from, $data) {
        $admin = $from;

        $key = $this->GetKey($from, $this->deviceDoor);

        if ($this->DeviceCheck($from, $this->deviceDoor)) {
                $this->imageDoor[$key] = $data['data'];
                $admin->send(json_encode(array('image' => $this->imageDoor[$key])));
                unset($this->imageDoor[$key]);
        }
    }

    protected function GetKey($from, $device) {
        $key = array_search($from, $device);
        return $key;
    }

    protected function GetPassage($connect, $data) {
        if ($this->DeviceCheck($connect, $this->deviceQr)) { 
            $pdo = new PDO('mysql:host=localhost;dbname=android_devices_rtc', 'root', 'root');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare("INSERT INTO passages (id, passagetime, passagestatus) VALUES (:id, :passagetime, :passagestatus)");

            foreach ($data as $key => $value) {
                $stmt->execute($value);
            }
            echo "Database update. \n";
        }
    }       

    protected function CheckOnline($nameDoor, $group_connect, $unix_time) {
        $device = "Android";

        $pdo = new PDO('mysql:host=localhost;dbname=android_devices_rtc', 'root', 'root');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("SELECT fcm_token, username, GUID FROM users WHERE device_group=? AND Type=?");
        $stmt->execute(array($group_connect, $device));

        while ($deviceType = $stmt->fetch()) {
            if (!array_key_exists ($deviceType['GUID'], $this->deviceAndroid)) {
                $Token = $deviceType['fcm_token'];
                $this->POSTrequestCall($Token, $nameDoor, $unix_time);
            }
        }
    }

    protected function CheckAnswer($nameDoor, $group_connect, $Android) {
        $pdo = new PDO('mysql:host=localhost;dbname=android_devices_rtc', 'root', 'root');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $device = "Android";

        $stmt = $pdo->prepare("SELECT fcm_token, username, GUID FROM users WHERE device_group=? AND Type=?");
        $stmt->execute(array($group_connect, $device));

        while ($deviceType = $stmt->fetch()) {
            if (!array_key_exists ($deviceType['GUID'], $Android)) {
                $Token = $deviceType['fcm_token'];
                $this->POSTrequestAnswer($Token, $nameDoor);
            }
        }
    }

    protected function CheckTimeOut($nameDoor, $group_connect, $Android) {
        $pdo = new PDO('mysql:host=localhost;dbname=android_devices_rtc', 'root', 'root');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $device = "Android";

        $stmt = $pdo->prepare("SELECT fcm_token, username, GUID FROM users WHERE device_group=? AND Type=?");
        $stmt->execute(array($group_connect, $device));

        while ($deviceType = $stmt->fetch()) {
            if (!array_key_exists ($deviceType['GUID'], $Android)) {
                $Token = $deviceType['fcm_token'];
                $this->POSTrequestTimeOut($Token, $nameDoor);
            }
        }
    }

    protected function POSTrequestCall($Token, $nameDoor, $unix_time) {
        $request = array(
                        'to' => $Token,
                        'notification' => array(
                                                'title' => 'Client app',
                                                'body' => 'Вам поступил звонок с ' . $nameDoor,
                                                'icon' => 'ic_launcher',
                                                'sound' => 'default',
                                                'color' => '#0044ff',
                                                'tag' => 'tf.tantonfirst.qrcodescanner.' . $nameDoor
                                                ),
                        'data' => array(
                                        'door_name' => $nameDoor,
                                        'message' => 'Call_to_you',
                                        'unix_time' => (string)$unix_time
                                        )
                        );
        // Данные для отправки
        $header1 = 'Content-Type: application/json;';
        $header2 = 'Authorization: key=AAAAuqIigoM:APA91bFLzDaRTwbN8j0MueGPUSeqi3S2mcEMeBRvg9L-w6GwX2331qE7zxyzhBVBPGaiFRYDxPHN_SJejFZaeiBNI5Zd9luN2IOeTrl2NmmaeV0rH4D6DNTEB1qXmm5c7L8K-6FHI_O3';
        // Указание опций для контекста потока
        $options = array(
                        'http' => array(
                                        'method' => 'POST',
                                        'header' => $header1 . "\r\n" . $header2 . "\r\n", 
                                        'content' => json_encode($request)
                                        )
                        );
        // Создание контекста потока
        $context = stream_context_create($options);
        // Отправка данных и получение результата
        $te = file_get_contents('https://fcm.googleapis.com/fcm/send', 0, $context);
        echo "Push send Call:" . $te . "\n";
    }

    protected function POSTrequestAnswer($Token, $nameDoor) {
        $request = array(
                        'to' => $Token,
                        'notification' => array(
                                                'title' => 'Client app',
                                                'body' => 'Звонок с двери ' . $nameDoor . ' принял кто-то другой',
                                                'icon' => 'ic_launcher',
                                                'sound' => 'default',
                                                'color' => '#0044ff',
                                                'tag' => 'tf.tantonfirst.qrcodescanner.' . $nameDoor
                                                ),
                        'data' => array('door_name' => $nameDoor,
                        'message' => 'accept_by_not_you')
                        );
        // Данные для отправки
        $header1 = 'Content-Type: application/json;';
        $header2 = 'Authorization: key=AAAAuqIigoM:APA91bFLzDaRTwbN8j0MueGPUSeqi3S2mcEMeBRvg9L-w6GwX2331qE7zxyzhBVBPGaiFRYDxPHN_SJejFZaeiBNI5Zd9luN2IOeTrl2NmmaeV0rH4D6DNTEB1qXmm5c7L8K-6FHI_O3';
        // Указание опций для контекста потока
        $options = array(
                        'http' => array(
                        'method' => 'POST',
                        'header' => $header1 . "\r\n" . $header2 . "\r\n", 
                        'content' => json_encode($request)
                                        )
                        );
        // Создание контекста потока
        $context = stream_context_create($options);
        // Отправка данных и получение результата
        $te = file_get_contents('https://fcm.googleapis.com/fcm/send', 0, $context);
        echo "Push send Answer:" . $te . "\n";
    }

    protected function POSTrequestTimeOut($Token, $nameDoor) {
        $request = array(
                        'to' => $Token,
                        'notification' => array(
                                                'title' => 'Client app',
                                                'body' => 'Никто не принял звонок с двери ' . $nameDoor,
                                                'icon' => 'ic_launcher',
                                                'sound' => 'default',
                                                'color' => '#0044ff',
                                                'tag' => 'tf.tantonfirst.qrcodescanner.' . $nameDoor
                                                ),
                        'data' => array(
                                        'door_name' => $nameDoor,
                                        'message' => '30sec_timeout')
                        );

        // Данные для отправки
        $header1 = 'Content-Type: application/json;';
        $header2 = 'Authorization: key=AAAAuqIigoM:APA91bFLzDaRTwbN8j0MueGPUSeqi3S2mcEMeBRvg9L-w6GwX2331qE7zxyzhBVBPGaiFRYDxPHN_SJejFZaeiBNI5Zd9luN2IOeTrl2NmmaeV0rH4D6DNTEB1qXmm5c7L8K-6FHI_O3';
        // Указание опций для контекста потока
        $options = array(
                        'http' => array(
                                        'method' => 'POST',
                                        'header' => $header1 . "\r\n" . $header2 . "\r\n", 
                                        'content' => json_encode($request)
                                        )
                        );
        // Создание контекста потока
        $context = stream_context_create($options);
        // Отправка данных и получение результата
        $te = file_get_contents('https://fcm.googleapis.com/fcm/send', 0, $context);
        echo "Push send TimeOut:" . $te . "\n";
    }

    protected function POST() {
        $fp = fsockopen('osora.ru', 8001, $errno, $errstr, 30); 

        if (!$fp) {
            echo "$errstr ($errno)\n";
            return false;
        }
        else {
            $out = "GET / HTTP/1.1\r\n"; 
            $out .= "Host: www.osora.ru\r\n"; 
            $out .= "Connection: Close\r\n\r\n";
            fwrite($fp, $out); 
            feof($fp); 
            $he=fgets($fp,15);
            fclose($fp);
        }

        if(substr($he,9,12)==200) {
            echo "Status server OK\n";
            return true;
        } 
        else
            return false;
    }

    protected function ServerStatus() {
        if ($this->POST())
            return '1';
        else
            return '0';
    }

    protected function CheckStatusServer() {
        $StatusServer = $this->ServerStatus();
        if ($StatusServer != $this->OldStatusServer) {
            $AllDevice = array_merge($this->deviceAndroid, $this->deviceDoor);
            foreach ($AllDevice as $Device) {
                $Device->send(json_encode(array('webrtc_status' => $StatusServer)));
            }
            $this->OldStatusServer = $StatusServer;
            echo 'Status server changed. Status: ' . $StatusServer . "\n";
        }
    }

    protected function CheckDevices() {
        $pdo = new PDO('mysql:host=localhost;dbname=android_devices_rtc', 'root', 'root');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("SELECT device_group FROM users WHERE GUID=?");

        if ($this->deviceGroup) {
            $SArray = array_merge($this->deviceAndroid, $this->deviceDoor);
            foreach ($this->deviceGroup as $key => $value) {
                $stmt->execute(array($key)); 
                $dGroup = $stmt->fetchColumn();
                if ($value != $dGroup && $dGroup) {
                    $connect = $SArray[$key];
                    echo "User: " . $key . " chanched device_group.\n";
                    $this->deviceGroup[$key] = $dGroup;
                    echo "Old group: " . $value . " New group: " . $dGroup . "\n";
                }
                if (!$dGroup) {
                    echo "User: " . $key . " Not found GUID\n";
                    $connect = $SArray[$key];
                    $connect->send(json_encode(array('authorization' => 0, 'webrtc_status' => $this->ServerStatus())));
                    unset($this->deviceAndroid[$key]);
                    unset($this->deviceDoor[$key]);
                    unset($this->deviceGroup[$key]);
                }
            }
        }
    }

}