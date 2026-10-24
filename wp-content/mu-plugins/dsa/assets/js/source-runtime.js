/* Generated from Seam app/runtimeBridge.ts; source SHA256 77131e8e80ef150372eba9308a9bc451ea8470cd6ef6c9576a04f36fcd755e32; bridge 1. Do not edit. */
(function(w,d,c){
  "use strict";
  var api=w.BRXSourceRuntime=w.BRXSourceRuntime||{};
  if(api.version!=="1"){
  api.version="1";
  api.bundles=api.bundles||{};
  api.executed=api.executed||{};
  api.epoch=api.epoch||0;
  api._scheduled=api._scheduled||false;
  api._observer=api._observer||null;
  api._eventsBound=api._eventsBound||false;
  api._headerRoots=api._headerRoots||new Set();
  api.bridgeHeaderOffset=function(roots){
    roots.forEach(function(root){api._headerRoots.add(root);});
    if(!api._headerMeasure)api._headerMeasure=function(){
      var header=d.querySelector('#brx-header'),height=0;
      if(header){var candidates=[header].concat(Array.prototype.slice.call(header.querySelectorAll('header,[data-seam-source-tag="header"]')));candidates.forEach(function(node){var rect=node.getBoundingClientRect();if(rect.height>height)height=rect.height;});}
      api._headerRoots.forEach(function(root){if(!root.isConnected){api._headerRoots.delete(root);return;}var value=Math.ceil(Math.max(0,height))+'px';if(root.style.getPropertyValue('--seam-header-height')!==value)root.style.setProperty('--seam-header-height',value);if(root.style.getPropertyValue('--header')!==value)root.style.setProperty('--header',value,'important');});
    };
    if(!api._headerResizeBound){api._headerResizeBound=true;w.addEventListener('resize',api._headerMeasure,{passive:true});}
    var header=d.querySelector('#brx-header');
    if(header&&api._headerObserved!==header){if(api._headerObserver)api._headerObserver.disconnect();api._headerObserved=header;if(w.ResizeObserver){api._headerObserver=new w.ResizeObserver(api._headerMeasure);api._headerObserver.observe(header);Array.prototype.forEach.call(header.querySelectorAll('header,[data-seam-source-tag="header"]'),function(node){api._headerObserver.observe(node);});}}
    api._headerMeasure();
  };
  api.bridgeArchiveLinks=function(roots){
    roots.forEach(function(root){Array.prototype.slice.call(root.querySelectorAll('[data-seam-archive-link]')).slice(0,100).forEach(function(link){
      var activeClass=link.getAttribute('data-seam-archive-active-class')||'';
      if(!/^[a-zA-Z_][a-zA-Z0-9_-]{0,100}$/.test(activeClass))return;
      var active=false;try{var href=new w.URL(link.getAttribute('href'),w.location.href),here=new w.URL(w.location.href);active=href.origin===here.origin&&href.pathname.replace(/\/$/,'')===here.pathname.replace(/\/$/,'');}catch(error){}
      link.classList.toggle(activeClass,active);if(active)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');
    });});
  };
  api.escapeSelector=api.escapeSelector||function(value){return w.CSS&&typeof w.CSS.escape==="function"?w.CSS.escape(String(value)):String(value).replace(/[^a-zA-Z0-9_-]/g,"\\$&");};
  // A native Bricks Image may own a figure wrapper. Preserve authored image
  // property/attribute access on that source node without rewriting arbitrary
  // application code or moving its layout, classes or selector identity.
  api._imageTargets=api._imageTargets||new WeakMap();
  api.bridgeImages=function(roots,selectors){
    roots.forEach(function(root){(selectors||[]).forEach(function(selector){
      var targets=[];try{targets=Array.prototype.slice.call(root.querySelectorAll(selector));}catch(error){return;}
      targets.forEach(function(target){
        if(!target)return;
        // Native Image custom attributes are rendered on the inner img,
        // while its source ID and layout belong to the Bricks wrapper.
        if(String(target.tagName).toLowerCase()==="img"){
          var owner=target.closest(".brxe-image");
          if(owner&&owner!==target)target=owner;
          else if(target.parentElement&&api._imageTargets.has(target.parentElement))target=target.parentElement;
          else return;
        }
        var image=target.querySelector("img");if(!image)return;
        if(api._imageTargets.has(target)){api._imageTargets.set(target,image);return;}
        api._imageTargets.set(target,image);
        ["src","srcset","sizes","alt","loading","decoding","fetchPriority","crossOrigin","referrerPolicy"].forEach(function(property){
          if(property in target)return;
          Object.defineProperty(target,property,{configurable:true,get:function(){return api._imageTargets.get(target)[property];},set:function(value){api._imageTargets.get(target)[property]=value;}});
        });
        ["currentSrc","complete","naturalWidth","naturalHeight"].forEach(function(property){
          if(property in target)return;
          Object.defineProperty(target,property,{configurable:true,get:function(){return api._imageTargets.get(target)[property];}});
        });
        var attributes={src:1,srcset:1,sizes:1,alt:1,loading:1,decoding:1,fetchpriority:1,crossorigin:1,referrerpolicy:1};
        ["getAttribute","setAttribute","removeAttribute","hasAttribute"].forEach(function(method){
          var original=target[method];
          target[method]=function(name){var current=api._imageTargets.get(target);return attributes[String(name).toLowerCase()]?current[method].apply(current,arguments):original.apply(target,arguments);};
        });
      });
    });});
  };
  api.setRootProperty=api.setRootProperty||function(selector,method){
    var args=Array.prototype.slice.call(arguments,2),roots=[];
    try{roots=Array.prototype.slice.call(d.querySelectorAll(String(selector||"")));}catch(error){return;}
    roots.forEach(function(root){if(root&&root.style&&typeof root.style[method]==="function")root.style[method].apply(root.style,args);});
  };
  api.openDialog=api.openDialog||function(brx,id){
    var target=d.getElementById(String(id||""));
    if(!target)return;
    if(typeof target.showModal==="function"&&!target.open)target.showModal();
    else target.setAttribute("open","");
    var focus=target.querySelector("[data-search-input],input,button,[href],[tabindex]:not([tabindex='-1'])");
    if(focus)w.setTimeout(function(){focus.focus();},40);
  };
  api.closeDialog=api.closeDialog||function(brx){
    var source=brx&&brx.source;
    var target=source&&source.closest?source.closest("dialog,[role='dialog']"):null;
    if(!target)return;
    if(typeof target.close==="function"&&target.open)target.close();
    else target.removeAttribute("open");
  };
  api.closeDialogBySelector=api.closeDialogBySelector||function(brx,selector){
    var target=null;
    try{target=d.querySelector(String(selector||""));}catch(error){return;}
    if(!target)return;
    if(typeof target.close==="function"&&target.open)target.close();
    else target.removeAttribute("open");
  };
  api._ensureVisibilityStyle=api._ensureVisibilityStyle||function(){
    if(d.getElementById("brx-source-runtime-visibility"))return;
    var style=d.createElement("style");
    style.id="brx-source-runtime-visibility";
    style.textContent=".brx-source-runtime-hidden{display:none!important}";
    (d.head||d.documentElement).appendChild(style);
  };
  api.setHidden=api.setHidden||function(target,state){
    if(!target)return state;
    target.hidden=state;
    if(target.nodeType===1&&target.classList){api._ensureVisibilityStyle();target.classList.toggle("brx-source-runtime-hidden",Boolean(state));}
    return state;
  };
  api.toggleClass=api.toggleClass||function(brx,selector,className){
    var target=brx&&brx.source;
    if(selector){try{target=d.querySelector(String(selector));}catch(error){return;}}
    if(target&&target.classList&&className)target.classList.toggle(String(className));
  };
  api.scrollRail=api.scrollRail||function(brx,id,direction){
    var rail=d.getElementById(String(id||""));
    if(!rail||typeof rail.scrollBy!=="function")return;
    var amount=Math.min(rail.clientWidth*.85,760)*(Number(direction)||1);
    rail.scrollBy({left:amount,behavior:"smooth"});
  };
  api._hash=api._hash||function(value){var hash=2166136261;value=String(value||"");for(var i=0;i<value.length;i++){hash^=value.charCodeAt(i);hash=Math.imul(hash,16777619);}return(hash>>>0).toString(36);};
  // External identity is the URL, never its index/source label in a template.
  // Keep the complete identity as a map key to avoid short hash collisions.
  api._signature=function(script){var src=script.src||"";if(src){try{src=new URL(src,d.baseURI).href;}catch(error){}return "url:"+src+"|"+(script.type||"");}return "inline:"+(script.source||"")+"\u0000"+(script.code||"")+"\u0000"+(script.type||"");};
  api.pending=api.pending||{};
  api.errors=api.errors||{};
  api.inlineAttempted=api.inlineAttempted||{};
  api._listenerMarks=api._listenerMarks||new WeakMap();
  api._withIdempotentListeners=api._withIdempotentListeners||function(sourceSignature,callback){
    var proto=w.EventTarget&&w.EventTarget.prototype;
    if(!proto)return callback();
    var original=proto.addEventListener;
    proto.addEventListener=function(type,listener,options){
      var receiver=this&&(typeof this==="object"||typeof this==="function")?this:w;
      if(!listener)return original.call(receiver,type,listener,options);
      var capture=typeof options==="boolean"?options:!!(options&&options.capture);
      var body=typeof listener==="function"?Function.prototype.toString.call(listener):String(listener&&listener.handleEvent||listener);
      var key=sourceSignature+"|"+type+"|"+(capture?"1":"0")+"|"+api._hash(body);
      var marks=api._listenerMarks.get(receiver);
      if(!marks){marks={};api._listenerMarks.set(receiver,marks);}
      if(marks[key])return;
      marks[key]=true;
      return original.call(receiver,type,listener,options);
    };
    try{return callback();}finally{proto.addEventListener=original;}
  };
  api._execute=function(script,bundleId,index,epoch){
    var signature=api._signature(script);
    if(api.executed[signature])return Promise.resolve();
    if(api.pending[signature])return api.pending[signature];
    // An inline script may have declared global consts before throwing. Never
    // replay that partially executed application on an unrelated remount.
    if(!script.src&&api.inlineAttempted[signature])return Promise.resolve();
    // Reserve the promise before appending: other bundles must await the same
    // request, including module scripts, rather than treating pending as ready.
    var finish;
    var pending=new Promise(function(resolve){finish=resolve;});
    api.pending[signature]=pending;
    (function(){
      var node=d.createElement("script");
      if(!script.src)api.inlineAttempted[signature]=true;
      if(script.type)node.type=script.type;
      node.setAttribute("data-brx-source-runtime",script.source||bundleId+":"+index);
      var completed=false,timer,removeModuleListeners;
      function complete(error){
        if(completed)return;completed=true;
        if(timer)w.clearTimeout(timer);
        if(removeModuleListeners)removeModuleListeners();
        if(error){
          api.errors[signature]={bundleId:bundleId,index:index,source:script.src||script.source||"",message:String(error.message||error)};
          d.dispatchEvent(new CustomEvent("brx-source:runtime-error",{detail:api.errors[signature]}));
        }else{api.executed[signature]=true;delete api.errors[signature];}
        delete api.pending[signature];finish();
      }
      // A failed dependency is recorded, not marked successful. Continue into
      // the source's own fallback branch; a later explicit mount may retry it.
      if(script.src||/module/i.test(script.type||"")){
        node.async=false;node.onload=function(){complete();};node.onerror=function(){complete(new Error("Source script failed to load or evaluate"));};
        if(script.src)node.src=script.src;else{
          // Inline modules do not fire load (HTML script processing model).
          // A tail signal waits for imports and top-level await without
          // wrapping the module or changing its document-relative base.
          var moduleEvent="brx-source:module-complete";
          var moduleToken=bundleId+":"+index+":"+(api._moduleCounter=(api._moduleCounter||0)+1);
          var moduleSource="brx-source-module-"+api._moduleCounter+".js";
          function onModuleComplete(event){if(event.detail===moduleToken)complete();}
          function onModuleError(event){if(event.filename===moduleSource)complete(event.error||new Error(event.message||"Source module error"));}
          d.addEventListener(moduleEvent,onModuleComplete);w.addEventListener("error",onModuleError);
          removeModuleListeners=function(){d.removeEventListener(moduleEvent,onModuleComplete);w.removeEventListener("error",onModuleError);};
          node.onload=null;
          node.text=String(script.code||"")+"\n;globalThis.document.dispatchEvent(new globalThis.CustomEvent("+JSON.stringify(moduleEvent)+",{detail:"+JSON.stringify(moduleToken)+"}));\n//# sourceURL="+moduleSource;
        }
        timer=w.setTimeout(function(){node.remove();complete(new Error("Source script load timed out"));},20000);
        try{(d.head||d.documentElement).appendChild(node);}catch(error){complete(error);}return;
      }
      node.text=String(script.code||"")+"\n//# sourceURL=brx-source-runtime-"+bundleId+"-"+index+".js";
      // Inline runtime exceptions are dispatched as window errors rather than
      // thrown from appendChild. Record them without suppressing the console.
      var runtimeError;
      function onRuntimeError(event){runtimeError=event.error||new Error(event.message||"Source script error");}
      w.addEventListener("error",onRuntimeError);
      try{api._withIdempotentListeners(signature,function(){(d.head||d.documentElement).appendChild(node);});node.remove();complete(runtimeError);}catch(error){complete(error);}
      finally{w.removeEventListener("error",onRuntimeError);}
    })();
    return pending;
  };
  api._mount=function(bundle,epoch){
    var roots=[];
    try{roots=Array.prototype.slice.call(d.querySelectorAll(bundle.rootSelector));}catch(error){return;}
    if(!roots.length)return;
    if(bundle.headerOffset===true)api.bridgeHeaderOffset(roots);
    api.bridgeArchiveLinks(roots);
    api.bridgeImages(roots,bundle.nativeImageSelectors);
    var pending=roots.filter(function(root){return root.getAttribute("data-brx-source-runtime-ready")!==bundle.id;});
    if(!pending.length)return;
    roots.forEach(function(root){root.setAttribute("data-brx-source-runtime-ready",bundle.id);});
    bundle.scripts.reduce(function(chain,script,index){return chain.then(function(){return api._execute(script,bundle.id,index,epoch);});},Promise.resolve()).then(function(){
      d.dispatchEvent(new CustomEvent("brx-source:runtime-mounted",{detail:{bundleId:bundle.id,roots:pending.length}}));
    });
  };
  api._bundleNodes=api._bundleNodes||new WeakMap();
  api.scan=function(){
    Array.prototype.slice.call(d.querySelectorAll('script[data-seam-runtime="1"][data-brx-source-runtime-bundle]')).slice(0,256).forEach(function(node){
      if(node.type&&!/^(?:text|application)\/javascript$/i.test(node.type))return;
      var text=node.textContent||"",prefix="(function(w,c){\"use strict\";var a=w.BRXSourceRuntime;if(a&&a.version===\"1\")a.register(c);else(w.BRXSourceRuntimeQueue=w.BRXSourceRuntimeQueue||[]).push(c);})(window,",suffix=");";
      if(api._bundleNodes.get(node)===text||text.length>2097152||text.slice(0,prefix.length)!==prefix||text.slice(-suffix.length)!==suffix)return;
      try{var bundle=JSON.parse(text.slice(prefix.length,-suffix.length));if(bundle.id!==node.getAttribute('data-brx-source-runtime-bundle'))return;api.register(bundle);api._bundleNodes.set(node,text);}catch(error){}
    });
  };
  api.run=function(){api._scheduled=false;api.scan();api.epoch+=1;var epoch=api.epoch;Object.keys(api.bundles).forEach(function(id){api._mount(api.bundles[id],epoch);});};
  api.schedule=api.schedule||function(){if(api._scheduled)return;api._scheduled=true;w.setTimeout(api.run,0);};
  api.register=function(bundle){if(!bundle||typeof bundle.id!=="string"||!bundle.id||typeof bundle.rootSelector!=="string"||!Array.isArray(bundle.scripts)||bundle.scripts.length>256)return;api.bundles[bundle.id]=bundle;api.schedule();};
  if(!api._eventsBound){
    api._eventsBound=true;
    ["DOMContentLoaded","pageshow","popstate","bricks/ajax/nodes_added","bricks/ajax/query_result/displayed","bricks/ajax/popup/loaded","brx-source:ready"].forEach(function(name){
      (name==="pageshow"||name==="popstate"?w:d).addEventListener(name,api.schedule);
    });
  }
  if(!api._observer&&d.documentElement){api._observer=new MutationObserver(api.schedule);api._observer.observe(d.documentElement,{childList:true,subtree:true});}
  var queue=w.BRXSourceRuntimeQueue||[];w.BRXSourceRuntimeQueue=[];queue.slice(0,256).forEach(api.register);api.schedule();
  }
  if(c)api.register(c);
})(window,document,null);
