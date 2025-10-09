<?php
$hostname = '{imap.gmail.com:993/imap/ssl}INBOX';
$username = '';
$appPassword = ''; // 16 Zeichen App-Passwort

$inbox = imap_open($hostname, $username, $appPassword);
if (!$inbox) {
    die('IMAP-Fehler: ' . imap_last_error());
}

$emails = imap_search($inbox, 'UNSEEN'); // nur ungelesene
if (!$emails) {
    echo "Keine neuen E-Mails.\n";
    imap_close($inbox);
    exit;
}

rsort($emails); // neueste zuerst

$ret = "<div class=\"table-responsive\"><table class=\"table table-sm align-middle\"><thead><tr><th>Datum</th><th>von</th><th>Betreff</th></tr></thead><tbody>";
foreach ($emails as $num) {
    $overview = imap_fetch_overview($inbox, $num, 0)[0];
    $from    = $overview->from    ?? '(kein From)';
    $date    = $overview->date    ?? '(kein Date)';
    $subject = $overview->subject ?? '(kein Betreff)';
	
	
	
    //echo "$date | $from | $subject\n";
	
	$tstamp = strtotime($date);
	$ret .= "<tr>
	<td>
	" . date("d.m.Y, H:i", $tstamp) . " Uhr
	</td>
	<td>
	" . $from . "
	</td>
	<td>
	" . $subject . "
	</td>
	
	</tr>";
}
$ret .= "</tbody></table></div>";
imap_close($inbox);

echo $ret;
?>

