<?php

/**
 * A component to interface with Memcache. Uses a Cake-styled multidimensional map of keys instead
 * of a linear list. Several missing dependencies here (including Cake's Hash utility class) and
 * certainly won't stand on its own, but an interesting example nonetheless.
 */
 
App::import('Vendor', 'Hash', array('file' => 'cake/Hash.php'));
class MemcacheComponent extends Object {

    /**
     * @var Cache The Cake cache engine to interface with.
     */
    private $Connection = null;
    
    /**
     * @var Controller The cake controller to hook in to.
     */
    private $Controller = null;
    
    /**
     * @var string The root level to house all of the available keys.
     */
    private $_globalNamespace = 'global';
    
    /**
     * @var string The key to use as a reference to the keys list.
     */
    private $_mapNamespace = 'cache-key-map';
    
    /**
     * @var string Prefix to prepend to keys to store meta data regarding the cached data.
     */
    private $_metaNamespace = 'meta';
    
    /**
     * @var array Internal reference table for building and validating keys.
     */
    private $_map = array();
    
    /**
     * @var array List of app directories to use for prepending and building keys. This list is
     * created during startup().
     */
    private $_appDirs = array();
    
    /**
     * @var integer Time (in seconds) before a map of cache keys should expire.
     */
    private $_mapExpirationTime = HOUR;
    
    /**
     * @var array Default Memcache sever options
     * @link http://php.net/manual/en/memcache.addserver.php
     */
    private $_memcacheServerDefaults = array(
        'host'              => '',
        'port'              => 11211,
        'persistent'        => true,
        'weight'            => 1,
        'timeout'           => 1,
        'retry_interval'    => 15,
        'status'            => true,
    );
    
    /**
     * @var string Separator for keys.
     */
    private $_delimiter = '.';
    
    /**
     * @var integer Default duration for cache entries in seconds.
     */
    private $_duration = 10 * MINUTE;
    
    /**
     * @var bool Toggle meta data storage on/off.
     */
    public $_enableMeta = true;
    
    /**
     * Initializes the component. Creates a reference to both the attached Controller and to a
     * Memcache instance. Configures the `_appDirs` property.
     * @param Controller $controller The controller to create a reference property for.
     */
    public function startup(&$controller) {
        $this->Controller =& $controller;
        
        $this->Connection =& new Memcache;
        foreach(Configure::read('Memcache.servers') as $server) {
            $serverOpts = array_merge($this->_memcacheServerDefaults, $server);
            call_user_func_array(array($this->Connection, 'addServer'), $serverOpts);
        }
        
        $appDirs = scandir(ROOT);
        foreach($appDirs as $appDir) {
            if (!in_array($appDir{0}, array('.', '_')) && is_dir(ROOT . DS . $appDir) && file_exists(ROOT . DS . $appDir . DS . 'index.php')) {
                $this->_appDirs[] = $appDir;
            }
        }
        if (!in_array(APP_DIR, $this->_appDirs)) {
            $this->_appDirs[] = APP_DIR;
        }
        
        $this->_cleanUp();
    }
    
    /**
     * Writes information to the cache.
     * @param string $key The key to store the entry under
     * @param mixed $value Write whatever your heart desires to the cache!
     * @param mixed $duration How long to store the entry in the cache. Can be strtotime()-friendly
     * or an integer measured in seconds. If left in boolean false, falls back to default cache
     * duration as specified in the core configuration.
     */
    public function write($key, $value, $duration = false) {
    
        if (is_string($duration)) {
            $duration = strtotime($duration) - time();
        }
        
        if (!is_integer($duration)) {
            $duration = $this->_duration;
        }
        
        $this->_resolveMap();
        $key = $this->_resolveKey($key);
        
        if (is_array($value)) {
            // Assume that we want to overwrite the provided key with the new structure
            $this->Connection->delete($this->_encode($key));
            
            $temp = Hash::flatten($value);
            foreach($temp as $left => $right) {
                $this->write($key . $this->_delimiter . $left, $right, $duration);
            }
        } else {
            $this->Connection->set($this->_encode($key), $value, MEMCACHE_COMPRESSED, $duration);
            $this->_addKey($key);
            
            if ($this->_enableMeta) {
                if (!preg_match('/^' . preg_quote($this->_metaNamespace . $this->_delimiter, '/') . '?/', $key)) {
                    $metaKey = $this->_resolveKeyMeta($key);
                    $meta = array(
                        'duration'    => $duration,
                        'key'         => $key,
                        'keyEncoded'  => $this->_encode($key),
                        'created'     => time(),
                        'expires'     => time() + $duration,
                        
                        // Internal method for fetching user identifier
                        'author'      => $this->Controller->Authorization->userInfo('email'),
                    );
                    
                    $this->write($metaKey, $meta, $duration);
                }
            
            }
        }
    }
    
