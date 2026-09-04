
function finNotice(message,type='error'){
 const old=document.querySelector('#appFeedbackToast');if(old)old.remove();
 const el=document.createElement('div');el.id='appFeedbackToast';el.className=`app-feedback-toast ${type}`;el.setAttribute('role','status');el.setAttribute('aria-live','assertive');
 const safe=String(message??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
 el.innerHTML=`<span class="app-feedback-icon">${type==='error'?'!':'✓'}</span><div><b>${type==='error'?'No se pudo completar':'Listo'}</b><p>${safe}</p></div><button type="button" aria-label="Cerrar">×</button>`;document.body.appendChild(el);requestAnimationFrame(()=>el.classList.add('show'));
 const close=()=>{el.classList.remove('show');setTimeout(()=>el.remove(),220)};el.querySelector('button').onclick=close;setTimeout(close,type==='error'?4300:2800);
}
function finParseMoney(value){let v=String(value??'').trim().replace(/\s/g,'').replace(/S\/?/gi,'').replace(/[^0-9,.-]/g,'');if(!v)return 0;const neg=v.startsWith('-');v=v.replace(/-/g,'');const d=v.lastIndexOf('.'),c=v.lastIndexOf(',');if(d>=0&&c>=0){const x=Math.max(d,c);v=v.slice(0,x).replace(/[.,]/g,'')+'.'+v.slice(x+1).replace(/[.,]/g,'')}else if(c>=0){const r=v.length-c-1;v=r>0&&r<=2?v.replace(/\./g,'').replace(',','.'):v.replace(/,/g,'')}else if(d>=0){const r=v.length-d-1;if(r===3&&v.indexOf('.')===d)v=v.replace(/\./g,'');else{const a=v.split('.');if(a.length>2)v=a.slice(0,-1).join('')+'.'+a.at(-1)}}const n=finParseMoney(v);return Number.isFinite(n)?(neg?-n:n):0}
function finMoneySet(el,value){if(!el)return;el.value=Number(value||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}
function finCaret(formatted,count){const d=formatted.indexOf('.'),limit=d>=0?d:formatted.length;let seen=0;for(let i=0;i<limit;i++){if(/\d/.test(formatted[i]))seen++;if(seen>=count)return i+1}return limit}
function finBindMoney(root=document){root.querySelectorAll?.('[data-money-input]').forEach(el=>{if(el.dataset.finMoneyBound)return;el.dataset.finMoneyBound='1';el.addEventListener('focus',()=>{if(el.value.trim()){finMoneySet(el,finParseMoney(el.value));const d=el.value.indexOf('.');try{el.setSelectionRange(d,d)}catch{}}});el.addEventListener('keydown',e=>{if(e.key==='.'||e.key===','){e.preventDefault();if(!el.value.trim())el.value='0.00';else finMoneySet(el,finParseMoney(el.value));const d=el.value.indexOf('.');try{el.setSelectionRange(d+1,d+3)}catch{}}});el.addEventListener('input',()=>{const raw=el.value;if(!raw)return;const pos=el.selectionStart??raw.length,sep=Math.max(raw.lastIndexOf('.'),raw.lastIndexOf(',')),decs=sep>=0&&pos>sep,intCount=(raw.slice(0,decs?sep:pos).match(/\d/g)||[]).length,decCount=decs?(raw.slice(sep+1,pos).match(/\d/g)||[]).length:0,fmt=Number(finParseMoney(raw)||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});el.value=fmt;requestAnimationFrame(()=>{const d=fmt.indexOf('.'),next=decs?Math.min(fmt.length,d+1+Math.min(2,decCount)):finCaret(fmt,Math.max(1,intCount));try{el.setSelectionRange(next,next)}catch{}})});el.addEventListener('blur',()=>{if(el.value.trim())finMoneySet(el,finParseMoney(el.value))})})}
(() => {
 const api=f=>`${APP.apiBase}/${f}`; const $=s=>document.querySelector(s); let lastLocalAction=0;
 async function req(file,data){const r=await fetch(api(file),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':APP.csrf},body:JSON.stringify(data)}),j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.message||'Error');lastLocalAction=Date.now();return j;}
 document.querySelectorAll('.autosave-finance').forEach(form=>{let t,balanceDirty=false;const status=form.querySelector('[data-status]');form.querySelectorAll('input,select').forEach(el=>el.addEventListener('change',e=>{if(e.target.name==='current_balance')balanceDirty=true;save()}));form.querySelectorAll('input[type="text"],input[type="number"],.title-edit').forEach(el=>el.addEventListener('input',e=>{if(e.target.name==='current_balance')balanceDirty=true;clearTimeout(t);status&&(status.textContent='● Pendiente');t=setTimeout(save,550)}));async function save(){clearTimeout(t);const data=Object.fromEntries(new FormData(form).entries());if(form.dataset.endpoint==='account_save.php'&&!balanceDirty)delete data.current_balance;status&&(status.textContent='● Guardando...');try{await req(form.dataset.endpoint,data);balanceDirty=false;status&&(status.textContent='● Guardado');}catch(e){status&&(status.textContent='● Error');finNotice(e.message,'error')}}});
 const newAccount=$('#newAccountForm');if(newAccount)newAccount.onsubmit=async e=>{e.preventDefault();try{await req('account_save.php',Object.fromEntries(new FormData(e.target).entries()));location.reload()}catch(err){finNotice(err.message,'error')}};
 const transfer=$('#transferForm');if(transfer)transfer.onsubmit=async e=>{e.preventDefault();try{await req('account_transfer.php',Object.fromEntries(new FormData(e.target).entries()));location.reload()}catch(err){finNotice(err.message,'error')}};
 const newFund=$('#newFundForm');if(newFund)newFund.onsubmit=async e=>{e.preventDefault();try{await req('fund_save.php',Object.fromEntries(new FormData(e.target).entries()));location.reload()}catch(err){finNotice(err.message,'error')}};

 // Fondos: las opciones secundarias viven en un menú ⋯ y se editan en un único modal.
 const fundMenus=[...document.querySelectorAll('.fund-menu-wrap')];
 function closeFundMenus(except=null){fundMenus.forEach(w=>{if(w===except)return;w.classList.remove('open');const b=w.querySelector('[data-fund-menu-toggle]');b&&b.setAttribute('aria-expanded','false')})}
 document.querySelectorAll('[data-fund-menu-toggle]').forEach(btn=>btn.addEventListener('click',e=>{e.stopPropagation();const wrap=btn.closest('.fund-menu-wrap'),willOpen=!wrap.classList.contains('open');closeFundMenus(wrap);wrap.classList.toggle('open',willOpen);btn.setAttribute('aria-expanded',willOpen?'true':'false')}));
 document.addEventListener('click',e=>{if(!e.target.closest('.fund-menu-wrap'))closeFundMenus()});
 document.addEventListener('keydown',e=>{if(e.key==='Escape')closeFundMenus()});

 const fundEditModal=$('#fundEditModal'),fundEditForm=$('#fundEditForm'),fundEditStatus=$('#fundEditStatus');
 let fundEditTimer=null,fundEditDirty=false,fundEditButton=null;
 function setFundEditStatus(text,kind='saved'){if(!fundEditStatus)return;fundEditStatus.textContent=text;fundEditStatus.className='autosave-status '+kind}
 function updateFundCard(btn,data){
   if(!btn)return;const card=document.querySelector(`[data-fund-card="${data.id}"]`);if(!card)return;
   const available=Number(btn.dataset.fundAvailable||0),target=Number(data.target_amount||0),pct=target>0?Math.min(100,Math.max(0,available/target*100)):0;
   const icon=card.querySelector('[data-fund-card-icon]'),name=card.querySelector('[data-fund-card-name]'),bar=card.querySelector('[data-fund-card-progress]'),goal=card.querySelector('[data-fund-card-goal]');
   if(icon)icon.textContent=data.icon||'💰';if(name)name.textContent=data.name||'';if(bar){bar.style.width=pct+'%';bar.style.background=data.color||'#6b7280'}
   if(goal)goal.innerHTML=target>0?`<span>Meta S/ ${target.toLocaleString('es-PE',{minimumFractionDigits:2,maximumFractionDigits:2})}</span><b>${Math.round(pct)}%</b>`:'<span>Sin meta definida</span><b>Opcional</b>';
   btn.dataset.fundName=data.name||'';btn.dataset.fundIcon=data.icon||'💰';btn.dataset.fundColor=data.color||'#6b7280';btn.dataset.fundTarget=target.toFixed(2);
   const rel=card.querySelector('.release-fund');if(rel)rel.dataset.fundName=data.name||'';
   const more=card.querySelector('[data-fund-menu-toggle]');if(more)more.setAttribute('aria-label','Opciones de '+(data.name||'fondo'));
 }
 async function saveFundEdit(){
   clearTimeout(fundEditTimer);fundEditTimer=null;if(!fundEditForm||!fundEditDirty)return true;
   const data=Object.fromEntries(new FormData(fundEditForm).entries());setFundEditStatus('● Guardando...','saving');
   try{await req('fund_save.php',data);fundEditDirty=false;setFundEditStatus('● Guardado','saved');updateFundCard(fundEditButton,data);return true}catch(err){setFundEditStatus('● No se guardó','error');finNotice(err.message,'error');return false}
 }
 function scheduleFundEdit(){fundEditDirty=true;setFundEditStatus('● Pendiente','pending');clearTimeout(fundEditTimer);fundEditTimer=setTimeout(saveFundEdit,500)}
 document.querySelectorAll('[data-edit-fund]').forEach(btn=>btn.addEventListener('click',()=>{
   closeFundMenus();fundEditButton=btn;$('#fundEditId').value=btn.dataset.fundId||'';$('#fundEditIcon').value=btn.dataset.fundIcon||'💰';$('#fundEditName').value=btn.dataset.fundName||'';$('#fundEditTarget').value=btn.dataset.fundTarget||'0.00';$('#fundEditColor').value=btn.dataset.fundColor||'#6b7280';$('#fundEditTitle').textContent='Editar '+(btn.dataset.fundName||'fondo');fundEditDirty=false;setFundEditStatus('● Guardado','saved');fundEditModal.classList.add('show');fundEditModal.setAttribute('aria-hidden','false');setTimeout(()=>$('#fundEditName')?.focus(),80)
 }));
 if(fundEditForm){fundEditForm.querySelectorAll('input').forEach(el=>{el.addEventListener(el.type==='color'?'change':'input',scheduleFundEdit);if(el.type!=='color')el.addEventListener('change',scheduleFundEdit)})}
 if(fundEditForm)fundEditForm.addEventListener('submit',async e=>{e.preventDefault();await saveFundEdit()});
 async function closeFundEdit(){if(fundEditTimer||fundEditDirty){const ok=await saveFundEdit();if(!ok)return}fundEditModal?.classList.remove('show');fundEditModal?.setAttribute('aria-hidden','true')}
 document.querySelectorAll('[data-fund-edit-close]').forEach(b=>b.addEventListener('click',closeFundEdit));
 if(fundEditModal)fundEditModal.addEventListener('click',e=>{if(e.target===fundEditModal)closeFundEdit()});
 document.querySelectorAll('.fund-menu-wrap .release-fund').forEach(b=>b.addEventListener('click',closeFundMenus));
 const distModal=$('#distributionModal'),openDist=$('#openDistribution');if(openDist)openDist.onclick=()=>distModal.classList.add('show');document.querySelectorAll('[data-dist-close]').forEach(b=>b.onclick=()=>distModal.classList.remove('show'));
 const distForm=$('#distributionForm');if(distForm){const inputs=[...distForm.querySelectorAll('[name^="allocation_"]')],total=$('#distributionTotal');inputs.forEach(i=>i.oninput=()=>{const n=inputs.reduce((s,x)=>s+finParseMoney(x.value||0),0);total.textContent='Total a distribuir: S/ '+n.toLocaleString('es-PE',{minimumFractionDigits:2,maximumFractionDigits:2})});distForm.onsubmit=async e=>{e.preventDefault();const fd=new FormData(e.target),alloc={};for(const [k,v] of fd.entries())if(k.startsWith('allocation_')&&finParseMoney(v)>0)alloc[k.replace('allocation_','')]=finParseMoney(v);try{await req('fund_allocate.php',{allocations:alloc,source_transaction_id:fd.get('source_transaction_id')});location.reload()}catch(err){finNotice(err.message,'error')}}}
 const relModal=$('#releaseModal');document.querySelectorAll('.release-fund').forEach(b=>b.onclick=()=>{$('#releaseFundId').value=b.dataset.fundId;$('#releaseTitle').textContent='Liberar de '+b.dataset.fundName;$('#releaseAmount').max=b.dataset.available;relModal.classList.add('show')});document.querySelectorAll('[data-release-close]').forEach(b=>b.onclick=()=>relModal.classList.remove('show'));const rel=$('#releaseForm');if(rel)rel.onsubmit=async e=>{e.preventDefault();try{await req('fund_release.php',{fund_id:$('#releaseFundId').value,amount:finParseMoney($('#releaseAmount').value).toFixed(2)});location.reload()}catch(err){finNotice(err.message,'error')}};
 const es=new EventSource(api('stream.php'));es.addEventListener('change',()=>{if(Date.now()-lastLocalAction<1800)return;setTimeout(()=>{const active=document.activeElement?.tagName||'';if(!document.querySelector('.modal.show')&&!['INPUT','SELECT','TEXTAREA'].includes(active))location.reload();},300)});
})();

finBindMoney(document);
