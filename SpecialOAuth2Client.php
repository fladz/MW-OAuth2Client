<?php
/**
 * SpecialOAuth2Client.php
 * Based on TwitterLogin by David Raison, which is based on the guideline published by Dave Challis at http://blogs.ecs.soton.ac.uk/webteam/2010/04/13/254/
 * @license: LGPL (GNU Lesser General Public License) http://www.gnu.org/licenses/lgpl.html
 *
 * @file SpecialOAuth2Client.php
 * @ingroup OAuth2Client
 *
 * @author Joost de Keijzer
 * @author Nischay Nahata for Schine GmbH
 *
 * Uses the OAuth2 library https://github.com/vznet/oauth_2.0_client_php
 *
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This is a MediaWiki extension, and must be run from within MediaWiki.' );
}
require __DIR__.'/JsonHelper.php';

class SpecialOAuth2Client extends SpecialPage {

	private $_provider;

	/**
	 * Required settings in global $wgOAuth2Client
	 *
	 * $wgOAuth2Client['client']['id']
	 * $wgOAuth2Client['client']['secret']
	 * //$wgOAuth2Client['client']['callback_url'] // extension should know
	 *
	 * $wgOAuth2Client['configuration']['authorize_endpoint']
	 * $wgOAuth2Client['configuration']['access_token_endpoint']
	 * $wgOAuth2Client['configuration']['http_bearer_token']
	 * $wgOAuth2Client['configuration']['query_parameter_token']
	 * $wgOAuth2Client['configuration']['api_endpoint']
	 */
	public function __construct() {

		parent::__construct('OAuth2Client'); // ???: wat doet dit?
		global $wgOAuth2Client, $wgScriptPath;
		global $wgServer, $wgArticlePath;

		require __DIR__ . '/vendors/oauth2-client/vendor/autoload.php';

		$this->_provider = new \League\OAuth2\Client\Provider\GenericProvider([
			'clientId'                => $wgOAuth2Client['client']['id'],    // The client ID assigned to you by the provider
			'clientSecret'            => $wgOAuth2Client['client']['secret'],   // The client password assigned to you by the provider
			'redirectUri'             => $wgOAuth2Client['configuration']['redirect_uri'],
			'urlAuthorize'            => $wgOAuth2Client['configuration']['authorize_endpoint'],
			'urlAccessToken'          => $wgOAuth2Client['configuration']['access_token_endpoint'],
			'urlResourceOwnerDetails' => $wgOAuth2Client['configuration']['api_endpoint'],
			'scopes'                  => $wgOAuth2Client['configuration']['scopes']
		]);
	}

	// default method being called by a specialpage
	public function execute( $parameter ){
		$this->setHeaders();
		switch($parameter){
			case 'redirect':
				$this->_redirect();
			break;
			case 'callback':
				$this->_handleCallback();
			break;
			default:
				$this->_default();
			break;
		}

	}

	private function _redirect() {

		global $wgRequest, $wgOut;
		$wgRequest->getSession()->persist();
		$wgRequest->getSession()->set('returnto', $wgRequest->getVal( 'returnto' ));

		// Fetch the authorization URL from the provider; this returns the
		// urlAuthorize option and generates and applies any necessary parameters
		// (e.g. state).
		$authorizationUrl = $this->_provider->getAuthorizationUrl();

		// Get the state generated for you and store it to the session.
		$wgRequest->getSession()->set('oauth2state', $this->_provider->getState());
		$wgRequest->getSession()->save();

		// Redirect the user to the authorization URL.
		$wgOut->redirect( $authorizationUrl );
	}

	private function _handleCallback(){
		global $wgRequest;

		try {
			$storedState = $wgRequest->getSession()->get('oauth2state');
			// Enforce the `state` parameter to prevent clickjacking/CSRF
			if(isset($storedState) && $storedState != $_GET['state']) {
				if(isset($_GET['state'])) {
					throw new UnexpectedValueException("State parameter of callback does not match original state");
				} else {
					throw new UnexpectedValueException("Required state parameter missing");
				}
			}

			// Try to get an access token using the authorization code grant.
			$accessToken = $this->_provider->getAccessToken('authorization_code', [
				'code' => $_GET['code']
			]);
		} catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
			exit($e->getMessage()); // Failed to get the access token or user details.
		} catch (UnexpectedValueException $e) {
			exit($e->getMessage());
		}

		$resourceOwner = $this->_provider->getResourceOwner($accessToken);
		$user = $this->_userHandling( $resourceOwner->toArray() );
		if ( is_null( $user ) ) {
			global $wgOut;
			$wgOut->redirect( Title::newMainPage()->getFullURL() );
			return false;
		}

		$user->setCookies();

		global $wgOut, $wgRequest;
		$title = null;
		$wgRequest->getSession()->persist();
		if( $wgRequest->getSession()->exists('returnto') ) {
			$title = Title::newFromText( $wgRequest->getSession()->get('returnto') );
			$wgRequest->getSession()->remove('returnto');
			$wgRequest->getSession()->save();
		}

		if( !$title instanceof Title || 0 > $title->getArticleID() ) {
			$title = Title::newMainPage();
		}
		$wgOut->redirect( $title->getFullURL() );
		return true;
	}

	private function _default(){
		global $wgOAuth2Client, $wgOut, $wgScriptPath, $wgExtensionAssetsPath;

		$service_name = ( isset( $wgOAuth2Client['configuration']['service_name'] ) && 0 < strlen( $wgOAuth2Client['configuration']['service_name'] ) ? $wgOAuth2Client['configuration']['service_name'] : 'OAuth2' );

		$wgOut->setPagetitle( wfMessage( 'oauth2client-login-header', $service_name)->text() );
		$user = RequestContext::getMain()->getUser();
		if ( !$user->isRegistered() ) {
			$wgOut->addWikiMsg( 'oauth2client-you-can-login-to-this-wiki-with-oauth2', $service_name );
			$wgOut->addWikiMsg( 'oauth2client-login-with-oauth2', $this->getPageTitle( 'redirect' )->getPrefixedURL(), $service_name );

		} else {
			$wgOut->addWikiMsg( 'oauth2client-youre-already-loggedin' );
		}
		return true;
	}

	/**
	 * Optional callback settings in global $wgOAuth2Client
	 *
	 * Provide a callback and error message in the configuration that evaluates
	 * a conditional based upon the result of some business logic provided by
	 * the authorization endpoint response.
	 * $wgOAuth2Client['configuration']['authz_callback']
	 * $wgOAuth2Client['configuration']['authz_failure_message']
	 */
	protected function _userHandling( $response ) {
		global $wgOAuth2Client, $wgAuth, $wgRequest;

		if (
			isset($wgOAuth2Client['configuration']['authz_callback'])
			&& false === $wgOAuth2Client['configuration']['authz_callback']($response)
		) {
			$callback_failure_message = isset($wgOAuth2Client['configuration']['authz_failure_message'])
				? $wgOAuth2Client['configuration']['authz_failure_message']
				: 'Not authorized';
			throw new MWException($callback_failure_message);
		}

		$username = JsonHelper::extractValue($response, $wgOAuth2Client['configuration']['username']);
		$email =  JsonHelper::extractValue($response, $wgOAuth2Client['configuration']['email']);
		$allowed_domains = ( isset( $wgOAuth2Client['configuration']['allowed_domains'] ) ? $wgOAuth2Client['configuration']['allowed_domains'] : null );

		if ( $allowed_domains != NULL &&  sizeof( $allowed_domains ) > 0 ) {
			// Domain whitelist configured, make sure the requesting user is one of the provided domains.
			$domain_name = substr( strrchr( $email, '@' ), 1);
			if ( !in_array( $domain_name, $allowed_domains ) ) {
				// Valid account, but not whitelisted.
				return null;
			}
		}
		Hooks::run('OAuth2ClientBeforeUserSave', [&$username, &$email, $response]);

		$user = User::newFromName($username, 'creatable');
		if (!$user) {
			throw new MWException('Could not create user with username:' . $username);
			die();
		}
		$user->setRealName($username);
		$user->setEmail($email);
		$user->load();
		if ( !( $user instanceof User && $user->getId() ) ) {
			$user->addToDatabase();
			// MediaWiki recommends below code instead of addToDatabase to create user but it seems to fail.
			// $authManager = MediaWiki\Auth\AuthManager::singleton();
			// $authManager->autoCreateUser( $user, MediaWiki\Auth\AuthManager::AUTOCREATE_SOURCE_SESSION );
			$user->confirmEmail();
		}
		$user->setToken();

		// Setup the session
		$wgRequest->getSession()->persist();
		$user->setCookies();
		$this->getContext()->setUser( $user );
		$user->saveSettings();
		RequestContext::getMain()->setUser( $user );
		$sessionUser = User::newFromSession($this->getRequest());
		$sessionUser->load();
		return $user;
	}

}
