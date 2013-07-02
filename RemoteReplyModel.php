<?php
/**
 * @author: Koshkin Alexey (koshkin.alexey@gmail.com)
 */

/**
 * Class RemoteReplyModel
 * An addition to @see RemoteLib
 * Used only on replying service
 *
 * Parent model for all models that could be called from remote service
 */

abstract class RemoteReplyModel
{
	/**
	 * @return array of allowed actions and rules
	 */
	public static function allowedActions()
	{
		return array(
		);
	}

    /**
	 * Trigger that is called on replying service before any called method
	 * Used to check access rules
	 *
     * @param string $method Method that is requested by remote asking service
     * @param string $askingServiceID Remote asking service name
     * @param array $params Params with witch requested method is called
     *
     * @return bool If action (call method) is allowed
     * @throws Exception
     */
    public static function beforeAction($method, $askingServiceID, $params)
    {
		$className = get_called_class();
		if (!method_exists($className, 'allowedActions')) {
			throw new Exception("Can't validate action without allowedActions method");
		}

		$list = $className::allowedActions();

		if (!isset($list[$method])) {
			return self::noAccess();
		}

		if ($list[$method] == '*') {
			return true;
		}

		if (array_search($askingServiceID, $list[$method]) !== false ||
			array_search('*', $list[$method]) !== false
		) {
			return true;
		}

		return self::noAccess();
    }

    public static function noAccess()
    {
		throw new Exception("You don't have access to this module");
        return false;
    }
}