<?php
// includes/mailer.php

function mailBuildMsg(string $toHeader, string $subject, string $htmlBody, array $attachments): string {
    $from     = MAIL_FROM;
    $fromName = MAIL_FROM_NAME;
    $subj     = "=?UTF-8?B?" . base64_encode($subject) . "?=";
    $fname    = "=?UTF-8?B?" . base64_encode($fromName) . "?=";
    $msg      = "Date: " . date('r') . "\r\n";
    $msg     .= "From: $fname <$from>\r\n";
    $msg     .= "To: $toHeader\r\n";
    $msg     .= "Subject: $subj\r\n";
    $msg     .= "MIME-Version: 1.0\r\n";
    if ($attachments) {
        $b = '----=_Part_' . md5(uniqid());
        $msg .= "Content-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n";
        $msg .= "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        foreach ($attachments as $att) {
            if (!file_exists($att['path'])) continue;
            $fn = "=?UTF-8?B?" . base64_encode($att['name']) . "?=";
            $msg .= "--$b\r\nContent-Type: " . ($att['mime'] ?? 'application/octet-stream') . "\r\n";
            $msg .= "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$fn\"\r\n\r\n";
            $msg .= chunk_split(base64_encode(file_get_contents($att['path']))) . "\r\n";
        }
        $msg .= "--$b--\r\n";
    } else {
        $msg .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($htmlBody));
    }
    return $msg . "\r\n.\r\n";
}

function mailSmtpOpen() {
    $ctx  = stream_context_create(['ssl' => [
        'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true
    ]]);
    $smtp = stream_socket_client("ssl://smtp.gmail.com:465", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$smtp) throw new \Exception("Nelze připojit: $errstr");
    stream_set_timeout($smtp, 15);
    fgets($smtp, 512);
    fputs($smtp, "EHLO odvysoke.drymtym.cz\r\n");
    while ($l = fgets($smtp, 512)) { if (substr($l,3,1) === ' ') break; }
    fputs($smtp, "AUTH LOGIN\r\n"); fgets($smtp, 512);
    fputs($smtp, base64_encode(GMAIL_USER) . "\r\n"); fgets($smtp, 512);
    fputs($smtp, base64_encode(GMAIL_PASS) . "\r\n");
    $resp = fgets($smtp, 512);
    if (substr($resp, 0, 3) !== '235') throw new \Exception("Auth failed: $resp");
    return $smtp;
}

function sendMail(array $to, string $subject, string $htmlBody, array $attachments = [], bool $bcc = false): bool {
    if (empty($to)) return false;
    $from  = MAIL_FROM;
    $fname = "=?UTF-8?B?" . base64_encode(MAIL_FROM_NAME) . "?=";
    $ok    = true;

    if ($bcc) {
        foreach ($to as $email) {
            $email = trim($email);
            if (!$email) continue;
            try {
                $smtp = mailSmtpOpen();
                fputs($smtp, "MAIL FROM:<$from>\r\n"); fgets($smtp, 512);
                fputs($smtp, "RCPT TO:<$email>\r\n"); fgets($smtp, 512);
                fputs($smtp, "DATA\r\n"); fgets($smtp, 512);
                fputs($smtp, mailBuildMsg("$fname <$from>", $subject, $htmlBody, $attachments));
                $resp = fgets($smtp, 512);
                if (substr($resp, 0, 3) !== '250') $ok = false;
                fputs($smtp, "QUIT\r\n"); fclose($smtp);
            } catch (\Exception $e) {
                error_log("Mailer BCC ($email): " . $e->getMessage());
                $ok = false;
            }
        }
    } else {
        try {
            $smtp = mailSmtpOpen();
            fputs($smtp, "MAIL FROM:<$from>\r\n"); fgets($smtp, 512);
            foreach ($to as $email) {
                fputs($smtp, "RCPT TO:<" . trim($email) . ">\r\n"); fgets($smtp, 512);
            }
            fputs($smtp, "DATA\r\n"); fgets($smtp, 512);
            fputs($smtp, mailBuildMsg(implode(', ', $to), $subject, $htmlBody, $attachments));
            $resp = fgets($smtp, 512);
            $ok = substr($resp, 0, 3) === '250';
            fputs($smtp, "QUIT\r\n"); fclose($smtp);
        } catch (\Exception $e) {
            error_log('Mailer: ' . $e->getMessage());
            $ok = false;
        }
    }
    return $ok;
}

