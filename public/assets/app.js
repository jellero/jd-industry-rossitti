'use strict';

const state = {
  clients: [], jobTypes: [], machines: [], jobs: [], files: [], selectedFileId: null,
  selectedJobs: new Set(), dashboardTimer: null, refreshSeconds: 10,
  company: {}, reportSearchTimer: null, reportJobId: null,
};
const $ = (sel, root = document) => root.querySelector(sel);
const all = (sel, root = document) => Array.from(root.querySelectorAll(sel));
const bind = (sel, event, handler) => { const el = $(sel); if (el) el.addEventListener(event, handler); return el; };

async function api(route, options = {}) {
  const query = options.query ? '&' + new URLSearchParams(options.query).toString() : '';
  const res = await fetch('api.php?route=' + encodeURIComponent(route) + query, {
    method: options.method || (options.body !== undefined ? 'POST' : 'GET'),
    headers: options.body !== undefined ? {'Content-Type':'application/json'} : {},
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    cache: 'no-store',
  });
  const json = await res.json().catch(() => ({ok:false,error:'Risposta server non JSON'}));
  if (!res.ok || !json.ok) throw new Error(json.error || `Errore HTTP ${res.status}`);
  return json.data;
}
function esc(v){ return String(v ?? '').replace(/[&<>'"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c])); }
function fmtDateTime(v){ return v ? String(v).replace('T',' ').replace(/\.000.*$/,'').replace(/Z$/,'') : ''; }
function fmtNumber(v,d=2){ return Number(v||0).toLocaleString('it-IT',{minimumFractionDigits:d,maximumFractionDigits:d}); }
function fmtMoney(v){ return Number(v||0).toLocaleString('it-IT',{style:'currency',currency:'EUR'}); }
function fmtDuration(sec){ sec=Number(sec||0); const h=Math.floor(sec/3600), m=Math.floor((sec%3600)/60), s=Math.floor(sec%60); return `${h}h ${m}m ${s}s`; }
function fmtBytes(bytes){ bytes=Number(bytes||0); if(bytes<1024)return bytes+' B'; if(bytes<1048576)return (bytes/1024).toFixed(1)+' KB'; return (bytes/1048576).toFixed(2)+' MB'; }
function toast(message,type='ok'){ const el=$('#toast'); el.textContent=message; el.className='toast show '+type; clearTimeout(el._t); el._t=setTimeout(()=>el.className='toast',3800); }
function formToObject(form){ const d={}; new FormData(form).forEach((v,k)=>d[k]=v); all('input[type="checkbox"]',form).forEach(cb=>d[cb.name]=cb.checked?1:0); return d; }
function fillForm(form,row){ Object.entries(row||{}).forEach(([k,v])=>{ const el=form.elements[k]; if(!el)return; if(el.type==='checkbox')el.checked=Number(v)===1||v===true; else el.value=v??''; }); }
function resetForm(form){ form?.reset(); if(form?.elements.id)form.elements.id.value=''; }
function optionHtml(rows,labelFn,empty=null){ return (empty!==null?`<option value="">${esc(empty)}</option>`:'')+rows.map(r=>`<option value="${esc(r.id)}">${esc(labelFn(r))}</option>`).join(''); }
function preserveValue(select,html){ if(!select)return; const old=select.value; select.innerHTML=html; if([...select.options].some(o=>o.value===old))select.value=old; }
function renderTable(table,cols,rows,actions=null){ if(!table)return; const head='<thead><tr>'+cols.map(c=>`<th>${esc(c.label)}</th>`).join('')+(actions?'<th>Azioni</th>':'')+'</tr></thead>'; const body=rows.length?rows.map(r=>'<tr>'+cols.map(c=>`<td>${c.render?c.render(r):esc(r[c.key])}</td>`).join('')+(actions?`<td class="actions">${actions(r)}</td>`:'')+'</tr>').join(''):`<tr><td colspan="${cols.length+(actions?1:0)}" class="muted">Nessun dato</td></tr>`; table.innerHTML=head+'<tbody>'+body+'</tbody>'; }
function badge(status){ return `<span class="badge ${esc(status)}">${esc(String(status||'').replaceAll('_',' '))}</span>`; }

async function bootstrap(){ bindTabs(); bindForms(); bindButtons(); setDefaultDateTimes(); await loadBaseData(); await Promise.all([loadDashboard(),loadJobs(),loadFiles(),loadProduction(),loadScheduling(),loadCosts(),loadCompanySettings()]); scheduleDashboardPolling(); }
function bindTabs(){ all('.tabs button').forEach(btn=>btn.addEventListener('click',()=>{ all('.tabs button').forEach(b=>b.classList.remove('active')); all('.tab').forEach(t=>t.classList.remove('active')); btn.classList.add('active'); const tab=$('#tab-'+btn.dataset.tab); if(tab)tab.classList.add('active'); })); }
function bindForms(){
  bind('#clientForm','submit',async e=>{ e.preventDefault(); try{ await api('clients',{body:formToObject(e.currentTarget)}); resetForm(e.currentTarget); await loadClients(); await loadJobs(); toast('Cliente salvato'); }catch(err){toast(err.message,'error');} });
  bind('#jobForm','submit',async e=>{ e.preventDefault(); try{ await api('jobs',{body:formToObject(e.currentTarget)}); resetForm(e.currentTarget); await loadJobs(); await loadDashboard(); toast('Commessa salvata'); }catch(err){toast(err.message,'error');} });
  bind('#machineForm','submit',async e=>{ e.preventDefault(); try{ await api('machines',{body:formToObject(e.currentTarget)}); resetForm(e.currentTarget); await loadMachines(); toast('Macchina salvata'); }catch(err){toast(err.message,'error');} });
  bind('#schedulingForm','submit',async e=>{ e.preventDefault(); try{ const d=await api('scheduling',{body:formToObject(e.currentTarget)}); renderScheduling(d); toast('Scheduling salvato'); scheduleDashboardPolling(); }catch(err){toast(err.message,'error');} });
  bind('#costForm','submit',async e=>{ e.preventDefault(); try{ const d=await api('costs',{body:formToObject(e.currentTarget)}); fillForm(e.currentTarget,d); toast('Parametri costi salvati'); }catch(err){toast(err.message,'error');} });
  bind('#companyForm','submit',async e=>{ e.preventDefault(); try{ state.company=await api('company-settings',{body:formToObject(e.currentTarget)}); renderCompanySettings(); renderCompanyReportHeader(); toast('Dati azienda salvati'); }catch(err){toast(err.message,'error');} });
  bind('#companyLogoForm','submit',uploadCompanyLogo);
  bind('#folderJobCreateForm','submit',createFolderJob);
}
function bindButtons(){
  bind('#btnRefresh','click',()=>refreshAll(true)); bind('#btnLiveRefresh','click',()=>refreshMachineLive(true));
  bind('#btnClientReset','click',()=>resetForm($('#clientForm'))); bind('#btnJobReset','click',()=>resetForm($('#jobForm')));
  bind('#btnClientSearch','click',loadClients); bind('#clientSearch','keydown',e=>{if(e.key==='Enter')loadClients();});
  bind('#btnJobSearch','click',loadJobs); bind('#jobSearch','keydown',e=>{if(e.key==='Enter')loadJobs();}); bind('#jobStatusFilter','change',loadJobs);
  bind('#btnBulkOpen','click',()=>bulkOrder('open')); bind('#btnBulkClose','click',()=>bulkOrder('close'));
  bind('#btnMaestroInfo','click',maestroInfo); bind('#btnMaestroStatus','click',maestroStatus);
  bind('#btnOrderOpen','click',()=>maestroOrder('open')); bind('#btnOrderActivate','click',()=>maestroOrder('activate')); bind('#btnOrderClose','click',()=>maestroOrder('close'));
  bind('#btnImportProduction','click',importProduction); bind('#btnReportLoad','click',()=>loadReport()); bind('#btnReportPrint','click',printReport);
  bind('#reportSearch','input',onReportSearchInput); bind('#reportSearch','focus',()=>{ if($('#reportSearch').value.trim()) searchReportJobs(); });
  bind('#btnScanFiles','click',scanFiles); bind('#btnFilesRefresh','click',loadFiles); bind('#fileAssignedFilter','change',loadFiles); bind('#fileSearch','keydown',e=>{if(e.key==='Enter')loadFiles();});
  bind('#btnAssignFile','click',assignFile); bind('#btnUnassignFile','click',unassignFile); bind('#btnSchedulerRun','click',runScheduler);
  bind('#btnFolderJobCreateToggle','click',()=>toggleFolderJobCreate(true)); bind('#btnFolderJobCreateCancel','click',()=>toggleFolderJobCreate(false));
  document.addEventListener('click',e=>{ const box=$('#reportSearchResults'), input=$('#reportSearch'); if(box&&input&&!box.contains(e.target)&&e.target!==input)box.hidden=true; });
}
function setDefaultDateTimes(){ const now=new Date(), start=new Date(now.getTime()-24*3600*1000); const from=$('#prodFrom'), to=$('#prodTo'); if(from)from.value=toLocalInput(start); if(to)to.value=toLocalInput(now); }
function toLocalInput(d){ const p=n=>String(n).padStart(2,'0'); return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; }
async function loadBaseData(){ await Promise.all([loadClients(),loadJobTypes(),loadMachines()]); }
async function refreshAll(showToast=false){ try{ await loadBaseData(); await Promise.all([loadDashboard(),loadJobs(),loadFiles(),loadProduction(),loadScheduling(),loadCosts(),loadCompanySettings()]); $('#lastRefresh').textContent='Aggiornato '+new Date().toLocaleTimeString('it-IT'); if(showToast)toast('Dati aggiornati'); }catch(e){toast(e.message,'error');} }

async function loadDashboard(){ const d=await api('dashboard'); state.refreshSeconds=Number(d.refresh_seconds||10); const cards=[['Commesse aperte',d.jobs_open],['In lavorazione',d.jobs_running],['Commesse chiuse',d.jobs_closed],['Pannelli registrati',d.production_rows],['File da associare',d.files_new]]; $('#dashboardCards').innerHTML=cards.map(([l,v])=>`<div class="card metric"><span>${esc(l)}</span><strong>${esc(v)}</strong></div>`).join(''); renderMachineCards(d.machines||[]); renderTable($('#dashboardJobsTable'),[{label:'Commessa',render:r=>`<strong>${esc(r.job_code)}</strong><div class="muted small">${esc(r.title)}</div>`},{label:'Cliente',key:'company_name'},{label:'Macchina',key:'machine_name'},{label:'Stato',render:r=>badge(r.status)},{label:'Aggiornata',render:r=>fmtDateTime(r.updated_at||r.created_at)}],d.recent_jobs||[]); $('#lastRefresh').textContent='Aggiornato '+new Date().toLocaleTimeString('it-IT'); }
function renderMachineCards(rows){ $('#machineCards').innerHTML=rows.map(m=>{ if(m.kind!=='maestro_rest') return `<div class="machine-card neutral"><div class="machine-title"><span class="status-dot neutral"></span><strong>${esc(m.name)}</strong></div><p>Macchina cartella / file</p></div>`; const online=Number(m.online)===1; const cls=!online?'offline':(Number(m.alarms)?'alarm':Number(m.working)?'working':'ready'); const stateLabel=!online?'Non raggiungibile':(m.machine_state||'Connessa'); return `<div class="machine-card ${cls}"><div class="machine-title"><span class="status-dot ${cls}"></span><div><strong>${esc(m.name)}</strong><div class="machine-state">${esc(stateLabel)}</div></div></div><div class="machine-kpis"><div><span>Commessa</span><b>${esc(m.current_order||'—')}</b></div><div><span>Velocità</span><b>${m.track_speed!==null&&m.track_speed!==''?esc(m.track_speed)+' m/min':'—'}</b></div><div><span>Pezzi in macchina</span><b>${m.pieces_in_machine??'—'}</b></div><div><span>Ultima chiusa</span><b>${esc(m.last_order_closed||'—')}</b></div></div><div class="machine-footer">${m.last_error?`<span class="error-text">${esc(m.last_error)}</span>`:`Ultimo dato: ${esc(fmtDateTime(m.last_success_at||m.last_checked_at)||'mai')}`}</div></div>`; }).join('')||'<div class="empty-state">Nessuna macchina attiva.</div>'; }
async function refreshMachineLive(showToast=false){ try{ await api('machines/live'); await Promise.all([loadDashboard(),loadJobs()]); if(showToast)toast('Stato macchine aggiornato'); }catch(e){ if(showToast)toast(e.message,'error'); } }
function scheduleDashboardPolling(){ clearInterval(state.dashboardTimer); const sec=Math.max(5,Number(state.refreshSeconds||10)); state.dashboardTimer=setInterval(async()=>{ const tab=$('#tab-dashboard'); if(document.visibilityState==='visible' && tab?.classList.contains('active')) await refreshMachineLive(false); },sec*1000); }

async function loadClients(){ state.clients=await api('clients',{query:{q:$('#clientSearch')?.value||''}}); renderTable($('#clientsTable'),[{label:'Codice',key:'code'},{label:'Ragione sociale',key:'company_name'},{label:'Email',key:'email'},{label:'Telefono',key:'phone'},{label:'Attivo',render:r=>Number(r.active)?'Sì':'No'}],state.clients,r=>`<button data-edit-client="${r.id}" class="secondary">Modifica</button><button data-del-client="${r.id}" class="danger">Elimina</button>`); all('[data-edit-client]').forEach(b=>b.addEventListener('click',()=>fillForm($('#clientForm'),state.clients.find(x=>x.id==b.dataset.editClient)))); all('[data-del-client]').forEach(b=>b.addEventListener('click',async()=>{if(!confirm('Eliminare il cliente?'))return; try{await api('clients',{method:'DELETE',query:{id:b.dataset.delClient}}); await loadClients(); toast('Cliente eliminato');}catch(e){toast(e.message,'error');}})); updateSelects(); }
async function loadJobTypes(){ state.jobTypes=await api('job-types'); updateSelects(); }
async function loadMachines(){ state.machines=await api('machines'); renderTable($('#machinesTable'),[{label:'Nome',key:'name'},{label:'Tipo',key:'kind'},{label:'API',key:'api_version'},{label:'Host',key:'host'},{label:'Porta',key:'port'},{label:'Base path',key:'base_path'},{label:'Online',render:r=>r.kind==='maestro_rest'?(Number(r.online)?'<span class="badge online">Online</span>':'<span class="badge offline">Offline</span>'):'—'},{label:'Ultimo controllo',render:r=>fmtDateTime(r.last_checked_at)}],state.machines,r=>`<button data-edit-machine="${r.id}" class="secondary">Modifica</button>`); all('[data-edit-machine]').forEach(b=>b.addEventListener('click',()=>fillForm($('#machineForm'),state.machines.find(x=>x.id==b.dataset.editMachine)))); updateSelects(); }
async function loadJobs(){ state.jobs=await api('jobs',{query:{q:$('#jobSearch')?.value||'',status:$('#jobStatusFilter')?.value||''}}); renderJobs(); updateSelects(); }
function renderJobs(){ renderTable($('#jobsTable'),[{label:'',render:r=>r.source_type==='MAESTRO_REST'?`<input type="checkbox" class="job-check" value="${r.id}" ${state.selectedJobs.has(String(r.id))?'checked':''}>`:''},{label:'Codice',render:r=>`<strong>${esc(r.job_code)}</strong>`},{label:'Titolo',key:'title'},{label:'Cliente',key:'company_name'},{label:'Macchina',key:'machine_name'},{label:'Stato',render:r=>badge(r.status)},{label:'Creata',render:r=>fmtDateTime(r.created_at)}],state.jobs,r=>`<button data-report-job="${r.id}" class="secondary">Report</button><button data-edit-job="${r.id}" class="secondary">Modifica</button><button data-del-job="${r.id}" class="danger">Elimina</button>`); all('.job-check').forEach(ch=>ch.addEventListener('change',()=>{ch.checked?state.selectedJobs.add(ch.value):state.selectedJobs.delete(ch.value); updateBulkCount();})); all('[data-edit-job]').forEach(b=>b.addEventListener('click',()=>fillForm($('#jobForm'),state.jobs.find(x=>x.id==b.dataset.editJob)))); all('[data-report-job]').forEach(b=>b.addEventListener('click',()=>{ const job=state.jobs.find(x=>String(x.id)===String(b.dataset.reportJob)); if(job){ selectReportJob(job,false); $('[data-tab="reports"]').click(); loadReport(job.id); } })); all('[data-del-job]').forEach(b=>b.addEventListener('click',async()=>{ if(!confirm('Eliminare la commessa?'))return; try{await api('jobs',{method:'DELETE',query:{id:b.dataset.delJob}}); state.selectedJobs.delete(String(b.dataset.delJob)); await loadJobs(); await loadDashboard(); toast('Commessa eliminata');}catch(e){toast(e.message,'error');} })); updateBulkCount(); }
function updateBulkCount(){ $('#bulkCount').textContent=`${state.selectedJobs.size} selezionate`; }
function updateSelects(){ const clients=optionHtml(state.clients.filter(c=>Number(c.active)),r=>`${r.company_name}${r.code?' ('+r.code+')':''}`,'Seleziona cliente'); const types=optionHtml(state.jobTypes,r=>r.name,'Seleziona tipo'); const machines=optionHtml(state.machines.filter(m=>Number(m.active)),r=>r.name,'Nessuna'); const maestro=state.machines.filter(m=>m.kind==='maestro_rest'&&Number(m.active)); const folders=state.machines.filter(m=>m.kind==='folder'&&Number(m.active)); const jobs=optionHtml(state.jobs,r=>`${r.job_code} - ${r.company_name}`,'Seleziona commessa'); all('select[name="client_id"]').forEach(s=>preserveValue(s,clients)); all('select[name="job_type_id"]').forEach(s=>preserveValue(s,types)); all('select[name="machine_id"]').forEach(s=>preserveValue(s,machines)); ['#maestroMachine','#bulkMachine'].forEach(sel=>preserveValue($(sel),optionHtml(maestro,r=>r.name))); preserveValue($('#folderMachine'),optionHtml(folders,r=>r.name)); ['#maestroJob','#assignJob'].forEach(sel=>preserveValue($(sel),jobs)); preserveValue($('#folderNewClient'),clients); preserveValue($('#folderNewType'),optionHtml(state.jobTypes.filter(t=>t.source_type==='SMB_FOLDER'),r=>r.name,'Seleziona tipo')); }
async function bulkOrder(action){ const ids=[...state.selectedJobs].map(Number); if(!ids.length)return toast('Seleziona almeno una commessa','error'); if(action==='close'&&!confirm(`Chiudere ${ids.length} commesse sulla macchina?`))return; try{ const d=await api('maestro/orders-bulk',{body:{machine_id:$('#bulkMachine').value,job_ids:ids,action}}); const ok=d.results.filter(r=>r.success).length; const ko=d.results.length-ok; $('#bulkResult').innerHTML=`<strong>${ok} completate</strong>${ko?` · <span class="error-text">${ko} con errore</span>`:''}`; state.selectedJobs.clear(); await Promise.all([loadJobs(),loadDashboard()]); toast(`Operazione completata: ${ok}/${d.results.length}`); }catch(e){toast(e.message,'error');} }

async function maestroInfo(){ try{ const d=await api('maestro/info',{query:{machine_id:$('#maestroMachine').value}}); $('#maestroOutput').textContent=JSON.stringify(d,null,2); }catch(e){toast(e.message,'error');} }
async function maestroStatus(){ try{ const all=await api('machines/live',{query:{machine_id:$('#maestroMachine').value}}); const d=all[$('#maestroMachine').value]; $('#maestroOutput').textContent=JSON.stringify(d,null,2); renderMaestroHuman(d); await Promise.all([loadDashboard(),loadJobs()]); }catch(e){toast(e.message,'error');} }
function renderMaestroHuman(d){ if(!d?.success){$('#maestroHumanStatus').innerHTML='<span class="error-text">Macchina non raggiungibile.</span>';return;} const s=d.status; $('#maestroHumanStatus').innerHTML=`<div class="status-pill ${s.alarms?'alarm':s.working?'working':'ready'}">${s.alarms?'Allarme':s.working?'In lavoro':'Pronta'}</div><div><strong>Commessa:</strong> ${esc(s.current_order||'nessuna')} · <strong>Stato:</strong> ${esc(s.order_status||'—')} · <strong>Velocità:</strong> ${esc(s.track_speed??'—')} m/min · <strong>Pezzi:</strong> ${esc(s.pieces_in_machine??'—')}</div>`; }
async function maestroOrder(action){ const jobId=$('#maestroJob').value; if(!jobId)return toast('Seleziona una commessa','error'); try{ const d=await api('maestro/order',{body:{job_id:jobId,machine_id:$('#maestroMachine').value,action}}); $('#maestroOutput').textContent=JSON.stringify(d,null,2); await Promise.all([loadJobs(),loadDashboard()]); toast('Comando inviato: '+action); }catch(e){toast(e.message,'error');} }
async function importProduction(){ try{ const d=await api('maestro/import-production',{body:{job_id:$('#maestroJob').value||null,machine_id:$('#maestroMachine').value,from:$('#prodFrom').value,to:$('#prodTo').value,limit:$('#prodLimit').value}}); toast(`Letti ${d.seen}, nuovi ${d.inserted}`); await Promise.all([loadProduction(),loadDashboard()]); }catch(e){toast(e.message,'error');} }
async function loadProduction(jobId=null,table=$('#productionTable')){ const q={}; if(jobId)q.job_id=jobId; const rows=await api('production',{query:q}); renderProduction(table,rows); }
function renderProduction(table,rows){ renderTable(table,[{label:'Inizio',render:r=>fmtDateTime(r.datetime_start)},{label:'Commessa',render:r=>esc(r.remote_order_name||r.job_code)},{label:'Cliente',key:'company_name'},{label:'Programma',key:'program_name'},{label:'Misure mm',render:r=>`${esc(r.length_mm||'—')} × ${esc(r.width_mm||'—')} × ${esc(r.thickness_mm||'—')}`},{label:'Bordo',key:'edge_name_lh'},{label:'Consumo',render:r=>r.edge_consumption_lh?fmtNumber(r.edge_consumption_lh,1)+' mm':'—'}],rows); }

function onReportSearchInput(){
  state.reportJobId=null;
  $('#reportJobId').value='';
  $('#btnReportPrint').disabled=true;
  clearTimeout(state.reportSearchTimer);
  const q=$('#reportSearch').value.trim();
  if(!q){ $('#reportSearchResults').hidden=true; return; }
  state.reportSearchTimer=setTimeout(searchReportJobs,180);
}
async function searchReportJobs(){
  const q=$('#reportSearch').value.trim();
  if(!q)return;
  try{
    const rows=await api('jobs',{query:{q:q}});
    const box=$('#reportSearchResults');
    box.innerHTML=rows.slice(0,15).map(function(r){
      return '<button type="button" class="autocomplete-item" data-report-result="'+esc(r.id)+'"><strong>'+esc(r.job_code)+'</strong><span>'+esc(r.title||'')+'</span><small>'+esc(r.company_name)+' · '+esc(r.machine_name||'Nessuna macchina')+' · '+esc(String(r.status||'').replaceAll('_',' '))+'</small></button>';
    }).join('') || '<div class="autocomplete-empty">Nessuna commessa trovata.</div>';
    box.hidden=false;
    all('[data-report-result]',box).forEach(function(b){
      b.addEventListener('click',function(){
        const job=rows.find(function(r){ return String(r.id)===String(b.dataset.reportResult); });
        if(job)selectReportJob(job,true);
      });
    });
  }catch(e){toast(e.message,'error');}
}
function selectReportJob(job,autoLoad){
  if(autoLoad===undefined)autoLoad=true;
  state.reportJobId=Number(job.id);
  $('#reportJobId').value=String(job.id);
  $('#reportSearch').value=String(job.job_code||'')+' — '+String(job.company_name||'');
  $('#reportSearchResults').hidden=true;
  if(autoLoad)loadReport(job.id);
}
async function loadReport(jobIdOverride){
  const jobId=Number(jobIdOverride||state.reportJobId||$('#reportJobId').value||0);
  if(!jobId)return toast('Cerca e seleziona una commessa','error');
  try{
    const result=await Promise.all([
      api('reports/job',{query:{job_id:jobId}}),
      api('production',{query:{job_id:jobId}})
    ]);
    const r=result[0], prod=result[1];
    state.reportJobId=jobId;
    $('#reportHeader').classList.remove('empty-state');
    $('#reportHeader').innerHTML='<div><strong>'+esc(r.job.job_code)+' · '+esc(r.job.title)+'</strong><div>'+esc(r.job.company_name)+' · '+esc(r.job.machine_name||'Nessuna macchina')+'</div></div>'+badge(r.job.status);
    const p=r.production, costs=r.costs;
    $('#reportCards').innerHTML=[
      ['Pannelli',p.panels],
      ['Tempo macchina',fmtDuration(p.process_seconds)],
      ['Bordo consumato',fmtNumber(p.edge_meters,2)+' m'],
      ['Costo totale',fmtMoney(costs.total)]
    ].map(function(item){ return '<div class="card metric"><span>'+esc(item[0])+'</span><strong>'+esc(item[1])+'</strong></div>'; }).join('');
    $('#reportCosts').innerHTML='<div class="cost-grid"><div><span>Macchina</span><b>'+fmtMoney(costs.machine)+'</b></div><div><span>Bordo</span><b>'+fmtMoney(costs.edge)+'</b></div><div><span>Fisso</span><b>'+fmtMoney(costs.fixed)+'</b></div><div><span>Subtotale</span><b>'+fmtMoney(costs.subtotal)+'</b></div><div><span>Generali</span><b>'+fmtMoney(costs.overhead)+'</b></div><div class="total"><span>Totale</span><b>'+fmtMoney(costs.total)+'</b></div></div>';
    renderTable($('#reportEdgesTable'),[
      {label:'Bordo',key:'edge_name'},
      {label:'Pannelli',key:'panels'},
      {label:'Consumo',render:function(x){ return fmtNumber(Number(x.edge_mm)/1000,3)+' m'; }}
    ],p.edges||[]);
    renderProduction($('#reportProductionTable'),prod);
    renderCompanyReportHeader();
    $('#btnReportPrint').disabled=false;
  }catch(e){toast(e.message,'error');}
}
function printReport(){
  if(!state.reportJobId)return toast('Genera prima un report','error');
  window.print();
}
function renderCompanyReportHeader(){
  const el=$('#reportCompanyHeader');
  if(!el)return;
  const d=state.company||{};
  const city=[d.postal_code,d.city].filter(Boolean).join(' ');
  const address=[d.address,city,d.province,d.country].filter(Boolean).join(' · ');
  const fiscal=[d.vat_number?'P. IVA '+d.vat_number:'',d.tax_code?'C.F. '+d.tax_code:''].filter(Boolean).join(' · ');
  const contacts=[d.phone,d.email,d.pec?'PEC '+d.pec:'',d.sdi?'SDI '+d.sdi:'',d.website].filter(Boolean).join(' · ');
  const logo=d.logo_path?'<img src="'+esc(d.logo_path)+'" alt="Logo aziendale">':'';
  let html='<div class="report-company-main">'+logo+'<div><strong>'+esc(d.name||'')+'</strong>';
  if(address)html+='<div>'+esc(address)+'</div>';
  if(fiscal)html+='<div>'+esc(fiscal)+'</div>';
  if(contacts)html+='<div>'+esc(contacts)+'</div>';
  html+='</div></div>';
  if(d.report_footer)html+='<div class="report-company-note">'+esc(d.report_footer)+'</div>';
  el.innerHTML=html;
}

async function scanFiles(){ try{ const d=await api('files/scan',{query:{machine_id:$('#folderMachine').value}}); toast(`Nuovi ${d.created}, aggiornati ${d.updated}, visti ${d.seen}`); await Promise.all([loadFiles(),loadDashboard()]); }catch(e){toast(e.message,'error');} }
async function loadFiles(){ if(!$('#fileAssignedFilter'))return; const q={assigned:$('#fileAssignedFilter').value,q:$('#fileSearch').value||''}; state.files=await api('files',{query:q}); renderTable($('#filesTable'),[{label:'',render:r=>`<input type="radio" name="selectedFile" value="${r.id}" ${state.selectedFileId==r.id?'checked':''}>`},{label:'Nome',key:'file_name'},{label:'Percorso',key:'relative_path'},{label:'Dimensione',render:r=>fmtBytes(r.size_bytes)},{label:'Modificato',render:r=>fmtDateTime(r.modified_at)},{label:'Associato a',render:r=>r.job_code?`${esc(r.job_code)} - ${esc(r.company_name)}`:'<span class="badge new">Nuovo</span>'}],state.files); all('input[name="selectedFile"]').forEach(r=>r.addEventListener('change',()=>state.selectedFileId=r.value)); }
async function assignFile(){ if(!state.selectedFileId)return toast('Seleziona un file','error'); if(!$('#assignJob').value)return toast('Seleziona una commessa','error'); try{await api('files/assign',{body:{file_id:state.selectedFileId,job_id:$('#assignJob').value}}); state.selectedFileId=null; await Promise.all([loadFiles(),loadDashboard()]); toast('File associato');}catch(e){toast(e.message,'error');} }
async function unassignFile(){ if(!state.selectedFileId)return toast('Seleziona un file','error'); try{await api('files/unassign',{body:{file_id:state.selectedFileId}}); state.selectedFileId=null; await Promise.all([loadFiles(),loadDashboard()]); toast('Associazione rimossa');}catch(e){toast(e.message,'error');} }

function toggleFolderJobCreate(show){
  const form=$('#folderJobCreateForm');
  if(!form)return;
  form.hidden=!show;
  if(show){
    const firstType=state.jobTypes.find(function(t){ return t.source_type==='SMB_FOLDER'; });
    if(firstType)$('#folderNewType').value=String(firstType.id);
    $('#folderNewCode')?.focus();
  }else{
    form.reset();
    updateSelects();
  }
}
async function createFolderJob(e){
  e.preventDefault();
  const machineId=Number($('#folderMachine').value||0);
  const clientId=Number($('#folderNewClient').value||0);
  const typeId=Number($('#folderNewType').value||0);
  const code=$('#folderNewCode').value.trim();
  const title=$('#folderNewTitle').value.trim();
  if(!machineId||!clientId||!typeId||!code||!title)return toast('Compila cliente, tipo, codice e titolo','error');
  try{
    const created=await api('jobs',{body:{
      client_id:clientId,
      job_type_id:typeId,
      machine_id:machineId,
      job_code:code,
      title:title,
      status:'aperta',
      notes:$('#folderNewNotes').value.trim()
    }});
    const rows=await api('jobs',{query:{q:code}});
    const job=rows.find(function(r){ return Number(r.id)===Number(created.id); });
    if(job&&!state.jobs.some(function(r){ return Number(r.id)===Number(job.id); }))state.jobs.unshift(job);
    updateSelects();
    $('#assignJob').value=String(created.id);
    if(state.selectedFileId){
      await api('files/assign',{body:{file_id:state.selectedFileId,job_id:created.id}});
      state.selectedFileId=null;
      await Promise.all([loadFiles(),loadDashboard()]);
      toast('Commessa creata e file associato');
    }else{
      toast('Commessa creata e selezionata');
    }
    toggleFolderJobCreate(false);
  }catch(err){toast(err.message,'error');}
}

async function loadScheduling(){ try{ const d=await api('scheduling'); renderScheduling(d); const r=d.last_run, cards=$('#dashboardCards'); if(cards){ const old=$('#dashboardSchedulerCard'); if(old)old.remove(); const txt=!d.enabled?'Disabilitato':(!r?'Non avviato':(r.success==1?'Attivo':'Con errori')); cards.insertAdjacentHTML('beforeend',`<div class="card metric" id="dashboardSchedulerCard"><span>Scheduler</span><strong>${esc(txt)}</strong><div class="muted small">${r?esc(fmtDateTime(r.started_at)):'Nessuna esecuzione registrata'}</div></div>`); } }catch(e){ /* migration may not yet be applied */ } }
function renderScheduling(d){ fillForm($('#schedulingForm'),d); state.refreshSeconds=Number(d.dashboard_refresh_seconds||10); const r=d.last_run; $('#schedulerLastRun').innerHTML=r?`<strong>${r.success==1?'Completata':'Con errori'}</strong><div>Avvio: ${esc(fmtDateTime(r.started_at))}</div><div>Macchine: ${esc(r.machines_ok)}/${esc(r.machines_total)}</div>${r.error_message?`<div class="error-text">${esc(r.error_message)}</div>`:''}`:'Nessuna esecuzione registrata.'; }
async function runScheduler(){ try{ const d=await api('scheduler/run',{body:{}}); toast(d.success?'Sincronizzazione completata':'Sincronizzazione completata con errori',d.success?'ok':'error'); await Promise.all([loadScheduling(),loadDashboard(),loadJobs(),loadProduction()]); }catch(e){toast(e.message,'error');} }
async function loadCosts(){ try{ const d=await api('costs'); fillForm($('#costForm'),d); }catch(e){} }

async function loadCompanySettings(){
  try{
    state.company=await api('company-settings');
    renderCompanySettings();
    renderCompanyReportHeader();
  }catch(e){}
}
function renderCompanySettings(){
  const form=$('#companyForm');
  if(form)fillForm(form,state.company||{});
  const header=$('#headerCompanyName'); if(header)header.textContent=(state.company&&state.company.name)?state.company.name:'Sistema gestionale';
  const box=$('#companyLogoPreview');
  if(box){
    if(state.company&&state.company.logo_path){
      box.innerHTML='<img src="'+esc(state.company.logo_path)+'?v='+Date.now()+'" alt="Logo aziendale">';
    }else{
      box.textContent='Nessun logo configurato.';
    }
  }
}
async function uploadCompanyLogo(e){
  e.preventDefault();
  const input=$('#companyLogoFile');
  if(!input||!input.files||!input.files.length)return toast('Seleziona un logo','error');
  try{
    const fd=new FormData();
    fd.append('logo',input.files[0]);
    const res=await fetch('api.php?route=company-logo',{method:'POST',body:fd,cache:'no-store'});
    const json=await res.json().catch(function(){ return {ok:false,error:'Risposta server non JSON'}; });
    if(!res.ok||!json.ok)throw new Error(json.error||('Errore HTTP '+res.status));
    state.company.logo_path=json.data.logo_path;
    input.value='';
    renderCompanySettings();
    renderCompanyReportHeader();
    toast('Logo aziendale aggiornato');
  }catch(err){toast(err.message,'error');}
}

console.info('JD Industry UI build 20260928-1446');
window.addEventListener('DOMContentLoaded',()=>bootstrap().catch(err=>{ console.error('Bootstrap UI fallito:',err); toast(err?.message||'Errore inizializzazione','error'); }));
