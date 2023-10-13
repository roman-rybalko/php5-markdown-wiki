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
	protected $baseUrl;

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

	public function wikiLink($link) {
		global $docIndex;

		$isNew = false;
		$wikiUrl = $link;

		if (preg_match('/^\/?([a-z0-9-]+(\/[a-z0-9-]+)*)$/i', $link, $matches)) {
			$wikiUrl = "{$this->baseUrl}{$matches[1]}";
			$isNew = !$this->isMarkdownFile($link);
		} elseif ($link=='/') {
			$wikiUrl = "{$this->baseUrl}{$this->config['defaultPage']}";
			$isNew = !$this->isMarkdownFile($this->config['defaultPage']);
		}

		return array($isNew, $wikiUrl);
	}

	public function isMarkdownFile($link) {
		$filename = "{$this->config['docDir']}{$link}.{$this->config['markdownExt']}";
		return file_exists($filename);
	}

	public function setConfig($config) {
		$this->config = array_merge($this->config, $config);
	}

	public function handleRequest($request=false, $server=false) {
		$action           = $this->parseRequest($request, $server);
		$action->model    = $this->getModelData($action);

		// If this is a new file, switch to edit mode
		if ($action->model->updated==0 && $action->action=='display') {
			$action->action = 'edit';
		}

		$action->response = $this->doAction($action);

		if (empty($action->model->content) && empty($action->response['content']) && empty($action->response['messages'])) {
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

	protected function doDisplay($action) {
		$top = $this->dirname($action->page);
		$toptop = $this->dirname($top);
		$response = array(
			'title'    => "Displaying: {$action->page}",
			'content'  => $this->renderDocument($action),
			'editForm' => '',
			'options'  => array(
				'Top' => "{$action->base}{$toptop}{$this->config['defaultPage']}?id={$toptop}{$this->config['defaultPage']}",
				'Browse' => "{$action->base}{$top}{$this->config['defaultPage']}?action=browse&amp;id={$top}{$this->config['defaultPage']}",
				'Edit' => "{$action->base}{$action->page}?action=edit&amp;id={$action->page}",
			),
			'related'  => ''
		);

		if (basename($action->page)==$this->config['defaultPage'] || basename($action->page)=="{$this->config['defaultPage']}.{$this->config['markdownExt']}") {
			// skip
		} else {
			$response['options'] += array(
				'Index' => "{$action->base}{$top}{$this->config['defaultPage']}?id={$top}{$this->config['defaultPage']}",
			);
		}

		return $response;
	}

	protected function doEdit($action) {
		$top = $this->dirname($action->page);
		$toptop = $this->dirname($top);
		$response = array(
			'title'    => "Editing: {$action->page}",
			'content'  => '',
			'editForm' => $this->renderEditForm($action),
			'options'  => array(
				'Top' => "{$action->base}{$toptop}{$this->config['defaultPage']}?id={$toptop}{$this->config['defaultPage']}",
				'Browse' => "{$action->base}{$top}{$this->config['defaultPage']}?action=browse&amp;id={$top}{$this->config['defaultPage']}",
				'Upload' => "{$action->base}{$top}{$this->config['defaultPage']}?action=upload&amp;id={$top}{$this->config['defaultPage']}",
				'Cancel' => "{$action->base}{$action->page}",
			),
			'related'  => ''
		);

		return $response;
	}

	protected function doPreview($action) {
		$top = $this->dirname($action->page);
		$toptop = $this->dirname($top);
		$response = array(
			'title'    => "Editing: {$action->page}",
			'content'  => $this->renderPreviewDocument($action),
			'editForm' => $this->renderEditForm($action),
			'options'  => array(
				'Top' => "{$action->base}{$toptop}{$this->config['defaultPage']}?id={$toptop}{$this->config['defaultPage']}",
				'Browse' => "{$action->base}{$top}{$this->config['defaultPage']}?action=browse&amp;id={$top}{$this->config['defaultPage']}",
				'Cancel' => "{$action->base}{$action->page}",
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

		if (!empty($msg)) {
			$response['messages'][] = $msg;
		}

		return $response;
	}

	private function dirname($path) {
		$top = dirname($path);
		if ($top=='.' || $top=='/' || $top=='./' || $top=='') $top = ''; else $top .= '/';
		return $top;
	}

	protected function doBrowse($action) {
		$top1 = $top = $this->dirname($action->page);
		if ($top1 == '') $top1 = '/';
		$toptop = $this->dirname($top);
		$response = array(
			'title'    => "Browsing: {$top1}",
			'content'  => $this->renderFileList($action),
			'editForm' => '',
			'options'  => array(
				'Top' => "{$action->base}{$toptop}{$this->config['defaultPage']}?id={$toptop}{$this->config['defaultPage']}",
				'Upload' => "{$action->base}{$top}{$this->config['defaultPage']}?action=upload&amp;id={$top}{$this->config['defaultPage']}",
				'Edit' => "{$action->base}{$action->page}?action=edit&amp;id={$action->page}",
				'Index' => "{$action->base}{$top}{$this->config['defaultPage']}?id={$top}{$this->config['defaultPage']}",
			),
			'related'  => ''
		);

		return $response;
	}

	protected function doUpload($action) {
		$top1 = $top = $this->dirname($action->page);
		if ($top1 == '') $top1 = '/';
		$toptop = $this->dirname($top);
		$response = array(
			'title'    => "Uploading to: {$top1}",
			'content'  => '',
			'editForm' => $this->renderUploadForm($action),
			'options'  => array(
				'Top' => "{$action->base}{$toptop}{$this->config['defaultPage']}?id={$toptop}{$this->config['defaultPage']}",
				'Browse' => "{$action->base}{$top}{$this->config['defaultPage']}?action=browse&amp;id={$top}{$this->config['defaultPage']}",
				'Cancel' => "{$action->base}{$action->page}",
			),
			'related'  => ''
		);

		$msg = $this->setModelDataUpload($action->model);
		if ($msg === '') {
			// skip
		} elseif ($msg) {
			$response['messages'][] = $msg;
		} else {
			return $this->doBrowse($action);
		}

		return $response;
	}

	protected function doRename($action) {
		if (empty($action->post->path) || empty($action->post->newpath)) {
			$response = $this->doBrowse($action);
			$response['messages'][] = "ERROR: Invalid rename request (probably some params are insecure)";
			return $response;
		}

		$path = "{$this->config['docDir']}{$action->post->path}";
		if (!file_exists($path)) {
			$response = $this->doBrowse($action);
			$response['messages'][] = "ERROR: The path {$action->post->path} does not exist";
			return $response;
		}

		$newpath = "{$this->config['docDir']}{$action->post->newpath}";
		$newpathdir = dirname($newpath);

		if (!file_exists($newpathdir)) {
			if (empty($action->post->force)) {
				$response = $this->doBrowse($action);
				$directory = $this->dirname($action->post->newpath);
				$response['messages'][] = "ERROR: The destination directory {$directory} does not exist";
				return $response;
			}

			if (!mkdir($newpathdir, 0777, true)) {
				$response = $this->doBrowse($action);
				$response['messages'][] = "ERROR: mkdir() failed (see the server error log)";
				return $response;
			}
		}

		if (!is_dir($newpathdir)) {
			$response = $this->doBrowse($action);
			$directory = $this->dirname($action->post->newpath);
			$response['messages'][] = "ERROR: The destination directory {$directory} is a file";
			return $response;
		}

		if (file_exists($newpath)) {
			if (empty($action->post->force)) {
				$response = $this->doBrowse($action);
				$response['messages'][] = "ERROR: The path {$action->post->newpath} already exists";
				return $response;
			}

			// force rename with clobbering
			$newpath = $this->getBackupFilename($newpath);
		}

		if (!rename($path, $newpath)) {
			$response = $this->doBrowse($action);
			$response['messages'][] = "ERROR: rename() failed (see the server error log)";
			return $response;
		}

		return $this->doBrowse($action);
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

		if (pathinfo($data->file, PATHINFO_EXTENSION) == $this->config['markdownExt']) {
			$data->content = $this->getContent($data->file);
		}

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

	protected function setModelDataUpload($model) {
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
			return "ERROR: File " . basename($model->file) . " already exists";
		}

		if (!move_uploaded_file($model->tmpfile, $model->file)) {
			return "ERROR: move_uploaded_file() failed (see the server error log)";
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

		// Take a copy of the action base for the wikiLink function
		$this->baseUrl = $action->base;

		return $action;
	}

	protected function getFilename($page, $name = NULL) {
		if ($name) {
			if (substr($page, -1) != '/') $page = $this->dirname($page);
			$name = basename($name);
			$name = str_replace(' ', '_', $name);
			return "{$this->config['docDir']}{$page}{$name}";
		}
		if (file_exists("{$this->config['docDir']}{$page}")) {
			return "{$this->config['docDir']}{$page}";
		}
		if (pathinfo($page, PATHINFO_EXTENSION) == $this->config['markdownExt'])
		{
			return "{$this->config['docDir']}{$page}";
		}
		return "{$this->config['docDir']}{$page}.{$this->config['markdownExt']}";
	}

	protected function getContent($filename) {
		if (file_exists($filename)) {
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
			$post->tmpfile = $_FILES['text']['tmp_name'];
			$post->filename = basename($_FILES['text']['name']);
		} elseif (!empty($request['rename'])) {
			if ($this->isPathSecure($request['path'])) $post->path = $request['path'];
			if ($this->isPathSecure($request['newpath'])) $post->newpath = $request['newpath'];
			if (!empty($request['force'])) $post->force = $request['force'];
			//error_log(print_r($post, true));
		} else {
			$post->text    = stripslashes($request['text']);
			$post->updated = $request['updated'];
		}
		return $post;
	}

	/*********

		RESPONSE RENDERERS

	*********/

	public function renderResponse($response) {
		if (!empty($this->config['layout'])) {
			// TODO: Use a custom template
		} else {
			$header = array();

			if (!empty($response['options'])) {
				$header[] = '<table><tr>';
				foreach($response['options'] as $label=>$link) {
					$header[] = <<<HTML
<td><a href="{$link}">{$label}</a></td>
HTML;
				}
				$header[] = '</tr></table>';
			}
			$response['header'] = implode("\n", $header);

			if (empty($response['footer'])) {
				$response['footer'] = '';
			}

			if (empty($response['messages'])) {
				$response['messages'] = '';
			} else {
				$response['messages'] = '<table><tr><td>' . implode('</td></tr><tr><td>', $response['messages']) . '</td></tr></table>';
			}

			echo <<<PAGE
<html>
<head>
	<meta charset="UTF-8">
	<title>{$response['title']}</title>
</head>
<body>
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
		if (empty($action->model->content)) return '';
		return Markdown(
			$action->model->content,
			array($this, 'wikiLink')
		);
	}

	protected function renderPreviewDocument($action) {
		return Markdown(
			$action->post->text,
			array($this, 'wikiLink')
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
	<fieldset>
		<legend>Editing</legend>
		<label for="text">Content:</label><br>
		<textarea cols="78" rows="20" name="text" id="text">{$form['raw']}</textarea>
		<br>

		<input type="submit" name="preview" value="Preview">
		<input type="submit" name="save" value="Save">
		<input type="hidden" name="updated" value="{$form['updated']}">
	</fieldset>
</form>
HTML;
	}

	protected function renderFileList($action) {
		$directory = $this->dirname($action->model->file);
		$top = $this->dirname($action->page);
		$content[] = '<table>';
		if (file_exists($directory)) foreach (scandir($directory) as $file) {
			if ($file == '.' || $file == '..') continue;
			$content[] = '<tr><td>';
			if (is_dir("{$directory}{$file}")) {
				$content[] = '<a href="' . "{$action->base}{$top}{$file}/{$this->config['defaultPage']}?id={$top}{$file}/{$this->config['defaultPage']}" . "\">{$file}</a>";
			} else {
				$content[] = '<a href="' . "{$action->base}{$top}{$file}?id={$top}{$file}" . "\">{$file}</a>";
			}
			$content[] = '</td><td>';
			$content[] = filesize("{$directory}{$file}");
			$content[] = '</td><td>';
			$content[] = date("Y-m-d H:i:s", $this->getLastUpdated("{$directory}{$file}"));
			$content[] = '</td><td>';
			$content[] = "<a href=\"#\" onclick=\"deletePath('{$top}{$file}');return false;\">delete</a>";
			$content[] = '</td><td>';
			$content[] = "<a href=\"#\" onclick=\"renamePath('{$top}{$file}');return false;\">rename</a>";
			$content[] = '</td></tr>';
		}
		$content[] = '</table>';
		$content[] = <<<HTML
<form action="{$action->base}{$action->page}" method="post" id="rename">
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
		$top1 = $top = $this->dirname($action->page);
		if ($top1 == '') $top1 = '/';

		return <<<HTML
<form action="{$action->base}{$top}{$this->config['defaultPage']}" method="post" enctype="multipart/form-data">
	<fieldset>
		<legend>Uploading to {$top1}</legend>
		<table><tr><td>
		<label for="text">Content:</label>
		</td><td>
		<input type="file" name="text" id="text">
		</td><td>
		<input type="submit" name="upload" value="Upload">
		</td></tr></table>
	</fieldset>
</form>
HTML;
	}

}

if (!empty($_SERVER['REQUEST_URI'])) {
	# Dealing with a web request
	$wiki = new MarkdownWiki($config);
	$wiki->handleRequest();
	//error_log(print_r($wiki, true));
}

?>
