const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const repo=path.resolve(__dirname,'../..'),base=path.join(repo,'wp-content/mu-plugins/dsa'),file=path.join(repo,'tools/release/apps-ownership.json');
function walk(root,prefix=''){const out=[];for(const e of fs.readdirSync(root,{withFileTypes:true})){assert(!e.isSymbolicLink());const name=prefix+e.name;if(e.isDirectory())out.push(...walk(path.join(root,e.name),name+'/'));else out.push(name);}return out.sort();}
const value=JSON.parse(fs.readFileSync(file,'utf8'));value.coreApi=4;
value.apps.mcp={name:'Kiwe MCP',requiresPhp:'8.2',requiresWp:'7.1',requiresWoo:'',namespaces:{'DSA\\MCP\\':'includes/MCP/'},classes:{'DSA\\Access\\MCP_Workspace_Service':'includes/Access/MCP_Workspace_Service.php'},
files:['assets/css/mcp-app.css','assets/js/mcp-app.js','includes/Access/MCP_Workspace_Service.php',...walk(path.join(base,'includes/MCP'),'includes/MCP/'),...walk(path.join(base,'vendor/mcp'),'vendor/mcp/')].sort(),minimumVersion:'1.0.0-rc.1'};
const text=JSON.stringify(value,null,2)+'\n';if(process.argv.includes('--check'))assert.equal(fs.readFileSync(file,'utf8').replace(/\r\n?/g,'\n'),text);else fs.writeFileSync(file,text);
console.log(JSON.stringify({passed:true,coreApi:4,optionalApps:Object.keys(value.apps),mcpFiles:value.apps.mcp.files.length}));
