<?php
namespace Hail\Redis\Client;

use Hail\Redis\Exception\RedisException;

/**
 * Class ConnectPool
 *
 * @package Hail\Redis\Client
 * @author Feng Hao <flyinghail@msn.com>
 * @inheritdoc
 */
class ConnectPool extends PhpRedis
{
	/**
	 * @throws RedisException
	 */
    public function connect()
	{
		$this->redis = new \redisProxy();
		parent::connect();
	}

	/**
	 * @return bool
	 */
	public function close()
	{
		$this->redis = null;
		$this->connected = false;
		return true;
	}

	public function __call($name, $args)
	{
		$response = parent::__call($name, $args);

		if ($this->redisMulti === null) {
			$this->redis->release();
		}

		return $response;
	}
}
