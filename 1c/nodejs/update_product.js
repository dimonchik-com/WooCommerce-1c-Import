var request = require('request');
fs = require('fs');
var path = new String(process.argv[1]);
path=path.replace(/nodejs[\\|\/]update_product.js/g,"");

var new_commodity_list={};

setInterval(function () {
    if(isEmpty(new_commodity_list)) {
        
        request.get(
            {url: 'http://z-meridian.ru/index.php?action=doImport'},
            function (error, response, body) {
                console.log(body);
            }
        );

        fs.readFile(path + 'new_commodity_list.txt', 'utf8', function (err, data) {
            if (!err) {
                new_commodity_list = JSON.parse(data);
                //console.log(Object.keys(new_commodity_list).length);
                send_data(new_commodity_list);
            }
        });
    } 
},10000);

function send_data(new_commodity_list) {
    if(!isEmpty(new_commodity_list)) {

        var ar=[], iteration=0;
        for(var i in new_commodity_list) {
            ar.push({index:i, data:new_commodity_list[i]})
            delete new_commodity_list[i];
            iteration++;
            if(iteration==5) {
                break;
            }
        }
        send_post_data(new_commodity_list,ar);
    } else {
        fs.unlink(path+'new_commodity_list.txt', function(){
            deleteFolderRecursive(path+"/1cbitrix/import_files");
        });
    }
}

function send_post_data(new_commodity_list,ar){
    request.post(
        {url: 'http://z-meridian.ru/index.php?action=import_one_product', form: {list_product:ar}},
        function (error, response, body) {
            if (!error && response.statusCode == 200) {
                console.log(body+"="+Object.keys(new_commodity_list).length);
                send_data(new_commodity_list);
            } else {
                setTimeout(function(){
                    send_post_data(new_commodity_list,ar);
                }, 1000);
            }
        }
    );
}

function isEmpty(obj) {
    for(var prop in obj) {
        if(obj.hasOwnProperty(prop))
            return false;
    }
    return true && JSON.stringify(obj) === JSON.stringify({});
}

var fs = require('fs');
function deleteFolderRecursive(path) {
  if( fs.existsSync(path) ) {
    fs.readdirSync(path).forEach(function(file,index){
      var curPath = path + "/" + file;
      if(fs.lstatSync(curPath).isDirectory()) { // recurse
        deleteFolderRecursive(curPath);
      } else { // delete file
        fs.unlinkSync(curPath);
      }
    });
    fs.rmdirSync(path);
  }
};