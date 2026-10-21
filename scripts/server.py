"""Local site server with optional SMTP delivery. No external dependencies."""
from __future__ import annotations
import json
import os
import re
import smtplib
import ssl
import threading
import time
from collections import defaultdict
from email.message import EmailMessage
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[1]
STATIC_ROOT = ROOT / 'dist'
SERVICES = {'Пожарная сигнализация', 'Пожаротушение', 'Оповещение и эвакуация', 'Противодымная защита', 'Огнезащита', 'Обслуживание и ремонт', 'Нужна консультация'}
RATE_LIMIT = defaultdict(list)
RATE_LOCK = threading.Lock()


def load_environment():
    env_file = ROOT / '.env'
    if not env_file.exists():
        return
    for raw_line in env_file.read_text(encoding='utf-8-sig').splitlines():
        line = raw_line.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        if re.fullmatch(r'[A-Z][A-Z0-9_]*', key.strip()):
            os.environ.setdefault(key.strip(), value.strip().strip('"\''))


def mail_ready():
    required = ['SMTP_HOST', 'SMTP_USER', 'SMTP_PASSWORD', 'FROM_EMAIL', 'TO_EMAIL']
    return os.getenv('FORM_ENABLED', '').lower() == 'true' and all(os.getenv(key, '').strip() for key in required)


def validate_request(data):
    if not isinstance(data, dict):
        raise ValueError('Проверьте данные формы.')
    for key in ('name', 'phone', 'service', 'message'):
        if not isinstance(data.get(key, ''), str):
            raise ValueError('Проверьте данные формы.')
    name = data.get('name', '').strip()
    phone = data.get('phone', '').strip()
    service = data.get('service', '').strip()
    message = data.get('message', '').strip()
    if not 1 <= len(name) <= 100 or any(ord(c) < 32 for c in name):
        raise ValueError('Укажите имя длиной до 100 символов.')
    if len(phone) > 25 or not re.fullmatch(r'[+\d\s()\-]+', phone) or not 10 <= len(re.sub(r'\D', '', phone)) <= 15:
        raise ValueError('Укажите корректный телефон из 10–15 цифр.')
    if service not in SERVICES:
        raise ValueError('Выберите услугу из списка.')
    if len(message) > 2000 or '\x00' in message:
        raise ValueError('Описание должно содержать не более 2000 символов.')
    if data.get('consent') != 'on':
        raise ValueError('Нужно согласие на обработку данных обращения.')
    return dict(name=name, phone=phone, service=service, message=message)


def send_inquiry(data):
    message = EmailMessage()
    message['Subject'] = 'Заявка с сайта АДМ-СЕРВИС'
    message['From'] = os.environ['FROM_EMAIL']
    message['To'] = os.environ['TO_EMAIL']
    message.set_content('Заявка с сайта АДМ-СЕРВИС\n\nИмя: {name}\nТелефон: {phone}\nУслуга: {service}\n\nОписание:\n{message}'.format(**data))
    context = ssl.create_default_context()
    mode = os.getenv('SMTP_TLS', 'implicit')
    default_port = '465' if mode == 'implicit' else '587'
    port = int(os.getenv('SMTP_PORT', default_port))
    if mode == 'implicit':
        with smtplib.SMTP_SSL(os.environ['SMTP_HOST'], port, timeout=15, context=context) as smtp:
            smtp.login(os.environ['SMTP_USER'], os.environ['SMTP_PASSWORD'])
            refused = smtp.send_message(message)
    elif mode == 'starttls':
        with smtplib.SMTP(os.environ['SMTP_HOST'], port, timeout=15) as smtp:
            smtp.ehlo()
            smtp.starttls(context=context)
            smtp.ehlo()
            smtp.login(os.environ['SMTP_USER'], os.environ['SMTP_PASSWORD'])
            refused = smtp.send_message(message)
    else:
        raise ValueError('Unsupported SMTP_TLS mode')
    if refused:
        raise smtplib.SMTPRecipientsRefused(refused)


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(STATIC_ROOT), **kwargs)

    def end_headers(self):
        self.send_header('X-Content-Type-Options', 'nosniff')
        self.send_header('Referrer-Policy', 'strict-origin-when-cross-origin')
        self.send_header('Cache-Control', 'no-store')
        self.send_header('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'")
        super().end_headers()

    def json_response(self, status, data):
        payload = json.dumps(data, ensure_ascii=False).encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def do_GET(self):
        if urlsplit(self.path).path == '/api/status':
            self.json_response(200, {'formEnabled': mail_ready()})
        else:
            super().do_GET()

    def list_directory(self, path):
        self.send_error(404)
        return None

    def do_POST(self):
        if urlsplit(self.path).path != '/api/request':
            return self.json_response(404, {'message': 'Страница не найдена.'})
        if not mail_ready():
            return self.json_response(503, {'message': 'Приём заявок пока не подключён. Позвоните: +7 982 412 11 59.'})
        origin = self.headers.get('Origin', '')
        host = self.headers.get('Host', '')
        if not origin or urlsplit(origin).netloc != host:
            return self.json_response(403, {'message': 'Обновите страницу и повторите запрос.'})
        if self.headers.get('Content-Type', '').split(';')[0] != 'application/json':
            return self.json_response(415, {'message': 'Неверный формат запроса.'})
        try:
            length = int(self.headers.get('Content-Length', '0'))
        except ValueError:
            length = 0
        if not 1 <= length <= 16000:
            return self.json_response(413, {'message': 'Слишком большой или пустой запрос.'})
        now = time.monotonic()
        with RATE_LOCK:
            # Keep only recent entries so idle clients do not grow memory indefinitely.
            for ip in list(RATE_LIMIT):
                RATE_LIMIT[ip] = [stamp for stamp in RATE_LIMIT[ip] if now - stamp < 900]
                if not RATE_LIMIT[ip]:
                    del RATE_LIMIT[ip]
            attempts = RATE_LIMIT[self.client_address[0]]
            if len(attempts) >= 5:
                return self.json_response(429, {'message': 'Слишком много обращений. Попробуйте позже или позвоните.'})
            attempts.append(now)
        try:
            data = validate_request(json.loads(self.rfile.read(length)))
        except (ValueError, UnicodeError):
            return self.json_response(400, {'message': 'Проверьте имя, телефон, услугу и согласие на обработку данных.'})
        try:
            send_inquiry(data)
        except Exception:
            # SMTP responses may contain addresses or credentials; never log them.
            return self.json_response(502, {'message': 'Не удалось подтвердить отправку. Позвоните: +7 982 412 11 59.'})
        return self.json_response(200, {'ok': True})

    def log_message(self, format, *args):
        # Request bodies, contact details, and SMTP settings are not logged.
        pass


if __name__ == '__main__':
    load_environment()
    port = int(os.getenv('PORT', '4173'))
    server = ThreadingHTTPServer(('127.0.0.1', port), Handler)
    print(f'Local: http://127.0.0.1:{port}', flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
