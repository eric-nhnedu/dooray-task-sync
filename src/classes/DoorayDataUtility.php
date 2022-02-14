<?php

namespace NhnEdu\DoorayTaskSync;

class DoorayDataUtility {

	public static function getProgressingWorkflow($workflows) {
		$workflows = array_filter($workflows, function($workflow) { return in_array($workflow->class, ['registered','working']); });
		$workflows = array_map(function($workflow) { return (object)['id' => $workflow->id, 'name' => $workflow->name, 'class' => $workflow->class]; }, $workflows);
		return $workflows;
	}

	public static function getWorkflowIds($workflows) {
		return array_map(function($workflow) { return $workflow->id; }, $workflows);
	}

	public static function getWorkflowId2NameMap($workflows) {
		return array_map(function($workflow) { return [$workflow->id => $workflow->name]; }, $workflows);
	}

	public static function getDoorayTaskUrl($tenantId, $taskId) {
		return 'https://'.$tenantId.'.dooray.com/project/posts/'.$taskId;
	}

	public static function getProjectTaskNumberFromSubject($subject) {
		if (preg_match('#^\[\#((.*)/(\d+))\]#iU', $subject, $m)) {
			return $m[1];
		}
		return null;
	}

	public static function getSynchronizedTaskSubject($taskNumber, $subject) {
		return '[#'.$taskNumber.'] '.$subject;
	}

}
