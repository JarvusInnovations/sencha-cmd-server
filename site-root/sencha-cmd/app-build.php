<?php

$GLOBALS['Session']->requireAccountLevel('Developer');
set_time_limit(0);
Site::$debug = !empty($_REQUEST['debug']);

$defaultExclude = array(
    "#/\\.sass-cache(/|$)#"
    ,"#/\\.sencha-backup(/|$)#"
    ,"#/\\.emergence(/|$)#"
);

if(empty($_GET['dumpWorkspace'])) {
    Benchmark::startLive();
}


// get app name
if(empty($_REQUEST['name'])) {
    die('Parameter name required');
}

$appName = $_REQUEST['name'];
$App = new Sencha_App($appName);


// get build type
if(empty($_REQUEST['buildType'])) {
    $buildType = 'production';
}
else {    
    $buildType = $_REQUEST['buildType'];
}

Benchmark::mark("configured request: appName=$appName");


// load framework
$framework = Jarvus\Sencha\Framework::get($App->getFramework(), $App->getFrameworkVersion());

if (!$framework) {
    throw new \Exception('Failed to load framework');
}

Benchmark::mark("loaded framework $framework");


// load CMD
$cmd = Jarvus\Sencha\Cmd::get($App->getBuildCfg('app.cmd.version'));

if (!$cmd) {
    throw new \Exception('Failed to load CMD');
}

Benchmark::mark("loaded cmd $cmd");


// get app-level classpath
$classPaths = array_unique(array_filter(array_merge(
    explode(',', $App->getBuildCfg('app.classpath')),
    explode(',', Sencha::getWorkspaceCfg('workspace.classpath'))
)));


// set paths
$workspacePath = 'sencha-workspace';
$workspaceConfigPath = "$workspacePath/.sencha";
$packagesPath = "$workspacePath/packages";
$appPath = "$workspacePath/$appName";
$archivePath = "sencha-build/$appName/archive";


// get temporary directory and set paths
$tmpPath = Emergence_FS::getTmpDir();
$workspaceConfigTmpPath = "$tmpPath/.sencha";
$packagesTmpPath = "$tmpPath/packages";
$appTmpPath = "$tmpPath/$appName";
$archiveTmpPath = "$appTmpPath/archive";
$buildTmpPath = "$tmpPath/build/$appName/$buildType";

Benchmark::mark("created tmp: $tmpPath");


// change into tmp build directory
chdir($tmpPath);
Benchmark::mark("chdir to: $tmpPath");


// write framework to disk
$frameworkWriteResult = $framework->writeToDisk("./$framework");
Benchmark::mark("wrote framework to ./$framework: ".http_build_query($frameworkWriteResult));


// import framework to VFS
// TODO: remove this when crawlRequiredPackages can accept an on-disk framework as a package source
if ($frameworkWriteResult['source'] == 'archive') {
	$importResults = Emergence_FS::importTree("./$framework", $framework->getWorkspacePath());
	Benchmark::mark("imported framework $framework to ". $framework->getWorkspacePath() . ': ' . http_build_query($importResults));
}


// precache and write workspace config
$cachedFiles = Emergence_FS::cacheTree($workspaceConfigPath);
Benchmark::mark("precached $cachedFiles files in $workspaceConfigPath");
$exportResult = Emergence_FS::exportTree($workspaceConfigPath, $workspaceConfigTmpPath);
Benchmark::mark("exported $workspaceConfigPath to $workspaceConfigTmpPath: ".http_build_query($exportResult));

// ... packages
if (!($requiredPackages = $App->getRequiredPackages()) || !is_array($requiredPackages)) {
    $requiredPackages = array();
}

Benchmark::mark("aggregating classpaths from packages");
$classPaths = array_merge($classPaths, Sencha::aggregateClassPathsForPackages($requiredPackages));

foreach ($requiredPackages AS $packageName) {
    $cachedFiles = Emergence_FS::cacheTree("$packagesPath/$packageName");
    Benchmark::mark("precached $cachedFiles files in $packagesPath/$packageName");
    $exportResult = Emergence_FS::exportTree("$packagesPath/$packageName", "$packagesTmpPath/$packageName");
    Benchmark::mark("exported $packagesPath/$packageName to $packagesTmpPath/$packageName: ".http_build_query($exportResult));

    // append package-level classpaths
    $packageBuildConfigPath = "$packagesTmpPath/$packageName/.sencha/package/sencha.cfg";
    if (file_exists($packageBuildConfigPath)) {
        $packageBuildConfig = Sencha::loadProperties($packageBuildConfigPath);
        $classPaths = array_merge($classPaths, explode(',', $packageBuildConfig['package.classpath']));
    }
}

// ... app
$cachedFiles = Emergence_FS::cacheTree($appPath);
Benchmark::mark("precached $cachedFiles files in $appPath");
$exportResult = Emergence_FS::exportTree($appPath, $appTmpPath);
Benchmark::mark("exported $appPath to $appTmpPath: ".http_build_query($exportResult));


