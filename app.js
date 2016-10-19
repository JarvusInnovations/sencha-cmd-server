#!/usr/bin/env node
"use strict";

var fs = require('fs'),
    path = require('path'),
    http = require('http'),
    os = require('os'),
    exec = require('child_process').exec,
    execFile = require('child_process').execFile,
    spawn = require('child_process').spawn,
    zlib = require('zlib'),

    express = require('express'),
    winston = module.exports.logger = require('winston'),
    async = require('async'),
    unzip = require('unzip'),
    semver = require('semver'),
    uuid = require('node-uuid'),
    jsonParser = require('body-parser').json(),
    gitBackend = require('git-http-backend'),
    tmp = require('tmp'),
    requestModule = require('request'),
    mime = require('mime'),

    Git = require('./lib/git');

var servicePath = '/emergence/services/sencha-cmd',
    distPath = path.join(servicePath, 'dist'),
    buildsRepoPath = path.join(servicePath, 'builds.git'),

    parallelWorkers = 4,

    app = express(),
    port = process.argv[2],
    logRe = /^([a-f0-9]{40}) (.*)$/,
    buildBranchRe = /^builds\/([a-f0-9]{40})$/,
    refsRe = /^([a-f0-9]{40}) ([a-z]+)\t(.*)$/,
    urlRe = /^https?:\/\//,
    longTreeRe = /^(\d+) blob ([a-f0-9]{40}) +(\d+)\t(.*)$/,
    gitEnv = {},
    builds = {},
    buildsRepo,
    cmdPath;

if (!port) {
    console.log('port required');
    process.exit(1);
}


// setup git environment variables
gitEnv.GIT_AUTHOR_NAME = gitEnv.GIT_COMMITTER_NAME = 'Sencha Cmd';
gitEnv.GIT_AUTHOR_EMAIL = gitEnv.GIT_COMMITTER_EMAIL = 'sencha-cmd@'+os.hostname();


// register routes
app.get('/builds', function(request, response) {
    response.send(builds);
});

app.get('/builds/:buildId', function(request, response) {
    var buildId = request.params.buildId;

    if (!builds.hasOwnProperty(buildId)) {
        response.status(404).send('build not found');
        return;
    }

    response.send(builds[request.params.buildId]);
});

app.post('/builds', jsonParser, function(request, response) {
    var buildId = uuid.v4();

    winston.info('queued build', buildId);
    builds[buildId] = { status: 'pending', options: request.body };

    response.send(buildId);
});

// setup git backend queue
var gitBackendQueue = async.queue(function(task, callback) {
    var request = task.request,
        response = task.response,
        requestEncoding = request.headers['content-encoding'],
        requestStream;

    winston.info('Passing request to git backend', request.url);

    if (requestEncoding == 'gzip') {
        requestStream = request.pipe(zlib.createGunzip());
    } else if (requestEncoding == 'deflate') {
        requestStream = request.pipe(zlib.createInflate());
    } else {
        requestStream = request;
    }

    requestStream.pipe(gitBackend(request.url, function(error, service) {
        if (error) {
            response.end(error + '\n');
            return callback(error);
        }

        var branch = service.fields.branch,
            action = service.action;

        winston.verbose('handling', action, service.fields);

        response.setHeader('Content-Type', service.type);

        var ps = spawn(service.cmd, service.args.concat(buildsRepoPath));

        ps.on('exit', function(code) {
            if (action != 'push' || !service.fields.refname) {
                return callback();
            }

            winston.info('finished receiving %s: %s -> %s', service.fields.refname, service.fields.last, service.fields.head);

            if (branch && (branch = buildBranchRe.exec(branch))) {
                buildTree(branch[1], callback);
            } else {
                callback();
            }
        });

        ps.stdout.pipe(service.createStream()).pipe(ps.stdin);
    })).pipe(response);
});

app.all('/.git/*', function(request, response) {
    console.log("Receiving git request", request.url);
    gitBackendQueue.push({ request: request, response: response }, function() {
        winston.info('Finished request', request.url);
    });
});


// setup sencha CMD queue
var cmdQueue = async.queue(function(task, callback) {
    winston.info('executing', cmdPath, task.args.join(' '));

    execFile(
        cmdPath,
        task.args,
        task,
        function(error, stdout, stderr) {
             if (error) {
                return callback(error);
            }

            if (stderr) {
                return callback({stderr: stderr, stdout: stdout});
            }

            callback(null, stdout);
        }
    );
}, parallelWorkers);


