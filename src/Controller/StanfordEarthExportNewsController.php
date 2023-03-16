<?php

namespace Drupal\stanford_earth_export_news\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\node\Entity\Node;
use Drupal\Core\Entity\EntityTypeManager;

/**
 * Export news content nodes to JSON.
 */
class StanfordEarthExportNewsController extends ControllerBase
{

  /**
   * Page cache kill switch.
   *
   * @var Drupal\Core\PageCache\ResponsePolicy\KillSwitch
   *   The kill switch service.
   */
  protected $killSwitch;

  /**
   * StanfordEarthExportNewsController constructor.
   */
  public function __construct(KillSwitch $killSwitch)
  {
    $this->killSwitch = $killSwitch;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('page_cache_kill_switch')
    );
  }

  /**
   * Return an JSON file containing news data.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The currently processing request.
   *
   * @return Symfony\Component\HttpFoundation\JsonResponse
   *   JsonResponse object with news node data.
   */
  public function output(Request $request)
  {

    $params = [];
    if ($request->getMethod() == 'GET') {
      $params = $request->query->all();
    } elseif ($request->getMethod() == 'POST') {
      $params = $request->request->all();
    }
    $start = 'all';
    $end = 'all';
    $newsType = 'all';

    $items = [];

    $nids = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('type', 'stanford_news')
      ->execute();
    $nodes = \Drupal\node\Entity\Node::loadMultiple($nids);
    /** @var \Drupal\Core\Entity\EntityTypeManager $em */
    $em = \Drupal::entityTypeManager();
    foreach ($nodes as $node) {
      $item = [
        'nid' => $node->id(),
        'title' => $node->getTitle(),
      ];
      $fields = $node->getFields();
      foreach ($fields as $field_name => $field_obj) {
        if (substr($field_name, 0, 6) === "field_" && $field_name !== 'field_news_source') {
          $field_value = $node->get($field_name)->getValue();
          if ($field_name === 'field_earth_matters_topic' && !empty($field_value)) {
            //$em = \Drupal::entityTypeManager();
            $terms = [];
            foreach ($field_value as $term_value) {
              $terms[] = [
                'term_name' => $em->getStorage('taxonomy_term')->load($term_value['target_id'])->label(),
              ];
            }
            $field_value = $terms;
          }
          else if ($field_name === 'field_news_related_people' && !empty($field_value)) {
            $users = [];
            foreach ($field_value as $term_value) {
              /** @var \Drupal\Core\Session\AccountInterface $account */
              $account = $em->getStorage('user')->load($term_value['target_id']);
              if (!empty($account)) {
                $sunet = $account->getAccountName();
                if (empty($sunet)) {
                  $sunet = '';
                }
                $displayname = '';
                $dispname = $account->get('field_s_person_display_name')->getValue();
                if (!empty($dispname)) {
                  $dispname = reset($dispname);
                  if (!empty($dispname['value'])) {
                    $dispname = $dispname['value'];
                  }
                }
                if (!empty($dispname)) {
                  $displayname = $dispname;
                }
                $users[] = [
                  'sunetid' => $sunet,
                  'display_name' => $displayname,
                ];
              }
            }
            $field_value = $users;
          }
          $item[$field_name] = $field_value;
        }
      }
      $items[] = $item;
    }
    $this->killSwitch->trigger();
    return JsonResponse::create($items);

  }

}
