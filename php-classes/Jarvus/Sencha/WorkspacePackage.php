<?php

namespace Jarvus\Sencha;

use Site;
use Cache;
use Emergence_FS;

class WorkspacePackage extends Package
{
    protected $path;


    // factories
	public static function load($name, Framework $framework)
	{
        // try to load package from VFS
        $workspacePackages = static::getWorkspacePackages();

        if (empty($workspacePackages[$name])) {
            return null;
        }

        $workspacePackageVersions = $workspacePackages[$name];

        // find the best version
        $matchedVersion = null;
        $versionStack = explode('.', $framework->getVersion());

        while (
            count($versionStack) &&
            ($matchedVersion = $framework->getName() . '-' . implode('.', $versionStack)) &&
            empty($workspacePackageVersions[$matchedVersion])
        ) {
            array_pop($versionStack);
            $matchedVersion = null;
        }

        if (!$matchedVersion && !empty($workspacePackageVersions[$framework->getName()])) {
            $matchedVersion = $framework->getName();
        }

        if (!$matchedVersion && !empty($workspacePackageVersions['*'])) {
            $matchedVersion = '*';
        }

        if (!$matchedVersion) {
            return null;
        }

        $packageData = $workspacePackageVersions[$matchedVersion];

        return new static($packageData['name'], $packageData['config'], $packageData['path']);
	}


    // magic methods and property getters
    public function __construct($name, $config, $path)
	{
		parent::__construct($name, $config);
        $this->path = $path;
	}


    // member methods
    public function getFileContents($path)
    {
        $fileNode = Site::resolvePath("$this->path/$path");

        if (!$fileNode) {
            return null;
        }

        return file_get_contents($fileNode->RealPath);
    }

    public function getFilePointer($path)
    {
        $fileNode = Site::resolvePath("$this->path/$path");

        if (!$fileNode) {
            return null;
        }

        return $fileNode->get();
    }

    public function writeToDisk($path)
    {
        Emergence_FS::cacheTree($this->path);
        Emergence_FS::exportTree($this->path, $path);

        return true;
    }


    // static utility methods
    public static function getWorkspacePackages()
    {
        $cacheKey = 'sencha-workspace/packages';

        if (false === ($packages = Cache::fetch($cacheKey))) {
            $packages = [];

            $packageNodes = Emergence_FS::getAggregateChildren('sencha-workspace/packages');

            foreach ($packageNodes AS $packageDir => $packageNode) {
                $packagePath = "sencha-workspace/packages/$packageDir";
                $packageJsonNode = Site::resolvePath("$packagePath/package.json");

                if (!$packageJsonNode) {
                    throw new \Exception("Could not find package.json for $packagePath");
                }

                $packageConfig = static::loadPackageConfig($packageJsonNode);  
                $packageName = $packageConfig['name'];

                if (!preg_match('/^([\\w-]+)(@((\\w+)(-(\\d+(\\.\\d+){0,3}))?))?$/m', $packageDir, $matches)) {
                    throw new \Exception("Unparsable package directory name for $packagePath");
                }

                if ($packageName != $matches[1]) {
                    throw new \Exception("Name from package.json does not match package directory name for $packagePath");
                }

                $targetFramework = $matches[3] ?: '*';

                // write to packages map
                if (!array_key_exists($packageName, $packages)) {
                    $packages[$packageName] = [];
                }

                $packages[$packageName][$targetFramework] = [
                    'path' => $packagePath,
                    'name' => $packageName,
                    'config' => $packageConfig
                ];
            }

            Cache::store($cacheKey, $packages);
        }

        return $packages;
    }
}