<?php

namespace NhnEdu\DoorayTaskSync;

use NhnEdu\PhpDooray\DoorayCommonApi;
use NhnEdu\PhpDooray\DoorayProjectApi;
use NhnEdu\PhpDooray\DoorayMessengerHelper;

define('SEARCH_TODAY_DATE', date('Y-m-d').'T23:59:59');

class SyncTask {

	private $_configFilePath;

	private $_orgProjectApi;

	private $_destProjectApi;
	private $_destCommonApi;

	private $_cache;
	private $_synchronizedTaskIds;

	private $_taskCopy;

	public function __construct($configFilePath = 'config.json') {

		$this->_cache = new LocalCache();
		$this->_configFilePath = $configFilePath;

		$this->loadConfig();
		$this->initDoorayApi();
		$this->loadDestinationMembers();
		$this->initTaskCopy();
	}

	private function loadConfig() {
		$config = ConfigParser::getConfig($this->_configFilePath);

		if (!$config) {
			die("[Error] Configuration is not loaded.");
		}

		$this->_cache->putData("ORIGIN_AUTH_KEY", $config->ORIGIN_AUTH_KEY);
		$this->_cache->putData("ORIGIN_TENANT_ID", $config->ORIGIN_TENANT_ID);
		$this->_cache->putData('ORIGIN_PROJECT_IDS', $config->ORIGIN_PROJECT_IDS);

		$this->_cache->putData("DESTINATION_AUTH_KEY", $config->DESTINATION_AUTH_KEY);
		$this->_cache->putData("DESTINATION_TENANT_ID", $config->DESTINATION_TENANT_ID);

		$this->_cache->putData('DESTINATION_PROJECT_ID', $config->DESTINATION_PROJECT_ID);
		$this->_cache->putData('DESTINATION_PROJECT_TO_EMAILS', $config->DESTINATION_PROJECT_TO_EMAILS);
		$this->_cache->putData('DESTINATION_PROJECT_CC_EMAILS', $config->DESTINATION_PROJECT_CC_EMAILS);

		$this->_cache->putData('NEW_TASK_TAG_NAMES', $config->NEW_TASK_TAG_NAMES);

		$this->_cache->putData('SYNC_START_DATE', $config->SYNC_START_DATE);
		$this->_cache->putData('SYNC_END_DATE', str_replace('{today}',SEARCH_TODAY_DATE, $config->SYNC_END_DATE));

		$this->_cache->putData('CLONE_LOG_MESSAGE', $config->CLONE_LOG_MESSAGE);
	}

	private function initDoorayApi() {
		$this->_orgProjectApi = new DoorayProjectApi($this->_cache->getData('ORIGIN_AUTH_KEY'));

		$this->_destProjectApi = new DoorayProjectApi($this->_cache->getData('DESTINATION_AUTH_KEY'));
		$this->_destCommonApi = new DoorayCommonApi($this->_cache->getData('DESTINATION_AUTH_KEY'));
	}

	private function loadDestinationMembers() {
		$emails = array_merge($this->_cache->getData('DESTINATION_PROJECT_TO_EMAILS'), $this->_cache->getData('DESTINATION_PROJECT_CC_EMAILS'));

		$this->_cache->putData('memberId2organizationMemberId', []);

		if (sizeof($emails) < 1) {
			$this->_cache->putData('memberEmail2Ids', []);
			return ;
		}

		$members = $this->_destCommonApi->getMembers(0, 100, ['externalEmailAddresses' => $emails]);
		$memberEmailAndIds = array_map(function($member) { return [$member->externalEmailAddress, $member->id]; }, $members);
		$memberEmail2Ids = [];
		foreach ($memberEmailAndIds as $emailAndId) {
			$memberEmail2Ids[$emailAndId[0]] = $emailAndId[1];
		}

		$this->_cache->putData('memberEmail2Ids', $memberEmail2Ids);
	}

	public function loadSynchronizedTaskInfo() {
		$destProjectId = $this->_cache->getData('DESTINATION_PROJECT_ID');
		$destTasks = $this->_destProjectApi->getAllTasks($destProjectId, ['order'=>'createdAt']);
		
		$this->_synchronizedTaskIds = [];
		foreach ($destTasks as $destTask) {
			$originTaskNumber = DoorayDataUtility::getProjectTaskNumberFromSubject($destTask->subject);
			if (is_null($originTaskNumber)) {
				continue;
			}
			$this->_synchronizedTaskIds[] = $originTaskNumber;
		}
	}

