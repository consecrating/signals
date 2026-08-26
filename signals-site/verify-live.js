/*
 * verify-live.js — run the coverage logic against LIVE production data.
 *
 * Deliberately no fixtures and no mock feed: it fetches from the real proxy at
 * ads.sanctify.co.in and reports what the page would actually do right now. Use
 * this to confirm behaviour both while the market is open and after it closes.
 *
 *   node fetch step:  see PATCH-coverage.md
 *   bun verify-live.js
 */
const fs=require('fs');
eval(fs.readFileSync('signal-coverage.js','utf8'));
const parts=JSON.parse(fs.readFileSync('live_parts.json','utf8'));
const cov=FNOCoverage.assess(parts);
console.log('\n=== coverage computed from LIVE production data ===');
console.log('  market open      :', cov.marketOpen, '('+cov.marketReason+')');
console.log('  factors live     :', cov.factorsLive+'/'+cov.factorsTotal);
console.log('  model weight live:', Math.round(cov.coverage*100)+'%');
console.log('  missing          :', cov.missing.join(', ')||'none');
console.log('  classified as    :', cov.isOutage?'DATA OUTAGE':'market closed (expected)');
const sig=FNOCoverage.applyDiscount({direction:'SELL',confidence:78,actionable:true,vetoes:[]},cov);
console.log('  confidence 78 -> ', sig.confidence, '| direction', sig.direction, '| actionable', sig.actionable);
console.log('  UI label         :', FNOCoverage.factorLabel(cov));
console.log('  banner           :', FNOCoverage.banner(cov).replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().slice(0,140));
