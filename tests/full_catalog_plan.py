"""Optional private-data check. Input remains local and is never committed or sent to CI."""
import os,uuid,json
from pathlib import Path
from integration import admin_login,check

source=Path(os.environ['MIK_TEST_FULL_CSV']);payload=source.read_bytes();admin=admin_login();token=uuid.uuid4().hex;result=None
for offset in range(0,len(payload),1048576):
    result,code=admin.multipart('import/upload',{'kind':'catalog','upload_id':token,'id':0,'offset':offset,'total':len(payload)},payload[offset:offset+1048576])
    assert code==200,result
job=result['id'];admin.api('import/plan',{'id':job,'mb':512});steps=0
while True:
    plan=admin.api('import/plan-step',{'id':job})[0];steps+=1
    if steps%20==0:print('FULL CATALOG PLANNING:',plan['total_rows'],flush=True)
    if plan['status']=='ready':break
    assert steps<1000
expected=json.loads((source.parent/'catalog-report.json').read_text())['products']
assert plan['total_rows']==expected,(plan['total_rows'],expected)
assert sum(p['rows_count'] for p in plan['packages'])==expected
assert all(p['selected']==1 for p in plan['packages'])
assert all(p['estimated_bytes']<=512*1048576 for p in plan['packages'])
output={'products':expected,'packages':len(plan['packages']),'planning_requests':steps,'csv_bytes':len(payload),'package_limit_mb':512,'result':'passed'}
(Path(__file__).resolve().parents[1]/'.runtime/full-catalog-results.json').write_text(json.dumps(output,indent=2))
print('FULL CATALOG PLAN PASSED:',json.dumps(output),flush=True)
