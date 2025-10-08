<?php
	// editor.php — Einfacher Datensatz-Editor für rsvp.sqlite (separate Admin-Datei)
	// Zweck: Datensätze ansehen, suchen, anlegen, ändern, löschen
	// Schutz: Zugang per ?key=... (EDITOR_KEY unten setzen) — optional zusätzlich HTTP-Auth/.htaccess empfehlen
	
	// -----------------------------------------------
	// Konfiguration
	// -----------------------------------------------
	$DB_PATH    = __DIR__ . '/rsvp.sqlite'; // Pfad zur bestehenden DB aus rsvp.php
	$APP_TITLE  = 'Teilnehmer-Editor';
	const EDITOR_KEY = '';                 // z. B. 'gJ2k7wTn...'; Zugriff via editor.php?key=DEIN_KEY
	const PAGE_SIZE  = 50;                 // Einträge pro Seite
	
	// -----------------------------------------------
	// DB / Helpers
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
		// Nur falls Datei fehlt – gleiche Struktur wie in rsvp.php
		$sql = "
		CREATE TABLE IF NOT EXISTS attendees (
		token TEXT PRIMARY KEY,
		first_name TEXT,
		last_name TEXT,
		email TEXT,
		phone TEXT,
		school TEXT,
		status TEXT,
		paid INTEGER DEFAULT 0,
		updated_at TEXT
		);
		";
		db()->exec($sql);
	}
	function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
	function random_token(int $bytes=16): string { return bin2hex(random_bytes($bytes)); }
	
	// -----------------------------------------------
	// Session / Flash / CSRF
	// -----------------------------------------------
	session_start();
	function flash($m,$t='success'){ $_SESSION['flash']=['m'=>$m,'t'=>$t]; }
	function get_flash(){ if(isset($_SESSION['flash'])){$f=$_SESSION['flash']; unset($_SESSION['flash']); return $f;} return null; }
	if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
	$CSRF = $_SESSION['csrf'];
	
	// -----------------------------------------------
	// Access Guard
	// -----------------------------------------------
	$provided_key = $_GET['key'] ?? '';
	if (EDITOR_KEY !== '' && (!is_string($provided_key) || !hash_equals(EDITOR_KEY, $provided_key))) {
		http_response_code(403);
		echo '<!doctype html><meta charset="utf-8"><div style="font:16px system-ui;padding:2rem">Zugriff verweigert. Key fehlt/ungültig.</div>';
		exit;
	}
	
	// -----------------------------------------------
	// Init
	// -----------------------------------------------
	init_db();
	
	// -----------------------------------------------
	// Actions (POST)
	// -----------------------------------------------
	$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
	if ($method === 'POST') {
		if (!isset($_POST['csrf']) || !hash_equals($CSRF, (string)$_POST['csrf'])) {
			flash('Sicherheitsfehler (CSRF). Bitte erneut versuchen.', 'danger');
			header('Location: ' . $_SERVER['REQUEST_URI']);
			exit;
		}
		$action = $_POST['action'] ?? '';
		try {
			if ($action === 'create') {
				$data = [
				'token'      => trim($_POST['token'] ?? ''),
				'first_name' => trim($_POST['first_name'] ?? ''),
				'last_name'  => trim($_POST['last_name'] ?? ''),
				'email'      => trim($_POST['email'] ?? ''),
				'phone'      => trim($_POST['phone'] ?? ''),
				'school'     => trim($_POST['school'] ?? ''),
				'status'     => ($_POST['status'] ?? '') ?: null,
				'paid'       => isset($_POST['paid']) ? 1 : 0,
				];
				if ($data['token'] === '') { $data['token'] = random_token(); }
				$stmt = db()->prepare("INSERT INTO attendees (token, first_name, last_name, email, phone, school, status, paid, updated_at) VALUES (:t,:fn,:ln,:em,:ph, :sc,:st,:pd,datetime('now'))");
				$stmt->execute([':t'=>$data['token'], ':fn'=>$data['first_name'], ':ln'=>$data['last_name'], ':em'=>$data['email'], ':ph'=>$data['phone'], ':sc'=>$data['school'], ':st'=>$data['status'], ':pd'=>$data['paid']]);
				flash('Eintrag angelegt (Token: '.substr($data['token'],0,8).' …).');
				} elseif ($action === 'save') {
				$token = trim($_POST['token'] ?? '');
				if ($token === '') throw new RuntimeException('Token fehlt.');
				$data = [
				'first_name' => trim($_POST['first_name'] ?? ''),
				'last_name'  => trim($_POST['last_name'] ?? ''),
				'email'      => trim($_POST['email'] ?? ''),
				'phone'      => trim($_POST['phone'] ?? ''),
				'school'      => trim($_POST['school'] ?? ''),
				'status'     => ($_POST['status'] ?? '') ?: null,
				'paid'       => isset($_POST['paid']) ? 1 : 0,
				];
				$stmt = db()->prepare("UPDATE attendees SET first_name=:fn, last_name=:ln, email=:em, phone=:ph, school=:sc, status=:st, paid=:pd, updated_at=datetime('now') WHERE token=:t");
				$stmt->execute([':fn'=>$data['first_name'], ':ln'=>$data['last_name'], ':em'=>$data['email'], ':ph'=>$data['phone'], ':sc'=>$data['school'], ':st'=>$data['status'], ':pd'=>$data['paid'], ':t'=>$token]);
				flash('Eintrag gespeichert.');
				} elseif ($action === 'delete') {
				$token = trim($_POST['token'] ?? '');
				if ($token === '') throw new RuntimeException('Token fehlt.');
				$stmt = db()->prepare("DELETE FROM attendees WHERE token=:t");
				$stmt->execute([':t'=>$token]);
				flash('Eintrag gelöscht.','warning');
			}
			} catch (Throwable $e) {
			flash('Fehler: '.$e->getMessage(),'danger');
		}
		// PRG
		$redir = strtok($_SERVER['REQUEST_URI'], '#');
		header('Location: ' . $redir);
		exit;
	}
	
	// -----------------------------------------------
	// Read (GET) — Suche & Pagination
	// -----------------------------------------------
	$q = trim($_GET['q'] ?? '');
	$page = max(1, (int)($_GET['page'] ?? 1));
	$limit = PAGE_SIZE; $offset = ($page-1)*$limit;
	
	$where = []; $params=[];
	if ($q !== '') {
		$where[] = '(first_name LIKE :q OR last_name LIKE :q OR email LIKE :q OR phone LIKE :q)';
		$params[':q'] = '%'.$q.'%';
	}
	
	$total = 0;
	if ($where) {
		$stmt = db()->prepare('SELECT COUNT(*) FROM attendees WHERE '.implode(' AND ', $where));
		$stmt->execute($params);
		$total = (int)$stmt->fetchColumn();
		} else {
		$total = (int)db()->query('SELECT COUNT(*) FROM attendees')->fetchColumn();
	}
	
	// CSV-Export (optional: respektiert aktuelle Suche)
	if (isset($_GET['export']) && $_GET['export']==='csv') {
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="rsvp_export_'.date('Y-m-d_His').'.csv"');
		$out = fopen('php://output', 'w');
		fputcsv($out, ['token','vorname','nachname','email','telefon','schule','status','bezahlt','aktualisiert']);
		$sqlExp = 'SELECT token, first_name, last_name, email, phone, school, status, paid, updated_at FROM attendees';
		if ($where) $sqlExp .= ' WHERE ' . implode(' AND ', $where);
		$sqlExp .= ' ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE';
		$st = db()->prepare($sqlExp);
		foreach ($params as $k=>$v) { $st->bindValue($k,$v); }
		$st->execute();
		while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
			fputcsv($out, [
			$row['token'], $row['first_name'], $row['last_name'], $row['email'], $row['phone'],
			$row['school'], $row['status'], ((int)$row['paid'])? '1':'0', $row['updated_at']
			]);
		}
		fclose($out);
		exit;
	}
	
	$sql = 'SELECT * FROM attendees';
	if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
	$sql .= ' ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE LIMIT :lim OFFSET :off';
	$stmt = db()->prepare($sql);
	foreach ($params as $k=>$v) { $stmt->bindValue($k,$v); }
	$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
	$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
	$stmt->execute();
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	$pages = max(1, (int)ceil($total / max(1,$limit)));
	
	// Für Links zur öffentlichen rsvp-Seite
	$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['PHP_SELF']), '/');
	$rsvp_base = $base . '/rsvp.php?t=';
	$flash = get_flash();
	
	$addToDelete = "disabled";
	if(isset($_GET['superadmin'])){
		$addToDelete = "";
	}
