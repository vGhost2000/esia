<?php

namespace esia;


/**
 * Class Request
 * @package esia
 */
class Request
{
    /**
     * Url for calling request
     *
     * @var string
     */
    public $url;

    /**
     * Token for "Authorization" header
     *
     * @var string
     */
    public $token;

    /**
     * @param string $url
     * @param string $token
     */
    function __construct($url, $token, $verifySSL)
    {
        $this->url = $url;
        $this->token = $token;
        $this->verifySSL = $verifySSL;
    }

    /**
     * Call given method and return json decoded response
     *
     * if $withScheme equals false:
     * ````
     *     $request->url = 'https://esia-portal1.test.gosuslugi.ru/';
     *     $response = $request->call('/aas/oauth2/te');
     * ````
     * It will call https://esia-portal1.test.gosuslugi.ru/aas/oauth2/te
     *
     * if $withScheme equals true:
     * ````
     *     $request->call(https://esia-portal1.test.gosuslugi.ru/aas/oauth2/te, true);
     * ````
     * * It will call also https://esia-portal1.test.gosuslugi.ru/aas/oauth2/te
     *
     * @param string $method url
     * @param bool $withScheme if we need request with scheme
     * @return null|\stdClass
     */
    public function call($method, $withScheme = false)
    {

        $ch = $this->prepareAuthCurl();
        if(!is_resource($ch)) {
            return null;
        }

        $url = $withScheme ? $method : $this->url . $method;
        curl_setopt($ch, CURLOPT_URL, $url);

        $res = json_decode(curl_exec($ch));
        curl_close($ch);

        return $res;
    }

    /**
     * Prepare curl resource with "Authorization" header
     * @return resource|null
     */
    protected function prepareAuthCurl()
    {
        $ch = curl_init();

        if (is_resource($ch)) {

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $this->token]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifySSL);

            return $ch;
        }

        return null;
    }


}
