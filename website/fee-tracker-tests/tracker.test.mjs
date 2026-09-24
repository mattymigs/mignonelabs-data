import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {validateFeed,totals,selectRows,dateLabel,https} from '../plugins/carryaware-fee-tracker/assets/tracker.mjs';
const path=process.env.CARRY_FEE_DATA_PATH;
if(!path)throw Error('Set CARRY_FEE_DATA_PATH to the carryaware-data JSON. No copied dataset.');
const feed=JSON.parse(fs.readFileSync(path,'utf8'));
test('shared data and totals',()=>assert.deepEqual(totals(validateFeed(feed)),{total:20,full:18,partial:1,pending:1,percent:'3.55%',counties:8}));
test('search/county/status compose',()=>{const rows=selectRows(feed.municipalities,{search:'Berkeley',county:'Ocean',status:'verified_partial'});assert.equal(rows.length,1);assert.equal(rows[0].refund_amount,100);});
test('Borough and Beach are distinct',()=>{assert.equal(selectRows(feed.municipalities,{search:'Point Pleasant Beach'}).length,0);assert.equal(selectRows(feed.municipalities,{search:'Point Pleasant'}).length,1);});
test('amount sort is numeric with unknown values last both ways',()=>{assert.equal(selectRows(feed.municipalities,{sort:'refund_amount'})[0].refund_amount,100);const desc=selectRows(feed.municipalities,{sort:'refund_amount',direction:-1});assert.equal(desc[0].refund_amount,150);assert.equal(desc.at(-1).refund_amount,null);});
test('dates are stable across time zones',()=>{assert.equal(dateLabel('2026-05-18'),'May 18, 2026');assert.equal(dateLabel(null),'Not confirmed');});
test('malformed data never renders as totals',()=>{for(const mutate of [x=>x.municipalities.push(x.municipalities[0]),x=>x.municipalities[0].status='invalid',x=>x.municipalities[0].effective_date='2026-02-30',x=>x.municipalities[0].refund_amount='100',x=>x.municipalities[0].official_source_url='javascript:alert(1)']){const x=structuredClone(feed);mutate(x);assert.throws(()=>validateFeed(x));}});
test('HTTPS rejects credentials and active schemes',()=>{for(const u of ['http://x.com','javascript:x','https://u:p@x.com'])assert.equal(https(u),false);assert.equal(https('https://ptboro.com/'),true);});
test('refund and remaining municipal cost agree with the statutory portion',()=>{
  const bad=structuredClone(feed);Object.assign(bad.municipalities[0],{refund_amount:100,net_municipal_cost:100});assert.throws(()=>validateFeed(bad),/Inconsistent municipal fee/);
  const wrongPortion=structuredClone(feed);wrongPortion.municipalities[0].statutory_municipal_portion=200;assert.throws(()=>validateFeed(wrongPortion),/Inconsistent municipal fee/);
});
