<?php
/**
 * @author: Koshkin Alexey (koshkin.alexey@gmail.com)
 */


/**
 * Class RemoteLib
 *
 * Simple PHP class that allows two servers exchange crypted data and call remote classes static methods.
 *
 * Each data transaction consists of request and reply. All data transactions are independent/
 * Service that makes request is called in documentation "asking service", and other service replies
 * this request and it's called "replying service". After it they can change roles but it will be other data
 * transaction. It's normal situation that one service can only ask, and other only reply.
 *
 * <example>
 *
 * // Asking service: we want to ask something
 * $data = RemoteLib::ask(
 * 		'http://remote/request.php',
 * 		'my-id',
 * 		'secret-key',
 * 		'Testcommand::defaultMethod',
 * 		array('id' => 10)
 * );
 *
 * if ($data === false) echo "Error";
 *
 * // Replying service: we want to reply something
 * print RemoteLib::reply(
 * 			'secret-key',
 * 			'/local/path/to/executable/models/'
 * 		);
 * </example>
 *
 */

class RemoteLib {

	/**
	 * Minimum recommended length of auth token
	 * If token has length less than this value it is repeated for TOKEN_MIN_LENGTH times
	 */
	const TOKEN_MIN_LENGTH = 6;

	/**
	 * HTTP success response status
	 */
	const HTTP_CODE_OK = 200;

	/**
	 * @var string Request field that contains asking service ID
	 */
	public static $requestFieldFrom = 'from';

	/**
	 * @var string Request field than contains crypted content
	 */
	public static $requestFieldContent = 'content';

	/**
	 * @var string Default method to execute in remote model
	 */
	public static $callDefaultMethod = 'run';


	/**
	 * Ask remote service for some action
	 * Executes on asking service only
	 *
	 * @param string $remoteServiceUrl Url on remote service that serves our requests (it can use RemoteLib::reply, for example)
	 * @param string $myServiceID Name of our service, should be
	 * @param string $secretKey Secret token to crypt request message (stores locally, is not transferred)
	 * @param string $remoteCommand String contains class::method on replying service to execute
	 * @param array $params Parameters that pass to $remoteCommand
	 *
	 * @return mixed | false Reply of remote service or FALSE on fail
	 *
	 * @throws RemoteServiceException
	 */
	public static function ask($remoteServiceUrl, $myServiceID, $secretKey, $remoteCommand, $params = array())
	{
		if (!$remoteServiceUrl || !$secretKey || !$myServiceID || !$remoteCommand) {
			return false;
		}

		if (
			($cryptedRequest = self::encode(
				array(
					'class' => $remoteCommand,
					'params' => $params
				),
				$secretKey
			)) === false
		) {
			throw new RemoteServiceException("Encode request error");
		}

		if (($cryptedReply = self::getRemoteData($remoteServiceUrl, $myServiceID, $cryptedRequest)) === false) {
			throw new RemoteServiceException("Get remote data error");
		}

		return self::decode($cryptedReply, $secretKey);
	}

	/**
	 * Reply to remote service
	 * Executes on replying service only
	 *
	 * @param string $secretKey Secret token to decrypt request message (stores locally, is not transferred)
	 * @param string $executeDir Directory that contains classes, that could be executed by asking service
	 * @param bool $askingServiceID Asking service ID
	 *
	 * @return string
	 *
	 * @throws RemoteServiceException
	 */
	public static function reply($secretKey, $executeDir, $askingServiceID = false)
	{
		if (!$askingServiceID) {
			$askingServiceID = self::extractRequestAsker();
		}

		if (!$askingServiceID) {
			throw new RemoteServiceException("Unknown asker");
		}

		if (($askData = self::extractRequestData()) === false) {
			throw new RemoteServiceException("Bad request");
		}

		$requestTarget = self::decode($askData, $secretKey);
		if ($requestTarget === false) {
			throw new RemoteServiceException("Decode error");
		}

		$requestTarget = self::normalizeRequestTarget($requestTarget);
		if ($requestTarget === false) {
			throw new RemoteServiceException("Bad request target");
		}

		$reply = self::getExecuteResult(
			$executeDir,
			RemoteLib::getCalledClass($requestTarget),
			null,
			RemoteLib::getCalledClassParams($requestTarget),
			$askingServiceID
		);

		if ($reply === false) {
			throw new RemoteServiceException("Bad reply result");
		}

		return self::encode($reply, $secretKey);
	}

