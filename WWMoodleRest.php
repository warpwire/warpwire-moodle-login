<?php
/**********************************************************************
//
// Warpwire REST Connection Class 
// version 1.2.1
//
// Allows Moodle to make a REST authentication connection
// and bundle and return the results to Warpwire for
// authentication and processing
//
**********************************************************************/

/**********************************************************************
//
// Copyright 2019 Warpwire, Inc Licensed under the
//	 Educational Community License, Version 2.0 (the "License"); you may
//  not use this file except in compliance with the License. You may
//  obtain a copy of the License at
//
// http://www.osedu.org/licenses/ECL-2.0
//
//  Unless required by applicable law or agreed to in writing,
//  software distributed under the License is distributed on an "AS IS"
//  BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express
//  or implied. See the License for the specific language governing
//  permissions and limitations under the License.
//	
**********************************************************************/

/**********************************************************************
//	You should not make modification below this line without first
//  understanding how this class works in totality
**********************************************************************/

class WWMoodleRest {

	// host name information
	private $_HOST = null;
	// rest endpoint information
	private $_REST_ENDPOINT = null;
	// basic user information
	private $_USER = null;
	// basic password information
	private $_PASS = null;
	
	// Web Service Connection Objects
	private $_WS_LOGIN = null;
	// token for web services
	private $_TOKEN = null;
	// user membership within Moodle
	private $_MEMBERSHIP = array();
	// core user object
	private $_CORE_USER = array();	
	// the user id for the user
	private $_USER_ID;
	// the first name of the user
	private $_FIRST_NAME = null;
	// the last name of the user
	private $_LAST_NAME = null;
	// display name of the user
	private $_DISPLAY_NAME = null;
	// the unique identifier of the user
	private $_USER_UNIQUE_IDENTIFIER = null;
	// indicate that additional debugging should be shown
	private $_IS_DEV = false;
	// indicate that session should be used
	private $_USE_SESSION = false;

	// create a new basic soap object with assumed credentials
	public function __construct($_params = array()) {
		// the params array cannot be empty
		if ((! is_array($_params)) || (empty($_params)))
			return(null);
		// map the appropriate varibales
		if (isset($_params['host'])) {
			$this->setHost($_params['host']);
		}
		// set the user variable
		if (isset($_params['username'])) {
			$this->setUser($_params['username']);
		}
		// set the password 
		if (isset($_params['password'])) {
			$this->setPassword($_params['password']);
		}
		// set the rest endpoint
		if (isset($_params['restEndpoint'])) {
			$this->setRestEndpoint($_params['restEndpoint']);
		}
		// set the login endpoint
		if (isset($_params['loginEndpoint'])) {
			$this->setHost($_params['loginEndpoint']);
		}
		// set the development flag
		if ((isset($_params['dev'])) && ($_params['dev'] == true)) {
			$this->setIsDev(true);
		}
		// indicate that sessions should be used
		if ((isset($_params['useSession'])) && ($_params['useSession'] == true)) {
			$this->setUseSession(true);
		}

	}
	
