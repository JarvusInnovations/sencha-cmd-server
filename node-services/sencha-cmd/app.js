#!/usr/bin/env node

var fs = require('fs'),
    path = require('path'),
    http = require('http'),
    exec = require('child_process').exec,

    express = require('express'),
    winston = require('winston'),
    async = require('async'),
    unzip = require('unzip'),
    semver = require('semver'),
    uuid = require('node-uuid'),
    jsonParser = require('body-parser').json();

var servicePath = '/emergence/services/sencha-cmd',
    distPath = path.join(servicePath, 'dist'),

    app = express(),
    port = process.argv[2],
    builds = {};

if (!port) {
    console.log('port required');
    process.exit(1);
}


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


// setup sencha cmd
async.auto({
    findCmd: function(callback, results) {
        if (fs.existsSync(distPath)) {
            fs.readdir(distPath, function(error, files) {
                if (error) {
                    return callback(error);
                }

                files = files.filter(semver.valid);

                if (!files.length) {
                    return callback(null, false);
                }

                // sort newest first
                files.sort(semver.rcompare);

                callback(null, path.join(distPath, files[0]));
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
                cmdVersion = '6.1.2',
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

    startServer: [
        'installCmd',
        function(results, callback) {
            var cmdPath = results.installCmd;

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