	/**
	 * Checks that request target array is well formed
	 *
	 * @param array $requestTarget
	 *
	 * @return array | false
	 */
	public static function normalizeRequestTarget(array $requestTarget) {
		if (!is_array($requestTarget) || !isset($requestTarget['class'])) {
			return false;
		}
		if (!isset($requestTarget['params']) || !is_array($requestTarget['params'])) {
			$requestTarget['params'] = array();
		}

		return $requestTarget;
	}

	/**
	 * Returns request 'class' variable
	 *
	 * @param $requestTarget
	 *
	 * @return string | false
	 */
	public static function getCalledClass($requestTarget) {
		return isset($requestTarget['class']) && is_string($requestTarget['class']) ? $requestTarget['class'] : false;
	}

	/**
	 * Returns request 'params' array
	 *
	 * @param $requestTarget
	 *
	 * @return array
	 */
	public static function getCalledClassParams($requestTarget) {
		return isset($requestTarget['params']) && is_array($requestTarget['params']) ? $requestTarget['params'] : array();
	}

	/**
	 * Get data from reply service using Curl. Input and output are encrypted.
	 *
	 * @param string $remoteServiceUrl Url on remote service that can serve our requests
	 * @param string $askingServiceID Asking service id (NOT secret key)
	 * @param string $cryptedRequest Already encoded and crpted request body
	 *
	 * @return bool|mixed
	 *
	 * @throws RemoteServiceException
	 */
	public static function getRemoteData($remoteServiceUrl, $askingServiceID, $cryptedRequest)
	{
		if ($askingServiceID == '') {
			throw new RemoteServiceException("Blank service ID during request");
		}
		if ($cryptedRequest == '') {
			throw new RemoteServiceException("Blank data during request");
		}

		$curl = curl_init($remoteServiceUrl);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, self::prepareRequest($askingServiceID, $cryptedRequest));
		$cryptedReply = curl_exec($curl);
		$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		if ($httpCode != self::HTTP_CODE_OK) {
			throw new RemoteServiceException("HTTP reply status is not ok ({$httpCode})");
		}

