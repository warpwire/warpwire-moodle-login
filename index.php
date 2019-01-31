<?php
/**********************************************************************
//
// Version 1.1.5
//
//  Copyright 2019 Warpwire, Inc Licensed under the
//  Educational Community License, Version 2.0 (the "License"); you may
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
//
//			 CONFIGURATIONS
// 
// Please fill out the following section with your specific Warpwire
// configration information. Contact Warpwire support for additional
// information regarding these values.
//
**********************************************************************/

// PHP session configurations
ini_set('session.name', 'WWMoodleWebServicesID');
ini_set('session.use_cookies', 1);
ini_set('session.use_only_cookies', 1);

// show error messages for debugging purposes only
$_IS_DEV = false;
$_WW_USER = $_WW_KEY = $_WW_URL = $_LOGIN_URL = $_SERVICE_NAME = '';
$_SERVICE_ICON = $_SERVICE_COLOR = $_SERVICE_ICON_DIMENSIONS = $_SERVICE_LOGIN_LABEL = '';
$_SERVICE_HEADER_PADDING = $_WW_AUTH_METHOD = $_SERVICE_COLOR_ALTERNATE = '';
$__WSDL_PARAMS = array();


// load the configuration
require_once('config.php');

// Initialize the PHP session if applicable
prepareSession();

// Logout has been requested
if(isset($_GET['logout'])) {
	// log the user out
	logout();
	printSuccess('Successfully logged out.  Please close this window.', false, false);
}

// activate error reporting for messages and exceptions
if ($_IS_DEV) {
	error_reporting(E_ALL);
	ini_set('display_errors', true);
}

// All times must be in UTC format
date_default_timezone_set('UTC');

$_VALID_METHODS = array('REST');
// ensure that a authentication method is specified
if ((! isset($_WW_AUTH_METHOD)) || (! in_array(strtoupper(trim($_WW_AUTH_METHOD)), $_VALID_METHODS))) {
	printError('A valid authentication method is required. Please contact support.');
}

// list of required variables to be defined
$_REQUIRED_REST_VALUES = array('_LOGIN_URL', '_LOGIN_URL_REST', '_REST_ENDPOINT');

$_WW_AUTH_METHOD = strtoupper(trim($_WW_AUTH_METHOD));

//*********************************************************************//
//  Use caution when making modification below this line
//  (a backup is always a good idea)
//*********************************************************************//

// default version of webservice
if(!isset($_WW_WEBSERVICES_VERSION)) {
	$_WW_WEBSERVICES_VERSION = 1.1;
}


// load the JSON method
if ($_WW_AUTH_METHOD == 'REST') {
	// check for existence of SOAP
	if(!function_exists('json_decode')) {
		printError('Required PHP Json Decode Package does not exist.');
	}

	// check for existence of Web Services class before continuing
	if (!file_exists('WWMoodleRest.php' )) {
		printError('Required REST class can not be loaded.');
	}

	// load the required web services class
	require_once('WWMoodleRest.php');

	// ensure that all of the configruations are defined
	foreach($_REQUIRED_REST_VALUES AS $_REQUIRED_REST_VALUE) {
		if (! isset($_REQUIRED_REST_VALUE)) {
			printError('Required parameter '.$_REQUIRED_REST_VALUE.' is not defined.');
		}
		// the value cannot be empty
		if (empty($_REQUIRED_REST_VALUE)) {
			printError('Required parameter '.$_REQUIRED_REST_VALUE.' cannot be empty.');
		}
	}

	// map the name of the Moodle object	
	$_MOODLE_OBJECT = 'WWMoodleRest';
	// map the login to the REST server
	$_LOGIN_ENDPOINT = $_LOGIN_URL_REST;
}
else {
	printError('We are unable to find the class to authenticate you. Please contact support.');	
}

