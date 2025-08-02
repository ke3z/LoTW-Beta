<?php

include_once '/var/lotw/phplib/vendor/autoload.php';
include_once __DIR__.'/vendor/autoload.php';

use \ARRL\LoTW\Web\Login;

class Pg {
	protected $must;
	public $view = 'main';
	public $bg = '';
	public $qsl;
	public $withSig = false;
	public $qsldata = array();
	public $warnings = array();
	public $eligible = array();
	public $imgw = 1600;
	public $imgh = 0;
	public $imgo = 800;
	public $callsize = 160;
	public $namesize = 28;
	public $fieldsize = 24;
	public $countrysize = 38;
	public $country = '';
	public $state = '';
	public $flds = array(
		'worked' => 68,
		'band' => 193,
		'mode' => 266,
		'date' => 348,
		'time' => 469,
		'frequency' => 566,
	);
	public $fonts = array(
		'sans' => 'LiberationSans-Regular',
		'sansb' => 'LiberationSans-Bold',
		'sans0' => 'LiberationSans0-Regular',
		'sans0b' => 'LiberationSans0-Bold',
	);
	public $locfields = array(
		'us_state' => array('', 'state'),
		'ca_province' => array('', 'state'),
		'cn_province' => array('', 'state'),
		'fi_kunta' => array('', 'state'),
		'au_state' => array('', 'state'),
		'ru_oblast' => array('', 'state'),
		'grid' => 'Grid: ',
		'cqz'=> 'CQ Zone: ',
		'ituz' => 'ITU Zone: ',
	);
	public $names = array(
		'John Q. Public',
		'Clarence D. Tuska',
		'Charles III, King of England',
	);

	public function __construct() {
		$this->must = new \Mustache_Engine(array(
			'loader' => new \Mustache_Loader_FilesystemLoader(__DIR__.'/views'),
			'partials_loader' => new \Mustache_Loader_FilesystemLoader(__DIR__.'/views/partials')
		));
	}

	public function imglink() {
		$u = $this->url;
		if ($this->withSig)
			$u .= '&data=sig';
		elseif ($this->withData)
			$u .= '&data=1';
		if ($this->bg)
			$u .= "&bg={$this->bg}";
		return $u;
	}

	public function bglink() {
		$u = $this->url;
		if ($this->bg)
			$u .= "&bg={$this->bg}";
		return $u;
	}

	public function render($view = "", $obj = false) {
		if (!$obj)
			$obj = $this;
		print $this->must->render($view ?: $this->view, $obj);
		exit;
	}

	public function error($msg) {
		$msg = array('err' => $msg);
		$this->render('upload', $msg);
	}

	public function setQSL($qsl) {
		if (isset($qsl->error))
			$this->error($qsl->error);
		$this->qsl = $qsl;
		$this->datalink = $this->url;
		if ($this->withSig)
			$this->datalink .= "&data=sig";
		elseif ($this->withData)
			$this->datalink .= "&data=1";
		$this->country = '';
		$arrl = isset($qsl->data->dxcc_entity) ? (int)$qsl->data->dxcc_entity : 0;
		if ($arrl) {
			$dx = new \ARRL\LoTW\DXCCEntities();
			$dx->ARRL = $arrl;
			if ($dx->getByARRL())
				$this->country = $dx->Name;
		}
		$this->qsldata = array();
		foreach ($qsl->data as $k => $v)
			$this->qsldata[] = array('label' => $k, 'value' => $v);
		$eqsl = new \ARRL\LoTW\eQSL;
		$credits = $eqsl->evaluateAwards($qsl);
		$this->warnings = $eqsl->warnings;
		$this->eligible = array();
		foreach ($credits as $credit) {
			$pretty = $credit->award->creditPretty($credit->credit);
			$this->eligible[] = array('label' => "{$credit->award->Program} {$credit->award->Name}", 'value' => $pretty);
		}
	}

	public function qrCode($margin = 2) {
		$qrfile = tempnam("/var/lotw/tmp", 'eqsl');
		$qr = '';
		$url = isset($this->qsl->url) ? $this->qsl->url : '';
		$qrdat = clone $this->qsl->data;
		if (!empty($qrdat) && $this->withData) {
			if ($this->withSig) {
				$qrdat->cert = $this->qsl->sig->certificate_url;
				$qrdat->rand = $this->qsl->sig->randval;
				$qrdat->sig = $this->qsl->sig->signature;
			}
			$url .= "\n".json_encode($qrdat);
		}
		$this->qrtext = $url;
		exec("/usr/bin/qrencode -m $margin -s 4 -o $qrfile ".escapeshellarg($url));
		$info = getimagesize($qrfile);
		$this->qrimg = imagecreatefrompng($qrfile);
		$this->qrWidth = $info[0];
		$this->qrHeight = $info[1];
		unlink($qrfile);
	}

