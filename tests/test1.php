<?php

require_once __DIR__ . '/../src/RedLock.php';

$redisInstance1 = new \Redis();
$redisInstance1->connect('127.0.0.1', 6379, 0.01);

$redisInstance2 = new \Redis();
$redisInstance2->connect('127.0.0.1', 6389, 0.01);

$redisInstance3 = new \Redis();
$redisInstance3->connect('127.0.0.1', 6399, 0.01);

$servers = [$redisInstance1, $redisInstance2, $redisInstance3];

$redLock = new \RedLock\RedLock($servers);

while (true) {
    $lock = $redLock->lock('test', 10000);

    if ($lock) {
        print_r($lock);
    } else {
        print "Lock not acquired\n";
    }
}
