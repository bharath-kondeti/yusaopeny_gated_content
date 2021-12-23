<?php

namespace Drupal\openy_gc_shared_content\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Session\AccountInterface;
use Drupal\media\Plugin\media\Source\Image;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\Serializer;

/**
 * The SharedContentController class.
 */
class SharedContentController extends ControllerBase {

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * The serializer.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * SharedContentController constructor.
   *
   * @param \Drupal\Core\Datetime\DateFormatter $date_formatter
   *   The date formatter.
   * @param \Symfony\Component\Serializer\Serializer $serializer
   *   The serializer.
   */
  public function __construct(DateFormatter $date_formatter, Serializer $serializer) {
    $this->dateFormatter = $date_formatter;
    $this->serializer = $serializer;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('date.formatter'),
      $container->get('serializer')
    );
  }

  /**
   * Return the data.
   *
   * @return Symfony\Component\HttpFoundation\JsonResponse
   *   Return the JSON data.
   */
  public function index(string $type) {
    if (!in_array($type, ['gc_video', 'vy_blog_post'])) {
      return new JsonResponse(['error' => ['code' => '400']], 400);
    }

    return new JsonResponse(
      $this->getData($type) + [
        'method' => 'GET',
        'status' => 200,
      ]);
  }

  /**
   * Get the data to be returned.
   *
   * @return array
   *   The data to be returned.
   */
  public function getData($type) {
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $serializer = $this->serializer;

    $result = [];
    $included = [];
    $meta = [];
    $access_denied = FALSE;

    // Fetch all nodes of $type that are published and shared.
    $query = $node_storage->getQuery()
      ->condition('type', $type)
      ->condition('field_gc_share', 1)
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->accessCheck(TRUE);
    $nodes_ids = $query->execute();

    if ($nodes_ids) {
      foreach ($nodes_ids as $node_id) {
        /** @var Drupal\node\Entity\Node $node */
        $node = $node_storage->load($node_id);

        if (!$node->access('view')) {
          $access_denied = TRUE;
          break;
        }

        // Load each node then normalize it into a JSON-formatted array.
        $result[] = $serializer->normalize($node, 'json', ['plugin_id' => 'entity']);

        // Mimic JSON:API's 'include' section by recording any referenced items.
        foreach (end($result) as $field => $values) {
          if (strstr($field, 'field_')) {
            foreach ($values as $value) {
              if (isset($value['target_type']) && in_array(
                $value['target_type'], ['taxonomy_term', 'media'])) {
                // We don't need this back-reference.
                unset($value['target_uuid']);
                // Start building the array with field names as the key.
                $included[$field][] = $value;
              }
            }
          }
        }
      }

      // Dedupe each set of included entities.
      foreach ($included as $field => $values) {
        $included[$field] = array_values(array_unique($values, SORT_REGULAR));
      }

      // Recurse through the references and load the actual entities.
      $included_loaded = [];
      foreach ($included as $field => $values) {
        foreach ($values as $value) {
          // Load the entity, then normalize it to an array.
          $entity = $this->entityTypeManager()
            ->getStorage($value['target_type'])->load($value['target_id']);
          $included_loaded[$field][] = $serializer->normalize($entity, 'json');

          // If this is a media entity, extract the file and load it too.
          if (strstr($field, '_image') && $entity->getSource() instanceof Image) {
            $fid = $entity->getSource()->getSourceFieldValue($entity);
            $file = $this->entityTypeManager()->getStorage('file')->load($fid);
            $included_loaded['file'][] = $serializer->normalize($file, 'json');
          }
        }
      }

    }

    if ($access_denied) {
      $meta = ['omitted' => ["detail" => $this->t("Some resources have been omitted because of insufficient authorization.")]];
    }

    return ['data' => $result, 'included' => $included_loaded, 'meta' => $meta];
  }

  /**
   * Checks access for a specific request.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Run access checks for this account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account) {
    // Access checks are done when entities are loaded above,
    // which calls Drupal\openy_gated_content\SegmentContentAccessCheck.
    return AccessResult::allowed();
  }

}
