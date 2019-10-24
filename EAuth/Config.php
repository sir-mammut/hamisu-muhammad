<?php

namespace EAuth;

/**
* EAuth config class
*/
class Config
{
	protected $pdo;
	public $config;
	public $config_table = 'e_auth_config';

	/**
	* Config::__construct()
	*
	* Create a config class for EAuth\Auth
	* Examples:
	*
	*
	* @param \PDO $pdo
	* @param string $config_source -- declare source of config - table name, filepath or data-array
	* @param string $config_type -- default empty (means config in SQL table phpauth_config), possible values: 'sql', 'ini', 'array'
	* @param string $config_site_language -- declare site language, empty value means 'en_GB'
	*/
	
	public function __construct(\PDO $pdo, $config_source = NULL, $config_type = '', $config_site_language = '')
	{
		$config_type = strtolower($config_type);

		if (version_compare(phpversion(), '5.6.0', '<')) {
			die('EAuth: PHP version 5.6.0+ required');
		}

		$this->config = array();
		$this->pdo = $pdo;

		$this->config_table = 'e_auth_config';

		//check if table config exists in the database
		if (!$this->pdo->query("SHOW TABLES LIKE '{$this->config_table}'")->fetchAll() ) {
			die("EAuth: Config table `{$this->config_table}` NOT PRESENT in a given database".PHP_EOL);
		};

		//load configuration key => value pair
		$this->config = $this->pdo->query("SELECT `setting`,`value` FROM {$this->config_table}")->fetchAll(\PDO::FETCH_KEY_PAIR);

		$this->setForgottenDefaults(); //denger foreseen is half avoided.

		//Check if the required tables exist for authentication engine.

		// check if table_attempts exists
		if (! $this->pdo->query("SHOW TABLES LIKE '{$this->config['table_attempts']}'")->fetchAll() ) {
		    die("EAuth: Table `{$this->config['table_attempts']}` NOT PRESENT in given database" . PHP_EOL);
		};

		// check if table requests exists
		if (! $this->pdo->query("SHOW TABLES LIKE '{$this->config['table_requests']}'")->fetchAll() ) {
		    die("EAuth: Table `{$this->config['table_requests']}` NOT PRESENT in given database" . PHP_EOL);
		};

		// check if table sessions exists
		if (! $this->pdo->query("SHOW TABLES LIKE '{$this->config['table_sessions']}'")->fetchAll() ) {
		    die("EAuth: Table `{$this->config['table_sessions']}` NOT PRESENT in given database" . PHP_EOL);
		};

		// check if table users exist
		if (! $this->pdo->query("SHOW TABLES LIKE '{$this->config['table_users']}'")->fetchAll() ) {
		    die("EAuth: Table `{$this->config['table_users']}` NOT PRESENT in given database" . PHP_EOL);
		};

		// Determine site language
		$site_language = (empty($config_site_language))
		    ? isset($this->config['site_language']) ? $this->config['site_language'] : 'en_GB'
		    : $config_site_language;

		$dictionary = [];

		if (isset($this->config['translation_source'])) {

		    switch ($this->config['translation_source']) {
		        case 'php': {

		            $lang_file = dirname(__FILE__) . DIRECTORY_SEPARATOR . "languages" . DIRECTORY_SEPARATOR . "{$site_language}.php";

		            if (is_readable($lang_file)) {
		                $dictionary = include $lang_file;
		            } else {
		                $dictionary = $this->setForgottenDictionary();
		            }

		            break;
		        }
		        case 'ini': {

		            $lang_file = dirname(__FILE__) . DIRECTORY_SEPARATOR . "languages" . DIRECTORY_SEPARATOR . "{$site_language}.ini";

		            if (is_readable($lang_file)) {
		                $dictionary = parse_ini_file($lang_file);
		            } else {
		                $dictionary = $this->setForgottenDictionary();
		            }
		            break;
		        }
		        case 'sql': {

		            // check field `table_translations` present
		            if (empty($this->config['table_translations'])) {
		                $dictionary = $this->setForgottenDictionary();
		                break;
		            }

		            // check table exists in database
		            if (! $this->pdo->query("SHOW TABLES LIKE '{$this->config['table_translations']}'")->fetchAll() ) {
		                $dictionary = $this->setForgottenDictionary();
		                break;
		            };

		            $query = "SELECT `key`, `{$site_language}` as `lang` FROM {$this->config['table_translations']} ";
		            $dictionary = $this->pdo->query($query)->fetchAll(\PDO::FETCH_KEY_PAIR);

		            break;
		        }
		        case 'xml': {
		            break;
		        }
		        case 'json': {
		            break;
		        }
		        default: {
		            $dictionary = $this->setForgottenDictionary();
		        }
		    } // end switch

		} else {
		    $dictionary = $this->setForgottenDictionary();
		}

		// set dictionary
		$this->config['dictionary'] = $dictionary;

		// set reCaptcha config
		$config_recaptcha = [];

		if (array_key_exists('recaptcha_enabled', $this->config)) {
		    $config_recaptcha['recaptcha_enabled'] = true;
		    $config_recaptcha['recaptcha_site_key'] = $this->config['recaptcha_site_key'];
		    $config_recaptcha['recaptcha_secret_key'] = $this->config['recaptcha_secret_key'];
		}

		$this->config['recaptcha'] = $config_recaptcha;

	} //end of constructor

