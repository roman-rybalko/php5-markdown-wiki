<?php

class MarkdownWiki {
	// Wiki default configuration. All overridableindex
	protected $config = array(
		'docDir'      => '/tmp/',
		'defaultPage' => 'index',
		'newPageText' => 'Start editing your new page',
		'markdownExt' => 'markdown'
	);

	// An instance of the Markdown parser
	protected $parser;

	public function __construct($config=false) {
		$this->initWiki();
		if ($config) {
			$this->setConfig($config);
		}
	}

	protected function initWiki() {
		$baseDir = dirname(__FILE__) . '/';

		// Including the markdown parser
		//error_log("BaseDir: {$baseDir}");
		require_once $baseDir . 'markdown.php';
	}

	private function wikiLink($action, $link) {
		$isNew = false;
		$wikiUrl = '#';

		// str_contains()
		if (strpos($link, '://') !== false) {
			// URL
			$isNew = false;
			$wikiUrl = $link;
		} elseif (substr($link, 0, 1) == '#') {
			// fragment identifier (hash anchor)
			$isNew = false;
			$wikiUrl = $link;
		} elseif (!$this->isPathSecure($link)) {
			$isNew = true;
			$wikiUrl = '#insecure';
		} else {
			// str_ends_with()
			if (substr($link, -1) == '/') {
				$link .= $this->config['defaultPage'];
			}
			// str_starts_with()
			if (substr($link, 0, 1) == '/') {
				$page = substr($link, 1);
			} else {
				$page = $this->dirname($action->page) . $link;
			}
			$isNew = !file_exists($this->getFilename($page));
			$wikiUrl = "{$action->base}{$page}?id=" . urlencode($page);
		}

		//error_log("link = {$link}, isNew = {$isNew}, wikiUrl = {$wikiUrl}");
		return array($isNew, $wikiUrl);
	}

	public function setConfig($config) {
		$this->config = array_merge($this->config, $config);
	}

	public function handleRequest($request=false, $server=false) {
		$action           = $this->parseRequest($request, $server);
		$action->model    = $this->getModelData($action);

		if (is_dir($action->model->file)) {
			$action->page .= "/{$this->config['defaultPage']}";
			$action->model = $this->getModelData($action);
		}

		// If this is a new file, switch to edit mode
		if ($action->model->updated==0 && $action->action=='display') {
			$action->action = 'edit';
		}

		$action->response = $this->doAction($action);

		if (pathinfo($action->model->file, PATHINFO_EXTENSION) != $this->config['markdownExt'] && empty($action->response['messages'])) {
			header('Content-Type: ' . mime_content_type($action->model->file));
			header('Content-Length: ' . filesize($action->model->file));
			readfile($action->model->file);
		} else {
			$output = $this->renderResponse($action->response);
		}

		//error_log(print_r($action, true));
	}

	##
	## Methods handling each action
	##

	public function doAction($action) {

		switch($action->action) {
			case 'UNKNOWN': # Default to display
			case 'display':
				$response = $this->doDisplay($action);
				break;
			case 'edit':
				$response = $this->doEdit($action);
				break;
			case 'preview':
				$response = $this->doPreview($action);
				break;
			case 'save':
				$response = $this->doSave($action);
				break;
			case 'browse':
				$response = $this->doBrowse($action);
				break;
			case 'upload':
				$response = $this->doUpload($action);
				break;
			case 'rename':
				$response = $this->doRename($action);
				break;
			default:
				$response = array(
					'messages' => array(
						"Action {$action->action} is not implemented.",
					),
				);
				error_log(print_r($action, true));
				break;
		}

		return $response;
	}

	private function isDefaultPage($page) {
		return basename($page)==$this->config['defaultPage'] || basename($page)=="{$this->config['defaultPage']}.{$this->config['markdownExt']}";
	}

	private function getUpLink($action, $force = false) {
		$dir = $this->dirname($action->page);
		$updir = $this->dirname($dir);
		$up = $this->isDefaultPage($action->page) || $force
			? "{$action->base}{$updir}{$this->config['defaultPage']}?id=" . urlencode("{$updir}{$this->config['defaultPage']}")
			: "{$action->base}{$dir}{$this->config['defaultPage']}?id=" . urlencode("{$dir}{$this->config['defaultPage']}");
		return $up;
	}

