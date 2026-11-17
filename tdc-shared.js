/* TDC shared script — TDC-Office-Plan/brand/tdc-shared.js
   ONE copy lives in every TDC app next to index.html and must match this file exactly (the design check compares them).
   Load it with <script src="tdc-shared.js" defer></script>. It needs nothing from the app.
   What it does: group headings (class "tdc-gh") get the fold arrow; tapping a heading folds or opens its group,
   and folded groups are remembered on this device. Works after every re-render (it watches the page). */
(function(){
  "use strict";
  var KEY="tdc-folds:"+location.pathname;
  var folds={};
  try{folds=JSON.parse(localStorage.getItem(KEY)||"{}")||{}}catch(e){folds={}}
  var CHEV='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
  function label(h){
    if(h.dataset.gk)return h.dataset.gk;
    var f=h.firstElementChild||h;
    return (f.textContent||"").replace(/\s+/g," ").trim().slice(0,80);
  }
  function rowsOf(h){
    var out=[],n=h.nextElementSibling;
    while(n&&!n.classList.contains("tdc-gh")){out.push(n);n=n.nextElementSibling}
    return out;
  }
  function apply(h){
    var k=label(h),shut=!!folds[k];
    h.setAttribute("aria-expanded",shut?"false":"true");
    rowsOf(h).forEach(function(r){r.classList.toggle("tdc-gfold",shut)});
  }
  function prep(h){
    if(h.dataset.tdcGh)return;
    h.dataset.tdcGh="1";
    if(!h.hasAttribute("tabindex"))h.setAttribute("tabindex","0");
    h.setAttribute("role","button");
    if(!h.querySelector(".tdc-gchev")){
      var c=document.createElement("span");c.className="tdc-gchev";c.innerHTML=CHEV;
      var cnt=h.querySelector(".tdc-gcount"),first=h.firstElementChild;
      var after=cnt||first;
      if(after&&after.parentNode===h)h.insertBefore(c,after.nextSibling);else h.appendChild(c);
    }
    apply(h);
  }
  function scan(root){
    (root||document).querySelectorAll(".tdc-gh").forEach(function(h){h.dataset.tdcGh?apply(h):prep(h)});
  }
  function toggle(h){
    var k=label(h);
    if(folds[k])delete folds[k];else folds[k]=1;
    try{localStorage.setItem(KEY,JSON.stringify(folds))}catch(e){}
    apply(h);
  }
  document.addEventListener("click",function(e){
    var h=e.target.closest&&e.target.closest(".tdc-gh");
    if(!h)return;
    if(e.target.closest("a,button:not(.tdc-gchev),input,label,select,textarea,summary,[data-nofold]"))return;
    toggle(h);
  });
  document.addEventListener("keydown",function(e){
    if((e.key==="Enter"||e.key===" ")&&e.target.classList&&e.target.classList.contains("tdc-gh")){e.preventDefault();toggle(e.target)}
  });
  var pending=false;
  function later(){if(pending)return;pending=true;requestAnimationFrame(function(){pending=false;scan()})}
  function start(){scan();new MutationObserver(later).observe(document.body,{childList:true,subtree:true})}
  if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",start);else start();
})();
