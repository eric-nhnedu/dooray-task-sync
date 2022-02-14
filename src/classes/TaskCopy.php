<?php

namespace NhnEdu\DoorayTaskSync;

class TaskCopy {

	private $_originApi;
	private $_destApi;

	private $_excludeTaskNumbers = [];

	private $_originTenantId = null;
	private $_originProjectIds;
	private $_syncPeriod;

	private $_destProjectId = null;
	private $_defaultToOrganizationMemberIds = null;
	private $_defaultCcOrganizationMemberIds = null;
	private $_defaultDefaultTagIds = null;

	public function __construct($api, $tenantId, $projectIds, $excludeTaskNumbers = []) {
		$this->_originApi = $api;

		$this->_originTenantId = $tenantId;
		$this->_originProjectIds = $projectIds;

		$this->_excludeTaskNumbers = $excludeTaskNumbers;
	}
	public function setDestApi($api) {
		$this->_destApi = $api;
	}

	public function setDestProjectId($destProjectId) {
		$this->_destProjectId = $destProjectId;
	}

	public function setDefaultToMemberIds($memberIds) {
		$this->_defaultToOrganizationMemberIds = $memberIds;
	}

	public function setDefaultCcMemberIds($memberIds) {
		$this->_defaultCcOrganizationMemberIds = $memberIds;
	}

	public function setDefaultTagIds($tagIds) {
		$this->_defaultDefaultTagIds = $tagIds;
	}

	public function setSyncPeriod($searchStartDate, $searchEndDate) {
		$this->_syncPeriod = $searchStartDate.'~'.$searchEndDate;
	}

	public function copyTask($fn) {
		$filterOption = [	'createdAt'=>$this->_syncPeriod,
							'order'=>'createdAt' ];

		foreach ($this->_originProjectIds as $projectId) {
			$tasks = $this->_originApi->getAllTasks($projectId, $filterOption);

			foreach ($tasks as $task) {

				if (in_array($task->taskNumber, $this->_excludeTaskNumbers)) {
					continue;
				}
				
				$newTaskId = $this->copyTaskFrom($projectId, $task->id);

				$originTaskUrl = DoorayDataUtility::getDoorayTaskUrl($this->_originTenantId, $task->id);
				$fn($newTaskId, $task, $originTaskUrl);
			}
		}
	}

	private function copyTaskFrom($originProjectId, $originTaskId) {

		$originTask = $this->_originApi->getTask($originProjectId, $originTaskId);

		$subject = DoorayDataUtility::getSynchronizedTaskSubject($originTask->taskNumber, $originTask->subject);

		$result = $this->_destApi->postTask($this->_destProjectId,
											$subject, 
											$originTask->body->mimeType,
											$originTask->body->content,
											$this->_defaultToOrganizationMemberIds,
											$this->_defaultCcOrganizationMemberIds,
											null,
											$this->_defaultDefaultTagIds);

		$newTaskId = $result->id;

		if ($originTask->workflowClass == 'closed') {
			$this->_destApi->setPostDone($this->_destProjectId, $newTaskId);
		}

		return $newTaskId;
	}

}