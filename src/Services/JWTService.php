<?php

namespace markhuot\CraftQL\Services;

use Craft;
use craft\elements\User;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use markhuot\CraftQL\CraftQL;
use yii\base\Component;

class JWTService extends Component {

    private $key;

    function __construct($config=[]) {
        parent::__construct($config);

        if (CraftQL::getInstance()->getSettings()->securityKey) {
            $this->key = CraftQL::getInstance()->getSettings()->securityKey;
        }
        else {
            $this->key = Craft::$app->config->general->securityKey;
        }
    }

    function encode($string) {
        return JWT::encode($string, $this->key);
    }

    /**
     * @param $string
     * @return object
     *
     * @throws \UnexpectedValueException     Provided JWT was invalid
     * @throws \Firebase\JWT\SignatureInvalidException    Provided JWT was invalid because the signature verification failed
     * @throws \Firebase\JWT\BeforeValidException         Provided JWT is trying to be used before it's eligible as defined by 'nbf'
     * @throws \Firebase\JWT\BeforeValidException         Provided JWT is trying to be used before it's been created as defined by 'iat'
     * @throws \Firebase\JWT\ExpiredException             Provided JWT has since expired, as defined by the 'exp' claim
     */
    function decode($string) {
        return JWT::decode($string, $this->key, ['HS256']);
    }

    /**
     * @param $token
     * @return object
     *
     * @throws \UnexpectedValueException     Provided JWT was invalid
     * @throws \Firebase\JWT\SignatureInvalidException    Provided JWT was invalid because the signature verification failed
     * @throws \Firebase\JWT\BeforeValidException         Provided JWT is trying to be used before it's eligible as defined by 'nbf'
     * @throws \Firebase\JWT\BeforeValidException         Provided JWT is trying to be used before it's been created as defined by 'iat'
     * @throws \Firebase\JWT\ExpiredException             Provided JWT has since expired, as defined by the 'exp' claim
     */
    function refreshDecode($token) {
        try {
            $this->decode($token);
        } catch(\Firebase\JWT\ExpiredException $e) {

            list($header, $payload, $signature) = explode(".", $token);
            $tokenData = JWT::jsonDecode(JWT::urlsafeB64Decode($payload));

            if(!isset($tokenData->refresh_token)){
                throw $e;
            }

            $refreshToken = $tokenData->refresh_token;
            $user = \craft\elements\User::find()->id($tokenData->id)->one();
            if($refreshToken != $this->refreshToken($user)){
                throw $e;
            }

            return $tokenData;
        }
    }

    function tokenForUser(User $user) {
        $defaultTokenDuration = CraftQL::getInstance()->getSettings()->userTokenDuration;

        $tokenData = [
            'id' => $user->id,
            'refresh_token' => $this->refreshToken($user)
        ];

        if ($defaultTokenDuration > 0) {
            $tokenData['exp'] = time() + $defaultTokenDuration;
        }

        return $this->encode($tokenData);
    }

    function refreshToken(User $user) {
        $lastLogin = ($lastLogin = $user->lastLoginDate) ? $lastLogin->format(DATE_ATOM) : '';
        $passwordChanged = ($passwordChanged = $user->lastPasswordChangeDate) ? $passwordChanged->format(DATE_ATOM) : '';
        return hash('sha256', "$lastLogin:$passwordChanged");
    }

}