// routine for executing hooks
function executeHooks(buildTreeHash, event, payload, callback) {
    winston.info("Executing hook %s for tree %s:", event, buildTreeHash, payload);

    var webhookBody = {
            event: event,
            buildTreeHash: buildTreeHash
        },
        payloadKey;

    for (payloadKey in payload) {
        if (payload.hasOwnProperty(payloadKey)) {
            webhookBody[payloadKey] = payload[payloadKey];
        }
    }

    buildsRepo.exec('for-each-ref', 'refs/hooks/builds/'+buildTreeHash, function(error, output) {
        if (error) {
            return callback(error);
        }

        output = output.split('\n');

        var refs = [],
            ref;

        while ( ( ref = output.shift() ) && ( ref = refsRe.exec(ref) ) ) {
            refs.push({
                hash: ref[1],
                type: ref[2],
                ref: ref[3]
            });
        }

        async.eachSeries(refs, function(ref, callback) {
            buildsRepo.exec('cat-file', 'blob', ref.hash, function(error, output) {
                if (error) {
                    return callback(error);
                }

                var url;

                output = output.split('\n');

                if (output[0] == '#!webhook') {
                    output.shift();

                    async.eachSeries(output, function(url, callback) {
                        if (!urlRe.test(url)) {
                            return callback();
                        }

                        requestModule.post({
                            url: url,
                            headers: {
                                'X-SenchaCmd-Event': event,
                                'X-SenchaCmd-Tree': buildTreeHash
                            },
                            json: webhookBody,
                            callback: function(error, response, body) {
                                winston.info('got %s from %s:', response.statusCode, url, body);
                                callback();
                            }
                        });
                    }, callback);
                } else {
                    throw 'TODO: support executing shell scripts';
                }
            });
        }, callback);
    });
}