	protected function doDisplay($action) {
		$response = array(
			'title'    => "Displaying: {$action->page}",
			'content'  => $this->renderDocument($action),
			'editForm' => '',
			'options'  => array(
				'Up' => $this->getUpLink($action),
				'Browse' => "{$action->base}{$action->page}?action=browse&id=" . urlencode("{$action->page}"),
				'Edit' => "{$action->base}{$action->page}?action=edit&id=" . urlencode("{$action->page}"),
				'uplOad' => "{$action->base}{$action->page}?action=upload&id=" . urlencode("{$action->page}"),
			),
			'related'  => ''
		);

		return $response;
	}

	protected function doEdit($action) {
		$response = array(
			'title'    => "Editing: {$action->page}",
			'content'  => '',
			'editForm' => $this->renderEditForm($action),
			'options'  => array(
				'Up' => $this->getUpLink($action),
				'Browse' => "{$action->base}{$action->page}?action=browse&id=" . urlencode("{$action->page}"),
				'cancel(Z)' => "{$action->base}{$action->page}?id=" . urlencode("{$action->page}"),
				'uplOad' => "{$action->base}{$action->page}?action=upload&id=" . urlencode("{$action->page}"),
			),
			'related'  => ''
		);

		return $response;
	}

	protected function doPreview($action) {
		$response = array(
			'title'    => "Editing: {$action->page}",
			'content'  => $this->renderPreviewDocument($action),
			'editForm' => $this->renderEditForm($action),
			'options'  => array(
				'Up' => $this->getUpLink($action),
				'Browse' => "{$action->base}{$action->page}?action=browse&id=" . urlencode("{$action->page}"),
				'cancel(Z)' => "{$action->base}{$action->page}?id=" . urlencode("{$action->page}"),
			),
			'related'  => ''
		);

		return $response;
	}

	protected function doSave($action) {
		if (empty($action->model)) {
			// This is a new file
			$msg = "INFO: Saving a new file";
		} else
		// Check there isn't an editing conflict
		if ($action->model->updated==$action->post->updated) {
			$action->model->content = $action->post->text;
			$msg = $this->setModelData($action->model);
		} else {
			$msg = "WARN: Editing conflict!";
		}

		$response = $this->doDisplay($action);

		if (!empty($msg)) $response['messages'][] = $msg;

		return $response;
	}

	private function dirname($path) {
		$dir = dirname($path);
		if ($dir=='.' || $dir=='/' || $dir=='./' || $dir=='') $dir = ''; else $dir .= '/';
		return $dir;
	}

	private function getDisplayDir($action) {
		$dir = $this->dirname($action->page);
		if ($dir == '') $dir = '/';
		return $dir;
	}

	protected function doBrowse($action) {
		$response = array(
			'title'    => "Browsing: " . $this->getDisplayDir($action),
			'content'  => $this->renderFileList($action),
			'editForm' => '',
			'options'  => array(
				'Up' => $this->getUpLink($action, true) . "&action=browse",
				'Display' => "{$action->base}{$action->page}?id=" . urlencode("{$action->page}"),
				'Edit' => "{$action->base}{$action->page}?action=edit&id=" . urlencode("{$action->page}"),
				'uplOad' => "{$action->base}{$action->page}?action=upload&id=" . urlencode("{$action->page}"),
			),
			'related'  => ''
		);

		return $response;
	}

