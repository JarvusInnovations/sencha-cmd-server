<?php

namespace Jarvus\Sencha;

use Site;
use Cache;
use Emergence_FS;

class FrameworkPackage extends Package
{
    protected $framework;
    protected $source;
    protected $path;


    // factories
    public static function load($name, Framework $framework)
	{
        $frameworkPackages = static::getFrameworkPackages($framework);

        if (empty($frameworkPackages[$name])) {
            return null;
        }

        $packageData = $frameworkPackages[$name];

        return new static($packageData['name'], $packageData['config'], $framework, $packageData['source'], $packageData['path']);
	}


    // magic methods and property getters
    public function __construct($name, $config, Framework $framework, $source, $path)
    {
		parent::__construct($name, $config);
        $this->framework = $framework;
        $this->source = $source;
        $this->path = $path;
	}


    // member methods
    public function getFileContents($path)
    {
        if ($this->source == 'disk') {
            return @file_get_contents($this->path . '/' . $path) ?: null;
        } elseif ($this->source == 'vfs') {
            $node = Site::resolvePath($this->path . '/' . $path);

            if (!$node) {
                return null;
            }

            return @file_get_contents($node->RealPath) ?: null;
        }

        throw new \Exception("Cannot read file for source type $this->source");
    }

    public function getFilePointer($path)
    {
        if ($this->source == 'disk') {
            return @fopen($this->path . '/' . $path, 'r') ?: null;
        } elseif ($this->source == 'vfs') {
            $node = Site::resolvePath($this->path . '/' . $path);

            if (!$node) {
                return null;
            }

            return $node->get();
        }

        throw new \Exception("Cannot read file for source type $this->source");
    }

    public function writeToDisk($path)
    {
        if ($this->source == 'disk') {
            exec('cp -R ' . escapeshellarg($this->path) . ' ' . escapeshellarg($path));
            return true;
        } elseif ($this->source == 'vfs') {
            Emergence_FS::cacheTree($this->path);
            Emergence_FS::exportTree($this->path, $path);
            return true;
        }

        throw new \Exception("Cannot write to disk from source type $this->source");
    }


    // static utility methods
    public static function getFrameworkPackages(Framework $framework)
    {
        $cacheKey = "sencha-frameworks/$framework/packages";

        if (!$packages = Cache::fetch($cacheKey)) {
            // sniff for quickest source for package data
            if ($frameworkVirtualPath = $framework->getVirtualPath(false)) {
                $packages = static::loadPackagesFromVFS("$frameworkVirtualPath/packages");
            } elseif ($frameworkPhysicalPath = $framework->getPhysicalPath(false)) {
                $packages = static::loadPackagesFromDisk("$frameworkPhysicalPath/packages");
            } elseif ($frameworkPhysicalPath = $framework->getPhysicalPath(true)) {
                $packages = static::loadPackagesFromDisk("$frameworkPhysicalPath/packages");
            } elseif ($frameworkVirtualPath = $framework->getVirtualPath(true)) {
                $packages = static::loadPackagesFromVFS("$frameworkVirtualPath/packages");
            }

            Cache::store($cacheKey, $packages);
        }

        return $packages;
    }

    protected static function loadPackagesFromVFS($packagesPath)
    {
        $packages = [];
        $packageNodes = Emergence_FS::getAggregateChildren($packagesPath);

        foreach ($packageNodes AS $packageDir => $packageNode) {
            $packagePath = "$packagesPath/$packageDir";
            $packageJsonNode = Site::resolvePath("$packagePath/package.json");

            if (!$packageJsonNode) {
                throw new \Exception("Could not find package.json for $packagePath");
            }

            $packageConfig = static::loadPackageConfig($packageJsonNode);
 
            if ($packageDir != $packageConfig['name']) {
                throw new \Exception("Name from package.json does not match package directory name for $packagePath");
            }

            $packages[$packageDir] = [
                'source' => 'vfs',
                'path' => $packagePath,
                'name' => $packageDir,
                'config' => $packageConfig
            ];
        }

        return $packages;
    }

    protected static function loadPackagesFromDisk($packagesPath)
    {
        $packages = [];

        foreach(glob("$packagesPath/*") AS $packagePath) {
            $packageDir = basename($packagePath);
            $packageJsonPath = "$packagePath/package.json";

            if (!file_exists($packageJsonPath)) {
                throw new \Exception("Could not find package.json for $packagePath");
            }

            $packageConfig = static::loadPackageConfig($packageJsonPath);
 
            if ($packageDir != $packageConfig['name']) {
                throw new \Exception("Name from package.json does not match package directory name for $packagePath");
            }

            $packages[$packageDir] = [
                'source' => 'disk',
                'path' => $packagePath,
                'name' => $packageDir,
                'config' => $packageConfig
            ];
        }

        return $packages;
    }
}