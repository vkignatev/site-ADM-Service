"""HTTP integration tests. Uses temporary SQLite data and a local verified-TLS SMTP sink."""
from __future__ import annotations
import contextlib, datetime, hashlib, http.cookiejar, json, os, pathlib, secrets, socket, socketserver, sqlite3, ssl, subprocess, tempfile, threading, time, unittest, urllib.error, urllib.request
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID

ROOT = pathlib.Path(__file__).resolve().parents[1]

class Api:
    def __init__(self, base, agent='QA-browser'):
        self.base, self.csrf, self.agent = base, '', agent
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def call(self, action, data=None, csrf=True, origin=True):
        headers={'User-Agent':self.agent}
        if data is not None:
            headers['Content-Type']='application/json'
            if csrf: headers['X-CSRF-Token']=self.csrf
            if origin: headers['Origin']=self.base
        req=urllib.request.Request(self.base+'/api.php?action='+action, data=None if data is None else json.dumps(data,ensure_ascii=False).encode(),headers=headers)
        try: response=self.opener.open(req,timeout=35)
        except urllib.error.HTTPError as error: response=error
        body=response.read().decode('utf-8-sig')
        try: result=json.loads(body)
        except ValueError: raise AssertionError('Non-JSON API response, HTTP '+str(response.code))
        if 'csrf' in result:self.csrf=result['csrf']
        return response.code,result

class Sink(socketserver.StreamRequestHandler):
    def handle(self):
        def reply(text):self.wfile.write((text+'\r\n').encode());self.wfile.flush()
        reply('220 localhost test SMTP')
        while True:
            line=self.rfile.readline(20000)
            if not line:return
            cmd=line.decode(errors='replace').strip();upper=cmd.upper()
            if upper.startswith(('EHLO','HELO')):reply('250-localhost\r\n250-AUTH LOGIN PLAIN\r\n250 SIZE 100000')
            elif upper.startswith('AUTH LOGIN'):
                reply('334 VXNlcm5hbWU6');self.rfile.readline();reply('334 UGFzc3dvcmQ6');self.rfile.readline();reply('535 Authentication failed' if self.server.fail_auth else '235 Authenticated')
            elif upper.startswith('AUTH PLAIN'):reply('535 Authentication failed' if self.server.fail_auth else '235 Authenticated')
            elif upper.startswith(('MAIL FROM','RCPT TO','RSET','NOOP')):reply('250 OK')
            elif upper=='DATA':
                reply('354 End with dot');parts=[]
                while True:
                    part=self.rfile.readline(20000)
                    if part in (b'.\r\n',b''):break
                    parts.append(part)
                self.server.messages.append(b''.join(parts));reply('250 Accepted')
            elif upper=='QUIT':reply('221 Bye');return
            else:reply('250 OK')

class TLSSink(socketserver.ThreadingTCPServer):
    allow_reuse_address=True
    daemon_threads=True
    def get_request(self):
        connection,address=super().get_request()
        return self.context.wrap_socket(connection,server_side=True),address

def unused_port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]

class WebTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='adm-service-test-');cls.tmp=pathlib.Path(cls.temp.name)
        key=rsa.generate_private_key(public_exponent=65537,key_size=2048)
        subject=x509.Name([x509.NameAttribute(NameOID.COMMON_NAME,'localhost')]);now=datetime.datetime.now(datetime.timezone.utc)
        cert=(x509.CertificateBuilder().subject_name(subject).issuer_name(subject).public_key(key.public_key()).serial_number(x509.random_serial_number()).not_valid_before(now-datetime.timedelta(minutes=1)).not_valid_after(now+datetime.timedelta(days=1)).add_extension(x509.SubjectAlternativeName([x509.DNSName('localhost'),x509.DNSName('smtp-loopback.example.test')]),critical=False).add_extension(x509.BasicConstraints(ca=True,path_length=None),critical=True).sign(key,hashes.SHA256()))
        certpath=cls.tmp/'smtp.crt';keypath=cls.tmp/'smtp.key';certpath.write_bytes(cert.public_bytes(serialization.Encoding.PEM));keypath.write_bytes(key.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption()))
        cls.smtp=TLSSink(('127.0.0.1',0),Sink);cls.smtp.messages=[];cls.smtp.fail_auth=False;cls.smtp.context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);cls.smtp.context.load_cert_chain(certpath,keypath)
        threading.Thread(target=cls.smtp.serve_forever,daemon=True).start()
        cls.db=cls.tmp/'test.sqlite';storage=cls.tmp/'storage';storage.mkdir();sessions=cls.tmp/'sessions';sessions.mkdir()
        cfg=cls.tmp/'config.php';cfg.write_text("<?php return ['development'=>true,'timezone'=>'Asia/Novosibirsk','install_key'=>'','smtp_loopback_hosts'=>['smtp-loopback.example.test','invalid-peer.example.test'],'storage'=>"+repr(str(storage).replace('\\','/'))+",'db'=>['driver'=>'sqlite','path'=>"+repr(str(cls.db).replace('\\','/'))+"]];",encoding='utf-8')
        port=unused_port();cls.base='http://127.0.0.1:'+str(port);env=os.environ.copy();env['ADM_CONFIG_FILE']=str(cfg)
        command=[os.getenv('PHP_BIN','php')]
        if os.getenv('PHP_EXTENSION_DIR'):command+=['-d','extension_dir='+os.environ['PHP_EXTENSION_DIR'],'-d','extension=openssl','-d','extension=pdo_sqlite','-d','extension=pdo_mysql']
        command+=['-d','openssl.cafile='+str(certpath),'-d','session.save_path='+str(sessions),'-d','display_errors=0','-S','127.0.0.1:'+str(port),'-t',str(ROOT/'public')]
        cls.log=open(cls.tmp/'php.log','w',encoding='utf-8');cls.server=subprocess.Popen(command,env=env,stdout=cls.log,stderr=cls.log)
        cls.admin=Api(cls.base,'QA-admin');cls.password=secrets.token_urlsafe(20)
        for _ in range(100):
            try:
                code,me=cls.admin.call('me')
                if code==200:break
            except (OSError,AssertionError):pass
            time.sleep(.1)
        else:raise RuntimeError('PHP test server did not start')
        assert not me['installed']
        code,result=cls.admin.call('setup',{'login':'qa_admin','password':cls.password});assert code==200,result
    @classmethod
    def tearDownClass(cls):
        cls.server.terminate();cls.server.wait(timeout=10);cls.smtp.shutdown();cls.smtp.server_close();cls.log.close();cls.temp.cleanup()
    def visitor(self):
        client=Api(self.base,'Mozilla/5.0 '+self._testMethodName);client.call('status');return client
    def lead(self):return dict(name='Тестовое обращение',phone='+7 900 000 00 00',service='Аудит в области пожарной безопасности',message='Проверка формы <script>alert(1)</script>',consent='on',request_id=secrets.token_hex(20))
    def mail(self,enabled=True):
        return dict(enabled=enabled,host='localhost',port=self.smtp.server_address[1],encryption='ssl',username='qa@example.test',password='SMTP-TEST-ONLY',from_email='site@example.test',from_name='АДМ-СЕРВИС',to_email='receiver@example.test')
    def configure_mail(self,enabled=True):
        code,result=self.admin.call('mail-save',self.mail(enabled));self.assertEqual(code,200,result)
    def test_01_auth_required(self):
        visitor=self.visitor()
        for action in ['dashboard','leads','mail','lead&id=none']:
            self.assertEqual(visitor.call(action)[0],401)
        self.assertEqual(visitor.call('mail-test',{})[0],401)
    def test_02_setup_locked(self):
        self.assertEqual(self.visitor().call('setup',dict(login='intruder',password='not-a-real-password'))[0],409)
    def test_03_csrf_and_origin(self):
        self.assertEqual(self.admin.call('mail-save',self.mail(),csrf=False)[0],403)
        self.assertEqual(self.admin.call('mail-save',self.mail(),origin=False)[0],403)
    def test_04_login_logout(self):
        other=self.visitor();self.assertEqual(other.call('login',dict(login='qa_admin',password='wrong'))[0],401)
        self.assertEqual(other.call('login',dict(login='qa_admin',password=self.password))[0],200)
        self.assertEqual(other.call('logout',{})[0],200);self.assertEqual(other.call('leads')[0],401)
    def test_05_rate_limit(self):
        with contextlib.closing(sqlite3.connect(self.db)) as db:db.execute('DELETE FROM adm_limits');db.commit()
        try:
            other=self.visitor()
            for index in range(5):
                other.agent='Rotating-agent-'+str(index)
                self.assertEqual(other.call('login',dict(login='qa_admin',password='wrong'))[0],401)
            other.agent='Another-agent'
            self.assertEqual(other.call('login',dict(login='qa_admin',password='wrong'))[0],429)
        finally:
            with contextlib.closing(sqlite3.connect(self.db)) as db:db.execute('DELETE FROM adm_limits');db.commit()
    def test_06_request_validation(self):
        visitor=self.visitor()
        for field,value in [('phone','123'),('name','x\nBcc:test'),('service','Огнезащита'),('consent',False),('message','x'*2001)]:
            self.assertEqual(visitor.call('request',dict(self.lead(),**{field:value}))[0],422)
    def test_07_save_without_mail_and_idempotency(self):
        self.configure_mail(False);visitor=self.visitor();payload=self.lead();code,result=visitor.call('request',payload);self.assertEqual(code,201,result);self.assertEqual(result['mail_status'],'unconfigured')
        self.assertEqual(visitor.call('request',payload)[1]['id'],result['id'])
        self.assertEqual(visitor.call('request',dict(payload,message='changed'))[0],409)
        saved=self.admin.call('lead&id='+result['id'])[1];self.assertEqual(saved['message'],payload['message'])
        self.assertNotIn('request_key',saved)
    def test_08_mail_settings_encrypted(self):
        self.configure_mail();code,saved=self.admin.call('mail');self.assertEqual(code,200);self.assertTrue(saved['password_saved']);self.assertNotIn('password',saved);self.assertNotIn('password_cipher',saved)
        with contextlib.closing(sqlite3.connect(self.db)) as db:raw=db.execute("SELECT v FROM adm_settings WHERE k='mail'").fetchone()[0]
        self.assertNotIn('SMTP-TEST-ONLY',raw)
        blank=self.mail();blank['password']='';self.assertEqual(self.admin.call('mail-save',blank)[0],200)
        invalid=self.mail();invalid['encryption']='none';self.assertEqual(self.admin.call('mail-save',invalid)[0],422)
    def test_09_test_email_verified_tls(self):
        self.configure_mail();before=len(self.smtp.messages);code,result=self.admin.call('mail-test',{});self.assertEqual(code,200,result);self.assertEqual(len(self.smtp.messages),before+1)
    def test_09_loopback_keeps_certificate_checks(self):
        settings=self.mail();settings['host']='smtp-loopback.example.test'
        self.assertEqual(self.admin.call('mail-save',settings)[0],200)
        before=len(self.smtp.messages);code,result=self.admin.call('mail-test',{})
        self.assertEqual(code,200,result);self.assertEqual(len(self.smtp.messages),before+1)
        settings['host']='invalid-peer.example.test'
        self.assertEqual(self.admin.call('mail-save',settings)[0],200)
        before=len(self.smtp.messages);code,result=self.admin.call('mail-test',{})
        self.assertEqual(code,502,result);self.assertEqual(len(self.smtp.messages),before)
        self.assertNotIn('SMTP-TEST-ONLY',str(result))
        self.configure_mail()
    def test_10_real_delivery_to_local_sink(self):
        self.configure_mail();before=len(self.smtp.messages);visitor=self.visitor();payload=self.lead();code,result=visitor.call('request',payload);self.assertEqual(code,201,result);self.assertEqual(result['mail_status'],'sent');self.assertEqual(len(self.smtp.messages),before+1)
        self.assertEqual(visitor.call('request',payload)[0],200);self.assertEqual(len(self.smtp.messages),before+1)
        self.admin.call('lead-retry',{'id':result['id']});self.assertEqual(len(self.smtp.messages),before+1)
    def test_11_mail_failure_preserves_and_retry(self):
        self.configure_mail();self.smtp.fail_auth=True
        try:
            test_code,test_result=self.admin.call('mail-test',{})
            self.assertEqual(test_code,502)
            self.assertIn('отклонил авторизацию',test_result['message'])
            self.assertNotIn('SMTP-TEST-ONLY',str(test_result))
            self.assertNotIn('qa@example.test',str(test_result))
            code,result=self.visitor().call('request',self.lead())
        finally:self.smtp.fail_auth=False
        self.assertEqual(code,201,result);self.assertEqual(result['mail_status'],'failed')
        saved=self.admin.call('lead&id='+result['id'])[1];self.assertEqual(saved['mail_status'],'failed')
        retry=self.admin.call('lead-retry',dict(id=result['id']))[1];self.assertEqual(retry['mail_status'],'sent',retry)
        self.assertEqual(self.admin.call('lead-status',dict(id=result['id'],status='contacted'))[0],200)
    def test_12_analytics_sources_devices(self):
        visitor=self.visitor();before=self.admin.call('dashboard&days=7')[1]
        for _ in range(2):self.assertEqual(visitor.call('track',dict(path='/',referrer='https://search.example.test/find?q=private'))[0],200)
        after=self.admin.call('dashboard&days=7')[1];self.assertEqual(after['views']-before['views'],2);self.assertEqual(after['visitors']-before['visitors'],1)
        self.assertTrue(any(x['label']=='search.example.test' for x in after['sources']))
        with contextlib.closing(sqlite3.connect(self.db)) as db:
            rows=db.execute('SELECT visitor,referrer FROM adm_traffic').fetchall()
        self.assertTrue(all(len(row[0])==64 for row in rows));self.assertFalse(any('private' in row[1] for row in rows))
    def test_13_admin_not_counted(self):
        before=self.admin.call('dashboard')[1]['views'];self.admin.call('track',dict(path='/',referrer=''));self.assertEqual(self.admin.call('dashboard')[1]['views'],before)
    def test_14_password_rotation(self):
        other=Api(self.base,'QA-other');other.call('me');self.assertEqual(other.call('login',dict(login='qa_admin',password=self.password))[0],200)
        new=secrets.token_urlsafe(20);code,result=self.admin.call('password',dict(old_password=self.password,new_password=new));self.assertEqual(code,200,result);self.assertEqual(other.call('leads')[0],401);type(self).password=new
    def test_15_browser_ui(self):
        script=ROOT/'tests/browser-admin.cjs'
        if not os.getenv('RUN_BROWSER_TESTS'):self.skipTest('RUN_BROWSER_TESTS is not set')
        env=os.environ.copy();env.update(QA_URL=self.base,QA_LOGIN='qa_admin',QA_PASSWORD=self.password,QA_SMTP_PORT=str(self.smtp.server_address[1]))
        try: subprocess.run([os.environ.get('NODE_BIN','node'),str(script)],env=env,check=True,timeout=150)
        except Exception:
            self.log.flush();print((self.tmp/'php.log').read_text(encoding='utf-8')[-3500:]);raise

if __name__=='__main__':unittest.main(verbosity=2)

