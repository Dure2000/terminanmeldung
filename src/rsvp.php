<?php
	// rsvp.php — Ein Script zum Anmelden zum Jahrgangsstufenstreffen
	// Author: Ralf Schwalbe, 01.10.2025
	// Neu: Admin-Ansicht (Status/Bezahlt), CSV-Export
	// setze unten ADMIN_KEY und nutze ?admin=1&key=<ADMIN_KEY>.
	
	// -----------------------------------------------
	// Konfiguration
	// -----------------------------------------------
	$DB_PATH = __DIR__ . '/rsvp.sqlite';
	$CSV_PATH = __DIR__ . '/attendees.csv'; // optionaler Seed
	$APP_TITLE = 'Abi 96 - 30 Jahre! – Anmeldung';
	$FROM_NAME = 'Orga-Team';
	$IBAN = "";
	
	// Admin-Zugriff: leer lassen = ungeschützt (nicht empfohlen)
	const ADMIN_KEY = ''; // z.B. 'A9k33f...'; Zugriff via ?admin=1&key=...
	
	// -----------------------------------------------
	// Hilfsfunktionen DB
	// -----------------------------------------------
	function db(): PDO {
		global $DB_PATH;
		static $pdo = null;
		if ($pdo === null) {
			$pdo = new PDO('sqlite:' . $DB_PATH);
			$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$pdo->exec('PRAGMA foreign_keys = ON');
		}
		return $pdo;
	}
	
	function init_db() {
		$sql = "
		CREATE TABLE IF NOT EXISTS attendees (
		token TEXT PRIMARY KEY,
		first_name TEXT,
		last_name TEXT,
		email TEXT,
		phone TEXT,
		school TEXT,
		status TEXT, -- 'yes' | 'no' | NULL
		paid INTEGER DEFAULT 0, -- 0|1
		updated_at TEXT
		);
		";
		$pdo = db();
		$pdo->exec($sql);
		
		// Migrations: Spalte paid nachrüsten, falls alte DB
		if (!column_exists('attendees','school')) {
			$pdo->exec("ALTER TABLE attendees ADD COLUMN school TEXT");
		}
	}
	
	function column_exists($table,$col): bool {
		$stmt = db()->prepare("PRAGMA table_info($table)");
		$stmt->execute();
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			if (strcasecmp($row['name'],$col)===0) return true;
		}
		return false;
	}
	
	
	function get_attendee_by_token(string $token): ?array {
		$stmt = db()->prepare('SELECT * FROM attendees WHERE token = :t');
		$stmt->execute([':t' => $token]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}
	
	function upsert_attendee(array $data) {
		if (empty($data['token'])) {
			$data['token'] = bin2hex(random_bytes(16));
		}
		$sql = "
		INSERT INTO attendees (token, first_name, last_name, email, phone, school, status, paid, updated_at)
		VALUES (:token, :fn, :ln, :email, :phone, :school, :status, COALESCE(:paid,0), datetime('now'))
		ON CONFLICT(token) DO UPDATE SET
		first_name=excluded.first_name,
		last_name=excluded.last_name,
		email=excluded.email,
		phone=excluded.phone,
		school=excluded.school,
		status=COALESCE(excluded.status, attendees.status),
		paid=COALESCE(excluded.paid, attendees.paid),
		updated_at=datetime('now');
		";
		$stmt = db()->prepare($sql);
		$stmt->execute([
		':token' => $data['token'],
		':fn'	 => $data['first_name'] ?? '',
		':ln'	 => $data['last_name'] ?? '',
		':email' => $data['email'] ?? '',
		':phone' => $data['phone'] ?? '',
		':school' => $data['school'] ?? '',
		':status' => in_array($data['status'] ?? null, ['yes','no'], true) ? $data['status'] : null,
		':paid' => isset($data['paid']) ? (int)!!$data['paid'] : null,
		]);
		return $data['token'];
	}
	
	function set_paid(string $token, int $paid): void {
		$stmt = db()->prepare("UPDATE attendees SET paid=:p, updated_at=datetime('now') WHERE token=:t");
		$stmt->execute([':p'=>$paid, ':t'=>$token]);
	}
	
	function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
	
	// -----------------------------------------------
	// Bootstrap + Session Flash
	// -----------------------------------------------
	session_start();
	function flash($msg, $type='success') { $_SESSION['flash'] = ['msg'=>$msg, 'type'=>$type]; }
	function get_flash() { if (!empty($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); return $f; } return null; }
	
	// -----------------------------------------------
	// Init
	// -----------------------------------------------
	init_db();
	
	//seed_from_csv_if_empty();
	
	// -----------------------------------------------
	// Routing
	// -----------------------------------------------
	$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
	$token_param = trim($_GET['t'] ?? '');
	$is_admin = (isset($_GET['admin']) && $_GET['admin'] == '1');
	$admin_key_ok = (ADMIN_KEY === '' || (isset($_GET['key']) && hash_equals(ADMIN_KEY, (string)$_GET['key'])));
	
	
	

	// Front: Formular-Submit
	if ($method === 'POST') {
		$token = trim($_POST['token'] ?? ($token_param ?: ''));
		$fn	 = trim($_POST['first_name'] ?? '');
		$ln	 = trim($_POST['last_name'] ?? '');
		$email = trim($_POST['email'] ?? '');
		$phone = trim($_POST['phone'] ?? '');
		$school = trim($_POST['school'] ?? '');
		$status = $_POST['status'] ?? null; // 'yes' | 'no' | null
		$hp = trim($_POST['hp'] ?? '');
		if ($hp !== '') { // Honeypot ausgelöst → Submission ignorieren
			usleep(300000);
			header('Location: ' . $_SERVER['REQUEST_URI']);
			exit;
		}
		
		if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			flash('Bitte eine gültige E‑Mail-Adresse eingeben.', 'danger');
			} else {
			$saved_token = upsert_attendee([
			'token'	 => $token,
			'first_name' => $fn,
			'last_name' => $ln,
			'email'	 => $email,
			'phone'	 => $phone,
			'school' => $school,
			'status'	 => $status,
			]);
			$msg = 'Daten gespeichert';
			if ($status === 'yes') $msg = 'Danke! Wir freuen uns: Du kommst.';
			if ($status === 'no') $msg = 'Schade! Wir haben vermerkt, dass Du nicht kommst. Das kannst du aber noch ändern';
			flash($msg . ' (Dein Token: ' . $saved_token . ')', 'success');
			$redir = $_SERVER['PHP_SELF'] . '?t=' . urlencode($saved_token);
			header('Location: ' . $redir);
			exit;
		}
	}
	
	// Daten für Front laden
	$attendee = null;
	if ($token_param !== '') {
		$attendee = get_attendee_by_token($token_param);
	}
	$prefill = [
	'token'	 => $attendee['token'] ?? '',
	'first_name' => $attendee['first_name'] ?? '',
	'last_name' => $attendee['last_name'] ?? '',
	'email'	 => $attendee['email'] ?? '',
	'phone'	 => $attendee['phone'] ?? '',
	'school'	 => $attendee['school'] ?? '',
	'status'	 => $attendee['status'] ?? null,
	'paid'	 => (int)($attendee['paid'] ?? 0),
	];
	$flash = get_flash();
	
	// Statistik
	$stats = [
	'total'     => (int)db()->query("SELECT COUNT(*) FROM attendees")->fetchColumn(),
	'yes'       => (int)db()->query("SELECT COUNT(*) FROM attendees WHERE status='yes'")->fetchColumn(),
	'no'        => (int)db()->query("SELECT COUNT(*) FROM attendees WHERE status='no'")->fetchColumn(),
	'open'      => (int)db()->query("SELECT COUNT(*) FROM attendees WHERE status IS NULL")->fetchColumn(),
	'paid'      => (int)db()->query("SELECT COUNT(*) FROM attendees WHERE paid=1")->fetchColumn(),
	'unpaid'    => (int)db()->query("SELECT COUNT(*) FROM attendees WHERE paid=0")->fetchColumn(),
	'ekg_total' => (int)db()->query("SELECT COUNT(*) FROM attendees WHERE school='EKG'")->fetchColumn(),
	'ekg_yes'   => (int)db()->query("SELECT COUNT(*) FROM attendees WHERE school='EKG' AND status='yes'")->fetchColumn(),
	'ekg_paid'  => (int)db()->query("SELECT COUNT(*) FROM attendees WHERE school='EKG' AND paid=1")->fetchColumn(),
	'mwg_total' => (int)db()->query("SELECT COUNT(*) FROM attendees WHERE school='MWG'")->fetchColumn(),
	'mwg_yes'   => (int)db()->query("SELECT COUNT(*) FROM attendees WHERE school='MWG' AND status='yes'")->fetchColumn(),
	'mwg_paid'  => (int)db()->query("SELECT COUNT(*) FROM attendees WHERE school='MWG' AND paid=1")->fetchColumn(),
	];
	
	// -----------------------------------------------
	// Views
	// -----------------------------------------------
