<?php
/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class tx_igldapssoauth_typo3_user for the 'ig_ldap_sso_auth' extension.
 *
 * @author     Xavier Perseguers <xavier@typo3.org>
 * @author     Michael Gagnon <mgagnon@infoglobe.ca>
 * @package    TYPO3
 * @subpackage ig_ldap_sso_auth
 */
class tx_igldapssoauth_typo3_user {

	/**
	 * Creates a fresh BE/FE user record.
	 *
	 * @param string $table Either 'be_users' or 'fe_users'
	 * @return array
	 * @throws \RuntimeException
	 */
	static public function create($table) {
		if (!GeneralUtility::inList('be_users,fe_users', $table)) {
			throw new \RuntimeException('Invalid table "' . $table . '"', 1404891582);
		}

		$newUser = array();
		$fieldsConfiguration = self::getDatabaseConnection()->admin_get_fields($table);

		foreach ($fieldsConfiguration as $field => $configuration) {
			if ($configuration['Null'] === 'NO' && $configuration['Default'] === NULL) {
				$newUser[$field] = '';
			} else {
				$newUser[$field] = $configuration['Default'];
			}
		}

		return $newUser;
	}

	/**
	 * Searches BE/FE users either by uid or by DN (or username)
	 * in a given storage folder (pid).
	 *
	 * @param string $table Either 'be_users' or 'fe_users'
	 * @param int $uid
	 * @param int $pid
	 * @param string $username
	 * @param string $dn
	 * @return array Array of user records
	 * @throws \RuntimeException
	 */
	static public function fetch($table, $uid = 0, $pid = 0, $username = NULL, $dn = NULL) {
		if (!GeneralUtility::inList('be_users,fe_users', $table)) {
			throw new \RuntimeException('Invalid table "' . $table . '"', 1404891636);
		}

		$users = array();
		$databaseConnection = self::getDatabaseConnection();

		if ($uid) {
			// Search with uid
			$users = $databaseConnection->exec_SELECTgetRows(
				'*',
				$table,
				'uid=' . intval($uid)
			);
		} elseif (!empty($dn)) {
			// Search with DN (or fall back to username) and pid
			$where = '(' . 'tx_igldapssoauth_dn=' . $databaseConnection->fullQuoteStr($dn, $table);
			if (!empty($username)) {
				// This additional condition will automatically add the mapping between
				// a local user unrelated to LDAP and a corresponding LDAP user
				$where .= ' OR username=' . $databaseConnection->fullQuoteStr($username, $table);
			}
			$where .= ')' . ($pid ? ' AND pid=' . intval($pid) : '');

			$users = $databaseConnection->exec_SELECTgetRows(
				'*',
				$table,
				$where,
				'',
				'tx_igldapssoauth_dn DESC, deleted ASC'	// rows from LDAP first, then privilege active records
			);
		} elseif (!empty($username)) {
			// Search with username and pid
			$users = $databaseConnection->exec_SELECTgetRows(
				'*',
				$table,
				'username=' . $databaseConnection->fullQuoteStr($username, $table)
					. ($pid ? ' AND pid=' . intval($pid) : '')
			);
		}

		// Return TYPO3 users.
		return $users;
	}

	/**
	 * Adds a new BE/FE user to the database and returns the new record
	 * with all columns.
	 *
	 * @param string $table Either 'be_users' or 'fe_users'
	 * @param array $data
	 * @return array The new record
	 * @throws \RuntimeException
	 */
	static public function add($table, array $data = array()) {
		if (!GeneralUtility::inList('be_users,fe_users', $table)) {
			throw new \RuntimeException('Invalid table "' . $table . '"', 1404891712);
		}

		$databaseConnection = self::getDatabaseConnection();

		$databaseConnection->exec_INSERTquery(
			$table,
			$data,
			FALSE
		);
		$uid = $databaseConnection->sql_insert_id();

		$newRow = $databaseConnection->exec_SELECTgetSingleRow(
			'*',
			$table,
			'uid=' . intval($uid)
		);

		Tx_IgLdapSsoAuth_Utility_Notification::dispatch(
			__CLASS__,
			'userAdded',
			array(
				'table' => $table,
				'user' => $newRow,
			)
		);

		return $newRow;
	}

	/**
	 * Updates a BE/FE user in the database and returns a success flag.
	 *
	 * @param string $table Either 'be_users' or 'fe_users'
	 * @param array $data
	 * @return bool TRUE on success, otherwise FALSE
	 * @throws \Exception
	 */
	static public function update($table, array $data = array()) {
		if (!GeneralUtility::inList('be_users,fe_users', $table)) {
			throw new \RuntimeException('Invalid table "' . $table . '"', 1404891732);
		}

		$databaseConnection = self::getDatabaseConnection();

		$cleanData = $data;
		unset($cleanData['__extraData']);

		$databaseConnection->exec_UPDATEquery(
			$table,
			'uid=' . intval($data['uid']),
			$cleanData,
			FALSE
		);
		$success = $databaseConnection->sql_errno() == 0;

		if ($success) {
			Tx_IgLdapSsoAuth_Utility_Notification::dispatch(
				__CLASS__,
				'userUpdated',
				array(
					'table' => $table,
					'user' => $data,
				)
			);
		}

		return $success;
	}