	/**
	 * Config::__get()
	 *
	 * @param mixed $setting
	 * @return string
	 */
	public function __get($setting)
	{
		return array_key_exists($setting, $this->config) ? $this->config[$setting] : NULL;
	}

	/**
	 * @return array
	 */
	public function getAll()
	{
	    return $this->config;
	}

	/**
	 * Config::__set()
	 *
	 * @param mixed $setting
	 * @param mixed $value
	 * @return bool
	 */
	public function __set($setting, $value)
	{
		$query_prepared = $this->pdo->prepare("UPDATE {$this->config_table} SET value = :value WHERE setting = :setting");

		if ($query_prepared->execute(['value' => $value, 'setting' => $setting])) {
		    $this->config[$setting] = $value;

		    return true;
		}

		return false;
	}

	/**
	 * Config::override()
	 *
	 * @param mixed $setting
	 * @param mixed $value
	 * @return bool
	 */
	public function override($setting, $value)
	{
	    $this->config[$setting] = $value;

	    return true;
	}

	/**
	 * Danger foreseen is half avoided.
	 *
	 * Set default values.
	 * THIS IS REQUIRED FOR USERS THAT DOES NOT UPDATE THEIR `config` TABLES.
	 */
    protected function setForgottenDefaults()
    {
        // ==== unchecked values ====
        $this->repairConfigValue('bcrypt_cost', 10);

        // cookies* values
        $this->repairConfigValue('cookie_name', 'e_auth_session_cookie');

        // verify* values
        $this->repairConfigValue('verify_password_min_length', 3);

        $this->repairConfigValue('verify_email_min_length', 5);

        $this->repairConfigValue('verify_email_max_length', 100);

        $this->repairConfigValue('verify_email_use_banlist', 1);

        // emailmessage* values

        $this->repairConfigValue('emailmessage_suppress_activation', 0);

        $this->repairConfigValue('emailmessage_suppress_reset', 0);

        $this->repairConfigValue('mail_charset', "UTF-8");

        // others
        $this->repairConfigValue('allow_concurrent_sessions', false);
	}

	/**
	 * Set configuration value if it is not present.
	 * @param $setting
	 * @param $default_value
	 */
	protected function repairConfigValue($setting, $default_value)
	{
	    if (!isset($this->config[$setting]))
	        $this->config[$setting] = $default_value;
	}

