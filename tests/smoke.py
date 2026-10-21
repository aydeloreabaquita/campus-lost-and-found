"""End-to-end checks against a running PHP development server. Uses disposable accounts."""
import http.cookiejar
import re
import secrets
import sys
import urllib.parse
import urllib.request

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://localhost:8080'

class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.page = self.get()

    def get(self, path='/'):
        self.page = self.http.open(BASE + path).read().decode()
        return self.page

    def post(self, data, csrf=True):
        if csrf:
            data['csrf'] = re.search(r'name="csrf" value="([^"]+)"', self.page)[1]
        self.page = self.http.open(BASE + '/', urllib.parse.urlencode(data).encode()).read().decode()
        return self.page

c = Client()
assert 'Welcome back.' in c.page
assert 'Your item reports' not in c.get('/?edit=1')
email='codex_test_' + secrets.token_hex(6) + '@example.com'
password=secrets.token_urlsafe(16)
c.post({'action': 'register', 'username': email, 'password': password})
assert 'Your item reports' in c.page
record = dict(action='save', item_name='Test wallet <script>', category='Personal', item_type='Found',
              description='QA test', location='Library', date_reported='2026-10-20', status='Open', reporter_name='Test reporter')
c.post(record.copy())
assert 'Record saved successfully.' in c.page, re.findall(r'<p class="notice error"[^>]*>(.*?)</p>', c.page)
assert 'Test wallet &lt;script&gt;' in c.page and 'Test wallet <script>' not in c.page
item_id = re.search(r'href="/\?edit=([a-f0-9]{24})"', c.page)[1]
c.get('/?search=Library')
assert 'Test wallet &lt;script&gt;' in c.page
c.get('/?search=NotPresentAnywhere')
assert 'No matching reports.' in c.page
c.get()
c.post(dict(record, id=item_id, status='Matched', item_name='Updated wallet'))
assert 'Updated wallet' in c.page and '>Matched<' in c.page
c.post(dict(record, csrf='invalid', item_name='CSRF ATTACK'), csrf=False)
assert 'Session expired.' in c.page
assert 'CSRF ATTACK' not in c.get()
other = Client()
other.post({'action': 'register', 'username': 'codex_other_' + secrets.token_hex(6) + '@example.com', 'password': secrets.token_urlsafe(16)})
assert 'Updated wallet' not in other.page
assert 'Record not found.' in other.get('/?edit=' + item_id)
other.post({'action':'delete','id':item_id})
assert 'Record not found.' in other.page
assert 'Updated wallet' in c.get()
c.post({'action': 'delete', 'id': item_id})
assert 'Record deleted successfully.' in c.page and 'Updated wallet' not in c.page
assert all('>'+action+'<' in c.page for action in ['Added','Updated','Deleted'])
c.post({'action': 'logout'})
assert 'Welcome back.' in c.page and 'Your item reports' not in c.page
c.post({'action':'login','username':email,'password':password})
assert 'Your item reports' in c.page
c.post({'action':'logout'})
print('PASS: registration, sessions, add/search/update/delete, escaping, CSRF, ownership, audit log, logout')
