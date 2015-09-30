<?php

/*
 * Wizard for including a branding logo.
 */

class Branding_Start extends AddModule_Base
{

	protected function renderInternal()
	{
		Render::addScriptBottom('fileselect');
		Render::addDialog(Dictionary::translate('config-module', 'branding_title'), false, 'sysconfig/branding-start', array(
			'step' => 'Branding_ProcessFile',
			'edit' => $this->edit ? $this->edit->id() : false
		));
	}

}

class Branding_ProcessFile extends AddModule_Base
{

	private $task;
	private $svgFile;
	private $tarFile;

	protected function preprocessInternal()
	{
		$url = Request::post('url');
		if ((!isset($_FILES['file']['error']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) && empty($url)) {
			Message::addError('empty-field');
			Util::redirect('?do=SysConfig&action=addmodule&step=Branding_Start');
		}
		
		$this->svgFile = tempnam(sys_get_temp_dir(), 'bwlp-');
		if (isset($_FILES['file']['error']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
			// Prefer uploaded image over URL (in case both are given)
			if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
				Message::addError('upload-failed', Util::uploadErrorString($_FILES['file']['error']));
				Util::redirect('?do=SysConfig&action=addmodule&step=Branding_Start');
			}
			if (!move_uploaded_file($_FILES["file"]["tmp_name"], $this->svgFile)) {
				Message::addError('upload-failed', 'Moving temp file failed');
				Util::redirect('?do=SysConfig&action=addmodule&step=Branding_Start');
			}
		} else {
			// URL - launch task that fetches the SVG file from it
			if (strpos($url, '://') === false)
				$url = "http://$url";
			$title = false;
			if (!$this->downloadSvg($this->svgFile, $url, $title))
				Util::redirect('?do=SysConfig&action=addmodule&step=Branding_Start');
			Session::set('logo_name', $title);
		}
		chmod($this->svgFile, 0644);
		$this->tarFile = '/tmp/bwlp-' . time() . '-' . mt_rand() . '.tgz';
		$this->task = Taskmanager::submit('BrandingGenerator', array(
				'tarFile' => $this->tarFile,
				'svgFile' => $this->svgFile
		));
		$this->task = Taskmanager::waitComplete($this->task, 5000);
		if (Taskmanager::isFailed($this->task)) {
			@unlink($this->svgFile);
			Taskmanager::addErrorMessage($this->task);
			Util::redirect('?do=SysConfig&action=addmodule&step=Branding_Start');
		}
		Session::set('logo_tgz', $this->tarFile);
		Session::save();
	}

	protected function renderInternal()
	{
		$svg = $png = false;
		if (isset($this->task['data']['pngFile']))
			$png = base64_encode(file_get_contents($this->task['data']['pngFile']));
		if (filesize($this->svgFile) < 1000000)
			$svg = base64_encode(file_get_contents($this->svgFile));
		Render::addDialog(Dictionary::translate('config-module', 'branding_title'), false, 'sysconfig/branding-check', array(
			'png' => $png,
			'svg' => $svg,
			'error' => $this->task['data']['error'],
			'step' => 'Branding_Finish',
			'edit' => $this->edit ? $this->edit->id() : false,
			'title' => $this->edit ? $this->edit->title() : false
			)
		);
		@unlink($this->svgFile);
	}

	/**
	 * Download an svg file from the given url. This function has "wikipedia support", it tries to detect
	 * URLs in wikipedia articles or thumbnails and then find the actual svg file.
	 *
	 * @param string $svgName file to download to
	 * @param string $url url to download from
	 * @return boolean true of download succeded, false on download error (also returns true if downloaded file doesn't
	 * 		seem to be svg!)
	 */
	private function downloadSvg($svgName, $url, &$title)
	{
		$title = false;
		// [wikipedia] Did someone paste a link to a thumbnail of the svg? Let's fix that...
		if (preg_match('#^(.*)/thumb/(.*\.svg)/.*\.svg#', $url, $out)) {
			$url = $out[1] . '/' . $out[2];
		}
		for ($i = 0; $i < 5; ++$i) {
			$code = 400;
			if (!Download::toFile($svgName, $url, 3, $code) || $code < 200 || $code > 299) {
				Message::addError('remote-timeout', $url, $code);
				return false;
			}
			$content = FileUtil::readFile($svgName, 25000);
			// Is svg file?
			if (strpos($content, '<svg') !== false)
				return true; // Found an svg tag - don't try to find links to the actual image
				
			// [wikipedia] Try to be nice and detect links that might give a hint where the svg can be found
			if (preg_match_all('#href="([^"]*upload.wikimedia.org/[^"]*/[^"]*/[^"]*\.svg|[^"]+/[^"]+:[^"]+\.svg[^"]*)"#', $content, $out, PREG_PATTERN_ORDER)) {
				if ($title === false && preg_match('#<title>([^<]*)</title>#i', $content, $tout))
					$title = trim(preg_replace('/\W*Wikipedia.*/', '', $tout[1]));
				foreach ($out[1] as $res) {
					if (strpos($res, 'action=edit') !== false)
						continue;
					$new = $this->internetCombineUrl($url, html_entity_decode($res, ENT_COMPAT, 'UTF-8'));
					if ($new !== $url)
						break;
				}
				if ($new === $url)
					break;
				$url = $new;
				continue;
			}
			break;
		}
		Message::addError('no-image');
		return false;
	}

	/**
	 * Make relative url absolute.
	 *
	 * @param string $absolute absolute url to use as base
	 * @param string $relative relative url that will be converted to an absolute url
	 * @return string combined absolute url
	 */
	private function internetCombineUrl($absolute, $relative)
	{
		$p = parse_url($relative);
		if (!empty($p["scheme"]))
			return $relative;

		$parsed = parse_url($absolute);
		$path = dirname($parsed['path']);

		if ($relative{0} === '/') {
			if ($relative{1} === '/')
				return "{$parsed['scheme']}:$relative";
			$cparts = array_filter(explode("/", $relative));
		} else {
			$aparts = array_filter(explode("/", $path));
			$rparts = array_filter(explode("/", $relative));
			$cparts = array_merge($aparts, $rparts);
			foreach ($cparts as $i => $part) {
				if ($part == '.') {
					$cparts[$i] = null;
				}
				if ($part == '..') {
					$cparts[$i - 1] = null;
					$cparts[$i] = null;
				}
			}
			$cparts = array_filter($cparts);
		}
		$path = implode("/", $cparts);
		$url = "";
		if (!empty($parsed['scheme']))
			$url = $parsed['scheme'] . "://";
		if (!empty($parsed['user'])) {
			$url .= $parsed['user'];
			if (!empty($parsed['pass']))
				$url .= ":" . $parsed['pass'];
			$url .= "@";
		}
		if ($parsed['host'])
			$url .= $parsed['host'] . "/";
		$url .= $path;
		return $url;
	}

}

class Branding_Finish extends AddModule_Base
{
	