	protected function doUpload($action) {
		$response = array(
			'title'    => "Uploading to: " . $this->getDisplayDir($action),
			'content'  => '',
			'editForm' => $this->renderUploadForm($action),
			'options'  => array(
				'Up' => $this->getUpLink($action),
				'Browse' => "{$action->base}{$action->page}?action=browse&id=" . urlencode("{$action->page}"),
				'Edit' => "{$action->base}{$action->page}?action=edit&id=" . urlencode("{$action->page}"),
				'cancel(Z)' => "{$action->base}{$action->page}?id=" . urlencode("{$action->page}"),
			),
			'related'  => ''
		);

		$msg = $this->setModelDataUpload($action->model, !empty($action->post->force));
		//error_log("msg = {$msg}, type = " . gettype($msg));
		if ($msg === '') {
			// skip
		} elseif (is_array($msg)) {
			// informational messages
			$response = $this->doBrowse($action);
			$response['messages'] = empty($response['messages']) ? $msg : $response['messages'] + $msg;
			return $response;
		} elseif ($msg) {
			$response['messages'][] = $msg;
		} else {
			// workaround: add some messages to prevent binary passthrough
			$response = $this->doBrowse($action);
			$response['messages'][] = "Uploaded: " . basename($action->model->file) . " "
				. "<a href=\"#\" onclick=\"renamePath('" . $this->dirname($action->page) . basename($action->model->file) . "');return false;\">rename</a>";
			return $response;
		}

		return $response;
	}

	protected function doRename($action) {
		if (empty($action->post->path) || empty($action->post->newpath)) {
			$msg = "ERROR: Invalid rename request (probably some params are insecure)";
		} else {
			$msg = $this->doModelRename($action->post->path, $action->post->newpath, !empty($action->post->force));
		}

		$response = $this->doBrowse($action);

		if (!empty($msg)) $response['messages'][] = $msg;

		return $response;
	}

	##
	## Methods dealing with the model (plain old file system)
	##

	protected function getModelData($action) {
		$data = (object) NULL;

		$filename = NULL;
		if ($action->method=='POST' && !empty($action->post->tmpfile)) {
			$filename = $action->post->filename;
			$data->tmpfile = $action->post->tmpfile;
		}

		$data->file = $this->getFilename($action->page, $filename);
		$data->content = $this->getContent($data->file);
		$data->updated = $this->getLastUpdated($data->file);

		return $data;
	}

	private function getBackupFilename($path, $max_backups = 1000) {
		$dir = $this->dirname($path);
		$file = basename($path);
		$extpos = strrpos($file, '.');
		if (!$extpos) $extpos = strlen($file);
		$pref = $dir . substr($file, 0, $extpos);
		$ext = substr($file, $extpos, strlen($path) - $extpos);
		//error_log("dir = {$dir}, file = {$file}, ext = {$ext}");

		$fn = function($i) use($pref, $ext) { return "{$pref}.{$i}{$ext}"; };

		for ($i = 1; $i < $max_backups; ++$i) {
			$backup = $fn($i);
			if (!file_exists($backup)) break;
		}
		//error_log("backup = {$backup}, i = {$i}");

		if ($i >= $max_backups)
			// max backup count was reached, rotating backups
			for ($k = 1; $k+1 < $i; ++$k)
				rename($fn($k+1), $fn($k));

		return $backup;
	}

	protected function setModelData($model) {
		$directory = dirname($model->file);
		if (!file_exists($directory)) {
			if (!mkdir($directory, 0777, true)) {
				return "ERROR: Can not create some directory in the tree (probably there is a file on the way, see the server error log)";
			}
		} elseif (!is_dir($directory)) {
			return "ERROR: Can not create the directory " . basename($directory) . " (already exists, is a file)";
		}

		// Save version
		if (file_exists($model->file)) {
			if (!rename($model->file, $this->getBackupFilename($model->file))) {
				$msg = "WARN: backup failed (see the server error log)";
			}
		}

		if (!file_put_contents($model->file, $model->content))
		{
			return "ERROR: file_put_contents() failed (see the server error log)";
		}

		if (!empty($msg)) return $msg;
	}

	// Returns:
	// '' - no needed post data in the request, skipping
	// 'str' - error
	// ['str', 'str'] - ok, info messages
	// NULL - ok
	protected function setModelDataUpload($model, $force = false) {
		if (empty($model->tmpfile)) {
			// No uploaded file right now
			return '';
		}

		$directory = dirname($model->file);
		if (!file_exists($directory)) {
			mkdir($directory, 0777, true);
		} elseif (!is_dir($directory)) {
			return "ERROR: Can not create the directory " . basename($directory) . " (already exists, is a file)";
		}

		if (file_exists($model->file)) {
			if (!$force) {
				return "ERROR: File " . basename($model->file) . " already exists";
			}

			// force uploading with clobbering
			$model->file = $this->getBackupFilename($model->file);
			$msgs[] = "INFO: Target file already exists, uploaded with a new name: " . basename($model->file);
		}

		if (!move_uploaded_file($model->tmpfile, $model->file)) {
			return "ERROR: move_uploaded_file() failed (see the server error log)";
		}

		if (!empty($msgs)) return $msgs;
	}

