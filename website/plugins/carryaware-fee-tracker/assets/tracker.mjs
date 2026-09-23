export const STATUS = {
  verified_full_or_substantial: 'Full / substantial', verified_partial: 'Partial relief',
  announced_pending_documents: 'Pending documents', under_consideration: 'Under consideration',
  inactive_or_repealed: 'Inactive / repealed'
};
const SOURCES = {official_resolution:'Official resolution',official_minutes:'Official minutes',official_police:'Official police guidance',official_agenda:'Official agenda · proposal only',official_notice:'Official public notice',advocacy_reporting:'Advocacy reporting',secondary_reporting:'Secondary reporting',social_media:'Social media'};
export const https = value => {try {const u=new URL(value);return u.protocol==='https:' && !!u.hostname && !u.username && !u.password && !/\s/.test(value);}catch{return false;}};
const validDate = value => value===null || (typeof value==='string' && /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(Date.parse(value)) && new Date(value).toISOString().slice(0,10)===value);
export function validateFeed(feed) {
  if (!feed || feed.schema_version!==1 || feed.state_municipality_count!==564 || !Array.isArray(feed.municipalities) || !validDate(feed.last_verified_at) || !validDate(feed.last_checked_at) || !feed.last_checked_at) throw new Error('Unrecognized tracker data');
  const ids=new Set();
  for(const r of feed.municipalities){
    if (!r || !/^\d{4}$/.test(r.municipality_code) || ids.has(r.municipality_code) || !Object.hasOwn(STATUS,r.status) || !Object.hasOwn(SOURCES,r.source_type)) throw new Error('Invalid municipality record');
    ids.add(r.municipality_code);
    for(const key of ['municipality','county','municipality_type','application_instructions','eligibility_summary','notes']) if(typeof r[key]!=='string' || !r[key].trim()) throw new Error('Incomplete record');
    for(const key of ['refund_amount','net_municipal_cost']) if(r[key]!==null && (typeof r[key]!=='number' || !Number.isFinite(r[key]) || r[key]<0 || r[key]>150)) throw new Error('Invalid fee');
    for(const key of ['effective_date','retroactive_date','verified_at','last_checked_at']) if(!validDate(r[key])) throw new Error('Invalid date');
    for(const key of ['official_source_url','secondary_source_url']) if(r[key]!==null && !https(r[key])) throw new Error('Invalid source');
    if(!r.official_source_url && !r.secondary_source_url) throw new Error('Source missing');
    if(r.status.startsWith('verified_') && !r.verified_at) throw new Error('Missing verification date');
    if(r.status==='announced_pending_documents' && r.verified_at!==null) throw new Error('Pending cannot be verified');
  }
  return feed;
}
export function totals(feed){const rows=feed.municipalities;return {total:rows.length,full:rows.filter(r=>r.status==='verified_full_or_substantial').length,partial:rows.filter(r=>r.status==='verified_partial').length,pending:rows.filter(r=>['announced_pending_documents','under_consideration'].includes(r.status)).length,percent:(rows.length/564*100).toFixed(2)+'%',counties:new Set(rows.map(r=>r.county)).size};}
export function selectRows(rows,{search='',county='',status='',sort='municipality',direction=1}={}){
  const q=search.trim().toLocaleLowerCase('en-US');
  return rows.filter(r=>r.municipality.toLocaleLowerCase('en-US').includes(q) && (!county || r.county===county) && (!status || r.status===status)).sort((a,b)=>{
    const x=a[sort],y=b[sort]; if(x===null && y!==null)return 1;if(y===null && x!==null)return -1;
    const comparison=typeof x==='number' && typeof y==='number'?x-y:String(x??'').localeCompare(String(y??''),'en-US');
    return comparison*direction || a.municipality.localeCompare(b.municipality,'en-US');
  });
}
export function dateLabel(value){return value?new Intl.DateTimeFormat('en-US',{month:'short',day:'numeric',year:'numeric',timeZone:'UTC'}).format(new Date(value+'T12:00:00Z')):'Not confirmed';}
const el=(tag,text,cls)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;};
function link(text,url){const a=el('a',text);a.href=url;a.target='_blank';a.rel='noopener noreferrer';return a;}
export async function mount(host,feedURL){
  const root=host.querySelector('.cft');if(!root)return;
  const form=root.querySelector('form'),tbody=root.querySelector('tbody'),message=root.querySelector('[data-feed-message]');
  let feed=null, sort='municipality',direction=1;
  const cacheKey='carryaware-fees-v1:'+feedURL;
  function showMessage(text,retry=false){message.replaceChildren(el('span',text));message.hidden=false;if(retry){const b=el('button','Try again');b.type='button';b.addEventListener('click',load);message.append(b);}}
  function render(){
    if(!feed)return;
    const rows=selectRows(feed.municipalities,{search:form.elements.municipality.value,county:form.elements.county.value,status:form.elements.status.value,sort,direction});
    root.querySelector('[data-result-count]').textContent=`Showing ${rows.length} of ${feed.municipalities.length} municipalities`;
    root.querySelector('[data-empty]').hidden=rows.length>0;tbody.replaceChildren();
    for(const r of rows){
      const tr=el('tr');tr.dataset.code=r.municipality_code;
      function cell(label){const td=el('td');td.dataset.label=label;tr.append(td);return td;}
      cell('Municipality').append(el('strong',r.municipality,'cft-town'));
      cell('County').append(el('span',r.county));
      const relief=cell('Relief');relief.append(el('span',r.refund_amount===null?'Amount not confirmed':`$${r.refund_amount} refund`,r.refund_amount===null?'cft-unknown':'cft-amount'));
      if(r.net_municipal_cost!==null)relief.append(el('span',`$${r.net_municipal_cost} municipal cost after refund`,'cft-cell-note'));
      cell('Effective date').append(el('span',dateLabel(r.effective_date)));
      const kind=r.status==='verified_partial'?'partial':r.status.includes('pending')||r.status==='under_consideration'?'pending':r.status==='inactive_or_repealed'?'inactive':'';
      cell('Status').append(el('span',STATUS[r.status],'cft-status '+kind));
      const detail=el('details');detail.append(el('summary','How to apply'),el('p',r.eligibility_summary),el('p',r.application_instructions),el('p',r.notes,'cft-evidence'));
      if(r.retroactive_date)detail.append(el('p',`Eligible payment date: ${dateLabel(r.retroactive_date)}.`,'cft-evidence'));
      cell('Instructions').append(detail);
      const source=cell('Official source / evidence');source.className='cft-source';
      if(r.official_source_url)source.append(link('Official source ↗',r.official_source_url));
      else source.append(el('span','Municipal document not verified','cft-unknown'));
      source.append(el('span',SOURCES[r.source_type],'cft-source-label'));
      if(r.secondary_source_url)source.append(link(r.source_type==='advocacy_reporting'?'NRA-ILA report ↗':r.municipality_code==='1506'?'Official fee listing ↗':'Additional source ↗',r.secondary_source_url));
      source.append(el('span',r.verified_at?`Evidence reviewed ${dateLabel(r.verified_at)}`:`Adoption unverified · checked ${dateLabel(r.last_checked_at)}`,'cft-cell-note'));
      tbody.append(tr);
    }
    for(const button of root.querySelectorAll('[data-sort]')){button.parentElement.removeAttribute('aria-sort');if(button.dataset.sort===sort)button.parentElement.setAttribute('aria-sort',direction===1?'ascending':'descending');}
  }
  function apply(data){feed=validateFeed(data);for(const [key,value] of Object.entries(totals(feed)))root.querySelector(`[data-stat="${key}"]`).textContent=value;
    root.querySelector('[data-last-verified]').textContent=`Last verified: ${dateLabel(feed.last_verified_at)}`;
    root.querySelector('[data-last-checked]').textContent=`Sources checked: ${dateLabel(feed.last_checked_at)}`;
    const featured=feed.municipalities.find(r=>r.municipality_code==='1525');
    const notice=root.querySelector('[data-featured-notice]');notice.hidden=!featured;
    if(featured){
      root.querySelector('[data-featured-status]').textContent=STATUS[featured.status].toUpperCase();
      root.querySelector('#cft-latest').textContent=featured.status==='announced_pending_documents'?'Point Pleasant Borough moves to refund its municipal fee':'Point Pleasant Borough: '+STATUS[featured.status];
      root.querySelector('[data-featured-summary]').textContent=featured.eligibility_summary+' '+featured.notes;
      const source=root.querySelector('[data-featured-source]');source.hidden=!featured.official_source_url;if(featured.official_source_url)source.href=featured.official_source_url;
    }
    const selected=form.elements.county.value;form.elements.county.replaceChildren(new Option('All counties',''));
    for(const county of [...new Set(feed.municipalities.map(r=>r.county))].sort())form.elements.county.add(new Option(county,county));
    form.elements.county.value=selected;render();
  }
  async function load(){
    message.hidden=true;const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),12000);
    try {const response=await fetch(feedURL,{signal:controller.signal,cache:'no-store',credentials:'omit'});if(!response.ok)throw new Error('Feed unavailable');
      const text=await response.text();if(text.length>1000000)throw new Error('Feed too large');const data=validateFeed(JSON.parse(text));apply(data);
      try{sessionStorage.setItem(cacheKey,JSON.stringify(data));}catch{}
    }catch{
      let cached=null;try{const raw=sessionStorage.getItem(cacheKey);if(raw)cached=validateFeed(JSON.parse(raw));}catch{}
      if(cached){apply(cached);showMessage(`The live feed is unavailable. Showing a saved snapshot checked ${dateLabel(cached.last_checked_at)}; it may be out of date. Confirm with the municipality.`,true);}
      else{feed=null;root.querySelector('[data-featured-notice]').hidden=true;tbody.replaceChildren();for(const n of root.querySelectorAll('[data-stat]'))n.textContent='—';root.querySelector('[data-last-verified]').textContent='Verification dates unavailable';root.querySelector('[data-last-checked]').textContent='';root.querySelector('[data-result-count]').textContent='Municipality records are temporarily unavailable.';root.querySelector('[data-empty]').hidden=true;showMessage('We could not load the fee tracker. Try again or contact support@mignonelabs.com. Confirm fees directly with your municipality.',true);}
    }finally{clearTimeout(timer);}
  }
  form.addEventListener('submit',e=>e.preventDefault());form.addEventListener('input',e=>{if(e.target.tagName==='INPUT')render();});form.addEventListener('change',e=>{if(e.target.tagName==='SELECT')render();});form.addEventListener('reset',()=>setTimeout(render,0));
  root.querySelector('[name=sort]').addEventListener('change',e=>{sort=e.target.value;direction=1;render();});
  for(const button of root.querySelectorAll('[data-sort]'))button.addEventListener('click',()=>{direction=sort===button.dataset.sort?-direction:1;sort=button.dataset.sort;root.querySelector('[name=sort]').value=sort;render();});
  await load();
}
if(typeof document!=='undefined')for(const host of document.querySelectorAll('[data-carryaware-fee-tracker]'))mount(host,host.dataset.feedUrl);
