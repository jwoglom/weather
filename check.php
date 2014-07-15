<?php
class WeatherChecker {

    var $db;

    function __construct() {
        $this->db = new SQLite3("database.sqlite3");
    }

    function run($all) {
        $this->get_entries();
        return $this->loop_entries($all);
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

    function loop_entries($all) {
        $ret = array();
        if(!isset($this->entries)) return array();
        foreach($this->entries as $entry) {
            $postid = explode("?x=", $entry['id'])[1]; // actual ID
            $fr = $this->find_row($postid); // See if row exists and is notified
            echo "\n fr = $fr for $postid\n\n";
            $msg = $this->get_message($entry);
            if($fr == false || $all) {
                if($all) {
                    echo "Force adding row: $postid\n";
                    $msg = "";
                }
                else echo "Trying to add row: $postid\n";
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
        $arr = $query->fetchArray();
        var_dump($arr);
        if(count($arr) > 0 && $arr != false) {
            echo "Found $id\n";
            return true;
        } else {
            "Not found $id\n";
            return false;
        }
    }

    function add_row($id, $message) {
        if($this->find_row($id) === true) {
            echo "Trying to add existent row.";return;
        }
        $this->db->query("INSERT INTO messages VALUES(" .
            "'" . $this->db->escapeString($id) . "', " .
            "'" . $this->db->escapeString($message) . "', " .
            "1);"
        );
    }

    function mark_notified($msgid) {
        $this->db->query("UPDATE TABLE messages SET irc_notified=1 WHERE id='" . $this->db->escapeString($msgid) . "'");
    }

    function getconfig($key) {
        $q = $this->db->query("SELECT key,value FROM config WHERE key='" . $this->db->escapeString($key) . "';");
        if(isset($q, $q->value)) {
            return $q->value;
        } else {
            return null;
        }
    }

    function setconfig($key, $value) {
        $this->db->query("DELETE FROM config WHERE key = '" . $this->db->escapeString($key) . "';");
        $this->db->query("INSERT INTO config VALUES('" . $this->db->escapeString($key) . "', '" . $this->db->escapeString($value) . "');");
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

        var $timestamp;

        // This is going to hold our TCP/IP connection
        var $socket;
        var $config;

        // This is going to hold all of the messages both server and client
        var $ex = array();

        function __construct($config) {
            echo "IRC: Starting.\n";
            if(isset($config['ns'])) {
                $nscmd = "PRIVMSG NickServ :identify ".$config['ns']['user']." ".$config['ns']['pass'];
            } else $nscmd = "";
            $this->todo = array($nscmd);
            
            $this->timestamp = time();
            $this->weather = new WeatherChecker($this->todo);
            $this->socket = fsockopen($config['server'], $config['port']);
            $this->config = $config;
            $this->login($config);

            register_shutdown_function(array($this, 'handle_shutdown'));

            echo "Forking..\n";
            $pid = pcntl_fork();
            if($pid == -1) die("Error.");
            else if($pid) {
                // Parent.
                $this->main($config);
            } else {
                // Child
                while(true) {
                    echo "Running background check.\n";
                    $this->check_weather(false);
                    for($i=0; $i<30; $i+=5) {
                        sleep(5);
                        if(time() - $this->timestamp >= 300) {
                            echo "My time is ".time().", ts is ".$this->timestamp."\n";
                            // exit();
                        }
                    }
                }
                }   
        }

        function login($config) {
                $this->send_data('USER', $config['nick'].' wogloms.com '.$config['nick'].' :'.$config['name']);
                $this->send_data('NICK', $config['nick']);
        $this->join_channel($config['channel']);
                echo "IRC: Logged in.\n";
        }

        function main($config) {
            $this->timestamp = time();
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
                        $loc = $this->ex[2];
                        if($loc == $this->config['nick']) {
                            echo "Sending PM to main channel from ".$this->ex[0]."\n";
                            $loc = $this->config['channel'];
                        }
                        $this->send_data('PRIVMSG '.$loc.' :'.$message);
                        break;

                    case ':@restart':
                        $this->say("Restarting..");
                        $this->reboot();

                    case ':@set':
                        $this->setconfig($this->ex[4], $this->ex[5]);
                        break;

                    case ':@get':
                        $this->say($this->getconfig($this->ex[4]));
                        break;

                    case ':@check':
                        $this->check_weather(true);
                        break;

                    case ':@shutdown':
                        $this->send_data('QUIT', 'Bot quitting');
                        $this->timestamp = 0;
                        exit;

                    case ':@help':
                        $this->say("I am a bot which displays Weather.gov alerts. Direct all inquiries to jwoglom.");
                        $this->say("To manually check for alerts, run @check.");

                }
            }

            usleep(50000);

            $this->main($config);
        }

        function say($txt) {
            return $this->send_data("PRIVMSG ".$this->config['channel']." :".$txt);
        }

        function send_data($cmd, $msg = null) {
            if($msg == null) {
                fputs($this->socket, $cmd."\r\n");
                if(strpos($cmd, "NickServ :identify") !== false) {
                    $cmd = "NickServ identify command";
                }
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
            $ret = $this->weather->run($all);
            if(sizeof($ret) > 0) {
                foreach($ret as $txt) {
                    if(substr(trim(strtolower($txt)), 0, 19) != "there are no active" || $all || $this->getconfig("showall") == true) {
                        echo "\nSending: $txt \n";
                        $this->say($txt);
                    } else echo "\nNot sending: $txt \n";
                }
            }
        }

        function getconfig($key) {
            return $this->weather->getconfig($key);
        }

        function setconfig($key, $val) {
            return $this->weather->setconfig($key, $val);
        }

        function handle_shutdown() {
            echo "Running shutdown functions...";
            $this->timestamp = 0;
            $tmp = null;
            die();
        }

        function reboot() {
            die(exec('php '.join(' ', $GLOBALS['argv']) . ' > /dev/null &'));
        }

}


new IRCWeatherChecker(array(
    "server" => "chat.freenode.net",
    "port" => 6667,
    "channel" => "***REMOVED***",
    "name" => "tjWeather",
    "nick" => "tjWeather",
    "ns" => array(
        "user" => "tjWeather",
        "pass" => base64_decode(file_get_contents("pass.txt"))
    )
));
?>