	public function qslImage($raw = false) {
		$fpath = __DIR__.'/assets';
		$callFont = "$fpath/{$this->fonts['sans0b']}.ttf";
		$fieldFont = "$fpath/{$this->fonts['sans']}.ttf";
		$field0Font = "$fpath/{$this->fonts['sans0']}.ttf";
		$stateFont = "$fpath/{$this->fonts['sansb']}.ttf";
		$file = "QSLBlank{$this->bg}.png";
		if (!file_exists("$fpath/$file")) {
			$file = "QSLBlank1.png";
			$this->bg = '';
		}
		$img = imagecreatefrompng("$fpath/$file");
		// Call
		$box = imageftbbox($this->callsize, 0, $callFont, $this->qsl->data->call);
		$centerx = $this->imgw / 2;
		$callw = $box[2] - $box[0];
		$cally = (160 * $this->imgw) / $this->imgo;
		imagefttext($img, $this->callsize, 0, $centerx - $callw/2, $cally, 0, $callFont, $this->qsl->data->call);
		// Name
		$name = $this->names[(int)$this->bg - 1];
		$box = imageftbbox($this->namesize, 0, $fieldFont, $name);
//		$this->dbg = "$name [$this->bg]: ".print_r($this->names, true);
		$namew = $box[2] - $box[0];
		$namey = (200 * $this->imgw) / $this->imgo;
		imagefttext($img, $this->namesize, 0, $centerx - $namew/2, $namey, 0, $fieldFont, $name);
		// Country
		$countryY = (240 * $this->imgw) / $this->imgo;
		$box = imageftbbox($this->countrysize, 0, $callFont, $this->country);
		$countryw = $box[2] - $box[0];
		imagefttext($img, $this->countrysize, 0, $centerx - $countryw/2, $countryY, 0, $callFont, $this->country);
		// QSO data fields
		$datay = (325 * $this->imgw) / $this->imgo;
		foreach ($this->flds as $f => $x) {
			$x = ($x * $this->imgw) / $this->imgo;
			$datafont = ($f == 'worked') ? $field0Font : $fieldFont;
			if (isset($this->qsl->data->$f)) {
				imagefttext($img, $this->fieldsize, 0, $x, $datay, 0, $datafont, $this->qsl->data->$f);
			}
		}
		// Location data fields
		$fldy = (380 * $this->imgw) / $this->imgo;
		$box = imageftbbox($this->fieldsize, 0, $datafont, 'XXX');
		$x = ($this->flds['worked'] * $this->imgw) / $this->imgo;
		$yincr = ($box[1] - $box[7]) * 1.7;
		foreach ($this->locfields as $f => $lead) {
			if (!isset($this->qsl->data->$f))
				continue;
			$font = $datafont;
			if (is_array($lead)) {
				$var = $lead[1];
				$lead = $lead[0];
				$val = $this->$var ?: $this->qsl->data->$f;
				$font = $stateFont;
			} else
				$val = $this->qsl->data->$f;
			if ($val) {
				$text = $lead.$val;
				imagefttext($img, $this->fieldsize, 0, $x, $fldy, 0, $font, $text);
				$fldy += $yincr;
			}
		}
		// Add the QR code
		$this->imgh = (533 * $this->imgw) / $this->imgo;
		$this->imgs = $this->imgh / 2;
		$this->qrCode();
		$qrx = $this->imgw - ($this->imgw / 40) - $this->qrWidth;
		$qry = $this->imgh - ($this->imgw / 40) - $this->qrHeight;
		imagecopy($img, $this->qrimg, $qrx, $qry, 0, 0, $this->qrWidth, $this->qrHeight);
		$stream = fopen("php://memory", 'r+');
		imagepng($img, $stream);
		rewind($stream);
		if ($raw)
			return stream_get_contents($stream);
		return "data://image/png;base64,".base64_encode(stream_get_contents($stream));
	}
}


$login = new Login();
$user = $login->getUserFromWeb();

if (!$user || !$user->hasAdminRole('BETA')) {
	header('HTTP/1.0 401 Unauthorized');
	print "<h1>401 Unauthorized</h1><p>You are not authorized to access this</p>\n";
	exit;
}

$p = new Pg;

$response = false;

$disposition = 'inline';

\ARRL\LoTW\eQSL::$eQSLSystem = 'ARRL Test eQSL System';

$url = 'https://'. $_SERVER['SERVER_NAME'] . $_SERVER['SCRIPT_NAME'];

$request = $_REQUEST;

if (isset($request['bg'])) {
	$p->bg = (int)$request['bg'];
	if (!$p->bg)
		$p->bg = 1;
	if ($p->bg > count($p->names)) {
		$p->bg = 1;
	}
	$sel = "bgsel{$p->bg}";
	$p->$sel = 'checked';
} else {
	$p->bg = 1;
	$p->bgsel1 = 'checked';
}

