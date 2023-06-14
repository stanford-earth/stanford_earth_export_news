<?php

namespace Drupal\stanford_earth_export_news\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Mail\MailFormatHelper;

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

  protected $big_photo_cred_count;
  protected $big_image_cred_count;

  protected $paragraph_types;

  protected $embedded_media;

  protected $field_media;

  protected $data_view_mode;

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
    $this->embedded_media = [];
    $this->field_media = [];
    $this->data_view_mode = [];
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
          if ($field_name === 'field_p_hero_banner_photo_credit' ||
            $field_name === 'field_p_responsive_image_cred') {
            $txt_test = $pval[0]['value'];
            $txt_test = MailFormatHelper::htmlToText($txt_test);
            $txt_test = htmlspecialchars($txt_test);
            if (strlen($txt_test) > 1000) {
              if ($field_name === 'field_p_hero_banner_photo_credit') {
                $this->big_photo_cred_count += 1;
              }
              else if ($field_name === 'field_p_responsive_image_cred') {
                $this->big_image_cred_count += 1;
              }
            }
          }
          if (strpos($field_name, "link") !== false && !empty($pval) && is_array($pval) &&
            !empty($pval[0]['uri']) && str_starts_with($pval[0]['uri'], 'entity:node')) {
              $uri = $pval[0]['uri'];
              $alias = \Drupal::service('path_alias.manager')
                ->getAliasByPath(str_replace("entity:", "/", $uri));
              $pval[0]['uri'] = "https://earth.stanford.edu" . $alias;
          }
          if (!empty($pval)) {
            foreach ($pval as $ppval) {
              if (!empty($ppval['value'])) {
                $pvalues[$field_name][] = $this->expandMediaInfo($ppval['value']);
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

  private function getImageFileByUuid($field_value) {
    /** @var \Drupal\file\Entity $file */
    $file = $this->em->getStorage('file')
      ->loadByProperties(['uuid' => $field_value]);
    $file = reset($file);
    $media_info = [];
    if (!empty($file)) {
      $url = "";
      $file_uri = $file->getFileUri();
      if (!empty($file_uri)) {
        if (strpos($file_uri,"://") !== FALSE) {
          $uri = str_replace("&#039;", "", substr($file_uri,strpos($file_uri,"://")+3));
          $uri = str_replace("?", "", $uri);
          $url = "https://earth.stanford.edu/sites/default/files/" . $uri;
        }
      }
      $fixname = $file->getFilename();
      $fixname = str_replace("&#039;", "", $fixname);
      $fixname = str_replace("?", "", $fixname);
      $media_info = [
        'type' => 'image',
        'name' => $fixname,
        'url' => $url,
        'filemime' => $file->getMimeType(),
        'filesize' => $file->getSize(),
        'id' => "f" . strval($file->id()),
      ];
    }
    return [$media_info];
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
        $fixname = $name[0]['value'];
        $fixname = str_replace("&#039;", "", $fixname);
        $fixname = str_replace("?", "", $fixname);
        $media_info['name'] = $fixname;
      }
      if (!empty($media_info['target_id'])) {
        $fid = $media_info['target_id'];
        $file_entity = File::load($fid);
        if (!empty($file_entity)) {
          $file_uri = $file_entity->getfileuri();
          $media_info['url'] = "";
          if (!empty($file_uri)) {
            if (strpos($file_uri,"://") !== FALSE) {
              $uri = str_replace("&#039;", "", substr($file_uri,strpos($file_uri,"://")+3));
              $uri = str_replace("?", "", $uri);
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
        if (stripos($media_info['name'], "untitled") !== false ||
          stripos($media_info['url'], "untitled") !== false) {
          $fileinfo = pathinfo($media_info['url']);
          $sites_path = substr($fileinfo['dirname'], strpos($fileinfo['dirname'], "/sites"));
          $fpath = $_SERVER['DOCUMENT_ROOT'] . $sites_path;
          $oldname = $fpath . "/" . $fileinfo['basename'];
          $newbasename = "sdss_" . $fid . "." . $fileinfo['extension'];
          $newname = $fpath . "/" . $newbasename; //sdss_" . $fid . "." . $fileinfo['extension'];
          $update_media_info = true;
          if (!file_exists($newname)) {
           $update_media_info = copy($oldname, $newname);
          }
          if ($update_media_info) {
            $media_info['name'] = $newbasename;
            $media_info['url'] = str_replace($fileinfo['basename'], $newbasename, $media_info['url']);
          }
        }
        $this->images[strval($mid)] = $media_info;
        if (!$by_uuid) {
          $this->field_media[strval($mid)] = $media_info;
        }
      } else if ($bundle === 'file') {
        $this->files[strval($mid)] = $media_info;
      } else if ($bundle === 'video') {
        $this->videos[strval($mid)] = $media_info;
      }
      $media_info['id'] = strval($mid);
      $media_info['type'] = $bundle;
      unset($media_info['mid']);
      return [$media_info];
    }
    else {
      return [];
    }
  }

  private function expandMediaInfo($value) {
    $embedded_images = [];

    if (!empty($value) && strpos($value,"<drupal-media") !== false) {
      $val_pos = 0;
      while (strpos($value, "<drupal-media", $val_pos) !== false) {
        $pos1 = strpos($value, "<drupal-media", $val_pos);
        $pos2 = strpos($value, "uuid=\"", $pos1);
        $pos3 = strpos($value, "\"", $pos2+6);
        $pos4 = strpos($value, "/drupal-media",$pos3);
        $uuid = substr($value, $pos2+6, $pos3-($pos2+6));
        $mid = $this->getMedia($uuid, true);
        if (!empty($mid[0]['id'])) {
          $embedded_images[$uuid] = $mid[0];
          $embedded_images[$uuid]['embed'] = $uuid;
        }
        $val_pos = $pos4+ 13;
      }
    }

    if (!empty($value) && strpos($value,"<img") !== false) {
      $replacements = [];
      $val_pos = 0;
      while (strpos($value, "<img ", $val_pos) !== FALSE) {
        $pos1 = strpos($value, "<img ", $val_pos);
        $pos2 = strpos($value, "/>", $pos1);
        if ($pos2 === FALSE) {
          break;
        }
        $val_pos = $pos2;
        $img_str = substr($value, $pos1, ($pos2 + 2) - $pos1);
        if (strpos($img_str, "data-entity-uuid") !== FALSE) {
          $data_pairs = [];
          $cur_key = "";
          $cur_val = "";
          $started = false;
          $pairs = explode(" ", $img_str);
          foreach ($pairs as $pair) {
            if ($started) {
              $cur_val .= " " . $pair;
              if (strpos($pair, "\"") !== FALSE) {
                if (!empty($cur_key)) {
                  $data_pairs[$cur_key] = $cur_val;
                }
                $started = FALSE;
              }
            }
            else {
              if (strpos($pair, "=\"") !== FALSE) {
                $keyval = explode("=", $pair, 2);
                if (sizeof($keyval) == 2 && !empty($keyval[0])) {
                  $cur_key = $keyval[0];
                  $cur_val = $keyval[1];
                  if (str_ends_with($keyval[1], "\"")) {
                    $data_pairs[$cur_key] = $cur_val;
                  }
                  else {
                    $started = TRUE;
                  }
                }
              }
            }
          }
          foreach ($data_pairs as $cur_key => $cur_val) {
            $data_pairs[$cur_key] = str_replace("\"", "", $cur_val);
          }
          if (!empty($data_pairs['data-entity-uuid'])) {
            $uuid = $data_pairs['data-entity-uuid'];
            $mid = $this->getImageFileByUuid($uuid);

            if (!empty($data_pairs['alt'])) {
              $mid[0]['alt'] = $data_pairs['alt'];
            }
            if (!empty($data_pairs['width'])) {
              $mid[0]['width'] = $data_pairs['width'];
            }
            if (!empty($data_pairs['height'])) {
              $mid[0]['height'] = $data_pairs['height'];
            }
            if (!empty($mid[0]['id'])) {
              $embedded_images[$uuid] = $mid[0];
              $embedded_images[$uuid]['embed'] = $uuid;
            }
            $media_tag = "<drupal-media ";
            if (!empty($data_pairs['data-align'])) {
              $media_tag .= "data-align=\"" . $data_pairs['data-align'] . "\" ";
            }
            if (!empty($data_pairs['data-caption'])) {
              $media_tag .= "data-caption=\"" . $data_pairs['data-caption'] . "\" ";
              $hash = substr(md5($data_pairs['data-caption']),0,5);
              $media_tag .= "data-caption-hash=\"" . $hash . "\" ";
            }
            $media_tag .= "data-entity-type=\"media\" ";
            $media_tag .= "data-entity-uuid=\"" . $uuid . "\" ";
            $media_tag .= "data-view-mode=\"default\"></drupal-media>";
            $replacements[] = [
              'old' => $img_str,
              'new' => $media_tag,
            ];
          }
        }
      }
      foreach($replacements as $replacement) {
        $value = str_replace($replacement['old'], $replacement['new'], $value);
      }
    }

    if (!empty($embedded_images)) {
      $this->embedded_media = array_merge($this->embedded_media, $embedded_images);
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
    $node = '';
    if (!empty($params['node'])) {
      $node = $params['node'];
    }
    $year = '';
    if (!empty($params['year'])) {
      $year = $params['year'];
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

    if (empty($node) && empty($year)) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('You must include at least one url parameter such as<br />?year=2017, ?year=2018, etc.<br />or ?node=123456'),
      ];
    }

    $this->big_image_cred_count = 0;
    $this->big_photo_cred_count = 0;
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
      $this->embedded_media = [];
      $this->field_media = [];
      $count = $count + 1;
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
                if (!empty($parray['field_highlight_cards_title'])) {
                  $field_value = '<h2>' . reset($parray['field_highlight_cards_title']) . '</h2>';
                }
                if (!empty($parray['field_p_section_highlight_cards'])) {
                  foreach ($parray['field_p_section_highlight_cards'] as $highlight_card) {
                    $field_value .= '<p>';
                    if (!empty($highlight_card['field_p_highlight_card_title'])) {
                      $field_value .= reset($highlight_card['field_p_highlight_card_title']) . '<br />';
                    }
                    if (!empty($highlight_card['field_p_highlight_card_subtitle'])) {
                      $field_value .= reset($highlight_card['field_p_highlight_card_subtitle']) . '<br />';
                    }
                    if (!empty($highlight_card['field_p_highlight_card_desc'])) {
                      $field_value .= strip_tags(reset($highlight_card['field_p_highlight_card_desc']),
                          ['<a>']) ;
                    }
                    $field_value .= '</p>';
                    $field_value = str_replace("\r\n", "", $field_value);
                  }
                }
              }
            }
          }
          else if ($field_name === 'field_s_news_feat_media') {
            $field_value = $this->getMedia($field_value);
          }
          else if ($field_name === 'field_s_news_top_media') {
            $paragraphs = [];
            foreach ($field_value as $pid_value) {
              $paragraphs[] = $this->getParagraphValues($pid_value['target_id']);
            }
            $field_value = $paragraphs;
          }
          else if ($field_name === 'field_s_news_summary' ||
            $field_name === 'field_s_news_teaser') {
            if (!empty($field_value[0]['value'])) {
              $field_value = [$this->expandMediaInfo($field_value[0]['value'])];
            }
          }
          else if ($field_name === 'field_s_news_rich_content') {
            $paragraphs = [];
            foreach ($field_value as $pid_value) {
              $paragraphs[] = $this->getParagraphValues($pid_value['target_id']);
            }
            if (!empty($paragraphs)) {
              $newParas = [];
              foreach ($paragraphs as $subpara) {
                if (!empty($subpara)) {
                  $xpara = [];
                  $key = 'card';
                  if (array_key_exists('field_p_banner_cards', $subpara)) {
                    $xpara = $subpara['field_p_banner_cards'];
                    unset($subpara['field_p_banner_cards']);
                    $key = 'field_p_banner_cards';
                  } else if (array_key_exists('field_p_feat_blocks_block', $subpara)) {
                    $xpara = $subpara['field_p_feat_blocks_block'];
                    unset($subpara['field_p_feat_blocks_block']);
                    $key = 'field_p_feat_blocks_block';
                  } else if (array_key_exists('field_p_filmstrip_slide', $subpara)) {
                    $xpara = $subpara['field_p_filmstrip_slide'];
                    unset($subpara['field_p_filmstrip_slide']);
                    $key = 'field_p_filmstrip_slide';
                  } else if (array_key_exists('field_p_tall_filmstrip_cards', $subpara)) {
                    $xpara = $subpara['field_p_tall_filmstrip_cards'];
                    unset($subpara['field_p_tall_filmstrip_cards']);
                    $key = 'field_p_tall_filmstrip_cards';
                  } else if (array_key_exists('field_p_doub_film_cards', $subpara)) {
                    $xpara = $subpara['field_p_doub_film_cards'];
                    unset($subpara['field_p_doub_film_cards']);
                    $key = 'field_p_doub_film_cards';
                  } else if (array_key_exists('field_p_link_banner_links', $subpara)) {
                    $xpara = $subpara['field_p_link_banner_links'];
                    unset($subpara['field_p_link_banner_links']);
                    $key = 'field_p_link_banner_links';
                  }
                  $newParas[] = $subpara;
                  if (!empty($xpara)) {
                    foreach($xpara as $xsubpara) {
                      $newParas[] = [$key => [$xsubpara]];
                    }
                  }
                }
              }
              $paragraphs = $newParas;
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
      if (!empty($item['field_s_news_media_contacts'])) {
        $item['field_s_news_rich_content'][] = ['field_p_wysiwyg' => [$item['field_s_news_media_contacts']]];
      }
      /*
      if (!empty($item['field_news_related_people'])) {
        $related_people = "";
        foreach ($item['field_news_related_people'] as $person_line) {
          if (!empty($person_line['display_name'])) {
            if (!empty($related_people)) {
              $related_people .= ", ";
            }
            $related_people .= $person_line['display_name'] ;
          }
        }
        if (!empty($related_people)) {
          $related_people = '<p>Related People: ' . $related_people . '</p>';
          $item['field_s_news_rich_content'][] = ['field_p_wysiwyg' => [$related_people]];
        }
      }
      */
      $item['embedded_media'] = $this->embedded_media;
      $item['field_media'] = $this->field_media;
      $items[$item['nid']] = $item;
    }
    $this->killSwitch->trigger();
    $json = [
      'big_photo_creds' => $this->big_photo_cred_count,
      'big_image_creds' => $this->big_image_cred_count,
      'terms' => $this->taxonomyTerms,
      'paragraph_types' => $this->paragraph_types,
      'images' => $this->images,
      'files' => $this->files,
      'videos' => $this->videos,
      'media_contact_content' => $this->other_stuff,
      'data_view_mode' => $this->data_view_mode,
      'node_count' => [sizeof($items)],
      'nodes' => $items,
    ];
    return JsonResponse::create($json);

  }

}