	protected function doModelRename($page, $newpage, $force = false) {
		$path = "{$this->config['docDir']}{$page}";

		if (!file_exists($path)) {
			return "ERROR: The path {$page} does not exist";
		}

		$newpath = "{$this->config['docDir']}{$newpage}";
		$newpathdir = dirname($newpath);

		if (!file_exists($newpathdir)) {
			if (!$force) {
				$directory = $this->dirname($newpage);
				return "ERROR: The destination directory {$directory} does not exist";
			}

			if (!mkdir($newpathdir, 0777, true)) {
				return "ERROR: mkdir() failed (see the server error log)";
			}
		}

		if (!is_dir($newpathdir)) {
			$directory = $this->dirname($newpage);
			return "ERROR: The destination directory {$directory} is a file";
		}

		if (file_exists($newpath)) {
			if (!$force) {
				return "ERROR: The path {$newpage} already exists";
			}

			// force rename with clobbering
			$newpath = $this->getBackupFilename($newpath);
		}

		if (!rename($path, $newpath)) {
			return "ERROR: rename() failed (see the server error log)";
		}
	}

	##
	## Methods for parsing the incoming request
	##

	public function parseRequest($request=false, $server=false) {
		$action = (object) NULL;

		if (!$request) { $request = $_REQUEST; }
		if (!$server)  { $server  = $_SERVER;  }

		//error_log("Request: " . print_r($request, true));
		//error_log("Server : " . print_r($server, true));

		$action->method = $this->getMethod($request, $server);
		$action->page   = $this->getPage($request, $server);
		$action->action = $this->getAction($request, $server);
		$action->base   = $this->getBaseUrl($request, $server);

		if ($action->method=='POST') {
			$action->post = $this->getPostDetails($request, $server);
		}

		return $action;
	}

	protected function getFilename($page, $name = NULL) {
		if ($name) {
			if (substr($page, -1) != '/') $page = $this->dirname($page);
			$name = basename($name);
			$name = str_replace(' ', '_', $name);
			$name = str_replace('%', '_', $name);
			return "{$this->config['docDir']}{$page}{$name}";
		}
		// Order is important
		if (pathinfo($page, PATHINFO_EXTENSION) == $this->config['markdownExt']) {
			return "{$this->config['docDir']}{$page}";
		}
		if (file_exists("{$this->config['docDir']}{$page}") && !is_dir("{$this->config['docDir']}{$page}")) {
			return "{$this->config['docDir']}{$page}";
		}
		if (file_exists("{$this->config['docDir']}{$page}.{$this->config['markdownExt']}")) {
			return "{$this->config['docDir']}{$page}.{$this->config['markdownExt']}";
		}
		if (file_exists("{$this->config['docDir']}{$page}")) {
			return "{$this->config['docDir']}{$page}";
		}
		return "{$this->config['docDir']}{$page}.{$this->config['markdownExt']}";
	}

	protected function getContent($filename) {
		if (pathinfo($filename, PATHINFO_EXTENSION) != $this->config['markdownExt']) {
			// skip a binary file
			return '';
		}
		if (file_exists($filename)) {
			if (is_dir($filename)) {
				return '';
			}
			return file_get_contents($filename);
		}
		return $this->config['newPageText'];
	}

	protected function getLastUpdated($filename) {
		if (file_exists($filename)) {
			return filemtime($filename);
		}
		return 0;
	}

	protected function getMethod($request, $server) {
		if (!empty($server['REQUEST_METHOD'])) {
			return $server['REQUEST_METHOD'];
		}
		return 'UNKNOWN';
	}

