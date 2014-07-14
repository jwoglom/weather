<?php


$db = new SQLite3("database.sqlite3");


function googl($url) {
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

function get_entries() {
    $url = "http://alerts.weather.gov/cap/wwaatmget.php?x=VAC600&y=0&ts=".time();
    $urlcontents = file_get_contents($url);
    $xml = simplexml_load_string($urlcontents);
    $arr = json_decode(json_encode($xml), TRUE);
    $entries = $arr['entry'];
    if(isset($entries['id'])) {
        $entries = array(0 => $entries);
    }
    return $entries;
}

function get_message($entry) {
    $text = $entry['title'];
    $short = googl($entry['link']['@attributes']['href']);
    // Keep message under 160 chars
    if((strlen($text) + strlen($short) + 2) > 160) {
        $text = substr($text, 0, 157 - (strlen($short) + 2))."...";
    }
    $msg = $text.": ".$short;
    return $msg;
}

function loop_entries($entries) {
    foreach($entries as $entry) {
        $postid = explode("?x=", $entry['id'])[1];
        $fr = find_row($postid);
        $msg = get_message($entry);
        if($fr == 2) add_row($postid, $msg);
        if($fr >= 1) {
            notify($msg);
        } else echo "Not notifying $postid -- already notified. \n";

    }
}

function find_row($id) {
    global $db;
    $query = $db->query("SELECT irc_notified FROM messages WHERE " .
        "id='" . $db->escapeString($id) . "' LIMIT 1;"
    );
    if($query->numColumns() == 0) {
        return 2;
    } else {
        if($query->fetchArray()['irc_notified'] == 0) {
            return 1;
        } else {
            return true;
        }
    }
}

function add_row($id, $message) {
    global $db;
    echo "INSERT INTO messages VALUES(" .
        "'" . $db->escapeString($id) . "', " .
        "'" . $db->escapeString($message) . "', " .
        "0);";
    $db->query("INSERT INTO messages VALUES(" .
        "'" . $db->escapeString($id) . "', " .
        "'" . $db->escapeString($message) . "', " .
        "0);"
    );
    return true;
}

function mark_notified($msgid) {
    global $db;
    $db->query("UPDATE TABLE messages SET irc_notified=1 WHERE id='" . $db->escapeString($msgid) . "'");
}

loop_entries(get_entries());
?>
