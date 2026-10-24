const {TextDecoder}=require('node:util');
function assertTextIntegrity(body,extension,label){
 if(body.includes(0))throw Error('Null-filled source refused: '+label);
 let text;try{text=new TextDecoder('utf-8',{fatal:true}).decode(body);}catch{throw Error('Invalid UTF-8 source refused: '+label);}
 if(extension==='.php'&&!/^\s*<\?php\b/.test(text))throw Error('PHP source opening tag missing: '+label);
 return text;
}
module.exports={assertTextIntegrity};
