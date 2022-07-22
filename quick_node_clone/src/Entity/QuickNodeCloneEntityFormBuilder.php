<?php

namespace Drupal\quick_node_clone\Entity;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFormBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupContent;
use Drupal\layout_builder\Plugin\Block\InlineBlock;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\layout_builder\SectionComponent;

/**
 * Builds entity forms.
 */
class QuickNodeCloneEntityFormBuilder extends EntityFormBuilder {
  use StringTranslationTrait;

  /**
   * The Form Builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;
  /**
   * The Entity Bundle Type Info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;
  /**
   * The Config Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;
  /**
   * The Module Handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;
  /**
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;
  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;
  /**
   * The Private Temp Store.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $privateTempStoreFactory;

  /**
   * The Translation Interface.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface
   */
  protected $stringTranslation;

  /**
   * The UUID service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuid;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('form_builder'),
      $container->get('entity_type.bundle.info'),
      $container->get('config.factory'),
      $container->get('module_handler'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('tempstore.private'),
      $container->get('string_translation'),
      $container->get('uuid')
    );
  }

  /**
   * QuickNodeCloneEntityFormBuilder constructor.
   *
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilder
   *   The form builder.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   The entity type bundle info provider.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   Current user.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $privateTempStoreFactory
   *   Private temp store factory.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID service.
   */
  public function __construct(FormBuilderInterface $formBuilder, EntityTypeBundleInfoInterface $entityTypeBundleInfo, ConfigFactoryInterface $configFactory, ModuleHandlerInterface $moduleHandler, EntityTypeManagerInterface $entityTypeManager, AccountInterface $currentUser, PrivateTempStoreFactory $privateTempStoreFactory, TranslationInterface $stringTranslation, UuidInterface $uuid) {
    $this->formBuilder = $formBuilder;
    $this->entityTypeBundleInfo = $entityTypeBundleInfo;
    $this->configFactory = $configFactory;
    $this->moduleHandler = $moduleHandler;
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
    $this->privateTempStoreFactory = $privateTempStoreFactory;
    $this->stringTranslation = $stringTranslation;
    $this->uuid = $uuid;
  }

  /**
   * {@inheritdoc}
   */
  public function getForm(EntityInterface $original_entity, $operation = 'default', array $form_state_additions = []) {

    // Clone the node using the awesome createDuplicate() core function.
    /** @var \Drupal\node\Entity\Node $new_node */
    $new_node = $original_entity->createDuplicate();
    $new_node->set('uid', $this->currentUser->id());
    $new_node->set('created', time());
    $new_node->set('changed', time());
    $new_node->set('revision_timestamp', time());

    // Get and store groups of original entity, if any.
    $groups = [];
    if ($this->moduleHandler->moduleExists('gnode')) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $original_entity */
      $group_contents = GroupContent::loadByEntity($original_entity);
      foreach ($group_contents as $group_content) {
        $groups[] = $group_content->getGroup();
      }
    }
    $form_state_additions['quick_node_clone_groups_storage'] = $groups;

    // Get default status value of node bundle.
    $default_bundle_status = $this->entityTypeManager->getStorage('node')->create(['type' => $new_node->bundle()])->status->value;

    // Clone all translations of a node.
    foreach ($new_node->getTranslationLanguages() as $langcode => $language) {
      /** @var \Drupal\node\Entity\Node $translated_node */
      $translated_node = $new_node->getTranslation($langcode);
      $translated_node = $this->cloneParagraphs($translated_node);
      $translated_node = $this->cloneInlineBlocks($translated_node);
      $this->moduleHandler->alter('cloned_node', $translated_node, $original_entity);

      // Unset excluded fields.
      $config_name = 'exclude.node.' . $translated_node->getType();
      if ($exclude_fields = $this->getConfigSettings($config_name)) {
        foreach ($exclude_fields as $field) {
          unset($translated_node->{$field});
        }
      }

      $prepend_text = "";
      $title_prepend_config = $this->getConfigSettings('text_to_prepend_to_title');
      if (!empty($title_prepend_config)) {
        $prepend_text = $title_prepend_config . " ";
      }
      $clone_status_config = $this->getConfigSettings('clone_status');
      if (!$clone_status_config) {
        $key = $translated_node->getEntityType()->getKey('published');
        $translated_node->set($key, $default_bundle_status);
      }

      $translated_node->setTitle($this->t('@prepend_text@title',
        [
          '@prepend_text' => $prepend_text,
          '@title' => $translated_node->getTitle(),
        ],
        [
          'langcode' => $langcode,
        ]
      )
      );
    }

    // Get the form object for the entity defined in entity definition.
    $form_object = $this->entityTypeManager->getFormObject($translated_node->getEntityTypeId(), $operation);

    // Assign the form's entity to our duplicate!
    $form_object->setEntity($translated_node);

    $form_state = (new FormState())->setFormState($form_state_additions);
    $new_form = $this->formBuilder->buildForm($form_object, $form_state);

