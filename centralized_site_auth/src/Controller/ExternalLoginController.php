<?php

namespace Drupal\centralized_site_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;

class ExternalLoginController extends ControllerBase
{

    /**
     * Logs the user out of the centralized site and redirects to the login page.
     */
    public function logout()
    {
        // dd("test 2");
        // Perform local logout.
        wp_user_logout();

        // Get the session cookie name using PHP's session_name().
        $session_name = session_name();

        // Delete the session cookie by setting it with an expiration in the past.
        setcookie($session_name, '', time() - 3600, '/', '', true, true);

        // Generate the URL for the user login page.
        $login_url = Url::fromRoute('user.login', [], ['absolute' => TRUE])->toString();

        // Redirect to the login page.
        return new TrustedRedirectResponse($login_url);
    }
}