		return $cryptedReply;
	}

	/**
	 * Executes $dir/$class::$method($params) and returns execution result
	 * Used only on replying service
	 *
	 * @param string $dir Directory to search callable models
	 * @param string $class Class name (filename) of called class
	 * @param string $method Method name (if not set self::$callDefaultMethod used)
	 * @param array $params Parameters that can be passed to executed method
	 * @param string $askingServiceID Name of asking service
	 * @return mixed | false
	 *
	 * @throws RemoteServiceException
	 */
	public static function getExecuteResult($dir, $class, $method = null, $params = array(), $askingServiceID = null) {
		if (!$dir || !$class) {
			throw new RemoteServiceException("Execute path is not set");
		}

		if (strpos($class, '::') !== false) {
			list ($class, $method) = explode('::', $class, 2);
		} elseif (!$method) {
			$method = self::$callDefaultMethod;
		}

		if ($dir[count($dir) - 1] != DIRECTORY_SEPARATOR) {
			$dir .= DIRECTORY_SEPARATOR;
		}
		if (!file_exists($dir . $class . '.php')) {
			return false;
		}
		try {
			require_once ($dir . $class . '.php');
		} catch (Exception $e) {
			throw new RemoteServiceException("Remote model include error");
		}

		if (!method_exists($class, $method)) {
			throw new RemoteServiceException("Unknown remote method");
		}

		// If we have beforeAction trigger in called model firstly we call it
		if (method_exists($class, 'beforeAction')) {
			$triggerResult = $class::beforeAction($method, $askingServiceID, $params);
			if ($triggerResult === true) {
				return $class::$method($params);
			} else {
				return $triggerResult;
			}
		} else {
			return $class::$method($params);
		}
	}

	/**
	 * Prepares request to send request to remote service
	 *
	 * @param string $askingServiceID Asking service id (NOT secret key)
	 * @param string $cryptedData  Already encoded and crpted request body
	 *
	 * @return array
	 */
	public function prepareRequest($askingServiceID, $cryptedData)
	{
		return array(
			self::$requestFieldFrom => $askingServiceID,
			self::$requestFieldContent => $cryptedData,
		);
	}

	/**
	 * Extracts asking service ID from request
	 *
	 * @return string | false
	 */
	public function extractRequestAsker()
	{
		return isset($_POST[self::$requestFieldFrom]) ? $_POST[self::$requestFieldFrom] : false;
	}

	/**
	 * Extracts crypted request data
	 *
	 * @return string | false
	 */
	public function extractRequestData()
	{
		return isset($_POST[self::$requestFieldContent]) && $_POST[self::$requestFieldContent] != '' ? $_POST[self::$requestFieldContent] : false;
	}


	/**
	 * Encode and crypt data before send
	 *
	 * @param array $data Data to encode
	 * @param string $secretKey Key that defines transposition table used on crypting (the same as used to decrypt)
	 *
	 * @return string
	 * @throws RemoteServiceException
	 */
	public function encode($data, $secretKey)
	{
		if (!$secretKey) {
			throw new RemoteServiceException("Trying to encode without secret key");
		}
		return strtr(base64_encode(json_encode($data)), self::createCryptArray($secretKey, false));
	}


	/**
	 * Decrypt and decode data after receive
	 *
	 * @param string $data Crypted and encoded string
	 * @param string $secretKey Key that defines transposition table used on decrypting (the same as used to crypt)
	 *
	 * @return mixed | false
	 */
	public function decode($data, $secretKey)
	{
		if (!$secretKey) return false;

		$decrypted = strtr($data, self::createCryptArray($secretKey, true));
		if (($decoded = base64_decode($decrypted)) === false)
			return false;

		return json_decode($decoded, true);
	}


	/**
	 * Makes crypt table, using token as input.
	 * Crypting is symmetric, so if we know token we can build reverse array on other server, using the same function
	 *
	 * @param string $token Secret token that defines array items transposition
	 * @param bool $reverse If is true returns array, to make reverse transposition
	 *
	 * @return array Transposition array that is good for use in strtr() function
	 */
	public static function createCryptArray($token, $reverse = false)
	{
		$char_array = array(
			'0','1','2','3','4','5','6','7','8','9',
			'A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z',
			'a','b','c','d','e','f','g','h','i','j','k','l','m','n','o','p','q','r','s','t','u','v','w','x','y','z'
		);
		$char_array_new = $char_array;

		if (strlen($token) < self::TOKEN_MIN_LENGTH) {
			$token = str_repeat($token, self::TOKEN_MIN_LENGTH);
		}
		$hash = md5($token) . md5(substr($token, 1));
		for ($i = 0; $i < strlen($token); $i++) {
			$hash .= md5(substr($token, $i));
		}

		$cnt = count($char_array);
		for ($i = 0; $i < strlen($hash) - 4; $i += 4) {

			$k1 = (ord($hash[$i]) * ord($hash[$i + 2])) % $cnt;
			$k2 = (ord($hash[$i + 1]) * ord($hash[$i + 3])) % $cnt;

			$tmp = $char_array_new[$k1];
			$char_array_new[$k1] = $char_array_new[$k2];
			$char_array_new[$k2] = $tmp;

		}

		return $reverse ? array_combine($char_array, $char_array_new) : array_combine($char_array_new, $char_array);
	}

}

/**
 * Class RemoteServiceTransferException
 */
class RemoteServiceException extends Exception {

}
