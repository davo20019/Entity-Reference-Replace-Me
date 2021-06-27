<?php

namespace Drupal\entityreference_replaceme\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\field\Entity\FieldConfig;

/**
 * Provides a Entity Reference Replace Me form.
 */
class ReplaceMeForm extends FormBase {

    /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity ready to clone.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $entity;

  /**
   * The entity type définition.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface
   */
  protected $entityTypeDefinition;

  /**
   * The string translation manager.
   *
   * @var \Drupal\Core\StringTranslation\TranslationManager
   */
  protected $stringTranslationManager;

  /**
   * Event dispatcher service.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\Messenger
   */
  protected $messenger;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new Entity Clone form.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match service.
   * @param \Drupal\Core\StringTranslation\TranslationManager $string_translation
   *   The string translation manager.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher service.
   * @param \Drupal\Core\Messenger\Messenger $messenger
   *   The messenger service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RouteMatchInterface $route_match, TranslationManager $string_translation, EventDispatcherInterface $eventDispatcher, Messenger $messenger, AccountProxyInterface $currentUser) {
    $this->entityTypeManager = $entity_type_manager;
    $this->stringTranslationManager = $string_translation;
    $this->eventDispatcher = $eventDispatcher;
    $this->messenger = $messenger;

    // TODO: make it work with any entity
    $id = \Drupal::request()->attributes->get('node');
    $this->entity = $entity_type_manager->getStorage('node')->load($id);

    $this->entityTypeDefinition = $entity_type_manager->getDefinition($this->entity->getEntityTypeId());
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('string_translation'),
      $container->get('event_dispatcher'),
      $container->get('messenger'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'entityreference_replaceme_replace_me';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    
    // Get all the fields
    $fieldsWithReferences = $this->getFieldsReferencingCurrentEntity();

    // If there are not fields, just return.
    if (empty($fieldsWithReferences)) {
      return [
        '#type' => 'item',
        '#title' => t('The entity is not being referenced'),
        '#markup' => t('Sorry, this entity is not referenced.'),
      ];
    }
    
    $entitiesCounter = 0;
    foreach ($fieldsWithReferences as $fieldName => $fieldDetails) {
      foreach ($fieldDetails['bundles'] as $bundle) {
        $bundleEntities = $this->getNodesWithReference($bundle, $this->entity->id(), $fieldName);
        if (empty($bundleEntities)) {
          continue;
        }
        $entitiesCounter += count($bundleEntities);
      }
    }
    
    //print_r($ref_fields);
    $form['help'] = [
      '#type' => 'item',
      '#title' => t('Entities to be updated'),
      '#markup' => t('We would update: ' . $entitiesCounter . ' entities'),
    ];

    $form['new_entity'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => $this->entity->getEntityTypeId(),
      '#required' => TRUE,
      '#title' => $this->t('Existing Entity'),
      '#description' => $this->t('Select an existing entity to replace this one with. All the references to this entity will be replaced to the one you select here.'),
      '#tags' => TRUE,
      '#selection_settings' => [
        'target_bundles' => [$this->entity->bundle()], // TODO: Get the entity type
      ],
      '#weight' => '0',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Add any validation here.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Get the fields
    $fieldsWithReferences = $this->getFieldsReferencingCurrentEntity();
    $entityId = $form_state->getValue('new_entity');
    $newReferrencedEntity = $this->entityTypeManager->getStorage('node')->load($entityId[0]['target_id']);

    foreach ($fieldsWithReferences as $fieldName => $fieldDetails) {
      foreach ($fieldDetails['bundles'] as $bundle) {
        $bundleEntities = $this->getNodesWithReference($bundle, $this->entity->id(), $fieldName);
        if (empty($bundleEntities)) {
          continue;
        }
        $this->updateReferences($newReferrencedEntity, $bundleEntities, $fieldName);
      }
    }
    
    $this->messenger()->addStatus($this->t('We updateed the references, you can delete this entity if you want.'));
  }

  /**
   * Helper method gets a list of all entity reference fields that reference
   * the specified entity type.
   *
   * @param $entity_type string
   * @param $entity_bundle string (optional)
   * @return a filtered field map of entity reference fields.
   */
  protected function getEntityReferenceFieldsByEntityType($entity_type, $entity_bundle = '') {

    // Gather a list of all entity reference fields.
    $entity_field_manager = \Drupal::service('entity_field.manager');
    $map = $entity_field_manager->getFieldMapByFieldType('entity_reference');
    $ids = [];
    foreach ($map as $type => $info) {
      foreach ($info as $name => $data) {
        foreach ($data['bundles'] as $bundle_name) {
          $ids[] = "$type.$bundle_name.$name";
        }
      }
    }

    // Determine if any of the reference fields reference a specific entity type
    // and bundle type.
    $filtered_map = [];
    foreach (FieldConfig::loadMultiple($ids) as $field_config) {
      $field_name = $field_config->getName();
      $target_type = $field_config->getSetting('target_type');
      if (!empty($target_type) && $target_type == $entity_type) {
        if (!empty($entity_bundle)) {
          $handler_settings = $field_config->getSetting('handler_settings');
          if (isset($handler_settings['target_bundles'][$entity_bundle])) {
            $filtered_map[$field_name] = $map[$entity_type][$field_name];
          }
        } 
        else {
          $filtered_map[$field_name] = $map[$entity_type][$field_name];
        }
      }
    }

    return $filtered_map;
  }
  
  /**
   * Returns all the nodes which have a reference to the node.
   * @param string $bundle
   * @param int $referencedNodeId
   * @param string $fieldName
   * @param string $entityType
   */
  protected function getNodesWithReference($bundle, $referencedNodeId, $fieldName, $entityType = 'node') {
    $node_storage = $this->entityTypeManager->getStorage($entityType);
    $query = $node_storage->getQuery()
      ->condition('type', $bundle)
      ->condition($fieldName, $referencedNodeId, '=');
    $entityIds = $query->execute();
    
    $entities = [];
    if (!empty($entityIds)) {
      $entities = \Drupal::entityTypeManager()->getStorage($entityType)->loadMultiple($entityIds);
    }
    
    return $entities;
  }
  
  /**
   * Updates the entity reference to a new one.
   * @param type $newReferrencedEntity
   * @param type $updatingEntities
   * @param type $fieldName
   * @return type
   */
  protected function updateReferences($newReferrencedEntity, $updatingEntities, $fieldName) {
    
    foreach ($updatingEntities as $entity) {
      $entity->set($fieldName, $newReferrencedEntity->id());
      $entity->save();
    }
    
    return $updatingEntities;
  }
  
  /**
   * Returns a list of entity reference fields where the current entity is referenced.
   * @return array
   */
  protected function getFieldsReferencingCurrentEntity() {
    // Get all the fields
    $entityType = $this->entity->getEntityTypeId();
    return $this->getEntityReferenceFieldsByEntityType($entityType, $this->entity->bundle());
  }

}
