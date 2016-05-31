<?php

class Actions {

    public function __construct($config) {
        $this->config = $config;
        $this->Log = new Log($this->config);
        $this->connection = new MongoClient($this->config['mongodb']);
        $this->collection = $this->connection->pybot;
        $this->curl = new Curl();
        $this->socket = null;
        $this->version = $config['version'];
        $this->currentuser = '';
        $this->currentchannel = false;
        $this->message_data = '';
        $this->isIRCOper = false;
        // seperate config array incase we need to override anything(?)
        $linguo_config = $this->config;
        $this->linguo = new Linguo($linguo_config);
        $this->txlimit = 256; // transmission length limit in bytes (chars)
        $this->userCache = array();
        $this->array_key = '';
        $this->myparts = array();
        $this->public_commands = array('version', 'abuse', 'history', 'testtpl', 'you', 'me', 'uptime');

        if ($this->_check_permissions($this->get_current_user())) { $this->write_user("GTFO"); return false; }
        if (!$this->connection) {
            sleep(60);
            // try again
            $this->connection = new MongoClient($this->config['mongodb']);
        }

    }

    private function _check_permissions($nick = '') {
        return in_array($nick, $this->config['banned_users']);
    }

    /* Sets socket for writing to IRC */
    public function setsocket($socket) {
        $this->socket = $socket;
    }

    /* calculates a date timespan (mimicing CI function) */
    private function calculate_timespan($seconds = 1, $time = '', $display_mins_secs = true) {

        if (!is_numeric($seconds)) {
            $seconds = 1;
        }

        if (!is_numeric($time)) {
            $time = time();
        }

        if ($time <= $seconds) {
            $seconds = 1;
        } else {
            $seconds = $time - $seconds;
        }

        $str = '';
        $years = floor($seconds / 31536000);

        if ($years > 0) {
            $str .= $years.' '.(($years > 1) ? 'Years' : 'Year').', ';
        }

        $seconds -= $years * 31536000;
        $months = floor($seconds / 2628000);

        if ($years > 0 OR $months > 0) {
            if ($months > 0) {   
                $str .= $months.' '.(($months   > 1) ? 'Months' : 'Month').', ';
            }
            $seconds -= $months * 2628000;
        }

        $weeks = floor($seconds / 604800);

        if ($years > 0 OR $months > 0 OR $weeks > 0) {
            if ($weeks > 0) {   
                $str .= $weeks.' '.(($weeks > 1) ? 'Weeks' : 'Week').', ';
            }

            $seconds -= $weeks * 604800;
        }           

        $days = floor($seconds / 86400);

        if ($months > 0 OR $weeks > 0 OR $days > 0) {
            if ($days > 0) {   
                $str .= $days.' '.(($days   > 1) ? 'Days' : 'Day').', ';
            }
            $seconds -= $days * 86400;
        }

        $hours = floor($seconds / 3600);

        if ($days > 0 OR $hours > 0) {
            if ($hours > 0) {
                $str .= $hours.' '.(($hours > 1) ? 'Hours' : 'Hour').', ';
            }
            $seconds -= $hours * 3600;
        }

        // don't display minutes/seconds unless $display_mins_secs
        // == true
        if ($display_mins_secs) {
            $minutes = floor($seconds / 60);
            if ($days > 0 OR $hours > 0 OR $minutes > 0) {
                if ($minutes > 0) {   
                    $str .= $minutes.' '.(($minutes > 1) ? 'Minutes' : 'Minute').', ';
                }
                $seconds -= $minutes * 60;
            }

            if ($str == '') {
                $str .= $seconds.' '.(($seconds > 1) ? 'Seconds' : 'Second').', ';
            }
        }

        return substr(trim($str), 0, -1);
    }

    /* Writes messages to IRC socket */
    public function write($type, $channel = null, $message = null) {
        if ($type == 'QUIT') {
            $quitmsg = $type . " :".$message."\r\n\r\n";
            return Irc::write($quitmsg); // $this->socket, $quitmsg, strlen($quitmsg));
        }
        if (!$channel) { $channel = $this->config['default_chan']; }
        if (strlen($message) > $this->txlimit) {
            // @TODO gots to make this substr nicely (look for the last space)
            $message_parts = $this->_split_message($message);
            foreach ($message_parts as $message_part) {
                $msg = "$type $channel :$message_part\r\n\r\n";
                Irc::write($msg); //fwrite($this->socket, $msg, strlen($msg));
            }
            return true;
        } else {
            $msg = "$type $channel :$message\r\n\r\n";
        }
        return Irc::write($msg); //fwrite($this->socket, $msg, strlen($msg));
    }

    // uses class variable "txlimit" to split the message string up
    // in order to ensure the IRC server receives the full message

    private function _split_message($message = '') {
        if (empty($message)) return false;
        $parts = array();
        $i = 0;
        $prevpos = false;
        for ($i=0; $i < strlen($message); $i = $i+$this->txlimit) {
            $start = (!$prevpos) ? $i : ($start + $prevpos);
            $prevpos = $this->txlimit - strpos(strrev(substr($message, $start, $this->txlimit)), " ");
            $parts[] = substr($message, $start, $prevpos);
        }
        return $parts;
    }

    public function set_parts($parts = array()) {
        if (count($parts) == 0) return false;
        $this->myparts = $parts;
    }

    public function get_parts() {
        return $this->myparts;
    }

    /* macro function for writing to the current channel */
    private function write_channel($message) {
        $this->write("PRIVMSG", $this->get_current_channel(), $message);
    }

    /* macro function for writing to the current user */
    private function write_user($message) {
        $this->write("PRIVMSG", $this->get_current_user(), $message);
    }

