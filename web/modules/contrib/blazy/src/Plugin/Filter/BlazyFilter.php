<?php

namespace Drupal\blazy\Plugin\Filter;

use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\blazy\Blazy;
use Drupal\blazy\BlazyDefault;
use Drupal\blazy\BlazyOEmbed;
use Drupal\blazy\Dejavu\BlazyVideoTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a filter to lazyload image, or iframe elements.
 *
 * Best after Align images, caption images.
 *
 * @Filter(
 *   id = "blazy_filter",
 *   title = @Translation("Blazy"),
 *   description = @Translation("Lazyload inline images, or video iframes using Blazy."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_REVERSIBLE,
 *   settings = {
 *     "filter_tags" = {"img" = "img", "iframe" = "iframe"},
 *     "media_switch" = "",
 *   },
 *   weight = 3
 * )
 */
class BlazyFilter extends FilterBase implements BlazyFilterInterface, ContainerFactoryPluginInterface {

  use BlazyVideoTrait;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The blazy manager service.
   *
   * @var \Drupal\blazy\BlazyManagerInterface
   */
  protected $blazyManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ImageFactory $image_factory, EntityFieldManagerInterface $entity_field_manager, BlazyOEmbed $blazy_oembed) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->imageFactory = $image_factory;
    $this->entityFieldManager = $entity_field_manager;
    $this->blazyOembed = $blazy_oembed;
    $this->blazyManager = $blazy_oembed->blazyManager();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('image.factory'),
      $container->get('entity_field.manager'),
      $container->get('blazy.oembed')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $result = new FilterProcessResult($text);

    $allowed_tags = array_values((array) $this->settings['filter_tags']);
    if (empty($allowed_tags)) {
      return $result;
    }

    $dom = Html::load($text);
    $settings = $this->buildSettings($text);

    $valid_nodes = [];
    foreach ($allowed_tags as $allowed_tag) {
      $nodes = $dom->getElementsByTagName($allowed_tag);
      if ($nodes->length > 0) {
        foreach ($nodes as $node) {
          if ($node->hasAttribute('data-unblazy')) {
            continue;
          }

          $valid_nodes[] = $node;
        }
      }
    }

    $attachments = [];
    if (count($valid_nodes) > 0) {
      $elements = $grid_nodes = [];
      $item_settings = $settings;
      $item_settings['count'] = $nodes->length;
      foreach ($valid_nodes as $delta => $node) {
        // Build Blazy elements with lazyloaded image, or iframe.
        $item_settings['uri'] = $item_settings['image_url'] = '';
        $item_settings['delta'] = $delta;
        $this->buildItemSettings($item_settings, $node);

        // Extracts image item from SRC attribute.
        $build = ['settings' => $item_settings];
        $this->buildImageItem($build, $node);

        // Extracts image caption if available.
        $this->buildImageCaption($build, $node);

        // Marks invalid/ unknown IMG or IFRAME for removal.
        if (empty($build['settings']['uri'])) {
          $node->setAttribute('class', 'blazy-removed');
          continue;
        }

        // Build valid nodes as structured render array.
        $output = $this->blazyManager->getBlazy($build);
        if ($settings['_grid']) {
          $elements[] = $output;
          $grid_nodes[] = $node;
        }
        else {
          $altered_html = $this->blazyManager->getRenderer()->render($output);

          // Load the altered HTML into a new DOMDocument, retrieve element.
          $updated_nodes = Html::load($altered_html)->getElementsByTagName('body')
            ->item(0)
            ->childNodes;

          foreach ($updated_nodes as $updated_node) {
            // Import the updated from the new DOMDocument into the original
            // one, importing also the child nodes of the updated node.
            $updated_node = $dom->importNode($updated_node, TRUE);
            $node->parentNode->insertBefore($updated_node, $node);
          }

          // Finally, remove the original blazy node.
          if ($node->parentNode) {
            $node->parentNode->removeChild($node);
          }
        }
      }

      // Prepares attachments.
      $all = ['blazy' => TRUE, 'filter' => TRUE, 'ratio' => TRUE];
      $all['media_switch'] = $switch = $settings['media_switch'];

      // Builds the grids if so provided via [data-column], or [data-grid].
      if ($settings['_grid'] && !empty($elements[0])) {
        $all['grid'] = $settings['grid'];
        $all['column'] = $settings['column'];
        if (isset($settings[$switch])) {
          $all[$switch] = $settings[$switch];
        }

        $settings['first_uri'] = isset($elements[0]['#build'], $elements[0]['#build']['settings']['uri']) ? $elements[0]['#build']['settings']['uri'] : '';
        $this->buildGrid($dom, $settings, $elements, $grid_nodes);
      }

      // Adds the attchments.
      $attachments = $this->blazyManager->attach($all);

      // Cleans up invalid, or moved nodes.
      $this->cleanupNodes($dom);
    }

    // Attach Blazy component libraries.
    $result->setProcessedText(Html::serialize($dom))
      ->addAttachments($attachments);

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function buildSettings($text) {
    $settings = BlazyDefault::lazySettings();
    $settings['grid'] = stristr($text, 'data-grid') !== FALSE;
    $settings['column'] = stristr($text, 'data-column') !== FALSE;
    $settings['media_switch'] = $switch = $this->settings['media_switch'];
    $settings['lightbox'] = ($switch && in_array($switch, $this->blazyManager->getLightboxes())) ? $switch : FALSE;
    $settings['id'] = $settings['gallery_id'] = Blazy::getHtmlId('blazy-filter-' . Crypt::randomBytesBase64(8));
    $settings['plugin_id'] = 'blazy_filter';
    $settings['_grid'] = $settings['column'] || $settings['grid'];
    $settings['placeholder'] = $this->blazyManager->configLoad('placeholder', 'blazy.settings');
    $settings['use_data_uri'] = isset($this->settings['media_switch']) ? $this->settings['media_switch'] : FALSE;
    $definitions = $this->entityFieldManager->getFieldDefinitions('media', 'remote_video');
    $settings['is_media_library'] = $definitions && isset($definitions['field_media_oembed_video']);

    // Allows lightboxes to provide its own optionsets.
    if ($switch) {
      $settings[$switch] = empty($settings[$switch]) ? $switch : $settings[$switch];
    }

    // Provides alter like formatters to modify at one go, even clumsy here.
    $build = ['settings' => $settings];
    $this->blazyManager->getModuleHandler()->alter('blazy_settings', $build, $this->settings);
    return array_merge($settings, $build['settings']);
  }

  /**
   * {@inheritdoc}
   */
  public function cleanupNodes(\DOMDocument &$dom) {
    $xpath = new \DOMXPath($dom);
    $nodes = $xpath->query("//*[contains(@class, 'blazy-removed')]");
    if ($nodes->length > 0) {
      foreach ($nodes as $node) {
        if ($node->parentNode) {
          $node->parentNode->removeChild($node);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildGrid(\DOMDocument &$dom, array &$settings, array $elements = [], array $grid_nodes = []) {
    $xpath = new \DOMXPath($dom);
    $query = $settings['style'] = $settings['column'] ? 'column' : 'grid';
    $grid = FALSE;

    // This is weird, variables not working for xpath?
    $node = $query == 'column' ? $xpath->query('//*[@data-column]') : $xpath->query('//*[@data-grid]');
    if ($node->length > 0 && $node->item(0) && $node->item(0)->hasAttribute('data-' . $query)) {
      $grid = $node->item(0)->getAttribute('data-' . $query);
    }

    if ($grid) {
      $grids = array_map('trim', explode(' ', $grid));

      foreach (['small', 'medium', 'large'] as $key => $item) {
        if (isset($grids[$key])) {
          $settings['grid_' . $item] = $grids[$key];
          $settings['grid'] = $grids[$key];
        }
      }

      $build = [
        'items' => $elements,
        'settings' => $settings,
      ];

      $output = $this->blazyManager->build($build);
      $altered_html = $this->blazyManager->getRenderer()->render($output);

      if ($first = $grid_nodes[0]) {

        // Create the parent grid container, and put it before the first.
        $container = $first->parentNode->insertBefore($dom->createElement('div'), $first);

        $updated_nodes = Html::load($altered_html)->getElementsByTagName('body')
          ->item(0)
          ->childNodes;

        // This extra container ensures hook_blazy_build_alter() aint screw up.
        $container->setAttribute('class', 'blazy-wrapper blazy-wrapper--filter');
        foreach ($updated_nodes as $updated_node) {
          // Import the updated from the new DOMDocument into the original
          // one, importing also the child nodes of the updated node.
          $updated_node = $dom->importNode($updated_node, TRUE);
          $container->appendChild($updated_node);
        }

        // Cleanups old nodes already moved into grids.
        foreach ($grid_nodes as $node) {
          if ($node->parentNode) {
            $node->parentNode->removeChild($node);
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildImageItem(array &$build, &$node) {
    $settings = &$build['settings'];
    $item = new \stdClass();

    // Checks if we have a valid file entity, not hard-coded image URL.
    if ($src = $node->getAttribute('src')) {
      // If starts with 2 slashes, it is always external.
      if (strpos($src, '//') === 0) {
        // We need to query stored SRC, https is enforced.
        $src = 'https:' . $src;
      }

      if ($node->tagName == 'img') {
        $item = $this->getImageItemFromImageSrc($settings, $node, $src);
      }
      elseif ($node->tagName == 'iframe') {
        $item = $this->getImageItemFromIframeSrc($settings, $node, $src);
      }
    }

    // Responsive image with aspect ratio requires an extra container to work
    // with Align/ Caption images filters.
    $build['media_attributes']['class'] = ['media-wrapper', 'media-wrapper--blazy'];
    // Copy all attributes of the original node to the item_attributes.
    if ($node->attributes->length) {
      foreach ($node->attributes as $attribute) {
        if ($attribute->nodeName == 'src') {
          continue;
        }

        // Move classes (align-BLAH,etc) to Blazy container, not image so to
        // work with alignments and aspect ratio. Sanitization is performed at
        // BlazyManager::prepareImage() to avoid double escapes.
        if ($attribute->nodeName == 'class') {
          $build['media_attributes']['class'][] = $attribute->nodeValue;
        }
        // Uploaded IMG has target_id in the least, respect hard-coded IMG.
        // @todo decide to remove as this is being too risky.
        elseif (!isset($item->target_id)) {
          $build['item_attributes'][$attribute->nodeName] = $attribute->nodeValue;
        }
      }

      $build['media_attributes']['class'] = array_unique($build['media_attributes']['class']);
    }

    $build['item'] = $item;
  }

  /**
   * {@inheritdoc}
   */
  public function buildImageCaption(array &$build, &$node) {
    // Sanitization was done by Caption filter when arriving here, as
    // otherwise we cannot see this figure, yet provide fallback.
    if ($node->parentNode && $node->parentNode->tagName === 'figure') {
      $caption = $node->parentNode->getElementsByTagName('figcaption');
      if ($caption->length > 0 && $caption->item(0) && $text = $caption->item(0)->nodeValue) {
        $build['captions']['alt'] = ['#markup' => Xss::filter($text, BlazyDefault::TAGS)];

        // Mark the FIGCAPTION for deletion because the caption will be
        // rendered in the Blazy way.
        $caption->item(0)->setAttribute('class', 'blazy-removed');

        // Marks figures for removal as its contents are moved into grids.
        if ($build['settings']['_grid']) {
          $node->parentNode->setAttribute('class', 'blazy-removed');
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getImageItemFromImageSrc(array &$settings, $node, $src) {
    $item = new \stdClass();
    $uuid = $node->hasAttribute('data-entity-uuid') ? $node->getAttribute('data-entity-uuid') : '';

    // Uploaded image has UUID with file API.
    if ($uuid && $file = $this->blazyManager->getEntityRepository()->loadEntityByUuid('file', $uuid)) {
      $data = $this->getImageItem($file);
      $item = $data['item'];
      $item->alt = $node->hasAttribute('alt') ? $node->getAttribute('alt') : ($item ? $item->alt : '');
      $item->title = $node->hasAttribute('title') ? $node->getAttribute('title') : ($item ? $item->title : '');
      $settings = array_merge($settings, $data['settings']);
    }
    else {
      // Manually hard-coded image has no UUID, nor file API.
      $settings['uri'] = $src;

      // Attempts to get the correct URI with hard-coded URL if applicable.
      if ($uri = Blazy::buildUri($src)) {
        $settings['uri'] = $item->uri = $uri;
      }
    }

    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function getImageItemFromIframeSrc(array &$settings, &$node, $src) {
    $item = new \stdClass();

    // Iframe with data: alike scheme is a serious kidding, strip it earlier.
    $src = UrlHelper::stripDangerousProtocols($src);
    $settings['input_url'] = $src;

    // @todo figure out to not hard-code `field_media_oembed_video`.
    $media = [];
    if (!empty($settings['is_media_library'])) {
      $media = $this->blazyManager->getEntityTypeManager()->getStorage('media')->loadByProperties(['field_media_oembed_video' => $src]);
    }

    if (count($media) && $media = reset($media)) {
      // We have media entity.
      $data['settings'] = $settings;
      $this->blazyOembed->getMediaItem($data, $media);

      // Update data with local image.
      $settings = array_merge($settings, $data['settings']);
      $item = $data['item'];
    }
    // Attempts to build safe embed URL directly from oEmbed resource.
    elseif ($resource = $this->blazyOembed->build($settings)) {
      // All we have here is external images.
      $settings['uri'] = $settings['image_url'] = $resource->getThumbnailUrl()->getUri();
      $settings['width'] = empty($settings['width']) ? $resource->getWidth() : $settings['width'];
      $settings['height'] = empty($settings['height']) ? $resource->getHeight() : $settings['height'];
    }

    $settings['ratio'] = empty($settings['width']) ? '16:9' : 'fluid';
    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function buildItemSettings(array &$settings, $node) {
    $width = $node->getAttribute('width');
    $height = $node->getAttribute('height');
    $src = $node->getAttribute('src');

    if (!$width && $node->tagName == 'img') {
      if ($src && $data = @getimagesize(DRUPAL_ROOT . $src)) {
        list($width, $height) = $data;
      }
    }

    $settings['width'] = $width;
    $settings['height'] = $height;
    $settings['ratio'] = !$width ? '' : 'fluid';
    $settings['media_switch'] = $this->settings['media_switch'];
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    if ($long) {
      return $this->t('
        <p><strong>Blazy</strong>: Image or iframe is lazyloaded. To disable, add attribute <code>data-unblazy</code>:</p>
        <ul>
            <li><code>&lt;img data-unblazy /&gt;</code></li>
            <li><code>&lt;iframe data-unblazy /&gt;</code></li>
        </ul>
        <p>To build a grid of images/ videos, add attribute <code>data-grid</code> or <code>data-column</code> (only to the first item):
        <ul>
            <li><code>&lt;img data-grid="1 3 4" /&gt;</code></li>
            <li><code>&lt;iframe data-column="1 3 4" /&gt;</code></li>
        </ul>
        The numbers represent the amount of grids/ columns for small, medium and large devices respectively, space delimited. Be aware! All media items will be grouped regardless of their placements, unless those given a <code>data-unblazy</code>. Also <b>required</b> if using <b>Image to lightbox</b> (Colorbox, Photobox, PhotoSwipe) to build the gallery correctly.</p>');
    }
    else {
      return $this->t('To disable lazyload, add attribute <code>data-unblazy</code> to <code>&lt;img&gt;</code> or <code>&lt;iframe&gt;</code> elements. Examples: <code>&lt;img data-unblazy</code> or <code>&lt;iframe data-unblazy</code>.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $lightboxes = $this->blazyManager->getLightboxes();

    $form['filter_tags'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enable HTML tags'),
      '#options' => [
        'img' => $this->t('Image'),
        'iframe' => $this->t('Video iframe'),
      ],
      '#default_value' => empty($this->settings['filter_tags']) ? [] : array_values((array) $this->settings['filter_tags']),
      '#description' => $this->t('Recommended placement after Align / Caption images. To disable for individual items, add attribute <code>data-unblazy</code>.'),
    ];

    $form['media_switch'] = [
      '#type' => 'select',
      '#title' => $this->t('Media switcher'),
      '#options' => [
        'media' => $this->t('Image to iframe'),
      ],
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $this->settings['media_switch'],
      '#description' => $this->t('<ul><li><b>Image to iframe</b> will hide iframe behind image till toggled.</li><li><b>Image to lightbox</b> (Colorbox, Photobox, PhotoSwipe) <b>requires</b> a grid to build the gallery correctly. Add <code>data-column="1 3 4"</code> or <code>data-grid="1 3 4"</code> to the first image/ iframe only.</li></ul>'),
    ];

    if (!empty($lightboxes)) {
      foreach ($lightboxes as $lightbox) {
        $name = Unicode::ucwords(str_replace('_', ' ', $lightbox));
        $form['media_switch']['#options'][$lightbox] = $this->t('Image to @lightbox', ['@lightbox' => $name]);
      }
    }

    $form['use_data_uri'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Trust data URI'),
      '#default_value' => isset($this->settings['use_data_uri']) ? $this->settings['use_data_uri'] : FALSE,
      '#description' => $this->t('Enable to support the use of data URI. Leave it unchecked if unsure, or never use data URI.'),
    ];

    return $form;
  }

}
