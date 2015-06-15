<?php

namespace Jarvus\Sencha;

use Site;
use Emergence_FS;

class Framework
{
	public static $versions = [
        'ext' => [
			// default config for all framework versions
			'*'			=> [
				'class' => Framework\Ext::class
			],

			// version mappings
			'4'		    => '4.2',
            '4.2'       => '4.2.3',
			'4.2.1'     => '4.2.1.883',
			'4.2.2'     => '4.2.2.1144',
			'4.2.3'     => '4.2.3.1477',
			'5'		    => '5.1',
            '5.0'       => '5.0.1',
            '5.0.0'     => '5.0.0.970',
            '5.0.1'     => '5.0.1.1255',
            '5.1'       => '5.1.1',
            '5.1.0'     => '5.1.0.107',
            '5.1.1'     => '5.1.1.451'

			// version-specific config
//			'5.1.1.451' => [
//				'class' => Framework\Ext511451::class
//			]
		],
		'touch' => [
			// default config for all framework versions
			'*'			=> [
				'class' => Framework\Touch::class
			],

			// version mappings
			'2'			=> '2.4',
			'2.2'		=> '2.2.1',
			'2.2.1'     => '2.2.1.2',
			'2.3'		=> '2.3.1',
    		'2.3.1' 	=> '2.3.1.410',
			'2.4'		=> '2.4.1',
        	'2.4.0' 	=> '2.4.0.487',
        	'2.4.1' 	=> '2.4.1.527'
		]
	];

	public static $downloadDirectory = '/tmp/sencha-frameworks';
	
	public static $extractPaths = [
		'*' => [
			'exclude' => ['build/*', 'welcome/*', 'examples/*', 'test/*']
		],
		'examples/ux/*',
		'build/*' => [
			'exclude' => ['build/examples/*', 'build/packages/*', 'build/welcome/*']
		]
	];


	protected $name;
	protected $version;
	protected $config;


	// factories
	public static function get($name, $version, $config = [])
	{
		$version = static::getCanonicalVersion($name, $version);
		$config = array_merge(static::getDefaultConfig($name, $version), $config);

		if (empty($config['class'])) {
			$config['class'] = get_called_class();
		}

		return new $config['class']($name, $version, $config);
	}


	// magic methods and property getters
	public function __construct($name, $version, $config = [])
	{
		$this->name = $name;
		$this->version = $version;

		$this->config = $this->parseConfig($config);
	}

	public function __toString()
	{
		return $this->name . '-' . $this->version;
	}

	public function getName()
	{
		return $this->name;
	}

	public function getVersion()
	{
		return $this->version;
	}

	public function getConfig($key = null)
	{
		return $key ? $this->config[$key] : $this->config;
	}


	// public instance methods
	/**
	 * Normalizes a user-supplied config array by applying conversions and defaults.
	 */
	public function parseConfig(array $config)
	{
		return $config;
	}

	public function getDownloadUrl()
	{
		return $this->getConfig('downloadUrl');
	}

	public function getWorkspacePath()
	{
		return "sencha-workspace/$this";
	}

	public function writeToDisk($path)
	{
		// export from VFS workspace
		$workspaceFrameworkPath = $this->getWorkspacePath();

		if ($workspaceFrameworkPath && Site::resolvePath($workspaceFrameworkPath)) {
		    Emergence_FS::cacheTree($workspaceFrameworkPath);
		    Emergence_FS::exportTree($workspaceFrameworkPath, $path);
			return [
				'source' => 'workspace'
			];
		}


		// export from downloaded archive
		$downloadUrl = $this->getDownloadUrl();

		if (static::$downloadDirectory && $downloadUrl) {
			$archiveFilename = basename($downloadUrl);

		    if (!is_dir(static::$downloadDirectory)) {
		        mkdir(static::$downloadDirectory, 0777, true);
		    }

			$archivePath = static::$downloadDirectory . '/' . $archiveFilename;

			if (!file_exists($archivePath)) {
				exec("wget $downloadUrl -O $archivePath", $downloadOutput, $downloadStatus);
				
				if ($downloadStatus != 0 || !file_exists($archivePath)) {
					unlink($archivePath);
					throw new \Exception("Failed to download framework from $downloadUrl, wget status=$downloadStatus");
				}
			}

			// determine archive's root directory
			$archiveRootDirectory = trim(exec('unzip -l '.escapeshellarg($archivePath).' -x \'*/**\' | grep \'/\' | awk \'{print $4}\''), " \t\n\r/");

			// extract minimum files
			$extractPaths = $this->getExtractPaths();
			
			foreach ($extractPaths AS $extractPath => $extractConfig) {
				if (is_string($extractConfig)) {
					$extractPath = $extractConfig;
				}

				if (!is_array($extractConfig)) {
					$extractConfig = [];
				}

				$extractPath = $archiveRootDirectory . '/' . $extractPath;

				$unzipCommand = 'unzip '.escapeshellarg($archivePath) . ' ' . escapeshellarg($extractPath);
				
				if (!empty($extractConfig['exclude'])) {
					$unzipCommand .= ' ' . 
						implode(
							' ',
							array_map(
								function($excludePath) use($archiveRootDirectory) {
									return '-x ' . escapeshellarg($archiveRootDirectory . '/' . $excludePath);
								},
								is_string($extractConfig['exclude']) ? [$extractConfig['exclude']] : $extractConfig['exclude']
							)
						);
				}

				$unzipCommand .= ' -d ' . escapeshellarg($path . '.tmp');

				exec($unzipCommand);
			}

			rename($path . '.tmp/' . $archiveRootDirectory, $path);
			rmdir($path . '.tmp');

			return [
				'source' => 'archive',
				'downloaded' => isset($downloadStatus)
			];
		}

		throw new \Exception('Framework not available to write to disk');
	}

	/**
	 * Returns a list of paths within the framework distribution that
	 * must be extracted for builds
	 */
	public function getExtractPaths()
	{
		return static::$extractPaths;
	}


	// static utility methods
	public static function getCanonicalVersion($name, $version)
	{
    	$versions = static::$versions[$name];

		while ($versions && is_string($versions[$version])) {
			$version = $versions[$version];
		}
		
		return $version;
	}

	public static function getDefaultConfig($name, $version)
	{
		$config = [];

		if (isset(static::$versions[$name])) {
			if (isset(static::$versions[$name]['*'])) {
				$config = array_merge($config, static::$versions[$name]['*']);
			}

			if (isset(static::$versions[$name][$version])) {
				$config = array_merge($config, static::$versions[$name][$version]);
			}
		}

    	return $config;
	}
}