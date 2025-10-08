<?php
	// importer.php — CSV-Importer für rsvp.sqlite
	// Funktionen: Drag&Drop-Upload (Dropzone.js), Spalten-Mapping, Duplikatprüfung (Token/Name),
	//             Review & Commit (Update/Insert/Skip) mit Zusammenfassung
	// Optisch/technisch passend zu rsvp.php/editor.php, aber als eigenständige Datei
	// Schutz: IMPORT_KEY (Query-Param ?key=...) + CSRF
	
	//-------------------------------------------------
	// Konfiguration
	//-------------------------------------------------
	$DB_PATH    = __DIR__ . '/rsvp.sqlite';
	$APP_TITLE  = 'CSV-Importer';
	const IMPORT_KEY = 'ekgmwgabi96'; // z.B. 'zX3...'; Aufruf: importer.php?key=DEIN_KEY
	const MAX_PREVIEW_ROWS = 50;  // für Mapping/Review, Performance
	const UPLOAD_DIR = null;        // null => sys_get_temp_dir(); sonst absoluter Pfad
	
	//-------------------------------------------------
	// DB & Helpers
	//-------------------------------------------------
	function db(): PDO {
		global $DB_PATH; static $pdo=null;
		if ($pdo===null){
			$pdo = new PDO('sqlite:'.$DB_PATH);
			$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$pdo->exec('PRAGMA foreign_keys=ON');
		}
		return $pdo;
	}
	function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
	function now(){ return date('Y-m-d H:i:s'); }
	function app_base_url(): string {
		$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
		$path   = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
		return $scheme.'://'.$host.$path;
	}
	
	//-------------------------------------------------
	// Session, Flash, CSRF, Access
	//-------------------------------------------------
	session_start();
	function flash($m,$t='success'){ $_SESSION['flash']=['m'=>$m,'t'=>$t]; }
	function get_flash(){ if(isset($_SESSION['flash'])){$f=$_SESSION['flash']; unset($_SESSION['flash']); return $f;} return null; }
	if (empty($_SESSION['csrf'])) { $_SESSION['csrf']=bin2hex(random_bytes(16)); }
	$CSRF = $_SESSION['csrf'];
	
	// Access Guard
	$provided_key = $_GET['key'] ?? ($_POST['key'] ?? '');
	if (IMPORT_KEY !== '' && (!is_string($provided_key) || !hash_equals(IMPORT_KEY, $provided_key))) {
		http_response_code(403);
		echo '<!doctype html><meta charset="utf-8"><div style="font:16px system-ui;padding:2rem">Zugriff verweigert. Key fehlt/ungültig.</div>';
		exit;
	}
	
	//-------------------------------------------------
	// CSV Utilities
	//-------------------------------------------------
	function detect_delimiter(string $file): string {
		$sample = file_get_contents($file, false, null, 0, 4096) ?: '';
		$candidates = [',',';','\t','|'];
		$best=','; $bestCount=0;
		foreach($candidates as $d){
			$count = substr_count($sample, $d);
			if ($count>$bestCount){ $best=$d; $bestCount=$count; }
		}
		return $best; // simple heuristic
	}
	function read_csv_preview(string $file, int $maxRows=MAX_PREVIEW_ROWS): array {
		$out=[]; $delim=detect_delimiter($file);
		$fh=fopen($file,'r'); if(!$fh) return [[],[]];
		// strip UTF-8 BOM if present
		$first = fgets($fh);
		if ($first===false) { fclose($fh); return [[],[]]; }
		$first = preg_replace("/^\xEF\xBB\xBF/", '', $first);
		$headers = str_getcsv($first, $delim);
		$headers = array_map(fn($v)=>trim((string)$v), $headers);
		$rows=[]; $n=0;
		while(($row = fgetcsv($fh, 0, $delim)) !== false){
			$rows[] = array_map(fn($v)=>trim((string)$v), $row);
			if(++$n >= $maxRows) break;
		}
		fclose($fh);
		return [$headers,$rows];
	}
	function map_row(array $headers, array $row, array $map): array {
		// $map: dbField => headerIndex (or -1 for const/ignore)
		$val = fn($idx)=> ($idx>=0 && $idx<count($row)) ? trim((string)$row[$idx]) : '';
		return [
		'token'      => $val($map['token'] ?? -1),
		'first_name' => $val($map['first_name'] ?? -1),
		'last_name'  => $val($map['last_name'] ?? -1),
		'email'      => $val($map['email'] ?? -1),
		'phone'      => $val($map['phone'] ?? -1),
		'school'     => $val($map['school'] ?? -1),
		'status'     => ($s=strtolower($val($map['status'] ?? -1)))? (($s==='yes'||$s==='ja')?'yes' : (($s==='no'||$s==='nein')?'no':null)) : null,
		'paid'       => ($p=strtolower($val($map['paid'] ?? -1)))? ((in_array($p,['1','true','ja','yes','x','paid'],true))?1:0) : null,
		];
	}
	
	// ---------- Automatisches Mapping aufgrund von Spaltennamen ----------
	function normalize_header_name(string $s): string {
		$s = strtolower(trim($s));
		$s = str_replace(['ä','ö','ü','ß'], ['ae','oe','ue','ss'], $s);
		$s = str_replace([' ',"	",'-','_','.'], '', $s);
		return $s; // z.B. "E-Mail-Adresse" -> "emailadresse"
	}
	function auto_map_headers(array $headers): array {
		$norm = array_map('normalize_header_name', $headers);
		$idxOf = function(array $candidates) use ($norm): int {
			foreach ($candidates as $cand) {
				$cand = normalize_header_name($cand);
				foreach ($norm as $i=>$h) { if ($h === $cand) return (int)$i; }
			}
			return -1;
		};
		return [
		'token'      => $idxOf(['token','hash','id','schluessel','key']),
		'first_name' => $idxOf(['vorname','firstname','first_name','givenname','given_name']),
		'last_name'  => $idxOf(['nachname','lastname','last_name','surname','familienname']),
		'email'      => $idxOf(['email','e-mail','mail','emailadresse','e-mail-adresse']),
		'phone'      => $idxOf(['telefon','phone','telefonnummer','handy','mobile','mobil','tel']),
		'school'     => $idxOf(['schule','school']),
		'status'     => $idxOf(['status','teilnahme','kommt','anmeldung']),
		'paid'       => $idxOf(['bezahlt','paid','zahlung','beitrag_ok','bez','bezahltstatus']),
		];
	}
	
	function db_token_exists(string $token): bool {
		$st=db()->prepare("SELECT 1 FROM attendees WHERE token=:t LIMIT 1");
		$st->execute([':t'=>$token]); return (bool)$st->fetchColumn();
	}
	function db_find_by_name(string $fn,string $ln): ?string {
		$st=db()->prepare("SELECT token FROM attendees WHERE lower(first_name)=lower(:fn) AND lower(last_name)=lower(:ln) LIMIT 1");
		$st->execute([':fn'=>$fn, ':ln'=>$ln]); $t=$st->fetchColumn(); return $t?:null;
	}
	function upsert_attendee(array $data): string {
		if (empty($data['token'])) { $data['token'] = bin2hex(random_bytes(16)); }
		$sql = "
		INSERT INTO attendees (token, first_name, last_name, email, phone, school, status, paid, updated_at)
		VALUES (:token,:fn,:ln,:em,:ph,:sc,:st,COALESCE(:pd,0),datetime('now'))
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
		$st = db()->prepare($sql);
		$st->execute([
		':token'=>$data['token'], ':fn'=>$data['first_name']??'', ':ln'=>$data['last_name']??'', ':em'=>$data['email']??'', ':ph'=>$data['phone']??'', ':sc'=>$data['school']??'', ':st'=>in_array($data['status']??null,['yes','no'],true)?$data['status']:null, ':pd'=>isset($data['paid'])?(int)!!$data['paid']:null,
		]);
		return $data['token'];
	}
	
	//-------------------------------------------------
	// Routing/Steps
	//-------------------------------------------------
	$step = $_GET['step'] ?? 'upload';
	$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
	
	// Upload endpoint for Dropzone
	if (isset($_GET['upload']) && $method==='POST') {
		if (!isset($_POST['csrf']) || !hash_equals($CSRF, (string)$_POST['csrf'])) {
			http_response_code(400); echo json_encode(['ok'=>false,'error'=>'CSRF']); exit;
		}
		if (!isset($_FILES['file']) || $_FILES['file']['error']!==UPLOAD_ERR_OK) {
			http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Upload fehlgeschlagen']); exit;
		}
		$tmp = $_FILES['file']['tmp_name'];
		$name = $_FILES['file']['name'];
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if ($ext!=='csv') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Nur .csv erlaubt']); exit; }
		$dir = UPLOAD_DIR ?: sys_get_temp_dir();
		$dest = $dir.'/import_'.session_id().'_'.time().'.csv';
		if (!move_uploaded_file($tmp, $dest)) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Konnte Datei nicht verschieben']); exit; }
		// Testlesung
		[$headers,$rows] = read_csv_preview($dest, 3);
		if (!$headers) { @unlink($dest); http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Leere/ungültige CSV']); exit; }
		$_SESSION['import_file']=$dest;
		$_SESSION['import_name']=$name;
		header('Content-Type: application/json');
		echo json_encode(['ok'=>true,'redirect'=>basename(__FILE__).'?step=map&key='.urlencode($provided_key)]);
		exit;
	}
	
	// MAP submit => build preview plan
	if ($step==='map_submit' && $method==='POST') {
		if (!isset($_POST['csrf']) || !hash_equals($CSRF, (string)$_POST['csrf'])) { flash('Sicherheitsfehler (CSRF).','danger'); header('Location: '.basename(__FILE__).'?step=map&key='.urlencode($provided_key)); exit; }
		$file = $_SESSION['import_file'] ?? '';
		if (!$file || !is_readable($file)) { flash('Keine Upload-Datei gefunden.','danger'); header('Location: '.basename(__FILE__).'?step=upload&key='.urlencode($provided_key)); exit; }
		[$headers,$rows] = read_csv_preview($file, MAX_PREVIEW_ROWS);
		$map = [
		'token'      => (int)($_POST['map_token'] ?? -1),
		'first_name' => (int)($_POST['map_first_name'] ?? -1),
		'last_name'  => (int)($_POST['map_last_name'] ?? -1),
		'email'      => (int)($_POST['map_email'] ?? -1),
		'phone'      => (int)($_POST['map_phone'] ?? -1),
		'school'     => (int)($_POST['map_school'] ?? -1),
		'status'     => (int)($_POST['map_status'] ?? -1),
		'paid'       => (int)($_POST['map_paid'] ?? -1),
		];
		$_SESSION['import_map']=$map;
		
		// Build plan rows
		$plan=[]; $name_matches=0; $token_updates=0; $inserts=0;
		foreach($rows as $r){
			$m = map_row($headers, $r, $map);
			if ($m['token']!=='') {
				if (db_token_exists($m['token'])) { $action='update_token'; $token_updates++; }
				else { $action='insert'; $inserts++; }
				$matched_token = $m['token'];
				} else {
				$match = ($m['first_name']!=='' && $m['last_name']!=='') ? db_find_by_name($m['first_name'],$m['last_name']) : null;
				if ($match){ $action='match_name'; $name_matches++; $matched_token=$match; }
				else { $action='insert'; $inserts++; $matched_token=null; }
			}
			$plan[] = [ 'data'=>$m, 'action'=>$action, 'matched_token'=>$matched_token ];
		}
		$_SESSION['import_plan']=$plan;
		$_SESSION['import_headers']=$headers;
		flash("Vorschau erstellt: $token_updates Token-Updates, $name_matches Namens-Treffer, $inserts Einfügungen (vor Auswahl).", 'info');
		header('Location: '.basename(__FILE__).'?step=review&key='.urlencode($provided_key));
		exit;
	}
	
	// COMMIT
	if ($step==='commit' && $method==='POST') {
		if (!isset($_POST['csrf']) || !hash_equals($CSRF, (string)$_POST['csrf'])) { flash('Sicherheitsfehler (CSRF).','danger'); header('Location: '.basename(__FILE__).'?step=review&key='.urlencode($provided_key)); exit; }
		$plan = $_SESSION['import_plan'] ?? [];
		if (!$plan){ flash('Kein Import-Plan vorhanden.','danger'); header('Location: '.basename(__FILE__).'?step=upload&key='.urlencode($provided_key)); exit; }
		$do = $_POST['do'] ?? [];
		$updated=0; $inserted=0; $skipped=0; $errors=0;
		foreach($plan as $idx=>$row){
			$choice = $do[$idx] ?? $row['action'];
			$data = $row['data'];
			try{
				if ($row['action']==='update_token') { // immer update, ungeachtet Auswahl
					$data['token']=$row['matched_token']; upsert_attendee($data); $updated++;
				} elseif ($choice==='skip') { $skipped++; continue; }
				elseif ($choice==='update_name' && $row['matched_token']) { $data['token']=$row['matched_token']; upsert_attendee($data); $updated++; }
				elseif ($choice==='insert' || $choice==='insert_new') { unset($data['token']); upsert_attendee($data); $inserted++; }
				else { // fallback
					if ($row['matched_token']) { $data['token']=$row['matched_token']; upsert_attendee($data); $updated++; }
					else { unset($data['token']); upsert_attendee($data); $inserted++; }
				}
			} catch (Throwable $e) { $errors++; }
		}
		flash("Fertig: $updated aktualisiert, $inserted angelegt, $skipped übersprungen, $errors Fehler.");
		// Aufräumen
		unset($_SESSION['import_plan'], $_SESSION['import_map'], $_SESSION['import_headers']);
		header('Location: '.basename(__FILE__).'?step=done&key='.urlencode($provided_key));
		exit;
	}
	
	//-------------------------------------------------
	// UI
	//-------------------------------------------------
	$flash = get_flash();
?><!doctype html>
<html lang="de">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
 		<meta name="robots" content="noindex, nofollow">
		
		<title><?=h($APP_TITLE)?> · Import</title>
		<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/dropzone@6.0.0-beta.2/dist/dropzone.css">
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" >
		<style>
			body { background:#f6f7fb; }
			.card { max-width: 1360px; margin: 1rem auto; }
			.dz { border: 2px dashed #bbb; border-radius: .75rem; background:#fff; padding: 2rem; }
			.table-fixed { table-layout: fixed; }
			.small-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85rem; }
			.sticky-actions { position: sticky; bottom: 0; background: #fff; border-top: 1px solid #e5e7eb; }
		</style>
	</head>
	<body>
		<div class="container">
			<div class="card shadow-sm">
				<div class="card-body p-4">
					<div class="col-12 text-center">
						<img src="EKG.png" style="height: 100px;">&nbsp;&nbsp;&nbsp;&nbsp;<img src="MWG.png" style="height: 100px; ">
					</div>
					<h1 class="h3 mb-1"><?=h($APP_TITLE)?></h1>
					
						
						<div class="d-flex gap-2 float-end">
							<a class="btn btn-secondary " href="rsvp.php"><i class="bi bi-folder2-open"></i> Zur Formularansicht</a>
							<a class="btn btn-primary" href="editor.php<?= $provided_key!==''? '?key='.urlencode($provided_key):'' ?>"><i class="bi bi-pencil-square"></i> Zum Editor</a>
						</div>
					
					<?php if (IMPORT_KEY===''): ?>
					<div class="alert alert-warning">Hinweis: <code>IMPORT_KEY</code> ist leer – diese Seite ist ungeschützt. Bitte in <code>importer.php</code> setzen und per <code>?key=…</code> aufrufen.</div>
					<?php endif; ?>
					<?php if ($flash): ?>
					<div class="alert alert-<?=h($flash['t'] ?? 'info')?>" role="alert"><?=h($flash['m'])?></div>
					<?php endif; ?>
					
					<?php if ($step==='upload'): ?>
					<p class="text-muted">CSV hochladen. Erste Zeile sollte Spaltennamen enthalten. Trennzeichen werden automatisch erkannt (Komma, Semikolon, Tab, Pipe).</p>
					<form action="<?=h(basename(__FILE__))?>?upload=1" class="dropzone dz" id="csvDrop" enctype="multipart/form-data">
						<input type="hidden" name="csrf" value="<?=h($CSRF)?>">
						<input type="hidden" name="key" value="<?=h($provided_key)?>">
						<div class="dz-message">Datei hier ablegen oder klicken, um auszuwählen (nur .csv)</div>
					</form>
					<div class="text-muted small mt-2">Tipp: UTF‑8 ohne BOM ist ideal. Für Excel-Exports funktioniert meist auch Semikolon.</div>
					<?php elseif ($step==='map'): ?>
					<?php [$headers,$rows] = read_csv_preview($_SESSION['import_file'] ?? '', 10); $auto = auto_map_headers($headers); ?>
					<h2 class="h6">Spalten-Mapping <span class="text-muted small">(Datei: <?=h($_SESSION['import_name'] ?? basename($_SESSION['import_file'] ?? ''))?>)</span></h2>
					<?php if (!$headers): ?>
					<div class="alert alert-danger">Keine gültige CSV erkannt. <a href="?step=upload&key=<?=h($provided_key)?>">Zurück zum Upload</a>.</div>
					<?php else: ?>
					<div class="text-muted small mb-2">Tipp: Wir haben die Auswahl anhand der Spaltennamen vorbefüllt. Bitte kurz prüfen und ggf. anpassen.</div>
					<form method="post" action="?step=map_submit">
						<input type="hidden" name="csrf" value="<?=h($CSRF)?>">
						<input type="hidden" name="key" value="<?=h($provided_key)?>">
						<div class="table-responsive">
							<table class="table table-sm table-fixed align-middle">
								<thead>
									<tr>
										<th>DB-Feld</th>
										<th>CSV-Spalte</th>
										<th>Beispiel (1. Zeilen)</th>
									</tr>
								</thead>
								<tbody>
									<?php
										$dbFields = [
										'token'=>'Token (optional, falls vorhanden)',
										'first_name'=>'Vorname*',
										'last_name'=>'Nachname*',
										'email'=>'E-Mail',
										'phone'=>'Telefon',
										'school'=>'Schule (EKG/MWG)',
										'status'=>'Status (yes/no/ja/nein)',
										'paid'=>'Bezahlt (1/0, ja/nein)',
										];
										foreach($dbFields as $f=>$label):
										$name = 'map_'.$f;
									?>
									<tr>
										<td><strong><?=h($label)?></strong></td>
										<td>
											<select class="form-select form-select-sm" name="<?=h($name)?>">
												<?php $guess = (int)($auto[$f] ?? -1); ?>
												<option value="-1" <?= $guess<0? 'selected':'' ?>>— Ignorieren —</option>
												<?php foreach($headers as $idx=>$hcol): ?>
												<option value="<?= (int)$idx ?>" <?= ($guess=== (int)$idx)? 'selected':'' ?>><?=h($hcol)?> (Spalte <?= (int)$idx+1 ?>)</option>
												<?php endforeach; ?>
											</select>
										</td>
										<td class="small-mono">
											<?php $g = (int)($auto[$f] ?? -1); for($i=0;$i<min(3,count($rows));$i++): $val = ($g>=0 && isset($rows[$i][$g])) ? $rows[$i][$g] : ''; ?>
											<div class="text-truncate" style="max-width:520px;">Row <?= $i+1 ?>: <?=h($val)?></div>
											<?php endfor; ?>
										</td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<div class="text-muted small mb-2">Felder mit * sind sinnvoll für Duplikatprüfung. Wenn Token vorhanden ist, hat er Vorrang.</div>
						<div class="d-flex gap-2">
							<a class="btn btn-outline-secondary" href="?step=upload&key=<?=h($provided_key)?>"><i class="bi bi-arrow-counterclockwise"></i> Zurück</a>
							<button class="btn btn-primary"><i class="bi bi-window-fullscreen"></i> Weiter zur Vorschau</button>
						</div>
					</form>
					<?php endif; ?>
					<?php elseif ($step==='review'): ?>
					<?php $plan = $_SESSION['import_plan'] ?? []; ?>
					<h2 class="h6">Vorschau &amp; Entscheidungen</h2>
					<?php if (!$plan): ?>
					<div class="alert alert-danger">Kein Plan vorhanden. <a href="?step=upload&key=<?=h($provided_key)?>"><i class="bi bi-arrow-clockwise"></i> Neu starten</a>.</div>
					<?php else: ?>
					<form method="post" action="?step=commit">
						<input type="hidden" name="csrf" value="<?=h($CSRF)?>">
						<input type="hidden" name="key" value="<?=h($provided_key)?>">
						<div class="table-responsive">
							<table class="table table-sm align-middle">
								<thead>
									<tr>
										<th>#</th>
										<th>Name</th>
										<th>E-Mail</th>
										<th>Telefon</th>
										<th>Schule</th>
										<th>Status</th>
										<th>Bez.</th>
										<th>Aktion</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach($plan as $i=>$it): $d=$it['data']; $act=$it['action']; ?>
									<tr>
										<td class="text-muted small"><?= $i+1 ?></td>
										<td><?=h(trim(($d['first_name']??'').' '.($d['last_name']??'')))?></td>
										<td><?=h($d['email']??'')?></td>
										<td><?=h($d['phone']??'')?></td>
										<td><?=h($d['school']??'')?></td>
										<td><?=h($d['status']??'')?></td>
										<td><?= ($d['paid']??0)? '✔️':'—' ?></td>
										<td>
											<?php if ($act==='update_token'): ?>
											<span class="badge text-bg-primary">Update (Token)</span>
											<?php elseif ($act==='match_name'): ?>
											<div class="d-flex gap-2">
												<label class="form-check-label"><input class="form-check-input" type="radio" name="do[<?= (int)$i ?>]" value="update_name" checked> Bestehenden (Name) aktualisieren</label>
												<label class="form-check-label"><input class="form-check-input" type="radio" name="do[<?= (int)$i ?>]" value="insert_new"> Als neu einfügen</label>
												<label class="form-check-label"><input class="form-check-input" type="radio" name="do[<?= (int)$i ?>]" value="skip"> Überspringen</label>
											</div>
											<?php else: ?>
											<div class="d-flex gap-2">
												<label class="form-check-label"><input class="form-check-input" type="radio" name="do[<?= (int)$i ?>]" value="insert" checked> Einfügen</label>
												<label class="form-check-label"><input class="form-check-input" type="radio" name="do[<?= (int)$i ?>]" value="skip"> Überspringen</label>
											</div>
											<?php endif; ?>
										</td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<div class="sticky-actions p-3 d-flex justify-content-between align-items-center">
							<div class="text-muted small">Hinweis: Wenn ein <strong>Token</strong> vorhanden ist, wird der Datensatz <strong>immer aktualisiert</strong> (unabhängig von der Auswahl).</div>
							<div class="d-flex gap-2">
								<a class="btn btn-secondary" href="?step=map&key=<?=h($provided_key)?>"><i class="bi bi-arrow-counterclockwise"></i> Zurück</a>
								<button class="btn btn-success"><i class="bi bi-floppy"></i> Import ausführen</button>
							</div>
						</div>
					</form>
					<?php endif; ?>
					<?php elseif ($step==='done'): ?>
					<div class="alert alert-success">Import abgeschlossen.</div>
					<a class="btn btn-primary" href="editor.php<?= IMPORT_KEY? '?key='.urlencode($provided_key):'' ?>"><i class="bi bi-pencil-square"></i> Zum Editor</a>
					<a class="btn btn-secondary" href="?step=upload&key=<?=h($provided_key)?>"><i class="bi bi-cloud-plus"></i> Neuen Import starten</a>
					<?php endif; ?>
					
				</div>
			</div>
		</div>
		
		<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
		<script src="https://cdn.jsdelivr.net/npm/dropzone@6.0.0-beta.2/dist/dropzone-min.js"></script>
		<script>
			// Dropzone Setup
			(function(){
				const form = document.getElementById('csvDrop');
				if (!form) return;
				Dropzone.autoDiscover = false;
				const dz = new Dropzone(form, {
					url: form.getAttribute('action'),
					method: 'post',
					maxFiles: 1,
					acceptedFiles: '.csv',
					dictDefaultMessage: 'Datei hier ablegen oder klicken (nur .csv)',
					init(){ this.on('success', (file, resp)=>{ try{ if(resp && resp.redirect){ window.location.href = resp.redirect; } }catch(e){} }); },
				});
			})();
		</script>
	</body>
</html>