	protected function getPage($request, $server) {
		$page = '';

		// Determine the page name
		if (!empty($request['id'])) {
			$page = $request['id'];
		} elseif (!empty($server['PATH_INFO'])) {
			//error_log("Path info detected");
			// If we are using PATH_INFO then that's the page name
			$page = substr($server['PATH_INFO'], 1);
		} else {
			// TODO: Keep checking
			//error_log("WARN: Could not find a pagename");
		}

		// Check whether a default Page is being requested
		if ($page=='' || preg_match('/\/$/', $page)) {
			$page .= $this->config['defaultPage'];
		}

		return $page;
	}

	protected function getAction($request, $server) {
		if ($server['REQUEST_METHOD']=='POST') {
			if (!empty($request['preview'])) {
				return 'preview';
			} elseif (!empty($request['save'])) {
				return 'save';
			} elseif (!empty($request['upload'])) {
				return 'upload';
			} elseif (!empty($request['rename'])) {
				return 'rename';
			}
		} elseif (!empty($request['action'])) {
			return $request['action'];
		} elseif (!empty($server['PATH_INFO'])) {
			return 'display';
		}

		return 'UNKNOWN';
	}

	protected function getBaseUrl($request, $server) {
		if (!empty($this->config['baseUrl'])) {
			return $this->config['baseUrl'];
		}
		/**
			PATH_INFO $_SERVER keys
    [SERVER_NAME] => localhost
    [DOCUMENT_ROOT] => /home/user/sites/default/htdocs
    [SCRIPT_FILENAME] => /home/user/sites/default/htdocs/index-sample.php
    [REQUEST_METHOD] => GET
    [QUERY_STRING] =>
    [REQUEST_URI] => /index-sample.php
    [SCRIPT_NAME] => /index-sample.php
    [PHP_SELF] => /index-sample.php
		**/

		$scriptName = $server['SCRIPT_NAME'];
		$requestUrl = $server['REQUEST_URI'];
		$phpSelf    = $server['PHP_SELF'];

		if ($requestUrl==$scriptName) {
			// PATH_INFO based
		} elseif(strpos($requestUrl, $scriptName)===0) {
			// Query string based
		} else {
			// Maybe mod_rewrite based?
			// Perhaps we need a config entry here
		}

		return '/markdown/'; // PATH-INFO base
	}

	private function isPathSecure($path) {
		// str_contains()
		if (strpos($path, '/../') !== false) return false;

		// str_starts_with()
		if (strpos($path, '../') === 0) return false;

		// str_ends_with()
		if (strpos(strrev($path), strrev('/..')) === 0) return false;

		return true;
	}

	protected function getPostDetails($request, $server) {
		$post = (object) NULL;
		if (!empty($request['upload'])) {
			$post->tmpfile = $_FILES['file']['tmp_name'];
			$post->filename = basename($_FILES['file']['name']);
			if (!empty($request['force'])) $post->force = $request['force'];
		} elseif (!empty($request['rename'])) {
			if ($this->isPathSecure($request['path'])) $post->path = $request['path'];
			if ($this->isPathSecure($request['newpath'])) $post->newpath = $request['newpath'];
			if (!empty($request['force'])) $post->force = $request['force'];
			//error_log(print_r($post, true));
		} else {
			$post->text = $request['text'];
			$post->updated = $request['updated'];
			if (!empty($request['resolve'])) $post->resolve = $request['resolve'];
		}
		return $post;
	}

	/*********

		RESPONSE RENDERERS

	*********/

	private function getHotkey($label) {
		$key = strtoupper($label[0]);
		foreach(str_split(strrev($label)) as $c) { // find Uppercase
			if (ctype_upper($c)) {
				$key = $c;
				break;
			}
		}
		return $key;
	}

