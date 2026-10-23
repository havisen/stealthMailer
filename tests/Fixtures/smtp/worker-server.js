// Disposable controlled SMTP server for outbound-worker integration tests.
// Each port maps to one deterministic scenario. Test certificates and
// credentials are supplied through environment variables; nothing is stored.
const fs = require('fs');
const path = require('path');
const tls = require('tls');

const root = path.resolve(__dirname, '..', '..', '..');
const keyPath = process.env.MAILPANEL_SMTP_TEST_SERVER_KEY || path.join(root, '.tools', 'smtp-server.key');
const certPath = process.env.MAILPANEL_SMTP_TEST_SERVER_CERT || path.join(root, '.tools', 'smtp-server.crt');
const key = fs.readFileSync(keyPath);
const cert = fs.readFileSync(certPath);
const expectedUser = process.env.MAILPANEL_SMTP_TEST_USER || 'smtp-test-user';
const expectedPass = process.env.MAILPANEL_SMTP_TEST_PASSWORD || '';
const basePort = Number(process.env.MAILPANEL_SMTP_WORKER_BASE_PORT || 4465);
const scenarios = ['accepted', 'rcpt_reject', 'rcpt_transient', 'partial', 'data_reject', 'data_transient', 'data_disconnect'];

function authPlain(value) {
  const decoded = Buffer.from(value, 'base64').toString('utf8');
  const parts = decoded.split('\0');
  return parts.length === 3 && parts[1] === expectedUser && parts[2] === expectedPass;
}

function serve(socket, scenario) {
  let buffer = '';
  let dataMode = false;
  let authStep = null;
  let authUser = '';
  let recipients = 0;
  let mailFrom = '';

  const write = line => socket.write(line + '\r\n');
  socket.write('220 controlled-smtp ESMTP ready\r\n');

  function finishData() {
    if (scenario === 'data_disconnect') {
      socket.destroy();
      return;
    }
    if (scenario === 'data_reject') {
      write('550 5.1.1 message rejected');
      return;
    }
    if (scenario === 'data_transient') {
      write('451 4.3.0 temporary failure');
      return;
    }
    write('250 2.0.0 queued as controlled');
  }

  function command(line) {
    const upper = line.toUpperCase();

    if (authStep === 'user') {
      authUser = Buffer.from(line, 'base64').toString('utf8');
      authStep = 'pass';
      write('334 UGFzc3dvcmQ6');
      return;
    }
    if (authStep === 'pass') {
      const password = Buffer.from(line, 'base64').toString('utf8');
      authStep = null;
      write(authUser === expectedUser && password === expectedPass
        ? '235 2.7.0 authentication successful'
        : '535 5.7.8 authentication failed');
      return;
    }

    if (upper.startsWith('EHLO') || upper.startsWith('HELO')) {
      write('250-controlled-smtp');
      write('250-AUTH PLAIN LOGIN');
      write('250 SIZE 10485760');
      return;
    }
    if (upper.startsWith('AUTH PLAIN')) {
      const value = line.slice(10).trim();
      write(authPlain(value) ? '235 2.7.0 authentication successful' : '535 5.7.8 authentication failed');
      return;
    }
    if (upper === 'AUTH LOGIN') {
      authStep = 'user';
      write('334 VXNlcm5hbWU6');
      return;
    }
    if (upper.startsWith('MAIL FROM:')) {
      mailFrom = line.slice(10).trim();
      write('250 2.1.0 sender accepted');
      return;
    }
    if (upper.startsWith('RCPT TO:')) {
      if (scenario === 'rcpt_reject') {
        write('550 5.1.1 recipient rejected');
        return;
      }
      if (scenario === 'rcpt_transient') {
        write('451 4.1.1 recipient temporarily unavailable');
        return;
      }
      if (scenario === 'partial' && recipients >= 1) {
        recipients++;
        write('550 5.1.1 second recipient rejected');
        return;
      }
      recipients++;
      write('250 2.1.5 recipient accepted');
      return;
    }
    if (upper === 'DATA') {
      if (recipients === 0) {
        write('503 5.5.1 no recipients');
        return;
      }
      dataMode = true;
      write('354 End data with <CR><LF>.<CR><LF>');
      return;
    }
    if (upper === 'RSET') {
      recipients = 0;
      write('250 2.0.0 reset');
      return;
    }
    if (upper === 'NOOP') {
      write('250 2.0.0 ok');
      return;
    }
    if (upper === 'QUIT') {
      write('221 2.0.0 bye');
      socket.end();
      return;
    }

    write('502 5.5.2 command not implemented');
  }

  socket.on('data', chunk => {
    buffer += chunk.toString('utf8');
    while (true) {
      const index = buffer.indexOf('\r\n');
      if (index < 0) {
        return;
      }
      const line = buffer.slice(0, index);
      buffer = buffer.slice(index + 2);
      if (dataMode) {
        if (line === '.') {
          dataMode = false;
          finishData();
          return;
        }
        continue;
      }
      command(line);
    }
  });
  socket.on('error', () => {});
}

scenarios.forEach((scenario, index) => {
  const port = basePort + index;
  tls.createServer({ key, cert }, socket => serve(socket, scenario)).listen(port, '127.0.0.1');
});
console.log(`Controlled SMTP worker server listening on ${basePort}-${basePort + scenarios.length - 1}`);