function mailTemplate(string $title, string $body): string {
    return '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f7f6f2;margin:0;padding:20px">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto"><tr><td>
  <table width="100%" style="background:#185FA5;border-radius:10px 10px 0 0"><tr><td style="padding:20px 28px">
    <p style="color:#fff;font-size:18px;font-weight:bold;margin:0">SVJ Od Vysoké – Rozhled</p>
    <p style="color:rgba(255,255,255,0.8);font-size:12px;margin:4px 0 0">Sdělení výboru SVJ</p>
  </td></tr></table>
  <table width="100%" style="background:#fff"><tr><td style="padding:24px 28px">
    <h2 style="color:#185FA5;margin:0 0 16px">' . htmlspecialchars($title) . '</h2>
    ' . nl2br(htmlspecialchars($body)) . '
    <p style="margin-top:20px;padding-top:16px;border-top:1px solid #eee;font-size:12px;color:#888">
      Tato zpráva byla odeslána automaticky portálem SVJ Od Vysoké – Rozhled.<br>
      <a href="https://odvysoke.drymtym.cz" style="color:#185FA5">odvysoke.drymtym.cz</a>
    </p>
  </td></tr></table>
  <table width="100%" style="background:#f1efe8;border-radius:0 0 10px 10px;border-top:1px solid #e0dfd8">
    <tr><td style="padding:12px 28px;font-size:11px;color:#6b6a65">Systém vytvořil &copy; ' . date('Y') . ' Medusoft</td></tr>
  </table>
</td></tr></table></body></html>';
}

function mailTemplatePoll(string $question, array $options, ?string $closesAt, string $description = ''): string {
    $colors = ['#185FA5','#3B6D11','#854F0B','#A32D2D','#5F5E5A'];
    $rows = '';
    foreach ($options as $i => $opt) {
        $c = $colors[$i % count($colors)];
        $rows .= '<tr><td style="padding:8px 0"><table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr><td style="background:' . $c . '1A;border:2px solid ' . $c . ';border-radius:8px;padding:12px 16px">
            <span style="font-size:15px;font-weight:600;color:' . $c . '">' . htmlspecialchars($opt) . '</span>
          </td></tr></table></td></tr>';
    }
    $closing = $closesAt ? '<p style="font-size:12px;color:#854F0B;margin:0 0 8px">⏰ Otevřeno do: <strong>' . date('j. n. Y', strtotime($closesAt)) . '</strong></p>' : '';
    return '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f7f6f2;margin:0;padding:20px">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto"><tr><td>
  <table width="100%" style="background:#185FA5;border-radius:10px 10px 0 0"><tr>
    <td style="padding:20px 28px"><p style="color:#fff;font-size:18px;font-weight:bold;margin:0">SVJ Od Vysoké – Rozhled</p></td>
    <td style="padding:20px 28px;text-align:right"><span style="background:rgba(255,255,255,0.2);color:#fff;padding:4px 12px;border-radius:99px;font-size:12px">🗳️ Anketa</span></td>
  </tr></table>
  <table width="100%" style="background:#fff"><tr><td style="padding:28px">
    <h2 style="color:#1a1a18;font-size:20px;margin:0 0 16px">' . htmlspecialchars($question) . '</h2>
    ' . ($description ? '<p style="color:#6b6a65;font-size:14px;margin:0 0 16px">' . nl2br(htmlspecialchars($description)) . '</p>' : '') . '
    <table width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>
    <table width="100%" style="margin-top:20px"><tr><td style="padding:16px;background:#E6F1FB;border-radius:8px;text-align:center">
      ' . $closing . '
      <a href="https://odvysoke.drymtym.cz/owner/polls.php" style="display:inline-block;background:#185FA5;color:#fff;text-decoration:none;padding:10px 24px;border-radius:6px;font-size:14px;font-weight:bold">Hlasovat →</a>
    </td></tr></table>
  </td></tr></table>
  <table width="100%" style="background:#f1efe8;border-radius:0 0 10px 10px;border-top:1px solid #e0dfd8">
    <tr><td style="padding:12px 28px;font-size:11px;color:#6b6a65">Systém vytvořil &copy; ' . date('Y') . ' Medusoft</td></tr>
  </table>
</td></tr></table></body></html>';
}