	// make a connection to the REST service
	public function connect() {
		// attempt to make a connection to the login endpoint
		try {
			// set the session data
			$_sessionData = $this->getCacheSessionData();
			// a previous session was not provided - use standard authentication method
			if ((! is_array($_sessionData)) || (! isset($_sessionData['token']))) {
				// build the login URL
				$_loginUrl = $this->getHost();
				// build the array to authenticate
				$_params = array(
					'username' => $this->getUser(),
					'password' => $this->getPassword(),
					'service' => 'moodle_mobile_app',
					'moodlewsrestformat' => 'json',
				);
				// attempt to login to the service
				$_login = $this->request($_loginUrl, $_params);

				// the username or pass is not valid
				if (! isset($_login['http_code'])) {
					throw new Exception('Unable to connect to the authentication service');
				}
				// ensure that the session information is returned
				if ((! isset($_login['content'])) || (strlen($_login['content']) <= 0)) {
					throw new Exception('The login information is not in the correct format');
				}
				// the login data must be valid JSON
				$_loginData = $this->getJson($_login['content']);
				// the data must be valid json
				if (! isset($_loginData['token'])) {
					throw new Exception('Unable to get a token to sign you in. Please try again.');
				}
				// set the token
				$this->setToken($_loginData['token']);
				// set the cached session data for the token
				if ($this->getUseSession()) {
				 $this->setCacheSessionData(array('CACHE_TOKEN' => $_loginData['token']));
				}
			
				// detect if the login is valid
				if (($_login['http_code'] != 200) && ($_login['http_code'] != 201)) {
					throw new Exception('Login parameters are incorrect');
				}
			} 
			// set the token to match
			elseif (isset($_sessionData['token'])) {
				$this->setToken($_sessionData['token']);
			}

			// assemble the session URL
			$_sessionUrl = $this->getRestEndpoint();
			// add the session parameters
			$_params = array(
				'moodlewsrestformat' => 'json',
				'wsfunction' => 'core_webservice_get_site_info'
			);
			// ensure that we can get the user identifier
			$_session = $this->request($_sessionUrl, $_params);

			// decode the json object
			$_results = $this->getJson($_session['content']);
			
			// there must be at least one session collection record
			if ((! isset($_results['userid'])) || (empty($_results['userid']))) {
				throw new Exception('Unable to get the user unique identifier (1)');
			}
			
			// the usereid value (user unique identifier) must be set
			if ((! isset($_results['username'])) || (empty($_results['username']))) {
				throw new Exception('Unable to get the user unique identifier (2)');
			}
			
			// set the user id
			$this->setUserId($_results['userid']);
			// set the user unique identifier
			$this->setUserUniqueIdentifier($_results['username']);
			// set the core user object
			$this->setCoreUser($_results);
			
			// set the user first name
			if (isset($_results['firstname'])) {
				$this->setFirstName($_results['firstname']);
			}
			// set the user last name
			if (isset($_results['lastname'])) {
				$this->setLastName($_results['lastname']);
			}
			// set the display name
			if (isset($_results['fullname'])) {
				$this->setDisplayName($_results['fullname']);
			}

			// make a query for the membership list
			$_membershipURL = $this->getRestEndpoint();
			// add the membership parameter list
			$_params = array(
				'moodlewsrestformat' => 'json',
				'wsfunction' => 'core_enrol_get_users_courses',
				'userid' => $this->getUserId(),
			);
			
			// ensure that the user is a member of at least one course
			$_enrolled = $this->request($_membershipURL, $_params);

			// decode the json object
			$_results = $this->getJson($_enrolled['content']);
			
			$_courses = array();
			// iterate through the enrolled courses gathering the ids
			foreach($_results AS $_result) {
				// the course id does not exist
				if (! isset($_result['id'])) continue;
				// get the name of the course
				$_courseName = '';				
				if (isset($_result['idnumber'])) {
					$_courseName = $_result['idnumber'];
				}
				elseif (isset($_result['shortname'])) {
					$_courseName = $_result['shortname'];
				}
				elseif (isset($_result['fullname'])) {
					$_courseName = $_result['fullname'];
				}
				// add the course id to the list
				$_courses[$_result['id']] = $_courseName;
				//array_push($_courses, $_result['id']);
			}
			// the user is not a member of any courses
			if (count($_courses) <= 0) {
				return(true);
			}
			// set the course membership
			$this->setMembership($_courses);
	
		}
		// pass the exception to the handler
		catch (Exception $e) {
			// build the login URL
			$_loginUrl = $this->getHost();
			$headers = @get_headers($_loginUrl);
			// reset the cookie jar data
			$this->setCacheSessionData('');
			// clean up the connection - specifically unlinking the cookie jar file
			$this->cleanUp();
			throw new Exception($e->getMessage().' Headers: '.var_export($headers, true));
		}
	}
	
	// set the hostname retroactively
	public function setHost($_host) {
		if ((isset($_host) && (! empty($_host)))) {
			$this->_HOST = trim($_host);
		}
	}
	
	// set the rest endpoint
	public function setRestEndpoint($_rest) {
		if ((isset($_rest) && (! empty($_rest)))) {
			$this->_REST_ENDPOINT = trim($_rest);
		}
	}
	
	// allow the user name to be set retroactively
	public function setUser($_user) {
		if ((isset($_user) && (! empty($_user)))) {
			$this->_USER = $_user;
		}
	}

	// allow the passord to be set retroactively
	public function setPassword($_password) {
		if ((isset($_password) && (! empty($_password)))) {
			$this->_PASS = $_password;
		}
	}
	
	// returns information about the core user
	public function getCoreUser() {
		// return the cached version
		if ((! empty($this->_CORE_USER)) && (is_array($this->_CORE_USER))) {
			return($this->_CORE_USER);
		}
		// there is no core user
		return(array());		
	}