    /* Called for every action (join/part/privmsg etc) 
       Use this for any event that should fire every time something happens. */
    public function catchall($data) {

        if(!$data) { return; }

        // make sure we have text content, that it's a message of some sort and make sure we're not logging ourself
        if (!empty($data['message']) && ($data['command']=='PRIVMSG') && ($data['user'] != $this->config['irc_handle'])) {
            if (@$this->config['log_history']) {
                try { 
                    $this->collection->log->insert($data);
                } catch (Exception $e) {
                    $this->Log->log("DB Error", 2);
                }
            }
        }
        if ($this->config['log_stats']) { $this->stats($data); }
        if (@$data['command'] == 'JOIN' && $data['user'] != $this->config['irc_handle']) {
            // abuse new user
            sleep(2);
			
			$abuse_tpls = array(
								840, // Oh great that guy is back or whatever
								1068, // Why do you even bother, guy?
								1081, // Fabulous, guy is here
                                1289,
							);
			
	        $this->abuse(array('arg1' => $data['user'], 'tpl' => $abuse_tpls[rand(0, count($abuse_tpls)-1)]));
	    	
        }

        // only run check_url if we actually see a URL
        // *** can be expanded to look for 'www.' as well
        if (preg_match('/http[s]?:\/\//', $data['message'])>0) {
            // temp bandaid to prevent a shortener loop
            if (strstr($data['message'], '5kb.us')) return;
            $url = $this->check_url(explode(' ', $data['message']), $this->get_current_channel());
        }

    }

    public function set_arraykey($parts = array()) {
        if (isset($parts[3])) { // && isset($parts[5])) {
            $this->array_key = $parts[3]; // md5(@$parts[3] . @$parts[5]);
        }
    }

    public function get_arraykey() {
        return $this->array_key;
    }

    private function get_userhash($nick = '') {
        if (empty($nick)) return false;
        return (isset($this->userCache[$nick]) ? $this->userCache[$nick]['userhash'] : false);
    }

    public function _check_acl($data) {
        $action = @$data['command'];

        /*
        $dbgmsg = "Checking ACL action for " . $this->get_current_user() . " -- command: " . @$data['command'];

        $this->Log->log($dbgmsg, 3);
        $datastr = is_array($data) ? json_encode($data, true) : $data;
        $this->Log->log($datastr, 3);
        */

        if (isset($this->userCache[$this->get_current_user()]) && $this->check_current_userhash($this->get_current_user(), $this->get_parts())) {
            $isoper = $this->userCache[$this->get_current_user()]['isoper'];
        } else {
            $isoper = false;
        }

        $count = 0;
        
        if ($action == 'acl') {
            // check requesting user
            if ($isoper == 1) {
                $count = 1;
            } else {
                try {
                    $random_insult  = $this->linguo->get_word("insult");
                    $this->write_user("You're not allowed to do that, " . $random_insult['word'] . ".");
                } catch (Exception $e) {
                    $this->Log->log("DB Error", 2);
                }
            }
        } elseif (in_array($action, $this->public_commands)) {
            $count = 1;
        } else {
            // perform the actual user validation against the hash:
            // should be allowed
            $criteria = array(
                'user' => $this->get_userhash($this->get_current_user()),
                'action' => $action
            );
            try { 
                $count = $this->collection->acl->count($criteria);
            } catch (Exception $e) {
                $this->Log->log("DB Error", 2);
            }
        }
        
        /*
        if ($count == 0) {
            $random_abuse   = $this->linguo->get_abuse();
            $random_insult  = $this->linguo->get_word("insult");
            $this->write_user("You do not have permissions for command '" . $action . "'. Access is denied, " . $random_insult['word'] . ". (" . $random_abuse . ")");
        }
        */
        return $count;
    }
    