function mailTemplatePerRollam(string $title, string $description, array $items, ?string $closesAt): string {
    $rows = '';
    foreach ($items as $i => $item) {
        $rows .= '<tr><td style="padding:8px 0"><table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f0f5fb;border-left:3px solid #185FA5;border-radius:4px">
          <tr><td style="padding:10px 14px"><strong style="color:#185FA5">' . ($i+1) . '. ' . htmlspecialchars($item['title']) . '</strong>'
          . ($item['description'] ? '<br><span style="font-size:13px;color:#555">' . htmlspecialchars($item['description']) . '</span>' : '')
          . '</td></tr></table></td></tr>';
    }
    $closing = $closesAt ? '<p style="margin:0 0 8px;font-size:13px;color:#854F0B">⏰ Otevřeno do: <strong>' . date('j. n. Y H:i', strtotime($closesAt)) . '</strong></p>' : '';
    return '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f7f6f2;margin:0;padding:20px">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto"><tr><td>
  <table width="100%" style="background:#185FA5;border-radius:10px 10px 0 0"><tr>
    <td style="padding:20px 28px"><p style="color:#fff;font-size:18px;font-weight:bold;margin:0">SVJ Od Vysoké – Rozhled</p></td>
    <td style="padding:20px 28px;text-align:right"><span style="background:rgba(255,255,255,0.2);color:#fff;padding:4px 12px;border-radius:99px;font-size:12px">📋 Per rollam</span></td>
  </tr></table>
  <table width="100%" style="background:#fff"><tr><td style="padding:28px">
    <h2 style="color:#185FA5;margin:0 0 12px">' . htmlspecialchars($title) . '</h2>
    ' . ($description ? '<p style="color:#555;font-size:14px;margin:0 0 16px">' . nl2br(htmlspecialchars($description)) . '</p>' : '') . '
    <table width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>
    <table width="100%" style="margin-top:20px"><tr><td style="padding:16px;background:#E6F1FB;border-radius:8px;text-align:center">
      ' . $closing . '
      <a href="https://odvysoke.drymtym.cz/owner/perrollam.php" style="display:inline-block;background:#185FA5;color:#fff;text-decoration:none;padding:12px 28px;border-radius:6px;font-size:15px;font-weight:bold">Hlasovat →</a>
    </td></tr></table>
  </td></tr></table>
  <table width="100%" style="background:#f1efe8;border-radius:0 0 10px 10px;border-top:1px solid #e0dfd8">
    <tr><td style="padding:12px 28px;font-size:11px;color:#6b6a65">Systém vytvořil &copy; ' . date('Y') . ' Medusoft</td></tr>
  </table>
</td></tr></table></body></html>';
}

