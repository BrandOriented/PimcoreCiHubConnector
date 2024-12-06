<?php
/**
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @license    https://choosealicense.com/licenses/gpl-3.0/ GNU General Public License v3.0
 * @copyright  Copyright (c) 2023 Brand Oriented sp. z o.o. (https://brandoriented.pl)
 * @copyright  Copyright (c) 2021 CI HUB GmbH (https://ci-hub.com)
 */
declare(strict_types=1);

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Provider\Traits;

use CIHub\Bundle\SimpleRESTAdapterBundle\DataCollector\CompositeDataCollector;
use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use CIHub\Bundle\SimpleRESTAdapterBundle\Traits\LockedTrait;
use Pimcore\Localization\LocaleService;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Tool;
use Webmozart\Assert\Assert;

trait HasDataObjectProvider
{
    use LockedTrait;

    private CompositeDataCollector $compositeDataCollector;

    public function getIndexData(ElementInterface $element, ConfigReader $configReader): array
    {
        /* @var AbstractObject $element */
        Assert::isInstanceOf($element, AbstractObject::class);

        $data = [
            'system' => $this->getSystemValues($element),
        ];

        if ($element instanceof Concrete) {
            if (\in_array($element->getClassName(), $configReader->getObjectClassNames(), true)) {
                $data['data'] = $this->getDataValues($element, $configReader);
            }
        }

        return $data;
    }

    /**
     * Returns the data values of an object.
     *
     * @return array<string, mixed>
     */
    private function getDataValues(Concrete $concrete, ConfigReader $configReader): array
    {
        $objectSchema = $configReader->extractObjectSchema($concrete->getClassName());
        $gridConfigData = $this->getGridConfigData($objectSchema);
        $data = DataObject\Service::getCsvDataForObject(
            $concrete,
            $gridConfigData['language'],
            $gridConfigData['fields'],
            $gridConfigData['helperDefinitions'],
            new LocaleService(),
            'title',
            false
        );

        return $this->prepareExportDataForExtraction($gridConfigData['helperDefinitions'], $data);
    }

    protected function prepareExportDataForExtraction(array $definitions, array $data): array
    {
        foreach ($definitions as $field => $definition) {
            $mappedFieldName = $this->mapFieldName($field, $definition);

            if ($field != $mappedFieldName) {
                $data[$mappedFieldName] = $data[$field];
                unset($data[$field]);
                $field = $mappedFieldName;
            }

            $fieldConfigType = $definition['fieldConfig']['type'] ?? null;

            if ('link' == $fieldConfigType) {
                $data[$field] = (string) Tool\Serialize::unserialize(base64_decode((string) $data[$field], true));
            }
        }

        return $data;
    }

    /**
     * Returns the system values of an object.
     *
     * @return array<string, mixed>
     */
    private function getSystemValues(AbstractObject $object): array
    {
        return [
            'id' => $object->getId(),
            'key' => $object->getKey(),
            'fullPath' => $object->getFullPath(),
            'parentId' => $object->getParentId(),
            'type' => 'object',
            'subtype' => $object->getType(),
            'hasChildren' => $object->hasChildren(),
            'creationDate' => $object->getCreationDate(),
            'modificationDate' => $object->getModificationDate(),
            'locked' => $this->isLocked($object->getId(), 'object'),
        ];
    }

    protected function getGridConfigData(array $objectSchema): array
    {
        $helperDefinitions = $objectSchema['columnConfig'] ?? [];

        $fields = array_map(function ($key, array $value): array {
            $label = $key;
            if (isset($value['fieldConfig'])) {
                if (isset($value['fieldConfig']['label']) && $value['fieldConfig']['label']) {
                    $label = $value['fieldConfig']['label'];
                } elseif (isset($value['fieldConfig']['attributes']['label'])) {
                    $label = $value['fieldConfig']['attributes']['label'] ?: $key;
                }
            }

            return [
                'key' => $key,
                'label' => $label,
            ];
        }, array_keys($helperDefinitions),
            $helperDefinitions
        );
        foreach ($helperDefinitions as $k => $v) {
            if (DataObject\Service::isHelperGridColumnConfig($k)) {
                $helperDefinitions[$k] = json_decode(json_encode($v['fieldConfig']));
            }
        }
        $requestedLanguage = $objectSchema['language'] ?? '';

        return [
            'fields' => $fields,
            'helperDefinitions' => $helperDefinitions,
            'language' => $requestedLanguage,
        ];
    }

    protected function mapFieldName($field, $definition): string
    {
        if (str_starts_with((string) $field, '#') && $definition) {
            if (!empty($definition->attributes)) {
                return $definition->attributes->label ?: $field;
            }

            return $field;
        } elseif (str_starts_with((string) $field, '~')) {
            $fieldParts = explode('~', (string) $field);
            $type = $fieldParts[1];

            if ('classificationstore' === $type) {
                $fieldNames = $fieldParts[2];
                $groupKeyId = explode('-', $fieldParts[3]);
                $groupId = (int) $groupKeyId[0];
                $keyId = (int) $groupKeyId[1];

                $groupConfig = DataObject\Classificationstore\GroupConfig::getById($groupId);
                $keyConfig = DataObject\Classificationstore\KeyConfig::getById($keyId);

                if ($groupConfig && $keyConfig) {
                    $field = $fieldNames.'~'.$groupConfig->getName().'~'.$keyConfig->getName();
                }
            }
        }

        return $field;
    }
}
