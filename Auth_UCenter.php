<?php
/**
 * Authentication plugin interface
 *
 * Copyright © 2004 Brion Vibber <brion@pobox.com>
 * http://www.mediawiki.org/
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

error_reporting(E_ALL); // Debug
include './extensions/Auth_UCenter/config.inc.php';
include './extensions/Auth_UCenter/uc_client/client.php';

if (!class_exists('AuthPlugin')) {
	require_once './includes/AuthPlugin.php';
}

/**
 * Authentication plugin interface. Instantiate a subclass of AuthPlugin
 * and set $wgAuth to it to authenticate against some external tool.
 *
 * The default behavior is not to do anything, and use the local user
 * database for all authentication. A subclass can require that all
 * accounts authenticate externally, or use it only as a fallback; also
 * you can transparently create internal wiki accounts the first time
 * someone logs in who can be authenticated externally.
 */
class Auth_UCenter extends AuthPlugin {

	private function connectToDB() {
		global $wgAuthUCenterDBCharset;
		global $wgAuthUCenterDBHost;
		global $wgAuthUCenterDBName;
		global $wgAuthUCenterDBUser;
		global $wgAuthUCenterDBPassword;
		$connection = @mysql_connect(	$wgAuthUCenterDBHost,
						$wgAuthUCenterDBUser,
						$wgAuthUCenterDBPassword,
						true	);
		//if ((mysql_get_server_info() >= 4.1) && (isset($wgAuthUCenterDBCharset))) {
		//}
		$db = mysql_select_db($wgAuthUCenterDBName, $connection);
		return $connection;
	}

	private function processUsername( $username ) {
		global $wgAuthUCenterServerCharset;
		$username = str_replace(' ', '_', $username);
		$username = htmlspecialchars(strtolower($username), ENT_QUOTES, 'UTF-8');
		if (isset($wgAuthUCenterServerCharset) && (strcasecmp($wgAuthUCenterServerCharset, 'UTF-8') != 0)) {
			$username = iconv('UTF-8', $wgAuthUCenterServerCharset, $username);
		}
		return $username;
	}

	private function checkPermission( $uid ) {
		if (!$this->checkGroup($uid)) {
			return false;
		}
		return $this->checkAdmin($uid) || $this->checkCredits($uid);
	}

	private function checkAdmin( $uid ) {
		if ($this->queryAdminID($uid) > 0) {
			return true;
		}
		else {
			return false;
		}
	}

	private function checkGroup( $uid ) {
		global $wgAuthUCenterBannedGroup;
		if (isset($wgAuthUCenterBannedGroup)) {
			$group = $this->queryGroupId($uid);
			foreach ($wgAuthUCenterBannedGroup as $banned) {
				if ($banned == $group) {
					return false;
				}
			}
			return true;
		}
		else {
			return true;
		}
	}

	private function checkCredits( $uid ) {
		global $wgAuthUCenterCreditsLimit;
		if (isset($wgAuthUCenterCreditsLimit) && $wgAuthUCenterCreditsLimit > 0) {
			//$credit = uc_user_getcredit($uid, 1, 1);
			$credit = $this->queryCredits($uid);
			return $credit >= $wgAuthUCenterCreditsLimit;
		}
		else {
			return true;
		}
	}

	private function queryAdminId( $uid ) {
		return $this->queryMember($uid, 'adminid');
	}

	private function queryGroupId( $uid ) {
		return $this->queryMember($uid, 'groupid');
	}

	private function queryCredits( $uid ) {
		return $this->queryMember($uid, 'credits');
	}

	private function queryMember( $uid, $field='' ) {
		global $wgAuthUCenterDBTablePre;
		$connection = $this->connectToDB();
		$query = 	"SELECT `$field` ".
				"FROM ${wgAuthUCenterDBTablePre}common_member ".
				"WHERE `uid` = '$uid' ".
				"LIMIT 1";
		$row = mysql_query($query, $connection);
		while ($row = mysql_fetch_array($row)) {
			if ($field == '') {
				$result = $row;
			}
			else {
				$result = $row[$field];
			}
		}
		return $result;
	}
		
