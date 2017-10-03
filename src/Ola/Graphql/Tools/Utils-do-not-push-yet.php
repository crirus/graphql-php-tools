<?php
namespace Ola\GraphQL\Tools;

use GraphQL\Type\TypeKind;

class Utils
{
     private static $kinds = [];

    /**
     * @param $traversable
     * @param callable $valueFn function($value, $key) => $newValue
     * @return array
     * @throws \Exception
     */
    public static function mapValues($traversable, callable $valueFn) {
        self::invariant(is_array($traversable) || $traversable instanceof \Traversable, __METHOD__ . ' expects array or Traversable');

        $map = [];
        foreach ($traversable as $key => $value) {
            $newValue = $valueFn($value, $key);
            $map[$key] = $newValue;
        }
        return $map;
    }

    public static function getTypeKindLiteral($value) {
        if(empty(self::$kinds)) {
            $class = new \ReflectionClass('GraphQL\Type\TypeKind');
            self::$kinds = $class->getConstants();
        }

        if($value == 'INTERFACE') $value = 'INTERFACE_KIND';
        if($value == 'LIST') $value = 'LIST_KIND';
        return self::$kinds[$value];
    }

    public static function startsWith($haystack, $needle) {
        $length = strlen($needle);
        return (substr($haystack, 0, $length) === $needle);
    }

    function endsWith($haystack, $needle){
        $length = strlen($needle);
        return $length === 0 || (substr($haystack, -$length) === $needle);
    }

    public function merge($source, $destination) {
        if(!is_array($source) || !is_array($destination)) throw new \Exception("Source and destination must be array");
        return array_replace_recursive($destination, $source);

    }
}
