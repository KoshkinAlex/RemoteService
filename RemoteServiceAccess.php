<?php
/**
 * @author: Koshkin Alexey (koshkin.alexey@gmail.com)
 */

/**
 * Class RemoteServiceAccess
 *
 * Remote service access YII component based on @see RemoteLib library
 * Allows php scripts on remote servers talk using crypted data messages and call remote classes methods
 *
 * 	NOTICE: 	Full description you can read in RemoteLib class comments.
 *				This class is only wrapper to use RemoteLib as YII component.
 *
 * Dialog of two application cen be full-duplex or half duplex (only request or only reply).
 * In sample application config that is shown below in square brackets following notations are used:
 * 		[A] means that parameter is used on asking service
 * 		[R] means that parameter is used on replying service
 *
 * <example>
 * 	...
 *	// This is a part of application config
 *	'remote'=> array (
 * 		'class'=>'RemoteServiceAccess',
 * 		'myServiceID'=>'myApp', // [A] Name of our application
 * 		'services' => array(
 * 			// [A,R] Each item of array contains one remote service. It coud be as many remote services as we want.
 * 			'remoteApp'=> array (
 * 				'url'=>'http://remote.url/index.php?r=remote', // [A] URL that we use to connect on remote service
 * 				'key-reply'=>'secret-key-reply', // [R] This key we use to crypt messages when we are replying service
 * 				'key-request'=>'secret-key-request', [A] // This key we use to crypt messages when we are asking service
 * 				'key'=>'secret-key: common-key' // [A,R] We can use this key instead of previous two keys
 * 			),
 * 		)
 * 	...
 * </example>
 *
 *
 */

class RemoteServiceAccess extends CComponent
{

	/**
	 * @var string Name of current service
	 */
	public $myServiceID = null;

	/**
	 * @var string Directory that contains classes that could be executed by remote services
	 * Used only on replying service.
	 */
	public $modelDir = 'protected/models/remote/';

	/**
	 * @var array List of services witch we can talk.
	 * Settings defined for each service define witch roles has each service (ask/reply or both)
	 */
	public $services = array();

	/**
	 * @var array Array of RemoteServiceItem
	 */
	private $_serviceItems = array();

	/**
	 * @var string We call this method by default on remote model when we send to replying service only name of class
	 */
	public $defaultMethod = 'run';

    public function init()
    {

    }


	/**
	 * Getter for using following syntax Yii::app()->remote->remoteApp->ask(...)
	 *
	 * @param $remoteServiceID Name of remote service we want to ask
	 * @return RemoteServiceItem
	 * @throws CRemoteServiceException
	 */
	public function __get($remoteServiceID)
    {
        if (isset($this->_serviceItems) && is_array($this->_serviceItems) && isset($this->_serviceItems[$remoteServiceID])) {
            return $this->_serviceItems[$remoteServiceID];
        }

        if (!isset($this->services) || !is_array($this->services) || !isset($this->services[$remoteServiceID])) {
            throw new CRemoteServiceException('Unknown service requested');
        }
        $this->_serviceItems[$remoteServiceID] = new RemoteServiceItem($remoteServiceID);

        return $this->_serviceItems[$remoteServiceID];
    }

	/**
	 * Reply to remote service. Used on replying service.
	 *
	 * @return string
	 * @throws CRemoteServiceException
	 */
	public function reply()
    {
        if (($askingServiceID = RemoteLib::extractRequestAsker()) === false) {
            throw new CRemoteServiceException("RemoteServiceAccess: Can't reply because unknown asker");
        }

        if (!$this->modelDir) {
            throw new CRemoteServiceException("RemoteServiceAccess: you don't setup model directory");
        }

		try {
			return RemoteLib::reply(
				$this->_getSecretKeyReply($askingServiceID),
				$this->modelDir,
				$askingServiceID
			);
		} catch (RemoteServiceException $e) {
			throw new CRemoteServiceException($e->getMessage());
		}
    }

    /**
     * Ask remote service. Used on asking service.
     *
     * @param       $serviceID
     * @param null  $remoteClass
     * @param array $params
     *
     * @return array|bool
     * @throws CRemoteServiceException
     */
	public function ask($serviceID, $remoteClass, $params = array())
    {
        if (!$serviceID) {
            throw new CRemoteServiceException("RemoteServiceAccess: Can't ask remote service without id");
        }

		try {
			return RemoteLib::ask(
				$this->getAskUrl($serviceID),
				$this->myServiceID,
				$this->_getSecretKeyRequest($serviceID),
				$remoteClass,
				$params
			);
		} catch (RemoteServiceException $e) {
			throw new CRemoteServiceException("RemoteServiceAccess: ask error. ".$e->getMessage());
		}
    }

    /**
     * Get secret key to ask remote service by $serviceID
     *
     * @param $serviceID
     *
     * @return mixed
     * @throws CRemoteServiceException
     */
    private function _getSecretKeyRequest($serviceID)
    {
        $this->_checkServiceID($serviceID);
        if (isset($this->services[$serviceID]['key-request'])) {
            return $this->services[$serviceID]['key-request'];
        } elseif (isset($this->services[$serviceID]['key'])) {
            return $this->services[$serviceID]['key'];
        } else {
            throw new CRemoteServiceException("RemoteServiceAccess: Can't get request secret key for service id=[{$serviceID}]");
        }
    }

    /**
     * Get secret key to reply remote service by $serviceID
     *
     * @param $serviceID
     *
     * @return mixed
     * @throws CRemoteServiceException
     */
    private function _getSecretKeyReply($serviceID)
    {
        $this->_checkServiceID($serviceID);
        if (isset($this->services[$serviceID]['key-reply'])) {
            return $this->services[$serviceID]['key-reply'];
        } elseif (isset($this->services[$serviceID]['key'])) {
            return $this->services[$serviceID]['key'];
        } else {
            throw new CRemoteServiceException("RemoteServiceAccess: Can't get reply secret key for service id=[{$serviceID}]");
        }
    }

    /**
     * Checks if requested service exists in application
     *
     * @param $serviceID
     *
     * @throws CRemoteServiceException
     */
    private function _checkServiceID($serviceID)
    {
        if (!(
            $this->services
            && is_array($this->services)
            && isset($this->services[$serviceID])
            && is_array($this->services[$serviceID])
        )
        ) {
            throw new CRemoteServiceException("RemoteServiceAccess: Unknown service id=[{$serviceID}]");
        }
    }

    /**
     * Get URL on remote service to connect by $serviceID
     *
     * @param $serviceID
     *
     * @return mixed
     * @throws CRemoteServiceException
     */
	private function getAskUrl($serviceID)
    {
        if (
            $this->services
            && is_array($this->services)
            && isset($this->services[$serviceID])
            && isset($this->services[$serviceID]['url'])
        ) {
            return $this->services[$serviceID]['url'];
        } else {
            throw new CRemoteServiceException("RemoteServiceAccess: Can't get ask url for service id=[{$serviceID}]");
        }
    }

}


/**
 * Class RemoteServiceItem
 * Instance of one remote service. Used only for magic methods.
 */
class RemoteServiceItem extends CComponent
{
    private $_serviceID = null;

    public function __construct($serviceID)
    {
        $this->_serviceID = $serviceID;

        return $this;
    }

    public function __call($method, $params)
    {
        return Yii::app()->remote->ask($this->_serviceID, $method, $params);
    }

}


/**
 * Class RemoteServiceTransferException
 */
class CRemoteServiceException extends RemoteServiceException {

}
