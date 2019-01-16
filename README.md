# MW-OAuth2Client
Patch to an existing MW-OAuth2Client (Mediawiki OAuth2 client)

## Motivation
We were using [GoogleLogin Mediawiki extension](https://www.mediawiki.org/wiki/Extension:GoogleLogin) for our internal wiki, however with Google's decision of shutting down Google+ login & API (which the extension uses), we needed to either modify the GoogleLogin extension or look into alternative extensions. 
We found this [MW-OAuth2Client extension](https://www.mediawiki.org/wiki/Extension:OAuth2_Client) and it works fine, however this extension doesn't have a domain restriction. We use our company G-suite domains for wiki authentication so some sort of domain restriction was required.

## Changes
This patch has a change to apply a domain restriction (configurable). If a valid Google account is not in the specified list of domains, the login request will be rejected.

## How to use
If you want to restrict to a certain domain(s), add this in LocalSettings.php:

```$wgOAuth2Cilent['configuration']['allowed_domains'] = array( _LIST_OF_DOMAINS_ );```

If no restriction is needed, you can leave the configuration option off. 
