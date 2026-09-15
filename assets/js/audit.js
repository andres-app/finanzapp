(() => {
  function notice(message, type='ok') {
    let el=document.getElementById('auditToast');
    if(!el){el=document.createElement('div');el.id='auditToast';el.className='audit-toast';document.body.appendChild(el);}
    el.textContent=message;el.className='audit-toast show '+(type==='error'?'error':'ok');
    clearTimeout(el._timer);el._timer=setTimeout(()=>el.classList.remove('show'),2600);
  }
  async function post(file,data,signal){
    const headers=window.MiDinero?.clientHeaders({'Content-Type':'application/json','X-CSRF-Token':APP.csrf})||{'Content-Type':'application/json','X-CSRF-Token':APP.csrf};
    const r=await fetch(`${APP.apiBase}/${file}`,{method:'POST',headers,body:JSON.stringify(data),credentials:'same-origin',signal});
    let j={};try{j=await r.json()}catch(_){throw new Error('Respuesta inválida del servidor.');}
    if(!r.ok||j.ok===false)throw new Error(j.message||'No se pudo completar la operación.');return j;
  }
  function bindSpaFilters(root,signal){
    root.querySelectorAll('[data-spa-filter]').forEach(form=>{
      const go=()=>{const fd=new FormData(form),u=new URL(form.action,location.href);for(const [k,v] of fd.entries()){if(String(v)!=='')u.searchParams.set(k,String(v));else u.searchParams.delete(k);}window.MiDinero.softNavigate(u.href,{push:true});};
      form.addEventListener('submit',e=>{e.preventDefault();go()},{signal});
      form.querySelectorAll('input[type="month"],select').forEach(el=>el.addEventListener('change',go,{signal}));
    });
  }
  function initActivity({root}){
    const ac=new AbortController(),signal=ac.signal;bindSpaFilters(root,signal);
    const modal=document.getElementById('auditRevertModal'),form=document.getElementById('auditRevertForm'),id=document.getElementById('auditRevertId'),title=document.getElementById('auditRevertTitle');
    const close=()=>{modal?.classList.remove('show');modal?.setAttribute('aria-hidden','true');document.body.classList.remove('modal-open');};
    root.querySelectorAll('[data-audit-revert]').forEach(btn=>btn.addEventListener('click',()=>{id.value=btn.dataset.auditRevert||'';title.textContent=`${btn.dataset.auditTitle||'Esta operación'} dejará de afectar tus saldos, pero seguirá visible en la auditoría.`;modal.classList.add('show');modal.setAttribute('aria-hidden','false');document.body.classList.add('modal-open');modal.querySelector('textarea')?.focus();},{signal}));
    document.querySelectorAll('[data-audit-close]').forEach(btn=>btn.addEventListener('click',close,{signal}));
    modal?.addEventListener('click',e=>{if(e.target===modal)close()},{signal});
    form?.addEventListener('submit',async e=>{e.preventDefault();const submit=form.querySelector('button[type="submit"]');submit.disabled=true;submit.textContent='Anulando…';try{const data=Object.fromEntries(new FormData(form).entries());const j=await post('audit_revert.php',data,signal);close();notice(j.message||'Operación anulada.');await window.MiDinero.softRefresh({preserveScroll:true,noFallback:true});}catch(err){notice(err.message,'error');}finally{submit.disabled=false;submit.textContent='Anular operación';}},{signal});
    return {refresh:()=>window.MiDinero.softRefresh({preserveScroll:true,noFallback:true}),destroy:()=>ac.abort()};
  }
  function initClose({root}){
    const ac=new AbortController(),signal=ac.signal;bindSpaFilters(root,signal);
    const modal=document.getElementById('monthActionModal'),form=document.getElementById('monthActionForm'),type=document.getElementById('monthActionType'),period=document.getElementById('monthActionPeriod'),ttl=document.getElementById('monthActionTitle'),txt=document.getElementById('monthActionText'),notes=document.getElementById('monthNotesLabel'),submit=document.getElementById('monthActionSubmit');
    const close=()=>{modal?.classList.remove('show');modal?.setAttribute('aria-hidden','true');document.body.classList.remove('modal-open');};
    root.querySelectorAll('[data-month-action]').forEach(btn=>btn.addEventListener('click',()=>{const action=btn.dataset.monthAction,per=btn.dataset.period;type.value=action;period.value=per;if(action==='reopen'){ttl.textContent='Reabrir mes';txt.textContent='Podrás realizar correcciones y volver a cerrar el mes. La fotografía anterior permanecerá en el historial hasta el próximo cierre.';notes.style.display='none';submit.textContent='Reabrir mes';submit.className='btn';}else{ttl.textContent='Cerrar '+per;txt.textContent='Se guardará una fotografía de ingresos, gastos, cuentas y compromisos. Los datos actuales no se modificarán.';notes.style.display='grid';submit.textContent='Cerrar mes';submit.className='btn primary';}modal.classList.add('show');modal.setAttribute('aria-hidden','false');document.body.classList.add('modal-open');},{signal}));
    document.querySelectorAll('[data-month-close]').forEach(btn=>btn.addEventListener('click',close,{signal}));
    modal?.addEventListener('click',e=>{if(e.target===modal)close()},{signal});
    form?.addEventListener('submit',async e=>{e.preventDefault();submit.disabled=true;const old=submit.textContent;submit.textContent='Procesando…';try{const data=Object.fromEntries(new FormData(form).entries());const j=await post('month_close.php',data,signal);close();notice(j.message||'Cierre actualizado.');await window.MiDinero.softRefresh({preserveScroll:false,noFallback:true});}catch(err){notice(err.message,'error');}finally{submit.disabled=false;submit.textContent=old;}},{signal});
    return {refresh:()=>window.MiDinero.softRefresh({preserveScroll:true,noFallback:true}),destroy:()=>ac.abort()};
  }
  window.MiDineroRegister?.('actividad',initActivity);
  window.MiDineroRegister?.('cierre',initClose);
})();