	public function fillUser( &$user ) {
		$username = $this->processUsername($user->getName());
		if ($data = uc_get_user($username)) {
			list($uc_uid, $uc_username, $uc_email) = $data;
			$user->setEmail($uc_email);
		}
		else {
			echo "No such user.";
			return false;
		}
		$user->removeGroup("user");
		$user->removeGroup("autoconfirmed");
		$user->removeGroup("sysop");
		$admin = $this->queryAdminId($uc_uid);
		if ($admin > 0) {
			$user->addGroup("sysop");
		}
		if ($admin == 1) {
			$user->addGroup("bureaucrat");
		}
		$user->saveSettings();
		return true;
	}

	/**
	 * @var string
	 */
	protected $domain;

	/**
	 * Check whether there exists a user account with the given name.
	 * The name will be normalized to MediaWiki's requirements, so
	 * you might need to munge it (for instance, for lowercase initial
	 * letters).
	 *
	 * @param $username String: username.
	 * @return bool
	 */
	public function userExists( $username ) {
		$username = $this->processUsername($username);
		if ($data = uc_get_user($username)) {
			list($uc_uid, $uc_username, $uc_email) = $data;
			return $this->checkPermission($uc_uid);
		}
		else {
			return false;
		}
	}

	/**
	 * Check if a username+password pair is a valid login.
	 * The name will be normalized to MediaWiki's requirements, so
	 * you might need to munge it (for instance, for lowercase initial
	 * letters).
	 *
	 * @param $username String: username.
	 * @param $password String: user password.
	 * @return bool
	 */
	public function authenticate( $username, $password ) {
		$username = $this->processUsername($username);
		list($uc_uid, $uc_username, $uc_password, $uc_email) = uc_user_login($username, $password);
		if ($uc_uid > 0) {
			echo uc_user_synlogin($uc_uid);
			return $this->checkPermission($uc_uid);
		}
		else if ($uc_uid == -1) {
			//echo "No such user.";
			return false;
		}
		else if ($uc_uid == -2) {
			//echo "Password error.";
			return false;
		}
		else {
			//echo "Unknown error.";
			return false;
		}
	}

	/**
	 * Modify options in the login template.
	 *
	 * @param $template UserLoginTemplate object.
	 * @param $type String 'signup' or 'login'. Added in 1.16.
	 */
	public function modifyUITemplate( &$template, &$type ) {
		# Override this!
		$template->set( 'usedomain', false );
	}

	/**
	 * Set the domain this plugin is supposed to use when authenticating.
	 *
	 * @param $domain String: authentication domain.
	 */
	public function setDomain( $domain ) {
		$this->domain = $domain;
	}

	/**
	 * Get the user's domain
	 *
	 * @return string
	 */
	public function getDomain() {
		if ( isset( $this->domain ) ) {
			return $this->domain;
		} else {
			return 'invaliddomain';
		}
	}

	/**
	 * Check to see if the specific domain is a valid domain.
	 *
	 * @param $domain String: authentication domain.
	 * @return bool
	 */
	public function validDomain( $domain ) {
		return true;
	}

	/**
	 * When a user logs in, optionally fill in preferences and such.
	 * For instance, you might pull the email address or real name from the
	 * external user database.
	 *
	 * The User object is passed by reference so it can be modified; don't
	 * forget the & on your function declaration.
	 *
	 * @param $user User object
	 * @return bool
	 */
	public function updateUser( &$user ) {
		return $this->fillUser($user);
	}

	/**
	 * Return true if the wiki should create a new local account automatically
	 * when asked to login a user who doesn't exist locally but does in the
	 * external auth database.
	 *
	 * If you don't automatically create accounts, you must still create
	 * accounts in some way. It's not possible to authenticate without
	 * a local account.
	 *
	 * This is just a question, and shouldn't perform any actions.
	 *
	 * @return Boolean
	 */
	public function autoCreate() {
		return true;
	}

