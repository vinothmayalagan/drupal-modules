<?php

namespace Drupal\centralized_site_auth\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Subscribes to the logout route to perform additional logout logic.
 */
class LogoutRouteSubscriber extends RouteSubscriberBase
{
    /**
     * {@inheritdoc}
     */
    protected function alterRoutes(RouteCollection $collection)
    {
        // Override the user.logout route.
        if ($route = $collection->get('user.logout')) {
            $route->setDefault('_controller', '\Drupal\centralized_site_auth\Controller\ExternalLoginController::logout');
        }
    }
}
