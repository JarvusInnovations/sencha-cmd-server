# emergence-sencha

## TODO

### Next

- [X] have sencha-build.php upload build payload
  - [X] assemble build.json+workspace in tmp directory
  - [X] archive and hash the build payload
  - [X] POST and verify build payload to app.js
- [X] setup build workspace
  - [X] extract payload
  - [X] inject framework
  - [X] execute cmd build
- [X] parse build status
  - [X] post build back to projecthub webhook URL
  - [X] update github status with link to build
- [X] implement build server
- [ ] resolve problem with split CSS files getting imported with bad path, re-enable CSS splitting for IE9 support


### Later

- [X] Move sencha-cmd-server to its own repo
- [ ] auto-generate and possibly auto-install read-only deploy key?
- [ ] execute siesta tests
- [ ] build docs