?>
<!doctype html>
<html lang="de">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="robots" content="noindex, nofollow">
		
		<title><?=h($APP_TITLE)?> · Editor</title>
		<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" >
		<style>
			body { background:#f6f7fb; }
			.container-narrow { max-width: 1360px; margin: 1rem auto;}
			.sticky-col-actions { position: sticky; right: 0; background: #fff; }
			.table td, .table th { vertical-align: middle; }
			code.small { font-size: .8rem; }
			
			.btn-group-sm>.btn, .btn-xs {
			--bs-btn-padding-y: 0.2rem;
			--bs-btn-padding-x: 0.4rem;
			--bs-btn-font-size: 0.75rem;
			--bs-btn-border-radius: var(--bs-border-radius-sm);
			}
			
		</style>
	</head>
	<body>
		<div class="container container-narrow py-4">
			<div class="card shadow-sm mb-4">
				<div class="card-body">
					<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
						<div class="col-12 text-center">
							<img src="EKG.png" style="height: 100px;">&nbsp;&nbsp;&nbsp;&nbsp;<img src="MWG.png" style="height: 100px; ">
							
							<br>
						</div>
						<h1 class="h4 mb-0"><?=h($APP_TITLE)?> <span class="text-muted">(<?= (int)$total ?> Einträge) </span></h1>
						<div class="d-flex gap-2">
							<a class="btn btn-secondary" href="rsvp.php"><i class="bi bi-folder2-open"></i> Zur Formularansicht</a>
							<a class="btn btn-primary" href="?export=csv<?= $q!==''? '&amp;q='.urlencode($q):'' ?><?= $provided_key!==''? '&amp;key='.urlencode($provided_key):'' ?>"><i class="bi bi-filetype-csv"></i> CSV exportieren</a>
							<a class="btn btn-warning" href="importer.php<?= $provided_key!==''? '?key='.urlencode($provided_key):'' ?>"><i class="bi bi-filetype-csv"></i> CSV importieren</a>
						</div>
					</div>
					
					<?php if (EDITOR_KEY===''): ?>
					<div class="alert alert-warning">Hinweis: EDITOR_KEY ist leer – diese Seite ist ungeschützt. Bitte in der Datei setzen und per <code>?key=...</code> verwenden.</div>
					<?php endif; ?>
					
					<?php if ($flash): ?>
					<div class="alert alert-<?=h($flash['t'])?>"><?=(string)$flash['m']?></div>
					<?php endif; ?>
					
					<form class="row g-2 mb-3" method="get">
						<input type="hidden" name="key" value="<?=h($provided_key)?>">
						<div class="col-sm-8 col-md-6 col-lg-5">
							<input type="search" name="q" class="form-control" value="<?=h($q)?>" placeholder="Suche: Name, E‑Mail, Telefon">
						</div>
						<div class="col-auto">
							<button class="btn btn-primary"><i class="bi bi-search"></i> Suchen</button>
							<a class="btn btn-outline-secondary" href="?key=<?=h($provided_key)?>"><i class="bi bi-arrow-counterclockwise"></i> Zurücksetzen</a>
						</div>
					</form>
				</div>
			</div>
			<div class="card shadow-sm mb-4">
				<div class="card-body">
					<h2 class="h6">Neuen Teilnehmer-Eintrag anlegen</h2>
					<form method="post" class="row g-2">
						<input type="hidden" name="csrf" value="<?=h($CSRF)?>">
						<input type="hidden" name="action" value="create">
						<div class="col-md-2 col-lg-2">
							<input type="text" class="form-control" name="first_name" placeholder="Vorname">
						</div>
						<div class="col-md-2 col-lg-2">
							<input type="text" class="form-control" name="last_name" placeholder="Nachname">
						</div>
						<div class="col-md-2 col-lg-2">
							<input type="email" class="form-control" name="email" placeholder="E‑Mail">
						</div>
						<div class="col-md-2 col-lg-2">
							<input type="text" class="form-control" name="phone" placeholder="Telefon">
						</div>
						
						<div class="col-md-1 col-lg-1">
							<select class="form-select" name="school">
								<option value="EKG" >EKG</option>
								<option value="MWG" selected>MWG</option>
							</select>
						</div>
						<div class="col-md-1 col-lg-1">
							<select class="form-select" name="status">
								<option value="">Offen</option>
								<option value="yes">Zusage</option>
								<option value="no">Absage</option>
							</select>
						</div>
						<div class="col-md-1 col-lg-1 d-flex align-items-center">
							<div class="form-check">
								<input class="form-check-input" type="checkbox" name="paid" id="new_paid">
								<label class="form-check-label" for="new_paid">Bezahlt?</label>
							</div>
						</div>
						
						<div class="col-md-12 col-lg-12">
							<button class="btn btn-success"><i class="bi bi-floppy"></i> Neuen Teilnehmer speichern</button>
						</div>
					</form>
				</div>
			</div>
			
			<div class="card shadow-sm">
				<div class="card-body">
					<div class="table-responsive">
						<table class="table table-sm align-middle">
							<thead>
								<tr>
									<th>Vorname</th>
									<th>Nachname</th>
									<th>E‑Mail</th>
									<th>Telefon</th>
									<th>Status</th>
									<th>Bez.?</th>
									<!--<th>Token</th>-->
									<th>Schule</th>
									<th>Aktualisiert</th>
									<th class="text-end" style="width: 150px;">Aktion<br><code class="small">Löschen nur Ralf</code></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($rows as $r): ?>
								<!--<tr>
									<td colspan="9" style="border-bottom: 0px;"><code class="small"><?=h($r['token'])?></code></td>
								</tr>-->
								<tr>
									<form method="post">
										<input type="hidden" name="csrf" value="<?=h($CSRF)?>">
										<input type="hidden" name="action" value="save">
										<input type="hidden" name="token" value="<?=h($r['token'])?>">
										<td><input type="text" class="form-control form-control-sm" name="first_name" value="<?=h($r['first_name'])?>"></td>
										<td><input type="text" class="form-control form-control-sm" name="last_name" value="<?=h($r['last_name'])?>"></td>
										<td><input type="email" class="form-control form-control-sm" name="email" value="<?=h($r['email'])?>"></td>
										<td><input type="text" class="form-control form-control-sm" name="phone" value="<?=h($r['phone'])?>"></td>
										<td>
											<select class="form-select form-select-sm" name="status">
												<option value="" <?= $r['status']===null? 'selected':'' ?>>Offen</option>
												<option value="yes" <?= $r['status']==='yes'? 'selected':'' ?>>Zusage</option>
												<option value="no"  <?= $r['status']==='no'?  'selected':'' ?>>Absage</option>
											</select>
										</td>
										<td class="text-center">
											<input class="form-check-input" type="checkbox" name="paid" <?= ((int)$r['paid'])? 'checked':'' ?>>
										</td>
										
										<td>
											<select class="form-select form-select-sm" name="school">
												<option value="EKG" <?= $r['school']==='EKG'? 'selected':'' ?>>EKG</option>
												<option value="MWG"  <?= $r['school']==='MWG'?  'selected':'' ?>>MWG</option>
											</select>
										</td>
										<td><span class="text-muted small"><small><?=h($r['updated_at'])?></small></span></td>
										<td class="text-end">
											<button title="Diesen Eintrag speichern" class="btn btn-success btn-xs"><i class="bi bi-floppy"></i></button>
										</form>
										<form method="post" class="d-inline" onsubmit="return confirm('Diesen Eintrag wirklich löschen?');">
											<input type="hidden" name="csrf" value="<?=h($CSRF)?>">
											<input type="hidden" name="action" value="delete">
											<input type="hidden" name="token" value="<?=h($r['token'])?>">
											<a title="Link zum Formular öffnen" class="btn btn-secondary btn-xs" target="_blank" href="<?=h($rsvp_base . $r['token'])?>"><i class="bi bi-folder2-open"></i></a>
											<a title="E-Mail senden und/oder Notiz hinzufügen" class="btn btn-primary btn-xs" href="mailer.php?key=<?=EDITOR_KEY;?>&t=<?=h($r['token'])?>"><i class="bi bi-envelope-at"></i></a>
											<button title="Diesen Eintrag löschen (nur Superadmin)" class="btn btn-danger btn-xs" <?=h($addToDelete)?>><i class="bi bi-trash"></i></button>
										</form>
									</td>
								</tr>
								
								<?php endforeach; ?>
								<?php if (!$rows): ?>
								<tr><td colspan="10" class="text-center text-muted">Keine Einträge</td></tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
					
					<nav class="mt-3" aria-label="Seiten">
						<ul class="pagination mb-0">
							<?php
								$mk = function($p,$label=null) use($pages,$q,$provided_key){
									$p = max(1,min($p,$pages));
									$u = '?page='.$p . ($q!==''? '&q='.urlencode($q):'') . ($provided_key!==''? '&key='.urlencode($provided_key):'');
									return '<li class="page-item'.($p==($_GET['page']??1)?' active':'').'"><a class="page-link" href="'.$u.'">'.($label?:$p).'</a></li>';
								};
								if ($pages>1) {
									echo $mk(1,'«');
									echo $mk(max(1,$page-1),'‹');
									// einfache Fensterung
									$start=max(1,$page-2); $end=min($pages,$page+2);
									for($i=$start;$i<=$end;$i++){ echo $mk($i); }
									echo $mk(min($pages,$page+1),'›');
									echo $mk($pages,'»');
								}
							?>
						</ul>
					</nav>
					
				</div>
			</div>
		</div>
		
		<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
	</body>
</html>