// write any libraries from classpath
foreach (array_unique($classPaths) AS $classPath) {
	if (strpos($classPath, '${workspace.dir}/x/') === 0) {
		$extensionPath = substr($classPath, 19);
		$classPathSource = "ext-library/$extensionPath";
		$classPathDest = "$tmpPath/x/$extensionPath";
		Benchmark::mark("importing classPathSource: $classPathSource"); 
		
		$cachedFiles = Emergence_FS::cacheTree($classPathSource);
		Benchmark::mark("precached $cachedFiles files in $classPathSource");
		
        $sourceNode = Site::resolvePath($classPathSource);
        
        if (is_a($sourceNode, SiteFile)) {
            mkdir(dirname($classPathDest), 0777, true);
            copy($sourceNode->RealPath, $classPathDest);
    		Benchmark::mark("copied file $classPathSource to $classPathDest");
        } else {
        	$exportResult = Emergence_FS::exportTree($classPathSource, $classPathDest);
    		Benchmark::mark("exported $classPathSource to $classPathDest: ".http_build_query($exportResult));
        }
	}
}


// load hotfix package
$hotfixBranch = Chaki\Package::writeToDisk('packages/jarvus-hotfixes', 'jarvus-hotfixes', $framework);

if ($hotfixBranch) {
    Benchmark::mark("checked out branch $hotfixBranch to packages/jarvus-hotfixes");

	// inject into app.json requires
	if (!in_array('jarvus-hotfixes', $requiredPackages)) {
		$jsonPath = "$appTmpPath/app.json";
		$appCfg = json_decode(Sencha::cleanJson(file_get_contents($jsonPath))); // use json_decode with $assoc=true to preserve empty array/object differences
		$appCfg->requires[] = 'jarvus-hotfixes';
		file_put_contents($jsonPath, json_encode($appCfg, JSON_PRETTY_PRINT));
		Benchmark::mark("Injected jarvus-hotfixes into app.requires in $jsonPath");
	}
}


// write archive
if(!empty($_GET['archive'])) {
	try {
		$exportResult = Emergence_FS::exportTree($archivePath, $archiveTmpPath);
		Benchmark::mark("exported $archivePath to $archiveTmpPath: ".http_build_query($exportResult));
	}
	catch(Exception $e) {
		Benchmark::mark("failed to export $archivePath, continueing");
	}
}


// change into app's directory
chdir($appTmpPath);
Benchmark::mark("chdir to: $appTmpPath");


// prepare cmd
$shellCommand = $cmd->buildShellCommand(
    'ant'
        // preset build directory parameters
        ,"-Dbuild.dir=$buildTmpPath"
        ,"-Dapp.output.base=$buildTmpPath" // CMD 5.0.1 needs this set directly too or it gets loaded from app.defaults.json
        ,"-D" . $framework->getName() . ".dir=./$framework"
        
        // ant targets
        ,$buildType // buildType target (e.g. "production", "testing") sets up build parameters
        ,'build'
);
Benchmark::mark("running CMD: $shellCommand");

// optionally dump workspace and exit
if(!empty($_GET['dumpWorkspace']) && $_GET['dumpWorkspace'] != 'afterBuild') {
	header('Content-Type: application/x-bzip-compressed-tar');
	header('Content-Disposition: attachment; filename="'.$appName.'.'.date('Y-m-d').'.tbz"');
	chdir($tmpPath);
	passthru("tar -cjf - ./");
	exec("rm -R $tmpPath");
	exit();
}

// execute CMD
//  - optionally dump workspace and exit
if(!empty($_GET['dumpWorkspace']) && $_GET['dumpWorkspace'] == 'afterBuild') {
	exec($shellCommand);
	
	header('Content-Type: application/x-bzip-compressed-tar');
	header('Content-Disposition: attachment; filename="'.$appName.'.'.date('Y-m-d').'.tbz"');
	chdir($tmpPath);
	passthru("tar -cjf - ./");
	exec("rm -R $tmpPath");
	exit();
}
else {
	passthru("$shellCommand 2>&1", $cmdStatus);
}

Benchmark::mark("CMD finished: exitCode=$cmdStatus");

// import build
if($cmdStatus == 0) {	
	Benchmark::mark("importing $buildTmpPath");
	
	$importResults = Emergence_FS::importTree($buildTmpPath, "sencha-build/$appName/$buildType", array(
		'exclude' => $defaultExclude
	));
	Benchmark::mark("imported files: ".http_build_query($importResults));
	
	if ($framework == 'ext') {
		Emergence_FS::importFile("$appTmpPath/bootstrap.js", "$appPath/bootstrap.js");
		Benchmark::mark("imported bootstrap.js");
	}
	
	if(!empty($_GET['archive'])) {
		Benchmark::mark("importing $archiveTmpPath to $archivePath");
		
		$importResults = Emergence_FS::importTree($archiveTmpPath, $archivePath);
		Benchmark::mark("imported files: ".http_build_query($importResults));
	}
}


// clean up
if(empty($_GET['leaveWorkspace'])) {
	exec("rm -R $tmpPath");
	Benchmark::mark("erased $tmpPath");
}