function mailTemplateWelcome(string $username, string $password, string $unitLabel = ''): string {
    $unit = $unitLabel ? '<tr><td style="padding:4px 0;font-size:14px;color:#1a1a18"><strong>Vaše jednotka:</strong>&nbsp;' . htmlspecialchars($unitLabel) . '</td></tr>' : '';
    return '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f7f6f2;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f7f6f2;padding:20px 0">
<tr><td align="center"><table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%">
  <tr><td style="background:#185FA5;border-radius:10px 10px 0 0;padding:24px 32px">
    <p style="margin:0;color:#fff;font-size:20px;font-weight:bold">SVJ Od Vysoké – Rozhled</p>
    <p style="margin:6px 0 0;color:rgba(255,255,255,0.75);font-size:13px">Portál vlastníků</p>
  </td></tr>
  <tr><td style="background:#fff;padding:32px">
    <h2 style="margin:0 0 16px;color:#185FA5">Byl Vám vytvořen účet</h2>
    <table width="100%" style="background:#E6F1FB;border-radius:8px;margin-bottom:20px"><tr><td style="padding:20px 24px">
      <p style="margin:0 0 10px;font-size:12px;font-weight:bold;color:#185FA5;text-transform:uppercase">Přihlašovací údaje</p>
      <table cellpadding="0" cellspacing="0" border="0">
        <tr><td style="padding:4px 0;font-size:14px"><strong>Portál:</strong>&nbsp;<a href="https://odvysoke.drymtym.cz" style="color:#185FA5">odvysoke.drymtym.cz</a></td></tr>
        <tr><td style="padding:4px 0;font-size:14px"><strong>Jmeno:</strong>&nbsp;<span style="font-family:monospace;background:#fff;border:1px solid #b5d0f0;border-radius:4px;padding:2px 8px">' . htmlspecialchars($username) . '</span></td></tr>
        <tr><td style="padding:4px 0;font-size:14px"><strong>Heslo:</strong>&nbsp;<span style="font-family:monospace;background:#fff;border:1px solid #b5d0f0;border-radius:4px;padding:2px 8px">' . htmlspecialchars($password) . '</span></td></tr>
        ' . $unit . '
      </table>
    </td></tr></table>
    <table width="100%" style="background:#FAEEDA;border-radius:8px;margin-bottom:24px"><tr><td style="padding:14px 20px;font-size:14px;color:#854F0B">
      ⚠️ <strong>Po prvním přihlášení si změňte heslo</strong> v sekci Moje karta.
    </td></tr></table>
    <table width="100%"><tr><td align="center">
      <a href="https://odvysoke.drymtym.cz" style="display:inline-block;background:#185FA5;color:#fff;text-decoration:none;padding:14px 32px;border-radius:8px;font-size:16px;font-weight:bold">Přihlásit se →</a>
    </td></tr></table>
  </td></tr>
  <tr><td style="background:#f1efe8;border-radius:0 0 10px 10px;border-top:1px solid #e0dfd8;padding:14px 32px">
    <p style="margin:0;font-size:11px;color:#6b6a65">SVJ Od Vysoké – Rozhled &nbsp;·&nbsp; <a href="https://odvysoke.drymtym.cz" style="color:#185FA5">odvysoke.drymtym.cz</a><br>Systém vytvořil &copy; ' . date('Y') . ' Medusoft</p>
  </td></tr>
</table></td></tr></table></body></html>';
}

function mailTemplateSoused(string $subject, string $body, string $senderName, string $senderUnit): string {
    return '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f7f6f2;margin:0;padding:20px">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto"><tr><td>
  <table width="100%" style="background:#3B6D11;border-radius:10px 10px 0 0"><tr>
    <td style="padding:20px 28px">
      <p style="color:#fff;font-size:18px;font-weight:bold;margin:0">Zpráva od souseda</p>
      <p style="color:rgba(255,255,255,0.8);font-size:12px;margin:4px 0 0">SVJ Od Vysoké – Rozhled – portál vlastníků</p>
    </td>
    <td style="padding:20px 28px;text-align:right">
      <span style="background:rgba(255,255,255,0.2);color:#fff;padding:4px 12px;border-radius:99px;font-size:12px">✉️ Soused</span>
    </td>
  </tr></table>
  <table width="100%" style="background:#fff"><tr><td style="padding:28px">
    <h2 style="color:#3B6D11;margin:0 0 16px">' . htmlspecialchars($subject) . '</h2>
    <table width="100%" style="background:#EAF3DE;border-radius:8px;margin-bottom:20px"><tr><td style="padding:12px 16px">
      <p style="margin:0;font-size:13px;color:#3B6D11">
        <strong>Od:</strong> ' . htmlspecialchars($senderName) . '
        ' . ($senderUnit ? ' &nbsp;·&nbsp; ' . htmlspecialchars($senderUnit) : '') . '
      </p>
    </td></tr></table>
    <div style="font-size:15px;color:#1a1a18;line-height:1.7">' . nl2br(htmlspecialchars($body)) . '</div>
    <p style="margin-top:20px;padding-top:16px;border-top:1px solid #eee;font-size:12px;color:#888">
      Tato zpráva byla odeslána přes portál SVJ Od Vysoké – Rozhled.<br>
      Pro odpověď kontaktujte odesílatele přímo — jeho e-mail není součástí této zprávy.<br>
      <a href="https://odvysoke.drymtym.cz" style="color:#3B6D11">odvysoke.drymtym.cz</a>
    </p>
  </td></tr></table>
  <table width="100%" style="background:#f1efe8;border-radius:0 0 10px 10px;border-top:1px solid #e0dfd8">
    <tr><td style="padding:12px 28px;font-size:11px;color:#6b6a65">Systém vytvořil &copy; ' . date('Y') . ' Medusoft</td></tr>
  </table>
</td></tr></table></body></html>';
}

