<?php

namespace Drupal\shopify_api_data\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for fetching Shopify data using the API.
 */
class ShopifyApiDataController extends ControllerBase
{
  /**
   * The HTTP client service.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  protected $entityFieldManager;

  protected $entityTypeBundleInfo;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  protected $renderer;

  /**
   * Constructor for ShopifyApiDataController.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    ClientInterface $http_client,
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
    RendererInterface $renderer,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info
  ) {
    $this->httpClient = $http_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->renderer = $renderer;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('http_client'),
      $container->get('entity_type.manager'),
      $container->get('messenger'),
      $container->get('renderer'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info')
    );
  }

  private function createImageFolder()
  {
    $directory = 'public://Product-Image';
    $file_system = \Drupal::service('file_system');
    if (!$file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
      $this->messenger->addError(t('Failed to create the image folder.'));
    }
    return $directory;
  }

  /**
   * Fetches data from Shopify using the API and creates or updates content.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response with the result of the data sync.
   */
  public function fetchData()
  {
    try {
      $database = \Drupal::database();
      $current_user = \Drupal::currentUser()->id();
      $roles = array_values(\Drupal::currentUser()->getRoles(1));
      $role = $roles[0];

      $query = $database
        ->select('shopify_credentials', 'sc')
        ->fields('sc', ['api_key', 'api_secret_key', 'storeID'])
        ->condition('uid', $current_user)
        ->condition('roles', $role)  // Modify the index as needed.
        ->range(0, 1)
        ->execute()
        ->fetchAll();
      if ($query && isset($query[0]->api_key) && isset($query[0]->api_secret_key) && isset($query[0]->storeID)) {
        $api_key = ($query[0]->api_key);
        $api_password = ($query[0]->api_secret_key);
        $shopify_store = ($query[0]->storeID);
        $endpoint = "https://{$api_key}:{$api_password}@{$shopify_store}.myshopify.com/admin/api/2023-07/products.json";
        // dd($endpoint);

        $response = $this->httpClient->get($endpoint);
      } else {
        $this->messenger->addError($this->t('Failed to fetch Shopify credentials.'));
        // code for access Denied
        $url = Url::fromRoute('system.403');
        return new RedirectResponse($url->toString());
      }
      // Check if the request was successful.
      if ($response->getStatusCode() == 200) {
        $data = $response->getBody()->getContents();
        $decode = json_decode($data);

        if (isset($decode) && isset($decode->products)) {
          $this->syncNodes($decode->products, $shopify_store);
          $this->getImageUrl($decode->products);
          $this->messenger->addStatus($this->t('Data synced successfully.'));
          // Retrieve and render messages.
          $messages = $this->messenger->all();
          $rendered_messages = $this->renderer->renderRoot($messages);
          // Create a response with the status message.
          $content = new Response('Data synced successfully.', 200);  // Replace with your actual content.
          $response = new Response($rendered_messages);

          return [
            '#markup' => $response,
          ];
        }
      } else {
        $this->messenger->addError(t('Failed to fetch data from Shopify API.'));

        // Retrieve and render messages.
        $messages = $this->messenger->all();
        $rendered_messages = $this->renderer->renderRoot($messages);
        $response = new Response($rendered_messages);

        return [
          '#markup' => $response
        ];
      }
    } catch (\Exception $e) {
      $this->messenger->addError(t('An error occurred: @error', ['@error' => $e->getMessage()]));
      return new Response('An error occurred: ' . $e->getMessage(), 500);
    }
  }