	// returns the user first name
	public function getUserFirstName() {
		$_firstName = trim($this->getFirstName());
		// return the value if it is not empty
		if (! empty($_firstName)) {
			return($_firstName);
		}

		// get the core user information
		$_user = $this->getCoreUser();
		// return the user first name
		if (isset($_user['firstname'])) {
			return($_user['firstname']);
		}
		// return a default name
		return('');	
	}
	
	// returns the user last name
	public function getUserLastName() {
		$_lastName = trim($this->getLastName());
		// return the value if it is not empty
		if (! empty($_lastName)) {
			return($_lastName);
		}

		// get the core user information
		$_user = $this->getCoreUser();
		// return the user first name
		if (isset($_user['lastname'])) {
			return($_user['lastname']);
		}
		// return a default name
		return('');	
	}

	// get the user display name
	public function getUserDisplayName() {
		
		$_fullname = trim($this->getDisplayName());
		// return the value if it is not empty
		if (! empty($_firstName)) {
			return($_firstName);
		}

		// get the core user information
		$_user = $this->getCoreUser();
		// return the user first name
		if (isset($_user['lastname'])) {
			return($_user['lastname']);
		}
		// return a default name
		return('');	
	}

	// get the user email 
	public function getUserEmail() {
		// get the user membership information
		if (count($_memberships = ($this->getMembership())) <= 0) {
			return('');
		}
		// get the first item from the user membership roster
		$_membership = reset($_memberships);
		// the membership value must be great than 0
		if ($_membership <= 0) {
			return('');
		}
					
		// search for the user information
		try {
			// make a query for the membership list
			$_membershipURL = $this->getRestEndpoint();
			// add the membership parameter list
			$_params = array(
				'moodlewsrestformat' => 'json',
				'wsfunction' => 'core_enrol_get_enrolled_users',
				'courseid' => $_membership,
				'options' => array(
					array('name' => 'userfields', 'value' => 'idnumber'),
					array('name' => 'userfields', 'value' => 'email'),
				)
			);
			
			// ensure that the user is a member of at least one course
			$_enrolled = $this->request($_membershipURL, $_params);
			// get the enrollment information
			$_results = $this->getJson($_enrolled['content']);
			// there is no membership information
			if (empty($_results)) {
				return('');
			}
			$_userId = $this->getUserId();
			// iterate through the list until a match is found
			foreach($_results AS $_result) {
				// the id is not set
				if (! isset($_result['id'])) {
					continue;
				}
				// the email value is not set
				if ((! isset($_result['email'])) || (empty($_result['email']))) {
					continue;
				}
				// the id does not match
				if ($_userId != $_result['id']) {
					continue;
				}
				// return the email value
				return($_result['email']);
			}
		}	catch (\Exception $e) {}
			// no email found
			return('');	
	}

	// get the user id
	public function getUserId() {
		// return the user id
		return($this->_USER_ID);
	}

	// get the user unique identifier
	public function getUserUniqueIdentifier() {
		// return the user unique identifier
		return($this->_USER_UNIQUE_IDENTIFIER);
	}

	// get a list of all user sites for this user
	public function getAllUserSites() {
		// the session must be active
		if (count($_memberships = ($this->getMembership())) <= 0) {
			return(array());
		}
		// list of all available courses for the enrolled user
		$_courses = array();	
		try {
			// make a query for the membership list
			$_membershipURL = $this->getRestEndpoint();
			// add the membership parameter list
			$_params = array(
				'moodlewsrestformat' => 'json',
				'wsfunction' => 'core_enrol_get_users_courses',
				'userid' => $this->getUserId(),
			);
		
			// ensure that the user is a member of at least one course
			$_enrolled = $this->request($_membershipURL, $_params);

			// decode the json object
			$_results = $this->getJson($_enrolled['content']);
			// iterate through the enrolled courses gathering the ids
			foreach($_results AS $_result) {
				// the course id does not exist
				if (! isset($_result['id'])) {
					continue;
				}
				// get the name of the course
				$_courseName = '';				
				if (! empty($_result['idnumber'])) {
					$_courseName = $_result['idnumber'];
				}
				elseif (! empty($_result['shortname'])) {
					$_courseName = $_result['shortname'];
				}
				elseif (! empty($_result['fullname'])) {
					$_courseName = $_result['fullname'];
				}
				// add the course id to the list
				$_courses[$_result['id']] = $_courseName;
			}
		} catch (\Exception $e) {}

		// return the course list
		return($_courses);
	}

	// remove the cookie jar file
	public function cleanUp() {
		return(true);
	}