function mailTemplatePozvanka(string $title, string $datum, string $misto, string $agenda, float $quorum): string {
    $agendaHtml = '';
    if ($agenda) {
        $rows = explode("\n", trim($agenda));
        foreach ($rows as $row) {
            if (trim($row)) {
                $agendaHtml .= '<tr><td style="padding:6px 14px;border-bottom:1px solid #e0dfd8;font-size:14px;color:#1a1a18">' . htmlspecialchars(trim($row)) . '</td></tr>';
            }
        }
    }
    return '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f7f6f2;margin:0;padding:20px">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto"><tr><td>
  <table width="100%" style="background:#185FA5;border-radius:10px 10px 0 0"><tr>
    <td style="padding:20px 28px">
      <p style="color:#fff;font-size:18px;font-weight:bold;margin:0">SVJ Od Vysoké – Rozhled</p>
      <p style="color:rgba(255,255,255,0.8);font-size:12px;margin:4px 0 0">Pozvánka na shromáždění vlastníků</p>
    </td>
    <td style="padding:20px 28px;text-align:right">
      <span style="background:rgba(255,255,255,0.2);color:#fff;padding:4px 12px;border-radius:99px;font-size:12px">📋 Shromáždění</span>
    </td>
  </tr></table>
  <table width="100%" style="background:#fff"><tr><td style="padding:28px">
    <h2 style="color:#185FA5;margin:0 0 20px">' . htmlspecialchars($title) . '</h2>

    <!-- Datum a místo -->
    <table width="100%" style="background:#E6F1FB;border-radius:8px;margin-bottom:20px"><tr><td style="padding:16px 20px">
      <table cellpadding="0" cellspacing="0" border="0">
        <tr><td style="padding:4px 0;font-size:14px;color:#1a1a18">
          📅 <strong>Datum:</strong> ' . htmlspecialchars($datum) . '
        </td></tr>
        ' . ($misto ? '<tr><td style="padding:4px 0;font-size:14px;color:#1a1a18">📍 <strong>Místo:</strong> ' . htmlspecialchars($misto) . '</td></tr>' : '') . '
        <tr><td style="padding:4px 0;font-size:13px;color:#6b6a65">
          ⚖️ Kvórum pro usnášeníschopnost: <strong>' . $quorum . ' %</strong>
        </td></tr>
      </table>
    </td></tr></table>

    ' . ($agendaHtml ? '
    <!-- Program -->
    <p style="font-size:13px;font-weight:600;color:#6b6a65;text-transform:uppercase;letter-spacing:1px;margin:0 0 8px">Program shromáždění</p>
    <table width="100%" style="border:1px solid #e0dfd8;border-radius:8px;overflow:hidden;margin-bottom:20px">
      ' . $agendaHtml . '
    </table>' : '') . '

    <table width="100%" style="background:#EAF3DE;border-radius:8px;margin-bottom:20px"><tr><td style="padding:14px 20px;font-size:13px;color:#3B6D11">
      ✓ <strong>Vaše účast je důležitá</strong> — pro usnášeníschopnost je potřeba přítomnost vlastníků s podíly nad ' . $quorum . ' %.
    </td></tr></table>

    <table width="100%"><tr><td align="center">
      <a href="https://odvysoke.drymtym.cz" style="display:inline-block;background:#185FA5;color:#fff;text-decoration:none;padding:12px 28px;border-radius:6px;font-size:15px;font-weight:bold">
        Přihlásit se na portál →
      </a>
    </td></tr></table>
  </td></tr></table>
  <table width="100%" style="background:#f1efe8;border-radius:0 0 10px 10px;border-top:1px solid #e0dfd8">
    <tr><td style="padding:12px 28px;font-size:11px;color:#6b6a65">
      SVJ Od Vysoké – Rozhled &nbsp;·&nbsp; <a href="https://odvysoke.drymtym.cz" style="color:#185FA5">odvysoke.drymtym.cz</a><br>
      Systém vytvořil &copy; ' . date('Y') . ' Medusoft
    </td></tr>
  </table>
</td></tr></table>
</body></html>';
}

function getAllOwnerEmails(\PDO $db): array {
    $rows = $db->query("SELECT email FROM owners WHERE email IS NOT NULL AND email != '' AND notify_email = 1")->fetchAll(PDO::FETCH_COLUMN);
    return array_unique(array_filter($rows));
}