    // If we are cloning addresses, we need to reset our delta counter
    // once the form is built.
    $tempstore = $this->privateTempStoreFactory->get('quick_node_clone');
    if ($tempstore->get('address_initial_value_delta') !== NULL) {
      $tempstore->set('address_initial_value_delta', NULL);
    }

    return $new_form;
  }

  /**
   * Clone the paragraphs of a node.
   *
   * If we do not clone the paragraphs attached to the node, the linked
   * paragraphs would be linked to two nodes which is not ideal.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node to clone.
   *
   * @return \Drupal\node\Entity\Node
   *   The node with cloned paragraph fields.
   */
  public function cloneParagraphs(Node $node) {
    foreach ($node->getFieldDefinitions() as $field_definition) {
      $field_storage_definition = $field_definition->getFieldStorageDefinition();
      $field_settings = $field_storage_definition->getSettings();
      $field_name = $field_storage_definition->getName();
      if (isset($field_settings['target_type']) && $field_settings['target_type'] == "paragraph") {
        if (!$node->get($field_name)->isEmpty()) {
          foreach ($node->get($field_name) as $value) {
            if ($value->entity) {
              $value->entity = $value->entity->createDuplicate();
              foreach ($value->entity->getFieldDefinitions() as $field_definition) {
                $field_storage_definition = $field_definition->getFieldStorageDefinition();
                $pfield_settings = $field_storage_definition->getSettings();
                $pfield_name = $field_storage_definition->getName();

                // Check whether this field is excluded and if so unset.
                if ($this->excludeParagraphField($pfield_name, $value->entity->bundle())) {
                  unset($value->entity->{$pfield_name});
                }

                $this->moduleHandler->alter('cloned_node_paragraph_field', $value->entity, $pfield_name, $pfield_settings);
              }
            }
          }
        }
      }
    }

    return $node;
  }

  /**
   * Clone the inline blocks of a node's layout.
   *
   * For nodes that have layout builder enabled, the inline blocks needs
   * be to cloned as well.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to clone.
   *
   * @return \Drupal\node\NodeInterface
   *   The node with cloned layout field.
   */
  public function cloneInlineBlocks(NodeInterface $node) {
    $field_name = 'layout_builder__layout';

    if (!$node->hasField($field_name)) {
      return $node;
    }

    if ($node->get($field_name)->isEmpty()) {
      return $node;
    }

    /** @var \Drupal\layout_builder\SectionListInterface $layout_field */
    $layout_field = $node->get($field_name);

    foreach ($layout_field->getSections() as $sid => $section) {
      // Create a duplicate of each component.
      foreach ($section->getComponents() as $component) {
        $block = $component->getPlugin();

        // Only clone inline blocks.
        if (!$block instanceof InlineBlock) {
          continue;
        }

        $component_array = $component->toArray();
        $configuration = $component_array['configuration'];

        // Fetch the block content.
        $block_content = NULL;
        if (!empty($configuration['block_serialized'])) {
          $block_content = unserialize($configuration['block_serialized']);
        }
        elseif (!empty($configuration['block_revision_id'])) {
          $block_content = $this->entityTypeManager->getStorage('block_content')
            ->loadRevision($configuration['block_revision_id']);
        }

        // Create a duplicate block.
        if ($block_content) {
          /** @var \Drupal\block_content\BlockContentInterface $block_content */
          $cloned_block_content = $block_content->createDuplicate();

          // Unset the revision and add the serialized block content.
          $configuration['block_revision_id'] = NULL;
          $configuration['block_serialized'] = serialize($cloned_block_content);
        }

        $new_component = new SectionComponent(
          $this->uuid->generate(),
          $component_array['region'],
          $configuration,
          $component_array['additional']
        );

        // Remove existing components from the section and append a fresh copy.
        $section->insertAfterComponent($component->getUuid(), $new_component);
        $section->removeComponent($component->getUuid());
      }

      $layout_field->insertSection($sid, $section);
      $layout_field->removeSection($sid + 1);
    }

    return $node;
  }

  /**
   * Check whether to exclude the paragraph field.
   *
   * @param string $field_name
   *   The field name.
   * @param string $bundle_name
   *   The bundle name.
   *
   * @return bool|null
   *   TRUE or FALSE depending on config setting, or NULL if config not found.
   */
  public function excludeParagraphField($field_name, $bundle_name) {
    $config_name = 'exclude.paragraph.' . $bundle_name;
    if ($exclude_fields = $this->getConfigSettings($config_name)) {
      return in_array($field_name, $exclude_fields);
    }
  }

  /**
   * Get the settings.
   *
   * @param string $value
   *   The setting name.
   *
   * @return array|mixed|null
   *   Returns the setting value if it exists, or NULL.
   */
  public function getConfigSettings($value) {
    $settings = $this->configFactory->get('quick_node_clone.settings')
      ->get($value);

    return $settings;
  }

}
