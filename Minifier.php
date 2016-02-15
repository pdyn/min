<?php
namespace pdyn\min;

use \pdyn\base\Exception;

/**
 * Combine, minify, and serve CSS and JavaScript files.
 */
class Minifier {
	/** @var \pdyn\cache\FileCache The caching object to use */
	protected $cache;

	/**
	 * Constructor.
	 *
	 * @param \pdyn\cache\FileCache $filecache A file cache object to cache minified files.
	 */
	public function __construct(\pdyn\cache\FileCache $filecache, $servegzip = true) {
		$this->cache = $filecache;
		$this->servegzip = $servegzip;
	}

	/**
	 * Serve a set of CSS files.
	 *
	 * @param array $files An array of absolute filenames to combine, minify, and serve.
	 */
	public function serveCSS(array $files) {
		$this->serve_minified($files, 'css');
	}

	/**
	 * Serve a set of javascript files.
	 *
	 * @param array $files An array of absolute filenames to combine, minify, and serve.
	 */
	public function serveJS(array $files) {
		$this->serve_minified($files, 'js');
	}

	/**
	 * Generate a cache key that uniquely identifies the files.
	 *
	 * @param array $files Array of filenames contained in the cache.
	 * @param string $type The type of files.
	 * @return string A unique cache key.
	 */
	protected function generatecachekey($files, $type) {
		return implode(',', $files).'.'.$type;
	}

	/**
	 * Serve a minified, cached, version of a set of files.
	 *
	 * @param array $files An array of absolute filenames to combine and serve.
	 * @param string $type The type of resource. "css" or "js"
	 */
	protected function serve_minified(array $files, $type) {
		$cachekey = $this->generatecachekey($files, $type);
		$cache_filename = $this->cache->get_filename($type, $cachekey);

		switch($type) {
			case 'js':
				$mime = 'application/javascript';
				break;
			case 'css':
				$mime = 'text/css';
				break;
		}
		$regenerate = $this->cache_regeneration_needed($cache_filename, $files);
		if ($regenerate === true) {
			$output = $this->generate_cache($files, $type);
		}

		// Send a 304 if client has valid cache.
		$this->caching_headers($cache_filename);

		if ($regenerate === false) {
			$output = $this->cache->get($type, $cachekey);
		}

		if ($type === 'css') {
			$output = '@charset="utf-8";'.$output;
		}

		if ($this->servegzip === true) {
			$output = gzencode($output, 9);
		}

		header('Content-Length: '.strlen($output));
		header('Content-Type: '.$mime);
		if ($this->servegzip === true) {

			header('Content-Encoding: gzip');
		}
		header('Vary: Accept-Encoding');
		echo $output;
		die();
	}

	/**
	 * Remove the byte-order-mark from a string.
	 *
	 * @param string $str The string to check for/remove byte order marks.
	 * @return The processed string.
	 */
	protected function removeBOM($str) {
		return (mb_substr($str, 0, 3) === pack('CCC', 0xef, 0xbb, 0xbf)) ? mb_substr($str, 3) : $str;
	}

	/**
	 * Send headers to assist with caching.
	 *
	 * thanks to Rich Bradshaw (http://stackoverflow.com/users/16511/rich-bradshaw)
	 * from http://stackoverflow.com/questions/2000715/answering-http-if-modified-since-and-http-if-none-match-in-php
	 *
	 * @param string $file The file we want cached.
	 * @param int $timestamp Last modification time of the file.
	 */
	protected function caching_headers($file, $timestamp = null) {
		\pdyn\httputils\Utils::caching_headers($file, $timestamp, false);
	}

	/**
	 * Determine if we need to regenerate the cache.
	 *
	 * Compares the file modification time of all of the specified files with the file modification time of the cache file.
	 * If the cache file is older than any of the specified files, we need to regenerate the cache.
	 *
	 * @param string $cache_filename The full filename of the cache.
	 * @param array $files An array of filenames of files to store.
	 * @return bool Whether we need to regenerate the cache or not.
	 */
	protected function cache_regeneration_needed($cache_filename, $files) {
		$regenerate = false;
		if (file_exists($cache_filename)) {
			$cache_mtime = filemtime($cache_filename);
			foreach ($files as $file) {
				$mtime = (int)filemtime($file);
				if ($mtime > $cache_mtime && $mtime <= time()) {
					$regenerate = true;
					break;
				}
			}
		} else {
			$regenerate = true;
		}
		return $regenerate;
	}

	/**
	 * Get/Create the file cache.
	 *
	 * @param array $files An array of files to serve.
	 * @param string $type The type of files we're serving. "css" or "js".
	 * @return string The minified/cached content.
	 */
	protected function generate_cache($files, $type) {
		$cachekey = $this->generatecachekey($files, $type);

		$minified = '';
		switch ($type) {
			case 'js':
				foreach ($files as $file) {
					$js = file_get_contents($file);
					$js = $this->removeBOM($js);
					$this->minifyJS($js, $file);
					$minified .= "\n\n/*".$file."*/\n".$js;
				}
				break;

			case 'css':
				foreach ($files as $file) {
					$minified .= file_get_contents($file);
				}
				$this->minifyCSS($minified);
				break;
		}

		$this->cache->store($type, $cachekey, $minified);
		return $minified;
	}

	/**
	 * Minify CSS.
	 *
	 * @param string &$css The CSS to minify.
	 */
	protected function minifyCSS(&$css) {
		// Remove unnecessary whitespace.
		//$css = preg_replace('#\s+#', ' ', $css);
		//$css = preg_replace('#\s*{\s*#', '{', $css);
		//$css = preg_replace('#;?\s*}\s*#', '}', $css);
		//$css = preg_replace('#\s*;\s*#', ';', $css);

		// Minimize hex colors.
		$css = preg_replace('/([^=])#([a-f\\d])\\2([a-f\\d])\\3([a-f\\d])\\4([\\s;\\}])/i', '$1#$2$3$4$5', $css);

		// Convert 0px to 0.
		$css = preg_replace('#([^0-9])0px#', '${1}0', $css);

		// Remove comments.
		$css = preg_replace('/\/\*.*?\*\//', '', $css);

		$css = trim($css);
	}

	/**
	 * Minify javascript.
	 *
	 * @param string $js The javascript to minify.
	 * @param string $file The file to minify.
	 */
	protected function minifyJS(&$js, $file) {
		$js = \JShrink\Minifier::minify($js);
	}
}
