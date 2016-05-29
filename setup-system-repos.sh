#!/bin/bash
sudo mkdir /emergence/services/sencha

sudo git clone --bare https://github.com/JarvusInnovations/extjs.git /emergence/services/sencha/frameworks-repo.git
sudo chown root:www-data -R /emergence/services/sencha/frameworks-repo.git
sudo chmod ug=Xrw,o=Xr -R /emergence/services/sencha/frameworks-repo.git

sudo git init --bare /emergence/services/sencha/builds-repo.git
echo "/emergence/services/sencha/frameworks-repo.git/objects" > /emergence/services/sencha/builds-repo.git/objects/info/alternates
sudo chown root:www-data -R /emergence/services/sencha/builds-repo.git
sudo chmod ug=Xrw,o=Xr -R /emergence/services/sencha/builds-repo.git