<?php
/**
 * Created by PhpStorm.
 * User: liyang
 * Date: 2018/1/12
 * Time: 14:02
 */

namespace app\index\controller;

use app\common\controller\HomeBase;
use think\config;
use think\exception;
use think\Request;
use think\File;
use think\Db;
use think\Session;
use think\ucpaas\Ucpaas;

class Socket extends HomeBase{
    protected $host;
    protected $port;
    protected $null;
    public function _initialize()
    {
        parent::_initialize(); // TODO: Change the autogenerated stub
        $this->host = 'http://www.juedouxin.com';
        $this->port = 80;
        $this->null = NULL;

//创建tcp socket
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
//        var_dump( $this->port);
        socket_bind($socket, 0, $this->port);

//监听端口
        socket_listen($socket);

//连接的client socket 列表
        $clients = array($socket);

//设置一个死循环,用来监听连接 ,状态
        while (true) {

            $changed = $clients;
            socket_select($changed, $this->null, $this->null, 0, 10);

            //如果有新的连接
            if (in_array($socket, $changed)) {
                //接受并加入新的socket连接
                $socket_new = socket_accept($socket);
                $clients[] = $socket_new;

                //通过socket获取数据执行handshake
                $header = socket_read($socket_new, 1024);
                $this->perform_handshaking($header, $socket_new, $this->host,  $this->port);

                //获取client ip 编码json数据,并发送通知
                socket_getpeername($socket_new, $ip);
                $response = $this->mask(json_encode(array('type'=>'system', 'message'=>$ip.' connected')));
                $this->send_message($response);
                $found_socket = array_search($socket, $changed);
                unset($changed[$found_socket]);
            }

//轮询 每个client socket 连接
            foreach ($changed as $changed_socket) {

                //如果有client数据发送过来
                while(socket_recv($changed_socket, $buf, 1024, 0) >= 1)
                {
                    //解码发送过来的数据
                    $received_text =  $this->unmask($buf);
                    $tst_msg = json_decode($received_text);
                    $user_name = $tst_msg->name;
                    $user_message = $tst_msg->message;

                    //把消息发送回所有连接的 client 上去
                    $response_text =  $this->mask(json_encode(array('type'=>'usermsg', 'name'=>$user_name, 'message'=>$user_message)));
                    $this->send_message($response_text);
                    break 2;
                }

                //检查offline的client
                $buf = @socket_read($changed_socket, 1024, PHP_NORMAL_READ);
                if ($buf === false) {
                    $found_socket = array_search($changed_socket, $clients);
                    socket_getpeername($changed_socket, $ip);
                    unset($clients[$found_socket]);
                    $response =  $this->mask(json_encode(array('type'=>'system', 'message'=>$ip.' disconnected')));
                    $this->send_message($response);
                }
            }
        }
// 关闭监听的socket
        socket_close($socket);
    }

    public function index(){
        echo '111';
    }

//发送消息的方法
public function send_message($msg)
{
    global $clients;
    foreach($clients as $changed_socket)
    {
        @socket_write($changed_socket,$msg,strlen($msg));
    }
    return true;
}


//解码数据
    public function unmask($text) {
    $length = ord($text[1]) & 127;
    if($length == 126) {
        $masks = substr($text, 4, 4);
        $data = substr($text, 8);
    }
    elseif($length == 127) {
        $masks = substr($text, 10, 4);
        $data = substr($text, 14);
    }
    else {
        $masks = substr($text, 2, 4);
        $data = substr($text, 6);
    }
    $text = "";
    for ($i = 0; $i < strlen($data); ++$i) {
        $text .= $data[$i] ^ $masks[$i%4];
    }
    return $text;
}

//编码数据
    public function mask($text)
{
    $b1 = 0x80 | (0x1 & 0x0f);
    $length = strlen($text);

    if($length <= 125)
        $header = pack('CC', $b1, $length);
    elseif($length > 125 && $length < 65536)
        $header = pack('CCn', $b1, 126, $length);
    elseif($length >= 65536)
        $header = pack('CCNN', $b1, 127, $length);
    return $header.$text;
}

//握手的逻辑
    public function perform_handshaking($receved_header,$client_conn, $host, $port)
{
    $headers = array();
    $lines = preg_split("/\r\n/", $receved_header);
    foreach($lines as $line)
    {
        $line = chop($line);
        if(preg_match('/\A(\S+): (.*)\z/', $line, $matches))
        {
            $headers[$matches[1]] = $matches[2];
        }
    }

    $secKey = $headers['Sec-WebSocket-Key'];
    $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    $upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
        "Upgrade: websocket\r\n" .
        "Connection: Upgrade\r\n" .
        "WebSocket-Origin: $host\r\n" .
        "WebSocket-Location: ws://$host:$port/demo/shout.php\r\n".
        "Sec-WebSocket-Accept:$secAccept\r\n\r\n";
    socket_write($client_conn,$upgrade,strlen($upgrade));
}
}