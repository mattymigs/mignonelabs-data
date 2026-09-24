export const STATUS = {
  confirmed_full_or_substantial: 'Confirmed full/substantial relief',
  confirmed_partial: 'Confirmed partial relief',
  reported_full_or_substantial: 'Reported full/substantial relief',
  announced_pending_documents: 'Pending proposal / documents',
  policy_not_yet_verified: 'Policy not yet verified',
  under_consideration: 'Under consideration',
  inactive_or_repealed: 'Inactive / repealed'
};
const SOURCES = {official_resolution:'Official resolution',official_minutes:'Official minutes',official_police:'Official police guidance',official_agenda:'Official agenda · proposal only',official_notice:'Official public notice',advocacy_reporting:'Advocacy reporting',secondary_reporting:'Secondary reporting',social_media:'Social media'};
const CONFIRMED_SOURCES = new Set(['official_resolution','official_minutes','official_police','official_notice']);
const COUNTY_CODES = ['Atlantic','Bergen','Burlington','Camden','Cape May','Cumberland','Essex','Gloucester','Hudson','Hunterdon','Mercer','Middlesex','Monmouth','Morris','Ocean','Passaic','Salem','Somerset','Sussex','Union','Warren'];
export const PAGE_SIZE = 20;
export const https = value => {try {const u=new URL(value);return u.protocol==='https:' && !!u.hostname && !u.username && !u.password && !/\s/.test(value);}catch{return false;}};
const validDate = value => value===null || (typeof value==='string' && /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(Date.parse(value)) && new Date(value).toISOString().slice(0,10)===value);

