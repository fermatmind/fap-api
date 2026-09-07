import {readFileSync, writeFileSync, existsSync, appendFileSync} from 'node:fs';
import {MISSIONS} from './seo-platform-12a08-activation.mjs';
const read = path => JSON.parse(readFileSync(path,'utf8'));
const [mode, value] = process.argv.slice(2), index=Number(value);
if (!['prepare','enable'].includes(mode) || !['0','1','2'].includes(value)) throw new Error('A08_TRANSITION_SCOPE_HOLD');
const manifest=read('activation.json'), id=MISSIONS[index];
let ready=manifest.missions[id].source_acceptance.status === 'pass'
  && manifest.missions[id].end_to_end_acceptance?.status !== 'pass';
let generation;
if (mode === 'enable') {
  generation=read(`controlled-${index}.json`).generation;
} else if (index === 0) {
  const state=read('a08-install-after.json');
  // This task authorized the observed initial pause only. Never override a new pause.
  ready=ready && ((state.paused === true && state.selected_missions.length === 0
    && state.generation === '50bc7eec1b1d0aa3277d6237b9f37fa9')
    || (state.paused === false && state.selected_missions.includes(id)));
  generation=state.generation;
} else {
  const state=read('a08-install-after.json');
  const retained=state.paused === false && MISSIONS.slice(0,index).every(mission=>
    state.selected_missions.includes(mission) && manifest.missions[mission].end_to_end_acceptance?.status === 'pass');
  ready=ready && (existsSync(`enabled-${index-1}.json`) || retained);
  if (index === 2) ready=ready && manifest.missions[id].source_acceptance.observed_verdict === 'READY';
  generation=existsSync(`enabled-${index-1}.json`) ? read(`enabled-${index-1}.json`).generation : state.generation;
}
if (mode === 'prepare') appendFileSync(process.env.GITHUB_OUTPUT,`ready=${ready}\n`);
if (mode === 'enable' || ready) writeFileSync(`transition-${index}.json`,JSON.stringify({
  mode:mode === 'enable' ? 'enable' : 'controlled',mission_id:id,sha:manifest.bound_production_sha,expected_generation:generation,
}));
