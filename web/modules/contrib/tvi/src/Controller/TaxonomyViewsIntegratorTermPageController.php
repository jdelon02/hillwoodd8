<?php

namespace Drupal\tvi\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\tvi\Service\TaxonomyViewsIntegratorManagerInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Render views for taxonomy term pages.
 */
class TaxonomyViewsIntegratorTermPageController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * TVI Term display manager service.
   *
   * @var \Drupal\tvi\Service\TaxonomyViewsIntegratorManager
   */
  private $termDisplayManager;

  /**
   * Drupal Core's config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Drupal's entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * TaxonomyViewsIntegratorTermPageController constructor.
   *
   * @param \Drupal\tvi\Service\TaxonomyViewsIntegratorManagerInterface $term_display_manager
   *   TVI Term display manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Drupal Core's config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Drupal's entity type manager.
   */
  public function __construct(TaxonomyViewsIntegratorManagerInterface $term_display_manager, ConfigFactoryInterface $configFactory, EntityTypeManagerInterface $entityTypeManager) {
    $this->termDisplayManager = $term_display_manager;
    $this->configFactory = $configFactory;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tvi.tvi_manager'),
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Render the page for a given term.
   *
   * @param \Drupal\taxonomy\TermInterface $taxonomy_term
   *   The term to render the view for.
   *
   * @return \Symfony\Component\HttpFoundation\Response|array
   *   Views results render array.
   */
  public function render(TermInterface $taxonomy_term) {
    $config = $this->configFactory->getEditable("tvi.taxonomy_term.{$taxonomy_term->id()}");
    if ($config->isNew()) {
      $config = $this->configFactory->getEditable("tvi.taxonomy_vocabulary.{$taxonomy_term->bundle()}");
      if ($config->isNew()) {
        return $this->entityTypeManager->getViewBuilder('taxonomy_term')->view($taxonomy_term, 'full');
      }
    }
    return $this->termDisplayManager->getTaxonomyTermView($taxonomy_term);
  }

}