// the user has not authenticated, and a valid session has not been found - show the login form
if ((!hasValidSession()) && ((! isset($_POST['username'])) || (! isset($_POST['password'])))) {
	showLogin();
	exit;
}
// the user name is empty, and a valid session has not been found - show the login form
elseif ((!hasValidSession()) && (empty($_POST['username']))) {
	showLogin('The '.$_SERVICE_LOGIN_LABEL.' cannot be empty.');
	exit;
}
// authenticate the user
else {
	// determine if the time parameter is set
	if (isset($_POST['ts'])) {
		$timestamp = trim($_POST['ts']);
		// make sure that timestamp evaluation is appropriate
		if (((string)(int) $timestamp === $timestamp) && ($timestamp <= PHP_INT_MAX)
        && ($timestamp >= ~PHP_INT_MAX)) {
     		// make sure the timetamp is still valid   
    		if (time() > strtotime('+1 minute', $timestamp))
    			showLogin('Your session has expired. Please login again.');
    	}
	}

	// default username/password values
	$_username = 'user';
	$_password = 'pass';
	// set the provided username
	if(isset($_POST['username'])) {
		$_username = $_POST['username'];
	}
	// set the provided password
	if(isset($_POST['password'])) {
		$_password = $_POST['password'];	
	}
	// create a list of parameters for the object
	$_moodleParams = array(
		'loginEndpoint' => $_LOGIN_ENDPOINT,
		'restEndpoint' => $_REST_ENDPOINT, 
		'username' => $_username,
		'password' => $_password,
		'dev' => $_IS_DEV,
		'useSession' => $_WW_USE_SESSION,
	);
	// Warpwire/Moodle connection to authenticate and authorize users
	$_WWMoodleLogin = new $_MOODLE_OBJECT($_moodleParams);
	// make a connection to the appropriate service
	try {
		$_WWMoodleLogin->connect();
	}
	catch (Exception $e) {
		// error encountered, log the user out
		logout();
		if ($_IS_DEV) {
			print('<b>DEV Message:</b>: '.$e->getMessage().'<br />');
		}
		showLogin('Unable to login. Please check your '.$_SERVICE_LOGIN_LABEL.' and/or Password.');
		exit;
	}
	
	// Package parameters to submit to Warpwire
	try {
		// create a random token and nonce
		$_token = uniqid(mt_rand(10000,999999)).'-'.uniqid(mt_rand(10000,999999));
		$_nonce = mt_rand(10000000,999999999);
		// generate the components of the signature
		$_userId = $_WWMoodleLogin->getUserId();
		$_userUniqueIdentifier = $_WWMoodleLogin->getUserUniqueIdentifier();
		$_displayName = $_firstName = $_lastName = '';
		// determine if the fist and last name are set as options
		if (method_exists($_WWMoodleLogin, 'getUserFirstName')) {
			$_firstName = $_WWMoodleLogin->getUserFirstName();
		}
		if (method_exists($_WWMoodleLogin, 'getUserLastName')) {
			$_lastName = $_WWMoodleLogin->getUserLastName();
		}
		// composite the two values
		if (strlen(trim($_firstName)) > 0) {
			$_displayName .= $_firstName;
		}
		if (strlen(trim($_displayName)) > 0) {
			$_displayName .= ' '.$_lastName;
		}
		// determine if the display name function should be used
		if (strlen(trim($_displayName)) <= 0) {
			$_displayName = $_WWMoodleLogin->getUserDisplayName();
		}
		
		$_email = $_WWMoodleLogin->getUserEmail();
		$_groups = $_WWMoodleLogin->getAllUserSites();

		// cleanup the function
		$_WWMoodleLogin->cleanUp();
		$_groupString = '';
		// loop through the groups to compose a string
		foreach($_groups AS $_groupId => $_groupName) {
			$_groupString .= $_groupId;
		}
		$_time = time();
		
		// create a composite of all parameters
		$_compositeParams = $_userId.$_displayName.$_email.$_groupString;
		// version 1.2 of API requires a user unique identifier
		if(isset($_WW_WEBSERVICES_VERSION) && ($_WW_WEBSERVICES_VERSION >= 1.2)) {
			$_compositeParams = $_userId.$_userUniqueIdentifier.$_displayName.$_email.$_groupString;
		}

		$_data = array(
			'userId' => $_userId,
			'userUniqueIdentifier' => $_userUniqueIdentifier,
			'displayName' => $_displayName,
			'email' => $_email,
			'groups' => $_groups,
			'token' => $_token,
			'nonce' => $_nonce,
			'time' => $_time,
			'version' => $_WW_WEBSERVICES_VERSION,
			'signature' => hash_hmac('sha256', $_compositeParams.$_token.$_nonce.$_time, $_WW_KEY)
		);

		// set the user unique parameter if provided - used for disambiguation
		if(isset($_WW_UNIQUE_PARAM))
			$_data['uniqueParam'] = $_WW_UNIQUE_PARAM;
		
		$returnTo = '';
		// add a return to URL if it is set
		if (isset($_REQUEST['ReturnTo']))
			$returnTo = '<input type="hidden" name="ReturnTo" value="'.$_REQUEST['ReturnTo'].'" />';
		if (isset($_REQUEST['returnTo']))
			$returnTo = '<input type="hidden" name="returnTo" value="'.$_REQUEST['returnTo'].'" />';
		
		// save as a json object
		$_data = base64_encode(json_encode($_data));
		// generate a page and post content
		$_output = '';
		$_output .= '<html><head><title>Warpwire Login Redirect</title></head>
		<p>Please click the login button to continue.</p>
		<form method="POST" action="'.$_WW_URL.'" name="loginForm" enctype="multipart/form-data">
			<input type="hidden" name="manifest" value="'.$_data.'" />
			<input type="hidden" name="algo" value="sha256" />
			<input type="hidden" name="account" value="'.$_WW_USER.'" />
			<input type="hidden" name="email" value="'.$_email.'" />
			<input type="hidden" name="parent" value="moodle" />
			<input type="hidden" name="version" value="1" />'.
			$returnTo.'
			<input type="submit" value="Login" id="loginPassThrough" />
		</form>
		<script language="javascript">
			document.getElementById("loginPassThrough").click();
		</script>
		</body></html>';
		// show the form
		print($_output);
		exit;
	} catch (Exception $e) {
			if ($_IS_DEV) {
				print('<b>DEV Message:</b>: '.$e->getMessage().'<br />');
			}
	}

}

