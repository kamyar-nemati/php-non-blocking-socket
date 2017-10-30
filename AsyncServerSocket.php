<?php

//keep process id
$pid = getmypid(); //the main/parent's process id
$child_pid = NULL; //the child process id

//show all sorts of error messages
error_reporting(E_ALL);

//no execution time limit
set_time_limit(0);

//reserved flags to control socket communication
define("STX", /*"<-STX->"*/chr(2));
define("EOT", /*"<-EOT->"*/chr(4));
define("CAN", /*"<-CAN->"*/chr(24));

//end of line character
define("ENDL", chr(10));

//the state object that is responsible to handle each client
require_once dirname(__FILE__) . '/StateObject.php';

//get the maximum number of socket connections that the system can handle
$somaxconn = (int)shell_exec("cat /proc/sys/net/core/somaxconn");

//buffer size
const _LENGTH = 1024;

//read the config file
$config = parse_ini_file("config.ini", TRUE);
$host = $config['server']['host'];
$port = $config['server']['port'];

//initiating the socket
try {
    //creating the socket
    $serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($serverSocket === FALSE) {
        //creation failed
        $last_err_msg = socket_strerror(socket_last_error());
        throw new Exception("onCreate: {$last_err_msg}");
    }
    echo "Socket created" . ENDL;
    
    //binding the socket
    $foo = socket_bind($serverSocket, $host, $port);
    if ($foo === FALSE) {
        //binding failed
        $last_err_msg = socket_strerror(socket_last_error($serverSocket));
        throw new Exception("onBind: {$last_err_msg}");
    }
    echo "Socket bound on {$host}:{$port}" . ENDL;
    
    //making the socket listen
    $bar = socket_listen($serverSocket, $somaxconn);
    if ($bar === FALSE) {
        //listening failed
        $last_err_msg = socket_strerror(socket_last_error($serverSocket));
        throw new Exception("onListen: {$last_err_msg}");
    }
    echo "Socket is listening" . ENDL;
} catch (Exception $ex) {
    //dump and die on failure
    echo "Failure {$ex->getMessage()}" . ENDL;
    echo "Terminating" . ENDL;
    die();
}

//accept new clients
function begin_accept() {
    //get global variables
    global $pid;
    global $child_pid;
    global $serverSocket;

    //handling client
    try {
        //accepting the client
        $clientSock = socket_accept($serverSocket);
        if ($clientSock === FALSE) {
            //accepting failed
            $last_err_msg = socket_strerror(socket_last_error($serverSocket));
            throw new Exception("onAccept: {$last_err_msg}");
        }
        
        //fork the process to handle each client separately
        $child_pid = pcntl_fork();

        //this is for parent process to warn if it failed to fork
        if ($child_pid === -1) {
            /*
             * the variable 'child_pid' shall never be anything but
             * zero(0) within the child process thread. However, the
             * parent thread may either have the created child process
             * id or (-1) upon failure.
             */
            echo "Failed to create handler" . ENDL;
        }
        
        //the main process must only accept client, no handling
        if (getmypid() === $pid) {
            return begin_accept();
        }

        /*
         * From this point onward, the child process handles the client.
         * The parent process will continue accepting more clients and
         * throwing new child processes to handle them.
         */
        
        $clientHost = NULL;
        $clientPort = NULL;
        
        //get client's info
        $yow = socket_getpeername($clientSock, $clientHost, $clientPort);
        if ($yow === FALSE) {
            echo "Failed to get client's identity" . ENDL;
        }
        echo "Connection accepted from {$clientHost}:{$clientPort}" . ENDL;
    } catch (Exception $ex) {
        echo "Failure {$ex->getMessage()}" . ENDL;
        return;
    }
    
    //create a state object responsible for handling the connected client
    $client = new StateObject($clientHost, $clientPort, $clientSock);
    
    begin_read($client);
}

//reading client's data
function begin_read(&$client) {
    $aborted = FALSE;
    
    do {
        try {
            //read packets from client
            $packet = socket_read($client->socket, _LENGTH, PHP_NORMAL_READ);
            if ($packet === FALSE) {
                $last_err_msg = socket_strerror(socket_last_error($client->socket));
                throw new Exception("onRead: {$last_err_msg}");
            }

            //get rid of rubbish around the received packet
            $client->buffer = trim($packet);

            echo "Packet received: {$client->buffer}" . ENDL;

            //check if the 'Cancel' flag is received
            if ($client->buffer === CAN) {
                echo "Connection terminated by client" . ENDL;
                //abort upon cancellation
                $aborted = TRUE;
                break;
            }
            
            //accumulate message
            $client->message .= $client->buffer;
            
            //wipe off the entire received message upon the 'Start of Text' flag
            if (stripos($client->message, STX) !== FALSE) {
                $client->message = "";
            }
        } catch (Exception $ex) {
            echo "Failure {$ex->getMessage()}" . ENDL;
            break;
        }
    } while (stripos($client->message, EOT) === FALSE);
    
    if (!$aborted) {
        //finalizing the received message
        $client->message = str_replace(EOT, "", $client->message);
        echo "Received message: {$client->message}" . ENDL;
        
        begin_write($client);
    } else {
        begin_shutdown($client);
    }
}

//writing to the client
function begin_write(&$client) {
    //preparing the message to be sent to the client
    $client->message .= ENDL;
    
    try {
        //write
        $gee = socket_write($client->socket, $client->message, strlen($client->message));
        if ($gee === FALSE) {
            $last_err_msg = socket_strerror(socket_last_error($client->socket));
            throw new Exception("onWrite: {$last_err_msg}");
//            throw new Exception();
        }
    } catch (Exception $ex) {
        echo "Failure {$ex->getMessage()}" . ENDL;
    }

    begin_shutdown($client);
}

//shutting down the client socket
function begin_shutdown(&$client) {
    try {
        socket_shutdown($client->socket, 2);
        socket_close($client->socket);

        echo "Connection closed from {$client->host}:{$client->port}" . ENDL;
    } catch (Exception $ex) {

    }

    //release memory
    unset($client);
}

//start accepting clients asynchronously
begin_accept();

if (getmypid() === $pid) {    
    //waiting for all child processes to finish
    $p_stat = NULL;
    pcntl_wait($p_stat);
    
    //eliminating the server socket
    socket_shutdown($serverSocket, 2);
    socket_close($serverSocket);
}

//shutting down
$id = $child_pid === 0 ? getmypid() : $pid;
echo "Shutting down: {$id}" . ENDL;
die();









