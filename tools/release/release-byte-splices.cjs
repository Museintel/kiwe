// Transport only. Reconstructed bytes must still pass the native signed package verifier.
const crypto=require('crypto');
const hash=b=>crypto.createHash('sha256').update(b).digest('hex');
function spliceOne(original,target,baseFormat='original'){
 const base=normalize(original,baseFormat);
 if(base.length>4*1024*1024||target.length>4*1024*1024)throw Error('Bounded package members required');
 function lines(b){const out=[];let from=0;for(let i=0;i<b.length;i++)if(b[i]===10){out.push({offset:from,length:i+1-from,key:b.subarray(from,i+1).toString('base64')});from=i+1;}if(from<b.length)out.push({offset:from,length:b.length-from,key:b.subarray(from).toString('base64')});return out;}
 const old=lines(base),next=lines(target),unique=new Map();
 for(const line of old){if(line.length<12)continue;unique.set(line.key,unique.has(line.key)?null:line);}
 // Prefer the longest ordered anchor sequence; inserted code can contain a
 // line that also occurs much later in the base and must not skip that tail.
 const candidates=next.map(line=>({line,anchor:unique.get(line.key)})).filter(v=>v.anchor);
 const tails=[],previous=[];
 for(let i=0;i<candidates.length;i++){let low=0,high=tails.length;while(low<high){const mid=(low+high)>>1;if(candidates[tails[mid]].anchor.offset<candidates[i].anchor.offset)low=mid+1;else high=mid;}previous[i]=low?tails[low-1]:-1;tails[low]=i;}
 const anchors=[];for(let i=tails.at(-1);i!==undefined&&i>=0;i=previous[i])anchors.push(candidates[i]);anchors.reverse();
 const operations=[];let cursor=0,from=0;
 function copy(offset,length){if(!length)return;const last=operations.at(-1);if(Array.isArray(last)&&last[0]+last[1]===offset)last[1]+=length;else operations.push([offset,length]);}
 function literal(a,b){if(b>a)operations.push(target.subarray(a,b).toString('base64'));}
 for(const {line,anchor}of anchors){if(target.subarray(cursor,line.offset).equals(base.subarray(from,anchor.offset)))copy(from,anchor.offset-from);else literal(cursor,line.offset);copy(anchor.offset,anchor.length);cursor=line.offset+line.length;from=anchor.offset+anchor.length;}
 if(target.subarray(cursor).equals(base.subarray(from)))copy(from,base.length-from);else literal(cursor,target.length);
 if(operations.length>10000)throw Error('Bounded splice list required');
 return {schema:'kiwe.release-byte-splices.v1',baseSha256:hash(original),baseFormat,targetSha256:hash(target),targetBytes:target.length,operations};
}
function normalize(b,format){if(format==='original')return b;if(!['lf','crlf'].includes(format))throw Error('Unknown base format');const text=b.toString('utf8');if(!Buffer.from(text).equals(b))throw Error('Text format requires UTF-8');const lf=text.replace(/\r\n/g,'\n');return Buffer.from(format==='lf'?lf:lf.replace(/\n/g,'\r\n'));}
function spliceChunks(base,target){
 // Small minified bundles have no useful line anchors. Reuse exact byte
 // ranges with a bounded dictionary; the existing format/verifier is unchanged.
 const old=base.toString('latin1'),next=target.toString('latin1'),index=new Map(),operations=[];
 for(let i=0;i+32<=old.length;i+=8){const key=old.slice(i,i+32),list=index.get(key)||[];if(list.length<8)list.push(i);index.set(key,list);}
 let cursor=0,literalStart=0;
 while(cursor+32<=next.length){
  const offsets=index.get(next.slice(cursor,cursor+32));let length=0,offset=0;
  for(const candidate of offsets||[]){let size=32;while(candidate+size<old.length&&cursor+size<next.length&&old[candidate+size]===next[cursor+size])size++;if(size>length){length=size;offset=candidate;}}
  if(length){if(cursor>literalStart)operations.push(target.subarray(literalStart,cursor).toString('base64'));operations.push([offset,length]);cursor+=length;literalStart=cursor;}else cursor++;
 }
 if(literalStart<target.length)operations.push(target.subarray(literalStart).toString('base64'));
 if(operations.length>10000)throw Error('Bounded splice list required');
 return {schema:'kiwe.release-byte-splices.v1',baseSha256:hash(base),baseFormat:'original',targetSha256:hash(target),targetBytes:target.length,operations};
}
function splice(base,target){const choices=[spliceOne(base,target)];for(const mode of ['lf','crlf'])try{choices.push(spliceOne(base,target,mode));}catch{}if(base.length<=262144&&target.length<=262144&&base.toString().split('\n').length<8)choices.push(spliceChunks(base,target));return choices.sort((a,b)=>require('zlib').gzipSync(JSON.stringify(a)).length-require('zlib').gzipSync(JSON.stringify(b)).length)[0];}
function apply(base,patch){
 if(patch.schema!=='kiwe.release-byte-splices.v1'||hash(base)!==patch.baseSha256||!Array.isArray(patch.operations)||patch.operations.length>10000||!Number.isSafeInteger(patch.targetBytes)||patch.targetBytes<0||patch.targetBytes>4*1024*1024)throw Error('Invalid pinned splice');
 base=normalize(base,patch.baseFormat);if(base.length>4*1024*1024)throw Error('Oversized base');
 let size=0;const parts=patch.operations.map(op=>{let b;if(Array.isArray(op)){if(op.length!==2||op.some(n=>!Number.isSafeInteger(n)||n<0)||op[0]+op[1]>base.length)throw Error('Invalid copy range');b=base.subarray(op[0],op[0]+op[1]);}else if(typeof op==='string'){b=Buffer.from(op,'base64');if(b.toString('base64')!==op)throw Error('Invalid literal');}else throw Error('Invalid operation');size+=b.length;if(size>patch.targetBytes)throw Error('Oversized splice');return b;});
 const output=Buffer.concat(parts);if(output.length!==patch.targetBytes||hash(output)!==patch.targetSha256)throw Error('Target mismatch');return output;
}
module.exports={splice,apply};
if(require.main===module){let input='';process.stdin.setEncoding('utf8');process.stdin.on('data',v=>input+=v);process.stdin.on('end',()=>{try{const v=JSON.parse(input),base=Buffer.from(v.base,'base64');if(v.patch)process.stdout.write(JSON.stringify({target:apply(base,v.patch).toString('base64')}));else{const target=Buffer.from(v.target,'base64'),patch=splice(base,target);if(!apply(base,patch).equals(target))throw Error('Reconstruction failed');process.stdout.write(JSON.stringify(patch));}}catch(e){process.stderr.write(e.message);process.exitCode=1;}});}
