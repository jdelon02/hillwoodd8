<?php

namespace Drupal\context\Plugin\Condition;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\system\Plugin\Condition\RequestPath;

/**
 * Provides a 'Request path exclusion' condition.
 *
 * @Condition(
 *   id = "request_path_exclusion",
 *   label = @Translation("Request path exclusion"),
 * )
 */
class RequestPathExclusion extends RequestPath implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    unset($form['negate']);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate() {
    // Allow this to pass through gracefully when blank.
    $pages = mb_strtolower($this->configuration['pages']);
    if (!$pages) {
      return TRUE;
    }
    return !parent::evaluate();
  }

}