	public function renderResponse($response) {
		if (!empty($this->config['layout'])) {
			// TODO: Use a custom template
		} else {
			$header = array();

			if (!empty($response['options'])) {
				$header[] = '<table><tr>';
				foreach($response['options'] as $label=>$link) {
					$key = $this->getHotkey($label);
					$header[] = <<<HTML
<td><a href="{$link}" id="{$label}" title="Ctrl-{$key}">{$label}</a></td>
HTML;
				}
				$header[] = '</tr></table>';
				$header[] = <<<HTML
<script>
document.addEventListener("keydown", function(e) {
HTML;
				foreach(array_keys($response['options']) as $label) {
					$key = strtolower($this->getHotkey($label));
					$header[] = <<<HTML
	if (e.ctrlKey && e.key === '{$key}') {
		e.preventDefault(); // file dialog, page source, print, etc.
		document.getElementById("{$label}").click();
	}
HTML;
				}
				$header[] = <<<HTML
});
</script>
HTML;
			}
			$response['header'] = implode("\n", $header);

			if (empty($response['footer'])) {
				$response['footer'] = '<!-- footer -->';
			}

			if (empty($response['messages'])) {
				$response['messages'] = '<!-- messages -->';
			} else {
				$response['messages'] = '<table><tr><td>' . implode('</td></tr><tr><td>', $response['messages']) . '</td></tr></table>';
			}

			echo <<<PAGE
<html>
<head>
	<meta charset="UTF-8">
	<title>{$response['title']}</title>
</head>
<body style="background-color: white;">
	<div id="page">
		<div id="head">
{$response['header']}
{$response['messages']}
		</div>
		<div id="content">
{$response['content']}
{$response['editForm']}
		</div>
		<div id="related">
{$response['related']}
		</div>
		<div id="foot">
{$response['footer']}
		</div>
	</div>
</body>
</html>
PAGE;
		}
	}

	protected function renderDocument($action) {
		return Markdown(
			$action->model->content,
			function ($link) use($action) {
				return $this->wikiLink($action, $link);
			}
		);
	}

	private function resolveUrls($text) {
		$ctx = stream_context_create([
			'http' => ['timeout' => 1, 'user_agent' => 'MobileSafari/604.1 CFNetwork/978.0.7 Darwin/18.7.0'],
			'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
		]);
		$text = preg_replace_callback('|(\s+)(https?://[^\s,;\)\]\}]+)|i', function($matches) use($ctx) {
			$space = $matches[1];
			$url = $matches[2];
			//error_log("url = {$url}");
			$html = file_get_contents($url, false, $ctx, 0, 1000);
			//error_log("html = " . preg_replace('|\s+|', "", $html));
			if (preg_match('|<title[^>]*>(.+?)</title>|i', $html, $matches)) {
				$title = $matches[1];
				$title = trim($title);
				$title = str_replace(['*', '_'], ['\*', '\_'], $title);
			} else $title = str_replace(['*', '_'], ['\*', '\_'], $url);
			//error_log("title = {$title}");
			return "{$space}[{$title}]({$url})";
		}, $text);
		return $text;
	}

	protected function renderPreviewDocument($action) {
		if (!empty($action->post->resolve))
			$action->post->text = $this->resolveUrls($action->post->text);
		return Markdown(
			$action->post->text,
			function ($link) use($action) {
				return $this->wikiLink($action, $link);
			}
		);
	}

	protected function renderEditForm($action) {
		if (!empty($action->post)) {
			$form = array(
				'raw'     => $action->post->text,
				'updated' => $action->post->updated
			);
		} else {
			$form = array(
				'raw'     => $action->model->content,
				'updated' => $action->model->updated
			);
		}

		return <<<HTML
<form action="{$action->base}{$action->page}" method="post">
	<input type="hidden" name="id" value="{$action->page}">
	<fieldset>
		<legend>Editing</legend>
		<label for="text">Content:</label><br>
		<textarea cols="78" rows="20" name="text" id="text">{$form['raw']}</textarea>
		<br>
		<input type="checkbox" name="resolve" value="resolve" id="resolve">
		<label for="resolve" title="Fetch the html title, fill the link description.">Resolve urls in preview</label>
		<input type="submit" name="preview" value="Preview" id="preview" title="Ctrl-P">
		<input type="submit" name="save" value="Save" id="save" title="Ctrl-S">
		<input type="hidden" name="updated" value="{$form['updated']}">
	</fieldset>
</form>
<script>
document.addEventListener('keydown', function(e) {
	if (e.ctrlKey && e.key === 'p') {
		e.preventDefault(); // print dialog
		document.getElementById('preview').click();
	}
	if (e.ctrlKey && e.key === 's') {
		e.preventDefault(); // save dialog
		document.getElementById('save').click();
	}
});
document.getElementById('text').focus();
</script>
HTML;
	}