// routine for launching build
function buildTree(buildTreeHash, buildDone) {

    var branch = 'builds/'+buildTreeHash,
        hookPayload = {};

    async.auto({
        getCommits: function(callback) {
            buildsRepo.exec('log', { pretty: 'oneline' }, branch, function(error, output) {
                if (error) {
                    return callback(error);
                }

                output = output.split('\n');

                var commits = [],
                    commit;

                while ( ( commit = output.shift() ) && ( commit = logRe.exec(commit) ) ) {
                    commits.push({
                        hash: commit[1],
                        message: commit[2]
                    });
                }

                callback(null, commits);
            });
        },

        getAppName: function(callback) {
            buildsRepo.exec('cat-file', 'blob', buildTreeHash+':app.name', function(error, appName) {
                if (error) {
                    return callback(error);
                }

                hookPayload.appName = appName;

                callback(null, appName);
            });
        },

        getEnvironment: function(callback) {
            var env = Object.create(gitEnv);

            tmp.dir(function(error, tmpWorkTree) {
                if (error) {
                    return callback(error);
                }

                tmp.tmpName(function(error, tmpIndexFile) {
                    if (error) {
                        return callback(error);
                    }

                    env.GIT_INDEX_FILE = tmpIndexFile;
                    env.GIT_WORK_TREE = tmpWorkTree;

                    callback(null, env);
                });
            });
        },

        checkoutBuild: [
            'getCommits',
            'getEnvironment',
            function(results, callback) {
                var lastCommit = results.getCommits[0],
                    env = results.getEnvironment;

                if (!lastCommit.message.match(/^Generate /)) {
                    return callback('Branch is not in Generate state');
                }

                buildsRepo.exec(
                    { $env: env },
                    'checkout',
                    { force: true },
                    branch,
                    function(error, output) {
                        if (error) {
                            return callback(error);
                        }

                        hookPayload.buildTreePath = env.GIT_WORK_TREE;

                        executeHooks(buildTreeHash, 'checkout-build-tree', hookPayload, function() {
                            callback(null, env.GIT_WORK_TREE);
                        });
                    }
                );
            }
        ],

        createExecuteCommit: [
            'checkoutBuild',
            'getEnvironment',
            'getCommits',
            'getAppName',
            function(results, callback) {
                var generateCommitHash = results.getCommits[0].hash,
                    env = results.getEnvironment,
                    executeCommitHash;

                executeCommitHash = buildsRepo.exec(
                    { $env: env },
                    'commit-tree',
                    generateCommitHash+':',
                    {
                        p: generateCommitHash,
                        m: 'Execute Sencha CMD to build app '+results.getAppName
                    },
                    function(error, executeCommitHash) {
                        if (error) {
                            return callback(error);
                        }

                        buildsRepo.exec('update-ref', 'refs/heads/'+branch, executeCommitHash, function(error, output) {
                            if (error) {
                                return callback(error);
                            }

                            hookPayload.executeCommitHash = executeCommitHash;

                            executeHooks(buildTreeHash, 'commit-execute', hookPayload, function() {
                                callback(null, executeCommitHash);
                            });
                        });
                    }
                );
            }
        ],

        getCmdConfig: [
            'checkoutBuild',
            function(results, callback) {
                var workTree = results.checkoutBuild;

                callback(null, {
                    cwd: path.join(workTree, 'app'),
                    args: [
                        'ant',
                        '-Dapp.output.base='+path.join(workTree, 'build'),
                        '-Dbuild.temp.dir='+path.join(workTree, 'temp'),
                        '-Dapp.cache.deltas=false',
                        '-Dapp.output.microloader.enable=false',
                        '-Dbuild.css.selector.limit=0', // TODO: remove this, it breaks IE9 but currently split CSS files don't get linked to with correct paths
                        'production',
                        'build',
                        '.props'
                    ]
                });
            }
        ],

        executeCmd: [
            'createExecuteCommit',
            'getCmdConfig',
            function(results, callback) {
                cmdQueue.push(results.getCmdConfig, callback);
            }
        ],

        writeBuildTree: [
            'getEnvironment',
            'executeCmd',
            function(results, callback) {
                buildsRepo.exec(
                    { $env: results.getEnvironment },
                    'add',
                    'build', 'app',
                    function(error, output) {
                        if (error) {
                            return callback(error);
                        }

                        // write tree
                        buildsRepo.exec({ $env: results.getEnvironment }, 'write-tree', callback);
                    }
                );
            }
        ],

        writeManifest: [
            'checkoutBuild',
            'writeBuildTree',
            function(results, callback) {
                buildsRepo.exec('ls-tree', { r: true, l: true}, results.writeBuildTree+':build', function(error, treeOutput) {
                    if (error) {
                        return callback(error);
                    }

                    var manifestWriter = buildsRepo.exec('hash-object', { w: true, stdin: true, $spawn: true}),
                        manifestHash = '';

                    manifestWriter.stdout.on('data', function(data) {
                        manifestHash += data;
                    });

                    async.eachLimit(treeOutput.split('\n'), parallelWorkers, function(line, callback) {
                        if ( !(line = longTreeRe.exec(line))) {
                            return callback('unable to parse ls-tree output');
                        }

                        // get mime type
                        // exec('file --brief --mime-type "'+path.join(results.checkoutBuild, 'build', line[4])+'"', function(error, stdout, stderr) {
                            manifestWriter.stdin.write(
                                line[4]+'\t'+ // path
                                line[2]+'\t'+ // hash
                                line[3]+'\t'+ // size
                                mime.lookup(line[4])+'\n' // mime type
                                // (stdout ? stdout.trim() : 'application/octet-stream')+'\n' // mime type
                            );
                            callback();
                        // });
                    }, function(error) {
                        if (error) {
                            return callback(error);
                        }

                        manifestWriter.on('close', function(exitCode) {
                            if (exitCode) {
                                return callback('hashing manifest failed with code '+exitCode);
                            }

                            callback(null, manifestHash.trim());
                        });

                        manifestWriter.stdin.end();
                    });
                });
            }
        ],

        addManifestToBuildTree: [
            'getEnvironment',
            'writeManifest',
            function(results, callback) {
                var env = results.getEnvironment;

                buildsRepo.exec({ $env: env }, 'update-index', { add: true, cacheinfo: true }, '100644', results.writeManifest, 'build.manifest', function(error, output) {
                    if (error) {
                        return callback(error);
                    }

                    // write new tree
                    buildsRepo.exec({ $env: env }, 'write-tree', callback);
                });
            }
        ],

        commitBuild: [
            'getEnvironment',
            'getAppName',
            'getCmdConfig',
            'createExecuteCommit',
            'executeCmd',
            'addManifestToBuildTree',
            function(results, callback) {
                var commitMessage =
                    'Build app '+results.getAppName+' for production\n' +
                    '\n' +
                    'Executed command: `sencha '+results.getCmdConfig.args.join(' ')+'`\n' +
                    '\n' +
                    '    '+results.executeCmd.replace(/\n/g, '\n    ');

                buildsRepo.exec(
                    { $env: results.getEnvironment },
                    'commit-tree',
                    results.addManifestToBuildTree,
                    {
                        p: results.createExecuteCommit,
                        m: commitMessage
                    },
                    function(error, outputCommitHash) {
                        if (error) {
                            return callback(error);
                        }

                        buildsRepo.exec('update-ref', 'refs/heads/'+branch, outputCommitHash, function(error, output) {
                            if (error) {
                                return callback(error);
                            }

                            hookPayload.outputCommitHash = outputCommitHash;

                            executeHooks(buildTreeHash, 'commit-output', hookPayload, function() {
                                callback(null, outputCommitHash);
                            });
                        });
                    }
                );
            }
        ]
    }, function(error, results) {
        var env = results.getEnvironment,
            cleanupPaths = [];

        if (env) {
            if (env.GIT_WORK_TREE) {
                cleanupPaths.push(env.GIT_WORK_TREE);
            }

            if (env.GIT_INDEX_FILE) {
                cleanupPaths.push(env.GIT_INDEX_FILE);
            }
        }

        if (cleanupPaths.length) {
            exec('rm -R '+cleanupPaths.join(' '), function(error, stdout, stderr) {
                if (error) {
                    return winston.info('failed to clean up directories:', error);
                }

                winston.info('cleaned up directories:\n\t', cleanupPaths.join('\n\t'));
            });
        }

        if (error) {
            winston.error('build failed', error);
            return buildDone(error);
        }

        winston.info('build', results.commitBuild, 'finished in', branch);
        buildDone();
    });
}


