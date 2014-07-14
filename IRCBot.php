<?php
/**
 * Simple PHP IRC Bot
 * @author     Super3boy <admin@wildphp.com>
 **/
 class IRCBot {

        //This is going to hold our TCP/IP connection
        var $socket;

        //This is going to hold all of the messages both server and client
        var $ex = array();

        /*
        
         Construct item, opens the server connection, logs the bot in
         @param array

        */

        function __construct($config)

        {
                $this->socket = fsockopen($config['server'], $config['port']);
                $this->login($config);
                $this->main($config);
        }



        /*

         Logs the bot in on the server
         @param array

        */

        function login($config)
        {
                $this->send_data('USER', $config['nick'].' wildphp.com '.$config['nick'].' :'.$config['name']);
                $this->send_data('NICK', $config['nick']);
        $this->join_channel($config['channel']);
        }



        /*

         This is the workhorse function, grabs the data from the server and displays on the browser

        */

        function main($config)
        {             
                $data = fgets($this->socket, 256);
                
                echo nl2br($data);
                
                flush();

                $this->ex = explode(' ', $data);


                if($this->ex[0] == 'PING')
                {
                        $this->send_data('PONG', $this->ex[1]); //Plays ping-pong with the server to stay connected.
                }

                $command = str_replace(array(chr(10), chr(13)), '', $this->ex[3]);

                switch($command) //List of commands the bot responds to from a user.
                {                      
                        case ':!join':
                                $this->join_channel($this->ex[4]);
                                break;                     
                        case ':!part':
                                $this->send_data('PART '.$this->ex[4].' :', 'Wildphp.com Free IRC Bot Script');
                                break;   
                                                                 
                        case ':!say':
                                $message = "";
                                for($i=5; $i <= (count($this->ex)); $i++)
                                {
                                        $message .= $this->ex[$i]." ";
                                }
                                
                                $this->send_data('PRIVMSG '.$this->ex[4].' :', $message);
                                break;                              
                        
                        case ':!restart':
                                echo "<meta http-equiv=\"refresh\" content=\"5\">";
                                exit;
                        case ':!shutdown':
                                $this->send_data('QUIT', 'Wildphp.com Free IRC Bot Script');
                                exit;
                }

                $this->main($config);
        }



        function send_data($cmd, $msg = null) //displays stuff to the broswer and sends data to the server.
        {
                if($msg == null)
                {
                        fputs($this->socket, $cmd."\r\n");
                        echo '<strong>'.$cmd.'</strong><br />';
                } else {

                        fputs($this->socket, $cmd.' '.$msg."\r\n");
                        echo '<strong>'.$cmd.' '.$msg.'</strong><br />';
                }

        }



        function join_channel($channel) //Joins a channel, used in the join function.
        {

                if(is_array($channel))
                {
                        foreach($channel as $chan)
                        {
                                $this->send_data('JOIN', $chan);
                        }

                } else {
                        $this->send_data('JOIN', $channel);
                }
        }     
}