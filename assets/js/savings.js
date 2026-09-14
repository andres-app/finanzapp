(()=>{
 const $=s=>document.querySelector(s),$$=s=>[...document.querySelectorAll(s)],api=f=>`${APP.apiBase}/${f}`,data=window.SAVINGS_DATA||{savings:{goals:[]},accounts:[],free:0};
 const money=n=>'S/ '+Number(n||0).toLocaleString('es-PE',{minimumFractionDigits:2,maximumFractionDigits:2});
 const parseMoneyInput=value=>{let v=String(value??'').trim().replace(/\s/g,'').replace(/S\/?/gi,'').replace(/[^0-9,.-]/g,'');if(!v)return 0;const neg=v.startsWith('-');v=v.replace(/-/g,'');const d=v.lastIndexOf('.'),c=v.lastIndexOf(',');if(d>=0&&c>=0){const x=Math.max(d,c);v=v.slice(0,x).replace(/[.,]/g,'')+'.'+v.slice(x+1).replace(/[.,]/g,'')}else if(c>=0){const p=v.split(','),r=p.at(-1).length;v=p.length>2?(r<=2?p.slice(0,-1).join('')+'.'+p.at(-1):p.join('')):(r<=2?v.replace(',','.'):v.replace(/,/g,''))}else if(d>=0){const p=v.split('.'),r=p.at(-1).length;if(p.length>2)v=r<=2?p.slice(0,-1).join('')+'.'+p.at(-1):p.join('')}const n=Number(v);return Number.isFinite(n)?(neg?-n:n):0};
 const moneyFieldRaw=el=>parseMoneyInput(el?.value).toFixed(2);
 const moneyFieldSet=(el,value)=>{if(!el)return;if(value===''){el.value='';return}const n=Number(value||0);el.value=Number.isFinite(n)?n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}):''};
 function moneyFieldEdit(el){if(!el||!el.value.trim())return;const n=parseMoneyInput(el.value);el.value=Number.isFinite(n)?n.toFixed(2):''}
 function normalizeMoneyTyping(raw){raw=String(raw??'').replace(/S\/?/gi,'').replace(/\s/g,'');let out='',decimal=false,decimals=0;for(const ch of raw){if(/\d/.test(ch)){if(decimal){if(decimals>=2)continue;decimals++}out+=ch}else if((ch==='.'||ch===',')&&!decimal){if(!out)out='0';out+='.';decimal=true}}return out}
 function sanitizeMoneyTyping(el){if(!el||el.dataset.moneyFormatting==='1')return;const raw=el.value,pos=el.selectionStart??raw.length,before=normalizeMoneyTyping(raw.slice(0,pos)),clean=normalizeMoneyTyping(raw);if(raw===clean)return;el.dataset.moneyFormatting='1';el.value=clean;const next=Math.min(clean.length,before.length);requestAnimationFrame(()=>{try{el.setSelectionRange(next,next)}catch{}delete el.dataset.moneyFormatting})}
 const bindMoneyFields=()=>$$('[data-money-input]').forEach(el=>{if(el.dataset.moneyBound)return;el.dataset.moneyBound='1';el.addEventListener('focus',()=>moneyFieldEdit(el));el.addEventListener('input',()=>sanitizeMoneyTyping(el));el.addEventListener('paste',e=>{const text=e.clipboardData?.getData('text');if(text==null)return;e.preventDefault();const n=parseMoneyInput(text);el.value=Number.isFinite(n)?n.toFixed(2):'';requestAnimationFrame(()=>{try{el.setSelectionRange(el.value.length,el.value.length)}catch{}})});el.addEventListener('blur',()=>{if(el.value.trim())moneyFieldSet(el,parseMoneyInput(el.value))})});
 const esc=v=>String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#39;');
 const nowLocal=()=>new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,16);
 async function post(file,payload){const r=await fetch(api(file),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':APP.csrf},body:JSON.stringify(payload)}),raw=await r.text();let j={};try{j=JSON.parse(raw)}catch{}if(!r.ok||j.ok===false)throw new Error(j.message||`Error HTTP ${r.status}`);return j}
 const dep=$('#savingsDepositModal'),withM=$('#savingsWithdrawModal'),edit=$('#savingsEditModal');
 function show(m){m?.classList.add('show');m?.setAttribute('aria-hidden','false');document.body.classList.add('modal-open')}
 function hide(m){m?.classList.remove('show');m?.setAttribute('aria-hidden','true');if(!$$('.modal.show').length)document.body.classList.remove('modal-open')}
 function celebrateSavings(amount,goalName,total){
   document.querySelector('#savingsCelebration')?.remove();
   const box=document.createElement('div');box.id='savingsCelebration';box.className='savings-celebration';box.setAttribute('role','status');box.setAttribute('aria-live','assertive');
   const pieces=Array.from({length:14},(_,i)=>`<i style="--i:${i}"></i>`).join('');
   box.innerHTML=`<div class="savings-confetti">${pieces}</div><div class="savings-celebration-card"><span class="savings-celebration-icon">🎉</span><div><small>¡BIEN HECHO!</small><h3>¡Felicidades! Ahorraste ${money(amount)}</h3><p>${esc(goalName)} · ahora tienes <b>${money(total)}</b> protegidos.</p></div><span class="savings-celebration-check">✓</span></div>`;
   document.body.appendChild(box);requestAnimationFrame(()=>box.classList.add('show'));
   setTimeout(()=>box.classList.add('leaving'),1550);
   setTimeout(()=>box.remove(),1950);
 }
 function goal(id){return (data.savings?.goals||[]).find(g=>String(g.id)===String(id))}
 function account(id){return (data.accounts||[]).find(a=>String(a.id)===String(id))}
 function opts(selected='',rows=data.accounts||[]){return rows.map(a=>`<option value="${a.id}" ${String(a.id)===String(selected)?'selected':''}>${esc(a.icon||'🏦')} ${esc(a.name)}</option>`).join('')}
 function custodyMode(){return $('input[name="custody_mode"]:checked')?.value||'same'}
 function selectedGoalName(){const id=Number($('#savingGoal')?.value||0);return id>0?(goal(id)?.name||'Meta'):'Ahorro general'}
 function updateDepositPreview(){
   const from=account($('#savingFrom')?.value),same=custodyMode()==='same',to=same?from:account($('#savingTo')?.value),amount=parseMoneyInput($('#savingsDepositForm [name="amount"]')?.value||0),box=$('#savingsPreview');
   if(!box)return;
   if(!from){box.innerHTML='';return}
   const goalName=selectedGoalName(),destination=to?.name||from.name;
   box.innerHTML=`<span>🔒</span><div><b>${amount>0?money(amount):'Este dinero'} quedará protegido en Fondo Ahorro</b><small>${esc(goalName)} · ${same?'Se queda en '+esc(destination):'Se moverá de '+esc(from.name)+' a '+esc(destination)}. No podrás usarlo en gastos hasta retirarlo.</small></div>`;
   const hint=$('#savingFromHint');if(hint)hint.textContent=`Saldo: ${money(from.balance)} · Libre total para ahorrar: ${money(data.free)}`;const toHint=$('#savingToHint');if(toHint)toHint.textContent=!same&&to?`Saldo actual en destino: ${money(to.balance)}`:'';
 }
 function fillDeposit(goalId='',fromId=''){
   const goals=data.savings?.goals||[],sel=$('#savingGoal');if(!sel)return;
   sel.innerHTML=`<option value="0">🐷 Sin meta · Ahorro general</option>`+goals.map(g=>`<option value="${g.id}" ${String(g.id)===String(goalId)?'selected':''}>🎯 ${esc(g.name)} · ${money(g.saved_amount)} de ${money(g.target_amount)}</option>`).join('');
   if(String(goalId)==='0')sel.value='0';
   const accounts=data.accounts||[];
   $('#savingFrom').innerHTML=opts(fromId||'');if(fromId)$('#savingFrom').value=String(fromId);
   if(!$('#savingFrom').value&&accounts.length)$('#savingFrom').selectedIndex=0;
   $('#savingTo').innerHTML=opts($('#savingFrom').value);
   if($('#savingFrom').value)$('#savingTo').value=$('#savingFrom').value;
   const sameRadio=$('input[name="custody_mode"][value="same"]');if(sameRadio)sameRadio.checked=true;
   $('#savingDestinationWrap').hidden=true;
   $('#savingsDepositForm [name="occurred_at"]').value=nowLocal();
   $('#savingsDepositForm [name="amount"]').value='';
   updateDepositPreview();
 }
 function openDeposit(goalId='',fromId=''){fillDeposit(goalId,fromId);show(dep);setTimeout(()=>$('#savingsDepositForm [name="amount"]')?.focus(),80)}
 $('#openSavingsDeposit')?.addEventListener('click',()=>openDeposit());
 $('#openSavingsDepositInline')?.addEventListener('click',()=>openDeposit());
 $$('[data-save-to-goal]').forEach(b=>b.addEventListener('click',()=>openDeposit(b.dataset.saveToGoal)));
 $('#savingGoal')?.addEventListener('change',updateDepositPreview);
 $('#savingFrom')?.addEventListener('change',()=>{if(custodyMode()==='same')$('#savingTo').value=$('#savingFrom').value;updateDepositPreview()});
 $('#savingTo')?.addEventListener('change',updateDepositPreview);
 $('#savingsDepositForm [name="amount"]')?.addEventListener('input',updateDepositPreview);
 $$('input[name="custody_mode"]').forEach(r=>r.addEventListener('change',()=>{
   const same=custodyMode()==='same';$('#savingDestinationWrap').hidden=same;
   if(same){$('#savingTo').value=$('#savingFrom').value}else{
     const alt=[...$('#savingTo').options].find(o=>o.value!==$('#savingFrom').value);if(alt)$('#savingTo').value=alt.value;
   }
   updateDepositPreview();
 }));
 $$('[data-savings-close]').forEach(b=>b.onclick=()=>hide(dep));dep?.addEventListener('click',e=>{if(e.target===dep)hide(dep)});
 $('#savingsDepositForm')?.addEventListener('submit',async e=>{
   e.preventDefault();const b=e.submitter,d=Object.fromEntries(new FormData(e.target).entries());d.amount=moneyFieldRaw(e.target.querySelector('[name="amount"]'));
   d.goal_id=Number(d.goal_id||0);d.to_account_id=custodyMode()==='same'?d.from_account_id:$('#savingTo').value;
   b.disabled=true;b.textContent='Guardando...';
   try{
     const result=await post('savings_move.php',d);
     const goalName=selectedGoalName();
     hide(dep);
     celebrateSavings(Number(result.amount||d.amount||0),goalName,Number(result.protected_total||0));
     setTimeout(()=>location.reload(),1850);
   }catch(err){appNotice(err.message,'error')}finally{b.disabled=false;b.textContent='🔒 Guardar en Ahorro'}
 });

 function generalGoal(){return {id:0,name:'Ahorro general',saved_amount:Number(data.savings?.general_saved||0),account_breakdown:data.savings?.general_account_breakdown||[]}}
 function withdrawalAccountRows(g){return (g?.account_breakdown||[]).filter(a=>a.id&&Number(a.amount)>0.005).map(a=>({id:a.id,name:a.name,icon:a.icon,balance:a.amount}))}
 function openWithdraw(goalId){
   const g=Number(goalId)===0?generalGoal():goal(goalId),held=withdrawalAccountRows(g);if(!g||!held.length)return;
   $('#withdrawGoal').value=String(goalId);$('#withdrawFrom').innerHTML=opts('',held);$('#withdrawTo').innerHTML=opts(held[0]?.id||'',data.accounts||[]);if(held.length)$('#withdrawFrom').selectedIndex=0;
   if(held[0]?.id&&[...$('#withdrawTo').options].some(o=>o.value===String(held[0].id)))$('#withdrawTo').value=String(held[0].id);
   $('#savingsWithdrawForm [name="occurred_at"]').value=nowLocal();$('#savingsWithdrawForm [name="amount"]').value='';clearModalFeedback(withM);show(withM);setTimeout(()=>$('#savingsWithdrawForm [name="amount"]')?.focus(),80)
 }
 $$('[data-withdraw-goal]').forEach(b=>b.addEventListener('click',()=>openWithdraw(b.dataset.withdrawGoal)));
 $$('[data-withdraw-general]').forEach(b=>b.addEventListener('click',()=>openWithdraw(0)));
 $$('[data-savings-withdraw-close]').forEach(b=>b.onclick=()=>hide(withM));withM?.addEventListener('click',e=>{if(e.target===withM)hide(withM)});
 $('#savingsWithdrawForm')?.querySelectorAll('input,select').forEach(el=>el.addEventListener('input',()=>clearModalFeedback(withM)));
 $('#savingsWithdrawForm')?.querySelectorAll('select').forEach(el=>el.addEventListener('change',()=>clearModalFeedback(withM)));
 $('#savingsWithdrawForm')?.addEventListener('submit',async e=>{
   e.preventDefault();clearModalFeedback(withM);
   const form=e.target,b=e.submitter||form.querySelector('button[type="submit"]'),d=Object.fromEntries(new FormData(form).entries());
   d.amount=moneyFieldRaw(form.querySelector('[name="amount"]'));
   if(b){b.disabled=true;b.textContent='Liberando...'}
   try{
     const result=await post('savings_withdraw.php',d);
     hide(withM);appNotice(result.message||`Liberaste ${money(d.amount)} del Ahorro`,'ok');
     setTimeout(()=>location.reload(),750);
   }catch(err){
     modalFeedback(withM,err.message,'error');appNotice(err.message,'error');
   }finally{if(b){b.disabled=false;b.textContent='🔓 Retirar del Ahorro'}}
 });

 $$('[data-edit-goal]').forEach(b=>b.addEventListener('click',()=>{$('#editSavingGoal').value=b.dataset.editGoal;moneyFieldSet($('#editSavingTarget'),b.dataset.target);$('#editSavingPeriod').value=b.dataset.period;show(edit)}));
 $$('[data-savings-edit-close]').forEach(b=>b.onclick=()=>hide(edit));edit?.addEventListener('click',e=>{if(e.target===edit)hide(edit)});
 $('#savingsEditForm')?.addEventListener('submit',async e=>{e.preventDefault();const b=e.submitter,d=Object.fromEntries(new FormData(e.target).entries());d.target_amount=moneyFieldRaw($('#editSavingTarget'));b.disabled=true;b.textContent='Guardando...';try{await post('savings_goal_save.php',d);location.reload()}catch(err){appNotice(err.message,'error')}finally{b.disabled=false;b.textContent='Guardar meta'}});

 bindMoneyFields();
 const q=new URLSearchParams(location.search);if(q.get('action')==='deposit'){
   const goalFromUrl=q.get('goal')||'',fromFromUrl=q.get('from')||'';
   const cleanUrl=new URL(location.href);cleanUrl.searchParams.delete('action');cleanUrl.searchParams.delete('goal');cleanUrl.searchParams.delete('from');
   history.replaceState({},document.title,cleanUrl.pathname+(cleanUrl.search||'')+(cleanUrl.hash||''));
   setTimeout(()=>openDeposit(goalFromUrl,fromFromUrl),100)
 }
})();