// prints the login screen
function showLogin($message = '') {
	global $_SERVICE_NAME, $_SERVICE_LOGIN_LABEL, $_SERVICE_LOGIN_TEXT, $_SERVICE_ICON, $_SERVICE_COLOR, $_SERVICE_ICON_DIMENSIONS, $_SERVICE_HEADER_PADDING, $_SERVICE_COLOR_ALTERNATE;
	
	$_output = '';
	$_output .= '<!DOCTYPE html>
	<html>
	<head>
	<title>'.$_SERVICE_NAME.'</title>
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
	<meta name="format-detection" content="telephone=no">
	<style>
	body { margin: 0; font-family: Helvetica, sans-serif; font-weight: 300; font-size: 16px; line-height: 28px; color: #222; background: #f7f7f7; text-align: center; -webkit-text-size-adjust: 100%; }
	#container { margin: 50px auto 0 auto; max-width: 600px; box-shadow: 0 0 12px 0 rgba(0,0,0,0.1); }
	#header { background: #'.$_SERVICE_COLOR.'; '.$_SERVICE_HEADER_PADDING.' text-align: center; line-height: 0; }
	#header img { '.$_SERVICE_ICON_DIMENSIONS.' }
	#login { max-width: 600px; padding: 15px; text-align: center; background: #fff; padding-bottom: 45px; }
	a { color: #222; }
	#myLoginButton { color: #fff; background: #'.$_SERVICE_COLOR_ALTERNATE.'; width: 110px; margin: 15px auto 0 auto; font-size: 14px; padding: 12px 5px; cursor: pointer; transition: linear 150ms background; border: 0; }
	#myLoginButton:hover { background: #00a110; }
	.fontSmall { font-size: 12px; }
	.paddingBottom { padding-bottom: 12px; }
	.red { color: red; }
	.bold { font-weight: 400; }	
	input { color: #222; font-size: 14px; width: 198px; height: 22px; border: solid 1px #bbb; -webkit-appearance: none; -moz-appearance: none; appearance: none; -webkit-box-shadow: none; -moz-box-shadow: none; box-shadow: none; -webkit-border-radius: none; -moz-border-radius: none; -ms-border-radius: none; -o-border-radius: none; border-radius: none; }
	input.submit { color: #222; width: 100px; font-size: 16px; }
	.input { display: block; width: 210px; margin: 0 auto; }
	.input span { position: absolute; z-index: 1; cursor: text; pointer-events: none; color: #999; /* Input padding + input border */ padding: 9px 0 7px 0; /* Firefox does not respond well to different line heights. Use padding instead. */ line-height: 17px; /* This gives a little gap between the cursor and the label */ margin-left: 8px; }
	.input input { z-index: 0; padding: 7px 6px 6px 6px; margin: 0; font: inherit; line-height: 24px; }

	/* blink animations */
	.blink { font-weight: 300; display: inline !important; -webkit-animation-name: blink; -moz-animation-name: blink; -webkit-animation-iteration-count: infinite; -moz-animation-iteration-count: infinite; -webkit-animation-timing-function: cubic-bezier(1.0,0,0,1.0); -moz-animation-timing-function: cubic-bezier(1.0,0,0,1.0); -webkit-animation-duration: .8s; -moz-animation-duration: .8s; font-size: 54px; }
	.blink2 { -webkit-animation-delay: .2s; -moz-animation-delay: .2s; animation-delay: .2s; }
	.blink3 { -webkit-animation-delay: .4s; -moz-animation-delay: .4s; animation-delay: .4s; }
	@-webkit-keyframes blink { from { opacity: 1.0; } to { opacity: 0.0; } }
	@-moz-keyframes blink { from { opacity: 1.0; } to { opacity: 0.0; } }	

	@media only screen and (max-width: 576px) {
		body { line-height: 24px; font-weight: 400; }
		#container { margin-top: 0; box-shadow: none; }
	}
	</style>
	<!--[if IE 9]>
	<style>
		#container { width: 600px; border-collapse: separate; }
		@media only screen and (max-width: 576px) {
			#container { width: 100%; }
		}
	</style>
	<![endif]-->
	</head>
	<body>';

	// begin page content construction
	$_output .= '<div id="container">';
	
	// add an image if the service icon exists
	if ((isset($_SERVICE_ICON)) && (! empty($_SERVICE_ICON)))	{
		$_output .= '<div id="header"><img src="'.$_SERVICE_ICON.'" /></div>';
	}
	
	$_username = '';
	// show the user name
	if ((isset($_POST['username'])) && (strlen(trim($_POST['username'])) > 0)) {
		$_username = $_POST['username'];
	}
	
	$showMessage = '';
	// add a message to the page content if applicable
	if ((isset($message)) && (! empty($message))) {
		$showMessage = '<p class="bold red">'.$message.'</p>';
	}
	
	if (empty($_username)) {
		$_inputFormData = 'placeholder="'.$_SERVICE_LOGIN_LABEL.'"';
	}
	else {
		$_inputFormData = 'value="'.$_username.'"';
	}
	
	// create the login page content
	$_output .= '
		<div id="login">
			<form id="loginForm" method="POST" action="'.$_SERVER['REQUEST_URI'].'" onsubmit="if(event.preventDefault) event.preventDefault(); return showWaitIndicator();">
				<p>'.$_SERVICE_LOGIN_TEXT.'</p>
				'.$showMessage.'

				<p><label class="input"><input id="user_name" name="username" type="text" '.$_inputFormData.' /></label></p>
				<p><label class="input"><input id="user_pw" name="password" type="password" placeholder="Password" /></label></p>
				<button type="submit" id="myLoginButton" class="bold">Login</button>
				<div id="loginWait" style="display: none; font-family: helvetica; font-size: 16px; text-align: center; letter-spacing: 1px; line-height: 42px;"><span class="blink">&middot;</span><span class="blink blink2">&middot;</span><span class="blink blink3">&middot;</span></div>
			</form>
		</div>
		<p class="fontSmall paddingBottom">Powered by <a target="_blank" href="http://www.warpwire.com/">Warpwire</a>.</p>		
		<input type="hidden" name="ts" value="'.time().'" />
</div>
</body>
<script>
	var user_id = document.getElementById("user_name");
	if (user_id) {
		user_id.focus();
	}

	// provide a wait indicator when submitting the form
	function showWaitIndicator() {
		document.getElementById("myLoginButton").style.display = "none";
		document.getElementById("loginWait").style.display = "block";		
		var timeout = setTimeout(function(){ 
			document.getElementById("loginForm").submit();
		}, 500);
		return true;
	}

</script>
</html>
		
		';
	// show the login form
	print($_output);
	exit;
	
}

function hasValidSession() {
	if (session_id() == '') {
		return(false);
	}
	if(!isset($_SESSION) || !isset($_SESSION['CACHE_SESSION_DATA'])) {
		return(false);
	}
	if(! is_array($_SESSION['CACHE_SESSION_DATA'])) {
		return(false);
	}

	return(true);
}

function prepareSession() {
	global $_WW_USE_SESSION;
	if((!isset($_WW_USE_SESSION)) || (!$_WW_USE_SESSION)) {
		return(false);
	}
	// start the session
	if (session_id() == '') {
		session_start();
	}
	// no expiration time set - clear out the session
	if(!isset($_SESSION['EXPIRATION'])) {
		session_unset();
		return(false);
	}
	// session has expired, clear all session variables
	if(time() > $_SESSION['EXPIRATION']){
		session_unset();
		return(false);
	}
	return(true);
}

// function to log out the user - completely resets the session
function logout() {
	global $_WW_USE_SESSION;
	if((!isset($_WW_USE_SESSION)) || (!$_WW_USE_SESSION)) {
		return(false); 
	}
	if (session_id() == '')
		session_start();
    session_unset();
    session_destroy();
    session_write_close();
    setcookie(session_name(),'',0,'/');
    session_regenerate_id(true);
	return(true);
}

function printMessage($message = '', $error = false, $closeWindow = false) {
	global $_SERVICE_NAME;
	$_output = '<html><head>';
	
	$_output .= '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
	<style>
	body { margin: 0; font-family: Helvetica, sans-serif; font-weight: 300; font-size: 16px; }
	ol { padding-left: 30px; }
	li { margin-bottom: 5px; }
	.header { padding: 15px; background: #0073ba; color: #ffffff;}
	.header img { height: 32px; width: auto; }
	.content { margin: 30px 15px; }
	.bold { font-weight: 500; }
	</style>
	</head>
	<body>';
	$_output .= '<div class="header">'.$_SERVICE_NAME.'</div>';
	$_output .= '<div class="content">';
	if($error) {
		$_output .= '<span class="bold">An issue occurred:</span>';
	}
	$_output .= $message.'</div></body>';
	if($closeWindow) {
		$_output .= '<script type="text/javascript">window.close();</script>';
	}
	$_output .= '</html>';
	// print the message
	print($_output);
}

function printSuccess($message = '', $error = false, $closeWindow = false) {	
	// set the default message
	if ((! isset($message)) || (empty($message))) {
		$message = 'The request was completed successfully.';
	}

	// set the success header
	header('HTTP/1.1 200 OK');

	// print the success message, and exit
	printMessage($message, $error, $closeWindow);
	exit;
}

// prints a pretty error message
function printError($message = '', $error = true, $closeWindow = false) {
	// set the default message
	if ((! isset($message)) || (empty($message))) {
		$message = 'We are unable to complete your request. An error has occurred while processing this request.';
	}
	
	// set the bad request header
	header('HTTP/1.1 400 Bad Request');

	// print the error message, and exit
	printMessage($message, $error, $closeWindow);
	exit;
}