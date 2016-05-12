<?php

class Twitter {

    function tweet($message) {

        $tmhOAuth = new tmhOAuth(array(
          'consumer_key'    => 'kkqx07OVap3VClYjQm6vcA',
          'consumer_secret' => '14eObQCm0EsAh2dTjwlG6fJ5osVsyYmiB8rIfV0M',
          'user_token'      => '2176663651-bVALnSABGhQgfZpR2KgCtwL8cEXpYkRfJRPvEwy',
          'user_secret'     => 'Groygdk79INg4NonprSMjYZvshGh2mUgYhKvAHRqi97c0',
        ));

        $options = array(
            'method' => 'POST',
            'url' => $tmhOAuth->url('1.1/statuses/update'),
            'params' => array(
                'status' => $message
            )
        );

        $code = $tmhOAuth->user_request($options);
        return $code;
    }

    function follow($message) {

        $tmhOAuth = new tmhOAuth(array(
          'consumer_key'    => 'kkqx07OVap3VClYjQm6vcA',
          'consumer_secret' => '14eObQCm0EsAh2dTjwlG6fJ5osVsyYmiB8rIfV0M',
          'user_token'      => '2176663651-bVALnSABGhQgfZpR2KgCtwL8cEXpYkRfJRPvEwy',
          'user_secret'     => 'Groygdk79INg4NonprSMjYZvshGh2mUgYhKvAHRqi97c0',
        ));

        $options = array(
            'method' => 'POST',
            'url' => $tmhOAuth->url('1.1/friendships/create'),
            'params' => array(
                'screen_name' => $message
            )
        );

        $code = $tmhOAuth->user_request($options);
        return $code;
    }

}
