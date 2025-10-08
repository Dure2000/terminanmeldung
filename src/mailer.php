<?php
	// Author: Ralf Schwalbe
	// mailer.php — Versand einzelner Mails + Protokoll je Person (Token)
	// - Separates Skript, KEY-geschützt (wie editor.php)
	// - PHPMailer (Composer) wird verwendet
	// - Speichert Versand in mail_messages; manuelle Antworten/Notizen in mail_notes
	//
	// Voraussetzungen:
	//   composer require phpmailer/phpmailer
	//   vendor/autoload.php muss vorhanden sein
	//
	//-------------------------------------------------
	// Konfiguration
	//-------------------------------------------------
	$DB_PATH    = __DIR__ . '/rsvp.sqlite';
	$APP_TITLE  = 'Mailer';
	const MAILER_KEY = 'ekgmwgabi96'; // Zugriff via mailer.php?key=...
	
	// Mail-Passwort: $XCbd!&]}2)a_TN
	
	// SMTP / Absender (Gmail)
	const SMTP_HOST   = 'smtp.gmail.com';
	const SMTP_PORT   = 587;
	const SMTP_SECURE = 'tls'; // 'tls' (STARTTLS)
	const SMTP_USER   = 'abi96.ekg.mwg@gmail.com'; // <- anpassen
	const SMTP_PASS   = 'thqh wgql jafb uots';           // <- Google App-Passwort nutzen
	const FROM_EMAIL  = SMTP_USER;                     // i.d.R. gleich dem Gmail-User
	const FROM_NAME   = 'Orga-Team Abi 96';            // Anzeigename
	
	//-------------------------------------------------
	// DB & Helpers
	//-------------------------------------------------
	function db(): PDO {
		global $DB_PATH; static $pdo=null;
		if ($pdo===null){
			$pdo = new PDO('sqlite:'.$DB_PATH);
			$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$pdo->exec('PRAGMA foreign_keys = ON');
		}
		return $pdo;
	}
	function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
	function app_base_url(): string {
		$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
		$path   = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
		return $scheme.'://'.$host.$path;
	}
	function now(){ return date('Y-m-d H:i:s'); }
	
	//-------------------------------------------------
	// Tabellen anlegen (falls nicht vorhanden)
	//-------------------------------------------------
	function init_db(){
		$pdo = db();
		$pdo->exec(<<<SQL
		CREATE TABLE IF NOT EXISTS mail_messages (
		id          INTEGER PRIMARY KEY AUTOINCREMENT,
		token       TEXT,
		to_email    TEXT NOT NULL,
		subject     TEXT NOT NULL,
		body_html   TEXT NOT NULL,
		from_name   TEXT,
		from_email  TEXT,
		status      TEXT NOT NULL,
		message_id  TEXT,
		attempts    INTEGER DEFAULT 0,
		error       TEXT,
		meta_json   TEXT,
		created_at  TEXT NOT NULL,
		sent_at     TEXT,
		FOREIGN KEY(token) REFERENCES attendees(token) ON DELETE SET NULL
		);
		SQL);
		$pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_token ON mail_messages(token)");
		$pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_status ON mail_messages(status)");
		
		$pdo->exec(<<<SQL
		CREATE TABLE IF NOT EXISTS mail_notes (
		id          INTEGER PRIMARY KEY AUTOINCREMENT,
		token       TEXT NOT NULL,
		note_by     TEXT,
		note_text   TEXT NOT NULL,
		created_at  TEXT NOT NULL,
		FOREIGN KEY(token) REFERENCES attendees(token) ON DELETE CASCADE
		);
		SQL);
		$pdo->exec("CREATE INDEX IF NOT EXISTS idx_notes_token ON mail_notes(token)");
	}
	
	//-------------------------------------------------
	// Session / Flash / CSRF & Access Guard
	//-------------------------------------------------
	session_start();
	function flash($m,$t='success'){ $_SESSION['flash']=['m'=>$m,'t'=>$t]; }
	function get_flash(){ if(isset($_SESSION['flash'])){$f=$_SESSION['flash']; unset($_SESSION['flash']); return $f;} return null; }
	if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
	$CSRF = $_SESSION['csrf'];
	
	$provided_key = $_GET['key'] ?? ($_POST['key'] ?? '');
	if (MAILER_KEY !== '' && (!is_string($provided_key) || !hash_equals(MAILER_KEY, $provided_key))) {
		http_response_code(403);
		echo '<!doctype html><meta charset="utf-8"><div style="font:16px system-ui;padding:2rem">Zugriff verweigert. Key fehlt/ungültig.</div>';
		exit;
	}
	
	//-------------------------------------------------
	// Init
	//-------------------------------------------------
	init_db();
	
	//-------------------------------------------------
	// Datenzugriff
	//-------------------------------------------------
	function get_attendee_by_token(string $token): ?array {
		$st = db()->prepare('SELECT * FROM attendees WHERE token=:t');
		$st->execute([':t'=>$token]);
		$r = $st->fetch(PDO::FETCH_ASSOC); return $r ?: null;
	}
	function insert_mail_message(array $m): void {
		$sql = "INSERT INTO mail_messages (token,to_email,subject,body_html,from_name,from_email,status,message_id,attempts,error,meta_json,created_at,sent_at) VALUES (:token,:to_email,:subject,:body_html,:from_name,:from_email,:status,:message_id,:attempts,:error,:meta_json,:created_at,:sent_at)";
		db()->prepare($sql)->execute([
		':token'      => $m['token'] ?? null,
		':to_email'   => $m['to_email'],
		':subject'    => $m['subject'],
		':body_html'  => $m['body_html'],
		':from_name'  => $m['from_name'] ?? null,
		':from_email' => $m['from_email'] ?? null,
		':status'     => $m['status'],
		':message_id' => $m['message_id'] ?? null,
		':attempts'   => (int)($m['attempts'] ?? 1),
		':error'      => $m['error'] ?? null,
		':meta_json'  => $m['meta_json'] ?? null,
		':created_at' => $m['created_at'] ?? now(),
		':sent_at'    => $m['sent_at'] ?? null,
		]);
	}
	function get_mail_history(string $token): array {
		$st = db()->prepare('SELECT * FROM mail_messages WHERE token=:t ORDER BY id DESC');
		$st->execute([':t'=>$token]);
		return $st->fetchAll(PDO::FETCH_ASSOC);
	}
	function add_note(string $token, string $by, string $text): void {
		$st = db()->prepare("INSERT INTO mail_notes(token, note_by, note_text, created_at) VALUES (:t,:by,:tx, datetime('now'))");
		$st->execute([':t'=>$token, ':by'=>$by, ':tx'=>$text]);
	}
	function get_notes(string $token): array {
		$st = db()->prepare('SELECT * FROM mail_notes WHERE token=:t ORDER BY id DESC');
		$st->execute([':t'=>$token]);
		return $st->fetchAll(PDO::FETCH_ASSOC);
	}
	
	//-------------------------------------------------
	// Routing (GET/POST)
	//-------------------------------------------------
	$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
	$token  = trim($_GET['t'] ?? ($_POST['t'] ?? ''));
	
	// Aktionen: senden / Notiz hinzufügen
	if ($method==='POST') {
		if (!isset($_POST['csrf']) || !hash_equals($CSRF, (string)$_POST['csrf'])) {
			flash('Sicherheitsfehler (CSRF). Bitte erneut versuchen.', 'danger');
			header('Location: '.basename(__FILE__).'?t='.urlencode($token).'&key='.urlencode($provided_key));
			exit;
		}
		$action = $_POST['action'] ?? '';
		if ($action==='send') {
			$to_email = trim($_POST['to_email'] ?? '');
			$subject  = trim($_POST['subject']  ?? '');
			$body     = (string)($_POST['body'] ?? '');
			$from_name  = FROM_NAME;
			$from_email = FROM_EMAIL;
			
			if ($to_email==='' || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
				flash('Zieladresse fehlt/ungültig.', 'danger');
				} elseif ($subject==='') {
				flash('Betreff fehlt.', 'danger');
				} elseif ($body==='') {
				flash('Text fehlt.', 'danger');
				} else {
				// PHPMailer laden
				$autoload = __DIR__.'/vendor/autoload.php';
				if (!is_readable($autoload)) {
					flash('PHPMailer Autoload nicht gefunden (vendor/autoload.php).', 'danger');
					} else {
					require_once $autoload;
					$debugLog = [];
					try {
						$mail = new PHPMailer\PHPMailer\PHPMailer(true);
						$mail->isSMTP();
						$mail->Host       = SMTP_HOST;
						$mail->Port       = SMTP_PORT;
						$mail->SMTPSecure = SMTP_SECURE;
						$mail->SMTPAuth   = true;
						$mail->Username   = SMTP_USER;
						$mail->Password   = SMTP_PASS;
						$mail->CharSet    = 'UTF-8';
						$mail->setFrom($from_email, $from_name);
						
						// Empfängername (falls im Datensatz)
						$att = $token ? get_attendee_by_token($token) : null;
						$to_name = $att ? trim(($att['first_name']??'').' '.($att['last_name']??'')) : '';
						$mail->addAddress($to_email, $to_name);
						$mail->addReplyTo($from_email, $from_name);
						
						$mail->isHTML(true);
						// Body vorbereiten: Plaintext → HTML (Zeilenumbrüche) + Link einsetzen
						$base = app_base_url();
						$personalUrl = $token ? ($base.'/rsvp.php?t='.rawurlencode($token)) : ($base.'/rsvp.php');
						$bodyHtml = nl2br($body);
						// primitiver Token-Ersatz: {{link}} im Text wird ersetzt
						$bodyHtml = str_replace(['{{link}}','{{ LINK }}','{{Link}}'], h($personalUrl), $bodyHtml);
						
						$mail->Subject = $subject;
						$mail->Body    = $bodyHtml;
						$mail->AltBody = trim($body);
						
						$status='sent'; $messageId=null; $error=null;
						try {
							$mail->send();
							$messageId = $mail->getLastMessageID();
							flash('E-Mail erfolgreich gesendet.', 'success');
							} catch (Throwable $e) {
							$status='failed';
							$error = $mail->ErrorInfo ?: $e->getMessage();
							flash('Senden fehlgeschlagen: '.$error, 'danger');
						}
						
						insert_mail_message([
						'token'      => $token ?: null,
						'to_email'   => $to_email,
						'subject'    => $subject,
						'body_html'  => $bodyHtml,
						'from_name'  => $from_name,
						'from_email' => $from_email,
						'status'     => $status,
						'message_id' => $messageId,
						'attempts'   => 1,
						'error'      => $error,
						'meta_json'  => null,
						'created_at' => now(),
						'sent_at'    => $status==='sent'? now(): null,
						]);
						} catch (Throwable $e) {
						flash('Interner Fehler beim Senden: '.$e->getMessage(), 'danger');
					}
				}
			}
			header('Location: '.basename(__FILE__).'?t='.urlencode($token).'&key='.urlencode($provided_key));
			exit;
			} elseif ($action==='add_note') {
			$note_by = trim($_POST['note_by'] ?? '');
			$note_tx = trim($_POST['note_text'] ?? '');
			if ($token==='') { flash('Kein Token übergeben.', 'danger'); }
			elseif ($note_tx==='') { flash('Notiz-Text fehlt.', 'danger'); }
			else { add_note($token, $note_by, $note_tx); flash('Notiz gespeichert.', 'success'); }
			header('Location: '.basename(__FILE__).'?t='.urlencode($token).'&key='.urlencode($provided_key));
			exit;
		}
	}
	
	//-------------------------------------------------
	// Daten für View
	//-------------------------------------------------
	$attendee = $token ? get_attendee_by_token($token) : null;
	$to_email = $attendee['email'] ?? '';
	$to_name  = trim(($attendee['first_name'] ?? '').' '.($attendee['last_name'] ?? ''));
	$to_firstname  = trim(($attendee['first_name'] ?? ''));
	$history  = $token ? get_mail_history($token) : [];
	$notes    = $token ? get_notes($token) : [];
	$flash    = get_flash();
	
	// Standard-Betreff/Text (vorbefüllt) — kann frei geändert werden
	$base      = app_base_url();
	$personal  = $token ? ($base.'/rsvp.php?t='.rawurlencode($token)) : ($base.'/rsvp.php');
	$defSubject= 'Abi 96 – 30 Jahre Treffen - deine Anmeldung';
	$defBody   = "Hallo $to_firstname,\n\n".
	"unser Abitur ist nun bald 30 Jahre her und wir finden, das ist ein Grund zum Feiern. \nUnser Jahrgangsstufenstreffen des Abi-Jahrgangs 1996 von MWG und EKG Lemgo findet am 12.09.2026 im Kesselhaus in Lemgo statt.\n\nHier ist dein persönlicher Link zur Anmeldung/Änderung: {{link}}\n\nBitte prüfe deine Daten, fülle eventuell fehlende Information aus und sage zu. \nFalls du nicht kommen kannst, wäre es toll, wenn du auch absagen würdest.\nSo haben wir eine bessere Kontrolle über alles.\nAlle weiteren Informationen findest du auf der Anmeldeseite.\n\nWir freuen uns auf dich!\n\nDein Orga-Team Abi 96 EKG und MWG\n\np.s.: Bitte heb dir diese E-Mail auf, damit du deine Daten auch später noch einmal ändern/einsehen kannst und auf dem Laufenden bleibst.\n\n";
