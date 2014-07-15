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
            // echo "\n fr = $fr for $postid\n\n";
            $msg = $this->get_message($entry);
            if($fr == false || $all) {
                if($all) {
                    echo "Force continuing: $postid\n";
                }
                else {
                    echo "Trying to add row: $postid\n";
                    $this->add_row($postid, $msg); // Add row if doesn't exist
                }
                $ret[] = $msg;
            } else echo "Not notifying $postid -- already notified. \n";
        }
        echo "Returning:";
        print_r($ret);
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

    function clearall() {
        $this->db->query("DELETE FROM messages WHERE 1;");
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
            if(file_exists(".lock")) unlink(".lock");
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
                        if(file_exists(".lock")) {
                            echo "Time to go bye bye.\n\n";
                            exit();
                        }
                    }
                }
                }   
        }

        function login($config) {
                $this->send_data('USER', $config['nick'].' wogloms.com '.$config['nick'].' :'.$config['name']);
                $this->send_data('NICK', $config['nick']);
                $this->join_channel($config['channel']);
                $this->join_dbchannels();
                echo "IRC: Logged in.\n";
        }

        function main($config) {
            $this->timestamp = time();
            $data = fgets($this->socket, 256);
            echo "Got: $data";
            flush();
            while(sizeof($this->todo) > 0) {
                $this->send_data(array_shift($this->todo));
            }
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
                    if($this->isadmin($this->ex[0])) {
                        $this->sayprimary("Sending join to ".$this->ex[4]);
                        $this->join_channel($this->ex[4]);
                    }
                        break;

                    case ':@part':
                    if($this->isadmin($this->ex[0])) {
                        $this->sayprimary("Sending part to ".$this->ex[4]);
                        $this->part_channel($this->ex[4]);
                    }
                        break;   

                    case ':@say':
                    if($this->isadmin($this->ex[0])) {
                        $loc = $this->ex[2];
                        if($loc == $this->config['nick']) {
                            echo "Sending PM to main channel from ".$this->ex[0]."\n";
                            $loc = $this->config['channel'];
                        }
                        $this->send_data('PRIVMSG '.$loc.' :'.$message);
                    }
                        break;

                    case ':@sayto':
                    if($this->isadmin($this->ex[0])) {
                        $loc = $this->ex[4];
                        $message2 = "";
                        for($i=5; $i <= (count($this->ex)); $i++) {
                            if(isset($this->ex[$i])) {
                                $message2 .= $this->ex[$i]." ";
                            }
                        }
                        $this->sendto($loc, $message2);
                    }
                        break;

                    case ':@reboot':
                    if($this->isadmin($this->ex[0])) {
                        $this->sayprimary("Restarting..");
                        $this->reboot();
                    }
                        break;

                    case ':@set':
                    if($this->isadmin($this->ex[0])) {
                        $this->setconfig($this->ex[4], $this->ex[5]);
                    }
                        break;

                    case ':@get':
                    if($this->isadmin($this->ex[0])) {
                        $this->sayto($this->ex[2], $this->getconfig($this->ex[4]));
                    }
                        break;

                    case ':@check':
                        $this->check_weather(isset($this->ex[4]) ? ($this->ex[4] == "true" ? true : false) : true);
                        break;

                    case ':@help':
                        $this->sayto($this->ex[2], "I am a bot which displays Weather.gov alerts. Direct all inquiries to jwoglom.");
                        $this->sayto($this->ex[2], "To manually check for alerts, run @check. To follow alerts, run /msg ".$this->config['nick']." @follow");
                        break;

                    case ':@clearmessages':
                    if($this->isadmin($this->ex[0])) {
                        if(isset($this->ex[4]) && trim($this->ex[4]) == "yes") {
                            $this->weather->clearall();
                            $this->sayprimary("Cleared all saved messages.");
                        } else $this->sayprimary("Add yes to confirm.");
                    }
                        break;

                    case ':@clearchannels':
                    if($this->isadmin($this->ex[0])) {
                        if(isset($this->ex[4]) && trim($this->ex[4]) == "yes") {
                            foreach($this->getchans() as $ch) {
                                $this->rm_dbchannel($ch);
                            }
                            $this->sayprimary("Cleared all saved channels.");
                        } else $this->sayprimary("Add yes to confirm.");
                    }
                        break;

                    case ':@listchannels':
                    if($this->isadmin($this->ex[0])) {
                        foreach($this->getchans() as $ch) {
                            $str .= $ch." ";
                        }
                        $this->sayprimary("Joined to: $str");
                    }

                    case ':@runcustom':
                    if($this->isadmin($this->ex[0])) {
                        $this->sayprimary("Running ".$message);
                        $this->send_data($message);
                    }
                        break;

                    case ':@eval':
                        if($this->isadmin($this->ex[0])) {
                            try { eval($message); }
                            catch(Exception $e) {
                                $this->sayprimary("Exception $e occurred");
                            }
                        }
                        break;

                    case ':@follow':
                        // Add them to db table
                        $username = explode("@", $this->ex[0])[0];
                        $this->add_dbchannel($username);
                        $this->sayto($username, "You are now following weather alerts.");
                        $this->sayto($username, "You may stop recieving messages at any time by running:");
                        $this->sayto($username, "/msg ".$this->config['nick']." @unfollow");
                        break;

                    case ':@unfollow':
                        // Remove them from db table
                        $username = explode("@", $this->ex[0])[0];
                        $this->rm_dbchannel($username);
                        $this->sayto($username, "You will no longer recieve weather alerts.");
                        break;

                    case ':@quit':
                    if($this->isadmin($this->ex[0])) {
                        $this->send_data('QUIT', 'Bot quitting');
                        $this->timestamp = 0;
                    }
                        exit;

                }
            }

            usleep(50000);

            $this->main($config);
        }

        function getchans() {
            $sql = $this->weather->db->query("SELECT * FROM channels");
            $ret = array();
            while($chan = $sql->fetchArray()) {
                $ret[] = $chan['name'];
            }
            echo "Current db chans:\n";
            print_r($ret);
            return $ret;
        }

        function isadmin($host) {
            return explode("@", $host)[1] == "unaffiliated/jwoglom";
        }

        function sayto($chan, $txt) {
            $this->send_data("PRIVMSG ".$chan." :".$txt);
        }

        function sayprimary($txt) {
            $this->send_data("PRIVMSG ".$this->config['channel']." :".$txt);
        }

        function send_data($cmd, $msg = null) {
            if($msg == null) {
                fputs($this->socket, $cmd."\r\n");
                if(strpos($cmd, "NickServ :identify") !== false) {
                    $cmd = " ** NickServ identify command **";
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
                    $this->send_data('JOIN', trim($chan));
                    $this->add_dbchannel(trim($chan));
                }
            } else {
                $this->send_data('JOIN', $channel);
                $this->add_dbchannel($channel);
            }
        }

        function add_dbchannel($channel) {
            if(strlen(trim($channel)) > 0) { /*  && strpos($channel, "#") !== false */
                $this->weather->db->query("INSERT OR REPLACE INTO channels VALUES('" . $this->weather->db->escapeString(trim($channel)) . "');");
            }
        }

        function rm_dbchannel($channel) {
            $this->weather->db->query("DELETE FROM channels WHERE name='" . $this->weather->db->escapeString($channel) . "';");
        }

        function join_dbchannels() {
            $this->add_dbchannel($this->config['channel']);
            $sql = $this->weather->db->query("SELECT * FROM channels");
            while($chan = $sql->fetchArray()) {
                $ch = trim($chan['name']);
                if($ch == $this->config['channel']) {
                    // Already joined, main channel
                } else {
                    if(substr($ch, 0, 1) == "#") {
                        echo "Joining $ch\n";
                        array_push($this->todo, "JOIN $ch");
                        // Don't add_dbchannel because it's already in the database
                    } else {
                        // User -- don't join
                        echo "Followed user $ch\n";
                    }
                }
            }
        }

        function part_channel($channel) {
            $this->send_data('PART '.$channel.' :', 'Bot leaving');
            $this->rm_dbchannel($channel);
        }

        function check_weather($all) {
            $ret = $this->weather->run($all);
            if(sizeof($ret) > 0) {
                foreach($ret as $txt) {
                    if(strlen($txt) > 1 && (substr(trim(strtolower($txt)), 0, 19) != "there are no active" || $all || $this->getconfig("showall") == true)) {
                        echo "\nSending: $txt \n";
                        foreach($this->getchans() as $ch) {
                            $this->sayto($ch, $txt);
                        }
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
            if($this->timestamp == 0) die();
            echo "Running shutdown functions...";
            touch(".lock");
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
    "channel" => "#tjcsl-weather", // Primary channel
    "name" => "tjWeather",
    "nick" => "tjWeather",
    "ns" => array(
        "user" => "tjWeather",
        "pass" => base64_decode(file_get_contents("pass.txt"))
    )
));
?>
