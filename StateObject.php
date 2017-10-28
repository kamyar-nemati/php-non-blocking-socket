<?php

class StateObject {
    public $host;
    public $port;
    public $socket;
    public $buffer;
    public $message;
    
    public function __construct($host, $port, $socket) {
        $this->host = $host;
        $this->port = $port;
        $this->socket = $socket;
        $this->buffer = "";
        $this->message = "";
    }
}
