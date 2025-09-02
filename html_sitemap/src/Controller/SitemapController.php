<?php

namespace Drupal\html_sitemap\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

class SitemapController extends ControllerBase {

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a SitemapController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Generates the HTML sitemap page.
   *
   * @return array
   *   Render array for the HTML sitemap page.
   */
  public function sitemapPage() {
    // Query the xmlsitemap table to get the node IDs of published content.
    $query = $this->database->select('xmlsitemap', 'x')
      ->fields('x', ['id'])
      ->condition('type', 'node')
      ->condition('status', 1);
    $nids = $query->execute()->fetchCol();

    // Load the nodes and filter only the published ones.
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    $content = [];

    // Add the Home page first if it exists.
    foreach ($nodes as $key => $node) {
      if (strtolower($node->label()) == 'home' && $node->isPublished()) {
        $content[] = [
          'url' => $node->toUrl()->toString(),
          'title' => $node->label(),
        ];
        unset($nodes[$key]); // Remove the Home node from the list
        break; // We assume there is only one Home node
      }
    }

    // Prepare the remaining content array and sort it alphabetically by title.
    $remaining_content = [];
    foreach ($nodes as $node) {
      if ($node->isPublished()) {
        $remaining_content[] = [
          'url' => $node->toUrl()->toString(),
          'title' => $node->label(),
        ];
      }
    }

    // Sort the remaining content alphabetically by title.
    usort($remaining_content, function ($a, $b) {
      return strcasecmp($a['title'], $b['title']);
    });

    // Merge the content arrays, with Home first and the rest in alphabetical order.
    $content = array_merge($content, $remaining_content);

    return [
      '#theme' => 'html_sitemap',
      '#content' => $content,
    ];
  }

}
