(() => {
  function initPlanner({root=document}={}) {
    const cfg=window.MiDineroPlannerData||{};
    const $=s=>root.querySelector(s)||document.querySelector(s);
    const form=$('#purchasePlannerForm'),amount=$('#plannerAmount'),name=$('#plannerName'),account=$('#plannerAccount'),include=$('#plannerIncludePending');
    const money=n=>'S/ '+Number(n||0).toLocaleString('es-PE',{minimumFractionDigits:2,maximumFractionDigits:2});
    const parseMoney=v=>{let s=String(v??'').trim().replace(/\s/g,'').replace(/S\/?/gi,'').replace(/[^0-9,.-]/g,'');if(!s)return 0;const c=s.lastIndexOf(','),d=s.lastIndexOf('.'),i=Math.max(c,d);if(i>=0)s=s.slice(0,i).replace(/[.,]/g,'')+'.'+s.slice(i+1).replace(/[.,]/g,'');return Number(s)||0};
    let last=null;
    const histKey='midinero_purchase_scenarios_v1';
    function calc(){
      const purchase=Math.max(0,parseMoney(amount?.value));if(!purchase)return null;
      const total=Number(cfg.total_cash||0),pending=include?.checked?Number(cfg.pending||0):0,afterPurchase=total-purchase,afterAll=afterPurchase-pending;
      const selected=account?.selectedOptions?.[0];const accountId=Number(account?.value||0);const accountSpendable=accountId?Number(selected?.dataset.spendable||0):null;const accountAfter=accountId?accountSpendable-purchase:null;
      let risk='Cómodo',riskClass='safe';
      const threshold=Number(cfg.threshold||1000);
      if(afterAll<0){risk='No alcanza';riskClass='danger'}else if(afterAll<threshold){risk='Muy ajustado';riskClass='warning'}else if(afterAll<threshold*2){risk='Ajustado';riskClass='caution'}
      if(accountId&&accountAfter<0){risk='No alcanza en esa cuenta';riskClass='danger'}
      return {title:(name?.value||'Compra simulada').trim()||'Compra simulada',purchase,total,pending,afterPurchase,afterAll,accountId,accountName:selected?.dataset.name||'',accountSpendable,accountAfter,risk,riskClass,createdAt:new Date().toISOString()};
    }
    function render(data){
      if(!data)return;last=data;$('.planner-result-empty').hidden=true;$('.planner-result-content').hidden=false;$('#plannerResultName').textContent=data.title;$('#plannerCurrent').textContent=money(data.total);$('#plannerAfterPurchase').textContent=money(data.afterPurchase);$('#plannerAfterAll').textContent=money(data.afterAll);$('#plannerPendingNote').textContent=data.pending>0?`Incluye S/ ${data.pending.toLocaleString('es-PE',{minimumFractionDigits:2,maximumFractionDigits:2})} pendientes.`:'Sin considerar compromisos pendientes.';
      const risk=$('#plannerRisk');risk.textContent=data.risk;risk.className='planner-risk '+data.riskClass;
      const acc=$('#plannerAccountImpact');if(data.accountId){acc.hidden=false;$('#plannerAccountText').textContent=`${data.accountName}: ${money(data.accountSpendable)} → ${money(data.accountAfter)}`;}else acc.hidden=true;
      let text='';if(data.accountId&&data.accountAfter<0)text=`Esta compra supera el dinero disponible en ${data.accountName}. Te faltarían ${money(Math.abs(data.accountAfter))} en esa cuenta.`;else if(data.afterAll<0)text=`Después de esta compra y de tus compromisos actuales te faltarían ${money(Math.abs(data.afterAll))}.`;else if(data.afterAll<Number(cfg.threshold||1000))text=`La compra es posible con el saldo total, pero te dejaría con solo ${money(data.afterAll)} después de tus compromisos.`;else text=`Después de comprar y cubrir tus compromisos todavía quedarían ${money(data.afterAll)} disponibles.`;$('#plannerExplanation').textContent=text;
    }
    function format(){if(amount&&amount.value.trim())amount.value=parseMoney(amount.value).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}
    if(amount){amount.addEventListener('input',()=>{let v=amount.value.replace(/[^0-9.,]/g,''),i=Math.max(v.lastIndexOf('.'),v.lastIndexOf(','));if(i>=0)v=(v.slice(0,i).replace(/[.,]/g,'')||'0')+'.'+v.slice(i+1).replace(/[.,]/g,'').slice(0,2);else v=v.replace(/[.,]/g,'');amount.value=v});amount.addEventListener('blur',format)}
    form?.addEventListener('submit',e=>{e.preventDefault();const d=calc();if(!d){amount?.focus();return}format();render(d)});
    [account,include].forEach(el=>el?.addEventListener('change',()=>{if(last){const d=calc();if(d)render(d)}}));
    function readHistory(){try{return JSON.parse(localStorage.getItem(histKey)||'[]')||[]}catch{return[]}}
    function renderHistory(){const rows=readHistory(),card=$('#plannerHistoryCard'),box=$('#plannerHistory');if(!rows.length){card.hidden=true;return}card.hidden=false;box.innerHTML=rows.map((r,i)=>`<button type="button" data-planner-history="${i}"><span><b>${String(r.title).replace(/[&<>]/g,'')}</b><small>${new Date(r.createdAt).toLocaleDateString('es-PE')} · Compra ${money(r.purchase)}</small></span><strong>${money(r.afterAll)}<small>quedaría</small></strong></button>`).join('');box.querySelectorAll('[data-planner-history]').forEach(btn=>btn.onclick=()=>{const r=rows[Number(btn.dataset.plannerHistory)];if(!r)return;name.value=r.title;amount.value=Number(r.purchase).toFixed(2);format();render(calc()||r);window.scrollTo({top:0,behavior:'smooth'})})}
    $('#plannerSaveScenario')?.addEventListener('click',()=>{if(!last)return;const rows=readHistory();rows.unshift(last);localStorage.setItem(histKey,JSON.stringify(rows.slice(0,6)));renderHistory();const b=$('#plannerSaveScenario');b.textContent='✓ Guardado';setTimeout(()=>b.textContent='Guardar simulación',1400)});
    $('#plannerClearHistory')?.addEventListener('click',()=>{localStorage.removeItem(histKey);renderHistory()});
    renderHistory();
    return {refresh:()=>window.MiDinero.softRefresh({preserveScroll:true,noFallback:true})};
  }
  window.MiDineroRegister?.('planificador',initPlanner);
})();
