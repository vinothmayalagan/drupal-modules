<?php

namespace Drupal\other_site_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use GuzzleHttp\Client;

class ExternalLoginController extends ControllerBase
{
    public function loginUser(Request $request)
    {
        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? '';

        if (!$this->validateToken($token)) {
            return new JsonResponse(['error' => 'Invalid token'], 403);
        }

        $email = $data['email'] ?? '';
        $username = $data['username'] ?? '';
        $roles = $data['roles'] ?? [];
        $name = $data['name'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($email) || empty($username)) {
            return new JsonResponse(['error' => 'Missing required user details'], 400);
        }

        $user = user_load_by_mail($email);

        if (!$user) {
            $user = User::create([
                'name' => $username,
                'mail' => $email,
                'status' => 1,
                'roles' => $roles,
            ]);

            $user->set('init', $email);
            $user->set('langcode', 'en');
            $user->set('preferred_langcode', 'en');
            $user->set('preferred_admin_langcode', 'en');
            $user->setPassword(Null);

            $user->save();
        } else {
            $user->setPassword(Null);
            $user->save();
        }

        $uid = $user->id();

        // Return the UID to the client.
        return new JsonResponse(['uid' => $uid]);
    }


    /**
     * Validate the token.
     */
    private function validateToken($token)
    {
        // Decode the token.
        $decoded = base64_decode($token);
        \Drupal::logger('other_site_auth')->debug('Decoded token: @decoded', ['@decoded' => $decoded]);

        // Split the token into UID, timestamp, and HMAC.
        $parts = explode('|', $decoded);
        if (count($parts) !== 3) {
            \Drupal::logger('other_site_auth')->error('Invalid token format. Decoded token: @decoded', ['@decoded' => $decoded]);
            return FALSE;
        }

        [$uid, $timestamp, $hmac] = $parts;

        // Validate the HMAC.
        $secret = 'shared_secret_key'; // Same key as the centralized site.
        $expected_hmac = hash_hmac('sha256', "$uid|$timestamp", $secret, false);

        if (!hash_equals($expected_hmac, $hmac)) {
            \Drupal::logger('other_site_auth')->error('HMAC validation failed. Expected: @expected, Received: @received', [
                '@expected' => $expected_hmac,
                '@received' => $hmac,
            ]);
            return FALSE;
        }

        // (Optional) Check if the token has expired.
        $time_difference = time() - $timestamp;
        if ($time_difference > 300) { // Allow 5 minutes.
            \Drupal::logger('other_site_auth')->error('Token expired. Timestamp difference: @diff seconds', ['@diff' => $time_difference]);
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Handles user login redirect by UID.
     */
    public function userLoginRedirect()
    {
        $uid = \Drupal::request()->query->get('uid');

        if ($uid) {
            $user = User::load($uid);

            if ($user) {
                // Log in the user programmatically.
                user_login_finalize($user);

                // Redirect to the homepage or desired route.
                return new RedirectResponse(Url::fromRoute('user.login', [], ['absolute' => TRUE])->toString());
            }
        }

        // Redirect to the login page if UID is invalid or user not found.
        return new RedirectResponse(Url::fromRoute('user.login', [], ['absolute' => TRUE])->toString());
    }


    /**
     * Logs the user out of the subsite and redirects to the centralized site's login page.
     */
    public function logout()
    {
        // Perform local logout.
        wp_user_logout();

        // Get the session cookie name using PHP's session_name().
        $session_name = session_name();

        // Delete the session cookie by setting it with an expiration in the past.
        setcookie($session_name, '', time() - 3600, '/', '', true, true);

        // URL of the centralized site's login page.
        $centralized_login_url = 'http://localhost/wp_centralize/web/logout';

        // Redirect to the centralized site's login page using a trusted redirect.
        return new TrustedRedirectResponse($centralized_login_url);
    }
}
