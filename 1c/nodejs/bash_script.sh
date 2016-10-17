#!/bin/bash
if ! (ps aux | grep -q "nodejs\/update_product.js");
then
	#screen -dm -S test /usr/share/nginx/html/temp/node-v4.6.0-linux-x64/bin/node /usr/share/nginx/html/temp/nodejs/update_product.js
	screen -dm -S unloading_1s /home/m/mekoudaj/z-meridian.ru/public_html/1c/node-v4.6.0-linux-x64/bin/node /home/m/mekoudaj/z-meridian.ru/public_html/1c/nodejs/update_product.js
fi;