	protected function preprocessInternal()
	{
		$title = Request::post('title');
		if ($title === false || empty($title))
			$title = Session::get('logo_name');
		if ($title === false || empty($title)) {
			Message::addError('missing-title'); // TODO: Ask for title again instead of starting over
			Util::redirect('?do=SysConfig&action=addmodule&step=Branding_Start');
		}
		$tgz = Session::get('logo_tgz');
		if ($tgz === false || !file_exists($tgz)) {
			Message::addError('error-read', $tgz);
			Util::redirect('?do=SysConfig&action=addmodule&step=Branding_Start');
		}
		if ($this->edit === false)
			$module = ConfigModule::getInstance('Branding');
		else
			$module = $this->edit;
		if ($module === false) {
			Message::addError('error-read', 'branding.inc.php');
			Util::redirect('?do=SysConfig&action=addmodule&step=Branding_Start');
		}
		$module->setData('tmpFile', $tgz);
		if ($this->edit !== false)
			$ret = $module->update($title);
		else
			$ret = $module->insert($title);
		if (!$ret)
			Util::redirect('?do=SysConfig&action=addmodule&step=Branding_Start');
		elseif ($module->generate($this->edit === false, NULL, 200) === false)
			Util::redirect('?do=SysConfig&action=addmodule&step=Branding_Start');
		Session::set('logo_tgz', false);
		Session::set('logo_name', false);
		Session::save();
		// Yay
		if ($this->edit !== false)
			Message::addSuccess('module-edited');
		else
			Message::addSuccess('module-added');
		Util::redirect('?do=SysConfig');
	}

}