// setup sencha cmd
async.auto({
    findCmd: function(callback) {
        if (fs.existsSync(distPath)) {
            fs.readdir(distPath, function(error, files) {
                var buildVersionRe = /^(\d+\.\d+\.\d+)(\.\d+)$/;

                if (error) {
                    return callback(error);
                }

                // convert sencha build versions to standard semver
                files = files.map(function(version) {
                    return {
                        path: version,
                        version: version.replace(buildVersionRe, '$1-build$2')
                    };
                });

                files = files.filter(function(file) {
                    return semver.valid(file.version);
                });

                if (!files.length) {
                    return callback(null, false);
                }

                // sort newest first
                files.sort(function(a, b) {
                    return semver.rcompare(a.version, b.version);
                });

                callback(null, path.join(distPath, files[0].path));
            });
        } else {
            if (!fs.existsSync(servicePath)) {
                fs.mkdirSync(servicePath, '700');
            }

            fs.mkdirSync(distPath, '700');
            callback(null, false);
        }
    },

    installCmd: [
        'findCmd',
        function(results, callback) {
            var existingCmd = results.findCmd,
                cmdVersion = '6.2.0',
                downloadUrl = 'http://cdn.sencha.com/cmd/' + cmdVersion + '/no-jre/SenchaCmd-' + cmdVersion + '-linux-amd64.sh.zip',
                tmpPath = path.join('/tmp', path.basename(downloadUrl, '.zip'));

            // return found cmd if it is adaquate
            if (existingCmd) {
                return callback(null, existingCmd);
            }

            // download and install cmd
            async.auto({
                downloadInstaller: function(callback) {
                    winston.info('downloading', downloadUrl);

                    http.get(downloadUrl, function(response) {
                        var unzipStream = unzip.Parse(),
                            tmpFileStream = fs.createWriteStream(tmpPath);

                        winston.info('receiving response');

                        response.pipe(unzipStream);

                        unzipStream.on('entry', function(entry) {
                            if (entry.path.match(/\.sh$/)) {
                                winston.info('extracting', entry.path);
                                entry.pipe(tmpFileStream);
                            } else {
                                entry.autodrain();
                            }
                        });

                        tmpFileStream.on('finish', function() {
                            winston.info('finishing writing', tmpPath);

                            tmpFileStream.close(function(error) {
                                if (error) {
                                    return callback(error);
                                }

                                fs.chmod(tmpPath, '700', function(error) {
                                    if (error) {
                                        return callback(error);
                                    }

                                    callback(null, tmpPath);
                                });
                            });
                        });
                    }).on('error', function(error) {
                        fs.unlink(tmpPath);
                        callback(error);
                    });
                },

                executeInstaller: [
                    'downloadInstaller',
                    function(results, callback) {
                        var installerPath = results.downloadInstaller,
                            installPath = path.join(distPath, cmdVersion),
                            installerCommand = [
                                installerPath,
                                '-q', // quiet
                                '-a', // all components
                                '-dir', installPath
                            ].join(' ');

                        winston.info('executing', installerCommand);

                        exec(installerCommand, function(error, stdout, stderr) {
                            if (error) {
                                return callback('failed to install cmd');
                            }

                            winston.info('finished installation');
                            callback(null, installPath);
                        });
                    }
                ]
            }, function(error, results) {
                if (error) {
                    return callback(error);
                }

                callback(null, results.executeInstaller);
            });
        }
    ],

    initBuildsRepository: function(callback) {
        if (fs.existsSync(buildsRepoPath)) {
            return callback(null, true);
        }

        exec('git init --bare "'+buildsRepoPath+'"', function(error, stdout, stderr) {
            if (error) {
                return callback(error);
            }

            winston.info('initialized builds repository at', buildsRepoPath);

            callback(null, true);
        });
    },

    openBuildsRepository: [
        'initBuildsRepository',
        function(results, callback) {
            buildsRepo = new Git(buildsRepoPath);
            callback();
        }
    ],

    startServer: [
        'installCmd',
        'openBuildsRepository',
        function(results, callback) {
            cmdPath = results.installCmd + '/sencha';

            winston.info('starting service for', cmdPath);

            app.listen(port, function() {
                callback(null, port);
            });
        }
    ]
}, function(error, result) {
    if (error) {
        winston.error('launch failed', error);
        return;
    }

    winston.info('listening on', result.startServer);
    winston.info(result);
});

