<?php

namespace Hail\Util;

/**
 * 小数组
 * 尺寸:     msgpack < swoole = swoole(fast) < igbinary < json < hprose < serialize
 * 序列化速度:   swoole(fast) << serialize < msgpack < json < swoole << igbinary << hprose
 * 反序列化速度: swoole ~ swoole(fast) << igbinary < msgpack < serialize < hprose << json
 *
 * 大数组
 * 尺寸:     swoole < igbinary << hprose << msgpack < swoole(fast) < json << serialize
 * 序列化速度:   swoole(fast) < swoole << msgpack < serialize < igbinary =< json < hprose
 * 反序列化速度: swoole(fast) < swoole << igbinary < hprose < serialize < msgpack << json
 *
 */

use Hail\Util\Closure\Serializer;

/**
 * Class Serialize
 *
 * @package Hail\Util
 * @author  Feng Hao <flyinghail@msn.com>
 */
class Serialize
{
    public const SWOOLE = 'swoole';
    public const SWOOLE_FAST = 'swoole_fast';
    public const MSGPACK = 'msgpack';
    public const IGBINARY = 'igbinary';
    public const HPROSE = 'hprose';
    public const JSON = 'json';
    public const SERIALIZE = 'serialize';

    private const EXTENSION = 'ext';
    private const ENCODER = 'encoder';
    private const DECODER = 'decoder';

    private static $set = [
        self::MSGPACK => [
            self::EXTENSION => 'msgpack',
            self::ENCODER => '\msgpack_pack',
            self::DECODER => '\msgpack_unpack',
        ],
        self::SWOOLE => [
            self::EXTENSION => 'swoole_serialize',
            self::ENCODER => '\swoole_pack',
            self::DECODER => '\swoole_unpack',
        ],
        self::SWOOLE_FAST => [
            self::EXTENSION => 'swoole_serialize',
            self::ENCODER => '\swoole_fast_pack',
            self::DECODER => '\swoole_unpack',
        ],
        self::IGBINARY => [
            self::EXTENSION => 'igbinary',
            self::ENCODER => '\igbinary_serialize',
            self::DECODER => '\igbinary_unserialize',
        ],
        self::HPROSE => [
            self::EXTENSION => 'hprose',
            self::ENCODER => '\hprose_serialize',
            self::DECODER => '\hprose_unserialize',
        ],
        self::JSON => [
            self::EXTENSION => null,
            self::ENCODER => '\Hail\Util\Json::encode',
            self::DECODER => '\Hail\Util\Json::decode',
        ],
        self::SERIALIZE => [
            self::EXTENSION => null,
            self::ENCODER => '\serialize',
            self::DECODER => '\unserialize',
        ],
    ];

    private static $default = self::SERIALIZE;

    public static function default(?string $type): void
    {
        if ($type === null) {
            return;
        }

        self::$default = self::check($type);
    }

    private static function check(string $type): string
    {
        if (!isset(self::$set[$type])) {
            throw new \InvalidArgumentException('Serialize type not defined: ' . $type);
        }

        $extension = self::$set[$type][self::EXTENSION];
        if ($extension && !\extension_loaded($extension)) {
            if (
                \strpos($type, 'swoole') !== false &&
                \class_exists('\swoole_serialize', false)
            ) {
                self::$set[$type][self::ENCODER] = '\swoole_serialize::pack';
                self::$set[$type][self::DECODER] = '\swoole_serialize::unpack';

                if ($type === self::SWOOLE_FAST) {
                    self::$set[$type][self::ENCODER] = 'self::swooleSerializeFastPack';
                }
            } else {
                throw new \LogicException('Extension not loaded: ' . $extension);
            }
        }

        return $type;
    }

    private static function swooleSerializeFastPack(string $data)
    {
        return \swoole_serialize::pack($data, \SWOOLE_FAST_PACK);
    }

    private static function run(string $fn, $value, string $type = null)
    {
        if ($type === null || $type === self::$default) {
            $type = self::$default;
        } else {
            $type = self::check($type);
        }

        $fn = self::$set[$type][$fn];

        return $fn($value);
    }

    /**
     * @param mixed       $value
     * @param string|null $type
     *
     * @return string
     */
    public static function encode($value, string $type = null): string
    {
        return self::run(self::ENCODER, $value, $type);
    }

    /**
     * @param string      $value
     * @param string|null $type
     *
     * @return mixed
     */
    public static function decode(string $value, string $type = null)
    {
        return self::run(self::DECODER, $value, $type);
    }

    /**
     * @param string      $value
     * @param string|null $type
     *
     * @return string
     */
    public static function encodeToBase64($value, string $type = null): string
    {
        return \base64_encode(
            self::run(self::ENCODER, $value, $type)
        );
    }

    /**
     * @param string      $value
     * @param string|null $type
     *
     * @return mixed
     */
    public static function decodeFromBase64(string $value, string $type = null)
    {
        return self::run(self::DECODER, \base64_decode($value), $type);
    }

    /**
     * @param array       $array
     * @param string|null $type
     *
     * @return array
     */
    public static function encodeArray(array $array, string $type = null): array
    {
        foreach ($array as &$v) {
            $v = self::run(self::ENCODER, $v, $type);
        }

        return $array;
    }

    /**
     * @param array       $array
     * @param string|null $type
     *
     * @return array
     */
    public static function decodeArray(array $array, string $type = null): array
    {
        foreach ($array as &$v) {
            $v = self::run(self::DECODER, $v, $type);
        }

        return $array;
    }

    /**
     * @param array       $array
     * @param string|null $type
     *
     * @return array
     */
    public static function encodeArrayToBase64(array $array, string $type = null): array
    {
        foreach ($array as &$v) {
            $v = \base64_encode(self::run(self::ENCODER, $v, $type));
        }

        return $array;
    }

    /**
     * @param array       $array
     * @param string|null $type
     *
     * @return array
     */
    public static function decodeArrayFromBase64(array $array, string $type = null): array
    {
        foreach ($array as &$v) {
            $v = self::run(self::DECODER, \base64_decode($v), $type);
        }

        return $array;
    }

    /**
     * @param \Closure    $data
     * @param string|null $type
     *
     * @return string
     */
    public static function encodeClosure(\Closure $data, string $type = null): string
    {
        return Serializer::serialize($data, $type);
    }

    /**
     * @param string      $data
     * @param string|null $type
     *
     * @return \Closure
     */
    public static function decodeClosure(string $data, string $type = null): \Closure
    {
        return Serializer::unserialize($data, $type);
    }
}

Serialize::default(\env('SERIALIZE_TYPE'));
