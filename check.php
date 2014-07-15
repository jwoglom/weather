<?php
class WeatherChecker {

    var $db;

    function __construct() {
        $this->db = new SQLite3("database.sqlite3");
    }

    function run() {
        $this->get_entries();
        return $this->loop_entries();
    }

    function urlshorten($url) {
        $c = curl_init();
        curl_setopt($c, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
        curl_setopt($c, CURLOPT_URL, "https://www.googleapis.com/urlshortener/v1/url");
        curl_setopt($c, CURLOPT_POST, 1);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($c, CURLOPT_SSL_VERIFYHOST, false); // don't check certificate
        curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($c, CURLOPT_POSTFIELDS, json_encode(array("longUrl" => $url)));
        $res = curl_exec($c);
        curl_close($c);
        $j = json_decode($res);
        return $j->id;

    }

    var $entries;

    function get_entries() {
        $url = "http://alerts.weather.gov/cap/wwaatmget.php?x=VAC600&y=0&ts=".time();
        $urlcontents = file_get_contents($url);
        $xml = simplexml_load_string($urlcontents);
        $arr = json_decode(json_encode($xml), TRUE);
        $entries = $arr['entry'];
        if(isset($entries['id'])) {
            $entries = array(0 => $entries);
        }
        $this->entries = $entries;
    }

    function loop_entries() {
        $ret = array();
        foreach($this->entries as $entry) {
            $postid = explode("?x=", $entry['id'])[1]; // actual ID
            $fr = $this->find_row($postid); // See if row exists and is notified
            $msg = $this->get_message($entry);
            if(true) { //!$fr) {
                $this->add_row($postid, $msg); // Add row if doesn't exist
                $ret[] = $msg;
            } else echo "Not notifying $postid -- already notified. \n";
        }
        return $ret;
    }

    function get_message($entry) {
        $text = $entry['title'];
        $short = $this->urlshorten($entry['link']['@attributes']['href']);
        // Keep message under 160 chars
        if((strlen($text) + strlen($short) + 2) > 160) {
            $text = substr($text, 0, 157 - (strlen($short) + 2))."...";
        }
        $msg = $text.": ".$short;
        return $msg;
    }

    function find_row($id) {
        $query = $this->db->query("SELECT irc_notified FROM messages WHERE " .
            "id='" . $this->db->escapeString($id) . "' LIMIT 1;"
        );
        return ($query->numColumns() != 0);
    }

    function add_row($id, $message) {
        if($this->find_row($id)) return;
        $this->db->query("INSERT INTO messages VALUES(" .
            "'" . $this->db->escapeString($id) . "', " .
            "'" . $this->db->escapeString($message) . "', " .
            "1);"
        );
    }

    function mark_notified($msgid) {
        $this->db->query("UPDATE TABLE messages SET irc_notified=1 WHERE id='" . $this->db->escapeString($msgid) . "'");
    }
}
/*
class IRCWeatherChecker extends WeatherChecker {
    var $irc;
    var $ircchan;

    function __construct($opts) {
        echo "Forking..\n";
        $pid = pcntl_fork();
        if($pid == -1) die("Error.");
        else if($pid) {
            // Parent.
            $this->irc = new IRCBot($opts);
        } else {
            // Child
            $this->ircchan = $opts['channel'];
            echo "Continuing..\n";
            parent::__construct();
        }   
    }


    function notify($msg) {
        echo "Subnotify $msg \n";
        $this->irc->send_message($this->ircchan, $msg);
        parent::notify($msg);
    }

    function run_notify($msg) {
        return $this->notify($msg);
    }
}
*/
class IRCWeatherChecker {

        var $todo;

        var $weather;

        // This is going to hold our TCP/IP connection
        var $socket;
        var $config;

        // This is going to hold all of the messages both server and client
        var $ex = array();

        function __construct($config) {
                echo "IRC: Starting.\n";
                $this->todo = array();
                $this->weather = new WeatherChecker($this->todo);
                $this->socket = fsockopen($config['server'], $config['port']);
                $this->config = $config;
                $this->login($config);
                $this->main($config);
        }

        function login($config) {
                $this->send_data('USER', $config['nick'].' wogloms.com '.$config['nick'].' :'.$config['name']);
                $this->send_data('NICK', $config['nick']);
        $this->join_channel($config['channel']);
                echo "IRC: Logged in.\n";
        }

        function main($config) {   
            $data = fgets($this->socket, 256);
            echo "Got: $data";
            flush();
            $this->ex = explode(' ', $data);
            if($this->ex[0] == 'PING') {
                    $this->send_data('PONG', $this->ex[1]); //Plays ping-pong with the server to stay connected.
            }
            if(sizeof($this->ex) >= 4) {
                $command = str_replace(array(chr(10), chr(13)), '', $this->ex[3]);
                $message = "";
                for($i=4; $i <= (count($this->ex)); $i++) {
                    if(isset($this->ex[$i])) {
                        $message .= $this->ex[$i]." ";
                    }
                }
                switch($command) {//List of commands the bot responds to from a user.
                    case ':@join':
                        $this->join_channel($this->ex[4]);
                        break;                     
                    case ':@part':
                        $this->send_data('PART '.$this->ex[4].' :', 'Bot leaving');
                        break;   

                    case ':@say':
                        $this->send_data('PRIVMSG '.$this->ex[2].' :'.$message);
                        break;

                    case ':@check':
                        $this->check_weather();
                        break;

                    case ':@shutdown':
                        $this->send_data('QUIT', 'Bot quitting');
                        exit;

                }
            }

            usleep(50000);

            $this->main($config);
        }



        function send_data($cmd, $msg = null) {
            if($msg == null) {
                fputs($this->socket, $cmd."\r\n");
                echo "Sent: ".$cmd."\n";
            } else {
                fputs($this->socket, $cmd.' '.$msg."\r\n");
                echo "Sent: ".$cmd." ".$msg."\n";
            }
        }

        function join_channel($channel) {
            if(is_array($channel)) {
                foreach($channel as $chan) {
                    $this->send_data('JOIN', $chan);
                }
            } else {
                $this->send_data('JOIN', $channel);
            }
        }

        function check_weather($all) {
            $ret = $this->weather->run();
            if(sizeof($ret) > 0) {
                foreach($ret as $txt) {
                    if(strpos(trim(lower($txt)), 0, 19) != "there are no active" || $all) {
                        $this->send_data("PRIVMSG ".$this->config['channel']." :".$txt);
                    }
                }
            }
        }

}


new IRCWeatherChecker(array(
    "server" => "chat.freenode.net",
    "port" => 6667,
    "channel" => "***REMOVED***",
    "name" => "tjWeather",
    "nick" => "tjWeather",
    "pass" => ""
));
?>
