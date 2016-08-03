<?php

class Page_mail_templates extends Page
{

	private $templates = [];

	protected function doPreprocess()
	{
		User::load();

		if (!User::hasPermission('superadmin')) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		$action = Request::get('action', 'show', 'string');

		if ($action === 'show') {
			$this->fetchTemplates();
		} elseif ($action === 'save') {
			$this->handleSave();
		}
	}

	private function enrichHtml() {
		/* for each template */
		foreach ($this->templates as $k => $t) {
			$lis = "";
			$optManVars = "";
			$optVars = "";
			foreach ($t['mandatory_variables'] as $var) {
				$optManVars .= "<option selected=\"selected\" value=\"$var\">$var</option>";
				$lis .= "<li><strong>$var</strong></li>";
			}
			foreach($t['optional_variables'] as $var) {
				$optVars .= "<option selected=\"selected\" value=\"$var\">$var</option>";
				$lis .= "<li>$var</li>";
			}
			/* also options for hidden inputs */

			$this->templates[$k]['html_availableVariables'] = $lis;
			$this->templates[$k]['html_mandatoryVariables'] = $optManVars;
			$this->templates[$k]['html_optionalVariables'] 	= $optVars;

			/* also for javascript */
			$this->templates[$k]['list_mandatoryVariables'] =
				implode(',', $this->templates[$k]['mandatory_variables']);

			$this->templates[$k]['list_optionalVariables'] =
				implode(',', $this->templates[$k]['optional_variables']);
		}

	}
	protected function doRender()
	{
		//echo '<pre>';
		//var_dump($this->templates);
		//echo '</pre>';
		//die();
		$this->enrichHtml();
		Render::addTemplate('templates', ['templates' => $this->templates]);
	}

	private function handleSave() {
		$data = [];
		$data['templates'] = Request::post('templates');
		$data = $this->cleanTemplateArray($data);
		if ($data!= NULL) {
			$data = json_encode($data, JSON_PRETTY_PRINT);
			//echo '<pre>';
			//print_r($data);
			//echo '</pre>';
			//die();
			Database::exec("UPDATE sat.configuration SET value = :value WHERE parameter = 'templates'", array('value' => $data));
			Message::addSuccess('templates-saved');

			Util::redirect('?do=dozmod&section=templates&action=show');
		} else {
			die('error while encoding');
		}

	}

	private function fetchTemplates() {
		$templates= Database::queryFirst('SELECT value FROM sat.configuration WHERE parameter = :param', array('param' => 'templates'));
		if ($templates != null) {
			$templates = @json_decode($templates['value'], true);
			if (is_array($templates)) {
				$this->templates = $templates['templates'];
			}
		}

	}

	private function cleanTemplateArray($in) {
		$out = [];
		foreach ($in['templates'] as $t) {
			$tcopy = $t;
			$tcopy['mandatory_variables'] = $this->toArray($t['mandatory_variables']);
			$tcopy['optional_variables'] = $this->toArray($t['optional_variables']);
			$tcopy['description'] = $t['description'];
			$tcopy['name'] = $t['name'];

			$out['templates'][] = $tcopy;
		}
		return $out;
	}

	private function toArray($value) {
		if (empty($value)) {
			return [];
		} else if(is_array($value)) {
			return $value;
		} else {
			return array($value);
		}
	}
}
