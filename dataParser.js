
const ArkFiles = require('./lib/ArkFilesData');
var arkFiles = new ArkFiles(process.argv[2], 2);

var result = '';

if(process.argv[3] == 'players'){
    result = arkFiles.getPlayers();
}else if(process.argv[3] == 'tribes'){
    result = arkFiles.getTribes();
}else{

}

if(process.argv[4] != 'array')
    result = JSON.stringify(result);

console.dir(result, {'maxArrayLength': null, 'maxStringLength':null});