if (isset($_POST['upload'])) {
	$p->view = 'upload';
	if (!isset($_FILES['qslup']) || $_FILES['qslup']['error'] == 4)
		$p->error("No file found.");
	$up = $_FILES['qslup'];
	if ($up['error'])
		$p->error("File error {$up['error']}");
	$qsl = json_decode(file_get_contents($up['tmp_name']));
	if (!$qsl)
		$p->error("File does not appear to be JSON");
	if (!isset($qsl->schema) || !isset($qsl->data) || !is_object($qsl->data))
		$p->error("File does not appear to be an electronic QSL");
	$request = (array)$qsl->data;
	$request['qsodata'] = 1;
	$p->view = 'main';
}

$p->withData = isset($request['data']);
$p->withSig = $p->withData && ($request['data'] == 'sig');

if ($p->withSig)
	$p->iselsig = 'checked';
elseif ($p->withData)
	$p->isel1 = 'checked';
else
	$p->isel = 'checked';

if (isset($request['qsodata'])) {
	// Manually entered data
	$data = new stdClass();
	$url .= '?qsodata=1';
	foreach ($request as $f => $v) {
		if (in_array($f, explode(' ', 'qsodata sig dl data bg')))
			continue;
		$v = strtoupper(trim($v));
		if (!$v)
			continue;
		$url .= "&$f=".rawurlencode($v);
		$data->$f = ($v !== '') ? $v : null;
	}
	$p->url = $url;
	$eqsl = new \ARRL\LoTW\eQSL();
	$eqsl->setURLTrail($url);
	$eqsl->minimal = true;
	$eqsl->dataSource = "Manually entered";
	$info = null;
	if (isset($_SERVER['HTTP_REFERER'])) {
		$info = new stdClass();
		$info->sent_from = preg_replace('/[?].*$/', '', $_SERVER['HTTP_REFERER']);
	}
	try {
		$response = $eqsl->generate($data, $info);
	} catch (\RuntimeException $ex) {
		error500($ex->getMessage());
	}
	$p->fn = "Elec-QSL-{$response->data->call}-{$response->data->worked}";
} elseif (isset($request['qso'])) {
	$qsoid = isset($_GET['qso']) ? $_GET['qso'] : 0;
	$qid = (int)$qsoid;

	$response = new stdClass();

	if (!is_numeric($qsoid) || $qid < 0)
		$response->error = 'Invalid QSO ID value';
	else {
		$q = new \ARRL\LoTW\QSO();
		$q->ID = $qid;
		if (!$q->getByID())
			$response->error = "QSO not found";
		elseif (!$user->hasAdminRole('JRB') && ($q->UserID != $user->ID)) {
			// Not the user's QSO, see if it QSLs a user's QSO
			$ok = false;
			if ($q->QSL) {
				$qsl = new \ARRL\LoTW\QSO();
				$qsl->ID = $q->QSL;
				if ($qsl->getByID() && $qsl->UserID == $user->ID)
					$ok = true;
			}
			if (!$ok)
				$response->error = "Not your QSO ($user->ID, $q->UserID)";
		}
		$p->state = $q->getStation()->getPASName($q->DXCC);
		$p->fn = "Elec-QSL-LoTW-$q->ID";
	}

	if (!isset($response->error)) {
		$eqsl = new \ARRL\LoTW\eQSL();
		$eqsl->urlBase = "$url?qso=";
		$p->url = $eqsl->urlBase.$qid;
		$eqsl->minimal = true;
		$eqsl->sourceVerified = true;
		try {
			$response = $eqsl->make($q);
		} catch (\RuntimeException $ex) {
			error500($ex->getMessage());
		}
	}
} else
	$p->render('upload');

if (!$response)
	$p->error("No QSL found");

$p->setQSL($response);

$dl = isset($_REQUEST['dl']) ? $_REQUEST['dl'] : '';

switch ($dl) {
	case 'img':
		$fn = $p->fn;
		if ($p->withSig)
			$fn .= '-signed';
		elseif ($p->withData)
			$fn .= '-data';
		$fn .= '.png';
		header('Content-Type: image/png');
		header("Content-Disposition: attachment; filename=\"$fn\"");
		print $p->qslImage(true);
		exit;
	case 'json':
		$fn = $p->fn;
		$fn .= '.json';
		header('Content-Type: application/json');
		header("Content-Disposition: attachment; filename=\"$fn\"");
		print json_encode($p->qsl, JSON_PRETTY_PRINT);
		exit;
	case 'qr':
		$p->qrCode(4);
		$fn = $p->fn;
		if ($p->withSig)
			$fn .= '-signed';
		elseif ($p->withData)
			$fn .= '-data';
		$fn .= '-qr.png';
		header('Content-Type: image/png');
		header("Content-Disposition: attachment; filename=\"$fn\"");
		$stream = fopen("php://memory", 'r+');
		imagepng($p->qrimg, $stream);
		rewind($stream);
		print stream_get_contents($stream);
		exit;
}

$p->render('main');

function error500 ($why = '') {
	header("HTTP/1.0 500 System Error");
	print ("<h1>System Error</h1>");
	if ($why)
		print "<p>".htmlspecialchars($why)."</p>";
	exit;
}