?>
<!doctype html>
<html lang="de">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title><?=h($APP_TITLE)?> · Mail</title>
		<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
		<style>
			body { background:#f6f7fb; }
			.card { max-width: 1360px; margin: 1rem auto; }
			.small-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85rem; }
			.timeline li + li { border-top: 1px solid #eee; }
		</style>
	</head>
	<body>
		<div class="container">
			<div class="card shadow-sm">
				<div class="card-body p-4">
					<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
						<div class="col-12 text-center">
							<img src="EKG.png" style="height: 100px;">&nbsp;&nbsp;&nbsp;&nbsp;<img src="MWG.png" style="height: 100px; ">
							
							<br>
						</div>
						<h1 class="h4 mb-0"><?=h($APP_TITLE)?> </h1>
						<div class="d-flex gap-2">
							<a class="btn btn-outline-secondary" href="editor.php<?= $provided_key!==''? '?key='.urlencode($provided_key):'' ?>"><i class="bi bi-arrow-left"></i> Zurück zum Editor</a>
							<a class="btn btn-secondary" href="rsvp.php"><i class="bi bi-folder2-open"></i> Zur Formularansicht</a>
							<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#helpModal"><i class="bi bi-question-circle-fill"></i> Inbox prüfen</button> 
						</div>
					</div>
					<div class="alert alert-secondary">
						<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
							<h1 class="h5 mb-0"><i class="bi bi-envelope"></i> Mail an: <?=h($to_name ?: '—')?> <?php if($to_email): ?><span class="text-muted">&lt;<?=h($to_email)?>&gt;</span><?php endif; ?></h1>
							<div class="d-flex gap-2">
								
								<?php if (MAILER_KEY===''): ?>
								<span class="badge text-bg-warning">MAILER_KEY leer – ungeschützt</span>
								<?php endif; ?>
							</div>
						</div>
						
						<?php if ($flash): ?>
						<div class="alert alert-<?=h($flash['t'])?>" role="alert"><?=h($flash['m'])?></div>
						<?php endif; ?>
						
						<?php if (!$token): ?>
						<div class="alert alert-danger">Kein Token übergeben. Aufruf über den Editor (Button „Mail an“) ist empfohlen.</div>
						<?php endif; ?>
						
						<form method="post" class="mb-4">
							<input type="hidden" name="csrf" value="<?=h($CSRF)?>">
							<input type="hidden" name="key"  value="<?=h($provided_key)?>">
							<input type="hidden" name="t"    value="<?=h($token)?>">
							<input type="hidden" name="action" value="send">
							
							<div class="row g-3">
								<div class="col-md-6">
									<label class="form-label">An (E‑Mail)</label>
									<input type="email" class="form-control" name="to_email" value="<?=h($to_email)?>" <?= $to_email?'':'placeholder="keine E‑Mail vorhanden"' ?> required>
									<div class="form-text">Empfängeradresse, änderbar.</div>
								</div>
								<div class="col-md-6">
									<label class="form-label">Betreff</label>
									<input type="text" class="form-control" name="subject" value="<?=h($defSubject)?>" required>
								</div>
								<div class="col-12">
									<label class="form-label">Nachricht</label>
									<textarea class="form-control" name="body" rows="10" required><?=h($defBody)?></textarea>
									<div class="form-text">Hinweis: <code>{{link}}</code> wird automatisch durch den persönlichen Link ersetzt.</div>
								</div>
							</div>
							<div class="mt-3 d-flex gap-2">
								<button class="btn btn-primary"><i class="bi bi-send"></i> Senden</button>
								<a class="btn btn-outline-secondary" href="editor.php<?= $provided_key!==''? '?key='.urlencode($provided_key):'' ?>">Abbrechen</a>
							</div>
						</form>
					</div>
					
					<div class="row g-4">
						<div class="col-lg-7">
							<div class="alert alert-secondary">
								<h2 class="h6 mb-2"><i class="bi bi-journal-text"></i> Versandprotokoll</h2>
								<?php if (!$history): ?>
								<div class="text-muted">Noch keine Einträge.</div>
								<?php else: ?>
								<ul class="list-unstyled timeline">
									<?php foreach ($history as $m): ?>
									<li class="py-3">
										<div class="d-flex justify-content-between">
											<div>
												<div><strong><?=h($m['subject'])?></strong></div>
												<div class="small text-muted">Status: <?=h($m['status'])?><?php if($m['message_id']): ?> · ID: <span class="small-mono"><?=h($m['message_id'])?></span><?php endif; ?></div>
												<?php if($m['error']): ?><div class="small text-danger">Fehler: <?=h($m['error'])?></div><?php endif; ?>
											</div>
											<div class="text-end small text-muted">
												<div>Erstellt: <?=h($m['created_at'])?></div>
												<?php if ($m['sent_at']): ?><div>Gesendet: <?=h($m['sent_at'])?></div><?php endif; ?>
											</div>
										</div>
										<details class="mt-2">
											<summary class="small">Inhalt anzeigen</summary>
											<div class="border rounded p-2 mt-2 bg-light" style="white-space:pre-wrap;overflow:auto;max-height:280px;">
												<?=$m['body_html'] /* bewusst nicht escapen, da HTML-Snapshot */?>
											</div>
										</details>
									</li>
									<?php endforeach; ?>
								</ul>
								<?php endif; ?>
							</div>
						</div>
						<div class="col-lg-5">
							<div class="alert alert-secondary">
								<h2 class="h6 mb-2"><i class="bi bi-chat-dots"></i> Antworten / Notizen</h2>
								<form method="post" class="mb-3">
									<input type="hidden" name="csrf" value="<?=h($CSRF)?>">
									<input type="hidden" name="key"  value="<?=h($provided_key)?>">
									<input type="hidden" name="t"    value="<?=h($token)?>">
									<input type="hidden" name="action" value="add_note">
									<div class="mb-2">
										<label class="form-label">Von (wer trägt ein?)</label>
										<input type="text" name="note_by" class="form-control" required placeholder="Dein Name (wäre cool)" />
									</div>
									<div class="mb-2">
										<label class="form-label">Notiz / Antwort</label>
										<textarea name="note_text" class="form-control" rows="4" placeholder="Antwort zusammenfassen…" required></textarea>
									</div>
									<button class="btn btn-outline-primary"><i class="bi bi-plus-circle"></i> Notiz hinzufügen</button>
								</form>
								
								<?php if (!$notes): ?>
								<div class="text-muted">Noch keine Notizen.</div>
								<?php else: ?>
								<div class="list-group">
									<?php foreach ($notes as $n): ?>
									<div class="list-group-item">
										<div class="d-flex justify-content-between">
											<strong><?=h($n['note_by'] ?: 'Notiz')?></strong>
											<span class="small text-muted"><?=h($n['created_at'])?></span>
										</div>
										<div class="mt-1" style="white-space:pre-wrap;"><?=nl2br(h($n['note_text']))?></div>
									</div>
									<?php endforeach; ?>
								</div>
								<?php endif; ?>
							</div>
						</div>
					</div>
					
				</div>
			</div>
		</div>
		
		
		
		
		
		<div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpModalLabel" aria-hidden="true">
			<div class="modal-dialog modal-xl modal-dialog-scrollable">
				<div class="modal-content">
					<div class="modal-header">
						<h5 class="modal-title" id="helpModalLabel">Posteingang des E-Mail Postfachs </h5>
						<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
					</div>
					<div class="modal-body">
						
							<?php 
								include('inbox.php');
								
							?>
						
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Alles klar</button>
					</div>
				</div>
			</div>
		</div>
		
		<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
	</body>
</html>
