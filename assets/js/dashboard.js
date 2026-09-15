window.MiDineroRegister('dashboard', () => {
  const pageAbort = new AbortController();
  const $ = s => document.querySelector(s);
  const $$ = s => [...document.querySelectorAll(s)];
  const money = n => 'S/ ' + Number(n || 0).toLocaleString('es-PE',{minimumFractionDigits:2,maximumFractionDigits:2});
  const setText = (sel,value) => { const el=$(sel); if(el) el.textContent=value; };

  // Animación del monto principal del dashboard.
  // En la primera carga parte de 0; en actualizaciones realtime parte del valor visible actual.
  function animateMoney(sel,target,{duration=900}={}){
    const el=$(sel);
    if(!el)return;
    const end=Number(target||0);
    const reduced=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if(el._moneyAnimation)cancelAnimationFrame(el._moneyAnimation);
    const stored=Number(el.dataset.moneyValue);
    const start=Number.isFinite(stored)?stored:0;
    if(reduced||Math.abs(end-start)<0.005){
      el.textContent=money(end);
      el.dataset.moneyValue=String(end);
      el.classList.remove('is-counting');
      return;
    }
    const t0=performance.now();
    const ease=t=>1-Math.pow(1-t,4);
    el.classList.add('is-counting');
    const frame=now=>{
      const t=Math.min(1,(now-t0)/duration);
      const value=start+(end-start)*ease(t);
      el.textContent=money(value);
      if(t<1){
        el._moneyAnimation=requestAnimationFrame(frame);
      }else{
        el.textContent=money(end);
        el.dataset.moneyValue=String(end);
        el.classList.remove('is-counting');
        el._moneyAnimation=null;
      }
    };
    el._moneyAnimation=requestAnimationFrame(frame);
  }
  const api = f => `${APP.apiBase}/${f}`;
  let chart=null;
  let config=window.FINANCE_FORM_DATA||{categories:[],concepts:[],accounts:[],funds:[],income_defaults:[]};
  let currentPayment=null, currentData=null, loading=false, queued=false, timer=null, toastTimer=null;

  async function json(url,opt={}){
    const options={...opt,signal:opt?.signal||pageAbort.signal};
    const method=String(options.method||'GET').toUpperCase();
    if(method!=='GET'&&method!=='HEAD') options.headers=window.MiDinero?.clientHeaders(options.headers||{})||options.headers;
    const r=await fetch(url,options),j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.message||'No se pudo completar la acción');return j;
  }
  function esc(v){return String(v??'').replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll("'",'&#39;').replaceAll('<','&lt;').replaceAll('>','&gt;');}
  function safeColor(v,fallback='#6b7280'){return /^#[0-9a-f]{6}$/i.test(String(v||''))?String(v):fallback;}
  function periodLabel(p){const [y,m]=p.split('-');const n=['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];return `${n[Number(m)]} ${y}`;}
  function nowLocal(){return new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,16);}
  function toast(message,type='ok'){const el=$('#quickToast');if(!el)return;clearTimeout(toastTimer);el.className=`quick-toast show ${type}`;el.textContent=message;toastTimer=setTimeout(()=>el.classList.remove('show'),2800);}
  function typeCats(type){return (config.categories||[]).filter(c=>String(c.type)===type);}
  function conceptsFor(type){const ids=new Set(typeCats(type).map(c=>String(c.id)));return (config.concepts||[]).filter(c=>ids.has(String(c.category_id)));}
  function accountLabel(a){return `${a.icon||'🏦'} ${a.name}`;}
  function accountSpendable(a){return Number(('spendable_balance' in a)?a.spendable_balance:a.balance||0);}
  function accountProtected(a){return Math.max(0,Number(a.savings_reserved||0));}
  function fundLabel(f){return `${f.icon||'💰'} ${f.name}`;}

  // Campos monetarios: mientras escribes NO se agregan ceros ni separadores.
  // Se permite 113.50 (o 113,50) de forma natural y solo se formatea al salir del campo.
  function parseMoneyInput(value){
    let v=String(value??'').trim().replace(/\s/g,'').replace(/S\/?/gi,'').replace(/[^0-9,.-]/g,'');
    if(!v)return 0;
    const neg=v.startsWith('-');v=v.replace(/-/g,'');
    const lastDot=v.lastIndexOf('.'),lastComma=v.lastIndexOf(',');
    if(lastDot>=0&&lastComma>=0){
      const dec=Math.max(lastDot,lastComma);
      v=v.slice(0,dec).replace(/[.,]/g,'')+'.'+v.slice(dec+1).replace(/[.,]/g,'');
    }else if(lastComma>=0){
      const parts=v.split(','),right=parts.at(-1).length;
      if(parts.length>2){v=right<=2?parts.slice(0,-1).join('')+'.'+parts.at(-1):parts.join('');}
      else v=right<=2?v.replace(',','.'):v.replace(/,/g,'');
    }else if(lastDot>=0){
      const parts=v.split('.'),right=parts.at(-1).length;
      if(parts.length>2)v=right<=2?parts.slice(0,-1).join('')+'.'+parts.at(-1):parts.join('');
      // Con un solo punto lo tratamos siempre como decimal. Así 113.500 no salta a 113,500.
    }
    const n=Number(v);return Number.isFinite(n)?(neg?-n:n):0;
  }
  function moneyFieldRaw(el){return parseMoneyInput(el?.value).toFixed(2);}
  function moneyFieldSet(el,value){
    if(!el)return;
    if(value===''){el.value='';return;}
    const n=Number(value||0);
    el.value=Number.isFinite(n)?n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}):'';
  }
  function moneyFieldEdit(el){
    if(!el||!el.value.trim())return;
    const n=parseMoneyInput(el.value);
    el.value=Number.isFinite(n)?n.toFixed(2):'';
  }
  function formatMoneyField(el){if(!el||!el.value.trim())return;moneyFieldSet(el,parseMoneyInput(el.value));}
  function normalizeMoneyTyping(raw){
    raw=String(raw??'').replace(/S\/?/gi,'').replace(/\s/g,'');
    let out='',decimal=false,decimals=0;
    for(const ch of raw){
      if(/\d/.test(ch)){
        if(decimal){if(decimals>=2)continue;decimals++;}
        out+=ch;
      }else if((ch==='.'||ch===',')&&!decimal){
        if(!out)out='0';out+='.';decimal=true;
      }
    }
    return out;
  }
  function sanitizeMoneyTyping(el){
    if(!el||el.dataset.moneyFormatting==='1')return;
    const raw=el.value,pos=el.selectionStart??raw.length;
    const before=normalizeMoneyTyping(raw.slice(0,pos)),clean=normalizeMoneyTyping(raw);
    if(raw===clean)return;
    el.dataset.moneyFormatting='1';el.value=clean;
    const next=Math.min(clean.length,before.length);
    requestAnimationFrame(()=>{try{el.setSelectionRange(next,next)}catch{}delete el.dataset.moneyFormatting;});
  }
  function bindMoneyFields(root=document){root.querySelectorAll?.('[data-money-input]').forEach(el=>{
    if(el.dataset.moneyBound)return;el.dataset.moneyBound='1';
    el.addEventListener('focus',()=>moneyFieldEdit(el));
    el.addEventListener('input',()=>sanitizeMoneyTyping(el));
    el.addEventListener('paste',e=>{
      const text=e.clipboardData?.getData('text');if(text==null)return;
      e.preventDefault();const n=parseMoneyInput(text);el.value=Number.isFinite(n)?n.toFixed(2):'';
      requestAnimationFrame(()=>{try{el.setSelectionRange(el.value.length,el.value.length)}catch{}});
    });
    el.addEventListener('blur',()=>formatMoneyField(el));
  });}

  async function loadConfig(){
    try{const fresh=await json(api('config.php'));if(pageAbort.signal.aborted)return;if(fresh&&Array.isArray(fresh.categories)){config=fresh;populateQuickForms();renderPayOptions();}}
    catch(err){if(err?.name==='AbortError')return;console.warn('No se pudo refrescar configuración:',err);populateQuickForms();renderPayOptions();}
  }

  async function load(){
    if(pageAbort.signal.aborted)return;
    if(loading){queued=true;return}
    loading=true;
    try{const periodEl=$('#period');if(!periodEl)return;const p=periodEl.value;const j=await json(api('dashboard.php')+'?period='+encodeURIComponent(p));if(pageAbort.signal.aborted)return;currentData=j.data;render(j.data);}
    catch(err){if(err?.name!=='AbortError')throw err;}
    finally{loading=false;if(queued&&!pageAbort.signal.aborted){queued=false;load();}}
  }

  function render(d){
    const s=d.summary;
    setText('#periodLabel',periodLabel(d.period));
    const availableInAccounts=Number(s.available_in_accounts??s.total_cash??0);
    setText('#reservedTotal',money(s.operational_reserved??s.reserved));setText('#pendingHero',money(s.pending_total));animateMoney('#freeToSpend',availableInAccounts);
    const afterCommitments=Number(s.after_commitments??(availableInAccounts-Number(s.pending_total||0)));
    setText('#afterCommitments',money(afterCommitments));
    const projection=$('#cashProjection');if(projection)projection.classList.toggle('negative',afterCommitments<0);
    const free=$('#freeToSpend');if(free)free.classList.toggle('negative',availableInAccounts<0);
    setText('#cashExplanation',Number(s.pending_total||0)>0
      ? 'Los pagos pendientes todavía no reducen este saldo. Se descontarán cuando los registres como pagados.'
      : 'Este saldo refleja únicamente movimientos ya registrados.');
    setText('#monthIncome',money(s.income));setText('#monthExpense',money(s.expense));setText('#monthNet',money(s.balance));
    const net=$('#monthNet');if(net){net.classList.toggle('negative',Number(s.balance)<0);net.classList.toggle('positive',Number(s.balance)>0);}
    setText('#balanceMini',`Variación ${s.balance>=0?'+':''}${money(s.balance)}`);
    setText('#incomeChange',`${s.income_change>=0?'+':''}${Number(s.income_change).toFixed(1)}% vs. mes anterior`);
    setText('#expenseChange',`${s.expense_change>=0?'+':''}${Number(s.expense_change).toFixed(1)}% vs. mes anterior`);
    setText('#quickUnallocated',money(s.unallocated));
    renderSavings(d.savings||{});renderFunds((d.funds||[]).filter(f=>!f.savings_goal_id));renderPending(d.pending_current||d.pending);renderAnt(d.ant_expenses,s.ant,s.ant_projected);renderRecent(d.recent||[]);
    try{renderChart(d.daily);}catch(err){console.error('No se pudo renderizar el gráfico:',err);}
  }

  function renderChart(rows){
    const p=$('#period').value,[year,month]=p.split('-').map(Number),today=new Date(),daysInMonth=new Date(year,month,0).getDate();
    const isCurrent=year===today.getFullYear()&&month===today.getMonth()+1,isFuture=new Date(year,month-1,1)>new Date(today.getFullYear(),today.getMonth(),1);
    const movementDays=rows.map(r=>Number(String(r.d).slice(-2))).filter(Boolean),visibleDays=isCurrent?today.getDate():(isFuture?Math.max(1,...movementDays):daysInMonth);
    const map=new Map(rows.map(r=>[Number(String(r.d).slice(-2)),{income:+r.income||0,expense:+r.expense||0,adjustment:+r.adjustment||0}]));
    const opening=Number(currentData?.summary?.opening_balance||0);
    let running=opening,cumulativeExpense=0;const labels=[],balances=[],expensesAccumulated=[],dailyInfo=[],balancePointRadius=[],expensePointRadius=[];
    for(let day=1;day<=visibleDays;day++){const item=map.get(day)||{income:0,expense:0,adjustment:0};running+=item.income-item.expense+item.adjustment;cumulativeExpense+=item.expense;labels.push(String(day).padStart(2,'0'));balances.push(Number(running.toFixed(2)));expensesAccumulated.push(Number(cumulativeExpense.toFixed(2)));dailyInfo.push(item);balancePointRadius.push(item.income||item.expense||item.adjustment?4:0);expensePointRadius.push(item.expense?4:0);}
    const totalIncome=dailyInfo.reduce((a,r)=>a+r.income,0),totalExpense=dailyInfo.reduce((a,r)=>a+r.expense,0),avgExpense=visibleDays?totalExpense/visibleDays:0,availableBase=Math.max(0,opening)+totalIncome,usedPct=availableBase>0?Math.min(999,(totalExpense/availableBase)*100):0,variation=running-opening,minBalance=balances.length?Math.min(...balances):opening;
    const projectedExpense=isCurrent?avgExpense*daysInMonth:(isFuture?0:totalExpense);
    let peakExpense=0,peakDay=null;dailyInfo.forEach((r,i)=>{if(r.expense>peakExpense){peakExpense=r.expense;peakDay=i+1;}});
    setText('#avgDailyExpense',money(avgExpense));
    setText('#avgDailyExpenseHint',isCurrent?`Promedio de los primeros ${visibleDays} día${visibleDays===1?'':'s'}`:`Promedio de ${visibleDays} días`);
    setText('#spentRatio',`${usedPct.toFixed(usedPct<10?1:0)}%`);
    setText('#projectedExpense',money(projectedExpense));
    const projectedHint=$('#projectedExpenseHint');if(projectedHint)projectedHint.textContent=isCurrent?'Si mantienes el ritmo actual':(isFuture?'Aún no inicia este período':'Gasto total del período');
    setText('#peakExpense',money(peakExpense));
    setText('#peakExpenseHint',peakDay?`Día ${String(peakDay).padStart(2,'0')} del mes`:'Sin gastos registrados');
    setText('#balanceMini',`Variación ${variation>=0?'+':''}${money(variation)}`);
    const insight=$('#flowInsight');let text='',state='neutral';
    if(totalIncome===0&&totalExpense===0)text='Aún no hay movimientos en este período. Tu saldo se mantiene igual al inicio del mes.';
    else if(running<=0){text=`Has consumido ${usedPct.toFixed(0)}% del dinero disponible del período y el saldo del flujo llegó a ${money(running)}. Conviene revisar los próximos compromisos.`;state='danger';}
    else if(usedPct>=80){text=`Ya utilizaste ${usedPct.toFixed(0)}% del dinero disponible del período. El saldo más bajo registrado es ${money(minBalance)}.`;state='warning';}
    else if(variation<0){text=`Tu saldo ha disminuido ${money(Math.abs(variation))} desde que inició el mes. Al ritmo actual, el gasto del mes sería aproximadamente ${money(projectedExpense)}.`;state='warning';}
    else{text=`Tu saldo aumentó ${money(variation)} durante el período. Estás gastando en promedio ${money(avgExpense)} por día${isCurrent?` y proyectas ${money(projectedExpense)} al cierre`:''}.`;state='good';}
    if(insight){insight.className=`flow-insight ${state}`;const ip=insight.querySelector('p');if(ip)ip.textContent=text;}
    const canvas=$('#flowChart');if(chart)chart.destroy();if(!window.Chart||!canvas)return;const ctx=canvas.getContext('2d');const gradient=ctx.createLinearGradient(0,0,0,230);gradient.addColorStop(0,'rgba(89,163,216,.20)');gradient.addColorStop(1,'rgba(89,163,216,0)');
    chart=new Chart(ctx,{type:'line',data:{labels,datasets:[{label:'Saldo acumulado',data:balances,borderColor:'#5da5d8',backgroundColor:gradient,borderWidth:2.5,pointRadius:balancePointRadius,pointHoverRadius:6,pointBackgroundColor:'#fff',pointBorderColor:'#5da5d8',pointBorderWidth:2,tension:.28,fill:true},{label:'Gasto acumulado',data:expensesAccumulated,borderColor:'#f2a24b',backgroundColor:'transparent',borderWidth:2.5,pointRadius:expensePointRadius,pointHoverRadius:6,pointBackgroundColor:'#fff',pointBorderColor:'#f2a24b',pointBorderWidth:2,tension:.25,fill:false}]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{display:false},tooltip:{displayColors:true,callbacks:{title:items=>`Día ${items[0].label}`,label:ctx=>`${ctx.dataset.label}: ${money(ctx.parsed.y)}`,footer:items=>{const idx=items[0]?.dataIndex??0,d=dailyInfo[idx]||{income:0,expense:0,adjustment:0};const lines=[`Ingresos del día: ${money(d.income)}`,`Gastos del día: ${money(d.expense)}`];if(Math.abs(Number(d.adjustment||0))>0.005)lines.push(`Ajuste de saldo: ${d.adjustment>=0?'+':''}${money(d.adjustment)}`);return lines;}}}},scales:{x:{grid:{display:false},border:{display:false},ticks:{maxTicksLimit:10}},y:{grid:{color:'#f0f0ed'},border:{display:false},ticks:{callback:v=>money(v)},beginAtZero:true}}}});
  }

  function renderSavings(s){
    setText('#dashboardSavingsTotal',money(s.total_saved||0));
    setText('#heroSavingsTotal',money(s.total_saved||0));
    const goals=s.goals||[],g=goals[0];
    // La tarjeta habla de una meta concreta, por eso el progreso debe ser
    // el de esa meta y no el agregado de todas las metas.
    const progress=g?Number(g.progress||0):0;
    setText('#dashboardSavingsPct',g?`${progress.toFixed(0)}% de ${g.name}`:'Sin meta activa');
    setText('#dashboardSavingsGoal',g?`${g.name}: faltan ${money(g.remaining_amount)}`:'Crea una meta para empezar a ahorrar');
    setText('#dashboardSavingsAccount',g?(g.account_name?`En ${g.account_name}`:'Elige dónde guardarlo al aportar'):'Sin cuenta definida');
    const bar=$('#dashboardSavingsProgress');if(bar)bar.style.width=Math.min(100,Math.max(0,progress))+'%';
    const btn=$('#dashboardSaveBtn');if(btn)btn.href=goals.length?(APP.routes.ahorro+'?action=deposit'):(APP.routes.configuracion+'/metas');
  }
  function renderFunds(rows){const box=$('#fundCards');if(!box)return;box.innerHTML=rows.length?rows.slice(0,4).map(f=>{const avail=+f.available,target=+f.target_amount,p=target>0?Math.min(100,Math.max(0,avail/target*100)):0;return `<a class="fund-mini-card" href="${APP.routes.fondos}"><span class="fund-mini-icon">${esc(f.icon||'💰')}</span><div><b>${esc(f.name)}</b><strong>${money(avail)}</strong><div class="thin-progress"><i style="width:${p}%;background:${safeColor(f.color)}"></i></div><small>${target>0?`Meta ${money(target)}`:`Disponible ahora`}</small></div></a>`}).join(''):'<div class="empty compact">Crea un fondo para organizar tus gastos.</div>';}
  function renderAccounts(rows){const box=$('#accountCards');if(!box)return;box.innerHTML=rows.length?rows.map(a=>`<a class="account-mini-row" href="${APP.routes.cuentas}"><span>${esc(a.icon||'🏦')}</span><div><b>${esc(a.name)}</b><small>${a.account_type==='bank'?'Banco':a.account_type==='wallet'?'Billetera':a.account_type==='cash'?'Efectivo':'Otra cuenta'}</small></div><strong>${money(a.balance)}</strong></a>`).join(''):'<div class="empty compact">Sin cuentas.</div>';}
  function paymentDueLabel(r){const n=Number(r.days_left);if(n<0)return {text:`Vencido hace ${Math.abs(n)} día${Math.abs(n)===1?'':'s'}`,tone:'overdue'};if(n===0)return {text:'Vence hoy',tone:'today'};if(n===1)return {text:'Vence mañana',tone:'soon'};if(n<=7)return {text:`Vence en ${n} días`,tone:'soon'};return {text:`Vence ${r.due_date.split('-').reverse().join('/')}`,tone:'normal'};}
  function renderPending(rows){
    rows=Array.isArray(rows)?rows:[];
    const total=rows.reduce((a,r)=>a+Number(r.amount||0),0),box=$('#railPendingList');
    setText('#railPendingTotal',money(total));
    if(!box)return;
    if(!rows.length){box.innerHTML='<div class="rail-all-paid"><span>✓</span><b>Todo está pagado</b><small>No tienes pagos fijos vencidos ni pendientes por cubrir.</small></div>';return;}
    const visible=rows.slice(0,4);
    box.innerHTML=visible.map(r=>{const due=paymentDueLabel(r),amount=Number(r.amount||0);return `<article class="rail-payment-item ${due.tone}"><div class="rail-payment-icon">${esc(r.icon||'•')}</div><div class="rail-payment-info"><div class="rail-payment-title"><b>${esc(r.name)}</b><strong>${money(amount)}</strong></div><div class="rail-payment-bottom"><span class="rail-due ${due.tone}">${due.text}</span><button class="rail-pay-btn" type="button" data-pay="${r.id}" data-name="${esc(r.name)}" data-icon="${esc(r.icon||'•')}" data-amount="${r.amount}" data-due="${r.due_date}" data-fund="${r.fund_id||''}">Pagar</button></div></div></article>`}).join('')+(rows.length>4?`<a class="minimal-more-payments" href="${APP.routes.configuracion}/pagos">+ ${rows.length-4} pago${rows.length-4===1?'':'s'} más</a>`:'');
    $$('[data-pay]').forEach(b=>b.onclick=()=>openPay(b.dataset));
  }
  function renderAnt(rows,total,projected){setText('#antTotal',money(total));setText('#antProjection',projected>total?`Proyección ${money(projected)} al cierre`:'Pequeños gastos del mes');const box=$('#antList');if(!box)return;box.innerHTML=rows.length?rows.map(r=>`<div class="task-row"><span class="task-icon">${esc(r.icon||'•')}</span><div class="task-name"><b>${esc(r.name)}</b><small>${r.qty} movimiento${+r.qty!==1?'s':''}</small></div><strong>${money(r.total)}</strong></div>`).join(''):'<div class="empty compact">Sin gastos hormiga este mes.</div>';}
  function renderRecent(rows){
    const box=$('#recentList');if(!box)return;
    box.innerHTML=rows.length?rows.slice(0,6).map(r=>{
      const actor=esc(r.actor_name||'Usuario');
      const when=r.occurred_at?String(r.occurred_at).replace(' ','T'):'';
      let time='';try{time=when?new Intl.DateTimeFormat('es-PE',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}).format(new Date(when)):'';}catch(_){time=String(r.occurred_at||'').slice(0,16);}
      return `<div class="minimal-activity-row"><span class="minimal-activity-icon">${esc(r.icon||'•')}</span><div class="minimal-activity-copy"><div><b>${esc(r.concept||r.category||'Movimiento')}</b><time>${esc(time)}</time></div><p>${actor} · ${esc(r.account||'Cuenta')}${r.fund?' · '+esc(r.fund):''}</p></div><strong class="minimal-activity-amount ${r.type}">${r.type==='income'?'+':'−'} ${money(r.amount)}</strong></div>`;
    }).join(''):'<div class="empty compact">Todavía no hay movimientos registrados.</div>';
  }
  function renderCategories(rows){const total=rows.reduce((a,r)=>a+Number(r.total),0)||1;$('#categoryBars').innerHTML=rows.length?rows.slice(0,4).map(r=>{const p=Math.round(+r.total/total*100);return `<div class="category-row"><span class="category-icon">${esc(r.icon||'•')}</span><div><div><b>${esc(r.name)}</b><strong>${money(r.total)}</strong></div><div class="thin-progress"><i style="width:${p}%;background:${safeColor(r.color)}"></i></div><small>${p}% del gasto</small></div></div>`}).join(''):'<div class="empty compact">Aún no hay egresos.</div>';}

  // ===== Acciones rápidas independientes =====
  const actionModals={expense:$('#expenseModal'),income:$('#incomeModal'),transfer:$('#transferModal'),allocate:$('#allocateModal')};
  const actionForms={expense:$('#expenseForm'),income:$('#incomeForm'),transfer:$('#transferQuickForm'),allocate:$('#allocateQuickForm')};
  const registerMenu=$('#registerMenu'),newTx=$('#newTx');

  function closeRegisterMenu(){if(!registerMenu)return;registerMenu.classList.remove('show');registerMenu.setAttribute('aria-hidden','true');newTx?.setAttribute('aria-expanded','false');}
  function toggleRegisterMenu(){if(!registerMenu)return;const open=!registerMenu.classList.contains('show');registerMenu.classList.toggle('show',open);registerMenu.setAttribute('aria-hidden',open?'false':'true');newTx?.setAttribute('aria-expanded',open?'true':'false');}
  function closeActionModal(action){const modal=actionModals[action];if(!modal)return;modal.classList.remove('show');modal.setAttribute('aria-hidden','true');document.body.classList.remove('modal-open');}
  function closeAllActionModals(){Object.keys(actionModals).forEach(closeActionModal);}
  function openActionModal(action){const modal=actionModals[action];if(!modal)return;populateQuickForms();closeRegisterMenu();closeAllActionModals();modal.classList.add('show');modal.setAttribute('aria-hidden','false');document.body.classList.add('modal-open');setTimeout(()=>actionForms[action]?.querySelector('input[name="amount"]')?.focus(),80);}

  function fillSelect(el,rows,mapper,empty){if(!el)return;const prior=el.value;if(!rows.length){el.innerHTML=`<option value="">${esc(empty)}</option>`;el.disabled=true;return;}el.disabled=false;el.innerHTML=rows.map(mapper).join('');if(prior&&rows.some(r=>String(r.id)===String(prior)))el.value=prior;}
  function selectRememberedAccount(el){const remembered=localStorage.getItem('fin_last_account');if(remembered&&[...el.options].some(o=>o.value===remembered))el.value=remembered;}
  function fillConceptSelect(el,type,preferred=''){if(!el)return;const rows=conceptsFor(type);const cats=typeCats(type);const prior=preferred||el.value;let html='<option value="" disabled>Selecciona un concepto</option>';if(rows.length)html+=rows.map(c=>{const cat=cats.find(x=>String(x.id)===String(c.category_id));return `<option value="${c.id}" data-category="${c.category_id}" data-amount="${c.default_amount??''}">${esc(cat?.icon||'•')} ${esc(c.name)}</option>`}).join('');else html='<option value="" disabled>No tienes conceptos todavía</option>';el.innerHTML=html;el.disabled=false;if(prior&&rows.some(c=>String(c.id)===String(prior)))el.value=String(prior);else el.value='';}
  function fillCategorySelect(el,type){const rows=typeCats(type);fillSelect(el,rows,c=>`<option value="${c.id}">${esc(c.icon||'•')} ${esc(c.name)}</option>`,type==='income'?'Crea una categoría de ingreso':'Crea una categoría de gasto');}
  function renderConceptChips(containerId,type,selectId,categoryId,amountFormId){const box=$(containerId),rows=conceptsFor(type).slice(0,5);if(!box)return;box.innerHTML=rows.map(c=>`<button type="button" data-qconcept="${c.id}" data-qtype="${type}">${esc(c.name)}</button>`).join('');box.querySelectorAll('[data-qconcept]').forEach(btn=>btn.onclick=()=>{const sel=$(selectId);sel.value=btn.dataset.qconcept;syncConcept(type,selectId,categoryId,amountFormId);box.querySelectorAll('button').forEach(x=>x.classList.toggle('active',x===btn));});}
  function renderFundChoices(){const box=$('#fundChoiceGrid');const rows=(config.funds||[]).slice(0,5);box.innerHTML=rows.map(f=>`<button type="button" data-qfund="${f.id}"><span>${esc(f.icon||'💰')}</span><b>${esc(f.name)}</b><small>${money(f.available)} disponible</small></button>`).join('');box.querySelectorAll('[data-qfund]').forEach(btn=>btn.onclick=()=>{$('#allocateFund').value=btn.dataset.qfund;box.querySelectorAll('button').forEach(x=>x.classList.toggle('active',x===btn));});}
  function incomeDefaultForConcept(conceptId){return (config.income_defaults||[]).find(x=>String(x.concept_id)===String(conceptId));}
  function renderIncomeAccountState(manual=false){
    const conceptId=$('#incomeConcept')?.value,account=$('#incomeAccount'),box=$('#incomeAccountAuto'),hint=$('#incomeAccountHint');
    if(!account)return;
    const def=incomeDefaultForConcept(conceptId),selected=(config.accounts||[]).find(a=>String(a.id)===String(account.value));
    if(def&&box){
      const suggested=(config.accounts||[]).find(a=>String(a.id)===String(def.account_id));
      box.hidden=false;
      if(manual&&String(account.value)!==String(def.account_id)){
        box.className='income-account-auto changed';
        box.innerHTML=`<span>↪</span><div><b>Cambiaste la cuenta habitual</b><small>${esc(def.name||'Este ingreso')} normalmente está configurado para ${esc(suggested?.name||def.account_name||'otra cuenta')}.</small></div>`;
      } else {
        box.className='income-account-auto';
        box.innerHTML=`<span>✓</span><div><b>Cuenta elegida automáticamente</b><small>${esc(def.name||'Este ingreso')} → ${esc(suggested?.name||def.account_name||'Cuenta configurada')}</small></div>`;
      }
    } else if(box){box.hidden=true;box.innerHTML='';}
    if(hint){
      if(selected){const spend=('spendable_balance' in selected)?selected.spendable_balance:selected.balance;hint.textContent=`Destino: ${selected.name} · ${money(spend)} disponible antes de recibir.`;}
      else hint.textContent='Este ingreso no tiene una cuenta habitual. Elige dónde ingresó realmente el dinero.';
    }
  }
  function applyIncomeAccountSuggestion(){
    const account=$('#incomeAccount'),conceptId=$('#incomeConcept')?.value;if(!account)return;
    const def=incomeDefaultForConcept(conceptId);
    if(def&&[...account.options].some(o=>String(o.value)===String(def.account_id))){account.value=String(def.account_id);renderIncomeAccountState(false);}
    else {account.value='';renderIncomeAccountState(false);}
  }
  function syncConcept(type,selectId,categoryId,formId){const sel=$(selectId),o=sel.selectedOptions[0],cat=$(categoryId);if(o?.dataset.category&&[...cat.options].some(x=>x.value===o.dataset.category))cat.value=o.dataset.category;if(o?.dataset.amount){const amount=$(`${formId} input[name="amount"]`);if(amount&&!amount.value)moneyFieldSet(amount,Number(o.dataset.amount));}if(type==='income')applyIncomeAccountSuggestion();}
  function updateAccountHint(selectId,hintId){const id=$(selectId)?.value,a=(config.accounts||[]).find(x=>String(x.id)===String(id)),hint=$(hintId);if(hint&&a){const spend=accountSpendable(a),protectedAmt=accountProtected(a);hint.textContent=`Disponible: ${money(spend)}`+(protectedAmt>0?` · ${money(protectedAmt)} protegido en Ahorro`:'');}}
  function updateTransferHints(){
    const from=(config.accounts||[]).find(a=>String(a.id)===String($('#transferFrom')?.value));
    const to=(config.accounts||[]).find(a=>String(a.id)===String($('#transferTo')?.value));
    const fromHint=$('#transferFromHint'),toHint=$('#transferToHint');
    if(fromHint)fromHint.textContent=from?`Puedes mover ${money(accountSpendable(from))}${accountProtected(from)>0?` · ${money(accountProtected(from))} protegidos`:''}`:'';
    if(toHint)toHint.textContent=to?`Saldo actual ${money(Number(to.balance||0))}`:'';
  }
  function updatePayAccountHint(){const a=(config.accounts||[]).find(x=>String(x.id)===String($('#payAccount')?.value)),hint=$('#payAccountHint');if(hint)hint.textContent=a?`Disponible: ${money(accountSpendable(a))}${accountProtected(a)>0?` · ${money(accountProtected(a))} protegido en Ahorro`:''}`:'';}
  let quickConceptContext=null;
  const quickConceptModal=$('#quickConceptModal'),quickConceptForm=$('#quickConceptForm');
  function refreshConceptControls(type,preferredId=''){
    const isExpense=type==='expense',selectId=isExpense?'#expenseConcept':'#incomeConcept',categoryId=isExpense?'#expenseCategory':'#incomeCategory',formId=isExpense?'#expenseForm':'#incomeForm',chipsId=isExpense?'#expenseChips':'#incomeChips';
    fillConceptSelect($(selectId),type,preferredId);
    renderConceptChips(chipsId,type,selectId,categoryId,formId);
    if(preferredId){$(selectId).value=String(preferredId);syncConcept(type,selectId,categoryId,formId);}
  }
  function openQuickConceptCreator(type){
    const cats=typeCats(type),sourceCategory=$(type==='expense'?'#expenseCategory':'#incomeCategory');
    quickConceptContext=type;$('#quickConceptType').value=type;$('#quickConceptName').value='';$('#quickConceptAmount').value='';$('#quickConceptAnt').checked=false;
    $('#quickConceptKicker').textContent=type==='expense'?'NUEVO CONCEPTO DE GASTO':'NUEVO CONCEPTO DE INGRESO';
    $('#quickConceptTitle').textContent=type==='expense'?'¿Qué nuevo gasto quieres registrar?':'¿Qué nuevo ingreso quieres registrar?';
    $('#quickConceptHelp').textContent='Se guardará en tu catálogo y quedará seleccionado automáticamente.';
    $('#quickConceptAntWrap').hidden=type!=='expense';
    const cat=$('#quickConceptCategory');cat.innerHTML=cats.length?cats.map(c=>`<option value="${c.id}">${esc(c.icon||'•')} ${esc(c.name)}</option>`).join(''):'<option value="" disabled>No hay categorías disponibles</option>';
    if(sourceCategory?.value&&[...cat.options].some(o=>o.value===sourceCategory.value))cat.value=sourceCategory.value;
    quickConceptModal.classList.add('show');quickConceptModal.setAttribute('aria-hidden','false');document.body.classList.add('modal-open');setTimeout(()=>$('#quickConceptName')?.focus(),80);
  }
  function closeQuickConceptCreator(){quickConceptModal?.classList.remove('show');quickConceptModal?.setAttribute('aria-hidden','true');quickConceptContext=null;if(!Object.values(actionModals).some(m=>m?.classList.contains('show')))document.body.classList.remove('modal-open');}

  function populateQuickForms(){
    const accounts=config.accounts||[],funds=config.funds||[];
    ['#expenseAccount','#transferFrom','#transferTo'].forEach(id=>fillSelect($(id),accounts,a=>`<option value="${a.id}">${esc(accountLabel(a))}</option>`,'Primero crea una cuenta'));
    const incomeAccount=$('#incomeAccount');
    if(incomeAccount){
      const prior=incomeAccount.value;
      incomeAccount.disabled=!accounts.length;
      incomeAccount.innerHTML=accounts.length?'<option value="" disabled>Elige una cuenta</option>'+accounts.map(a=>`<option value="${a.id}">${esc(accountLabel(a))}</option>`).join(''):'<option value="">Primero crea una cuenta</option>';
      incomeAccount.value=(prior&&accounts.some(a=>String(a.id)===String(prior)))?prior:'';
    }
    selectRememberedAccount($('#expenseAccount'));
    if(accounts.length>1&&$('#transferTo').value===$('#transferFrom').value)$('#transferTo').selectedIndex=1;
    fillSelect($('#expenseFund'),[{id:'',name:'No, dinero libre',icon:'○'},...funds],f=>f.id===''?'<option value="">○ No, dinero libre</option>':`<option value="${f.id}">${esc(fundLabel(f))}</option>`,'No hay fondos');
    fillSelect($('#allocateFund'),funds,f=>`<option value="${f.id}">${esc(fundLabel(f))}</option>`,'Primero crea un fondo');
    fillConceptSelect($('#expenseConcept'),'expense');fillConceptSelect($('#incomeConcept'),'income');fillCategorySelect($('#expenseCategory'),'expense');fillCategorySelect($('#incomeCategory'),'income');
    renderConceptChips('#expenseChips','expense','#expenseConcept','#expenseCategory','#expenseForm');renderConceptChips('#incomeChips','income','#incomeConcept','#incomeCategory','#incomeForm');renderFundChoices();
    updateAccountHint('#expenseAccount','#expenseAccountHint');applyIncomeAccountSuggestion();updateTransferHints();
    $$('.quick-form input[name="occurred_at"]').forEach(i=>{if(!i.value)i.value=nowLocal();});
    if(currentData?.summary)$('#quickUnallocated').textContent=money(currentData.summary.unallocated);
  }
  function renderPayOptions(){
    const payAccount=$('#payAccount'),payFund=$('#payFund');
    fillSelect(payAccount,config.accounts||[],a=>`<option value="${a.id}">${esc(accountLabel(a))}</option>`,'Primero crea una cuenta');selectRememberedAccount(payAccount);
    payFund.innerHTML='<option value="">○ No, dinero libre</option>'+(config.funds||[]).map(f=>`<option value="${f.id}">${esc(fundLabel(f))}</option>`).join('');updatePayAccountHint();
  }
  async function saveTransaction(form,type){const data=Object.fromEntries(new FormData(form).entries());data.amount=moneyFieldRaw(form.querySelector('[name="amount"]'));data.type=type;if(type==='income')delete data.fund_id;const account=data.account_id;await json(api('transaction_save.php'),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':APP.csrf},body:JSON.stringify(data)});if(account&&type==='expense')localStorage.setItem('fin_last_account',account);form.reset();form.querySelector('input[name="occurred_at"]')&&(form.querySelector('input[name="occurred_at"]').value=nowLocal());closeActionModal(type);toast(type==='expense'?'Gasto registrado':'Ingreso registrado');await Promise.all([load(),loadConfig()]);}

  newTx?.addEventListener('click',e=>{e.stopPropagation();toggleRegisterMenu();});
  $$('[data-quick-action]').forEach(btn=>btn.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();openActionModal(btn.dataset.quickAction);}));
  $$('[data-action-close]').forEach(btn=>btn.addEventListener('click',()=>closeActionModal(btn.dataset.actionClose)));
  $$('[data-quick-concept-create]').forEach(btn=>btn.addEventListener('click',()=>openQuickConceptCreator(btn.dataset.quickConceptCreate)));
  $$('[data-quick-concept-close]').forEach(btn=>btn.addEventListener('click',closeQuickConceptCreator));
  quickConceptModal?.addEventListener('click',e=>{if(e.target===quickConceptModal)closeQuickConceptCreator();});
  Object.entries(actionModals).forEach(([action,modal])=>modal?.addEventListener('click',e=>{if(e.target===modal)closeActionModal(action);}));
  document.addEventListener('click',e=>{if(registerMenu?.classList.contains('show')&&!e.target.closest('.register-launcher'))closeRegisterMenu();},{signal:pageAbort.signal});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'){if(quickConceptModal?.classList.contains('show'))closeQuickConceptCreator();else{closeRegisterMenu();closeAllActionModals();}}},{signal:pageAbort.signal});
  $('#expenseConcept').onchange=()=>{syncConcept('expense','#expenseConcept','#expenseCategory','#expenseForm');$$('#expenseChips button').forEach(x=>x.classList.remove('active'));};
  $('#incomeConcept').onchange=()=>{syncConcept('income','#incomeConcept','#incomeCategory','#incomeForm');$$('#incomeChips button').forEach(x=>x.classList.remove('active'));};
  $('#expenseAccount').onchange=()=>updateAccountHint('#expenseAccount','#expenseAccountHint');$('#incomeAccount').onchange=()=>renderIncomeAccountState(true);
  $('#transferFrom').onchange=()=>{if($('#transferTo').value===$('#transferFrom').value&&$('#transferTo').options.length>1)$('#transferTo').selectedIndex=$('#transferFrom').selectedIndex===0?1:0;updateTransferHints();};$('#transferTo').onchange=updateTransferHints;$('#payAccount').onchange=updatePayAccountHint;
  quickConceptForm.onsubmit=async e=>{e.preventDefault();const type=quickConceptContext||$('#quickConceptType').value,b=$('#quickConceptSubmit'),data=Object.fromEntries(new FormData(e.target).entries());data.type=type;data.default_amount=moneyFieldRaw($('#quickConceptAmount'));data.is_ant_expense=$('#quickConceptAnt').checked?1:0;b.disabled=true;b.textContent='Creando...';try{const j=await json(api('concept_save.php'),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':APP.csrf},body:JSON.stringify(data)});const c=j.concept;if(c){config.concepts=(config.concepts||[]).filter(x=>String(x.id)!==String(c.id));config.concepts.push(c);refreshConceptControls(type,c.id);}closeQuickConceptCreator();toast(j.existing?'Concepto existente seleccionado':'Concepto creado y seleccionado');}catch(err){toast(err.message,'error')}finally{b.disabled=false;b.textContent='Crear y usar'}};
  $('#expenseForm').onsubmit=async e=>{e.preventDefault();const b=e.submitter;if(!$('#expenseConcept').value){toast('Selecciona o crea un concepto para este gasto.','error');$('#expenseConcept').focus();return;}b.disabled=true;b.textContent='Registrando...';try{await saveTransaction(e.target,'expense')}catch(err){toast(err.message,'error')}finally{b.disabled=false;b.textContent='Registrar gasto'}};
  $('#incomeForm').onsubmit=async e=>{e.preventDefault();const b=e.submitter;if(!$('#incomeConcept').value){toast('Selecciona o crea un concepto para este ingreso.','error');$('#incomeConcept').focus();return;}if(!$('#incomeAccount').value){toast('Indica en qué cuenta recibiste este dinero.','error');$('#incomeAccount').focus();return;}b.disabled=true;b.textContent='Registrando...';try{await saveTransaction(e.target,'income')}catch(err){toast(err.message,'error')}finally{b.disabled=false;b.textContent='Registrar ingreso'}};
  $('#transferQuickForm').onsubmit=async e=>{e.preventDefault();const d=Object.fromEntries(new FormData(e.target).entries()),b=e.submitter;d.amount=moneyFieldRaw(e.target.querySelector('[name="amount"]'));b.disabled=true;b.textContent='Moviendo...';try{await json(api('account_transfer.php'),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':APP.csrf},body:JSON.stringify(d)});e.target.reset();e.target.querySelector('[name="occurred_at"]').value=nowLocal();closeActionModal('transfer');toast('Dinero movido entre tus cuentas');await Promise.all([load(),loadConfig()]);}catch(err){toast(err.message,'error')}finally{b.disabled=false;b.textContent='Mover dinero'}};
  $('#allocateQuickForm').onsubmit=async e=>{e.preventDefault();const fd=new FormData(e.target),fid=fd.get('fund_id'),amount=parseMoneyInput(fd.get('amount')),b=e.submitter;b.disabled=true;b.textContent='Separando...';try{await json(api('fund_allocate.php'),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':APP.csrf},body:JSON.stringify({allocations:{[fid]:amount},note:'Asignación rápida desde dashboard'})});e.target.reset();closeActionModal('allocate');toast('Dinero separado en tu fondo');await Promise.all([load(),loadConfig()]);}catch(err){toast(err.message,'error')}finally{b.disabled=false;b.textContent='Separar dinero'}};

  // Pagos pendientes
  function openPay(d){currentPayment=d;$('#payId').value=d.pay;$('#payName').textContent=d.name;$('#payIcon').textContent=d.icon||'⌂';$('#payReference').textContent=money(d.amount);moneyFieldSet($('#payAmount'),Number(d.amount)>0?Number(d.amount):'');$('#payDue').textContent='Vence '+d.due.split('-').reverse().join('/');renderPayOptions();const linked=(config.funds||[]).find(f=>String(f.id)===String(d.fund));if(linked&&Number(linked.available||0)+0.005>=Number(d.amount||0)&&[...$('#payFund').options].some(o=>o.value===String(d.fund)))$('#payFund').value=String(d.fund);else $('#payFund').value='';$('#payModal').classList.add('show');$('#payModal').setAttribute('aria-hidden','false');}
  function closePay(){$('#payModal').classList.remove('show');currentPayment=null;}
  $$('[data-pay-close]').forEach(b=>b.onclick=closePay);
  $('#payForm').onsubmit=async e=>{e.preventDefault();if(!currentPayment)return;const btn=$('#paySubmit');btn.disabled=true;btn.textContent='Registrando...';try{await json(api('payment_mark.php'),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':APP.csrf},body:JSON.stringify({id:currentPayment.pay,amount:moneyFieldRaw($('#payAmount')),payment_method:$('#payMethod').value,account_id:$('#payAccount').value,fund_id:$('#payFund').value})});localStorage.setItem('fin_last_account',$('#payAccount').value);closePay();toast('Pago registrado');await Promise.all([load(),loadConfig()]);}catch(err){toast(err.message,'error')}finally{btn.disabled=false;btn.textContent='✓ Registrar pago'}};

  function shift(delta){const [y,m]=$('#period').value.split('-').map(Number),d=new Date(y,m-1+delta,1);$('#period').value=`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;load();}
  $('#prevMonth').onclick=()=>shift(-1);$('#nextMonth').onclick=()=>shift(1);$('#period').onchange=load;
  bindMoneyFields();populateQuickForms();renderPayOptions();load().catch(err=>{if(err?.name!=='AbortError')console.error(err)});loadConfig();

  // Permite abrir una acción desde cualquier módulo: /dashboard?action=transfer, etc.
  const q=new URLSearchParams(location.search),action=q.get('action');
  if(['expense','income','transfer','allocate'].includes(action)){setTimeout(()=>openActionModal(action),120);q.delete('action');const rest=q.toString();history.replaceState({},'',location.pathname+(rest?'?'+rest:''));}
  else if(action==='choose'){setTimeout(()=>toggleRegisterMenu(),120);q.delete('action');const rest=q.toString();history.replaceState({},'',location.pathname+(rest?'?'+rest:''));}

  return {
    refresh: async () => {
      if(pageAbort.signal.aborted)return;
      await Promise.all([load(),loadConfig()]);
    },
    destroy: () => {
      pageAbort.abort();
      clearTimeout(timer);
      clearTimeout(toastTimer);
      if(chart){try{chart.destroy()}catch{}chart=null;}
      document.querySelectorAll('[data-money-value]').forEach(el=>{if(el._moneyAnimation)cancelAnimationFrame(el._moneyAnimation)});
    }
  };
});