	/**
	 * Returns forgotten translation dictionary
	 *
	 * @return array
	 */
	private function setForgottenDictionary()
	{
	    $lang = array();

	    $lang['user_blocked'] = "You are currently locked out of the system.";
	    $lang['user_verify_failed'] = "Captcha Code was invalid.";

	    //new
	    $lang['account_email_invalid']  = "Email address is incorrect or banned";

	    //new
	    $lang['account_password_invalid'] = "Password is invalid";

	    //new
	    $lang['account_not_found']          = "Account with given email not found.";


	    $lang['login_remember_me_invalid'] = "The remember me field is invalid.";

	    $lang['privacy_invalid'] = "privacy and policy field is invalid";

	    $lang['accept_privacy'] = "Please read and agree with our Privacy and Policy";

	    $lang['email_password_invalid'] = "Email address / password are invalid.";
	    $lang['email_password_incorrect'] = "Password are incorrect for given EMail.";
	    $lang['remember_me_invalid'] = "The remember me field is invalid.";

	    $lang['password_short'] = "Password is too short.";
	    $lang['password_weak'] = "Password is too weak please enter atleast 8 different combination.";
	    $lang['password_nomatch'] = "Passwords do not match.";
	    $lang['password_changed'] = "Password changed successfully.";
	    $lang['password_incorrect'] = "Current password is incorrect.";
	    $lang['password_notvalid'] = "Password is invalid.";

	    $lang['newpassword_short'] = "New password is too short.";
	    $lang['newpassword_long'] = "New password is too long.";
	    $lang['newpassword_invalid'] = "New password must contain at least one uppercase and lowercase character, and at least one digit.";
	    $lang['newpassword_nomatch'] = "New passwords do not match.";
	    $lang['newpassword_match'] = "New password is the same as the old password.";

	    $lang['email_short'] = "Email address is too short.";
	    $lang['email_long'] = "Email address is too long.";
	    $lang['email_invalid'] = "It is not a correct Email address.";
	    $lang['email_incorrect'] = "Email address is incorrect.";
	    $lang['email_banned'] = "This email address is not allowed.";
	    $lang['email_changed'] = "Email address changed successfully.";

	    $lang['newemail_match'] = "New email matches previous email.";

	    $lang['account_inactive'] = "Account has not yet been activated.";
	    $lang['account_activated'] = "Account activated.";

	    $lang['logged_in'] = "You are now logged in.";
	    $lang['logged_out'] = "You are now logged out.";

	    $lang['system_error'] = "A system error has been encountered. Please try again.";

	    $lang['register_success'] = "Account created. Activation email sent to email.";
	    $lang['register_success_emailmessage_suppressed'] = "Account created.";
	    $lang['email_taken'] = "The email address is already registered.";

	    $lang['resetkey_invalid'] = "Reset key is invalid.";
	    $lang['resetkey_incorrect'] = "Reset key is incorrect.";
	    $lang['resetkey_expired'] = "Reset key has expired.";
	    $lang['password_reset'] = "Password reset successfully.";

	    $lang['activationkey_invalid'] = "Activation key is invalid.";
	    $lang['activationkey_incorrect'] = "Activation key is incorrect.";
	    $lang['activationkey_expired'] = "Activation key has expired.";

	    $lang['reset_requested'] = "Password reset request sent to email address.";
	    $lang['reset_requested_emailmessage_suppressed'] = "Password reset request is created.";
	    $lang['reset_exists'] = "A reset request already exists.";

	    $lang['already_activated'] = "Account is already activated.";
	    $lang['activation_sent'] = "Activation email has been sent.";
	    $lang['activation_exists'] = "An activation email has already been sent.";

	    $lang['email_activation_subject'] = '%s - Activate account';
	    $lang['email_activation_body'] = 'Hello,<br/><br/> To be able to log in to your account you first need to activate your account by clicking on the following link : <strong><a href="%1$s/%2$s">%1$s/%2$s</a></strong><br/><br/> You then need to use the following activation key: <strong>%3$s</strong><br/><br/> If you did not sign up on %1$s recently then this message was sent in error, please ignore it.';
	    $lang['email_activation_altbody'] = 'Hello, ' . "\n\n" . 'To be able to log in to your account you first need to activate your account by visiting the following link :' . "\n" . '%1$s/%2$s' . "\n\n" . 'You then need to use the following activation key: %3$s' . "\n\n" . 'If you did not sign up on %1$s recently then this message was sent in error, please ignore it.';

	    $lang['email_reset_subject'] = '%s - Password reset request';
	    $lang['email_reset_body'] = 'Hello,<br/><br/>To reset your password click the following link :<br/><br/><strong><a href="%1$s/%2$s">%1$s/%2$s</a></strong><br/><br/>You then need to use the following password reset key: <strong>%3$s</strong><br/><br/>If you did not request a password reset key on %1$s recently then this message was sent in error, please ignore it.';
	    $lang['email_reset_altbody'] = 'Hello, ' . "\n\n" . 'To reset your password please visiting the following link :' . "\n" . '%1$s/%2$s' . "\n\n" . 'You then need to use the following password reset key: %3$s' . "\n\n" . 'If you did not request a password reset key on %1$s recently then this message was sent in error, please ignore it.';

	    $lang['account_deleted'] = "Your Account was deleted successfully.";
	    $lang['account_deleted2'] = "Account was deleted successfully.";
	    $lang['function_disabled'] = "This function has been disabled.";

	    return $lang;
	}

}