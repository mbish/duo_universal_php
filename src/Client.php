<?php
/**
 * This contains the Client class for the Universal flow
 *
 * This SDK allows a web developer to quickly add Duo's interactive,
 * self-service, two-factor authentication to any Python web login form.
 *
 * PHP version 7
 *
 * @category Duo
 * @package  DuoUniversal
 * @author   Duo Security <support@duosecurity.com>
 * @license  https://opensource.org/licenses/BSD-3-Clause
 * @link     https://duo.com/docs/duoweb-v4
 * @file
 */
namespace Duo\DuoUniversal;

use \Firebase\JWT\JWT;
use \Firebase\JWT\SignatureInvalidException;

/**
 * This class contains the client for the Universal flow
 *
 * @category Duo
 * @package  DuoUniversal
 * @author   Duo Security <support@duosecurity.com>
 * @license  https://opensource.org/licenses/BSD-3-Clause
 * @link     https://duo.com/docs/duoweb-v4
 */
class Client
{
    const MAX_STATE_LENGTH = 1024;
    const MIN_STATE_LENGTH = 22;
    const JTI_LENGTH = 36;
    const DEFAULT_STATE_LENGTH = 36;
    const CLIENT_ID_LENGTH = 20;
    const CLIENT_SECRET_LENGTH = 40;
    const JWT_EXPIRATION = 300;
    const SUCCESS_STATUS_CODE = 200;

    const USER_AGENT = "duo_universal_php/0.0.1";
    const SIG_ALGORITHM = "HS512";
    const GRANT_TYPE = "authorization_code";
    const CLIENT_ASSERTION_TYPE = "urn:ietf:params:oauth:client-assertion-type:jwt-bearer";

    const HEALTH_CHECK_ENDPOINT = "/oauth/v1/health_check";
    const TOKEN_ENDPOINT = "/oauth/v1/token";
    const AUTHORIZE_ENDPOINT = "/oauth/v1/authorize";

    const USERNAME_ERROR = "The username is invalid.";
    const NONCE_ERROR = "The nonce is invalid.";
    const JWT_DECODE_ERROR = "Error decoding JWT";
    const PARSING_CONFIG_ERROR = "Error parsing config";
    const INVALID_CLIENT_ID_ERROR = "The Client ID is invalid";
    const INVALID_CLIENT_SECRET_ERROR = "The Client Secret is invalid";
    const DUO_STATE_ERROR = "State must be at least " . self::MIN_STATE_LENGTH . " characters long and no longer than " . self::MAX_STATE_LENGTH . " characters";
    const FAILED_CONNECTION = "Unable to connect to Duo";
    const MALFORMED_RESPONSE = "Result missing expected data.";
    const MISSING_CODE_ERROR = "Missing authorization code";

    public $client_id;
    public $api_host;
    public $redirect_url;
    private $client_secret;

    /**
     * Retrieves exception message for DuoException from HTTPS result message.
     *
     * @param array $result The result from the HTTPS request
     *
     * @return string The exception message taken from the message or MALFORMED_RESPONSE
     */
    private function getExceptionFromResult($result)
    {
        if (isset($result["message"]) && isset($result["message_detail"])) {
            return $result["message"] . ": " . $result["message_detail"];
        } elseif (isset($result["error"]) && isset($result["error_description"])) {
            return $result["error"] . ": " . $result["error_description"];
        }
        return self::MALFORMED_RESPONSE;
    }

    /**
     * Make HTTPS calls to Duo.
     *
     * @param string  $endpoint   The endpoint we are trying to hit
     * @param any     $request    Information to send to Duo
     * @param boolean $user_agent (Optional)True if we want to send
     *                            a user-agent string
     *
     * @return array of strings
     * @throws DuoException For failure to connect to Duo
     */
    protected function makeHttpsCall($endpoint, $request, $user_agent = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://" . $this->api_host . $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($user_agent !== null) {
            curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
        }
        $result = curl_exec($ch);