// The old feed called advocacy reports "verified". Read it without carrying that claim into the UI.
export function policyStatus(row) {
  if(row.status==='verified_full_or_substantial') return CONFIRMED_SOURCES.has(row.source_type) ? 'confirmed_full_or_substantial' : 'reported_full_or_substantial';
  if(row.status==='verified_partial') return 'confirmed_partial';
  return row.status;
}
export function validateFeed(feed) {
  if (!feed || ![1,2].includes(feed.schema_version) || feed.state_municipality_count!==564 || !Array.isArray(feed.municipalities) || feed.municipalities.length>564 || !validDate(feed.last_verified_at) || !validDate(feed.last_checked_at) || !feed.last_checked_at) throw new Error('Unrecognized tracker data');
  const ids=new Set();
  for(const r of feed.municipalities){
    const status=policyStatus(r || {}), unknown=status==='policy_not_yet_verified';
    if (!r || typeof r.municipality_code!=='string' || !/^\d{4}$/.test(r.municipality_code) || ids.has(r.municipality_code) || !Object.hasOwn(STATUS,status) || (feed.schema_version===2 && !Object.hasOwn(STATUS,r.status))) throw new Error('Invalid municipality record');
    ids.add(r.municipality_code);
    for(const key of ['municipality','county','municipality_type','application_instructions','eligibility_summary','notes']) if(typeof r[key]!=='string' || !r[key].trim()) throw new Error('Incomplete record');
    if(COUNTY_CODES[Number(r.municipality_code.slice(0,2))-1]!==r.county) throw new Error('County does not match municipality code');
    for(const key of ['refund_amount','net_municipal_cost']) if(r[key]!==null && (typeof r[key]!=='number' || !Number.isFinite(r[key]) || r[key]<0 || r[key]>150)) throw new Error('Invalid fee');
    if(r.statutory_municipal_portion!==150 || (r.refund_amount!==null && r.net_municipal_cost!==null && Math.abs(r.refund_amount+r.net_municipal_cost-150)>0.000001)) throw new Error('Inconsistent municipal fee');
    for(const key of ['effective_date','retroactive_date','verified_at','last_checked_at']) if(!validDate(r[key])) throw new Error('Invalid date');
    for(const key of ['official_source_url','secondary_source_url']) if(r[key]!==null && !https(r[key])) throw new Error('Invalid source');
    if(unknown){
      for(const key of ['refund_amount','net_municipal_cost','effective_date','retroactive_date','verified_at','last_checked_at','source_type','official_source_url','secondary_source_url']) if(r[key]!==null) throw new Error('Unverified policy cannot imply an amount, source, or review');
      if(r.relief_type!=='unknown' || !Array.isArray(r.evidence) || r.evidence.length!==0) throw new Error('Unverified policy cannot contain reviewed evidence');
    }else{
      if(!Object.hasOwn(SOURCES,r.source_type) || (!r.official_source_url && !r.secondary_source_url)) throw new Error('Source missing');
      if(!r.last_checked_at) throw new Error('Missing source-check date');
      if(status.startsWith('confirmed_') && (!r.verified_at || !r.official_source_url || !CONFIRMED_SOURCES.has(r.source_type))) throw new Error('Confirmed relief requires reviewed official evidence');
      if(status==='announced_pending_documents' && r.verified_at!==null) throw new Error('Pending cannot be verified');
      if(feed.schema_version===2 && (!Array.isArray(r.evidence) || r.evidence.length===0)) throw new Error('Policy evidence missing');
    }
    if(feed.schema_version===2 && (!https(r.directory_source_url) || !validDate(r.directory_checked_at) || !r.directory_checked_at || r.directory_checked_at!==feed.directory_roster?.checked_at)) throw new Error('Directory provenance missing');
  }
  if(feed.schema_version===2){
    const roster=feed.directory_roster;
    if(!roster || !https(roster.source_url) || !https(roster.query_url) || !https(roster.secondary_source_url) || typeof roster.source_title!=='string' || !roster.source_title.trim() || !validDate(roster.checked_at) || !roster.checked_at || roster.municipality_count!==564 || roster.county_count!==21 || ids.size!==564 || new Set(feed.municipalities.map(r=>r.county)).size!==21) throw new Error('Incomplete statewide directory');
  }
  return feed;
}
export function totals(feed){
  const rows=feed.municipalities, statuses=rows.map(policyStatus), count=predicate=>statuses.filter(predicate).length;
  const unverified=count(status=>status==='policy_not_yet_verified'), researched=rows.length-unverified;
  return {total:rows.length,stateTotal:feed.state_municipality_count,coveragePercent:(rows.length/feed.state_municipality_count*100).toFixed(2)+'%',counties:new Set(rows.map(r=>r.county)).size,researched,researchPercent:(researched/feed.state_municipality_count*100).toFixed(2)+'%',confirmed:count(status=>status.startsWith('confirmed_')),reported:count(status=>status.startsWith('reported_')),pending:count(status=>['announced_pending_documents','under_consideration'].includes(status)),unverified};
}
export function selectRows(rows,{search='',county='',status='',sort='municipality',direction=1}={}){
  const q=search.trim().toLocaleLowerCase('en-US');
  return rows.filter(r=>r.municipality.toLocaleLowerCase('en-US').includes(q) && (!county || r.county===county) && (!status || policyStatus(r)===status)).sort((a,b)=>{
    const x=sort==='status'?STATUS[policyStatus(a)]:a[sort],y=sort==='status'?STATUS[policyStatus(b)]:b[sort]; if(x===null && y!==null)return 1;if(y===null && x!==null)return -1;
    const comparison=typeof x==='number' && typeof y==='number'?x-y:String(x??'').localeCompare(String(y??''),'en-US');
    return comparison*direction || a.municipality.localeCompare(b.municipality,'en-US') || a.county.localeCompare(b.county,'en-US') || a.municipality_code.localeCompare(b.municipality_code);
  });
}
export function paginate(rows,page=1,pageSize=PAGE_SIZE){
  const pageCount=Math.max(1,Math.ceil(rows.length/pageSize)), currentPage=Math.max(1,Math.min(Math.floor(page)||1,pageCount)), start=(currentPage-1)*pageSize;
  return {rows:rows.slice(start,start+pageSize),page:currentPage,pageCount,start:rows.length?start+1:0,end:Math.min(start+pageSize,rows.length),total:rows.length};
}
export function dateLabel(value){return value?new Intl.DateTimeFormat('en-US',{month:'short',day:'numeric',year:'numeric',timeZone:'UTC'}).format(new Date(value+'T12:00:00Z')):'Not confirmed';}
const el=(tag,text,cls)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;};
function link(text,url){const a=el('a',text);a.href=url;a.target='_blank';a.rel='noopener noreferrer';return a;}
export async function mount(host,feedURL){
  const root=host.querySelector('.cft');if(!root)return;
  const form=root.querySelector('form'),tbody=root.querySelector('tbody'),message=root.querySelector('[data-feed-message]');
  let feed=null, sort='municipality',direction=1,page=1;
  const privateSnapshot=host.dataset.previewMode==='private-snapshot';
  const cacheKey='carryaware-fees-v2:'+feedURL;
  function showMessage(text,retry=false){message.replaceChildren(el('span',text));message.hidden=false;if(retry){const b=el('button','Try again');b.type='button';b.addEventListener('click',load);message.append(b);}}
  function render(){
    if(!feed)return;
    const rows=selectRows(feed.municipalities,{search:form.elements.municipality.value,county:form.elements.county.value,status:form.elements.status.value,sort,direction});
    const slice=paginate(rows,page);page=slice.page;
    root.querySelector('[data-result-count]').textContent=rows.length?`Showing ${slice.start}–${slice.end} of ${rows.length} matching municipalities (${feed.municipalities.length} in directory)`:'No matching municipalities';
    root.querySelector('[data-empty]').hidden=rows.length>0;tbody.replaceChildren();
    for(const nav of root.querySelectorAll('[data-pagination]')){
      nav.hidden=rows.length<=PAGE_SIZE;
      nav.querySelector('[data-page-label]').textContent=`Page ${page} of ${slice.pageCount}`;
      nav.querySelector('[data-page="previous"]').disabled=page===1;
      nav.querySelector('[data-page="next"]').disabled=page===slice.pageCount;
    }
    for(const r of slice.rows){
      const status=policyStatus(r),unknown=status==='policy_not_yet_verified';
      const tr=el('tr');tr.dataset.code=r.municipality_code;
      function cell(label){const td=el('td');td.dataset.label=label;tr.append(td);return td;}
      const town=cell('Municipality');town.append(el('strong',r.municipality,'cft-town'),el('span',`${r.municipality_type} · NJ code ${r.municipality_code}`,'cft-cell-note'));
      cell('County').append(el('span',r.county));
      const relief=cell('Relief');relief.append(el('span',r.refund_amount===null?(unknown?'Policy not yet verified':'Amount not confirmed'):`$${r.refund_amount} refund`,r.refund_amount===null?'cft-unknown':'cft-amount'));
      if(r.net_municipal_cost!==null)relief.append(el('span',`$${r.net_municipal_cost} municipal cost after refund`,'cft-cell-note'));
      cell('Effective date').append(el('span',unknown?'Not researched':dateLabel(r.effective_date)));
      const kind=unknown?'unverified':status.startsWith('reported_')?'reported':status==='confirmed_partial'?'partial':status.includes('pending')||status==='under_consideration'?'pending':status==='inactive_or_repealed'?'inactive':'confirmed';
      cell('Policy status').append(el('span',STATUS[status],'cft-status '+kind));
      const instructions=cell('Instructions');
      if(unknown) instructions.append(el('span','Local policy research is still needed. Confirm fees and any relief with the municipality.','cft-unknown'));
      else{
        const detail=el('details');detail.append(el('summary','How to apply'),el('p',r.eligibility_summary),el('p',r.application_instructions),el('p',r.notes,'cft-evidence'));
        if(r.retroactive_date)detail.append(el('p',`Eligible payment date: ${dateLabel(r.retroactive_date)}.`,'cft-evidence'));
        instructions.append(detail);
      }
      const source=cell('Policy evidence');source.className='cft-source';
      if(unknown){
        source.append(el('span','No policy evidence collected','cft-unknown'),el('span','Policy review: not yet researched','cft-cell-note'));
      }else{
        if(r.official_source_url)source.append(link('Official source ↗',r.official_source_url));
        else source.append(el('span','Municipal document not verified','cft-unknown'));
        source.append(el('span',SOURCES[r.source_type],'cft-source-label'));
        if(r.secondary_source_url)source.append(link(r.source_type==='advocacy_reporting'?'NRA-ILA report ↗':r.municipality_code==='1506'?'Official fee listing ↗':'Additional source ↗',r.secondary_source_url));
        source.append(el('span',r.verified_at?`Evidence reviewed ${dateLabel(r.verified_at)}`:`Adoption unverified · source checked ${dateLabel(r.last_checked_at)}`,'cft-cell-note'));
      }
      tbody.append(tr);
    }
    for(const button of root.querySelectorAll('[data-sort]')){button.parentElement.removeAttribute('aria-sort');if(button.dataset.sort===sort)button.parentElement.setAttribute('aria-sort',direction===1?'ascending':'descending');}
  }
  function apply(data){
    feed=validateFeed(data);const stats=totals(feed);
    for(const [key,value] of Object.entries(stats))for(const node of root.querySelectorAll(`[data-stat="${key}"]`))node.textContent=value;
    root.querySelector('[data-research-summary]').textContent=`${stats.researched} of ${stats.stateTotal} municipalities (${stats.researchPercent}) have collected policy evidence. These include confirmed relief, attributed reports, and pending proposals.`;
    root.querySelector('[data-directory-summary]').textContent=feed.schema_version===2?'All New Jersey municipalities are listed. Directory coverage is separate from policy research.':`This older feed lists ${stats.total} evidence entries; the remaining municipalities are not included in this snapshot. It is not a complete statewide directory.`;
    root.querySelector('[data-last-verified]').textContent=`Policy evidence last reviewed: ${dateLabel(feed.last_verified_at)}`;
    root.querySelector('[data-last-checked]').textContent=`Policy sources checked: ${dateLabel(feed.last_checked_at)}`;
    const provenance=root.querySelector('[data-roster-source]');provenance.replaceChildren();
    if(feed.directory_roster){
      const roster=feed.directory_roster;
      provenance.append(el('span',`Municipality roster checked ${dateLabel(roster.checked_at)}: `),link(roster.source_title,roster.source_url),document.createTextNode('. Cross-check: '),link(roster.secondary_source_title || 'NJ municipality directory',roster.secondary_source_url),document.createTextNode('. This is a directory check, not a policy review.'));
    }else provenance.append(el('span','Complete municipality-roster provenance is unavailable in this older snapshot.'));
    const featured=feed.municipalities.find(r=>r.municipality_code==='1525');
    const notice=root.querySelector('[data-featured-notice]');notice.hidden=!featured;
    if(featured){
      root.querySelector('[data-featured-status]').textContent=STATUS[policyStatus(featured)].toUpperCase();
      root.querySelector('#cft-latest').textContent=featured.status==='announced_pending_documents'?'Point Pleasant Borough proposal remains pending':'Point Pleasant Borough: '+STATUS[policyStatus(featured)];
      root.querySelector('[data-featured-summary]').textContent=featured.eligibility_summary+' '+featured.notes;
      const source=root.querySelector('[data-featured-source]');source.hidden=!featured.official_source_url;if(featured.official_source_url)source.href=featured.official_source_url;
    }
    const selected=form.elements.county.value;form.elements.county.replaceChildren(new Option('All counties',''));
    for(const county of [...new Set(feed.municipalities.map(r=>r.county))].sort())form.elements.county.add(new Option(county,county));
    form.elements.county.value=selected;render();
  }
  function clearFeed(){
    feed=null;root.querySelector('[data-featured-notice]').hidden=true;tbody.replaceChildren();
    for(const n of root.querySelectorAll('[data-stat]'))n.textContent='—';
    for(const n of root.querySelectorAll('[data-pagination]'))n.hidden=true;
    root.querySelector('[data-last-verified]').textContent='Policy review dates unavailable';root.querySelector('[data-last-checked]').textContent='';
    root.querySelector('[data-research-summary]').textContent='Research progress is unavailable until a valid dataset is loaded.';
    root.querySelector('[data-directory-summary]').textContent='Directory coverage is unavailable until a valid dataset is loaded.';
    root.querySelector('[data-roster-source]').replaceChildren();root.querySelector('[data-empty]').hidden=true;
  }
  async function load(){
    message.hidden=true;const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),12000);
    try {const response=await fetch(feedURL,{signal:controller.signal,cache:'no-store',credentials:'omit'});if(!response.ok)throw new Error('Feed unavailable');
      const text=await response.text();if(text.length>1000000)throw new Error('Feed too large');const data=validateFeed(JSON.parse(text));apply(data);
      try{sessionStorage.setItem(cacheKey,JSON.stringify(data));}catch{}
    }catch{
      let cached=null;try{const raw=sessionStorage.getItem(cacheKey);if(raw)cached=validateFeed(JSON.parse(raw));}catch{}
      if(cached){apply(cached);showMessage(`The live feed is unavailable. Showing a saved snapshot whose policy sources were checked ${dateLabel(cached.last_checked_at)}; it may be out of date. Confirm with the municipality.`,true);}
      else{clearFeed();root.querySelector('[data-result-count]').textContent='Municipality records are temporarily unavailable.';showMessage('We could not load the fee tracker. Try again or contact support@mignonelabs.com. Confirm fees directly with your municipality.',true);}
    }finally{clearTimeout(timer);}
  }
  const resetPage=()=>{page=1;render();};
  form.addEventListener('submit',e=>e.preventDefault());form.addEventListener('input',e=>{if(e.target.tagName==='INPUT')resetPage();});form.addEventListener('change',e=>{if(e.target.tagName==='SELECT')resetPage();});
  form.addEventListener('reset',()=>setTimeout(()=>{sort='municipality';direction=1;root.querySelector('[name=sort]').value=sort;resetPage();},0));
  root.querySelector('[name=sort]').addEventListener('change',e=>{sort=e.target.value;direction=1;resetPage();});
  for(const button of root.querySelectorAll('[data-sort]'))button.addEventListener('click',()=>{direction=sort===button.dataset.sort?-direction:1;sort=button.dataset.sort;root.querySelector('[name=sort]').value=sort;resetPage();});
  for(const button of root.querySelectorAll('[data-page]'))button.addEventListener('click',()=>{
    page+=button.dataset.page==='next'?1:-1;render();
    const count=root.querySelector('[data-result-count]');count.focus({preventScroll:true});count.scrollIntoView({block:'start'});
  });
  if(privateSnapshot){
    // Authentication happens in PHP. This mode never fetches or touches sessionStorage.
    root.prepend(el('p','PRIVATE PREVIEW — saved snapshot; no live updates. Visible only to administrators on an unpublished page.','cft-feed-message'));
    try{
      const source=host.querySelector(':scope > script[type="application/json"][data-cft-preview-data]');
      if(!source || source.textContent.length>1000000)throw new Error('Preview snapshot missing');
      apply(JSON.parse(source.textContent));
    }catch{
      clearFeed();root.querySelector('[data-result-count]').textContent='Private preview data is unavailable.';
      showMessage('The private snapshot is missing or invalid. Save valid review JSON in WordPress tracker settings, then reload this draft. No live data was loaded.');
    }
  }else await load();
}
if(typeof document!=='undefined')for(const host of document.querySelectorAll('[data-carryaware-fee-tracker]'))mount(host,host.dataset.feedUrl);