	/**
	 * Allow a property change? Properties are the same as preferences
	 * and use the same keys. 'Realname' 'Emailaddress' and 'Nickname'
	 * all reference this.
	 *
	 * @param $prop string
	 *
	 * @return Boolean
	 */
	public function allowPropChange( $prop = '' ) {
		if ( $prop == 'realname' && is_callable( array( $this, 'allowRealNameChange' ) ) ) {
			return $this->allowRealNameChange();
		} elseif ( $prop == 'emailaddress' && is_callable( array( $this, 'allowEmailChange' ) ) ) {
			return $this->allowEmailChange();
		} elseif ( $prop == 'nickname' && is_callable( array( $this, 'allowNickChange' ) ) ) {
			return $this->allowNickChange();
		} else {
			return true;
		}
	}

	/**
	 * Can users change their passwords?
	 *
	 * @return bool
	 */
	public function allowPasswordChange() {
		return false;
	}

	/**
	 * Should MediaWiki store passwords in its local database?
	 *
	 * @return bool
	 */
	public function allowSetLocalPassword() {
		return false;
	}

	/**
	 * Set the given password in the authentication database.
	 * As a special case, the password may be set to null to request
	 * locking the password to an unusable value, with the expectation
	 * that it will be set later through a mail reset or other method.
	 *
	 * Return true if successful.
	 *
	 * @param $user User object.
	 * @param $password String: password.
	 * @return bool
	 */
	public function setPassword( $user, $password ) {
		return true;
	}

	/**
	 * Update user information in the external authentication database.
	 * Return true if successful.
	 *
	 * @param $user User object.
	 * @return Boolean
	 */
	public function updateExternalDB( $user ) {
		return true;
	}

	/**
	 * Check to see if external accounts can be created.
	 * Return true if external accounts can be created.
	 * @return Boolean
	 */
	public function canCreateAccounts() {
		return false;
	}

	/**
	 * Add a user to the external authentication database.
	 * Return true if successful.
	 *
	 * @param $user User: only the name should be assumed valid at this point
	 * @param $password String
	 * @param $email String
	 * @param $realname String
	 * @return Boolean
	 */
	public function addUser( $user, $password, $email = '', $realname = '' ) {
		return true;
	}

	/**
	 * Return true to prevent logins that don't authenticate here from being
	 * checked against the local database's password fields.
	 *
	 * This is just a question, and shouldn't perform any actions.
	 *
	 * @return Boolean
	 */
	public function strict() {
		return false;
	}

	/**
	 * Check if a user should authenticate locally if the global authentication fails.
	 * If either this or strict() returns true, local authentication is not used.
	 *
	 * @param $username String: username.
	 * @return Boolean
	 */
	public function strictUserAuth( $username ) {
		return true;
	}

	/**
	 * When creating a user account, optionally fill in preferences and such.
	 * For instance, you might pull the email address or real name from the
	 * external user database.
	 *
	 * The User object is passed by reference so it can be modified; don't
	 * forget the & on your function declaration.
	 *
	 * @param $user User object.
	 * @param $autocreate Boolean: True if user is being autocreated on login
	 */
	public function initUser( &$user, $autocreate = false ) {
		$this->fillUser($user);
	}

	/**
	 * If you want to munge the case of an account name before the final
	 * check, now is your chance.
	 * @param $username string
	 * @return string
	 */
	public function getCanonicalName( $username ) {
		return $username;
	}

	/**
	 * Get an instance of a User object
	 *
	 * @param $user User
	 *
	 * @return AuthPluginUser
	 */
	/*
	public function getUserInstance( User &$user ) {
		return new AuthPluginUser( $user );
	}
	*/

	/**
	 * Get a list of domains (in HTMLForm options format) used.
	 *
	 * @return array
	 */
	public function domainList() {
		return array();
	}
}

class AuthUCenterUser extends AuthPluginUser {
	function __construct( $user ) {
		# Override this!
	}

	public function getId() {
		# Override this!
		return -1;
	}

	public function isLocked() {
		# Override this!
		return false;
	}

	public function isHidden() {
		# Override this!
		return false;
	}

	public function resetAuthToken() {
		# Override this!
		return true;
	}
}
