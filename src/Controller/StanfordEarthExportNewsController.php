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
  public function feed(Request $request)
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
    $this->killSwitch->trigger();
    return JsonResponse::create($items);

  }

}
