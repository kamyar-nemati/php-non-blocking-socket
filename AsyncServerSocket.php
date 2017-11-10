<?php

//keep process id
$pid = getmypid(); //the main/parent's process id
$child_pid = NULL; //the child process id
$sub_pid_arr = []; //the list of all forked/created child/handler processes

//show all sorts of error messages
error_reporting(E_ALL);

//no execution time limit
set_time_limit(0);

//reserved flags to control socket communication
define("STX", "<-STX->"/*chr(2)*/);
define("EOT", "<-EOT->"/*chr(4)*/);
define("CAN", "<-CAN->"/*chr(24)*/);

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

//the server-listening flag handles termination
$server_listening = TRUE;

//the server-sleeping flag handles server stop and continuation
$server_sleeping = FALSE;

//the frequency of checking whether server is sleeping or not (seconds)
const _FOR_A_WHILE = 2;

//initiating the socket
try {
    //creating the socket
    $serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($serverSocket === FALSE)
    {
        //creation failed
        $last_err_msg = socket_strerror(socket_last_error());
        throw new Exception("onCreate: {$last_err_msg}");
    }
    echo "Socket created" . ENDL;
    
    //binding the socket
    $foo = socket_bind($serverSocket, $host, $port);
    if ($foo === FALSE)
    {
        //binding failed
        $last_err_msg = socket_strerror(socket_last_error($serverSocket));
        throw new Exception("onBind: {$last_err_msg}");
    }
    echo "Socket bound on {$host}:{$port}" . ENDL;
    
    //making the socket listen
    $bar = socket_listen($serverSocket, $somaxconn);
    if ($bar === FALSE)
    {
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
    global $sub_pid_arr;
    global $serverSocket;
    global $server_listening;
    global $server_sleeping;

    //handling client
    try {
        //accepting the client
        $clientSock = socket_accept($serverSocket);
        if ($clientSock === FALSE)
        {
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
             * parent thread may either have the created child's process
             * id or (-1) upon failure.
             */
            echo "Failed to create handler" . ENDL;
        }
        
        //print out the handler's process id
        if ($child_pid === 0) {
            echo "--Handler created: " . posix_getpid() . ENDL;
        }
        
        //the main process must only accept client, no handling
        if (getmypid() === $pid) {
            //keep track of all child processes
            $sub_pid_arr[$child_pid] = $child_pid;
            
            //check for any pending signal
            pcntl_signal_dispatch();
            
            //accepting clients as long as server is listening
            if ($server_listening) {
                
                //checking if the process is stopped
                while ($server_sleeping) {
                    
                    //check for any pending signal
                    pcntl_signal_dispatch();
                    
                    //sleep
                    sleep(_FOR_A_WHILE);
                    echo "Zzz..." . ENDL;
                }
                //accept new clients
                return begin_accept();
            } else {
                //server gonna get terminated
                return;
            }
        } else {
            //child process shall not listen for other clients
            $server_listening = FALSE;
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
        if ($yow === FALSE)
        {
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
            if ($packet === FALSE)
            {
                $last_err_msg = socket_strerror(socket_last_error($client->socket));
                throw new Exception("onRead: {$last_err_msg}");
            }

            //get rid of rubbish around the received packet
            $client->buffer = trim($packet);

            echo "Packet received by " . posix_getpid() . ": {$client->buffer}" . ENDL;

            //check if the 'Cancel' flag is received
            if ($client->buffer === CAN)
            {
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
        if ($gee === FALSE)
        {
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
        //do nothing...
    }

    //release memory (maybe)
    unset($client);
}

//process control setup
if (getmypid() === $pid) {
    //process signal handler
    $sig_handler = function ($sig_no) {
        //read global variable
        global $sub_pid_arr;
        global $server_listening;
        global $server_sleeping;
        
        //handle signals
        /*
         * For the full list of Unix signals and
         * their detailed descriptions, please visit:
         * https://en.wikipedia.org/wiki/Signal_(IPC)
         */
        switch ($sig_no) {
            case SIGABRT:
            case SIGIOT:
//            case SIGBUS:
//            case SIGFPE:
//            case SIGILL:
//            case SIGPIPE:
//            case SIGSEGV:
//            case SIGSYS:
            case SIGHUP:
            case SIGINT:
//            case SIGKILL:
            case SIGQUIT:
            case SIGTERM:
//            case SIGSTOP:
            case SIGTSTP: {
                echo "Server stopped" . ENDL;
                //tell the server to stop listening to any incoming connections
                $server_listening = FALSE;
                break;
            }

            case SIGUSR1: {
                if ($server_sleeping) {
                    echo "Server woke up" . ENDL;
                    $server_sleeping = FALSE;
                } else {
                    echo "Server slept" . ENDL;
                    $server_sleeping = TRUE;
                }
                break;
            }
            
            case SIGCHLD: {
                //clean up all child processes who finished their job
                foreach ($sub_pid_arr as $key => &$val) {
                    $ch_stat = NULL;
                    $ch_psid = pcntl_waitpid($val, $ch_stat, WNOHANG);
                    if ($ch_psid !== 0) {
                        //remove the child process id from the list
                        unset($sub_pid_arr[$val]);
                        echo "--Handler destroyed: {$ch_psid}" . ENDL;
                    }
                }
                break;
            }

            default: {
                echo "An unexpected signal caught: " . $sig_no . ENDL;
                break;
            }
        }
    };
    
    //define the signal handler
    pcntl_signal(SIGABRT, $sig_handler);
    pcntl_signal(SIGIOT, $sig_handler);
    pcntl_signal(SIGHUP, $sig_handler);
    pcntl_signal(SIGINT, $sig_handler);
    pcntl_signal(SIGQUIT, $sig_handler);
    pcntl_signal(SIGTERM, $sig_handler);
    pcntl_signal(SIGTSTP, $sig_handler);
    pcntl_signal(SIGUSR1, $sig_handler);
    pcntl_signal(SIGCHLD, $sig_handler); //sent by children upon destruction
}

//start accepting clients asynchronously
begin_accept();

//process control
if (getmypid() === $pid) {
    //waiting for all child processes to finish
    $p_stat = NULL;
    pcntl_wait($p_stat);
    
    //eliminating the server socket
    socket_shutdown($serverSocket, 2);
    socket_close($serverSocket);
}

/*
 * The code block below is not necessary as
 * any dying child will usually send the
 * signal to its parent.
 */
//signal the parent that a child is dying
//if ($child_pid === 0) {
//    posix_kill($pid, SIGCHLD);
//}


//shutting down
$id = $child_pid === 0 ? getmypid() : $pid;
echo "Shutting down: {$id}" . ENDL;
die();