  /**
   * Synchronize nodes based on the Shopify product data.
   *
   * @param array $products
   *   An array of Shopify product data.
   */
  private function syncNodes(array $products, $storeid)
  {
    // dd($products);
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $contentType = $this->entityTypeManager->getStorage('node_type')->load('shopify_product');
    if (!$contentType) {
      $contentType = $this->entityTypeManager->getStorage('node_type')->create([
        'type' => 'shopify_product',
        'name' => 'Shopify Product',
      ]);
      $contentType->save();
      $this->messenger->addStatus(t('Content type "Shopify Product" created successfully.'));
    }
    // dd($products);

    foreach ($products as $key => $productData) {
      $title = $productData->title;
      $body_html = $productData->body_html;
      $image_url = $productData->image->src;
      $image_id = $this->uploadImage($image_url);
      $price = $productData->variants[0]->price;
      $productStatus = $productData->status;
      $vendor = $productData->vendor;
      $varientDefault = $productData->variants->id;
      $varientDefaultTitle = $productData->variants->title;
      // dump($productData->variants);
      $tags = $productData->tags;
      $variants = $productData->variants;

      $tagsarr = array_map('trim', explode(',', $tags));

      $string = basename($image_url);
      $parts = explode('?', $string);
      $base_url = $parts[0];
      $imagepath = $this->createImageFolder() . '/' . $base_url;
      $nodes = $nodeStorage->loadByProperties(['type' => 'shopify_product', 'title' => $title]);
      $vocabulary = 'collection';
      if ($productStatus == 'active') {
        if (!$nodes) {
          $node = $nodeStorage->create([
            'type' => 'shopify_product',
            'field_store_name' => $vendor,
            'field_store_id' => $storeid,
            'field_shopify_description' => $body_html,
            // 'field_variant_default' => ($varientDefaultTitle == 'Default Title') ? $varientDefault : null,
            'title' => $title,
            'field_shopify_title' => $title,
            'field_shopify_price' => $price,
            'field_shopify_image' => [
              'target_id' => $image_id,
              'alt' => 'img',
              'title' => $title . 'image',
              'width' => $productData->images[0]->width,
              'height' => $productData->images[0]->height,
              'uri' => $imagepath,
            ]
          ]);
          $current_tags = [];

          foreach ($tagsarr as $tag) {
            if (!empty($tag)) {
              // dump($tag);
              $term = $this->loadOrCreateTerm($tag, $vocabulary);
              if ($term) {
                $node->get('field_product_tags')->appendItem(['target_id' => $term->id()]);
              }
            }
          }
          foreach ($variants as $variant) {
            if ($variant) {
              $varianttitle = $variant->title;
              $variantId = $variant->id;
              $variantprice = $variant->price;
              $variantProductId = $variant->product_id;

              $product_title = str_replace(' ', '-', $title);
              $link = "https://{$storeid}.myshopify.com/products/{$product_title}?variant={$variantId}";
              if ($varianttitle != 'Default Title') {
                $paragraph = Paragraph::create([
                  'type' => 'shopify_var',
                  'field_variant_id' => $variantId,
                  'field_variant_price' => $variantprice,
                  'field_variant_product_id' => $variantProductId,
                  'field_variant_title' => $varianttitle,
                ]);

                $node->get('field_shopify_variant')->appendItem(['entity' => $paragraph]);
                // dd($node);
              } else {
                $node->set('field_variant_default', $variantId);
              }
            }

            $node->save();
          }

          $this->messenger->addStatus(t('Node @title created successfully.', ['@title' => $title]));
        } else {
          // dd($tags);
          $node = reset($nodes);
          $node->get('field_shopify_description')->setValue($body_html);
          $node->get('field_shopify_title')->setValue($title);
          $node->get('field_store_name')->setValue($vendor);
          $node->get('field_shopify_price')->setValue($price);
          $node->get('field_store_id')->setValue($storeid);
          // dd($varientDefault,$productData->variants->id);

          $node->get('field_shopify_image')->setValue([
            'target_id' => $image_id,
            'alt' => 'img',
            'title' => $title . ' image',
            'uri' => $imagepath,
          ]);
          // dd($variants);

          // Loop through new tags and append only if they don't exist already.
          foreach ($tagsarr as $tag) {
            foreach ($node->get('field_product_tags') as $tag_item) {
              $term_id = $tag_item->target_id;
              $term = Term::load($term_id);
              if ($term) {
                $current_tags[] = $term->getName();
              }
            }
          }
          if (!empty($tag) && !in_array($tag, $current_tags)) {
            $term = $this->loadOrCreateTerm($tag, $vocabulary);
            if ($term) {
              $node->get('field_product_tags')->appendItem(['target_id' => $term->id()]);
            }
          }
          foreach ($variants as $variant) {
            if ($variant) {
              $varianttitle = $variant->title;
              $variantId = $variant->id;
              $variantprice = $variant->price;
              $variantProductId = $variant->product_id;

              if ($varianttitle == 'Default Title') {
                $node->get('field_variant_default')->setValue($variantId);
              } else {
                $node->get('field_variant_default')->setValue(null);
              }
              // dump($variant);
              $product_title = str_replace(' ', '-', $title);
              $link = "https://{$storeid}.myshopify.com/products/{$product_title}?variant={$variantId}";
              // Check if a paragraph with this variant ID already exists
              $paragraphs = $node->get('field_shopify_variant')->referencedEntities();
              $existingParagraph = null;

              foreach ($paragraphs as $paragraph) {
                if ($paragraph->get('field_variant_id')->value == $variantId) {
                  $existingParagraph = $paragraph;
                  break;
                }
              }

              if ($varianttitle != 'Default Title') {
                if ($existingParagraph) {
                  // Update the existing paragraph
                  // dd($existingParagraph);
                  $existingParagraph->set('field_variant_price', $variantprice);
                  $existingParagraph->set('field_variant_product_id', $variantProductId);
                  $existingParagraph->set('field_variant_title', $varianttitle);
                  $existingParagraph->save();
                } else {
                  // Create a new paragraph
                  $paragraph = Paragraph::create([
                    'type' => 'shopify_var',
                    'field_variant_id' => $variantId,
                    'field_variant_price' => $variantprice,
                    'field_variant_product_id' => $variantProductId,
                    'field_variant_title' => $varianttitle,
                  ]);

                  // Append the paragraph to the node
                  $node->get('field_shopify_variant')->appendItem(['entity' => $paragraph]);
                }
              }
            }

            // Save the node after adding all variants
          }

          $node->save();
          $this->messenger->addStatus(t('Node @title updated successfully.', ['@title' => $title]));
        }
      } else {
        return [
          '#markup' => 'Only active Products added'
        ];
      }
    }
  }

