# emergence-sencha

## TODO

### Next
- [ ] have sencha-build.php upload build payload
  - [ ] assemble build.json+workspace in tmp directory
  - [ ] archive and hash the build payload
  - [ ] POST and verify build payload to app.js
- [ ] setup build workspace
  - [ ] extract payload
  - [ ] inject framework
  - [ ] execute cmd build
- [ ] parse build status
  - [ ] post build back to projecthub webhook URL
  - [ ] update github status with link to build
- [ ] implement build server


### Later
- [ ] Move sencha-cmd-server to its own repo
- [ ] auto-generate and possibly auto-install read-only deploy key?
- [ ] execute siesta tests
- [ ] build docs