<?php
namespace esia;

use app\components\encryption\Exception;
use esia\exceptions\RequestFailException;
use esia\exceptions\SignFailException;

/**
 * Class OpenId
 * @package esia
 */
class OpenId
{
    public $clientId;
    public $redirectUrl;

    /**
     * @var callable|null
     */
    public $log = null;
    public $portalUrl = 'https://esia-portal1.test.gosuslugi.ru/';
    public $tokenUrl = 'aas/oauth2/te';
    public $codeUrl = 'aas/oauth2/ac';
    public $personUrl = 'rs/prns';
    public $logoutUrl = 'idp/ext/Logout';
    public $privateKeyPath;
    public $privateKeyPassword;
    public $certPath;
    public $oid = null;

    protected $scope = 'fullname birthdate gender email mobile id_doc snils inn';

    protected $clientSecret = null;
    protected $responseType = 'code';
    protected $state = null;
    protected $timestamp = null;
    protected $accessType = 'offline';
    protected $tmpPath;
    protected $opensslPath = 'openssl';
    protected $verifySSL = true;


    /**
     * stdClass Object
     * (
     *      [access_token] => eyJ2ZXIiOjEsInR5cCI6IkpXVCIsInNidCI6ImFjY2VzcyIsImFsZyI6IlJTMjU2In0.eyJuYmYiOj....
     *      [refresh_token] => c8918503-2c98-427a-9bae-031a18595bb7
     *      [id_token] => eyJ2ZXIiOjAsInR5cCI6IkpXVCIsInNidCI6ImlkIiwiYWxnIjoiUlMyNTYifQ.eyJhdWQiOiI4MzI5MDI....
     *      [state] => dbea76e9-8f29-49f5-b83c-8b62931156b1
     *      [token_type] => Bearer
     *      [expires_in] => 3600
     * )
     *
     * @var array
     */
    private $_tokenData = [];

    private $url = null;
    public $token = null;

    public function __construct(array $config = [])
    {
        foreach ($config as $k => $v) {
            if (property_exists($this, $k)) {
                $this->$k = $v;
            }
        }
    }

    /**
     * Return an url for authentication
     *
     * ```
     *     <a href="<?=$esia->getUrl()?>">Login</a>
     * ```
     *
     * @return string|false
     *
     * @throws SignFailException
     */
    public function getUrl()
    {
        $this->timestamp = $this->getTimeStamp();
        $this->state = $this->getState();
        $this->clientSecret = $this->scope . $this->timestamp . $this->clientId . $this->state;
        $this->clientSecret = $this->signPKCS7($this->clientSecret);

        if ($this->clientSecret === false) {
            return false;
        }

        $url = $this->getCodeUrl() . '?%s';

        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUrl,
            'scope' => $this->scope,
            'response_type' => $this->responseType,
            'state' => $this->state,
            'access_type' => $this->accessType,
            'timestamp' => $this->timestamp,
        ];

        $request = http_build_query($params);

        $this->url = sprintf($url, $request);