	/**
	 * Disables all users for a given LDAP configuration.
	 *
	 * This method is meant to be called before a full synchronization, so that existing users which are not
	 * updated will be marked as disabled.
	 *
	 * @param $table
	 * @param $uid
	 */
	static public function disableForConfiguration($table, $uid) {
		if (isset($GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled'])) {
			$fields = array(
				$GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled'] => 1
			);
			if (isset($GLOBALS['TCA'][$table]['ctrl']['tstamp'])) {
				$fields[$GLOBALS['TCA'][$table]['ctrl']['tstamp']] = $GLOBALS['EXEC_TIME'];
			}
			self::getDatabaseConnection()->exec_UPDATEquery(
				$table,
				'tx_igldapssoauth_id = ' . intval($uid),
				$fields
			);

			Tx_IgLdapSsoAuth_Utility_Notification::dispatch(
				__CLASS__,
				'userDisabled',
				array(
					'table' => $table,
					'configuration' => $uid,
				)
			);
		}
	}

	/**
	 * Deletes all users for a given LDAP configuration.
	 *
	 * This method is meant to be called before a full synchronization, so that existing users which are not
	 * updated will be marked as deleted.
	 *
	 * @param $table
	 * @param $uid
	 */
	static public function deleteForConfiguration($table, $uid) {
		if (isset($GLOBALS['TCA'][$table]['ctrl']['delete'])) {
			$fields = array(
				$GLOBALS['TCA'][$table]['ctrl']['delete'] => 1
			);
			if (isset($GLOBALS['TCA'][$table]['ctrl']['tstamp'])) {
				$fields[$GLOBALS['TCA'][$table]['ctrl']['tstamp']] = $GLOBALS['EXEC_TIME'];
			}
			self::getDatabaseConnection()->exec_UPDATEquery(
				$table,
				'tx_igldapssoauth_id = ' . intval($uid),
				$fields
			);

			Tx_IgLdapSsoAuth_Utility_Notification::dispatch(
				__CLASS__,
				'userDeleted',
				array(
					'table' => $table,
					'configuration' => $uid,
				)
			);
		}
	}

	static public function set_usergroup(array $typo3_groups = array(), array $typo3_user = array(), tx_igldapssoauth_sv1 $pObj = NULL, $groupTable = '') {
		$group_uid = array();

		foreach ($typo3_groups as $typo3_group) {
			if ($typo3_group['uid']) {
				$group_uid[] = $typo3_group['uid'];
			}
		}

		// If group table is not explicitly defined, try to get it from context
		if (empty($groupTable)) {
			if (isset($pObj)) {
				$groupTable = $pObj->authInfo['db_groups']['table'];
			} else {
				if (TYPO3_MODE === 'BE') {
					$groupTable = 'be_groups';
				} else {
					$groupTable = 'fe_groups';
				}
			}
		}
		$assignGroups = GeneralUtility::intExplode(',', tx_igldapssoauth_config::is_enable('assignGroups'), TRUE);
		foreach ($assignGroups as $uid) {
			if (tx_igldapssoauth_typo3_group::fetch($groupTable, $uid) && !in_array($uid, $group_uid)) {
				$group_uid[] = $uid;
			}
		}

		if (tx_igldapssoauth_config::is_enable('keepTYPO3Groups') && $typo3_user['usergroup']) {
			$usergroup = GeneralUtility::intExplode(',', $typo3_user['usergroup'], TRUE);

			foreach ($usergroup as $uid) {
				if (!in_array($uid, $group_uid)) {
					$group_uid[] = $uid;
				}
			}
		}

		$updateAdminAttribForGroups = tx_igldapssoauth_config::is_enable('updateAdminAttribForGroups');
		if ($updateAdminAttribForGroups) {
			$updateAdminAttribForGroups = GeneralUtility::trimExplode(',', $updateAdminAttribForGroups);
			$typo3_user['admin'] = 0;
			foreach ($updateAdminAttribForGroups as $uid) {
				if (in_array($uid, $group_uid)) {
					$typo3_user['admin'] = 1;
					break;
				}
			}
		}

		$typo3_user['usergroup'] = implode(',', $group_uid);

		return $typo3_user;
	}

	/**
	 * Processes the username according to current configuration.
	 *
	 * @param string $username
	 * @return string
	 */
	static public function setUsername($username) {
		if (tx_igldapssoauth_config::is_enable('forceLowerCaseUsername')) {
			// Possible enhancement: use t3lib_cs::conv_case instead
			$username = strtolower($username);
		}
		return $username;
	}

	/**
	 * Defines a random password.
	 *
	 * @return string
	 */
	static public function setRandomPassword() {
		/** @var \TYPO3\CMS\Saltedpasswords\Salt\SaltInterface $instance */
		$instance = NULL;
		if (\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('saltedpasswords')) {
			$instance = \TYPO3\CMS\Saltedpasswords\Salt\SaltFactory::getSaltingInstance(NULL, TYPO3_MODE);
		}
		$password = GeneralUtility::generateRandomBytes(16);
		$password = $instance ? $instance->getHashedPassword($password) : md5($password);
		return $password;
	}

	/**
	 * Returns the database connection.
	 *
	 * @return \TYPO3\CMS\Core\Database\DatabaseConnection
	 */
	static protected function getDatabaseConnection() {
		return $GLOBALS['TYPO3_DB'];
	}

}
