#!/bin/bash
sudo mkdir /emergence/services/sencha

sudo git clone --bare https://github.com/JarvusInnovations/extjs.git /emergence/services/sencha/frameworks.git
sudo chown root:www-data -R /emergence/services/sencha/frameworks.git
sudo chmod ug=Xrw,o=Xr -R /emergence/services/sencha/frameworks.git

sudo git init --bare /emergence/services/sencha/builds.git
echo "/emergence/services/sencha/frameworks.git/objects" | sudo tee /emergence/services/sencha/builds.git/objects/info/alternates
sudo chown root:www-data -R /emergence/services/sencha/builds.git
sudo chmod ug=Xrw,o=Xr -R /emergence/services/sencha/builds.git