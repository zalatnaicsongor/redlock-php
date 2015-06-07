<?php

namespace RedLock;

class RedLock
{
    private $retryDelay;
    private $retryCount;
    private $clockDriftFactor = 0.01;

    private $quorum;

    /**
     * @var \Redis[]
     */
    private $servers = array();

    /**
     * @var \Redis[]
     */
    private $instances = array();

    private $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            else
                return 0
            end
        ';

    /**
     * @param array $servers An array of already connected and authenticated Redis or Snc\RedisBundle\Client\Phpredis instances
     * @param int $retryDelay
     * @param int $retryCount
     */
    function __construct(array $servers, $retryDelay = 200, $retryCount = 3)
    {
        $this->servers = $servers;

        $this->retryDelay = $retryDelay;
        $this->retryCount = $retryCount;

        $this->quorum = min(count($servers), floor(count($servers) / 2 + 1));
    }

    public function lock($resource, $ttl)
    {
        $this->initInstances();

        $token = base64_encode(mcrypt_create_iv(20, MCRYPT_DEV_URANDOM));
        $retry = $this->retryCount;

        do {
            $n = 0;

            $startTime = microtime(true) * 1000;

            foreach ($this->instances as $instance) {
                if ($this->lockInstance($instance, $resource, $token, $ttl)) {
                    $n++;
                }
            }

            # Add 2 milliseconds to the drift to account for Redis expires
            # precision, which is 1 millisecond, plus 1 millisecond min drift
            # for small TTLs.
            $drift = ($ttl * $this->clockDriftFactor) + 2;

            $validityTime = $ttl - (microtime(true) * 1000 - $startTime) - $drift;

            if ($n >= $this->quorum && $validityTime > 0) {
                return [
                    'validity' => $validityTime,
                    'resource' => $resource,
                    'token' => $token,
                ];

            } else {
                foreach ($this->instances as $instance) {
                    $this->unlockInstance($instance, $resource, $token);
                }
            }

            // Wait a random delay before to retry
            $delay = mt_rand(floor($this->retryDelay / 2), $this->retryDelay);
            usleep($delay * 1000);

            $retry--;

        } while ($retry > 0);

        return false;
    }

    private function initInstances()
    {
        if (empty($this->instances)) {
            foreach ($this->servers as $server) {
                if (!$server instanceof \Redis) {
                    if (get_class($server) != 'Snc\RedisBundle\Client\Phpredis') {
                        throw new \InvalidArgumentException(
                            "Redis or Snc\\RedisBundle\\Client\\Phpredis instance expected, got something else"
                        );
                    }
                }
                $this->instances[] = $server;
            }
        }
    }

    private function lockInstance($instance, $resource, $token, $ttl)
    {
        try {
            return $instance->set($resource, $token, ['NX', 'PX' => $ttl]);
        } catch (\RedisException $e) {
            return false;
        }
    }

    private function unlockInstance($instance, $resource, $token)
    {
        try {
            return $instance->eval($this->script, [$resource, $token], 1);
        } catch (\RedisException $e) {
            return false;
        }
    }

    public function unlock(array $lock)
    {
        $this->initInstances();
        $resource = $lock['resource'];
        $token = $lock['token'];

        foreach ($this->instances as $instance) {
            $this->unlockInstance($instance, $resource, $token);
        }
    }
}