	// a global comamnd to download files via curl
	public function request($url, $params = array(), $_token = '') {
		try {
			// set the curl options			
			$options = array(
				CURLOPT_RETURNTRANSFER => true,     // return web page
				CURLOPT_HEADER         => false,    // don't return headers
				CURLOPT_FOLLOWLOCATION => true,     // follow redirects
				CURLOPT_ENCODING       => '',       // handle all encodings
				CURLOPT_USERAGENT      => 'Warpwire-Authenticator-REST', // who am i
				CURLOPT_AUTOREFERER    => true,     // set referer on redirect
				CURLOPT_CONNECTTIMEOUT => 10,      // timeout on connect
				CURLOPT_TIMEOUT        => 10,      // timeout on response
				CURLOPT_MAXREDIRS      => 10,       // stop after 10 redirects
			);
	
				// set the session request token
			if ((isset($_token) && (strlen($_token) > 0))) {
				// add the token
				$params['wstoken'] = $_token;
			}
			// use the predefined token
			elseif (strlen($this->getToken()) > 0) {
				// add the token
				$params['wstoken'] = $this->getToken();
			}
			
			// set the request method
			if ((is_array($params)) && (! empty($params))) {
				$options[CURLOPT_POST] = true;
				$postFields = http_build_query($params);
				$options[CURLOPT_POSTFIELDS] = $postFields;
			}

			// get a new curl handler
			$ch = curl_init($url);
			curl_setopt_array($ch, $options);
			$content = curl_exec($ch);
			$err = curl_errno($ch);
			$errmsg = curl_error($ch);
			$output = curl_getinfo($ch);
			curl_close($ch);
			$output['errno']   = $err;
			$output['errmsg']  = $errmsg;
			$output['content'] = $content;
			if ($this->getIsDev()) {
				print('<p><br /><span style="font-weight:bold">DEV CURL Debug:</span>
					<textarea style="margin:0 auto;width:100%;max-width:400px;height:300px;">CURL Response:'.PHP_EOL.PHP_EOL.
					var_export($content, true).PHP_EOL.PHP_EOL.'CURL OUTPUT: '.
					var_export($output, true).'</textarea></p>');
			}
			return ($output);
		} catch (Exception $e) {
			throw $e;
		}
	}

	// returns if the last json parsed element was valid
	private function getJson($string) {
		$_output = @json_decode($string, true);
		// make sure the json was valid
		if (json_last_error() != JSON_ERROR_NONE) {
			throw new Exception('Unable to parse the JSON output. Please contact support.');
		}
		return($_output);
	}
						
	// naive retrieval function
	private function getHost() { return ($this->_HOST); }
	private function getRestEndpoint() { return ($this->_REST_ENDPOINT); }
	private function getUser() { return ($this->_USER); }
	private function getPassword() { return ($this->_PASS); }
	private function getWSLogin() { return ($this->_WS_LOGIN); }
	private function getToken() { return ($this->_TOKEN); }
	private function getCacheSessionData() {
		if ((session_id() != '') && (isset($_SESSION['CACHE_SESSION_DATA'])))
			return($_SESSION['CACHE_SESSION_DATA']);
		return('');
	}
	private function getMembership() { return($this->_MEMBERSHIP); }
	private function getFirstName() { return($this->_FIRST_NAME); }
	private function getLastName() { return($this->_LAST_NAME); }
	private function getDisplayName() { return($this->_DISPLAY_NAME); }
	private function getIsDev() { return($this->_IS_DEV); }
	private function getUseSession() { return($this->_USE_SESSION); }
	
	
	private function setToken($a) { $this->_TOKEN = $a; }
	private function setCacheSessionData($a) {
		if (session_id() == '') {
			return(false);
		}
		// set the cookie jar data
		$_SESSION['CACHE_SESSION_DATA'] = $a;
		// set the session expiration
		$_SESSION['EXPIRATION'] = strtotime('+4 hours', time());
	}
	private function setMembership($a) { $this->_MEMBERSHIP = $a; }
	private function setUserId($a) { $this->_USER_ID = $a; }
	private function setUserUniqueIdentifier($a) { $this->_USER_UNIQUE_IDENTIFIER = $a; }
	private function setFirstName($a) { $this->_FIRST_NAME = $a; }
	private function setLastName($a) { $this->_LAST_NAME = $a; }
	private function setDisplayName($a) { $this->_DISPLAY_NAME = $a; }
	private function setCoreUser($a) { $this->_CORE_USER = $a; }
	private function setIsDev($a) { $this->_IS_DEV = $a; }
	private function setUseSession($a) { $this->_USE_SESSION = $a; }
}