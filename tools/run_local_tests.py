#!/usr/bin/env python3
"""Disposable integration runner. Requires locally extracted PHP/MariaDB and WordPress in .runtime.
Servers and clients intentionally run in one invocation, so isolated execution namespaces work.
"""
import os,shutil,socket,subprocess,time,json,signal
from pathlib import Path

root=Path(__file__).resolve().parents[1];rt=root/'.runtime';wp=rt/'wordpress';env=dict(os.environ)
env['TMPDIR']=str(rt/'tmp');env['LD_LIBRARY_PATH']=str(rt/'root/usr/lib/x86_64-linux-gnu')
env['PHPRC']=str(rt/'php.ini');env['PLAYWRIGHT_BROWSERS_PATH']=str(rt/'browsers')
php=str(rt/'php');base=rt/'root/usr';dbdir=rt/'db-run';log=(rt/'test-run.log').open('w')
def run(cmd,**kw):
    print('+',cmd[0], ' '.join(str(x) for x in cmd[1:3]),flush=True)
    r=subprocess.run([str(x) for x in cmd],cwd=root,env=env,text=True,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,**kw)
    log.write(r.stdout);log.flush();print(r.stdout[-10000:],flush=True)
    if r.returncode:raise RuntimeError('Command failed: '+str(cmd[:3]))
    return r.stdout
cli=[php,str(rt/'wp-cli.phar'),'--allow-root','--path='+str(wp)]
mysql=None;server=None
try:
    (rt/'php-errors.log').write_text('')
    shutil.rmtree(dbdir,ignore_errors=True)
    run([base/'bin/mariadb-install-db','--no-defaults','--basedir='+str(base),'--datadir='+str(dbdir),'--tmpdir='+str(rt/'tmp'),'--auth-root-authentication-method=normal','--skip-test-db','--user=root'])
    mysql=subprocess.Popen([str(base/'sbin/mariadbd'),'--no-defaults','--basedir='+str(base),'--datadir='+str(dbdir),'--socket=','--bind-address=127.0.0.1','--port=33067','--user=root','--tmpdir='+str(rt/'tmp'),'--pid-file='+str(rt/'db-run.pid'),'--innodb-buffer-pool-size=64M','--innodb-use-native-aio=0','--log-error='+str(rt/'db-run.log')],env=env,stdout=log,stderr=log)
    for n in range(100):
        try:
            with socket.create_connection(('127.0.0.1',33067),timeout=.2) as s:s.recv(100)
            break
        except OSError:time.sleep(.1)
    else:raise RuntimeError('MariaDB did not start')
    run([base/'bin/mariadb','--no-defaults','--protocol=TCP','--host=127.0.0.1','--port=33067','-u','root','-e','CREATE DATABASE mik_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'])
    shutil.copytree(root/'mik-shop',wp/'wp-content/plugins/mik-shop',dirs_exist_ok=True)
    mu=wp/'wp-content/mu-plugins';mu.mkdir(exist_ok=True)
    (mu/'templates').mkdir(exist_ok=True);(mu/'mik-test-template.php').unlink(missing_ok=True)
    shutil.copy(root/'tests/fixture-mu.php',mu/'mik-test.php');shutil.copy(root/'tests/fixture-template.php',mu/'templates/mik-test-template.php')
    run(cli+['config','create','--force','--dbname=mik_test','--dbuser=root','--dbpass=','--dbhost=127.0.0.1:33067','--skip-check'])
    config=wp/'wp-config.php';config.write_text(config.read_text().replace("/* That's all, stop editing!", "define('WP_HOME', 'http://127.0.0.1:8095');\ndefine('WP_SITEURL', 'http://127.0.0.1:8095');\n/* That's all, stop editing!"))
    run(cli+['core','install','--url=http://127.0.0.1:8095','--title=MIK Tests','--admin_user=mik_test_admin','--admin_password=Local-Test-Only-Password-2026','--admin_email=admin@example.test','--skip-email'])
    run(cli+['plugin','activate','woocommerce','mik-shop'])
    run(cli+['rewrite','structure','/%postname%/'])
    run(cli+['eval-file',str(root/'tests/wp-fixtures.php')])
    run(cli+['db','query','SHOW TABLES'],check=False) if False else None
    serverenv=dict(env);serverenv['PHP_CLI_SERVER_WORKERS']='3'
    server=subprocess.Popen([php,'-S','127.0.0.1:8095','-t',str(wp),str(root/'tests/router.php')],cwd=root,env=serverenv,stdout=(rt/'http.log').open('w'),stderr=subprocess.STDOUT,start_new_session=True)
    time.sleep(.6)
    run([env.get('CODEX_PRIMARY_RUNTIME_PYTHON','python3'),str(root/'tests/integration.py')])
    if env.get('MIK_TEST_FULL_CSV'):run([env.get('CODEX_PRIMARY_RUNTIME_PYTHON','python3'),str(root/'tests/full_catalog_plan.py')])
    if os.environ.get('MIK_SKIP_BROWSER')!='1':run([env.get('CODEX_PRIMARY_RUNTIME_NODE','node'),str(root/'tests/browser.mjs')])
    print('INTEGRATION RUN COMPLETE',flush=True)
finally:
    if server:
        os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
    if mysql:
        mysql.terminate();mysql.wait(timeout=15)
    log.close()