  private function uploadImage($image_url)
  {
    $image_data = $this->httpClient->get($image_url);
    $file_system = \Drupal::service('file_system');
    $file_data = $image_data->getBody();

    $directory = 'public://images';
    if (!$file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
      $this->messenger->addError(t('Failed to create the image folder.'));
      return NULL;
    }

    $file_contents = file_get_contents($image_url);

    if ($file_contents) {
      $extension = pathinfo($image_url, PATHINFO_EXTENSION);
      $string = basename($image_url);
      $parts = explode('?', $string);
      $base_url = $parts[0];
      $mime_type = \Drupal::service('file.mime_type.guesser')->guessMimeType($extension);

      $destination = $directory . '/' . $base_url;
      $existing_file = $this->getExistingFileByUri($destination);
      if ($existing_file) {
        // Load the existing file entity.
        $file_entity = File::load($existing_file->id());
        $file_system->saveData($file_data, $destination, FileSystemInterface::EXISTS_REPLACE);

        $file_entity->setMimeType($mime_type);
        $file_entity->setPermanent();
        $file_entity->save();

        return $file_entity->id();
      }
      $uid = \Drupal::currentUser()->id();

      $file_system->saveData($file_data, $destination, FileSystemInterface::EXISTS_REPLACE);

      $file = File::create([
        'uri' => $destination,
        'filemime' => $mime_type,
        'uid' => $uid
      ]);

      $file->setPermanent();
      $file->save();

      return $file->id();
    }

    return NULL;
  }

  public function getImageUrl(array $product)
  {
    // Replace this with your logic to fetch the image URL.
    $productsValue = $product;
    $imageUrls = [];

    if ($productsValue != NULL) {
      foreach ($productsValue as $key => $imageValue) {
        $imageUrl = $imageValue->images[0]->src;
        $imageUrls[] = $imageUrl;
      }
    }
    // dd($imageUrls);
    // return $imageUrls;
    return new JsonResponse($imageUrls);
  }

  /**
   * Get an existing file entity by URI.
   *
   * @param string $uri
   *   The URI of the file.
   *
   * @return \Drupal\file\Entity\FileInterface|null
   *   The existing file entity, or NULL if not found.
   */
  private function getExistingFileByUri($uri)
  {
    $query = \Drupal::entityQuery('file')
      ->condition('uri', $uri)
      ->accessCheck(TRUE)
      ->execute();

    if (!empty($query)) {
      $file_id = reset($query);
      return \Drupal\file\Entity\File::load($file_id);
    }

    return NULL;
  }

  /**
   * Controller for displaying a welcome message with a link.
   */
  public static function Shopify_page_sync()
  {
    $current_user = \Drupal::currentUser();
    $username = $current_user->getAccountName();
    $value = 'Welcome User ' . $username;

    // Create the first link with attributes.
    $link_text = t('Fetch Data From Shopify');
    $link_url = '/admin/shopify-fetch-data';  // Replace this with the desired URL.
    $link = Link::fromTextAndUrl($link_text, Url::fromUserInput($link_url));
    $link = $link->toRenderable();
    $link['#attributes']['class'] = ['btn', 'btn-success'];
    $link = Markup::create(\Drupal::service('renderer')->render($link));

    // Create the second link with attributes.
    $links_text = t('Shopify Credentials Form For Fetch Data');
    $links_url = '/admin/shopifyCredentials/form';  // Replace this with the desired URL.
    $links_form = Link::fromTextAndUrl($links_text, Url::fromUserInput($links_url));
    $links_form = $links_form->toRenderable();
    $links_form['#attributes']['class'] = ['btn', 'btn-warning'];
    $links_form = Markup::create(\Drupal::service('renderer')->render($links_form));

    // Combine the welcome message and links using line breaks.
    $html = '<h5> Click Below For Fetch Shopify !!</h5>';
    $markup = '<h2>' . $value . '</h2>' . '<br>' . $html . '<br>' . '<br>' . $link . '<br>' . '<br>' . $links_form;

    return [
      '#markup' => $markup,
    ];
  }

  /**
   * Load or create a taxonomy term.
   *
   * @param string $name
   *   The name of the term.
   * @param string $vocabulary
   *   The machine name of the vocabulary.
   *
   * @return \Drupal\taxonomy\Entity\Term|null
   *   The loaded or created term, or NULL on failure.
   */
  private function loadOrCreateTerm($name, $vocabulary)
  {
    $term = NULL;
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $query = $term_storage
      ->getQuery()
      ->condition('name', $name)
      ->condition('vid', $vocabulary)
      ->accessCheck(FALSE);

    $term_ids = $query->execute();
    if (!empty($term_ids)) {
      $term = Term::load(reset($term_ids));
    } else {
      $values = [
        'name' => $name,
        'vid' => $vocabulary,
      ];
      $term = $term_storage->create($values);
      $term->save();
    }

    return $term;
  }
}