	private function initTaskCopy() {

		$this->loadSynchronizedTaskInfo();
		
		$destProjectId = $this->_cache->getData('DESTINATION_PROJECT_ID');

		$toOrganizationMemberIds = $this->getDestinationProjectMembers($destProjectId, 'TO');
		$ccOrganizationMemberIds = $this->getDestinationProjectMembers($destProjectId, 'CC');
		$tagIds = $this->getDestinationProjectTagIds($destProjectId);

		$this->_taskCopy = new TaskCopy(	$this->_orgProjectApi,
											$this->_cache->getData('ORIGIN_TENANT_ID'),
											$this->_cache->getData('ORIGIN_PROJECT_IDS'),
											$this->_synchronizedTaskIds
										);


		$this->_taskCopy->setDestApi($this->_destProjectApi);
		$this->_taskCopy->setDestProjectId($destProjectId);
		$this->_taskCopy->setDefaultToMemberIds($toOrganizationMemberIds);
		$this->_taskCopy->setDefaultCcMemberIds($ccOrganizationMemberIds);
		$this->_taskCopy->setDefaultTagIds($tagIds);
	}

	public function autoSync() {
		if (file_exists('.all_sync.lock')) {
			$this->syncLast3Days();
		} else {
			$this->allSync();
		}
	}

	public function syncLast3Days() {

		$endDate = $this->_cache->getData('SYNC_END_DATE');
		$ago3days = date('Y-m-d', strtotime('-3day',strtotime($endDate))).'T00:00:00+'.substr($endDate,-5);

		$this->_taskCopy->setSyncPeriod($this->_cache->getData('SYNC_START_DATE'), $endDate);

		$this->_taskCopy->copyTask(function($newTaskId, $originTask, $originTaskUrl) {
			$this->writeLogAfterCopy($newTaskId, $originTaskUrl);
			echo '[Sync] '.$originTask->taskNumber.'('.$originTask->id.') --> '.$newTaskId.PHP_EOL;
		});
	}

	public function allSync() {

		$this->_taskCopy->setSyncPeriod($this->_cache->getData('SYNC_START_DATE'),
										$this->_cache->getData('SYNC_END_DATE'));

		$this->_taskCopy->copyTask(function($newTaskId, $originTask, $originTaskUrl) {
			$this->writeLogAfterCopy($newTaskId, $originTaskUrl);
			echo '[Sync] '.$originTask->taskNumber.'('.$originTask->id.') --> '.$newTaskId.PHP_EOL;
		});

		file_put_contents('.all_sync.lock', date('Y-m-d H:i:s'));
	}

	private function getMemberIdByEmail($projectId, $email) {
		$memberEmail2Ids = $this->_cache->getData('memberEmail2Ids');
		if (!isset($memberEmail2Ids[$email])) {
			return null;
		}

		$memberId = $memberEmail2Ids[$email];

		$memberId2organizationMemberId = $this->_cache->getData('memberId2organizationMemberId');

		if (isset($memberId2organizationMemberId[$memberId])) {
			return $memberId2organizationMemberId[$memberId];
		}

		$memberId2organizationMemberId[$memberId] = $this->_destProjectApi->getOrganizationMemberIdByProjectMember($projectId, $memberId);
		$this->_cache->putData('memberId2organizationMemberId', $memberId2organizationMemberId);

		return $memberId2organizationMemberId[$memberId];
	}

	private function getDestinationProjectMembers($projectId, $targetType = 'TO') {
		$emails = $this->_cache->getData('DESTINATION_PROJECT_'.strtoupper($targetType).'_EMAILS');
		$organizationMemberIds = [];
		foreach ($emails as $email) {
			$organizationMemberId = $this->getMemberIdByEmail($projectId, $email);
			$organizationMemberIds[] = ["type"=>"member", "member" => ["organizationMemberId" => $organizationMemberId] ];
		}
		return $organizationMemberIds;
	}

	private function getDestinationProjectTagIds($projectId) {
		$destTags = $this->_destProjectApi->getProjectTags($projectId);
		$tagNames = $this->_cache->getData('NEW_TASK_TAG_NAMES');
		
		$tagIds = [];
		foreach ($destTags as $tag) {
			if (in_array($tag->name, $tagNames)) {
				$tagIds[] = $tag->id;
			}
		}

		return $tagIds;
	}

	private function writeLogAfterCopy($taskId, $originTaskUrl) {
		$destProjectId = $this->_cache->getData('DESTINATION_PROJECT_ID');
		$cloneMsg = $this->_cache->getData('CLONE_LOG_MESSAGE');
		$cloneMsg = str_replace('{url}', $originTaskUrl, $cloneMsg);
		$this->_destProjectApi->postLog($destProjectId, $taskId, $cloneMsg);
	}

}
