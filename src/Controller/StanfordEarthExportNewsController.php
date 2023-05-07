<?php

namespace Drupal\stanford_earth_export_news\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Entity\Query\QueryInterface;

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

  protected $taxonomyTerms;

  protected $other_stuff;

  protected $images;

  protected $files;

  protected $videos;

  protected $paragraph_types;

  /**
   * Entity Type Manager
   *
   * @var Drupal\Core\Entity\EntityTypeManager
   *   The thing.
   */
  protected $em;

  /**
   * StanfordEarthExportNewsController constructor.
   */
  public function __construct(KillSwitch $killSwitch)
  {
    $this->killSwitch = $killSwitch;
    $this->taxonomyTerms = [];
    $this->other_stuff = [];
    $this->images = [];
    $this->files = [];
    $this->videos = [];
    $this->paragraph_types = [];
    $this->em = \Drupal::entityTypeManager();
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

  private function getTerms($vocabulary, $field_value) {
    $terms = [];
    if (!empty($field_value)) {
      foreach ($field_value as $term_value) {
        $terms[] = [
          'term_name' => $this->em->getStorage('taxonomy_term')
            ->load($term_value['target_id'])->label(),
        ];
      }
      $field_value = $terms;
      foreach ($terms as $key => $value) {
        if (!isset($this->taxonomyTerms[$vocabulary])) {
          $this->taxonomyTerms[$vocabulary] = [];
        }
        if (isset($this->taxonomyTerms[$vocabulary][$value['term_name']])) {
          $this->taxonomyTerms[$vocabulary][$value['term_name']] += 1;
        }
        else {
          $this->taxonomyTerms[$vocabulary][$value['term_name']] = 1;
        }
      }
    }
    return $terms;
  }

  private function getParagraphValues($pid)
  {
    $pvalues = [];
    /** @var \Drupal\paragraphs\Entity $paragraph */
    $paragraph = $this->em->getStorage('paragraph')->load($pid);
    if (!empty($paragraph)) {
      $fields = $paragraph->getFields();
      foreach ($fields as $field_name => $field_obj) {
        if (substr($field_name, 0, 6) === "field_") {
          if (isset($this->paragraph_types[$field_name])) {
            $this->paragraph_types[$field_name] += 1;
          }
          else {
            $this->paragraph_types[$field_name] = 1;
          }
          $pval = $paragraph->get($field_name)->getValue();
          if (!empty($pval)) {
            foreach ($pval as $ppval) {
              if (!empty($ppval['value'])) {
                $pvalues[$field_name][] = $this->tokenizeMediaTags($ppval['value']);
              }
              else if (!empty($ppval['target_id'])) {
                if (strpos($field_name, 'media') !== false) {
                  $pvalues[$field_name] = $this->getMedia($pval);
                }
                else {
                  $pvalues[$field_name][$ppval['target_id']] = $this->getParagraphValues($ppval['target_id']);
                }
              }
              else if (!empty($ppval)) {
                $pvalues[$field_name] = $ppval;
              }
            }
          }
        }
      }
    }
    return $pvalues;
  }

  private function getMedia($field_value, $by_uuid = false) {
    $media_info = [];
    /** @var \Drupal\media\Entity $media */
    $media = null;
    if ($by_uuid) {
      $media = $this->em->getStorage('media')->loadByProperties(['uuid' => $field_value]);
      $media = reset($media);
    }
    else {
      if (!empty($field_value[0]['target_id'])) {
        $media = $this->em->getStorage('media')->load($field_value[0]['target_id']);
      }
    }
    if (!empty($media)) {
      $bundle = $media->bundle(); //  $media->get('bundle')->getValue();
      if (empty($bundle)) {
        return [];
      }
      $mid = $media->id();
      $media_info = ['mid' => $mid, 'type' => $bundle ];
      $file = [];
      if ($bundle === 'image') {
        $file = $media->get('field_media_image')->getValue();
      }
      else if ($bundle === 'file') {
        $file = $media->get('field_media_file')->getValue();
      }
      else if ($bundle === 'video') {
        $file = $media->get('field_media_oembed_video')->getValue();
      }
      if (!empty($file[0])) {
        $media_info = array_merge($media_info,  $file[0]);
      }
      else {
        return [];
      }
      $media_info['name'] = "";
      $name = $media->get('name')->getValue();
      if (!empty($name[0]['value'])) {
        $media_info['name'] = $name[0]['value'];
      }
      if (!empty($media_info['target_id'])) {
        $fid = $media_info['target_id'];
        $file_entity = File::load($fid);
        if (!empty($file_entity)) {
          $file_uri = $file_entity->getfileuri();
          $media_info['url'] = "";
          if (!empty($file_uri)) {
            if (strpos($file_uri,"://") !== FALSE) {
              $uri = str_replace(" ", "%20", substr($file_uri,strpos($file_uri,"://")+3));
              $media_info['url'] = "https://earth.stanford.edu/sites/default/files/" . $uri;
            }
          }
          $media_info['filemime'] = $file_entity->getMimeType();
          $media_info['filesize'] = $file_entity->getSize();
        }
      }
    }
    unset($media_info['target_id']);
    if (isset($bundle)) {
      if ($bundle === 'image') {
        $this->images[strval($mid)] = $media_info;
      } else if ($bundle === 'file') {
        $this->files[strval($mid)] = $media_info;
      } else if ($bundle === 'video') {
        $this->videos[strval($mid)] = $media_info;
      }
      $media_info['id'] = strval($mid);
      $media_info['type'] = $bundle;
      unset($media_info['mid']);
      return [$media_info];
      //return ['id' => strval($mid), 'type' => $bundle];
    }
    else {
      return [];
    }
  }

  private function tokenizeMediaTags($value) {
    if (!empty($value) && strpos($value,"<drupal-media") !== false) {
      $newvalue = $value;
      $val_pos = 0;
      while (strpos($value, "<drupal-media", $val_pos) !== false) {
        $pos1 = strpos($value, "<drupal-media", $val_pos);
        $pos2 = strpos($value, "uuid=\"", $pos1);
        $pos3 = strpos($value, "\"", $pos2+6);
        $pos4 = strpos($value, "/drupal-media",$pos3);
        $uuid = substr($value, $pos2+6, $pos3-($pos2+6));
        $mid = $this->getMedia($uuid, true);
        if (!empty($mid['id'])) {
          $newvalue = str_replace($uuid, '['.$mid['id'].']',$newvalue);
        }
        $val_pos = $pos4+ 13;
      }
      $value = $newvalue;
    }
    return $value;
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
    $count = 0;
    $params = [];
    if ($request->getMethod() == 'GET') {
      $params = $request->query->all();
    } elseif ($request->getMethod() == 'POST') {
      $params = $request->request->all();
    }
    $start = 'all';
    $end = 'all';
    $node = '';
    if (!empty($params['node'])) {
      $node = $params['node'];
    }
    $category = '';
    if (!empty($params['category'])) {
      $catstr = $params['category'];
      switch ($catstr) {
        case 'alumni': {
          $category = '151';
          break;
        }
        case 'career-profile': {
          $category = '91';
          break;
        }
        case 'comings-and-goings': {
          $category = '161';
          break;
        }
        case 'deans-desk': {
          $category = '156';
          break;
        }
        case 'media-mention': {
          $category = '76';
          break;
        }
        case 'school-highlight': {
          $category = '71';
          break;
        }
        case 'earth-matters': {
          $category = '81';
          break;
        }
        case 'honors-and-awards': {
          $category = '711';
          break;
        }
        default: $category = '';
      }
    }

    if (empty($node) && empty($category) && empty($year)) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('You must include at least one url parameter such as<br />?year=2017, ?year=2018, etc.<br />or ?category=alumni, ?category=career-profile, ?category=comings-and-goings, ?category=deans-desk, ?category=media-mention, ?category=school-highlight, ?category=earth-matters, ?category=honors-and-awards'),
      ];
    }


    $items = [];
    $query = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('type', 'stanford_news');
    if (!empty($node)) {
      $query = $query->condition('nid', $node);
    } else {
      if (!empty($category)) {
        $query = $query->condition('field_s_news_category', $category);
      }
      if (!empty($params['year'])) {
        $query = $query->condition('field_s_news_date', $params['year'], 'STARTS_WITH');
      }
    }
    $nids = $query->execute();

    $nodes = \Drupal\node\Entity\Node::loadMultiple($nids);
    foreach ($nodes as $node) {
      $count = $count + 1;
      if (fmod($count, 100) == 0) {
        $xyz = 1;
      }
      $item = [
        'nid' => $node->id(),
        'title' => $node->getTitle(),
      ];
      $fields = $node->getFields();
      foreach ($fields as $field_name => $field_obj) {
        if (substr($field_name, 0, 6) === "field_"
          && $field_name !== 'field_news_source'
          && $field_name !== 'field_s_news_masonry_style'
          && $field_name !== 'field_s_news_stamp') {
          $field_value = $node->get($field_name)->getValue();
          if ($field_name === 'field_earth_matters_topic') {
            $field_value = $this->getTerms('earth_matters_topics', $field_value);
          }
          else if ($field_name === 'field_news_related_people' && !empty($field_value)) {
            $users = [];
            foreach ($field_value as $term_value) {
              /** @var \Drupal\Core\Session\AccountInterface $account */
              $account = $this->em->getStorage('user')->load($term_value['target_id']);
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
          else if ($field_name === 'field_s_news_category') {
            $field_value = $this->getTerms('news_categories', $field_value);
          }
          else if ($field_name === 'field_s_news_challenge') {
            $field_value = $this->getTerms('research_areas', $field_value);
          }
          else if ($field_name === 'field_s_news_department') {
            $field_value = $this->getTerms('department_program', $field_value);
          }
          else if ($field_name === 'field_s_news_earth_tags') {
            $field_value = $this->getTerms('earth_tags', $field_value);
          }
          else if ($field_name === 'field_s_news_media_contacts') {
            $paragraphs = [];
            foreach ($field_value as $pid_value) {
              $paragraphs[] = $this->getParagraphValues($pid_value['target_id']);
            }
            $field_value = "";
            if (!empty($paragraphs)) {
              foreach ($paragraphs as $parray) {
                if (!empty($parray['field_p_section_highlight_cards']['field_p_highlight_card_title'][0])) {
                  $field_value = "<span>" . $parray['field_p_section_highlight_cards']['field_p_highlight_card_title'][0]."</span>";
                  $this->other_stuff[$field_name][$parray['field_p_section_highlight_cards']['field_p_highlight_card_title'][0]] += 1;
                }
                if (!empty($parray['field_p_section_highlight_cards']['field_p_highlight_card_subtitle'][0])) {
                  $field_value .= "<span>" . $parray['field_p_section_highlight_cards']['field_p_highlight_card_subtitle'][0]."</span>";
                }
              }
            }
          }
          else if ($field_name === 'field_s_news_feat_media') {
            $field_value = $this->getMedia($field_value);
          }
          else if ($field_name === 'field_s_news_top_media') {
            $field_value = $this->getMedia($field_value);
          }
          else if ($field_name === 'field_s_news_summary') {
            if (!empty($field_value[0]['value'])) {
              $field_value[0]['value'] = $this->tokenizeMediaTags($field_value[0]['value']);
            }
          }
          else if ($field_name === 'field_s_news_rich_content') {
            $paragraphs = [];
            foreach ($field_value as $pid_value) {
              $paragraphs[] = $this->getParagraphValues($pid_value['target_id']);
            }
            $field_value = $paragraphs;
          }
          $item[$field_name] = $field_value;
        }
        else {
          if ($field_name === 'path') {
            $paths = $node->get($field_name)->getValue();
            foreach ($paths as $path) {
              unset($path['pid']);
              unset($path['langcode']);
            }
            $item[$field_name][] = $path;
          }
        }
      }
      $items[$item['nid']] = $item;
    }
    $this->killSwitch->trigger();
    $json = [
      'terms' => $this->taxonomyTerms,
      'paragraph_types' => $this->paragraph_types,
      'images' => $this->images,
      'files' => $this->files,
      'videos' => $this->videos,
      'media_contact_content' => $this->other_stuff,
      'nodes' => $items,
    ];
    return JsonResponse::create($json);

  }

}
