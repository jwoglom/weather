<?php
class WeatherChecker {

    var $db;
    function __construct() {
        $this->db = new SQLite3("database.sqlite3");
        $this->get_entries();
        $this->loop_entries();
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
        foreach($this->entries as $entry) {
            $postid = explode("?x=", $entry['id'])[1]; // actual ID
            $fr = $this->find_row($postid); // See if row exists and is notified
            $msg = $this->get_message($entry);
            if(true) { //!$fr) {
                $this->add_row($postid, $msg); // Add row if doesn't exist
                $this->notify($msg);
            } else echo "Not notifying $postid -- already notified. \n";

        }
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

    function notify($msg) {
        echo "Notifying: $msg \n";
    }
}

class IRCWeatherChecker extends WeatherChecker {
    var $irc;
    var $ircchan;

    function __construct($opts) {
        parent::__construct();
        $this->irc = new IRCBot($opts);
        $this->ircchan = $opts['channel'];
    }
    function notify($msg) {
        $this->irc->send_data("PRIVMSG " . $this->ircchan . " :".$msg);
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