    /**
     * Fetches an entry from the cache. Delegates to _read().
     * @param string $key The key to read associated data from
     * @return mixed The value provided from reading the key
     */
    public function read($key) {
        $this->_resolveMap();
        $key = $this->_resolveKey($key);
        
        return $this->_read($key);
    }
    
    /**
     * Fetches meta data for an entry in the cache. Delegates to _read().
     * @param string $key The key to read associated data from
     * @return array A list of meta data provided from reading the key
     */
    public function readMeta($key) {
        if (!$this->_enableMeta) {
            return false;
        }
        
        $this->_resolveMap();
        $key = $this->_resolveKeyMeta($key);
        
        return $this->_read($key);
    }
    
    /**
     * Method for previewing the structure of a portion of the key map. Useful for debugging!
     * Accepts a key as a parameter to condense the map to a provided path.
     * @param string $pre The key to limit the map to.
     */
    public function map($pre = APP_DIR) {
        $this->_resolveMap();
        
        if (!is_string($pre) || !$pre) {
            $pre = $this->_globalNamespace . $this->_delimiter . APP_DIR;
        }
        
        $key = $this->_resolveKey($pre);
        
        $map = array();
        foreach(array_keys($this->_map) as $entry) {
            if (preg_match('/^' . preg_quote($key . $this->_delimiter, '/') . '?/', $entry)) {
                $map[preg_replace('/^' . preg_quote($key . $this->_delimiter, '/') . '?/', '', $entry)] = false;
            }
        }
        return $this->_hashExpand($map);
    }
    
    /**
     * Alias for `_destroy` method.
     * @param string $key The key to delete related data for
     */
    public function delete($key) {
        $this->_destroy($key);
    }
    
    /**
     * Alias for `_destroy` method.
     * @param string $key The key to delete related data for
     */
    public function destroy($key) {
        $this->_destroy($key);
    }
    
    /**
     * Deletes an entry from the cache.
     * @param string $key The key to delete related data for
     */
    private function _destroy($key) {
        $key = $this->_resolveKey($key);
        
        $map = $this->read($key);
        
        if (is_array($map) && array_diff($map, array(0 => false))) {
            $temp = Hash::flatten($map);
            foreach(array_keys($map) as $left) {
                $keyNode = $key . $this->_delimiter . $left;
                $this->_destroy($keyNode);
            }
        } else {
            $this->Connection->delete($this->_encode($key));
            $this->_removeKey($key);
            
            if ($this->_enableMeta && !preg_match('/^' . preg_quote($this->_metaNamespace . $this->_delimiter, '/') . '?/', $key)) {
                $meta = $this->readMeta($key);
                if (!empty($meta)) {
                    $meta = array_keys(Hash::flatten($meta));
                    foreach($meta as $metaKey) {
                        $metaKey = $this->_resolveKeyMeta($key . $this->_delimiter . $metaKey);
                        $this->_destroy($metaKey);
                    }
                }
            }
        }
        
        $this->_writeMap();
    }
    