    public function acl($args) {
        $uargs = $args['uargs'];
        $types = array('permit', 'deny');
        $action = trim(@$uargs[0]);
        $rule   = trim(@$uargs[1]);
        $user   = trim(@$uargs[2]);

        if (empty($user) && $action == 'list') {
            $nick = $rule;
        } else {
            $nick = $user;
        }

        $userhash = $this->get_userhash($nick);

        if (!$userhash) {
            $user = false;
        }

        $actions = array();
        $aclres = array();
        try {
            $aclres = $this->collection->acl->find();
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
        $acls = array();

        if ($action == 'list') {
            $actions = array();
            try {
                foreach ($this->collection->acl->find(array('user' => $userhash)) as $row) {
                    $actions[] = $row['action'];
                }
            } catch (Exception $e) {
                $this->Log->log("DB Error", 2);
            }
            $this->write_channel(implode(",", $actions));
            return;
        }
        if (!$user) {
            $this->write_channel('acl [action] [permit|deny] [user]');
            return;
        }
        if (!in_array($rule, $types)) {
            $this->write_channel('acl [action] [permit|deny] [user]');
            return;
        }
        // Check if method actually exists
        if (!method_exists($this, $action)) {
            if (substr($action,0,1) == '_') {
                $this->write_channel("Clever, nice try...");
                return;
            }
            $this->write_channel("Method '$action' does not exist.");
            return;
        }
        $acl = array(
            'action' => $action,
            'rule' => $rule,
            'user' => $userhash
        );

        $criteria = array(
            'user' => $userhash,
            'action' => $action
        );
        ($rule == 'permit') ? $text = "granted" : $text = "denied";
        if ($rule == 'deny') {
            try {
                $this->collection->acl->remove($criteria);
                $this->write_channel("$action $text for $user");
            } catch (Exception $e) {
                $this->Log->log("DB Error", 2);
            }
            return;
        }
        try {
            $this->collection->acl->update($criteria, $acl, array('upsert' => true));
            $this->write_channel("$action $text for $user");
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
    }

    public function debug($args) {
        //switch($args['arg1'])
        $this->write_channel(str_replace("\n", "", print_r($args, 1)));
    }

    /* Log user message statistics by day by user */
    public function stats($data) {
        unset($data['_id']);
        // Increment user message count
        $criteria = array('date' => date('Y-m-d'), 'user' => $data['user']);
        if ($data['command'] != 'PRIVMSG') { return; }
        $data = array(
            '$set' => array(
                'date' => date('Y-m-d'),
                'user' => @$data['user'],
                'channel' => $this->get_current_channel() 
            ),
            '$inc' => array(
                'count' => 1
            )
        );
        try {
            $this->collection->stats->update($criteria, $data, array('upsert' => true));
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
    }
    /* returns current message data string */
    public function get_message_data() {
        return $this->message_data;
    }
    /* sets current message data string */
    public function set_message_data($data = '') {
        if (!empty($data)) $this->message_data = $data;
    }

    private function get_param_string($command) {
        return preg_replace('/'.$command.'/i', '', $this->get_message_data());
    }

    public function check_current_userhash($nick = '', $parts = array()) {
        if (count($parts)==0 || empty($nick)) return false;
        if (isset($this->userCache[$nick])) {
            $userdata = Irc::break_hostmask($parts[0]);
            return ($this->userCache[$nick]['userhash'] == md5($userdata['nick'] . $userdata['host']));
        } else {
            return false;
        }
    }

    /* returns current channel unless called from /msg */
    public function get_current_channel() {
        if (!$this->currentchannel) $this->set_current_channel($this->config['default_chan']);
        return ($this->currentchannel==$this->config['irc_handle']) ? $this->get_current_user() : $this->currentchannel;
    }
    /* sets current channel string */
    public function set_current_channel($channel = '') {
        if (!empty($channel)) $this->currentchannel = $channel;
    }
    /* returns current user string */
    public function get_current_user() {
        return $this->currentuser;
    }
    /* sets current user string */
    public function set_current_user($user = '') {
        if(!empty($user)) $this->currentuser = $user;
    }

    public function set_isoper($val = false) {
        if (!$val) {
            $this->isIRCOper = $val;
        }
    }


    private function is_chat_text($type = '') {
        return ($type=='PRIVMSG') ? true : false;
    }

    /* Returns <title> of webpage. */
    public function get_site_title($url){
        $urlContents = file_get_contents($url);
        $dom = new DOMDocument();
        @$dom->loadHTML($urlContents);
        $title = $dom->getElementsByTagName('title');
        return trim(@$title->item(0)->nodeValue);
    }

    public function history($args) {
        $criteria = array('message' => new MongoRegEx('/^'.$args['arg1'].'/i')); 
        try {
            $result = $this->collection->log->find($criteria)->limit(10);
            $result->sort(array('time'=>-1));
            if ($result->count() > 0) {
                foreach($result as $history) {
                    $this->write_user("[".date('d/m/Y H:i', $history['time'])."] <".$history['user']."> ".$history['message']);
                }
            } else {
                $this->write_channel("Nothing found.");  
            }
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
            $this->write_channel("Nothing found.");
        }
    }

    /* Adds an event

        Syntax:
        add_event "My prophetic time of awesomeness" --time "1:20pm January 5th, 2014"

        Note: double quotes (") are required to encapsulate any strings with spaces

    */

    function add_event($args) {
        $time = strtotime(trim(@$args['time']));
        
        if ($time < time()) {
            $this->write_user("Date (" . $time . ") is malformed / in the past, use time (date / time) to get Unix time");
            return false;
        }
        
        $data = array(
            'when' => @$time,
            'who' => $this->get_current_user(),
            'rsvp' => array(),
            'description' => trim(@$args['arg1'])
        );
        try {
            $c = $this->collection->events->event;
            $c->insert($data);
            $this->write_channel("Event added for ".date('Y-m-d g:i A', $time));
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
            $this->write_channel("Error adding event");
        }
        return true;
    }

    /* Lists upcoming events */
    function events() {
        try {
            $c = $this->collection->events->event;
            $r = $c->find(array('when' => array('$gt' => time())));
            if ($r->count() > 0) {
                $this->write_user("Upcoming Events");
                foreach ($r as $e) {
                    $this->write_user("What : ".$e['description']);
                    $this->write_user("When : ".date('Y-m-d g:i A', (int) $e['when'])." (". date('l', (int) $e['when']) .")");
                    $this->write_user("RSVP : ".implode(', ', @$e['rsvp']));
                    $this->write_user("ID   : ".$e['_id']);
                    $this->write_user('--------------------------');
                }
            } else {
                $this->write_user("Nothing! How boring, do something!");
            }
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
            $this->write_user("Nothing! How boring, do something!");
        }
        return true;
    }

    /* removes an event given an ID passed */
    function rm_event($args) {
        $id = trim($args['arg1']);
        try {
            $c = $this->collection->events->event;
            $r = $c->remove(array('_id' => new MongoId($id)));
            $this->write_user('Event ' . $id . ' removed.');
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
        return true;
    }

    /* RSVPs to an event given its ID */
    function rsvp($args) {
        try {
            $c = $this->collection->events->event;
            $mid = new MongoId($args['arg1']);
            $criteria = array('_id' => $mid);
            $eventdata = $c->findOne($criteria);
            if (!$eventdata) {
                $this->write_user("Event doesn't exist.");
                return false;
            }
            $data = array('$addToSet' => array('rsvp' => $this->get_current_user()));
            $c->update($criteria, $data, array('upsert' => true));
            $this->write_channel($this->get_current_user() . ' will be attending ' . $eventdata['description']);
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
        return true; 
    }

    public function clear($args) {
        $this->write_channel("Your screen has been cleared");
    }

    public function time($args) {
        $time = trim($this->get_param_string($args['command']));
        if (!is_numeric($time)) {
            $this->write_channel("Unix : ".strtotime($time)); 
            $this->write_channel("Human : ".date('Y-m-d g:i A', strtotime($time)). ' ('.date('l', strtotime($time)).')');
        } else {
            $this->write_channel("Unix : ".$time); 
            $this->write_channel("Human : ".date('Y-m-d g:i A', $time). ' ('.date('l', $time).')');
        }
        return true;
    }
  
    /* displays the timespan in various units between two given strings (formatting should be strtotime() friendly) */
    /*
        --timespan <from> | <to>
    */
    public function timespan($args) {
        $parts = explode("|", $this->get_param_string($args['command']));
        $then = strtotime(trim(@$parts[0]));
        $now = strtotime(trim(@$parts[1]));
        $output = $this->calculate_timespan($then, $now)."\n";
        $years = round(($now-$then) / 60 / 60 / 24 / 365, 2);
        $days = round(($now-$then) / 60 / 60 / 24, 2);
        $hours = round(($now-$then) / 60 / 60, 2);
        $mins = round(($now-$then) / 60, 2);
        $secs = ($now-$then);
        $this->write_channel("Years : $years");
        $this->write_channel("Days : $days");
        $this->write_channel("Hours : $hours");
        $this->write_channel("Mins : $mins");
        $this->write_channel("Seconds : $secs");
        return true;
    }


    public function write_bio($args) {
        try {
            $c = $this->collection->bios->bios;
            $criteria = array('user' => $this->get_current_user());
            $data = array('user' => $this->get_current_user(), 'bio' => $this->get_param_string($args['command']));
            $c->update($criteria, $data, array('upsert' => true));
            $this->write_user("Your bio has been saved, " . $this->get_current_user());
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
        return true;
    }

    public function bio($args) {
        $user = trim($args['arg1']);
        try {
            $c = $this->collection->bios->bios;
            $criteria = array('user' => $user);
            $data = $c->findOne($criteria);
            $message = (count($data)>0) ? $user . "'s Bio: ".$data['bio'] : 'No bio found for ' . $user;
            $this->write_channel($message);
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
        return true;
    }

    public function bios($args) {
        try {
            $c = $this->collection->bios->bios;
            $data = $c->find();
            if ($data->count() > 0) {
                foreach ($data as $bio) {
                    $this->write_user($bio['user'] . ": " . $bio['bio']);
                }
            } else {
                $this->write_user('No bios found!');
            }
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
            $this->write_user("No bios found!");
        }
        return true; 
    }


    public function downvote($args) {
        try {
            $c = $this->collection->irc->votes;
            $criteria = array('user' => trim($args['arg1']));
            $data = array('$inc' => array('downvotes' => 1));
            $c->update($criteria, $data, array('upsert' => true));
            $d = $c->findOne($criteria);
            if ($d['downvotes'] % 11 == 0) {
                $this->write_channel('Congratulations! '.$d['user'] . ' has '.$d['downvotes'].'Congratulations, suck a dick faggot.');
                return true;
            }
            $this->write_channel($d['user'] . ' has ' . $d['downvotes'] . ' downvotes.');
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
        return true;
    }

    public function butthurt($args) {
        $this->write_channel('true');
    }

    public function jimmies($args) {
        $this->write_channel('Jimmies status : extremely rustled');
    }

    public function upvote($args) {
        if ($this->_check_permissions($this->get_current_user())) { $this->write_user("<permission denied> I can't let you do that ".$this->get_current_user()); return false;}
        $in_user = trim($args['arg1']);
        if ($this->get_current_user() == $in_user) { $this->write_user("You can't upvote yourself idiot"); return false; }
        try {
            $c = $this->collection->irc->votes;
            $criteria = array('user' => $in_user);
            $data = array('$inc' => array('upvotes' => 1));
            $c->update($criteria, $data, array('upsert' => true));
            $d = $c->findOne($criteria);
            if ($d['upvotes'] % 10 == 0) {
                $this->write_channel('Congratulations! '.$d['user'] . ' has '.$d['upvotes'].' upvotes and has been awarded a free BJ from pybot.');
                return true;
            }
            $this->write_channel($d['user'] . ' has ' . $d['upvotes'] . ' upvotes.');
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
        return true;
    }


    /* Make pybot say something to $chan */
    /*
      Usage:
	say "message" --chan #chan
    */
    public function say($args) {

        $chan = @$args['chan'];
        if (!$chan) { $chan = $this->get_current_channel(); }
        if (!$chan) { $chan = $this->config['default_chan']; }
        
        $message = @$args['arg1'];
        $this->write("PRIVMSG", $chan, "$message");
    }

    /* Tell pybot to /join a $chan */
    public function join($args) {
        if (@$args['arg1']) { $chan = $args['arg1']; } else { $chan = $this->config['default_chan']; }
        $this->write_channel("I'll be over in $chan");
        $this->write("JOIN", $chan);
    }

    /* Tell pybot to leave a $chan */
    public function part($args) {
        $chan = false;
        if ($args['arg1']) { $chan=$args['arg1']; }
        if (!$chan) { $chan = $this->get_current_channel(); }
        $oldchan = $this->get_current_channel();
        $this->set_current_channel($chan);
        $this->write_channel("I'm the fuck outta here");
        $this->write("PART", $chan);
        if ($oldchan !== $chan) $this->set_current_channel($oldchan);
    }

    public function stat($args) {
        return false;
        if (@$args['arg1']) { $chan = $args['arg1']; } else { $chan = $this->config['default_chan']; }
        try {
            foreach ($this->collection->stats->find() as $stat) {
                $str = $stat['user'].' : '.$stat['count'];
                $this->write("NOTICE", $this->get_current_channel(), $str);
            }
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
    }

    public function wotd($args) {
        $type = @$args['arg1'];
        if (! $type) {
            $this->write_channel("You did not specify a type");
            return;
        }
        try {
            $word = $this->linguo->get_word($type);
            $data['arg1'] = $word['word'];
            $this->define($data);
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
    }

    public function mkword($args) {
	    $word = trim(@$args['arg1']);
	    $tmp = trim(@$args['type']);
        $type = strtolower(preg_replace("/[^A-Za-z]/", '', $tmp));

        if (! $word || ! $type) {
            $this->write_channel("mkword <word> --type 'type'");
            return;
        }
        $criteria = array(
            'word' => $word,
            'type' => $type
        );
        $data = array(
            '$set' => array(
                'word' => $word,
                'type' => $type,
                'user' => $this->currentuser
            )
        );
        try {
            $this->collection->words->update($criteria, $data, array('upsert' => true));
            $this->write_channel("$word added as $type");
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
    }

    public function rmword($args) {
        $word = trim(@$args['arg1']);
        try {
            $this->collection->words->remove(array('word' => $word));
            $this->write_channel("$word removed from dictionary.");
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
    }

    public function qword($args) {
        $word = trim(@$args['arg1']);
        try {
            $data = $this->collection->words->findOne(array('word' => $word));
            if ($data) {
                $word = $data['word'];
                $user = $data['user'];
                $type = $data['type'];
                $this->write_channel("$word ($type) was submitted by $user");
                return;
            }
            $this->write_channel("Could not find '$word'.");
        } catch (Exception $e) {
            $this->write_channel("Could not find '$word'.");
            $this->Log->log("DB Error", 2);
        }
    }

    public function mktpl($args) {
	    $tpl = trim(@$args['arg1']);
        if (! $tpl) {
            $this->write_channel("Missing phrase");
        }
        try {
	        $dat = $this->collection->templates->find()->sort(array('id' => -1))->limit(1);
	        $iter = current(iterator_to_array($dat));
	        $id = $iter['id']+1;
            $criteria = array(
                'time' => time(),
                'user' => $this->get_current_user()
            );
            $data = array(
                '$set' => array(
                    'id' => (int) $id,
                    'template' => $tpl
                )
            );
            $this->collection->templates->update($criteria, $data, array('upsert' => true));
            $this->write_channel("Template ID : $id");
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
    }

	public function rmtpl($args) {
		$id = @$args['arg1'];
		try {
            $r = $this->collection->templates->remove(array('id' => $id));
		    $this->write_channel("Removed template $id - $r");
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
	}

    public function twabuse($args) {
        return;
        try {
            $abuse['arg1'] = $this->linguo->get_abuse($args);
            // $this->tweet($abuse);
            $this->write_channel($abuse['arg1']);
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
//		$this->write_channel("Sorri guiz got b& : http://i.imgur.com/FpoAkmz.png");
    }

    public function abuse($args) {
        try {
			if (!isset($args['arg1']) || strlen(trim($args['arg1'])) == 0) {
				$this->randabuse($args);
				return;
			}
            $abuse = $this->linguo->get_abuse($args);
            $this->write_channel($abuse);
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
    }
    
    public function rant($args) {
        try {
            $abuse = $this->linguo->get_rant($args);
            $this->write_channel($abuse);
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
    }

    public function testtpl($args) {
        try {
			$args['arg1'] = str_replace('$who', $this->randuser(), $args['arg1']);
            $this->write_channel($this->linguo->testtpl($args));
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
    }

    public function types($args) {
        try {
            $this->write_channel($this->linguo->get_word_types());
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
        return true;
    }

    // Pybot Stats
    public function pstats($agrs) {
        try {
            $count = $this->collection->words->count();
            $this->write("PRIVMSG", $this->get_current_channel(), "$count words");
            $count = $this->collection->templates->count();
            $this->write("PRIVMSG", $this->get_current_channel(), "$count templates");
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
    }

    public function geo($args) {
        $addr = trim($args['arg1']);
        $result = @geoip_record_by_name($addr);
        $output = '';
        if ($result['city']) {
            $output .= $result['city'].', ';
        }
        if ($result['region']) {
            $output .= $result['region'].'. ';
        }
        if ($result['country_name']) {
            $output .= $result['country_name'];
        }
        $this->write("PRIVMSG", $this->get_current_channel(), "$output");
    }

    public function _get_git_revision() {
        return shell_exec("/usr/bin/git rev-parse HEAD");
    }

    public function hup() {
        $this->write_channel("No, I don't think we'll be doing that");
        return;
    	$this->write_channel('As you command, my lord.');
        @shell_exec('/usr/bin/git pull');
        $this->set_current_channel($this->config['default_chan']);
	    $this->version();
        $this->write("QUIT: I'll be right back, folks!", null, null);
        fclose($this->socket);
        sleep(2);
        $path = array(getcwd().'/pybotd');
        if(!pcntl_fork()) {
            pcntl_exec("/usr/bin/php", $path); 
        }
        die('Killing parent');
    }

    public function version() {
	$version = $this->version;
	$branch = trim(@shell_exec('/usr/bin/git rev-parse --abbrev-ref HEAD'));
	$version_string = "pybot ($branch) version $version 'Bondage Chains'";
        $this->write_channel($version_string);
	return;
    }

    public function track($args) {
        return;
        $matches = array();
        $branch = preg_match('/^(\S+)/',$args['arg1'],$matches);
        $branch = isset($matches[1]) ? $matches[1] : "";
        $branches = @shell_exec('/usr/bin/git branch -r');
        $exists = preg_match('/\**origin\/'.$branch.'/',$branches);
        if ($exists && !empty($branch)) {
            $this->write_channel('Switching to branch '.$branch);
            @shell_exec('/usr/bin/git checkout ' . $branch);
            $this->hup();
        }
        else {
            try {
                $this->write_channel($this->linguo->_generate_phrase("That's a $noun not a branch","pybot"));
            } catch (Exception $e) {
                $this->Log->log("DB Error", 2);
            }
        }
    }
    /* function lmgtfy

        runs the string through Let Me Google That For You

    */

    public function lmgtfy($args) {
        $q = urlencode($args['arg1']);
        $base = "http://lmgtfy.com/?q=";
        $url = $base.$q;
        $result = $this->_shorten($url);
        $this->write_channel($result);
    }
    
    public function define($args) {
        if (!empty($args['arg1'])) { 
            $word = trim((string) $args['arg1']);
            $q = rawurlencode($word);
            $this->write_channel("Looking up definition for $word ...\n");
            $output = '';
            $html = file_get_html('http://www.urbandictionary.com/define.php?term='.$q);
            $result = $html->find('div[class=definition]');
            if (isset($result[0]) && isset($result[0]->nodes[0]) && isset($result[0]->nodes[0]->_)) {
                $def = $result[0]->nodes[0]->_;
            }
            if (!empty($def)) {
                foreach($def as $key => $val) {
                    $definition = htmlspecialchars_decode($val);
                }
                $this->write_channel("Definition: ".$definition);
            }   
            $exresult = $html->find('div[class=example]');
            $ex = $exresult[0]->nodes[0]->_;
            if (!empty($ex)) {
                foreach($ex as $key => $val) {
                    $example = htmlspecialchars_decode($val);
                }
                $this->write_channel("Example: ".$example);
            }   

        } else {
            $this->write_channel("Please specify a search query.");
        }   
    }

    public function reverse($args) {
        $this->write_channel(strrev($args['arg1']));
        return true;
    }

    public function yell($args) {
        $this->write_channel(strtoupper($args['arg1']));
        return true;
    }

    public function whisper($args) {
        $this->write_channel(strtolower($args['arg1']));
        return true;
    }

    public function get_chr($args) {
        $this->write_channel(chr($args['arg1']));
        return true;
    }

    public function sndex($args) {
        $this->write_channel(soundex($args['arg1']));
        return true;
    }

    public function getcrypt($args) {
        $this->write_channel(crypt($args['arg1']));
        return true;
    }
    
    public function get_sl($args) {
        $this->write_channel(strlen($args['arg1']));
        return true;
    }

    public function get_md5($args) {
        $this->write_channel(md5($args['arg1']));
        return true;
    }

    public function sep($args) {
        $parts = array_map("trim", explode("|", $args['arg1']));
        $sep = '-';
        $str = @$parts[0];
        $sep = @$parts[1];
        $letters = array_map("trim", str_split($str));
        $this->write_channel(implode($sep, $letters));

        return true;
    }

    public function get_uc($args) {
        $this->write_channel(urlencode($args['arg1']));
        return true;
    }
    
    public function to_binary($args) {
        $in = $args['arg1'];
        $out = '';
        for ($i = 0, $len = strlen($in); $i < $len; $i++) {
            $out .= sprintf("%08b",ord($in{$i}));
        }
        $this->write_channel($out);
        return true;
    }
    function from_binary($args) {
        $in = $args['arg1'];
        $out = '';
        $len = strlen($in);
        for ($i=0; $i < $len; $i++) {
            $ss = substr($in,$i,1);
            $bd = bindec(substr($in,$i,1));
            $out .= chr($bd);
            //print "\nin: " . $in . " len: " . $len . ' bd: ' . $bd . ' ss: ' . $ss; 
            //print "\nhere: " . $out;
        }
        $this->write_channel($out);
        return true;
    }

    function to_hex($args) {
        $string = $args['arg1'];
        $hex='';
        for ($i=0; $i < strlen($string); $i++)
        {
            $char = $string[$i];
            $o = ord($char);
            $dc = dechex($o);
            //print "\nchar: " . $char . " o: " .$o." dc: " . $dc . " -- hex: " . $hex;
            $hex .= $dc;
        }
        $this->write_channel($hex);
        return true;
    }

    function from_hex($args) {
        $hex = $args['arg1'];
        $string='';
        for ($i=0; $i < strlen($hex)-1; $i++)
        {
            $string .= chr(hexdec($hex[$i].$hex[$i+1]));
        }
        $this->write_channel($string);
        return true;
    }

    function base($args) {
        $parts = explode("|", $args['arg1']);
        $int = trim(@$parts[0]);
        $from = trim(@$parts[1]);
        $to = trim(@$parts[2]);
        if (!$to) { 
            $this->write_user("You're doing it wrong <val> | <base-from> | <base-to>");
            return false;
        }
        
        $this->write_channel(base_convert($int, $from, $to));
        return true;
    }

/*
    public function linkhistory($args) {
        // $output .= "$data->username : $data->title - $data->url - $data->created\n";
        try {
            $result = $this->collection->linkhistory->find();
            if ($result->count() > 0) {
                $result->sort(array('created'=>1)); 
                foreach($result as $history) {
                    $this->write_channel($history['username'] . ": " . $history['title'] . " - " . $history['url'] . " - " . $history['created']);
                }
            } else {
                $this->write_channel("Nothing found.");
            }
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
            $this->write_channel("Nothing found.");
        }
        return true;
    }

    public function tstat() {
        return;
        $trans = new TransmissionRPC();
        $r = $trans->get();
        $active = 0;
        $pending = 0;
        foreach ($r->arguments->torrents as $t) {
            $have = @$t->haveValid;
            $size = @$t->totalSize;
            $complete = round(($have / $size) * 100, 2);
            if ($complete > 0)  {
                $active++;
                $this->write_channel($t->name.' - '.$complete." %");
            } else {
                $pending++;
            }
            if ($t->status == 6) {
                $trans->remove($t->id);
            }
        }
        $this->write_channel("Active : $active Pending : $pending");
    }
    public function tsearch($args) {
        return;
        $arg = $args['arg1'];
        $results = json_decode(file_get_contents("http://isohunt.com/js/json.php?ihq=".urlencode($arg)."&rows=5&sort=seeds"));
        $output = null;
        foreach ($results->items->list as $file) {
            try {
                $this->collection->torrents->update($file, $file, array('upsert' => true));
                $r = $this->collection->torrents->findOne($file);
                $this->write_channel(strip_tags($file->title).' - '.$r['_id']);
            } catch (Exception $e) {
                $this->Log->log("DB Error", 2);
            }
        }
        return;
    }

    public function tget($args) {
        return;
        $trans = new TransmissionRPC();
        $id = trim($args['arg1']);
        try {
            $r = $this->collection->torrents->findOne(array('_id' => new MongoId($id)));
            $url = $r['enclosure_url'];
            $this->write_channel("Added ".strip_tags($r['title']));
            $trans->add_file($url);
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
    }

    function ssearch($args) {
        return;
        $q = $args['arg1'];
        $query = urlencode($q);
        $url = "http://nzbindex.com/rss/?q=$query&max=5&hidespam=1&complete=1&sort=sizedesc&minsize=80&maxsize=500";
        $string = file_get_contents($url);
        $xml = simplexml_load_string($string);
        $output = null;
        foreach ($xml->channel->item as $item) {
            $s = $item->enclosure->attributes()->length[0];
            $size = $s;
            $title = $this->_parse_nzb($item->title);
            $link = $item->link;
            $id = new MongoId();
            $str_id = (string) $id;
            try {
                $this->collection->usenet->insert(array('_id' => $id, 'title' => $title, 'link' => $link, 'size' => $size));
                $this->write_channel("$size : $title : $str_id");
            } catch (Exception $e) {
                $this->Log->log("DB Error", 2);
            }
        }
        return $output;
    }

    function sget($args) {
        return;
        $id = $args['arg1'];
        $id = new MongoId($id);
        try {
            $result = $this->collection->usenet->findOne(array('_id' => $id));
            $link = str_replace('/release/', '/download/', @$result['link'][0]);
            $clean = @$result['title'];
            $title = urlencode(@$result['title']);
            if (!$link) { return "ID Not Found"; }
            $r = file_get_contents("http://media.dev.riboflav.in:5000/api?mode=addurl&name=$link&nzbname=$title&cat=music");
            $this->write_channel("Downloading $clean");
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
        }
    }

    function sstat() {
        $r = file_get_contents("http://media:5000/api?mode=qstatus&output=json");
        $data = json_decode($r);
        $output = null;
        $speed = $data->speed;
        foreach ($data->jobs as $result) {
            $eta = $result->timeleft;
            $name = $result->filename;
            $size = round($result->mb);
            $left = round($result->mbleft);
            $complete = round($size-$left);
            $percent = round($complete/$size*100);
            $this->write_channel("$name - Complete :  {$percent}% - Size : $size M");
        }
        $this->write_channel("Speed : {$speed}B/s");
    }

*/
    private function _parse_nzb($title) {
        return;
        $search = array('-', '_', '.', 'nfo', 'par2', 'flac', 'mp3');
        $parts = explode('"', $title);
        $new = str_replace($search, " ", $parts[1]);
        $p = array_map("ucfirst", explode(" ", $new));
        return implode(" ", $p);
        
    }

    public function e164($args) {
        $arg = implode(".", array_reverse(str_split(preg_replace("/[^0-9]/", "", $args['arg1'])))).'.in-addr.arpa';
        $this->write_channel($arg);
    }

    public function proto($args) {
        $arg = trim($args['arg1']);
        $this->write_channel('TCP : '.getservbyport($arg, 'tcp').' | UDP : '.getservbyport($arg, 'udp'));
    }

    public function host($args) {
        $arg = trim($args['arg1']);
        $this->write_channel(gethostbyname($arg) . ' => ' . gethostbyaddr($arg));
    }

    public function follow($args) {
        $message = $args['arg1'];
        $twitter = new Twitter();
        $count = $twitter->follow($message);
        $this->write_channel("HTTP $count");
    }

    public function tweet($args) {
//		$this->write_channel("Sorri guiz got b& : http://i.imgur.com/FpoAkmz.png");
        $message = $args['arg1'];
        // $twitter = new Twitter();
        // $count = $twitter->tweet($message);
        // $this->write_channel("HTTP $count");
    }

	public function you() {
		// $this->write_channel('NO U!');
	}

	public function me($args) {
		$msg = $args['arg1'];
		$chr = chr(1);
		$message = "{$chr}ACTION $msg{$chr}";
		$this->write_channel($message);
	}

	public function nupogodi($args) {
		$this->write_channel('nupogodi is a fucking cuntbag');
	}

    public function rfr() {
        $track = file_get_contents('https://riboflav.in/rfr/api/ices_current');
        $this->write_channel("Now playing : $track");
    }

    public function skip() {
        $track = file_get_contents('https://riboflav.in/rfr/api/ices_next');
        sleep(2);
        $track = file_get_contents('https://riboflav.in/rfr/api/ices_current');
        $this->write_channel("Skipped, now playing : $track");
    }

    public function configure() {
        $base = "checking for ";
        $bool = array('yes', 'no');
        $word = $this->linguo->get_word('noun');
        $this->write_channel($base.$word['word']." ..... yes");
        $word = $this->linguo->get_word('verb');
        $adjective = $this->linguo->get_word('adverb');
        $this->write_channel($base.$word['word']." ..... yes, {$adjective['word']}");
        $word = $this->linguo->get_word('noun');
        $otherword = $this->linguo->get_word('verb');
        $this->write_channel("checking if {$word['word']} supports {$otherword['word']} ..... yes");
        $word = $this->linguo->get_word('verb');
        $this->write_channel("checking whether to {$word['word']} ..... yes");
        $word = $this->linguo->get_word('hole');
        $adjective = $this->linguo->get_word('adjective');
        $this->write_channel("checking whether {$word['word']} is {$adjective['word']}..... yes");
        $word = $this->linguo->get_word('hole');
        $otherword = $this->linguo->get_word('size');
        $this->write_channel("mkdep checking sizeof {$word['word']} ..... {$otherword['word']}");
        $word = $this->linguo->get_word('adverb');
        $this->write_channel($base.'lib-'.$word['word']." ..... yes");
        $word = $this->linguo->get_word('noun');
        $this->write_channel($base.$word['word']." ..... no");
        $rand = rand(99999,9999999);
        $otherword = $this->linguo->get_word('activity');
        $anotherword = $this->linguo->get_word('hole');
        $this->write_channel("ERROR: '/tmp/{$word['word']}/{$anotherword['word']}.c' failed on line $rand near '/* {$otherword['word']}' */");
    }

    public function godmode($args) {
        $user = trim(@$args['uargs'][0]);
        $userhash = $this->get_userhash($user);
        if ($action = 'all') {
            $fns = "help 
            stats 
            history 
            add_event 
            events 
            rm_event 
            rsvp 
            clear 
            time 
            timespan 
            uptime 
            write_bio 
            bio 
            bios 
            downvote 
            upvote 
            rfr
            skip 
            say 
            join 
            part 
            stat 
            wotd 
            mkword 
            rmword 
            qword 
            mktpl 
            rmtpl 
            twabuse 
            abuse 
            testtpl 
            types 
            pstats
            geo 
            hup
            version 
            track 
            lmgtfy 
            define 
            reverse 
            yell 
            whisper 
            get_chr 
            getcrypt 
            get_sl 
            get_md5 
            sep 
            get_uc 
            to_binary 
            from_binary 
            to_hex 
            from_hex 
            base 
            linkhistory 
            tstat 
            tsearch 
            tget 
            ssearch 
            sget 
            sstat 
            host 
            tweet 
            realtime
            rant 
            you 
            me";
            foreach (explode("\n", $fns) as $fn) {
                if (empty($fn)) { continue; }
                $criteria = array(
                    'user' => $userhash,
                    'action' => $fn
                );
                $acl = array(
                    'user' => $userhash,
                    'action' => $fn,
                    'rule' => 'permit'
                );
                $this->collection->acl->update($criteria, $acl, array('upsert' => true));
            }
            $this->write_channel("$user achieved God Mode!");
        }

    }

    /* Checks all words for valid url, retreives title ad shortens link */
    public function check_url($words, $channel) {
        $url = false;
        foreach ($words as $word) {
            $word = trim($word);
            if (filter_var($word, FILTER_VALIDATE_URL)) {
                $title = $this->get_site_title($word);
                $title = preg_replace('/[^a-zA-Z0-9\s]/', '', $title);
                $title = preg_replace('!\s+!', ' ', $title);
                $title = preg_replace('/\r|\n/', '', $title);
                if (empty($title)) { $title = "Untitled "; }
                $url = $this->_shorten($word);
                //   $this->db->insert('package__pybot_link_history', array('username' => $this->user, 'title' => $title, 'url' => $url, 'created' => date('d-m-Y g:i A')));
                $criteria = array();
                $data = array('username'=>$this->get_current_user(), 'title' => $title, 'url' => $url, 'created' => date('d-m-Y g:i A'));
                try {
                    $lh = $this->collection->linkhistory;
                    $lh->insert($data);
                } catch (Exception $e) {
                    $this->Log->log("DB Error", 2);
                }
                $this->write_channel("$title - $url");
            }
        }
        return $url;
    }

    /* echo the system uptime */
    public function uptime($args) {
        $this->write_channel(shell_exec('uptime'));
    }

    function b_shorten($url)
    {
        $uuid = "521f616057018475395133";
        $url = rawurlencode($url);

        $result = file_get_contents("https://coinurl.com/api.php?uuid={$uuid}&url={$url}");

        if($result == 'error')
            return false;
        else
            return $result;
    }

    private function _shorten($url) {
        return;
        $encoded = urlencode($url);
        $result = json_decode(file_get_contents("http://5kb.us/doit?url=$encoded"), 1);
        return $result['result'];
    }
	
	public function torstar() {
        $this->top_rss("http://www.thestar.com/feeds.topstories.rss", 3);
    }

    public function hn() {
		$this->top_rss("https://news.ycombinator.com/rss", 3);
    }
	
	public function reddit($args) {
		if (!isset($args['arg1'])) {
			$args['arg1'] = 'toronto';
		}
		
		$this->top_rss("http://www.reddit.com/r/". $args['arg1'] .".rss", 3);
    }
	
	
	
	private function top_rss($url, $count) {
		$data = json_decode(json_encode(simplexml_load_string(file_get_contents($url))), true);
        $i = 0;
        foreach ($data['channel']['item'] as $item) {
            if ($i <= $count - 1) {
                $title = $item['title'];
                $link = $this->_shorten($item['link']);
                $message = "$title - $link";
                $this->write_channel($message);
            }
            $i++;
        }
	}

    private function btc_general() {
        $data = json_decode(file_get_contents("http://api.bitcoincharts.com/v1/weighted_prices.json"), 1);
        $day = $data['CAD']['24h'];
        $week = $data['CAD']['7d'];
        $month = $data['CAD']['30d'];
        $this->write_channel("24h : $day");
        $this->write_channel("7d : $week");
        $this->write_channel("30d : $month");
    }
	
	public function btc($args) {
		if (!isset($args['symbol'])) {
			return $this->btc_general();
		}
		
		$data = json_decode(file_get_contents("http://api.bitcoincharts.com/v1/markets.json"), 1);
		foreach ($data as $symbol) {
			if (strcasecmp($symbol['symbol'], $args['symbol']) == 0) {
				$this->write_channel('Last trade: '.$symbol['close'].' '.$symbol['currency']);
				return;
			}
		}
		
		$this->write_channel('Symbol not found. Check http://bitcoincharts.com/markets/ for a list of symbol names.');
	}
	
	private function randuser() {
		return array_rand($this->userCache);
    }
	
	public function randabuse($args) {
		if (isset($args['tpl'])) {
			$this->abuse(array('arg1' => $this->randuser(), 'tpl' => $args['tpl']));
		} else {
			$this->abuse(array('arg1' => $this->randuser()));
		}
	}
	
	public function metar($args) {
		if (!isset($args['code'])) {
			$args['code'] = 'CYTZ'; // default to Billy Bishop
		}
		
		$data = json_decode(file_get_contents("http://api.geonames.org/weatherIcaoJSON?ICAO=" . $args['code'] . "&username=pybot"), 1);
		if (!isset($data['weatherObservation'])) {
			$this->write_channel("Station code not found or API limit reached.");
			return;
		}
		
		$weather = $data['weatherObservation'];
		
		$this->write_channel($weather['stationName'] . ": " .$weather['observation']);
	}

	public function b64e($args) {
		$this->write_channel(base64_encode($args['arg1']));
	}

	public function b64d($args) {
		$this->write_channel(base64_decode($args['arg1']));
	}

    public function leak() {
        $line = $this->get_rand_line();
        $this->write_channel($line);
    }


    private function get_rand_line() {
        $data = explode("\n", file_get_contents('lib/actions.php'));
        $rand = array_rand($data);
        $str = trim($data[$rand]);
        $len = strlen($str);
        if ($len <= 10) {
            return $this->get_rand_line();
        }
        return $str;
    }

    public function clrsite() {
        return;
        $path = "/var/www/shitsta.in/public/index.html";
        file_put_contents($path, '');
        $this->write_channel("Cleared");
    }

    public function website($args) {
        return;
		$this->write_channel();
        $path = "/var/www/shitsta.in/public/index.html";
        file_put_contents($path, $args['arg1'].PHP_EOL, FILE_APPEND);
        $this->write_channel("Added");
    }

    public function clrmysite() {
        return;
        $user = $this->currentuser;
        $path = "/var/www/shitsta.in/public/$user.html";
        file_put_contents($path, '');
        $url = "http://shitsta.in/$user.html";
        $this->write_channel($url);
    }

    public function mysite($args) {
        return;
		$this->write_channel();
        $user = $this->currentuser;
        $path = "/var/www/shitsta.in/public/$user.html";
        file_put_contents($path, $args['arg1'].PHP_EOL, FILE_APPEND);
        $url = "http://shitsta.in/$user.html";
        $this->write_channel($url);
    }

}
