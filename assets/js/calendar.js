(() => {
  function initCalendar({root=document}={}) {
    const cfg=window.MiDineroCalendarData||{};
    const $=s=>root.querySelector(s)||document.querySelector(s);
    const modal=$('#calendarPayModal'),form=$('#calendarPayForm');
    let current=null;
    const money=n=>'S/ '+Number(n||0).toLocaleString('es-PE',{minimumFractionDigits:2,maximumFractionDigits:2});
    const parseMoney=v=>{let s=String(v??'').trim().replace(/\s/g,'').replace(/S\/?/gi,'').replace(/[^0-9,.-]/g,'');if(!s)return 0;const c=s.lastIndexOf(','),d=s.lastIndexOf('.'),i=Math.max(c,d);if(i>=0){s=s.slice(0,i).replace(/[.,]/g,'')+'.'+s.slice(i+1).replace(/[.,]/g,'')}return Number(s)||0};
    const formatInput=el=>{if(el&&el.value.trim())el.value=parseMoney(el.value).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})};
    const esc=v=>String(v??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
    function notice(msg,type='ok'){if(window.finNotice)return finNotice(msg,type==='error'?'error':'success');let n=document.createElement('div');n.className='calendar-toast '+type;n.textContent=msg;document.body.appendChild(n);setTimeout(()=>n.classList.add('show'));setTimeout(()=>{n.classList.remove('show');setTimeout(()=>n.remove(),200)},2600)}
    function fillChoices(){
      const a=$('#calendarPayAccount'),f=$('#calendarPayFund');if(!a||!f)return;
      const prior=localStorage.getItem('fin_last_account')||'';
      a.innerHTML=(cfg.accounts||[]).map(x=>`<option value="${x.id}" data-spendable="${Number(x.spendable_balance||x.balance||0)}">${esc(x.icon||'🏦')} ${esc(x.name)} · ${money(x.spendable_balance||x.balance||0)}</option>`).join('');
      if(prior&&[...a.options].some(o=>o.value===prior))a.value=prior;
      f.innerHTML='<option value="">No, dinero libre</option>'+(cfg.funds||[]).filter(x=>Number(x.available)>0.005).map(x=>`<option value="${x.id}" data-available="${Number(x.available||0)}">${esc(x.icon||'◎')} ${esc(x.name)} · ${money(x.available)}</option>`).join('');
      updateHints();
    }
    function updateHints(){const a=$('#calendarPayAccount'),f=$('#calendarPayFund');const ao=a?.selectedOptions?.[0],fo=f?.selectedOptions?.[0];if($('#calendarPayAccountHint'))$('#calendarPayAccountHint').textContent=ao?`Disponible: ${money(ao.dataset.spendable)}`:'';if($('#calendarPayFundHint'))$('#calendarPayFundHint').textContent=fo&&fo.value?`Disponible en fondo: ${money(fo.dataset.available)}`:'Se descontará directamente de la cuenta.';}
    function open(btn){
      current={id:Number(btn.dataset.id),name:btn.dataset.name||'',due:btn.dataset.due||'',amount:Number(btn.dataset.amount||0),paid:Number(btn.dataset.paid||0),remaining:Number(btn.dataset.remaining||0)};
      fillChoices();$('#calendarPayId').value=current.id;$('#calendarPayTitle').textContent=current.name;$('#calendarPayMeta').textContent=`Vence ${new Date(current.due+'T12:00:00').toLocaleDateString('es-PE',{day:'2-digit',month:'long',year:'numeric'})}`;$('#calendarPayReference').textContent=money(current.amount);$('#calendarPayPaid').textContent=money(current.paid);$('#calendarPayRemaining').textContent=money(current.remaining);$('#calendarPayAmount').value=current.remaining.toFixed(2);formatInput($('#calendarPayAmount'));modal.classList.add('show');modal.setAttribute('aria-hidden','false');document.body.classList.add('modal-open');setTimeout(()=>$('#calendarPayAmount')?.focus(),80);
    }
    function close(){modal?.classList.remove('show');modal?.setAttribute('aria-hidden','true');document.body.classList.remove('modal-open');current=null;}
    root.querySelectorAll('[data-calendar-pay]').forEach(btn=>btn.addEventListener('click',()=>open(btn)));
    document.querySelectorAll('[data-calendar-pay-close]').forEach(btn=>btn.addEventListener('click',close));
    $('#calendarPayAccount')?.addEventListener('change',updateHints);$('#calendarPayFund')?.addEventListener('change',updateHints);
    const amt=$('#calendarPayAmount');if(amt){amt.addEventListener('input',()=>{let v=amt.value.replace(/[^0-9.,]/g,'');const sep=Math.max(v.lastIndexOf('.'),v.lastIndexOf(','));if(sep>=0){const left=v.slice(0,sep).replace(/[.,]/g,''),right=v.slice(sep+1).replace(/[.,]/g,'').slice(0,2);v=(left||'0')+'.'+right}else v=v.replace(/[.,]/g,'');amt.value=v});amt.addEventListener('blur',()=>formatInput(amt));}
    form?.addEventListener('submit',async e=>{e.preventDefault();if(!current)return;const submit=$('#calendarPaySubmit'),amount=parseMoney(amt.value);if(amount<=0){notice('Ingresa un monto mayor a cero.','error');return}if(amount>current.remaining+0.005){notice(`El máximo pendiente es ${money(current.remaining)}.`,'error');return}submit.disabled=true;submit.textContent='Registrando…';try{const headers=window.MiDinero?.clientHeaders({'Content-Type':'application/json','X-CSRF-Token':APP.csrf})||{'Content-Type':'application/json','X-CSRF-Token':APP.csrf};const r=await fetch(`${APP.apiBase}/payment_mark.php`,{method:'POST',headers,body:JSON.stringify({id:current.id,amount,account_id:$('#calendarPayAccount').value,fund_id:$('#calendarPayFund').value,payment_method:$('#calendarPayMethod').value})});const j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.message||'No se pudo registrar el pago');localStorage.setItem('fin_last_account',$('#calendarPayAccount').value);close();notice(j.status==='partial'?`Abono registrado. Falta ${money(j.remaining_amount)}.`:'Pago completado.');await window.MiDinero.softRefresh({preserveScroll:true});}catch(err){notice(err.message,'error')}finally{submit.disabled=false;submit.textContent='✓ Registrar pago'}});
    if(cfg.autoPay){const btn=root.querySelector(`[data-calendar-pay][data-id="${cfg.autoPay}"]`);if(btn)setTimeout(()=>open(btn),120);}
    return {refresh:()=>window.MiDinero.softRefresh({preserveScroll:true,noFallback:true}),destroy:()=>close()};
  }
  window.MiDineroRegister?.('calendario',initCalendar);
})();