    /**
     * A slightly modified version of `Hash::merge()`. Altered to preserve numeric keys in a flat
     * array.
     * @param array $data The array to serve as a destination for the merged data.
     * @param array $merge... The data to merge.
     * @return array The merge arrays.
     */
    public static function _hashMerge(array $data, $merge) {
        $args = func_get_args();
        $return = current($args);

        while (($arg = next($args)) !== false) {
            foreach ((array)$arg as $key => $val) {
                if (!empty($return[$key]) && is_array($return[$key]) && is_array($val)) {
                    $return[$key] = self::_hashMerge($return[$key], $val);
                } else {
                    $return[$key] = $val;
                }
            }
        }
        return $return;
    }
    
    /**
     * A slightly modified version of `Hash::expand()`. Altered to preserve numeric keys in flat
     * arrays.
     * @param array $data The array to blow up.
     * @return array The array, now less flat!
     */
    private function _hashExpand($data) {
        $result = array();
        foreach ($data as $flat => $value) {
            $keys = explode($this->_delimiter, $flat);
            $keys = array_reverse($keys);
            $child = array(
                $keys[0] => $value
            );
            array_shift($keys);
            foreach ($keys as $k) {
                $child = array(
                    $k => $child
                );
            }
            $result = self::_hashMerge($result, $child);
        }
        return $result;
    }
    
    /**
     * Fetches data from the cache.
     * @param string $key The key to read associated data from
     * @return mixed The value provided from reading the key
     */
    private function _read($key) {
        $map = $this->_hashExpand($this->_map);
        $data = Hash::extract($map, $key);
        
        $out = array();
        if (empty($data) || $data === array(0 => false)) {
            $out = $this->Connection->get($this->_encode($key));
        } else if (is_array($data)) {
            $nodes = Hash::flatten($data);
            foreach(array_keys($nodes) as $node) {
                $out[$node] = $this->Connection->get($this->_encode($key . $this->_delimiter . $node));
            }
            $out = self::_hashExpand($out);
        } else {
            return false;
        }
        return $out;
    }
    
    /**
     * Adds a key to the map and stores the new map to the cache. the `_map` property is updated to
     * reflect changes.
     * @param string $key The key to add to the map
     */
    private function _addKey($key) {
        $this->_resolveMap();
        
        $this->_map = $this->_hashMerge($this->_map, array($key => false));
        
        $this->_writeMap();
    }
    
    /**
     * Removes a key from the map and stores the new map to the cache. The `_map` property is
     * updated to reflect changes.
     * @param string $key The key to remove from the map.
     */
    private function _removeKey($key) {
        $this->_resolveMap(true);
        $key = $this->_resolveKey($key);
        unset($this->_map[$key]);
        $this->_writeMap();
    }
    
    /**
     * Writes the value of the `_map` property to the cache.
     */
    private function _writeMap() {
        $this->Connection->set($this->_encode($this->_mapNamespace), $this->_map, MEMCACHE_COMPRESSED, $this->_mapExpirationTime);
    }
    
    /**
     * Updates the value of the `_map` property.
     * @param bool $force Forces an import of the map in the cache. This should be done before
     * writing to the map in the cache at any time.
     */
    private function _resolveMap($force = false) {
        if (empty($this->_map) || $force === true) {
            $map = $this->Connection->get($this->_encode($this->_mapNamespace));
            if (is_array($map)) {
                $this->_map = $this->_hashMerge($this->_map, $map);
            }
        }
    }
    
    /**
     * Determines the "proper" path for a key by checking for global house and app directory paths.
     * @param string $key The key to resolve.
     * @return string The resolved key.
     */
    private function _resolveKey($key) {
        $firstFragment = substr($key, 0, strpos($key, $this->_delimiter) ? strpos($key, $this->_delimiter) : strlen($key));
        if (!in_array($firstFragment, array_merge(array($this->_globalNamespace, $this->_metaNamespace), $this->_appDirs))) {
            $key = APP_DIR . $this->_delimiter . $key;
        }
        if (!preg_match('/^(' . preg_quote($this->_globalNamespace, '/') . '|' . preg_quote($this->_metaNamespace, '/') . ')' . preg_quote($this->_delimiter, '/') . '?/', $key)) {
            $key = $this->_globalNamespace . $this->_delimiter . $key;
        }
        return $key;
    }
    
