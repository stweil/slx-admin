<?php

class Page_mail_templates extends Page
{

	private $templates = [];

	protected function doPreprocess()
	{
		$action = Request::post('action', 'show', 'string');
		if ($action === 'show') {
			$this->fetchTemplates();
		} elseif ($action === 'save') {
			$this->handleSave();
		} elseif ($action === 'reset') {
			$this->handleReset();
		} else {
			Message::addError('main.invalid-action', $action);
			Util::redirect('?do=dozmod&section=templates');
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
		$this->enrichHtml();
		Render::addTemplate('templates', ['templates' => $this->templates]);
	}

	private function handleSave() {
		$data = Request::post('templates');
		if (is_array($data)) {
			$this->fetchTemplates();
			foreach ($this->templates as &$template) {
				if (isset($data[$template['name']])) {
					$template['template'] = $data[$template['name']]['template'];
				}
			}
			unset($template);
			$data = json_encode(array('templates' => $this->templates));
			Database::exec("UPDATE sat.configuration SET value = :value WHERE parameter = 'templates'", array('value' => $data));
			Message::addSuccess('templates-saved');

		} else {
			Message::addError('nothing-submitted');
		}
		Util::redirect('?do=dozmod&section=templates');
	}

	private function handleReset()
	{
		$result = Download::asStringPost('http://127.0.0.1:9080/do/reset-mail-templates', array(), 10, $code);
		if ($code == 999) {
			Message::addError('timeout');
		} elseif ($code != 200) {
			Message::addError('dozmod-error', $code);
		} else {
			Message::addSuccess('all-templates-reset', $result);
		}
		Util::redirect('?do=dozmod&section=templates');
	}

	private function fetchTemplates() {
		$templates= Database::queryFirst('SELECT value FROM sat.configuration WHERE parameter = :param', array('param' => 'templates'));
		if ($templates != null) {
			$templates = @json_decode($templates['value'], true);
			if (is_array($templates)) {
				$names = array_map(function ($e) { return $e['name']; }, $templates['templates']);
				array_multisort($names, SORT_ASC, $templates['templates']);
				$this->templates = $templates['templates'];
			}
		}

	}

}