	protected function renderFileList($action) {
		$fsdir = $this->dirname($action->model->file);
		$urldir = $this->dirname($action->page);
		$content[] = '<table>';
		if (file_exists($fsdir)) foreach (scandir($fsdir) as $file) {
			if ($file == '.' || $file == '..') continue;
			$content[] = '<tr><td>';
			if (is_dir("{$fsdir}{$file}")) {
				$content[] = '<a href="' . "{$action->base}{$urldir}{$file}/{$this->config['defaultPage']}?action=browse&id=" . urlencode("{$urldir}{$file}/{$this->config['defaultPage']}") . "\">{$file}</a>";
			} else {
				$content[] = '<a href="' . "{$action->base}{$urldir}{$file}?id=" . urlencode("{$urldir}{$file}") . "\">{$file}</a>";
			}
			$content[] = '</td><td>';
			$content[] = filesize("{$fsdir}{$file}");
			$content[] = '</td><td>';
			$content[] = date("Y-m-d H:i:s", $this->getLastUpdated("{$fsdir}{$file}"));
			$content[] = '</td><td>';
			$content[] = "<a href=\"#\" onclick=\"deletePath('{$urldir}{$file}');return false;\">delete</a>";
			$content[] = '</td><td>';
			$content[] = "<a href=\"#\" onclick=\"renamePath('{$urldir}{$file}');return false;\">rename</a>";
			$content[] = '</td></tr>';
		}
		$content[] = '</table>';
		$content[] = <<<HTML
<form action="{$action->base}{$action->page}" method="post" id="rename">
	<input type="hidden" name="id" value="{$action->page}">
	<input type="hidden" name="path" id="rename_path">
	<input type="hidden" name="newpath" id="rename_newpath">
	<input type="hidden" name="rename" value="rename">
	<input type="hidden" name="force" id="rename_force">
</form>
<script>
function deletePath(path) {
	if (confirm('Delete ' + path + ' ?')) {
		document.getElementById('rename_path').value = path;
		document.getElementById('rename_newpath').value = '.trash/' + path;
		document.getElementById('rename_force').value = 'force';
		document.getElementById('rename').submit();
	}
}
function renamePath(path) {
	var newpath = prompt('New path/filename', path);
	if (newpath) {
		document.getElementById('rename_path').value = path;
		document.getElementById('rename_newpath').value = newpath;
		document.getElementById('rename').submit();
	}
}
</script>
HTML;
		return implode("\n", $content);
	}

	protected function renderUploadForm($action) {
		$dir = $this->getDisplayDir($action);
		return <<<HTML
<form action="{$action->base}{$action->page}" method="post" enctype="multipart/form-data">
	<input type="hidden" name="id" value="{$action->page}">
	<fieldset>
		<legend>Uploading to {$dir}</legend>
		<table><tr><td>
		<label for="upload_file">Content:</label>
		</td><td>
		<input type="file" name="file" id="upload_file">
		</td><td>
		<input type="submit" name="upload" value="Upload" id="upload">
		</td></tr></table>
	</fieldset>
	<input type="hidden" name="force" id="upload_force">
	<span title="Shift-Ins does not always work. If there is no file in clipboard try to reduce the size of the image.">Ctrl-V to paste from clipboard.</span>
</form>
<script>
function handleClipboard(e) {
	if (e.clipboardData.files.length < 1) {
		alert('No files in clipboard');
		return;
	}
	if (e.clipboardData.files.length > 1) {
		alert('Too many files in clipboard');
		return;
	}
	if (confirm('Uploading ' + e.clipboardData.files[0].name + ' ' + e.clipboardData.files[0].size + ' ' + e.clipboardData.files[0].type + ' ?')) {
		document.getElementById('upload_file').files = e.clipboardData.files;
		// No way to rename uploaded file here, clobbering with a `force` flag
		document.getElementById('upload_force').value = 'force';
		document.getElementById('upload').click();
	}
}
window.addEventListener('paste', handleClipboard);
document.getElementById('upload_file').focus();
</script>
HTML;
	}

}

if (!empty($config)) {
	# Dealing with a web request
	$wiki = new MarkdownWiki($config);
	$wiki->handleRequest();
	//error_log(print_r($wiki, true));
}

?>