        return $this->url;
    }

    /**
     * Return an url for logout
     * 
     * @param $redirect_url
     * @return string
     */
    public function getLogoutUrl($redirect_url = null) {
        $url = $this->portalUrl . $this->logoutUrl . '?%s';

        $params = [
            'client_id' => $this->clientId
        ];

        if ($redirect_url) {
            $params['redirect_url'] = $redirect_url;
        }

        $request = http_build_query($params);        

        return sprintf($url, $request);
    }

    /**
     * Return an url for request to get an access token
     *
     * @return string
     */
    public function getTokenUrl()
    {
        return $this->portalUrl . $this->tokenUrl;
    }

    /**
     * Return an url for request to get an authorization code
     *
     * @return string
     */
    public function getCodeUrl()
    {
        return $this->portalUrl . $this->codeUrl;
    }

    /**
     * Return an url for request person information
     *
     * @return string
     */
    public function getPersonUrl()
    {
        return $this->portalUrl . $this->personUrl;
    }

    /**
     * Method collect a token with given code
     *
     * @param $code
     * @param $refresh_token
     * @return false|string
     * @throws SignFailException|Exception
     */
    public function getToken($code = null, $refresh_token = null)
    {
        $this->timestamp    = $this->getTimeStamp();
        $this->state        = $this->getState();
        $grant_type         = $refresh_token
            ? 'refresh_token'
            : 'authorization_code'
        ;

        $clientSecret = $this->signPKCS7($this->scope . $this->timestamp . $this->clientId . $this->state);

        if ($clientSecret === false) {
            throw new SignFailException(SignFailException::CODE_SIGN_FAIL);
        }

        $request = [
            'client_id'     => $this->clientId,
            'code'          => $code,
            'grant_type'    => $grant_type,
            'client_secret' => $clientSecret,
            'state'         => $this->state,
            'redirect_uri'  => $this->redirectUrl,
            'scope'         => $this->scope,
            'timestamp'     => $this->timestamp,
            'token_type'    => 'Bearer',
            'refresh_token' => $refresh_token,
        ];

        $curl = curl_init();

        if ($curl === false) {
            return false;
        }

        $options = [
            CURLOPT_URL => $this->getTokenUrl(),
            CURLOPT_POSTFIELDS => http_build_query($request),
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
        ];

        curl_setopt_array($curl, $options);

        $result = curl_exec($curl);
        if ($result === false) {
            $this->writeLog('curl error: ' . curl_error($curl));
        }
        curl_close($curl);
        if ($result === false) {
            return false;
        }
        $this->_tokenData = $result = json_decode($result);

        $this->writeLog(print_r($result, true));

        $this->token = $result->access_token;

        # get object id from token
        $chunks = explode('.', $this->token);
        $payload = json_decode($this->base64UrlSafeDecode($chunks[1]));
        $this->oid = $payload->{'urn:esia:sbj_id'};

        $this->writeLog(var_export($payload, true));

        return $this->token;
    }


    /**
     * Метод возвращает другие элементы из данных ЕСИА апи получения токена
     * stdClass Object
     * (
     *      [access_token] => eyJ2ZXIiOjEsInR5cCI6IkpXVCIsInNidCI6ImFjY2VzcyIsImFsZyI6IlJTMjU2In0.eyJuYmYiOj....
     *      [refresh_token] => c8918503-2c98-427a-9bae-031a18595bb7
     *      [id_token] => eyJ2ZXIiOjAsInR5cCI6IkpXVCIsInNidCI6ImlkIiwiYWxnIjoiUlMyNTYifQ.eyJhdWQiOiI4MzI5MDI....
     *      [state] => dbea76e9-8f29-49f5-b83c-8b62931156b1
     *      [token_type] => Bearer
     *      [expires_in] => 3600
     * )
     *
     * @param string|null $key
     * @return array
     * @throws Exception
     */
    public function getTokenData($key = null)
    {
        if (empty($this->_tokenData)) {
            throw new Exception('Error: empty tokenData. Call "getToken($code)" first');
        }
        if ($key) {
            if (!isset($this->_tokenData->$key)) {
                throw new Exception("Error: object key $key not exists in tokenData");
            }
            return $this->_tokenData->$key;
        }
        return $this->_tokenData;
    }


    /**
     * При использовании ГОСТовских ключей для подписи сгенеренных через криптопро процедура
     * openssl_pkcs7_sign валится с ошибкой. Это альтернативный вариант формирования подписи
     *
     * @param string $message
     * @return string
     * @throws SignFailException
     */
    private function _signMessageV2($message)
    {
        $messageFile = $this->tmpPath . DIRECTORY_SEPARATOR . $this->getRandomString();
        $signFile = $this->tmpPath . DIRECTORY_SEPARATOR . $this->getRandomString();
        file_put_contents($messageFile, $message);

        $cmd = $this->opensslPath .' smime -sign -in ' . $messageFile . ' -out ' . $signFile . ' -binary -signer '
            . $this->certPath . ' -inkey ' . $this->privateKeyPath . ' -outform PEM'
        ;

        system($cmd, $return);
        if ($return || !is_file($signFile) || empty($signed = file_get_contents($signFile))) {
            self::_removeIfExist($signFile);
            self::_removeIfExist($messageFile);
            throw new SignFailException(SignFailException::CODE_SIGN_FAIL);
        }
        self::_removeIfExist($signFile);
        self::_removeIfExist($messageFile);

        $signed = explode("\n", $signed);
        array_shift($signed);
        array_pop($signed);
        array_pop($signed);

        return str_replace("\n", "", $this->urlSafe(implode($signed)));
    }


    /**
     * Метод удаляет временные файлы
     *
     * @param $file
     */
    private static function _removeIfExist($file)
    {
        if (is_file($file)) {
            unlink($file);
        }
    }



    /**
     * Algorithm for singing message which
     * will be send in client_secret param
     *
     * @param string $message
     * @return string
     * @throws SignFailException
     */
    public function signPKCS7($message)
    {
        $this->checkFilesExists();

        // @TODO скорее всего такая проверка нуждается в доработке, вероятно это условие лучше даже вынести в конфиг
        if (strpos(file_get_contents($this->privateKeyPath), 'X509v3 Key Usage')) {
            return $this->_signMessageV2($message);
        }

        $certContent = file_get_contents($this->certPath);
        $keyContent = file_get_contents($this->privateKeyPath);

        $cert = openssl_x509_read($certContent);

        if ($cert === false) {
            throw new SignFailException(SignFailException::CODE_CANT_READ_CERT);
        }

        $this->writeLog('Cert: ' . print_r($cert, true));

        $privateKey = openssl_pkey_get_private($keyContent, $this->privateKeyPassword);

        if ($privateKey === false) {
            throw new SignFailException(SignFailException::CODE_CANT_READ_PRIVATE_KEY);
        }

        $this->writeLog('Private key: : ' . print_r($privateKey, true));

        // random unique directories for sign
        $messageFile = $this->tmpPath . DIRECTORY_SEPARATOR . $this->getRandomString();
        $signFile = $this->tmpPath . DIRECTORY_SEPARATOR . $this->getRandomString();
        file_put_contents($messageFile, $message);

        $signResult = openssl_pkcs7_sign(
            $messageFile,
            $signFile,
            $cert,
            $privateKey,
            []
        );

        if ($signResult) {
            $this->writeLog('Sign success');
        } else {
            $this->writeLog('Sign fail');
            $this->writeLog('SSH error: ' . openssl_error_string());
            self::_removeIfExist($signFile);
            self::_removeIfExist($messageFile);
            throw new SignFailException(SignFailException::CODE_SIGN_FAIL);
        }

        $signed = file_get_contents($signFile);

        # split by section
        $signed = explode("\n\n", $signed);

        # get third section which contains sign and join into one line
        $sign = str_replace("\n", "", $this->urlSafe($signed[3]));

        self::_removeIfExist($signFile);
        self::_removeIfExist($messageFile);

        return $sign;

    }

    /**
     * Fetch person info from current person
     *
     * You must collect token person before
     * calling this method
     *
     * @throws \Exception
     * @return null|\stdClass
     */
    public function getPersonInfo()
    {
        $url = $this->personUrl . '/' . $this->oid;

        $request = $this->buildRequest();
        return $request->call($url);
    }

    /**
     * Fetch contact info about current person
     *
     * You must collect token person before
     * calling this method
     *
     * @throws \Exception
     * @return null|array
     */
    public function getContactInfo()
    {
        $url = $this->personUrl . '/' . $this->oid . '/ctts';
        $request = $this->buildRequest();
        $result = $request->call($url);

        if ($result && $result->size > 0) {
            return $this->collectArrayElements($result->elements);
        }

        return null;
    }


    /**
     * Fetch address from current person
     *
     * You must collect token person before
     * calling this method
     *
     * @throws \Exception
     * @return null|array
     */
    public function getAddressInfo()
    {
        $url = $this->personUrl . '/' . $this->oid . '/addrs';
        $request = $this->buildRequest();
        $result = $request->call($url);

        if ($result && $result->size > 0) {
            return $this->collectArrayElements($result->elements);
        }

        return null;
    }


    /**
     * документы пользователя;
     *
     * You must collect token person before
     * calling this method
     *
     * @throws \Exception
     * @return null|array
     */
    public function getDocumentsInfo()
    {
        $url = $this->personUrl . '/' . $this->oid . '/docs';
        $request = $this->buildRequest();
        $result = $request->call($url);

        if ($result && $result->size > 0) {
            return $this->collectArrayElements($result->elements);
        }

        return null;
    }


    /**
     * организации, сотрудником которых является данный пользователь;
     *
     * You must collect token person before
     * calling this method
     *
     * @throws \Exception
     * @return null|array
     */
    public function getOrganizationsInfo()
    {
        $url = $this->personUrl . '/' . $this->oid . '/orgs';
        $request = $this->buildRequest();
        $result = $request->call($url);

        if ($result && $result->size > 0) {
            return $this->collectArrayElements($result->elements);
        }

        return null;
    }


    /**
     * дети пользователя;
     *
     * You must collect token person before
     * calling this method
     *
     * @throws \Exception
     * @return null|array
     */
    public function getKidsInfo()
    {
        $url = $this->personUrl . '/' . $this->oid . '/kids';
        $request = $this->buildRequest();
        $result = $request->call($url);

        if ($result && $result->size > 0) {
            return $this->collectArrayElements($result->elements);
        }

        return null;
    }


    /**
     * транспортные средства пользователя.
     *
     * You must collect token person before
     * calling this method
     *
     * @throws \Exception
     * @return null|array
     */
    public function getVehiclesInfo()
    {
        $url = $this->personUrl . '/' . $this->oid . '/vhls';
        $request = $this->buildRequest();
        $result = $request->call($url);

        if ($result && $result->size > 0) {
            return $this->collectArrayElements($result->elements);
        }

        return null;
    }

    /**
     * This method can iterate on each element
     * and fetch entities from esia by url
     *
     *
     * @param $elements array of urls
     * @return array
     * @throws \Exception
     */
    protected function collectArrayElements($elements)
    {
        $result = [];
        foreach ($elements as $element) {
            $request = $this->buildRequest();
            $source = $request->call($element, true);

            if ($source) {
                array_push($result, $source);
            }
        }

        return $result;
    }

    /**
     * @return Request
     * @throws RequestFailException
     */
    public function buildRequest()
    {
        if (!$this->token) {
            throw new RequestFailException(RequestFailException::CODE_TOKEN_IS_EMPTY);
        }

        return new Request($this->portalUrl, $this->token, $this->verifySSL);
    }

    /**
     * @throws SignFailException
     */
    protected function checkFilesExists()
    {
        if (! file_exists($this->certPath)) {
            throw new SignFailException(SignFailException::CODE_NO_SUCH_CERT_FILE);
        }
        if (! file_exists($this->privateKeyPath)) {
            throw new SignFailException(SignFailException::CODE_NO_SUCH_KEY_FILE);
        }
        if (! file_exists($this->tmpPath)) {
            throw new SignFailException(SignFailException::CODE_NO_TEMP_DIRECTORY);
        }
    }

    /**
     * @return string
     */
    private function getTimeStamp()
    {
        return date("Y.m.d H:i:s O");
    }


    /**
     * Generate state with uuid
     *
     * @return string
     */
    private function getState()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Url safe for base64
     *
     * @param string $string
     * @return string
     */
    private function urlSafe($string)
    {
        return rtrim(strtr(trim($string), '+/', '-_'), '=');
    }


    /**
     * Url safe for base64
     *
     * @param string $string
     * @return string
     */
    private function base64UrlSafeDecode($string)
    {
        $base64 = strtr($string, '-_', '+/');

        return base64_decode($base64);
    }

    /**
     * Write log
     *
     * @param string $message
     */
    private function writeLog($message)
    {
        $log = $this->log;

        if (is_callable($log)) {
            $log($message);
        }
    }

    /**
     * Generate random unique string
     *
     * @return string
     */
    private function getRandomString()
    {
        return md5(uniqid(mt_rand(), true));
    }
}

