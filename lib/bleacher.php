<?php

class Bleacher {

    private static 
    $_patterns = array(
        'int'           => "/[^0-9\-]+/",
        'float'         => "/[^0-9\.\,\-]+/",
        'email'         => "/[^a-zA-Z0-9\@\.\-\_\+]+/",
        'text'          => "/[^a-zA-Z0-9\-\_\.\,\(\)\[\]\?\!\/ ]+/",
        'message'       => "/[^a-zA-Z0-9\-\_\.\,\(\)\[\]\?\!\/<>: =]+/",
        'string'        => "/[^a-zA-Z0-9\-\_\.\,\+\!\?]+/",
        'alphabetic'    => "/[^a-zA-Z]+/",
        'ircnick'       => "/[^a-zA-Z0-9\-\_]+/",
        'uppercase'     => "/[^A-Z]+/",
        'lowercase'     => "/[^a-z]+/",
        'mysqldate'     => "/[^\d{4}\/\d{2}\/\d{2}]+/",
        'loose'         => "/[^a-zA-Z0-9\-\_]+/",
        'strict'        => "/[^a-zA-Z0-9]+/",
        'password'      => "/[^a-zA-Z0-9\?\.\_\!\$\#]+/",
        'numeric'       => "/[^0-9]+/",
        'url'           => "/[^a-zA-Z0-9\/\#\?\&\-\_\=\:\.]+/"
    );

    public final static function int($i = 0) {
        return (int)preg_replace(self::$_patterns['int'], "", $i);
    }

    public final static function float($f = 0.00) {
        return (float)preg_replace(self::$_patterns['float'], "", $f);
    }

    public final static function email($email = '') {
        return (string)preg_replace(self::$_patterns['email'], "", $email);
    }

    public final static function text($str = '') {
        return (string)preg_replace(self::$_patterns['text'], "", $str);
    }

    public final static function message($msg = null) {
        return (string)preg_replace(self::$_patterns['message'], "", $msg);
    }

    public final static function string($str = '') {
        return (string)preg_replace(self::$_patterns['string'], "", $str);
    }

    public final static function alphabetic($str = '') {
        return (string)preg_replace(self::$_patterns['alphabetic'], "", $str);
    }

    public final static function ircnick($str = '') {
        return (string)preg_replace(self::$_patterns['ircnick'], "", $str);
    }

    public final static function uppercase($str = '') {
        return (string)preg_replace(self::$_patterns['uppercase'], "", $str);
    }

    public final static function lowercase($str = '') {
        return (string)preg_replace(self::$_patterns['lowercase'], "", $str);
    }

    public final static function mysqldate($str = '') {
        return (string)preg_replace(self::$_patterns['mysqldate'], "", $str);
    }

    public final static function loose($str = '') {
        return (string)preg_replace(self::$_patterns['loose'], "", $str);
    }

    public final static function strict($str = '') {
        return (string)preg_replace(self::$_patterns['strict'], "", $str);
    }

    public final static function password($str = '') {
        return (string)preg_replace(self::$_patterns['password'], "", $str);
    }

    public final static function numeric($num = 0) {
        return (int)preg_replace(self::$_patterns['numeric'], "", $num);
    }

    public final static function url($str = '') {
        return (string)preg_replace(self::$_patterns['url'], "", $str);
    }

}