?>
<!doctype html>
<html lang="de">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="robots" content="noindex, nofollow">
		<!--
			Author: Ralf Schwalbe
			Date: 2025-10-01
			Version: 0.12
			ToDo: 
			- Verstecken von "zufällig finden", Hash-URL und HoneyPot?
			- Verstecke Admin button
			- Verstecke Liste bereits registrierter (Datenschutz, wenn ausgerollt).
			- SQLite-Admin für manuelles Berbeiten der Daten ... 
		-->
		
		<title><?=h($APP_TITLE)?></title>
		<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" >
		
		<style>
			body { background: #f7f7f9; }
			.card { max-width: 1360px; margin: 1rem auto; }
			.btn-xl { padding: 1rem 1.25rem; font-size: 1.1rem; }
			button .small {font-size: 1rem}
			.hp-wrap{position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;}
			.progress-thin{height:6px}
		</style>
	</head>
	<body>
		<div class="container"><br>
			
			<div class="card shadow-sm">
				<div class="card-body p-4 p-md-5">
					<div class="col-12 text-center">
						<img src="EKG.png" style="height: 100px;">&nbsp;&nbsp;&nbsp;&nbsp;<img src="MWG.png" style="height: 100px; ">
						
						<br><br>
						<h1 class="h3 mb-1"><?=h($APP_TITLE)?> 		
						</h1>
						<p class="text-muted mb-4">Unser Jahrgangsstufenstreffen des Abi-Jahrgangs 1996 von MWG und EKG Lemgo findet am <strong>12.09.2026</strong> im Kesselhaus in Lemgo statt.<br>Bitte prüfe oder befülle Deine Daten und sag uns mit einem Klick, dass Du kommst.</p>
					</div>
					
					<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
						<button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#helpModal"><i class="bi bi-question-circle"></i> Hinweise &amp; FAQ</button>   <span class="text-body-secondary"><== Bitte erst lesen!</span>
							
							<a href="rsvp.php" class="btn btn-outline-secondary ms-auto" title="Wenn dir noch jemand einfällt, leg diese Person neu an und sende ihr ihren Link. Bitte nicht die eigenen Daten mit einem anderen Namen überschreiben, sondern einen neuen Eintrag anlegen"><i class="bi bi-node-plus"></i> Neuer Eintrag</a>
						</div>
						
						
						
						
						
						
						
						
						
						
						<?php
							$tot = max(1, (int)$stats['total']);
							$yes_pct  = (int)round(100 * ($stats['yes'] / $tot));
							$no_pct   = (int)round(100 * ($stats['no']  / $tot));
							$open_pct = max(0, 100 - $yes_pct - $no_pct);
							$paid_pct = (int)round(100 * ($stats['paid'] / $tot));
							
							$ekg_tot = max(1, (int)$stats['ekg_total']);
							$ekg_yes_pct  = (int)round(100 * ($stats['ekg_yes']  / $ekg_tot));
							$ekg_paid_pct = (int)round(100 * ($stats['ekg_paid'] / $ekg_tot));
							
							$mwg_tot = max(1, (int)$stats['mwg_total']);
							$mwg_yes_pct  = (int)round(100 * ($stats['mwg_yes']  / $mwg_tot));
							$mwg_paid_pct = (int)round(100 * ($stats['mwg_paid'] / $mwg_tot));
						?>
						
						<?php if ($flash): ?>
						<div class="alert alert-<?=h($flash['type'])?>" role="alert">
							<?=h($flash['msg'])?>
						</div>
						<?php endif; ?>
						
						<?php if ($prefill['status'] === 'yes'): ?>
						<div class="alert alert-success" role="alert">Status: <strong>Du kommst</strong> ✅</div>
						<?php elseif ($prefill['status'] === 'no'): ?>
						<div class="alert alert-warning" role="alert">Status: <strong>Du kommst nicht</strong> ❌</div>
						<?php endif; ?>
						
						<?php if (!empty($prefill['token'])): ?>
						<?php if (!empty($prefill['paid'])): ?>
						<div class="alert alert-success" role="alert">Bezahlstatus: <strong>Zahlung eingegangen</strong> 💶✅</div>
						<?php else: ?>
						<div class="alert alert-secondary" role="alert">Bezahlstatus: <strong>Noch offen</strong> — bitte 75,00&nbsp;€ bis <strong>31.12.2025</strong> an IBAN <code><?=h($IBAN);?></code> überweisen.</div>
						<?php endif; ?>
						<?php endif; ?>
						<br><br>
						<div class="alert alert-secondary">
							<h6>Deine Daten</h6>
							
							<form id="rsvpForm" method="post" class="needs-validation" novalidate>
								<input type="hidden" name="token" value="<?=h($prefill['token'])?>">
								<div class="hp-wrap" aria-hidden="true">
									<label for="hp">Homepage</label>
									<input type="text" id="hp" name="hp" autocomplete="off" tabindex="-1">
								</div>
								
								<div class="row g-3">
									<div class="col-md-6">
										<label for="first_name" class="form-label">Vorname</label>
										<input type="text" class="form-control" id="first_name" name="first_name" value="<?=h($prefill['first_name'])?>" required>
										<div class="invalid-feedback">Bitte ausfüllen.</div>
									</div>
									<div class="col-md-6">
										<label for="last_name" class="form-label">Nachname</label>
										<input type="text" class="form-control" id="last_name" name="last_name" value="<?=h($prefill['last_name'])?>" >
									</div>
									<div class="col-md-5">
										<label for="email" class="form-label">E‑Mail</label>
										<input type="email" class="form-control" id="email" name="email" value="<?=h($prefill['email'])?>" placeholder="">
									</div>
									<div class="col-md-5">
										<label for="phone" class="form-label">Telefon</label>
										<input type="text" class="form-control" id="phone" name="phone" value="<?=h($prefill['phone'])?>" placeholder="">
									</div>
									<div class="col-md-2">
										<label for="phone" class="form-label">Schule</label>
										<select class="form-select form-select-sm" name="school">
											<option value="EKG" <?= $prefill['school']==='EKG'? 'selected':'' ?>>EKG</option>
											<option value="MWG"  <?= $prefill['school']==='MWG'?  'selected':'' ?>>MWG</option>
										</select>
									</div>
								</div>
								
								
								
								<div class="d-grid gap-3 d-md-flex mt-4">
									<button class="btn btn-success btn-lg flex-fill" type="submit" name="status" value="yes"><span class="small"><i class="bi bi-hand-thumbs-up"></i> Speichern mit "Ich komme"</span></button>
									<button class="btn btn-danger btn-lg flex-fill" type="submit" name="status" value="no"><span class="small"><i class="bi bi-hand-thumbs-down"></i> Speichern mit "Ich komme nicht"</span></button>
									<button class="btn btn-outline-primary btn-lg" type="submit" name="status" value=""><span class="small"><i class="bi bi-hourglass"></i> Nur Daten Speichern</span></button>
								</div>
								
							</div>
							</form>
							<br><br>
							
							<div class="alert alert-secondary">
								
								<div class="mb-4">
									<h6>Dein persönlicher Link (zum Kopieren - <strong>nach</strong> dem Speichern)</h6>
									<div class="input-group">
										<input type="url" class="form-control" id="shareUrl" readonly value="https://<?=h($_SERVER['SERVER_NAME'])?><?=h($_SERVER['PHP_SELF'])?>?t=<?=h($prefill['token']);?>">
										<button class="btn btn-outline-secondary" type="button" id="btnCopyUrl"><i class="bi bi-copy"></i> Kopieren</button>
									</div>
								</div>
								<div class="d-flex gap-2 mt-2 flex-wrap">
									<button class="btn btn-outline-primary btn-sm" type="button" id="btnSharePersonal"><i class="bi bi-share"></i> Meinen Link teilen</button>
									<button class="btn btn-outline-primary btn-sm" type="button" id="btnShareNeutral"><i class="bi bi-share"></i> Neutralen Link teilen</button>
									<button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#helpModal"><i class="bi bi-question-circle"></i> Hinweise &amp; FAQ</button>
								</div>
							</div>	
							
							<br><br>
							<div class="alert alert-primary">
								<div class="row g-3 mb-4">
									<div class="col-12 col-md-6 col-lg-4">
										<div class="card h-100 shadow-sm">
											<div class="card-body">
												<div class="d-flex justify-content-between align-items-baseline">
													<h2 class="h6 mb-0">Gesamt</h2>
													<span class="badge text-bg-dark"><?=$stats['total']?> Einträge</span>
												</div>
												<div class="mt-3">
													<div class="d-flex justify-content-between small"><span>Zusage</span><span><?=$stats['yes']?> (<?=$yes_pct?>%)</span></div>
													<div class="progress progress-thin"><div class="progress-bar bg-success" style="width: <?=$yes_pct?>%"></div></div>
													<div class="d-flex justify-content-between small mt-2"><span>Offen</span><span><?=$stats['open']?> (<?=$open_pct?>%)</span></div>
													<div class="progress progress-thin"><div class="progress-bar bg-secondary" style="width: <?=$open_pct?>%"></div></div>
													<div class="d-flex justify-content-between small mt-2"><span>Absage</span><span><?=$stats['no']?> (<?=$no_pct?>%)</span></div>
													<div class="progress progress-thin"><div class="progress-bar bg-danger" style="width: <?=$no_pct?>%"></div></div>
													<div class="d-flex justify-content-between small mt-3"><span>Bezahlt</span><span><?=$stats['paid']?> (<?=$paid_pct?>%)</span></div>
													<div class="progress progress-thin"><div class="progress-bar" style="width: <?=$paid_pct?>%"></div></div>
												</div>
											</div>
										</div>
									</div>
									
									<div class="col-12 col-md-6 col-lg-4">
										<div class="card h-100 shadow-sm">
											<div class="card-body">
												<div class="d-flex justify-content-between align-items-baseline">
													<h2 class="h6 mb-0">EKG</h2>
													<span class="badge text-bg-dark"><?=$stats['ekg_total']?></span>
												</div>
												<div class="mt-3">
													<div class="d-flex justify-content-between small"><span>Zusage</span><span><?=$stats['ekg_yes']?> (<?=$ekg_yes_pct?>%)</span></div>
													<div class="progress progress-thin"><div class="progress-bar bg-success" style="width: <?=$ekg_yes_pct?>%"></div></div>
													<div class="d-flex justify-content-between small mt-2"><span>Bezahlt</span><span><?=$stats['ekg_paid']?> (<?=$ekg_paid_pct?>%)</span></div>
													<div class="progress progress-thin"><div class="progress-bar" style="width: <?=$ekg_paid_pct?>%"></div></div>
												</div>
											</div>
										</div>
									</div>
									
									<div class="col-12 col-md-6 col-lg-4">
										<div class="card h-100 shadow-sm">
											<div class="card-body">
												<div class="d-flex justify-content-between align-items-baseline">
													<h2 class="h6 mb-0">MWG</h2>
													<span class="badge text-bg-dark"><?=$stats['mwg_total']?></span>
												</div>
												<div class="mt-3">
													<div class="d-flex justify-content-between small"><span>Zusage</span><span><?=$stats['mwg_yes']?> (<?=$mwg_yes_pct?>%)</span></div>
													<div class="progress progress-thin"><div class="progress-bar bg-success" style="width: <?=$mwg_yes_pct?>%"></div></div>
													<div class="d-flex justify-content-between small mt-2"><span>Bezahlt</span><span><?=$stats['mwg_paid']?> (<?=$mwg_paid_pct?>%)</span></div>
													<div class="progress progress-thin"><div class="progress-bar" style="width: <?=$mwg_paid_pct?>%"></div></div>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>
							
							
							
							
							<div class="alert alert-warning mt-3" role="alert">
								<strong>Wichtig:</strong> Verbindlich wird Deine Anmeldung erst mit der Überweisung von <strong>75,00&nbsp;€</strong>
								bis spätestens <strong>31.12.2025</strong> auf das Konto <strong>IBAN</strong>
								<code><?=h($IBAN);?></code>. Wenn deine Zahlung gebucht ist, kannst du das auch hier einsehen!
							</div>
							<!--<br><br><br><br>
						
						<hr><span class="text-danger small">Ab hier ist alles im Produktiv-Einsatz verborgen.</span><hr>
							<div class="mt-4">
								<a class="btn btn-sm btn-outline-secondary" href="editor.php?key=">Zum Adminbereich</a>
							</div>
						-->
						
						<?php
							// Liste bereits erfasster Personen ein-/ausblenden
							// Steuerung: Konstante LIST_DEFAULT oder URL-Parameter ?list=1|0
							if (!defined('LIST_DEFAULT')) { define('LIST_DEFAULT', false); }
							$show_list = isset($_GET['list']) ? ($_GET['list'] === '1') : LIST_DEFAULT;
							if ($show_list):
							$stmt = db()->query("SELECT first_name, last_name, email, phone, school FROM attendees ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE");
							$existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
						?>
						<hr class="my-4">
						<div class="d-flex align-items-center justify-content-between mb-2">
							
							<div class="small text-muted">Diese Liste ist nur für das Orga-Team gedacht. Über die URL steuerbar: <code>?list=1</code> anzeigen, <code>?list=0</code> ausblenden. würde ich auch rausnehmen, bevor es los geht!<br><strong>Bereits erfasst (<?=count($existing)?>)</strong></div>
						</div>
						<div class="table-responsive">
							<table class="table table-sm">
								<thead>
									<tr>
										<th>Vorname</th>
										<th>Nachname</th>
										<th>E‑Mail</th>
										<th>Telefon</th>
										<th>Schule</th>
									</tr>
								</thead>
								<tbody>
									<?php if ($existing): foreach ($existing as $e): ?>
									<tr>
										<td><?=h($e['first_name'])?></td>
										<td><?=h($e['last_name'])?></td>
										<td><?=h($e['email'])?></td>
										<td><?=h($e['phone'])?></td>
										<td><?=h($e['school'])?></td>
									</tr>
									<?php endforeach; else: ?>
									<tr><td colspan="4" class="text-muted">Noch keine Einträge</td></tr>
									<?php endif; ?>
								</tbody>
							</table>
						</div>
						<?php endif; ?>
						
						<hr class="my-4">
						<p class="mb-0 text-muted">Fragen? Melde Dich beim <a hreF="mailto:abi96.ekg.mwg@gmail.com">Orga‑Team</a>. Grüße, <a hreF="mailto:abi96.ekg.mwg@gmail.com"><?=h($FROM_NAME)?></a>.</p>
						</div>
					</div>
				</div>
				
				<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
				<script>
					(() => {
						'use strict';
						const forms = document.querySelectorAll('.needs-validation');
						Array.from(forms).forEach(form => {
							form.addEventListener('submit', event => {
								if (!form.checkValidity()) { event.preventDefault(); event.stopPropagation(); }
								form.classList.add('was-validated');
							}, false);
						});
					})();
				</script>
				<script>
					// Kopieren & Teilen (Personal/Neutral)
					(() => {
						const input = document.getElementById('shareUrl');
						const btnCopy = document.getElementById('btnCopyUrl');
						const btnSharePersonal = document.getElementById('btnSharePersonal');
						const btnShareNeutral = document.getElementById('btnShareNeutral');
						
						// Links bestimmen
						const current = new URL(window.location.href);
						const token = (current.searchParams.get('t') || '').trim();
						const personalUrl = (input && input.value) ? input.value : (token ? current.href : '');
						const neutralUrl = current.origin + current.pathname; // bewusst OHNE Token/Parameter
						
						// Input füllen, falls leer
						if (input && (!input.value || /[?&]t=$/.test(input.value))) {
							input.value = personalUrl || neutralUrl;
						}
						
						function copyToClipboard(text, btn) {
							if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
								navigator.clipboard.writeText(text).then(() => flash(btn), () => fallback());
								} else {
								fallback();
							}
							function fallback(){
								const ta = document.createElement('textarea');
								ta.value = text;
								ta.setAttribute('readonly','');
								ta.style.position = 'fixed'; ta.style.top = '-1000px';
								document.body.appendChild(ta);
								ta.select();
								try { document.execCommand('copy'); flash(btn); }
								catch(e){ alert('Kopieren nicht möglich. Bitte manuell kopieren.'); }
								document.body.removeChild(ta);
							}
						}
						function flash(btn){
							if (!btn) return;
							const old = btn.textContent; btn.textContent = 'Kopiert!';
							setTimeout(() => btn.textContent = old, 1500);
						}
						async function share(url, title, text, fallbackBtn){
							if (navigator.share) {
								try { await navigator.share({title, text, url}); return; } catch(e){ /* abgebrochen */ }
							}
							copyToClipboard(url, fallbackBtn);
						}
						
						if (btnCopy && input) btnCopy.addEventListener('click', () => copyToClipboard(input.value, btnCopy));
						if (btnSharePersonal) btnSharePersonal.addEventListener('click', () => {
							if (!personalUrl) { alert('Persönlicher Link noch nicht vorhanden. Bitte zuerst speichern.'); return; }
							share(personalUrl, document.title, 'Mein persönlicher Anmeldelink', btnSharePersonal);
						});
						if (btnShareNeutral) btnShareNeutral.addEventListener('click', () => {
							share(neutralUrl, document.title, 'Neutrale Anmeldeseite ohne Token', btnShareNeutral);
						});
					})();
				</script>
				<br><div class="small text-center">&copy; 2025 Ralf Schwalbe</div>
				
				<!-- Hinweise & FAQ Modal -->
				<div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpModalLabel" aria-hidden="true">
					<div class="modal-dialog modal-xl modal-dialog-scrollable">
						<div class="modal-content">
							<div class="modal-header">
								<h5 class="modal-title" id="helpModalLabel">Hinweise &amp; FAQ</h5>
								<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
							</div>
							<div class="modal-body">
								<div class="alert alert-warning" role="alert">
									⚠️ <strong>Wichtig:</strong> Teile deinen <em>persönlichen Link</em> <u>nicht</u> mit anderen – damit könnten deine Daten überschrieben werden. Für Einladungen bitte immer den <strong>neutralen Link</strong> verwenden.
								</div>
								
								<div class="alert  alert-secondary" role="alert">
									<h6 class="mt-3">Persönlicher Link vs. neutraler Link</h6>
									<p>Dein persönlicher Link enthält einen eindeutigen Token. Damit rufst du <strong>deine</strong> Daten auf und kannst sie ändern. Der <strong>neutrale Link</strong> enthält <em>keinen</em> Token und führt zu einem leeren Formular für neue Einträge.</p>
									<ul class="small">
										<li><strong>„Diesen Link teilen“</strong> → persönlicher Link <em>nur für dich</em> (z. B. auf anderen Geräten).</li>
										<li><strong>„Neutralen Link teilen“</strong> → ohne Token, ideal für Gruppen/Einladungen.</li>
									</ul>
								</div>
								
								<div class="alert  alert-secondary" role="alert">
									<h6 class="mt-3">Kopieren &amp; Teilen</h6>
									<p>Nutze den Button <strong>„Kopieren“</strong> neben deinem Link. Auf dem Handy öffnet <em>Teilen</em> (falls verfügbar) die native Teilen-Ansicht; sonst kopieren wir den Link in die Zwischenablage.</p>
								</div>
								
								<div class="alert alert-warning" role="alert">
									<h6 class="mt-3">Zahlung &amp; Verbindlichkeit</h6>
									Verbindlich wird die Anmeldung mit der Überweisung von <strong>75,00&nbsp;€</strong> bis <strong>31.12.2025</strong> auf die <strong>IBAN</strong> <code><?=h($IBAN);?></code>. Der Bezahlstatus wird hier angezeigt, sobald die Zahlung verbucht ist.
								</div>
								<div class="alert alert-secondary" role="alert">
									<h6 class="mt-3">Was steckt in den 75&nbsp;€?</h6>
									<div>Alles – von <em>Essen &amp; Getränken</em> über <em>Saalmiete</em> und <em>DJ</em> bis hin zu <em>Versicherung</em> und <em>Reinigung</em>. Kurz: Du kommst, wir kümmern uns. Du gehst, und es glänzt wieder – nur die Erinnerungen bleiben schön klebrig 😄.</div>
								</div>
								<div class="alert  alert-secondary" role="alert">
									<h6 class="mt-3">Datenschutz</h6>
									<p>Wir verwenden deine Angaben ausschließlich für die Organisation des Treffens. Du kannst deine Daten jederzeit über deinen persönlichen Link anpassen. Bei Fragen melde dich einfach beim <a hreF="mailto:abi96.ekg.mwg@gmail.com">Orga‑Team</a>.</p>
								</div>
								<div class="alert  alert-secondary" role="alert">
									<h6 class="mt-3">Häufige Fälle</h6>
									<ul class="small mb-0">
										<li><strong>Falschen Link geteilt?</strong> Erkläre der Person, dass Sie sich bitte neu anlegen soll (Neuer Eintrag). Rufe deinen persönlichen Link wieder auf und korrigiere die Daten. Im Zweifel kurz das Orga‑Team informieren.</li>
										<li><strong>Link verloren?</strong> Frage nochmal danach, wir können dir jederzeit deinen Link nochmal zusenden.</li>
										<li><strong>Verklickt bei „Ich komme / ich komme nicht“?</strong> Einfach erneut speichern; der Status lässt sich jederzeit ändern.</li>
									</ul>
								</div>
							</div>
							<div class="modal-footer">
								<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Alles klar</button>
							</div>
						</div>
					</div>
				</div>
			</body>
		</html>
		