        /* Throw an error if the result doesn't exist or if our request returned a 5XX status */
        if (!$result) {
            throw new DuoException(self::FAILED_CONNECTION);
        }
        if (self::SUCCESS_STATUS_CODE !== curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
            throw new DuoException($this->getExceptionFromResult(json_decode($result, true)));
        }
        return json_decode($result, true);
    }

    private function createJwtPayload($audience)
    {
        $date = new \DateTime();
        $current_date = $date->getTimestamp();
        $payload =  [ "iss" => $this->client_id,
                      "sub" => $this->client_id,
                      "aud" => $audience,
                      "jti" => $this->generateRandomString(self::JTI_LENGTH),
                      "iat" => $current_date,
                      "exp" => $current_date + self::JWT_EXPIRATION
        ];
        return JWT::encode($payload, $this->client_secret, self::SIG_ALGORITHM);
    }

    /**
     * Generates a random hex string.
     *
     * @param integer $state_length The length of the hex string
     *
     * @return hex string
     * @throws DuoException    For lengths that are shorter than MIN_STATE_LENGTH or longer than MAX_STATE_LENGTH
     */
    private function generateRandomString($state_length)
    {
        if ($state_length > self::MAX_STATE_LENGTH || $state_length < self::MIN_STATE_LENGTH
        ) {
            throw new DuoException(self::DUO_STATE_ERROR);
        }
        $state = random_bytes($state_length);
        return bin2hex($state);
    }

    /**
     * Validate that the client_id and client_secret are the proper length.
     *
     * @param string $client_id      The Client ID found in the admin panel
     * @param string $client_secret  The Client Secret found in the admin panel
     * @param string $api_host       The api-host found in the admin panel
     * @param string $redirect_url   The URL to redirect back to after the prompt
     *
     * @return void
     * @throws DuoException If parameters are not strings or for invalid Client ID or Client Secret
     */
    private function validateInitialConfig(
        $client_id,
        $client_secret,
        $api_host,
        $redirect_url
    ) {
        if (!is_string($client_id) || !is_string($client_secret) || !is_string($api_host) || !is_string($redirect_url)
        ) {
            throw new DuoException(self::PARSING_CONFIG_ERROR);
        }
        if (strlen($client_id) !== self::CLIENT_ID_LENGTH) {
            throw new DuoException(self::INVALID_CLIENT_ID_ERROR);
        }
        if (strlen($client_secret) !== self::CLIENT_SECRET_LENGTH) {
            throw new DuoException(self::INVALID_CLIENT_SECRET_ERROR);
        }
    }

    /**
     * Constructor for Client class.
     *
     * @param string $client_id     The Client ID found in the admin panel
     * @param string $client_secret The Client Secret found in the admin panel
     * @param string $api_host      The api-host found in the admin panel
     * @param string $redirect_url  The URL to redirect back to after the prompt
     *
     * @return void
     * @throws DuoException For invalid Client ID or Client Secret
     */
    public function __construct(
        $client_id,
        $client_secret,
        $api_host,
        $redirect_url
    ) {
        $this->validateInitialConfig(
            $client_id,
            $client_secret,
            $api_host,
            $redirect_url
        );
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        $this->api_host = $api_host;
        $this->redirect_url = $redirect_url;
    }

    /**
     * Generate a random hex string with a length of DEFAULT_STATE_LENGTH.
     *
     * @return string
     */
    public function generateState()
    {
        return $this->generateRandomString(self::DEFAULT_STATE_LENGTH);
    }

    /**
     * Makes a call to HEALTH_CHECK_ENDPOINT to see if Duo is available.
     *
     * @return array The result of the health check
     * @throws DuoException For failure to connect to Duo or failed health check
     */
    public function healthCheck()
    {
        $audience = "https://" . $this->api_host . self::HEALTH_CHECK_ENDPOINT;
        $jwt = $this->createJwtPayload($audience);
        $request = ["client_id" => $this->client_id, "client_assertion" => $jwt];

        $result = $this->makeHttpsCall(self::HEALTH_CHECK_ENDPOINT, $request);

        if (!isset($result["stat"]) || $result["stat"] !== "OK") {
            throw new DuoException($this->getExceptionFromResult($result));
        }
        return $result;
    }

    /*
     * Generate URI to redirect to for the Duo prompt.
     *
     * @param string $username The username of the user trying to auth
     * @param string $state    Randomly generated character string of at least 22
     *                         chars returned to the integration by Duo after 2FA
     *
     * @return string The URI used to redirect to the Duo prompt
     * @throws DuoException For invalid inputs
     */
    public function createAuthUrl($username, $state)
    {
        if (!is_string($state)
            || strlen($state) < self::MIN_STATE_LENGTH || strlen($state) > self::MAX_STATE_LENGTH
        ) {
            throw new DuoException(self::DUO_STATE_ERROR);
        } elseif (!is_string($username)) {
            throw new DuoException(self::USERNAME_ERROR);
        }

        $date = new \DateTime();
        $current_date = $date->getTimestamp();
        $payload = [
            'scope' => 'openid',
            'redirect_uri' => $this->redirect_url,
            'client_id' => $this->client_id,
            'iss' => $this->client_id,
            'aud' => "https://" . $this->api_host,
            'exp' => $current_date + self::JWT_EXPIRATION,
            'state' => $state,
            'response_type' => 'code',
            'duo_uname' => $username,
            'use_duo_code_attribute' => 'True'
        ];

        $jwt = JWT::encode($payload, $this->client_secret, self::SIG_ALGORITHM);
        $allArgs = [
            'response_type' => 'code',
            'client_id' => $this->client_id,
            'scope' => 'openid',
            'redirect_uri' => $this->redirect_url,
            'request' => $jwt
        ];

        $arguments = http_build_query($allArgs);
        return "https://" . $this->api_host . self::AUTHORIZE_ENDPOINT . "?" . $arguments;
    }

    /**
     * Exchange a code returned by Duo for a token that contains information about the authorization.
     *
     * @param string $duoCode  The code returned by Duo as a URL parameter after a successful authentication
     * @param string $username The username of the user trying to authenticate with Duo
     * @param string $nonce    (Optional) Random 36B string used to associate a session with an ID token
     *
     * @return An array of strings that contains information about the authentication
     *
     * @throws DuoException For problems with parameters, malformed response from Duo,
     *                      problems decoding the JWT, the wrong username, and the wrong nonce
     */
    public function exchangeAuthorizationCodeFor2FAResult($duoCode, $username, $nonce = null)
    {
        if (!is_string($duoCode)) {
            throw new DuoException(self::MISSING_CODE_ERROR);
        } elseif (!is_string($username)) {
            throw new DuoException(self::USERNAME_ERROR);
        }

        $token_endpoint = "https://" . $this->api_host . self::TOKEN_ENDPOINT;
        $useragent = self::USER_AGENT . " php/" . phpversion() . " " . php_uname();
        $jwt = $this->createJwtPayload($token_endpoint);
        $request = ["grant_type" => self::GRANT_TYPE,
                    "code" => $duoCode,
                    "redirect_uri" => $this->redirect_url,
                    "client_id" => $this->client_id,
                    "client_assertion_type" => self::CLIENT_ASSERTION_TYPE,
                    "client_assertion" => $jwt];
        $result = $this->makeHttpsCall(self::TOKEN_ENDPOINT, $request, $useragent);

        /* Verify that we are recieving the expected response from Duo */
        $required_keys = ["id_token", "access_token", "expires_in", "token_type"];
        foreach ($required_keys as $key) {
            if (!isset($result[$key])) {
                throw new DuoException(self::MALFORMED_RESPONSE);
            }
        }
        if ($result["token_type"] !== "Bearer") {
            throw new DuoException(self::MALFORMED_RESPONSE);
        }

        try {
            $token_obj = JWT::decode($result['id_token'], $this->client_secret, [self::SIG_ALGORITHM]);
            /* JWT::decode returns a PHP object, this will turn the object into a multidimensional array */
            $token = json_decode(json_encode($token_obj), true);
        } catch (SignatureInvalidException $e) {
            throw new DuoException(self::JWT_DECODE_ERROR);
        }

        $required_token_key = ["exp", "iat", "iss", "aud"];
        foreach ($required_token_key as $key) {
            if (!isset($token[$key])) {
                throw new DuoException(self::MALFORMED_RESPONSE);
            }
        }
        /* Verify we have all expected fields in our token */
        if ($token['iss'] !== $token_endpoint || $token['aud'] !== $this->client_id) {
            throw new DuoException(self::MALFORMED_RESPONSE);
        }

        if (!isset($token['preferred_username']) || $token['preferred_username'] !== $username) {
            throw new DuoException(self::USERNAME_ERROR);
        }
        if (is_string($nonce) && (!isset($token['nonce']) || $token['nonce'] !== $nonce)) {
            throw new DuoException(self::NONCE_ERROR);
        }
        return $token;
    }
}