    /**
     * Determines the "proper" path for a _meta_ key, similar to `_resolveKey()`.
     * @param string $key The key to resolve.
     * @return string The resolved meta key.
     */
    private function _resolveKeyMeta($key) {
        $key = $this->_resolveKey($key);
        if (!preg_match('/^' . preg_quote($this->_metaNamespace . $this->_delimiter, '/') . '?/', $key)) {
            $key = $this->_metaNamespace . $this->_delimiter . $key;
        }
        return $key;
    }
    
    /**
     * Encodes a key for read/write use in the Memcache engine.
     * @param string $data The data to encode.
     * @return string The encoded `$data` passed through.
     */
    private function _encode($data) {
        return md5($data);
    }
    
    /**
     * Flushes the cache completely.
     */
    public function kill() {
        $this->Connection->flush();
        $this->_map = array();
        $this->_writeMap();
    }
    
    /**
     * Cleans up an dead keys on the map.
     */
    public function _cleanUp() {
        $this->_resolveMap();
        
        if ($this->_enableMeta) {
            $map = Hash::flatten($this->map($this->_globalNamespace));
            foreach(array_keys($map) as $key) {
                $key = $this->_resolveKey($key);
                $meta = $this->readMeta($key);
                if (empty($meta['expires']) || $meta['expires'] < time()) {
                    $this->_destroy($key);
                }
            }
        }
    }
    
    /**
     * The test method runs a test to test the test test.
     */
    public function test() {
        pr('*****************************');
        pr('** MEMCACHE COMPONENT TEST **');
        pr('*****************************');
        
        $key = 'App.rand.' . chr(mt_rand(97, 122));
        $value = '';
        for($i = 0; $i < 5; $i++) {
            $value .= chr(mt_rand(97, 122));
        }
        
        $start = time();
        
        pr('Using the following test data:');
        pr(' > Key: ' . $key);
        pr(' > Value: ' . $value);
        
        pr("\r\n\r\n");
        
        $tempKey = $this->_resolveKey($key);
        $tempKeyMeta = $this->_resolveKeyMeta($tempKey);
        
        pr('Writing ' . $tempKey . '...');
        $this->write($tempKey, $value, '10 seconds');
        
        pr("\r\n\r\n");
        
        pr('Reading ' . $tempKey . '...');
        $entry = $this->read($tempKey);
        pr($entry);
        
        pr("\r\n\r\n");
        
        pr('Does the return value match the original value?');
        pr(' > ' . ($entry === $value ? 'Yes' : 'No'));
        
        pr("\r\n\r\n");
        
        pr('Reading meta for ' . $tempKeyMeta . '...');
        $meta = $this->readMeta($tempKey);
        pr($meta);
        
        pr("\r\n\r\n");
        
        pr('Does it exist in the map?');
        pr(' > Index: ' . (isset($this->_map[$tempKey]) ? 'Yes' : 'No'));
        pr(' > Meta: ' . (isset($this->_map[$tempKeyMeta . $this->_delimiter . 'expires']) ? 'Yes' : 'No'));
        
        pr("\r\n\r\n");
        
        pr('Destroying ' . $tempKey . '...');
        $this->destroy($tempKey);
        
        pr("\r\n\r\n");
        
        pr('Does it still exist in the map?');
        pr(' > Index: ' . (isset($this->_map[$tempKey]) ? 'Yes' : 'No'));
        pr(' > Meta: ' . (isset($this->_map[$tempKeyMeta . $this->_delimiter . 'expires']) ? 'Yes' : 'No'));
        
        pr("\r\n\r\n");
        
        pr('Seconds elapsed: ' . (time() - $start));
        
        pr("\r\n\r\n");
        
        pr('And now the map looks like...');
        pr($this->map('global'));
    }
}

?>